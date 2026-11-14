<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 一条底部通知的**描述**（W8-2，2026-10-23）。
 *
 * ```php
 * // 组合根（唯一持有 Application 的地方，§4.3）把能力接线给页面：
 * $app = new MyApplication();
 * $app->run(MyRoot::make($app->snackbarPort()));   // 或任意"应用侧接线"形态
 *
 * // 页面里：
 * ($this->notify)(Snackbar::make('已保存')->action('撤销', function (): void { ... }));
 * ```
 *
 * ★★ **`$this->application()` 不存在** —— `State` / `Widget` 上都没有这个访问器
 *   （§4.3 禁 `Widget → App`，浮层归 `Application` 管）。2026-10-23 我在
 *   `Application::showSnackbar()` 的 docblock 和示例台里都写过它，结果示例台
 *   **真机点一下就把进程打死**（未定义方法 ⇒ AOT 侧直接崩），而 smoke 全绿
 *   —— 因为 smoke 的夹具本来就拿着 `Application`，走的不是那条路。
 *   ⇒ 应用侧要触发浮层，只有两条合规路：**组合根接线**（本注释这条，`Menu::onOpen`
 *     同款）或**框架托管**（`Widget::tooltip()` 那种声明式）。
 *
 * ★★ **它不是 Widget，这是刻意的**（与 `Modal` 相反）：
 *   ① **动作按钮要能关掉自己** —— Widget 一旦 seal 就拿不到 `Application`
 *      （§5.1 禁 `Widget → App`），所以"点了动作就收掉这条"只能由 `Application`
 *      在组面板时注入（见 `SnackbarPanel::make()` 的第二个参数）；
 *   ② **它的生命周期是一次性的**：显示 → 消失 → 丢弃，没有 diff 复用需求。
 *      `Modal` 做 Widget 是因为"内容变了要复用盒"，Snackbar 不会；
 *   ③ **队列里排着的那几条还没挂载**，本来就不该是 Widget。
 *   ⇒ 与 `OverlayEntry`（也是纯描述 `final class`）同形。
 *
 * ★ 链式方法不调 `mutate()`：这里没有"seal 之后不许改"的纪律要守 ——
 *   对象在 `showSnackbar()` 那一刻就被消费掉了。
 *
 * @api
 */
final class Snackbar
{
    /**
     * 默认停留时长（毫秒）。
     *
     * ★ 4000 是 Material 的默认值，也是"够读完两行中文"的量级。
     */
    public const int DEFAULT_DURATION_MS = 4000;

    /** 面板宽度上限（逻辑像素）：一行通知不该横穿整个窗口 */
    public const float MAX_WIDTH = 480.0;

    /**
     * 面板**内容**最小宽（逻辑像素）。
     *
     * ★★ 不是"好看一点"，是**必须的**：`RenderPopupPanel` 用
     *   `max(minWidth, child->intrinsicWidth(INF))` 定内容宽，而 `Button` 在
     *   **无界高度**下的 `intrinsicWidth()` 会失真（返回远小于实际）⇒ 内容宽被
     *   算成正文那行字的宽（如 42），布局时动作按钮要 50 ⇒
     *   `RenderFlex 放不下：子项共需 50.0，可用只有 42.0` **直接中止**
     *   （2026-10-23 实测）。
     *   ⇒ 给一个内容最小宽（> 按钮自然宽）就把这条失真路径绕开了；顺带让通知
     *     不会窄成一条（短文案 + 按钮挤在一起的形态本来就难看）。
     *   ★ 长文案不受影响：`max(220, 全文宽)` ⇒ 面板被 `MAX_WIDTH` 夹住 ⇒
     *     正文换行、按钮仍有位置。
     */
    public const float MIN_WIDTH = 220.0;

    /** 离客户区底边的留白（逻辑像素） */
    public const float MARGIN = 24.0;

    private string $message;

    /** 动作按钮文字；**空串 = 没有动作按钮**（不用 `?string`，见 `actionLabelValue()`） */
    private string $actionLabel = '';

    /** 动作处理器；`null` = 没有 */
    private ?\Closure $actionHandler = null;

    private int $duration = self::DEFAULT_DURATION_MS;

    private function __construct(string $message)
    {
        $this->message = $message;
    }

    public static function make(string $message): self
    {
        return new self($message);
    }

    /**
     * 加一个动作按钮（通常是「撤销」/「查看」）。
     *
     * ★ 点击后**先调 `$handler`、再收掉这条通知**（Material 行为）。
     *   ★ 诚实说明：这个顺序**其实不影响结果** —— 处理器里 `showSnackbar()` 时
     *     当前这条还在显示 ⇒ 新那条进队列；随后的 `dismissSnackbar()` 收掉当前
     *     并立刻让队首上位。反过来先收再调也一样（那时队列已空 ⇒ 直接显示）。
     *     保留这个顺序只为读起来符合因果，**别把这条注释当成约束去优化**。
     */
    public function action(string $label, \Closure $handler): static
    {
        $this->actionLabel = $label;
        $this->actionHandler = $handler;

        return $this;
    }

    /**
     * 停留时长（毫秒）。**`0` = 永不自动消失**，要显式 `dismissSnackbar()`。
     *
     * ★ `0` 在这里是**合法语义**（"0 毫秒后消失"本身无意义）⇒ 符合哨兵三档定则：
     *   哨兵必须能写成一个有名字的东西。错误提示要用户确认时就给 0。
     */
    public function duration(int $milliseconds): static
    {
        $this->duration = $milliseconds < 0 ? 0 : $milliseconds;

        return $this;
    }

    public function messageValue(): string
    {
        return $this->message;
    }

    /**
     * 动作按钮文字。
     *
     * ★ **空串 = 没有动作**，不用 `?string` —— 可选标量在 AOT 下退化 `php::Var`
     *   （2026-10-23 的 Tooltip 就是被这个坑咬了一口，见 `Widget::$tooltipText`）。
     */
    public function actionLabelValue(): string
    {
        return $this->actionLabel;
    }

    public function actionHandlerValue(): ?\Closure
    {
        return $this->actionHandler;
    }

    /** 停留时长（毫秒；`0` = 永不自动消失） */
    public function durationValue(): int
    {
        return $this->duration;
    }
}
