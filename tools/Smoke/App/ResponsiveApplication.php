<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\WindowSize;

/**
 * 响应式夹具：根按窗口档位换结构，并记下每次 `build()` 看到的档位。
 *
 * ★ 存在理由：`windowSize()` 的价值全在「跨档才重建」这条链上 —— 只断言 ctx 里的值
 *   变了，等于没断言 `build()` 重跑过；而"档内不重建"又必须反过来钉住。
 *
 * @internal 测试夹具
 */
final class ResponsiveApplication extends Application
{
    /** `build()` 跑过几次 */
    public int $builds = 0;

    /** 最近一次 `build()` 看到的档位 */
    public ?WindowSize $lastSize = null;

    /** 最近一次 `build()` 看到的宽度（证明档内那次没重跑，值也就停在跨档时） */
    public float $lastWidth = 0.0;

    public function buildRoot(): Widget
    {
        return ResponsiveProbe::make($this);
    }
}

/** @internal 测试夹具 */
final class ResponsiveProbe extends StatelessWidget
{
    private function __construct(private readonly ResponsiveApplication $app)
    {
        parent::__construct();
    }

    public static function make(ResponsiveApplication $app): self
    {
        return new self($app);
    }

    public function build(BuildContext $ctx): Widget
    {
        $this->app->builds++;
        $this->app->lastSize = $ctx->windowSize();
        $this->app->lastWidth = $ctx->width;

        return $ctx->windowSize()->isCompact()
            ? Column::make()->children([Text::make('单栏')])
            : Row::make()->children([Text::make('左'), Text::make('右')]);
    }
}
