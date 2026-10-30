<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Platform\Screen;
use Drift\Platform\WindowHost;
use Drift\Rendering\RenderSurface;

/**
 * 一个**已装配好的窗口**：句柄 + 应用 + 表面 + 状态存取（`WindowSet::open()` 的返回值）。
 *
 * ★ 为什么要有这个对象：装配完成之后，组合根还要对这个窗口做几件事
 *   （`setMinSize` / `setCloseGuard` / 语义树接线 / 拖动重排要用 surface）。
 *   没有它，那几件事就得各自拿 `$handle` 去问 `$platform->window()` ——
 *   句柄在组合根里四处流转，正是"传错一个不报错"的来源（多窗口下传错就是
 *   副窗的配置落到主窗上）。
 *
 * ★ **句柄只在构造时进来一次**，对外只读（`handle()`）。本类的每个方法都
 *   自带句柄调用平台端口 ⇒ 调用方不可能"忘了带窗口"。
 *
 * ★ `surface()` / `screen()` 在 `WindowSet::open()` 的 `$beforeRestore`
 *   阶段**还是 null**（表面要等窗口几何还原之后才定尺寸，见 `open()` 的次序）。
 *   正常时机是 `open()` 返回之后 —— 那时两者都已就位。
 *
 * @api 组合根用；应用代码看不到本类型（`Application` 不持有它）
 */
final class Window
{
    private ?RenderSurface $surface = null;

    private ?Screen $screen = null;

    private ?WindowStateStore $state = null;

    /** @internal 只能由 `WindowSet::open()` 造 —— 构造参数就是"装配到一半"的证据 */
    public function __construct(
        private readonly int $windowHandle,
        private readonly Application $application,
        private readonly WindowHost $host,
    ) {
    }

    /** 平台句柄（不透明整数；**每次运行都变**，不要拿它当持久化 key） */
    public function handle(): int
    {
        return $this->windowHandle;
    }

    public function app(): Application
    {
        return $this->application;
    }

    /** 平台窗口端口 —— 材质 / 无边框 / 拖动这些"开窗后配置"直接用 */
    public function host(): WindowHost
    {
        return $this->host;
    }

    public function surface(): ?RenderSurface
    {
        return $this->surface;
    }

    public function screen(): ?Screen
    {
        return $this->screen;
    }

    /** 窗口状态存取（`persist()` 由 `WindowSet::run()` 统一调，组合根不用管） */
    public function state(): ?WindowStateStore
    {
        return $this->state;
    }

    public function setMinSize(int $width, int $height): self
    {
        $this->host->setMinSize($this->windowHandle, $width, $height);

        return $this;
    }

    /** 关窗前先问本窗应用（`Application::onCloseRequested()`）—— 默认不声明 */
    public function setCloseGuard(bool $enabled = true): self
    {
        $this->host->setCloseGuard($this->windowHandle, $enabled);

        return $this;
    }

    /**
     * 把 UIA 语义树的投递接到**本窗**。
     *
     * ★ 不接 ⇒ 框架不建语义树、零开销；接了就必须指定窗口 —— 语义树是每窗一棵
     *   （`pushSemantics($handle, …)`），不指定会推到"最后建的窗"的 provider 上。
     */
    public function onSemantics(): self
    {
        $host = $this->host;
        $handle = $this->windowHandle;

        $this->application->setSemanticsSink(static function (array $nodes) use ($host, $handle): void {
            $host->pushSemantics($handle, $nodes);
        });

        return $this;
    }

    /** @internal `WindowSet::open()` 分两段填：还原几何之后 / 表面就绪之后 */
    public function mount(?RenderSurface $surface, ?Screen $screen, ?WindowStateStore $state): void
    {
        $this->surface = $surface;
        $this->screen = $screen;
        $this->state = $state;
    }
}
