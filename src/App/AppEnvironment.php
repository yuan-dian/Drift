<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Widget\Modal;
use Drift\Widget\Snackbar;

/**
 * 窗口级环境能力门面：浮层通知、模态、帧泵定时器。
 *
 * ★ 只收这三样，因为它们的后台资源是**窗口私有**的：notify / showModal 走本窗浮层栈，
 *   delay 走本窗帧泵。跨窗共享会把一个窗口的通知弹到另一个窗口。dialog / clipboard
 *   是组合根注入的**平台端口**（每应用一个、不随窗），不属于本门面，仍由组合根直传。
 *
 * ★ 不持有 {@see Application}：门面只暴露这三个能力，Store 拿到它摸不到 frame / attach /
 *   input 这些帧内方法。宿主片段构造期注入，不反向查找。
 *
 * @api
 */
final readonly class AppEnvironment
{
    public function __construct(
        private OverlayManager $overlay,
        private PumpHost $pumps,
    ) {
    }

    /** 弹一条底部通知（第一条立即显示，后面 FIFO 排队）。 */
    public function notify(Snackbar $snackbar): void
    {
        $this->overlay->showSnackbar($snackbar);
    }

    /** 收掉当前通知，有排队则立刻上队首。幂等。 */
    public function dismissSnackbar(): void
    {
        $this->overlay->dismissSnackbar();
    }

    public function hasSnackbar(): bool
    {
        return $this->overlay->hasSnackbar();
    }

    public function snackbarQueueLength(): int
    {
        return $this->overlay->snackbarQueueLength();
    }

    /** @param \Closure(): void $callback */
    public function delay(int $milliseconds, \Closure $callback): int
    {
        return $this->pumps->after($milliseconds, $callback);
    }

    public function cancelDelay(int $id): void
    {
        $this->pumps->cancelAfter($id);
    }

    /** 打开模态：与菜单共用同一个浮层槽位。 */
    public function showModal(Modal $modal): void
    {
        $this->overlay->showModal($modal);
    }

    public function closeModal(): void
    {
        $this->overlay->closeModal();
    }
}
