<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use App\Showcase\Pages\ConcurrentFetchPage;
use Drift\App\Application;
use Drift\Async\Future;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：根就是示例台的 `ConcurrentFetchPage`（「并发取数」页）。
 *
 * ★ **它补的是 golden 的同一张漏网**（与 `LoadMoreApplication` 同因）：
 *   `tools/golden-raster.php` 只收「构造器无必填参数」的页，本页要注入取数闭包
 *   ⇒ 会被跳过 ⇒ `build()` 没有基线覆盖。而"读 null 属性 / 卡片放进 Row 里拿
 *   无界高"这类错**只在建树时**才暴露。
 *
 * ★ `$requested` 是观测面：本页的判据是「**三条任务在同一帧里都发出去了**」
 *   —— 那件事只能从提交记录上看见（树上没有"在途几条"这种东西）。
 *   记录顺序同时也是"入参顺序"的判据。
 */
final class ConcurrentFetchApplication extends Application
{
    /** @var list<int> 页面请求过的页码（按时间正序；"再发一次"会继续追加） */
    public array $requested = [];

    /**
     * @param \Closure(int): Future $fetchRows
     */
    public function __construct(private \Closure $fetchRows)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return new ConcurrentFetchPage(ShowcaseCaps::make(null, function (int $page): Future {
            $this->requested[] = $page;

            return ($this->fetchRows)($page);
        }))->host();
    }

    /** 根页面实例（测试要拿它的命令驱动"再发一次" / "重试"） */
    public function pageState(): ?ConcurrentFetchPage
    {
        $element = $this->root();

        return $element instanceof \Drift\Widget\StatefulElement
            && $element->state() instanceof ConcurrentFetchPage
            ? $element->state()
            : null;
    }

    public function title(): string
    {
        return '并发取数';
    }
}
