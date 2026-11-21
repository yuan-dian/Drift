<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\Widget\Element;
use Drift\Widget\Environment;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;
use Drift\Widget\Widget;

/**
 * `Environment` 的**记录型**替身：每一条能力都留下可断言的痕迹。
 *
 * ★ 端口方法转发给 {@see FakeDialog}，而不是另写一份返回值表 —— 「取消」与
 *   「选了文件」是两条分支，用例要先设 `nextPath` 再点按钮；分成两套夹具会让
 *   "我设的是哪个"变成问题。
 * ★ 定时器是**手动交付**（`run($i)`）：测试里不能有自己转起来的东西，否则
 *   轮询节奏的断言变成"恰好在这一帧跑到了"。
 * ★ 空实现不抛异常：抛了就没法测"平台缺端口时业务怎么退化"。
 *
 * @internal 测试 / 冒烟夹具
 */
final class FakeEnvironment implements Environment
{
    /** @var list<array{0: int, 1: \Closure}> 按排期顺序（`run()` 交付） */
    public array $ticks = [];

    /** @var list<Snackbar> */
    public array $notified = [];

    /** @var list<Modal> */
    public array $modals = [];

    /** @var list<array{0: Widget, 1: ?Element, 2: ?float, 3: ?float}> */
    public array $menus = [];

    /** @var list<string> 被撤掉的定时器 id */
    public array $cancelled = [];

    public string $clipboard = '';

    /** 无边框窗：`true` ⇒ 顶条画自绘按钮。默认 `false`（有原生标题栏） */
    public bool $windowDragSupported = false;

    public int $windowDragStarts = 0;

    public function __construct(public readonly FakeDialog $dialog = new FakeDialog())
    {
    }

    // ── 浮层 ────────────────────────────────────────────────────────────

    public function notify(Snackbar $snackbar): void
    {
        $this->notified[] = $snackbar;
    }

    public function dismissSnackbar(): void
    {
        $this->notified = [];
    }

    public function showModal(Modal $modal): void
    {
        $this->modals[] = $modal;
    }

    public function closeModal(): void
    {
        $this->modals = [];
    }

    public function showMenu(
        Widget $panel,
        ?Element $anchor = null,
        ?float $atX = null,
        ?float $atY = null,
    ): void {
        $this->menus[] = [$panel, $anchor, $atX, $atY];
    }

    public function closeMenu(): void
    {
        $this->menus = [];
    }

    // ── 定时器 ──────────────────────────────────────────────────────────

    public function delay(int $milliseconds, \Closure $callback): int
    {
        $this->ticks[] = [$milliseconds, $callback];

        return \count($this->ticks);
    }

    public function cancelDelay(int $id): void
    {
        $this->cancelled[] = (string) $id;
    }

    public function count(): int
    {
        return \count($this->ticks);
    }

    /** 交付第 `$index` 拍（真实世界由本窗帧泵在到点时交付） */
    public function run(int $index): void
    {
        if (!isset($this->ticks[$index])) {
            throw new \RuntimeException('没有第 ' . $index . ' 拍可跑 —— 排期本身就没发生');
        }

        ($this->ticks[$index][1])();
    }

    // ── 平台端口 ────────────────────────────────────────────────────────

    public function openFile(string $title, array $filters = []): ?string
    {
        return $this->dialog->openFile($title, $filters);
    }

    public function openFiles(string $title, array $filters = []): array
    {
        return $this->dialog->openFiles($title, $filters);
    }

    public function saveFile(string $title, string $defaultName = '', array $filters = []): ?string
    {
        return $this->dialog->saveFile($title, $defaultName, $filters);
    }

    public function alert(string $title, string $body): void
    {
        $this->dialog->alert($title, $body);
    }

    public function confirm(string $title, string $body): bool
    {
        return $this->dialog->confirm($title, $body);
    }

    public function clipboardText(): ?string
    {
        return $this->clipboard === '' ? null : $this->clipboard;
    }

    public function setClipboardText(string $text): void
    {
        $this->clipboard = $text;
    }

    public function hasClipboardText(): bool
    {
        return $this->clipboard !== '';
    }

    // ── 窗口 ────────────────────────────────────────────────────────────

    public function supportsWindowDrag(): bool
    {
        return $this->windowDragSupported;
    }

    public function startWindowDrag(): void
    {
        $this->windowDragStarts++;
    }
}
