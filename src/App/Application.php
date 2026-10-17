<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Commands\CommandRouter;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Input\AcceleratorTable;
use Drift\Input\CursorShape;
use Drift\Input\InputEvent;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;
use Drift\Input\ThemeChangedEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderObject;
use Drift\Layout\RenderPopupPanel;
use Drift\Rendering\RenderSurface;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Text\FontLocator;
use Drift\Text\TtfFace;
use Drift\Geometry\Rect;
use Drift\Text\TextMetricsProvider;
use Drift\Widget\TextFieldState;
use Drift\Widget\ScrollViewState;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Element;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Modal;
use Drift\Widget\StatefulElement;
use Drift\Widget\Widget;

/**
 * **组合根**（§4.5）：应用只写 `buildRoot()` / `title()` / `commands()`。
 *
 * 它做四件事，别的都不做：① 持有根 Element 与一帧的 orchestrator；② 把
 * `commands()` 注册进 `CommandRouter`；③ **拼完 §11.4.3 的命令派发链** ——
 * 第 ①② 环（树内冒泡）在这里，第 ③ 环是 `CommandRouter`，第 ④ 环是"谁都没接"；
 * ④ 提供 `frame()` 让平台驱动一帧。
 *
 * ★ **它不拥有事件循环**（§5.2 裁决 1）：循环住平台，本类只提供"被告知该跑
 *   一帧时"的入口。所以测试能喂任意帧序列，动画相位 = f(帧时间戳) 才是确定的。
 *
 * ★ **表面是注入的，不是它造的**（§7.2 裁决 4：表面与树互认识）。⇒ 没有
 *   Win32 / Skia 桥也能在 `php.exe` 里跑完整应用（§14 的物质基础）。
 *
 * ```php
 * final class UsersApplication extends Application
 * {
 *     public function buildRoot(): Widget { return UsersRoot::make($this->store); }
 *     public function title(): string { return '用户管理'; }
 * }
 * ```
 *
 * @api
 */
abstract class Application
{
    protected ?Element $root = null;

    protected ?Frame $frame = null;

    /**
     * 输入路由宿主（§9.1 的 ②③ 环 + 命中测试）。
     *
     * ★ 它本身是 `@internal` 的实现细节，但**由 `Application` 持有**：路由需要根
     *   Element，而根 Element 住在这里 ⇒ 让调用方自己拿一个 `FocusManager`
     *   会变成"两份焦点状态"（§9.1 的焦点是渲染态，只能有一份）。
     */
    protected ?FocusManager $focus = null;

    protected CommandRouter $router;

    /** 拖动状态机（§A.3.5）；懒建 —— 不拖的应用零成本 */
    protected ?DragController $drag = null;

    /**
     * 拖放阈值起点（§A.3.5，2026-10-17 指针驱动接线）。
     *
     * ★ **按下 ≠ 拖动**：`down` 落在 `Draggable` 声明者上只记 pending，
     *   `move` 位移超过 `DRAG_THRESHOLD` 才真开始 —— 否则"点一下按钮"会
     *   顺手启动拖动（标准拖拽语义）。
     * ★ 不过阈值的 `up` ⇒ 丢弃 pending（那次点击的激活已在 down 发生过）。
     */
    protected ?Draggable $pendingDrag = null;

    protected ?Element $pendingDragSource = null;

    protected float $pendingDragX = 0.0;

    protected float $pendingDragY = 0.0;

    /**
     * 开始拖动的位移阈值（**逻辑**像素，平方比较免开方）。
     *
     * ★ 6 是"明显是拖、不是抖"的经验值（Windows 的 `SM_CXDRAG` 是 4 设备像素，
     *   逻辑单位下再放宽 —— 高 DPI 下手抖一个像素不该启动拖动）。
     */
    private const DRAG_THRESHOLD = 6.0;

    /**
     * 滚轮一格滚多少逻辑像素（2026-10-17："不跟手"的根因修复）。
     *
     * ★ 分工：平台只做**量化**（`÷120` 把 WHEEL_DELTA 收成"格"，§6.1），
     *   "一格值多少 px" 是**策略**，归框架。48 = 3 行 × 16px 行高，与
     *   Windows 默认 `SPI_GETWHEELSCROLLINES=3`、浏览器每格 ~40–53px 同量级。
     *   ★ 修复前是 1px/格 ⇒ "滑不动/不跟手"（一格 notch 只挪一个像素）。
     * ★ 桥未暴露 `SPI_GETWHEELSCROLLINES` ⇒ 这里是常量而非系统值（缺口已登记）。
     */
    private const WHEEL_NOTCH_PX = 48.0;

    /**
     * 浮层面板（O-20 第二棵根渲染树）：菜单 / 上下文菜单 / 模态共用同一槽位。
     *
     * ★ 与 `$root` **同构**（一个 Widget + 一个 Element）—— 树 / diff / 布局零配合，
     *   `Frame` 只在主树之后多画一棵，"绘制顺序即层叠"故**不引入 z-index**。
     *
     * ★ **锚点是应用级几何**：面板钉在锚点矩形的左下角，再夹进客户区。锚点由
     *   `showMenu()` 的 `$anchor` 给，`null` ⇒ 用最近一次命中的 Element
     *   （§5.1 裁决 1 的锚点来源 —— 命令是"意图"不带位置，§11.4.3）。
     */
    protected ?Widget $menu = null;

    protected ?Element $menuElement = null;

    protected float $menuAnchorX = 0.0;

    protected float $menuAnchorY = 0.0;

    /** 面板左上角在客户区的落点（`placeMenu()` 每帧算，已夹进客户区） */
    protected float $menuX = 0.0;

    protected float $menuY = 0.0;

    /**
     * 当前浮层是不是**模态**（O-20「一槽位两用」的判别位）。
     *
     * ★ 槽位共用（`$menu` / `$menuElement` / `$menuX` / `$menuY`），两处差异：
     *   ① **布局** —— 模态紧约束盖满（`placeModal()`），菜单无界自尺寸后钉锚点
     *      （`placeMenu()`）；② **关闭** —— 模态只在 `dismissible` 时被用户动作关掉、
     *      且**永不穿透**，菜单点外即关。
     * ★ 存 `?Modal` 而不是 bool：`dismissible` 住在控件上，判断要在
     *   `dispatchInput()` 里做 —— 布尔标记会逼出"第二个真相"。
     */
    protected ?Modal $modal = null;

