<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Widget\Element;
use Drift\Widget\Environment;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;
use Drift\Widget\Widget;

/**
 * {@see Environment} 的唯一实现：应用侧能力的收口点。
 *
 * ## 三类来源
 *
 * | 能力 | 来源 | 为什么在那儿 |
 * |---|---|---|
 * | 浮层 / 定时器 | 本窗 `OverlayManager` / `PumpHost` | 窗口私有：跨窗共享会把 A 窗的通知弹到 B 窗 |
 * | 对话框 / 剪贴板 | `AppScope` 的端口槽 | 一个进程一份（`Win32Dialog` 的 owner 每次现取，不绑窗口） |
 * | 窗口拖动 | 组合根 `windowDrag()` 注册的闭包 | 平台实现只有组合根能提，这里只持不透明闭包 |
 *
 * ## 不持有 Application
 *
 * 门面只暴露能力，不暴露 `frame()` / `attach()` / `input` —— Store 与控件拿到它
 * 也摸不到帧循环。宿主片段构造期注入，不反向查找。
 *
 * ## 为什么 `windowDrag` 是可变槽（本类唯一非 readonly 字段）
 *
 * 组合根在**建窗之后**才知道拖动怎么实现（要 `WindowHost` 与窗口句柄，两者都在
 * `WindowSet::open()` 的回调里）。而 `AppEnvironment` 在 `Application` 构造时就建好
 * —— 早于建窗。所以能力是**后注册、先可用**：注册前 `supportsWindowDrag()` 是
 * `false`，`build()` 期据此决定画不画自绘按钮，正好对上"无边框窗 -> 顶条要按钮"。
 *
 * @api
 */
final class AppEnvironment implements Environment
{
    /** 组合根注册的窗口拖动；`null` = 有原生标题栏 */
    private ?\Closure $windowDrag = null;

    /** 缺端口时只报一次 —— 一遍一遍刷 Diagnostic 会把 ring 里别的线索挤掉 */
    private bool $dialogMissingReported = false;

    private bool $clipboardMissingReported = false;

    public function __construct(
        private readonly OverlayManager $overlay,
        private readonly PumpHost $pumps,
        private readonly AppScope $scope,
    ) {
    }

    /** 组合根在 `attach()` 之前调；`null` 也能传（清掉已注册的，多窗口关掉无边框时用不到但不该崩） */
    public function setWindowDrag(?\Closure $drag): void
    {
        $this->windowDrag = $drag;
    }

    // ── 浮层 ────────────────────────────────────────────────────────────

    public function notify(Snackbar $snackbar): void
    {
        $this->overlay->showSnackbar($snackbar);
    }

    public function dismissSnackbar(): void
    {
        $this->overlay->dismissSnackbar();
    }

    /** 当前是否有通知在显示（devtools / 测试读法，不进 `Environment` 契约） */
    public function hasSnackbar(): bool
    {
        return $this->overlay->hasSnackbar();
    }

    /** 排队等待的通知条数（同上） */
    public function snackbarQueueLength(): int
    {
        return $this->overlay->snackbarQueueLength();
    }

    public function showModal(Modal $modal): void
    {
        $this->overlay->showModal($modal);
    }

    public function closeModal(): void
    {
        $this->overlay->closeModal();
    }

    public function showMenu(
        Widget $panel,
        ?Element $anchor = null,
        ?float $atX = null,
        ?float $atY = null,
    ): void {
        $this->overlay->showMenu($panel, $anchor, $atX, $atY);
    }

    public function closeMenu(): void
    {
        $this->overlay->closeMenu();
    }

    // ── 定时器 ──────────────────────────────────────────────────────────

    /** @param \Closure(): void $callback */
    public function delay(int $milliseconds, \Closure $callback): int
    {
        return $this->pumps->after($milliseconds, $callback);
    }

    public function cancelDelay(int $id): void
    {
        $this->pumps->cancelAfter($id);
    }

    // ── 平台端口 ────────────────────────────────────────────────────────

    public function openFile(string $title, array $filters = []): ?string
    {
        $dialog = $this->dialog();

        return $dialog?->openFile($title, $filters);
    }

    public function openFiles(string $title, array $filters = []): array
    {
        return $this->dialog()?->openFiles($title, $filters) ?? [];
    }

    public function saveFile(string $title, string $defaultName = '', array $filters = []): ?string
    {
        return $this->dialog()?->saveFile($title, $defaultName, $filters);
    }

    public function alert(string $title, string $body): void
    {
        $this->dialog()?->alert($title, $body);
    }

    public function confirm(string $title, string $body): bool
    {
        return $this->dialog()?->confirm($title, $body) ?? false;
    }

    public function clipboardText(): ?string
    {
        $clipboard = $this->clipboard();

        return $clipboard?->hasText() === true ? $clipboard->readText() : null;
    }

    public function setClipboardText(string $text): void
    {
        $this->clipboard()?->writeText($text);
    }

    public function hasClipboardText(): bool
    {
        return $this->clipboard()?->hasText() ?? false;
    }

    // ── 窗口 ────────────────────────────────────────────────────────────

    public function supportsWindowDrag(): bool
    {
        return $this->windowDrag !== null;
    }

    public function startWindowDrag(): void
    {
        if ($this->windowDrag !== null) {
            ($this->windowDrag)();
        }
    }

    // ── 内部 ────────────────────────────────────────────────────────────

    private function dialog(): ?\Drift\Platform\Dialog
    {
        $dialog = $this->scope->dialog();

        if ($dialog === null && !$this->dialogMissingReported) {
            $this->dialogMissingReported = true;
            Errors::report(
                '本平台没有对话框实现：Environment::openFile/openFiles/saveFile/alert/confirm 全部返回空值',
                Errors::DIAGNOSTIC,
            );
        }

        return $dialog;
    }

    private function clipboard(): ?\Drift\Platform\Clipboard
    {
        $clipboard = $this->scope->clipboard();

        if ($clipboard === null && !$this->clipboardMissingReported) {
            $this->clipboardMissingReported = true;
            Errors::report(
                '本平台没有剪贴板实现：Environment::clipboardText 返回 null',
                Errors::DIAGNOSTIC,
            );
        }

        return $clipboard;
    }
}
