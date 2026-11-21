<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use App\Showcase\Pages\AsyncTablePage;
use App\Showcase\Pages\ButtonsPage;
use App\Showcase\Pages\ComboBoxPage;
use App\Showcase\Pages\ConcurrentFetchPage;
use App\Showcase\Pages\DialogPage;
use App\Showcase\Pages\FeedbackPage;
use App\Showcase\Pages\LoadMorePage;
use App\Showcase\Pages\SnackbarPage;
use App\Showcase\Stores\ButtonsStore;
use Drift\Async\Future;
use Drift\Widget\Modal;
use Drift\Widget\Page;
use Drift\Widget\Snackbar;
use Drift\Widget\Widget;

/**
 * 无头装配表：整份页面清单都能在**没有 Application** 的环境里建出来。
 *
 * 能力全给空实现；取数默认 `Future::pending()`（首帧稳定画 loading，不把转圈相位
 * 画进基线）。基准要确定性数据时传自己的 `$fetchRows`（见 `Trees::showcase()`）。
 *
 * ★ 与真组合根 `ShowcaseApplication::pageFactories()` **故意不共享**：那份要真
 *   submit / 真 dialog，这份要零依赖与确定性 —— 混用会把测试网络和真接线绑死。
 *
 * @api 测试 / 基准 / 整页快照共用
 */
final class ShowcasePageWiring
{
    /**
     * @param \Closure(int): Future|null $fetchRows 省略 = 永远 pending
     * @return array<class-string, \Closure(): Widget|Page>
     */
    public static function factories(?\Closure $fetchRows = null): array
    {
        $env = new FakeEnvironment();
        $setCloseConfirm = static function (bool $enabled): void {
        };
        $rows = $fetchRows ?? static fn(int $page): Future => Future::pending();

        return [
            AsyncTablePage::class => static fn(): Widget|Page => new AsyncTablePage($rows),
            ButtonsPage::class => static fn(): Widget|Page => new ButtonsPage(new ButtonsStore()),
            ComboBoxPage::class => static fn(): Widget|Page => new ComboBoxPage($env),
            ConcurrentFetchPage::class => static fn(): Widget|Page => new ConcurrentFetchPage($rows),
            DialogPage::class => static fn(): Widget|Page => new DialogPage($env, $setCloseConfirm),
            FeedbackPage::class => static fn(): Widget|Page => new FeedbackPage($env),
            LoadMorePage::class => static fn(): Widget|Page => new LoadMorePage($rows),
            SnackbarPage::class => static fn(): Widget|Page => new SnackbarPage($env),
        ];
    }
}