    /** @param iterable<string, CommandHandler> $handlers `commands()` 的返回值 */
    public function __construct(iterable $handlers = [])
    {
        $this->router = CommandRouter::make();

        // 只注册**显式传进来**的那些。`commands()` **不在构造器里调** ——
        // PHP 的构造链是"父先子后"，此刻子类构造器里的字段（`$this->store` 之类）
        // 还都是 null ⇒ 照 §A.2 的样例会直接炸。注册推迟到 `attach()`（见那儿）。
        foreach ($handlers as $name => $handler) {
            $this->router->on((string) $name, $handler);
        }
    }

    /** 应用子树（§4.5：只写这一个方法就能跑起来） */
    abstract public function buildRoot(): Widget;

    /** 窗口标题 */
    public function title(): string
    {
        return 'Drift';
    }

    /**
     * 命令处理器（§11.4.3）：**应用级依赖清单，集中一处**。
     *
     * 形态是 `命令名 => handler`，不是 `list<handler>` —— handler 不知道自己的
     * 命令名（`handle()` 只收名字，返回"我处理了吗"）。而 §11.4.3 的"多命令共用
     * 一个 handler"要求**一个实例能挂多个名字** ⇒ 名字必须在注册侧给出。
     *
     * ```php
     * return ['users.refresh' => $this->cmds, 'users.delete' => $this->cmds];
     * ```
     *
     * @return iterable<string, CommandHandler>
     *
     * @api
     */
    public function commands(): iterable
    {
        return [];
    }

    /** 主题：构造时给死；换肤走 `Drift\Theme\ThemeScope`（§2446） */
    public function theme(): ThemeData
    {
        return ThemeData::light();
    }

    /**
     * 加速键表（§9.1 路由链第 ① 环，焦点之前）。
     *
     * ```php
     * public function accelerators(): AcceleratorTable
     * {
     *     return AcceleratorTable::make()
     *         ->bind(KeyChord::ctrl('R'), 'users.refresh');
     * }
     * ```
     *
     * ★ 默认空表 ⇒ 不覆写的应用零加速键、也零开销（`isEmpty()` 短路）。
     * ★ 每帧查询**现取现建**也行（值小、bind 便宜）；要省就缓存到字段——
     *   应用级数据没有不可变纪律（不是 Widget）。
     *
     * @api
     */
    public function accelerators(): AcceleratorTable
    {
        return AcceleratorTable::make();
    }

    /**
     * 度量实现（缓存住 —— 它现在要读一次字体文件，不能每次问都读 11 MB）。
     *
     * @internal 缓存字段；子类覆写 `metrics()` 时不受影响
     */
    private ?TextMetricsProvider $metricsCache = null;

    /**
     * **布局确定性的注入点**（§14.2）：度量由宿主给，不由平台探测。
     *
     * ## 2026-10-17：从"纯位图"改为"真字体优先，位图作字形源"
     *
     * 位图字模只覆盖生成器扫到的那批字（864），表外字符退 `NotdefFontFace`
     * 按宽/窄猜 1.0 / 0.5 em —— 而 `skia` 后端的字形来自真 TTF（全字集）。
     * 于是"画得出、宽度是猜的"：长句错位、换行点算错。现在把**度量**接回真字体
     * （`TtfFace` 解析 cmap + hmtx），位图面降级为 `soft` 后端的**字形源**。
     *
     * ★ 顺序要紧：`TtfFace` 注册在**最前**（它 `hasGlyph()` 查真字体 cmap ⇒
     *   全字集），位图两面跟在后面只服务"TTF 也没有的字"和 `soft` 的字形委托。
     * ★ 找不到字体文件 ⇒ 退纯位图（**降级留痕**：`TtfFace::fromFile()` 记
     *   Diagnostic；不是静默 —— 现象会是"表外字宽度不对"，那条日志是唯一线索）。
     *
     * @api
     */
    public function metrics(): TextMetricsProvider
    {
        if ($this->metricsCache !== null) {
            return $this->metricsCache;
        }

        $latin = DeterministicFontManager::latinFace();
        $cjk = DeterministicFontManager::cjkFace();

        $manager = new DeterministicFontManager();
        $fontPath = FontLocator::find('NotoSansSC-Regular.ttf');
        $ttf = $fontPath === null ? null : TtfFace::fromFile($fontPath, [$latin, $cjk]);

        if ($ttf !== null) {
            $manager->registerFace($ttf);
        }

        $manager->registerFace($latin);
        $manager->registerFace($cjk);

        $this->metricsCache = new DeterministicMetricsProvider(
            $manager->collection(),
            new DeterministicShaper(),
        );

        return $this->metricsCache;
    }

    public function router(): CommandRouter
    {
        return $this->router;
    }

    /**
     * 挂载：建根 Element、整棵树挂上。
     *
     * ★ 根**不包 `ThemeScope`** —— 应用想要全局主题就自己包（`buildRoot()` 里
     *   一层 `ThemeScope::make($this->theme(), $root)`），框架不替它决定。
     *   少了这一层 indirection，"换肤"就少一个可测试的落点。
     *
     * @param BoxConstraints $constraints 根收到的约束（通常 = 窗口逻辑尺寸）
     *
     * @api
     */
    public function attach(RenderSurface $surface, BoxConstraints $constraints, ?BuildContext $context = null): void
    {
        $context ??= new BuildContext(
            $constraints->maxWidth,
            $constraints->maxHeight,
            $this->theme(),
            $this->metrics(),
        );

        // 注册期（§11.4.3）：此刻子类字段已就位，`commands()` 才是可信的。
        // 每条命令重复注册 ⇒ Fatal（`CommandRouter::on()` 的那条 G2 检查），
        // 所以这里**必须可重入**：`attach()` 调两次会第二次命中同名注册。
        foreach ($this->commands() as $name => $handler) {
            if (!$this->router->has((string) $name)) {
                $this->router->on((string) $name, $handler);
            }
        }

        $root = $this->buildRoot()->createElement();
        $root->mount(null, $context);
        $this->root = $root;

        $this->frame = new Frame($surface);
        $this->frame->run($root, $constraints, 0.0);

        // 焦点链要在**挂载之后**建（此刻才有树可走）。
        $this->focus = new FocusManager();
        $this->focus->collect($root);
    }

