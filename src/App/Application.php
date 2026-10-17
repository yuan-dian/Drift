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
use Drift\Layout\RenderBox;
use Drift\Layout\RenderPopupPanel;
use Drift\Rendering\RenderSurface;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Geometry\Rect;
use Drift\Text\TextMetricsProvider;
use Drift\Widget\TextFieldState;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Element;
use Drift\Widget\Menu\Menu;
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

    /** **布局确定性的注入点**（§14.2）：度量由宿主给，不由平台探测 */
    public function metrics(): TextMetricsProvider
    {
        return new DeterministicMetricsProvider(
            DeterministicFontManager::standard()->collection(),
            new DeterministicShaper(),
        );
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
    }

    public function isMenuOpen(): bool
    {
        return $this->menuElement !== null;
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
     * 浮层的布局段（O-20 说"三段各加分支"，layout 落在这里）。
     *
     * 三件事，都要锚点或客户区尺寸 ⇒ 只能住应用级：
     *   ① **无界约束** layout（面板自尺寸 —— 它不知道外面多大）；
     *   ② 面板矩形左上角钉到锚点；
     *   ③ **夹进客户区**（★ 按**面板**矩形算，不含投影留白 —— §9.1 语义 #6）。
     */
    private function placeMenu(BoxConstraints $constraints): void
    {
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
            // §9.2 事件通路的终点：显式消费（不落 default 丢弃）。框架侧**暂不
            // 动作** —— `Screen::systemTheme()` 查询实时 + `Frame::run` 每帧
            // `settle()` ⇒ 主题值变化下一帧自然重建。显式的"标全树脏"重建机制
            // 按 A15（没有消费者不预先发明）留待应用真需要跟随系统主题时，
            // 与 §A.2.5 的 `onSystemThemeChanged()` 钩子一并对齐。
            return null;
        }

        if ($event instanceof KeyEvent) {
            // §9.1 关闭语义 #3：**ESC 在 `dispatchKey` 之前拦** —— 否则被焦点
            // 控件（输入框）吃掉，菜单关不掉。
            if ($event->chord->is(KeyChord::escape())) {
                if ($this->menuElement !== null) {
                    $this->closeMenu();

                    return null;
                }

                if ($this->drag?->isDragging() === true) {
                    $this->cancelDrag();

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
            // §9.1 关闭语义 #1：**浮层开着 ⇒ 指针先归浮层**（先选根树）；
            // 点浮层**外** ⇒ 关且**吞掉该次按下**（不穿透，否则"关菜单"顺手点到
            // 下面按钮 —— GUI 仓真机踩过）。
            if ($this->menuElement !== null) {
                $menuBox = $this->menuElement->effectiveBox();

                if ($menuBox !== null && $this->hitsMenu($event, $menuBox)) {
                    // 浮层局部坐标 = 客户区坐标 − 浮层原点（Frame 画时的 translate）
                    $hit = $focus->hitTest($event->x - $this->menuX, $event->y - $this->menuY, $this->menuElement);
                    // 菜单项（MenuItem extends Button）与根树按钮同规则：焦点落在
                    // 浮层内本次点击上 ⇒ 立即激活。命令经 dispatch() 分发 ⇒ 浮层按
                    // 语义 #2 在分发后关闭 —— 与键盘 Enter 完全同路。
                    if ($focus->focusHit($hit, $this->menuElement)) {
                        $this->activateFocusedWidget();
                    }

                    return $focus->cursorOf($hit);
                }

                $this->closeMenu();

                return null; // 吞掉：不喂 focusHit、不改光标
            }

            $hit = $focus->hitTest($event->x, $event->y, $root);   // ★ 只走一次树

            // ★ 右键最小集（§9.1）：非主键 ⇒ 找最近的 `ContextMenu` 声明者并打开。
            //   用 `down` 即弹（`up` / 位移阈值要 M4 的指针事件，见 `ContextMenu` docblock）。
            if (!$event->isPrimary()) {
                $this->openContextMenu($hit === null ? null : $focus->elementAt($root, $hit));

                return null;
            }

            // §9.1 动作缝的指针侧：**焦点真落在本次点击上 ⇒ 立即激活**（与键盘
            // Enter/Space 同一入口 activateFocusedWidget）。空点击 / 不可聚焦命中
            // ⇒ focusHit 返回 false，不激活 —— 否则会误触发上一个焦点控件。
            // 按下即激活：当前指针只有 down 相位（up 相位要 P1 的指针事件）。
            if ($focus->focusHit($hit, $root)) {
                $this->activateFocusedWidget();
            }

            return $focus->cursorOf($hit);
        }

        return null;
    }

    /** 指针是否落在浮层面板盒内（§9.1 语义 #1 的判据） */
    private function hitsMenu(PointerEvent $event, RenderBox $menuBox): bool
    {
        return $menuBox->hitTest($event->x - $this->menuX, $event->y - $this->menuY) !== null;
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
        if ($handled && $this->menuElement !== null) {
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
     * ★ **指针激活缺席的诚实边界**：`PointerEvent` 今天只有 `down()`（抬起 /
     *   移动留给 M4 完整输入层）⇒ 鼠标点按钮只给焦点，激活走键盘。这是输入层
     *   分期的结构后果（同 §11.5 的 loading 态边界），不是漏接。
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
     * ★ **真机指针序列等 M4**：`PointerEvent` 只有 `down()`（`move`/`up` 缺席）
     *   ⇒ 下面三个入口当前由宿主 / 测试驱动（与 `Spinner::tick()` 同一个分期形态）。
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
