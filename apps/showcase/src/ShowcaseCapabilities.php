<?php
declare(strict_types=1);

namespace App\Showcase;

use App\Showcase\Jobs\FetchRowsJob;
use App\Showcase\Stores\ButtonsStore;
use Drift\Async\Future;
use Drift\Platform\Dialog;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;

/**
 * 页面够不到、只能由组合根递进来的一束能力与共享状态。
 *
 * 闭包收成私有字段，外部只见具名方法：签名在方法上，IDE 看得见，AOT 按固定 arity 调。
 * `dialog` 保持端口接口，它有多种返回语义，不包成动作闭包。
 *
 * @api
 */
final readonly class ShowcaseCapabilities
{
    private readonly ButtonsStore $buttons;

    /**
     * @param \Closure(Snackbar): void $notify
     * @param \Closure(int, \Closure): void $delay
     * @param \Closure(Modal): void $showModal
     * @param \Closure(bool): void $setCloseConfirm
     * @param \Closure(int): Future $fetchRows
     * @param ButtonsStore|null $buttons 共享状态；省略则新建（组合根只建一次 caps）
     */
    public function __construct(
        private \Closure $notify,
        private \Closure $delay,
        private \Closure $showModal,
        private Dialog $dialog,
        private \Closure $setCloseConfirm,
        private \Closure $fetchRows,
        ?ButtonsStore $buttons = null,
    ) {
        $this->buttons = $buttons ?? new ButtonsStore();
    }

    /** 组合根默认装配；页面构造点不必再重复六个闭包。 */
    public static function fromApplication(ShowcaseApplication $app): self
    {
        return new self(
            static function (Snackbar $snackbar) use ($app): void {
                $app->showSnackbar($snackbar);
            },
            static function (int $milliseconds, \Closure $callback) use ($app): void {
                $app->after($milliseconds, $callback);
            },
            static function (Modal $modal) use ($app): void {
                $app->showModal($modal);
            },
            $app->dialog(),
            static function (bool $enabled) use ($app): void {
                $app->setConfirmClose($enabled);
            },
            static fn(int $page): Future => $app->submit(
                new FetchRowsJob($page, 20, $app->rowsMode())
            ),
        );
    }

    public function notify(Snackbar $snackbar): void
    {
        ($this->notify)($snackbar);
    }

    /** @param \Closure(): void $callback */
    public function delay(int $milliseconds, \Closure $callback): void
    {
        ($this->delay)($milliseconds, $callback);
    }

    public function showModal(Modal $modal): void
    {
        ($this->showModal)($modal);
    }

    public function dialog(): Dialog
    {
        return $this->dialog;
    }

    public function setCloseConfirm(bool $enabled): void
    {
        ($this->setCloseConfirm)($enabled);
    }

    public function fetchRows(int $page): Future
    {
        return ($this->fetchRows)($page);
    }

    /** 应用级共享状态：所有页面拿到同一个实例，跨页切换不丢。 */
    public function buttons(): ButtonsStore
    {
        return $this->buttons;
    }
}