    /** 根 Element（挂载后才有）—— 测试与 devtools 读它 */
    public function root(): ?Element
    {
        return $this->root;
    }

    /**
     * 打开浮层面板（O-20 方案 A：第二棵根渲染树）。
     *
     * ```php
     * // 菜单标题的 onOpen 里
     * ->onOpen(fn(Menu $m, ?Element $anchor) => $this->showMenu($m->panel(), $anchor))
     * ```
     *
     * @param Widget $panel 面板（通常是 `PopupPanel`；菜单用 `Menu::panel()` / `ContextMenu::panel()`）
     * @param Element|null $anchor 锚点 Element；`null` ⇒ 用最近一次命中的（`FocusManager::lastHit()`）
     *
     * ★ **调用点是应用**（菜单的 `onOpen` / 右键入口）—— 打开要碰 `Application`，
     *   而 §4.3 禁 `Widget → App`；于是"菜单声明形体、应用管落位"这条分工
     *   （§5.1 裁决 1）在类型层面就成立了。
     *
     * @api
     */
    public function showMenu(Widget $panel, ?Element $anchor = null): void
    {
        if ($this->root === null) {
            return; // 未挂载：没有客户区，浮层无处安放
        }

        $element = $panel->createElement();
        $element->mount(null, $this->menuContext());

        $this->menu = $panel;
        $this->menuElement = $element;

        $anchor ??= $this->focus?->lastHit();
        $box = $anchor?->effectiveBox();

        if ($box !== null) {
            // 盒原点要**沿父链累加**才有客户区坐标（`offsetX()` 是相对父的）
            [$x, $y] = self::absoluteOrigin($box);
            $this->menuAnchorX = $x;
            $this->menuAnchorY = $y + ($box->size()?->height ?? 0.0); // 面板挂在锚点**下方**
        } else {
            $this->menuAnchorX = 0.0;
            $this->menuAnchorY = 0.0;
        }

        $this->menuX = $this->menuAnchorX;
        $this->menuY = $this->menuAnchorY;
    }

    /** 关掉浮层（已关 ⇒ 无事发生；幂等） */
    public function closeMenu(): void
    {
        if ($this->menuElement === null) {
            return;
        }

        $this->menuElement->unmount();
        $this->menuElement = null;
        $this->menu = null;

        // ★ 槽位共用 ⇒ 模态标记必须跟着清：漏了它，"关掉模态之后 ESC 仍被吞"，
        //   而且 `isModalOpen()` 永远为真（两处状态会开始互相撒谎）。
        $this->modal = null;
    }

    /**
     * 菜单是否开着。
     *
     * ★ **模态占槽位时不算菜单**：一个槽位两用 ⇒ "槽位空不空"与"菜单开没开"
     *   是两个问题。混答的后果是 `showModal()` 之后 `isMenuOpen()` 返回真，
     *   应用里"菜单开着就别响应某个快捷键"之类的判断会开始撒谎。
     */
    public function isMenuOpen(): bool
    {
        return $this->menuElement !== null && $this->modal === null;
    }

    /** 浮层 Element（测试 / devtools） */
    public function menuElement(): ?Element
    {
        return $this->menuElement;
    }

    /** 浮层面板左上角在客户区的落点（夹紧后；测试 / devtools） */
    public function menuOrigin(): array
    {
        return [$this->menuX, $this->menuY];
    }

    /**
     * 打开模态（§A.3.7 / §12.1 第 6 条）—— 与菜单**共用**同一个浮层槽位。
     *
     * ```php
     * $this->application()->showModal(
     *     Modal::make()->title('确认删除')->child($body)
     * );
     * ```
     *
     * ★ **模态与菜单天然互斥**（GUI 仓裁决原话）：同一槽位 ⇒ 打开模态会先关掉
     *   菜单。不是"顺手清理"，是语义：一个应用同时只有一个"浮在最上、吃掉输入"
     *   的东西。
     * ★ **面板自居中靠组合**：`Modal::build()` 给的是 `ModalHost → Center →
     *   PopupPanel`，本方法只负责"盖满 + 原点归零"（`placeModal()`）。
     *
     * @api
     */
    public function showModal(Modal $modal): void
    {
        if ($this->root === null) {
            return; // 未挂载：没有客户区，模态无处安放（同 showMenu）
        }

        $this->closeMenu(); // 互斥：先清槽位（`closeMenu()` 幂等）

        $element = $modal->createElement();
        $element->mount(null, $this->menuContext());

        $this->menu = $modal;
        $this->menuElement = $element;
        $this->modal = $modal;

        // 模态不钉锚点：原点恒为 (0,0)，尺寸由 placeModal() 盖满
        $this->menuAnchorX = 0.0;
        $this->menuAnchorY = 0.0;
        $this->menuX = 0.0;
        $this->menuY = 0.0;
    }

    /**
     * 关掉模态（幂等；没开 ⇒ 无事发生）。
     *
     * ★ **只有应用能调它**：`Modal` 自己不关（§A.3.7「关不关归应用」）。
     *   `dismissible` 只管"用户动作能不能关"，不限制这里 —— 应用永远能关。
     *
     * @api
     */
    public function closeModal(): void
    {
        if ($this->modal === null) {
            return;
        }

        $this->closeMenu(); // 槽位清理只有一份（含 `$this->modal = null`）
    }

    public function isModalOpen(): bool
    {
        return $this->modal !== null;
    }

    /** 当前模态（测试 / devtools / 应用读 `dismissible`） */
    public function modal(): ?Modal
    {
        return $this->modal;
    }

