<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Geometry\EdgeInsets;
use Drift\Input\FilesDroppedEvent;
use Drift\Widget\Column;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：拖放路由语义的宿主（A5-②，§27）。
 *
 * ★ 一棵可控的小树：根 `Column` + 三个 `Text` 叶子。根与首叶可各自声明/撤销
 *   `onFilesDropped`，用来证路由纪律（命中 / 冒泡 / 最近优先 / 无接收者 / 异常兜）。
 * ★ `calls` 与 `payloads` 是公开数组：用例派发后直接读，不需要反射 State。
 *   ★ 闭包捕获 `$this`（Application），跨重建仍指向同一实例 —— 与页面在
 *     `build()` 里捕获 State 是同一条路。
 */
final class FilesDropRoutingApplication extends Application
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $payloads = [];

    public bool $rootReceives = true;

    public bool $leafReceives = true;

    public bool $leafThrows = false;

    public function buildRoot(): Widget
    {
        $first = Text::make('ALPHA');
        if ($this->leafReceives) {
            $first = $first->onFilesDropped(function (FilesDroppedEvent $e): void {
                $this->calls[] = 'leaf';
                $this->payloads[] = (string) $e->pathsText;
                if ($this->leafThrows) {
                    throw new \RuntimeException('boom-from-leaf');
                }
            });
        }

        $root = Column::make()->spacing(20.0)->padding(EdgeInsets::all(20.0))->children([
            $first,
            Text::make('BETA'),
            Text::make('GAMMA'),
        ]);

        if ($this->rootReceives) {
            $root = $root->onFilesDropped(function (FilesDroppedEvent $e): void {
                $this->calls[] = 'root';
                $this->payloads[] = (string) $e->pathsText;
            });
        }

        return $root;
    }
}
