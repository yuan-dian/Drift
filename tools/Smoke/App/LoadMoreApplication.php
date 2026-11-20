<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use App\Showcase\Pages\LoadMorePage;
use App\Showcase\Pages\States\LoadMorePageState;
use Drift\App\Application;
use Drift\Async\Future;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：根就是示例台的 `LoadMorePage`（「加载更多」页）。
 *
 * ★ **它补的是 golden 的一张漏网**：`tools/golden-raster.php` 只收「构造器无必填
 *   参数」的页，而 `LoadMorePage` 要注入取数闭包 ⇒ 会被 `$skippedPages` 跳过。
 *   于是这一页的 `build()`（`fold()` 四支 + `LazyColumn` + 页脚）没有任何基线 ——
 *   而"读 null 属性 / 无界高度"这类错**只在建树时**才暴露。
 *
 * ★ `$requested` 是观测面：`fetchRows` 每次被调用都记一笔页码。
 *   "追加请求的是第 2 页"只能从提交次数上看见 —— 从树上找 Text 会受裁剪影响。
 */
final class LoadMoreApplication extends Application
{
    /** @var list<int> 页面请求过的页码（按时间正序） */
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
        return LoadMorePage::make(ShowcaseCaps::make(null, function (int $page): Future {
            $this->requested[] = $page;

            return ($this->fetchRows)($page);
        }));
    }

    /** 根页面的 State（测试要拿它的命令驱动 `append()`） */
    public function pageState(): ?LoadMorePageState
    {
        $element = $this->root();

        return $element instanceof \Drift\Widget\StatefulElement
            && $element->state() instanceof LoadMorePageState
            ? $element->state()
            : null;
    }

    public function title(): string
    {
        return '加载更多';
    }
}