    /**
     * 模态的布局段：**紧约束盖满客户区**，原点归零。
     *
     * ★ 与 `placeMenu()` 的差别只有两点：约束是 tight（不是 unbounded），原点
     *   是 (0,0)（不是锚点）—— 所以不共用一个方法：共用就得在里面长出
     *   `if (模态)` 分支，而两个分支的**输入含义**根本不同（"自尺寸"vs"盖满"）。
     * ★ 无界轴退化：窗口表面永远有界，这里只为不产生 `tight(INF)` 的非法约束
     *   （真发生了就退成 unbounded，模态会缩到内容大小 —— 可见地不对，不是静默）。
     */
    private function placeModal(BoxConstraints $constraints): void
    {
        $box = $this->menuElement?->effectiveBox();
        if ($box === null) {
            return;
        }

        if ($constraints->hasBoundedWidth() && $constraints->hasBoundedHeight()) {
            $box->layout(BoxConstraints::tight(
                new \Drift\Geometry\Size($constraints->maxWidth, $constraints->maxHeight),
            ));
        } else {
            $box->layout(BoxConstraints::unbounded());
        }

        $this->menuX = 0.0;
        $this->menuY = 0.0;
    }

    /**
     * 浮层的布局段（O-20 说"三段各加分支"，layout 落在这里）。
     *
     * 三件事，都要锚点或客户区尺寸 ⇒ 只能住应用级：
     *   ① **无界约束** layout（面板自尺寸 —— 它不知道外面多大）；
     *   ② 面板矩形左上角钉到锚点；
     *   ③ **夹进客户区**（★ 按**面板**矩形算，不含投影留白 —— §9.1 语义 #6）。
     */
    private function placeMenu(BoxConstraints $constraints): void
    {
        if ($this->modal !== null) {
            $this->placeModal($constraints);

            return;
        }

        $box = $this->menuElement?->effectiveBox();
        if ($box === null) {
            return;
        }

        $box->layout(BoxConstraints::unbounded());

        // 面板矩形（含投影留白的盒要内缩 `elevation` 才是"面板"本身）
        if ($box instanceof RenderPopupPanel) {
            $panel = $box->panelRect();
            $panelW = $panel->width;
            $panelH = $panel->height;
            $e = $panel->left;
        } else {
            $panelW = $box->size()?->width ?? 0.0;
            $panelH = $box->size()?->height ?? 0.0;
            $e = 0.0;
        }

        $maxX = max(0.0, $constraints->maxWidth - $panelW);
        $maxY = max(0.0, $constraints->maxHeight - $panelH);

        // 盒原点 = 面板原点 − 留白（让**面板**左上角落在夹紧后的锚点上）
        $this->menuX = min(max($this->menuAnchorX, 0.0), $maxX) - $e;
        $this->menuY = min(max($this->menuAnchorY, 0.0), $maxY) - $e;
    }

    /** 浮层的渲染环境（复用主树的 —— 同主题 / 同度量） */
    private function menuContext(): BuildContext
    {
        return $this->root?->context()
            ?? new BuildContext(0.0, 0.0, $this->theme(), $this->metrics());
    }

    /** 盒在客户区的绝对原点（沿父链累加相对偏移） */
    private static function absoluteOrigin(RenderBox $box): array
    {
        $x = 0.0;
        $y = 0.0;

        for ($node = $box; $node !== null; $node = $node->parent()) {
            $x += $node->offsetX();
            $y += $node->offsetY();
        }

        return [$x, $y];
    }

    /**
     * IME 定位用的光标矩形（§8.4）—— **逻辑像素**的窗口坐标 `[x, y, h]`。
     *
     * ★ 返回 null = 无插入符（无焦点 / 焦点不在编辑态 / 光标矩形算不出来）。
     *   组合根把它喂给 `Platform::imeCaret()`；平台侧负责 × dpr（§6.1：
     *   **量化只发生在平台侧** —— 这里给逻辑值，与 `BoxConstraints`/视口同单位）。
     *
     * ★ 为什么不做在框架里每帧自动同步：**框架不持平台**（§5.1「平台名只出现
     *   在组合根」）⇒ 这一行的驱动归组合根。几何算在框架侧是为了可头侧测试
     *   （见 smoke 的 IME 夹具）—— 坐标换算是这条链上最容易错的一段。
     */
    public function imeCaretRect(): ?array
    {
        $focused = $this->focus?->focused();
        if (!$focused instanceof StatefulElement) {
            return null;
        }

        $state = $focused->state();
        if (!$state instanceof TextFieldState || !$state->isEditing()) {
            return null;
        }

        $box = $focused->effectiveBox();
        if ($box === null) {
            return null;
        }

        $local = $state->caretRect();   // 盒内坐标（含内边距）
        if (!$local instanceof Rect) {
            return null;
        }

        [$originX, $originY] = self::absoluteOrigin($box);

        return [
            $originX + $local->left,
            $originY + $local->top,
            $local->height,
        ];
    }

    /**
     * 系统主题变了（§9.2 / §A.2.5 的应用钩子）—— **应用扩展点**。
     *
     * ★ 框架不持平台（§5.1）⇒ 拿不到 `Screen::systemTheme()`，这个钩子**不带
     *   参数**：应用自己持有 screen（组合根注入）时在这里重读，并更新自己
     *   `theme()` 的来源字段。默认**空实现** —— 不跟随系统主题的应用零开销。
     *
     * ★ 调用时机在 `applyNewContext()` **之前**（见 `dispatchInput` 的
     *   ThemeChangedEvent 分支）：钩子跑完 `theme()` 才是新值。
     *
     * @api
     */
    protected function onSystemThemeChanged(): void
    {
    }

