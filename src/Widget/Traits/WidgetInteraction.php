<?php
declare(strict_types=1);

namespace Drift\Widget\Traits;

use Drift\Input\KeyResult;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Drag\DragTarget;
use Drift\Widget\Menu\ContextMenu;

/**
 * `Widget` 的**交互面**：回调槽与一一对应的读侧。
 *
 * 每个槽都是 `xxx(Closure)` + 一个 `xxxValue()`：写侧只在构建期调用，读侧给
 * 命中链 / 帧管线问"声明了什么"。读侧标 `@internal`：应用不该读自己的槽。
 *
 * @internal 与 `Widget` 同生共死
 */
trait WidgetInteraction
{
    /**
     * 声明"本控件及其子树可被拖动"。
     * 拖动状态机住 `Drift\App\DragController`；指针驱动：down 记意图 → move 过阈值 begin → up 放置。
     * @api
     */
    public function draggable(Draggable $value): static
    {
        $this->mutate();
        $this->draggable = $value;

        return $this;
    }

    /** @internal 拖动状态机读它；`null` = 不可拖 */
    public function draggableValue(): ?Draggable
    {
        return $this->draggable;
    }

    /**
     * 声明"本控件接受放置"。
     * @api
     */
    public function dragTarget(DragTarget $value): static
    {
        $this->mutate();
        $this->dragTarget = $value;

        return $this;
    }

    /** @internal 拖动状态机读它；`null` = 不接受放置 */
    public function dragTargetValue(): ?DragTarget
    {
        return $this->dragTarget;
    }

    /**
     * 声明右键弹出的上下文菜单（挂在子树上，命中链向上找最近的声明者）。
     * @api
     */
    public function contextMenu(ContextMenu $value): static
    {
        $this->mutate();
        $this->contextMenu = $value;

        return $this;
    }

    /** @internal 右键入口读它（`Application::dispatchInput()` 的指针分支） */
    public function contextMenuValue(): ?ContextMenu
    {
        return $this->contextMenu;
    }

    /**
     * 声明"指针悬停在我上面时通知我"。
     * 只在目标变化时派发（进入 / 离开各一次）；回调签名 `function (bool $entered): void`。
     * @api
     */
    public function hover(\Closure $handler): static
    {
        $this->mutate();
        $this->hoverHandler = $handler;

        return $this;
    }

    /** @internal 悬停派发读它；`null` = 不参与悬停（交给命中链上更近的祖先） */
    public function hoverHandlerValue(): ?\Closure
    {
        return $this->hoverHandler;
    }

    /**
     * 声明"我要处理指针事件"。
     * ★ 捕获不是"能收到更多事件"：捕获期指针事件不再命中测试（拖到控件外仍归我）；
     *   抬起 / 捕获者卸载 / 窗口失焦时自动释放。
     * `$ownsPress = true` ⇒ 命中链上最近声明者接管按下，跳过 `activateFocusedWidget()`，
     *   焦点照旧落上（键盘可达）。坐标是客户区坐标，要局部坐标减 `RenderBox::absoluteOrigin()`；
     *   回调第二参是命中的 `Element`（盒每帧重建 ⇒ 现取 `effectiveBox()`，别缓存盒）。
     * @api
     */
    public function pointer(\Closure $handler, bool $ownsPress = false): static
    {
        $this->mutate();
        $this->pointerHandler = $handler;
        $this->pointerOwnsPress = $ownsPress;

        return $this;
    }

    /** @internal 捕获派发读它；`null` = 本控件不接受捕获期事件 */
    public function pointerHandlerValue(): ?\Closure
    {
        return $this->pointerHandler;
    }

    /** @internal 按下路由读它；`true` = 本次按下由本控件接管（不激活） */
    public function pointerOwnsPressValue(): bool
    {
        return $this->pointerOwnsPress;
    }

    /**
     * 声明"按在本控件上就拖动窗口"（无边框窗的标题栏替代品）。
     * 只在命中的"本控件这一层"生效：沿父链遇到自己处理按下的控件（可聚焦 /
     * `Activatable` / 有 `pointer()`）就立刻让位，否则按钮会被一起拖走。
     * 闭包无参；只在按下时调一次，不捕获指针（拖窗鼠标循环归平台）。
     * @api
     */
    public function windowDrag(\Closure $handler): static
    {
        $this->mutate();
        $this->windowDragHandler = $handler;

        return $this;
    }

    /** @internal 按下路由读它；`null` = 本控件不声明窗口拖动 */
    public function windowDragHandlerValue(): ?\Closure
    {
        return $this->windowDragHandler;
    }

    /**
     * 声明"本控件（及其子树）接收系统拖放进来的文件"。
     * 落点是命中 → 沿父链冒泡找最近声明者（`InputRouter::dispatchFilesDropped`），
     * 所以包裹式控件也能挂。找不到声明者 ⇒ 静默丢弃（拖到不接收处是合法的）。
     * 闭包 `\Closure(FilesDroppedEvent): void`；异常由路由层兜住、不吞消息泵。
     * @api
     */
    public function onFilesDropped(\Closure $handler): static
    {
        $this->mutate();
        $this->filesDropHandler = $handler;

        return $this;
    }

