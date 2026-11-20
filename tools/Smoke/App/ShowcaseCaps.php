<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use App\Showcase\ShowcaseCapabilities;
use Drift\Async\Future;
use Drift\Platform\Dialog;

/** 无头夹具共用：默认能力都是空操作，只在用例关心的那一项上传入真实现。 */
final class ShowcaseCaps
{
    public static function make(
        ?Dialog $dialog = null,
        ?\Closure $fetchRows = null,
        ?\Closure $showModal = null,
        ?\Closure $setCloseConfirm = null,
    ): ShowcaseCapabilities {
        return new ShowcaseCapabilities(
            static function ($snackbar): void {
            },
            static function ($milliseconds, $callback): void {
            },
            $showModal ?? static function ($modal): void {
            },
            $dialog ?? new FakeDialog(),
            $setCloseConfirm ?? static function ($enabled): void {
            },
            $fetchRows ?? static fn(int $page): Future => Future::pending(),
        );
    }
}