    /**
     * 把新的渲染环境下发到**根与浮层**两棵树（O-20 差异表 #2：浮层是第二棵树）。
     *
     * ★ **不是新机制**：`Element::applyContext()` 就是"换一份渲染环境（主题切换
     *   的唯一入口）"，且已实现两种兑现时机（无状态立即重建 / 有状态只标脏、
     *   等帧末 flush）+ 递归只走一遍 —— 本方法只是**第一个生产调用者**。
     *
     * ★ 主题值来自 `$this->theme()`（应用注入点）。若应用没覆写 `theme()`、
     *   也没在钩子里换源 ⇒ 新 context 与旧值 `===` ⇒ `applyContext()` 幂等
     *   短路（零重建）。这是**设计**：不跟随系统主题的应用不该付重建成本。
     */
    private function applyNewContext(): void
    {
        $root = $this->root;
        if ($root === null) {
            return;
        }

        $context = $root->context()->withTheme($this->theme());
        $root->applyContext($context);
        $this->menuElement?->applyContext($context);
    }

    /**
     * 跑一帧（§5.2）：平台每帧调一次。
     *
     * @param float $timestampMs **注入的**时间戳（不读环境时钟）
     *
     * @api
     */
    public function frame(float $timestampMs, BoxConstraints $constraints): void
    {
        $root = $this->root;
        $frame = $this->frame;
        if ($root === null || $frame === null) {
            return; // 未挂载：没有树就没有帧
        }

        // 浮层布局段（无界自尺寸 + 钉锚点 + 夹进客户区）—— 见 placeMenu()
        $this->placeMenu($constraints);

        $frame->run($root, $constraints, $timestampMs, $this->menuElement, $this->menuX, $this->menuY);

        // ★ 每帧重建焦点链：`settle()` 可能重建了子树 ⇒ 旧链里可能握着**已被移除**
        //   的 Element（按键会静默丢给死节点）。`collect()` 幂等，且会保留仍然存在
        //   的焦点（见 `FocusManager::collect()`）。
        //   O(树) 与同帧的 `layout()` 同阶 —— §13.3：Render 层本就全树重排。
        // ★ **浮层是第二棵树，也要进焦点链**（O-20 差异表 #2）—— 否则菜单项
        //   永远 Tab 不到。
        $this->focus?->collect($root, ...($this->menuElement !== null ? [$this->menuElement] : []));
    }

    /**
     * 喂一条平台事件（§9.1 路由链的入口）。
     *
     * ```php
     * while ($platform->pump()) {
     *     while (($event = $input->poll()) !== null) {
     *         $shape = $app->dispatchInput($event);
     *         if ($shape !== null) { $window->setCursor($handle, $shape); }
     *     }
     *     $app->frame($platform->now(), $screen->viewport());
     * }
     * ```
     *
     * @return CursorShape|null 指针事件 ⇒ 命中链解析出的光标形状（★ 与焦点
     *   **同一次命中**，不重复走树 —— §9.1「一次命中，三方消费」）；其余事件
     *   ⇒ `null`（不改光标）。★ 应用它需要 `WindowHost`，而**框架不持平台**
     *   （§5.1：共享代码零平台引用）⇒ 由组合根把返回值喂给 `setCursor()`。
     *
     * @api
     */
    public function dispatchInput(InputEvent $event): ?CursorShape
    {
        $root = $this->root;
        $focus = $this->focus;

        if ($root === null || $focus === null) {
            return null;   // 未挂载：没有树就没有路由
        }

        if ($event instanceof ThemeChangedEvent) {
            // §9.2 事件通路的终点：显式消费（不落 default 丢弃）。
            //
            // ★ 顺序要紧：**先钩子、再下发**。`onSystemThemeChanged()` 是应用
            //   重读系统主题、更新自己 `theme()` 来源的地方；它跑完，`theme()`
            //   才是新值 ⇒ 这之后下发的 context 才带着新主题。
            $this->onSystemThemeChanged();
            $this->applyNewContext();

            return null;
        }

        if ($event instanceof KeyEvent) {
            // §9.1 关闭语义 #3：**ESC 在 `dispatchKey` 之前拦** —— 否则被焦点
            // 控件（输入框）吃掉，菜单关不掉。
            if ($event->chord->is(KeyChord::escape())) {
                if ($this->modal !== null) {
                    // §A.3.7：`dismissible=false` ⇒ **吞掉但不关**。"拒绝"不等于
                    // "放行" —— 若把 ESC 交回加速键链，Ctrl+W 之类能绕过
                    // "必须处理的对话框"，模态就白做了。
                    if ($this->modal->isDismissible()) {
                        $this->closeModal();
                    }

                    return null;
                }

                if ($this->menuElement !== null) {
                    $this->closeMenu();

                    return null;
                }

                if ($this->drag?->isDragging() === true) {
                    $this->cancelDrag();

                    return null;
                }

                // 仅 pending（按下未过阈值）⇒ ESC 丢弃这次拖动意图并消费该键
                if ($this->pendingDrag !== null) {
                    $this->pendingDrag = null;
                    $this->pendingDragSource = null;

                    return null;
                }
            }

            // §9.1 第 ① 环：加速键（在焦点之前）。
            // 让位规则 ②：焦点控件声明了独占该组合 ⇒ 不吃快捷键，往下走焦点链。
            $accel = $this->accelerators()->resolve($event->chord);
            if ($accel !== null && !$focus->claims($event->chord)) {
                $this->dispatch($accel);

                return null;
            }

            if ($focus->dispatchKey($event)) {
                return null;
            }

            // §9.1：焦点按钮"回车/空格能触发"—— 动作缝的键盘侧激活
            $this->activateFocused($event);

            return null;
        }

        if ($event instanceof PointerEvent) {
            return $this->dispatchPointer($event, $root, $focus);
        }

        if ($event instanceof ScrollEvent) {
            // 滚轮（2026-10-17）：命中 → 沿命中链找最近的 ScrollViewState → scrollBy。
            //
            // ★ 与指针分支同构的三段：**浮层开着 ⇒ 先归浮层**（长菜单自己滚），
            //   轮在浮层外 ⇒ 不穿透滚主树（同语义 #1：否则"关菜单"翻掉整页）。
            //   ★ 命中一次、就滚那一个：指针停在哪个视口里就滚哪个 —— 多个滚动
            //   容器各自滚动，外层的视口不吞内层的事件（scrollAt 只找最近的）。
            // ★ **格 → 像素只在这里发生一次**（策略层）：`$event->delta` 是平台
            //   量化出的"格"（WHEEL_DELTA/120），`scrollBy()` 起收的是逻辑像素。
            $px = $event->delta * self::WHEEL_NOTCH_PX;

            if ($this->menuElement !== null) {
                $menuBox = $this->menuElement->effectiveBox();

                if ($menuBox !== null && $this->hitsMenu($event, $menuBox)) {
                    $hit = $focus->hitTest($event->x - $this->menuX, $event->y - $this->menuY, $this->menuElement);
                    $element = $hit === null ? null : $focus->elementAt($this->menuElement, $hit);
                    $this->scrollAt($element, $px);

                    return null;
                }

                return null; // 浮层开着、轮在浮层外：不穿透
            }

            $hit = $focus->hitTest($event->x, $event->y, $root);
            $element = $hit === null ? null : $focus->elementAt($root, $hit);
            $this->scrollAt($element, $px);

            return null;
        }

        return null;
    }