    /** @internal 拖放路由读它；`null` = 本控件不接收文件拖放 */
    public function filesDropHandlerValue(): ?\Closure
    {
        return $this->filesDropHandler;
    }

    /**
     * 声明"我要处理键盘"。
     * ★ 沿焦点控件的父链找"最近的声明者"（`FocusManager::dispatchKey`）——
     *   所以包裹式控件也能挂：`ComboBox` 包 `TextField` 时焦点在 `TextField`，
     *   而 `ComboBox` 自己声明的 `keyboard()` 照样收得到。
     * 回调 `\Closure(KeyEvent, Element): (bool|KeyResult)`：
     * ★ 两个形参必须写全：AOT 校验实参个数、解释器忽略多余实参（lint `callback-arity` 守）。
     * - `true` / `KeyResult::Handled` ⇒ 吃掉这个键；
     * - `false` / `KeyResult::Owned` ⇒ 不吃，但**这个键仍归我**（下游各环照跑，
     *   框架的方向键导航不跑）。默认口径，`Select` 面板关着靠它让位给激活；
     * - `KeyResult::Declined` ⇒ 不要这个键，交回框架默认语义（方向键几何导航 /
     *   翻页键滚容器）。**只认一两个键的控件必须用它**，否则就等于整组按键归我
     *   （树只认 ←→、日历只认 PageUp/PageDown 都是这么写的）。
     * 第二个参数是**声明者自己**的 `Element`（拿 `effectiveBox()` 用）—— 不是焦点控件；
     * 焦点控件可能在这条链的更深处（`ComboBox` 包 `TextField` 时就是这个形状）。
     * 只对焦点控件那一支调（Tab / Shift+Tab 之后）。
     * @api
     */
    public function keyboard(\Closure $handler): static
    {
        $this->mutate();
        $this->keyHandler = $handler;

        return $this;
    }

    /** @internal 键盘派发读它；`null` = 本控件不处理按键 */
    public function keyHandlerValue(): ?\Closure
    {
        return $this->keyHandler;
    }

    /**
     * 声明"悬停在我上面时显示这段提示"。
     * 浮层生命周期归 `Application`（控件只把文字交出去）。多行由 `\n` 表达，渲染侧负责换行。
     * @param string $text 单行提示文本
     * @api
     */
    public function tooltip(string $text): static
    {
        $this->mutate();
        $this->tooltipText = $text;

        return $this;
    }

    /**
     * @internal Tooltip 浮层读它；空串 = 本控件无提示（不用 `?string`）
     */
    public function tooltipTextValue(): string
    {
        return $this->tooltipText;
    }

    /**
     * 声明"激活我时，浮一个面板"。
     * 工厂收一个 `dismiss` 闭包（面板里的按钮拿不到 `Application`，只能由
     * `Application` 在开面板时注入）。声明了本字段 ⇒ 激活时开面板，不走 `onPressed` / 命令。
     * 面板的落位 / 锚点 / 归属 / 关闭归 `Application`。
     * @param \Closure(\Closure): Widget $factory 形参 = `dismiss`，返回值 = 面板内容
     * @param float|null $maxWidth 面板宽上限（逻辑像素）。`null` = 框架默认（320）；
     *        需要并排两块的面板必须自报，否则默认上限会把 `Row` 挤爆。
     * @api
     */
    public function overlayPanel(\Closure $factory, ?float $maxWidth = null): static
    {
        $this->mutate();
        $this->overlayPanelFactory = $factory;
        $this->overlayPanelMaxWidth = $maxWidth;

        return $this;
    }

    /** @internal 激活时浮出的面板工厂；`null` = 本控件激活时不开面板 */
    public function overlayPanelValue(): ?\Closure
    {
        return $this->overlayPanelFactory;
    }

    /** @internal 面板宽上限（`null` = 用框架默认） */
    public function overlayPanelMaxWidthValue(): ?float
    {
        return $this->overlayPanelMaxWidth;
    }

    /**
     * 声明"我的面板在意什么" —— 一个字符串签名。
     * ★ 签名必须是"语义"而不是"身份"：应用每次重建都造新实例，用对象身份比
     *   必然"天天在变" ⇒ 面板每帧重建 ⇒ 列表滚动位置回顶部。
     * 求值时机：框架在锚点 Widget 换了之后才调一次，没重建就一次不调。
     * @param \Closure(): string $signature
     * @api
     */
    public function overlaySignature(\Closure $signature): static
    {
        $this->mutate();
        $this->overlaySignature = $signature;

        return $this;
    }

    /** @internal 面板内容签名；`null` = 本控件不声明（框架不传播受控值变化） */
    public function overlaySignatureValue(): ?\Closure
    {
        return $this->overlaySignature;
    }
}
