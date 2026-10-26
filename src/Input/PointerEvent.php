<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 一次指针事件（§9.1 路由链：`PointerEvent → 命中测试（RenderObject 树，从前到后）`）。
 *
 * 坐标是**逻辑单位**（§6.1），相对**视口左上角** —— 与 `RenderBox::hitTest()`
 * 同一坐标系（物理像素是 `Platform` 层的事，不进这一层）。
 *
 * ★ **四相位**（2026-10-17 落地）：`Down` / `Move` / `Up` / `Leave`
 *   （`PointerPhase`）。down 驱动"点一下 ⇒ 给焦点/激活"；move 驱动**光标刷新、
 *   悬停派发、拖放过程**（只在目标变化时通知 —— §9.1 语义 #5）；up 驱动拖放
 *   放下与释放；leave 驱动悬停全退出与拖放取消（无 `SetCapture` 的诚实边界：
 *   拖出窗口即取消，不卡死）。
 *
 * ★ **为什么相位住在本类而不是四个事件类**：四相位同坐标系、同命中链、同三方
 *   消费（一次 hitTest 喂光标/焦点/拖放）⇒ 是"指针事件的不同相位"。对照
 *   `ScrollEvent`：滚轮无相位、不参与焦点/光标 ⇒ 才独立成类。
 *
 * @api
 */
final readonly class PointerEvent implements InputEvent
{
    private function __construct(
        public float $x,
        public float $y,
        public int $button,
        public PointerPhase $phase = PointerPhase::Down,
        public bool $ctrl = false,
        public bool $alt = false,
        public bool $shift = false,
    ) {
    }

    /**
     * `$button`：0 = 主键（左键），1 = 中键，2 = 右键（§9.1 上下文菜单走右键手势）
     *
     * ★ 后三个是**按下这一刻**的修饰键（2026-10-26 加）。它们是 Ctrl/Shift+点击的
     *   唯一通道：表格的多选（Ctrl 切换 / Shift 连选）、拖拽修饰都靠它。缺了它，
     *   "多选"只能退化成"点一下就切换"，而 Shift 连选**表达不出来**。
     *   ★ 三个都有默认值 ⇒ 既有调用点（`src/` 15 处 + `tools/` 夹具）一字不改。
     */
    public static function down(
        float $x,
        float $y,
        int $button = 0,
        bool $ctrl = false,
        bool $alt = false,
        bool $shift = false,
    ): self {
        return new self($x, $y, $button, PointerPhase::Down, $ctrl, $alt, $shift);
    }

    /** 移动。`$button` 是**当前按住**的键（没按 = 0）—— 拖动过程由此识别 */
    public static function move(
        float $x,
        float $y,
        int $button = 0,
        bool $ctrl = false,
        bool $alt = false,
        bool $shift = false,
    ): self {
        return new self($x, $y, $button, PointerPhase::Move, $ctrl, $alt, $shift);
    }

    /** 抬起。`$button` 是**松开**的那个键 */
    public static function up(
        float $x,
        float $y,
        int $button = 0,
        bool $ctrl = false,
        bool $alt = false,
        bool $shift = false,
    ): self {
        return new self($x, $y, $button, PointerPhase::Up, $ctrl, $alt, $shift);
    }

    /**
     * 离开客户区。坐标是**最后已知位置**（系统不再给新坐标）—— 消费方只看
     * "离开了"，不用坐标。
     */
    public static function leave(float $x, float $y): self
    {
        return new self($x, $y, 0, PointerPhase::Leave);
    }

    /**
     * 桥的事件（`phase` = ABI 编号）→ 本类实例。
     *
     * ★ **映射只该有一处**（与 `CursorShape` 的 value=ABI 同纪律）：桥发 0–3，
     *   这里翻译成四个工厂 —— 改 `PointerPhase` 的 value 只需同步这一处读法。
     *   ★ 未知 phase 按 Down 兜（防御性：桥保证只发 0–3，与 `pump()` 的
     *   "其它 ⇒ 丢弃"同一档）。
     */
    public static function fromBridge(
        float $x,
        float $y,
        int $button,
        int $phase,
        bool $ctrl = false,
        bool $alt = false,
        bool $shift = false,
    ): self {
        return match (PointerPhase::tryFrom($phase) ?? PointerPhase::Down) {
            PointerPhase::Down => self::down($x, $y, $button, $ctrl, $alt, $shift),
            PointerPhase::Move => self::move($x, $y, 0, $ctrl, $alt, $shift),
            PointerPhase::Up => self::up($x, $y, $button, $ctrl, $alt, $shift),
            // Leave 不带修饰键：消费方只看"离开了"
            PointerPhase::Leave => self::leave($x, $y),
        };
    }

    /** 有没有按住任何修饰键（"纯点击" vs "带修饰的点击"的分叉点） */
    public function hasModifier(): bool
    {
        return $this->ctrl || $this->alt || $this->shift;
    }

    public function isPrimary(): bool
    {
        return $this->button === 0;
    }

    public function isDown(): bool
    {
        return $this->phase === PointerPhase::Down;
    }

    public function isMove(): bool
    {
        return $this->phase === PointerPhase::Move;
    }

    public function isUp(): bool
    {
        return $this->phase === PointerPhase::Up;
    }

    public function isLeave(): bool
    {
        return $this->phase === PointerPhase::Leave;
    }
}