    /**
     * 沿命中 Element 的父链找**最近**的可滚动视口并滚动它（滚轮路由）。
     *
     * ★ 找不到 ⇒ 无事发生（轮在不可滚动的区域上是合法的，不报错、不改光标）。
     * ★ **只找最近的**：嵌套滚动容器里，轮落在内层上就只滚内层 —— 外层的
     *   视口不吞内层的事件（§A.15：不预先发明没消费者的相位/规则，嵌套滚动的
     *   "滚动到边界再传给外层"留给真有消费者时）。
     *
     * @internal 由 dispatchInput() 的 ScrollEvent 分支调
     */
    private function scrollAt(?Element $element, float $delta): void
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            if (!$node instanceof StatefulElement) {
                continue;
            }

            $state = $node->state();
            if ($state instanceof ScrollViewState) {
                $state->scrollBy($delta);

                return;
            }
        }
    }

    /** 指针是否落在浮层面板盒内（§9.1 语义 #1 的判据） */
    private function hitsMenu(PointerEvent $event, RenderBox $menuBox): bool
    {
        return $menuBox->hitTest($event->x - $this->menuX, $event->y - $this->menuY) !== null;
    }

    /**
     * 命中的盒是否落在**模态面板**里（而非遮罩上）。
     *
     * ★ `Modal` 的 build 把面板包成 `ModalHost → Center → PopupPanel`，遮罩上的
     *   命中停在 `Center` / `ModalHost`（默认 `hitTest()`：子都不中 ⇒ 返回自己）
     *   ⇒ "父链上有 `RenderPopupPanel`" 就是"点在面板里"的判据。用父链而不是
     *   "命中的是不是 Center"，是为了面板外面再包布局（比如加个底部按钮栏）也成立。
     * ★ 边界：面板盒含 `elevation` 的投影留白 ⇒ 投影那 6 逻辑像素也算"面板内"
     *   （点它不关模态）。要精确到 `panelRect()` 得把坐标换算进盒坐标系，
     *   收益不值这个复杂度 —— 记在这里而不是假装没有。
     */
    private function hitsModalPanel(?RenderObject $hit): bool
    {
        for ($node = $hit; $node !== null; $node = $node->parent()) {
            if ($node instanceof RenderPopupPanel) {
                return true;
            }
        }

        return false;
    }

    /**
     * 指针事件派发（**四相位**，2026-10-17 move/up/leave 通路落地）。
     *
     * - **down**：三段（浮层优先 / 右键菜单 / 焦点激活 —— M3 修复的行为不动）
     *   + 拖放阈值起点（落在 `Draggable` 上记 pending，**不开始拖动**）
     * - **move**：光标刷新 + 悬停派发（目标变化才通知）+ 拖放阈值推进
     * - **up**：拖放放下（激活仍住 down —— 不做 press/release 视觉，A15）
     * - **leave**：悬停全退出 + 拖放取消（无 `SetCapture` 的诚实边界：
     *   拖出窗口即取消，**不卡死**）
     *
     * ★ **浮层开着时四相位都先归浮层**（与 down 同一条"不穿透"纪律）：
     *   命中浮层 ⇒ 菜单项悬停 / 光标；miss 浮层 ⇒ move/leave 只退悬停、
     *   **不关浮层**（关只归 down —— §9.1 语义 #1）。
     */
    private function dispatchPointer(PointerEvent $event, Element $root, FocusManager $focus): ?CursorShape
    {
        if ($this->menuElement !== null) {
            $menuBox = $this->menuElement->effectiveBox();

            if ($menuBox !== null && $this->hitsMenu($event, $menuBox)) {
                // 浮层局部坐标 = 客户区坐标 − 浮层原点（Frame 画时的 translate）
                $hit = $focus->hitTest($event->x - $this->menuX, $event->y - $this->menuY, $this->menuElement);
                $element = $hit === null ? null : $focus->elementAt($this->menuElement, $hit);

                // 模态：浮层盒**盖满客户区** ⇒ `hitsMenu()` 恒真，"点没点到面板"
                // 只能看命中链（见 `hitsModalPanel()`）。点遮罩 = 一次"关闭意图"，
                // 不是"点到了某个控件" ⇒ 不喂 focusHit、不改光标、**不穿透**。
                if ($this->modal !== null && !$this->hitsModalPanel($hit)) {
                    if ($event->isDown()) {
                        if ($this->modal->isDismissible()) {
                            $this->closeModal();
                        }

                        return null; // 不可关时同样吞掉（遮罩后面不能动）
                    }

                    if ($event->isMove() || $event->isLeave()) {
                        $focus->hoverTo(null); // 滑出面板 ⇒ 面板内不再高亮
                    }

                    return null;
                }

                if ($event->isDown()) {
                    // 菜单项（MenuItem extends Button）与根树按钮同规则：焦点落在
                    // 浮层内本次点击上 ⇒ 立即激活。命令经 dispatch() 分发 ⇒ 浮层按
                    // 语义 #2 在分发后关闭 —— 与键盘 Enter 完全同路。
                    if ($focus->focusHit($hit, $this->menuElement)) {
                        $this->activateFocusedWidget();
                    }
                } elseif ($event->isMove()) {
                    // 浮层内悬停：菜单项高亮（§9.1 语义 #5）
                    $focus->hoverTo($element);
                } elseif ($event->isLeave()) {
                    $focus->hoverTo(null);
                }

                return $event->isDown() || $event->isMove() ? $focus->cursorOf($hit) : null;
            }

            if ($event->isDown()) {
                // §9.1 关闭语义 #1：点浮层外 ⇒ 关且**吞掉该次按下**（不穿透，
                // 否则"关菜单"顺手点到下面按钮 —— GUI 仓真机踩过）。
                $this->closeMenu();

                return null; // 吞掉：不喂 focusHit、不改光标
            }

            // move/up/leave 在浮层外：不穿透、不关浮层（关只归 down）。
            // 悬停照退（指针滑出菜单 ⇒ 菜单项不再高亮，但菜单开着）。
            if ($event->isMove() || $event->isLeave()) {
                $focus->hoverTo(null);
            }

            return null;
        }

        $hit = $focus->hitTest($event->x, $event->y, $root);   // ★ 只走一次树
        $element = $hit === null ? null : $focus->elementAt($root, $hit);

        if ($event->isDown()) {
            // ★ 右键最小集（§9.1）：非主键 ⇒ 找最近的 `ContextMenu` 声明者并打开。
            if (!$event->isPrimary()) {
                $this->openContextMenu($element);

                return null;
            }

            // §9.1 动作缝的指针侧：**焦点真落在本次点击上 ⇒ 立即激活**（与键盘
            // Enter/Space 同一入口 activateFocusedWidget）。空点击 / 不可聚焦命中
            // ⇒ focusHit 返回 false，不激活 —— 否则会误触发上一个焦点控件。
            // 按下即激活：up 相位服务拖放，不做 press/release 视觉（A15）。
            if ($focus->focusHit($hit, $root)) {
                $this->activateFocusedWidget();
            }

            // 拖放阈值起点（§A.3.5）：落在 `Draggable` 声明者上记 pending
            $this->beginPendingDrag($element, $event->x, $event->y);

            return $focus->cursorOf($hit);
        }

        if ($event->isMove()) {
            $this->advanceDrag($event->x, $event->y);
            $focus->hoverTo($element);

            return $focus->cursorOf($hit);
        }

        if ($event->isUp()) {
            // 没过阈值的 up ⇒ 纯点击（激活已在 down 发生过）⇒ 丢 pending
            $this->pendingDrag = null;
            $this->pendingDragSource = null;

            if ($this->drag !== null && $this->drag->isDragging()) {
                $this->dropDrag();
            }

            return null;
        }

        // leave：悬停全退出 + 拖放取消（指针离开客户区 —— 无 SetCapture ⇒
        // 拖出窗外收不到后续 move/up，必须在这里收口，否则拖动卡死）。
        $focus->hoverTo(null);
        $this->pendingDrag = null;
        $this->pendingDragSource = null;

        if ($this->drag !== null && $this->drag->isDragging()) {
            $this->cancelDrag();
        }

        return null;
    }

    /**
     * down 落在 `Draggable` 声明者上 ⇒ 记阈值起点（**不开始拖动**）。
     *
     * @internal 由 `dispatchPointer()` 的 down 分支调
     */
    private function beginPendingDrag(?Element $element, float $x, float $y): void
    {
        $owner = $this->draggableOwner($element);

        if ($owner === null) {
            return;
        }

        $draggable = $owner->widget()->draggableValue();

        if ($draggable === null) {
            return;
        }

        $this->pendingDrag = $draggable;
        $this->pendingDragSource = $owner;
        $this->pendingDragX = $x;
        $this->pendingDragY = $y;
    }

    /**
     * move ⇒ 推进拖放阈值状态机：过阈值才真开始拖动，随后刷新悬停目标。
     *
     * @internal 由 `dispatchPointer()` 的 move 分支调
     */
    private function advanceDrag(float $x, float $y): void
    {
        $draggable = $this->pendingDrag;

        if ($draggable === null) {
            // 无 pending：已在拖动 ⇒ 只刷新悬停目标（DragController 自己只
            // 在目标变化时通知）
            if ($this->drag !== null && $this->drag->isDragging()) {
                $this->updateDrag($x, $y);
            }

            return;
        }

        $dx = $x - $this->pendingDragX;
        $dy = $y - $this->pendingDragY;

        if ($dx * $dx + $dy * $dy <= self::DRAG_THRESHOLD * self::DRAG_THRESHOLD) {
            return; // 阈值内：纯点击的移动，不开始拖动
        }

        $source = $this->pendingDragSource;
        $this->pendingDrag = null;
        $this->pendingDragSource = null;
        $this->drag()->begin($draggable, $source);
        $this->updateDrag($x, $y);
    }

    /** 沿父链找最近的 `Draggable` 声明者（§A.3.5：子声明覆盖祖先） */
    private function draggableOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            if ($node->widget()->draggableValue() !== null) {
                return $node;
            }
        }

        return null;
    }

    /**
     * 右键入口（§9.1"右键最小集"）：沿命中 Element 的父链找**最近**的声明者。
     *
     * ★ 找不到 ⇒ 什么也不做（右键落在没有上下文菜单的地方是合法的，不报错、
     *   也不落给焦点 —— 右键不是"选中"）。
     */
    private function openContextMenu(?Element $hit): void
    {
        for ($element = $hit; $element !== null; $element = $element->parent()) {
            $menu = $element->widget()->contextMenuValue();

            if ($menu !== null) {
                $this->showMenu($menu->panel(), $element);

                return;
            }
        }
    }

    /**
     * 派发一条命令（§11.4.3 全链）。
     *
     * 顺序刻意如此：**树内冒泡优先于应用级注册表** —— 页面自己最知道这条命令
     * 是不是它的（`users.refresh` 与列表页的关系，框架不该猜）。返回 true 就停。
     *
     * ★ **第 ①② 环从根开始深度优先**：现在还没有 hitTest（`Input` 层属 M3），
     *   所以"命中的 Element"这一环暂时由根遍历代替 —— 语义等价（都是"从具体
     *   往一般找"），等 hitTest 落地只改这一处。
     *
     * @api
     */
    public function dispatch(string $name, ?CommandArg $arg = null): bool
    {
        $root = $this->root;
        $handled = false;

        if ($root !== null && $this->bubble($root, $name, $arg ?? CommandArg::none())) {
            $handled = true;
        } else {
            $handled = $this->router->dispatch($name, $arg ?? CommandArg::none());
        }

        // §9.1 关闭语义 #2：**命令分发之后才关**浮层 —— 菜单项的处理逻辑可能
        // 自己开关浮层（例如"新建"打开另一个面板），此时先关会把它关掉。
        // ★ **模态不走这条**（§A.3.7「关不关归应用」）：模态里的按钮派完命令，
        //   模态必须还在 —— 否则"确认删除"的对话框会在命令执行前消失，而
        //   "校验失败就留在框里"这种正确写法直接做不到。菜单则相反：项点完
        //   就关是菜单的语义（§9.1 语义 #2）。
        if ($handled && $this->menuElement !== null && $this->modal === null) {
            $this->closeMenu();
        }

        return $handled;
    }

    /** 深度优先找第一个接得的 `State`（第 ①② 环） */
    private function bubble(Element $element, string $name, CommandArg $arg): bool
    {
        if ($element instanceof StatefulElement
            && $element->state()?->handleCommand($name, $arg) === true) {
            return true;
        }

        $accepted = false;
        $element->visitChildren(function (Element $child) use ($name, $arg, &$accepted): void {
            if (!$accepted && $this->bubble($child, $name, $arg)) {
                $accepted = true;
            }
        });

        return $accepted;
    }

    /**
     * 焦点控件的 Enter / Space ⇒ 激活（§9.1 路由链第 ③ 环的激活语义）。
     *
     * 键盘 Enter/Space 激活路径（指针路径走 dispatchPointer() 的 down 分支，
     * 同终点 activateFocusedWidget()，2026-10-17 指针四相位已落地 §24.2 #16）。
     *
     * @internal 由 dispatchInput() 调
     */
    private function activateFocused(KeyEvent $event): void
    {
        if (!$event->chord->is(KeyChord::enter()) && !$event->chord->is(KeyChord::make('Space'))) {
            return;
        }

        $this->activateFocusedWidget();
    }

    /**
     * 激活当前焦点控件 —— 键盘（Enter/Space）与指针（按下）两路径的**唯一**入口。
     *
     * §9.1 动作缝：Menu 走 `onOpen`（应用接线到 `showMenu()`）、Button 走
     * 命令（全链）或 `onPressed`。Menu 分支必须在 Button 之前 —— 子类会被
     * `instanceof` 认成父类。
     */
    private function activateFocusedWidget(): void
    {
        $element = $this->focus?->focused();
        $widget = $element?->widget();

        // ★ 菜单标题（`Menu extends Button`）⇒ 走 `onOpen`（应用接线到 `showMenu()`）。
        //   分支必须在 `Button` 之前 —— 子类会被 `instanceof` 认成父类。
        if ($widget instanceof Menu) {
            $open = $widget->openHandler();
            if ($open !== null) {
                $open($widget, $element);
            }

            return;
        }

        if (!$widget instanceof Button) {
            return;
        }

        // 命令优先（走全链：冒泡 → 注册表）；onPressed 是纯 UI 回调，框架兜底调
        $name = $widget->commandName();
        if ($name !== null) {
            $this->dispatch($name, $widget->commandArg() ?? CommandArg::none());

            return;
        }

        $handler = $widget->pressedHandler();
        if ($handler !== null) {
            $handler();
        }
    }

    /**
     * @internal 测试与 devtools 用：注册一个 handler（不必进构造器）
     */
    public function register(string $name, CommandHandler $handler): void
    {
        $this->router->on($name, $handler);
    }

    // ── 应用内拖放（§A.3.5）：声明住 Widget，状态机住这里 ──────────────

    /**
     * 拖动状态机（§A.3.5）。
     *
     * ★ **真机指针序列已接线**（2026-10-17，§24.2 #16）：`down` 在 `Draggable`
     *   上记阈值起点（`beginPendingDrag`，**按下 ≠ 拖动**），`move` 过
     *   `DRAG_THRESHOLD` 才 `begin`，`up` 放下，`leave`/ESC 取消。宿主 / 测试
     *   仍可直接调这四个入口（与 `Spinner::tick()` 同一个分期形态）。
     */
    public function drag(): DragController
    {
        return $this->drag ??= new DragController();
    }

    /**
     * @internal 开始拖动（源 = 最近命中的 Element；命中处须有 `Draggable` 声明）
     */
    public function beginDrag(Draggable $draggable): bool
    {
        $this->drag()->begin($draggable, $this->focus?->lastHit());

        return true;
    }

    /**
     * @internal 指针移动：刷新悬停目标（**目标变化才通知** `onHover`）
     */
    public function updateDrag(float $x, float $y): void
    {
        $root = $this->root;
        $focus = $this->focus;
        $drag = $this->drag;

        if ($root === null || $focus === null || $drag === null || !$drag->isDragging()) {
            return;
        }

        $hit = $focus->hitTest($x, $y, $root);

        $drag->hoverTo($hit === null ? null : $focus->elementAt($root, $hit));
    }

    /**
     * @internal 放下：命中 `DragTarget` ⇒ 交出负载。
     *
     * @return bool 是否被接收
     */
    public function dropDrag(): bool
    {
        return $this->drag?->drop() ?? false;
    }

    /** @internal 取消拖动（ESC 也会cancel —— 见 `dispatchInput()`） */
    public function cancelDrag(): void
    {
        $this->drag?->cancel();
    }

    /**
     * @internal 测试：把根盒拿出来做几何断言
     *
     * ★ 用 `effectiveBox()`：应用根常是 `StatelessWidget`（无盒），能排的是它
     *   底下的容器 —— 用 `renderObject()` 会得到 null，看不出"到底是没排开
     *   还是根本没有可绘制内容"。
     */
    public function rootBox(): ?RenderBox
    {
        return $this->root?->effectiveBox();
    }
}
