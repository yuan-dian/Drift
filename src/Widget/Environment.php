<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 控件能用的**窗口级能力**：浮层、定时器、平台端口、窗口拖动。
 *
 * ## 为什么契约住 Widget 层
 *
 * 实现是 `Drift\App\AppEnvironment`，但契约必须住这里 —— 否则
 * `Widget → App` 成环，而那意味着控件能 import 帧循环。分层方向只有
 * `App → Widget` 这一条；能力倒着要，就得把**接口**留在下层。
 *
 * ## 为什么端口方法只收 string / array / bool
 *
 * `Dialog` / `Clipboard` 住 `Drift\Platform`，而 `Widget` 的允许集不含它。
 * 把这两个类型写进签名，就等于为"控件能拿平台实现"放开整层。这里改写成
 * 值参数投影（`openFiles(string, array): array`），语义一字不差，
 * Widget 层的 Platform 依赖仍为 0。
 *
 * ## 为什么是一个接口而不是拆成六个
 *
 * 拆开（`SnackbarHost` / `Timer` / `FilePicker` …）会让注入点从 1 个变 N 个 ——
 * 那正是本接口要消灭的东西（4 个应用各写一种能力递送形状）。控件拿到的是
 * "整个窗口有什么"，不是"某一项能力"；它不用的方法不会被执行。
 *
 * ## 缺能力时的语义（不看实现，只看契约）
 *
 * 平台没实现端口 ⇒ `?T` 返回 `null`、`array` 返回 `[]`、`bool` 返回 `false`。
 * **不是异常**：越界与"这个平台确实没有"是两件事。实现在这种情况下会留一条
 * Diagnostic，让"点了没反应"可追。
 *
 * @api
 */
interface Environment
{
    // ── 浮层（本窗私有：走本窗浮层栈，跨窗弹错窗口）────────────────────

    /** 弹一条底部通知（第一条立即显示，后面 FIFO 排队）。 */
    public function notify(Snackbar $snackbar): void;

    /** 收掉当前通知，有排队则立刻上队首。幂等。 */
    public function dismissSnackbar(): void;

    public function showModal(Modal $modal): void;

    public function closeModal(): void;

    /**
     * 打开浮层面板（菜单 / 下拉 / 右键菜单共用这一个槽位）。
     *
     * @param float|null $atX 指针位置（客户区坐标）；给了就弹在这一点
     * @param float|null $atY 同上
     */
    public function showMenu(
        Widget $panel,
        ?Element $anchor = null,
        ?float $atX = null,
        ?float $atY = null,
    ): void;

    public function closeMenu(): void;

    // ── 定时器（本窗帧泵；`frame()` 里到期才跑）────────────────────────

    /**
     * 排一个延时回调，返回 id（用 `cancelDelay()` 撤）。
     *
     * @param \Closure(): void $callback
     */
    public function delay(int $milliseconds, \Closure $callback): int;

    public function cancelDelay(int $id): void;

    // ── 平台端口（组合根注入；不随窗，多窗口共用一份）──────────────────

    /**
     * @param list<array{0: string, 1: string}> $filters `[显示名, 通配符]`
     */
    public function openFile(string $title, array $filters = []): ?string;

    /**
     * @param list<array{0: string, 1: string}> $filters
     * @return list<string> 取消 ⇒ 空数组（不是失败）
     */
    public function openFiles(string $title, array $filters = []): array;

    /**
     * @param list<array{0: string, 1: string}> $filters
     */
    public function saveFile(string $title, string $defaultName = '', array $filters = []): ?string;

    public function alert(string $title, string $body): void;

    public function confirm(string $title, string $body): bool;

    /** 剪贴板为空、内容不是文本，或平台没实现 ⇒ `null` */
    public function clipboardText(): ?string;

    public function setClipboardText(string $text): void;

    public function hasClipboardText(): bool;

    // ── 窗口（无边框窗才支持；顶条要能拖、能自绘按钮）─────────────────

    /**
     * 本窗是否支持自绘顶条拖动 —— `build()` 期用它决定"画不画窗口按钮"
     * （`false` = 有原生标题栏，再画三个按钮就成了两套）。
     */
    public function supportsWindowDrag(): bool;

    /**
     * 开始拖动本窗（`WM_NCHITTEST` 那条路的替代）。不支持 ⇒ 无事发生。
     */
    public function startWindowDrag(): void;
}
