<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use App\Showcase\Pages\AsyncTablePage;
use Drift\App\Application;
use Drift\Async\Future;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：根就是示例台的 `AsyncTablePage`（异步表格页）。
 *
 * ★ **它补的是 golden 的一张漏网**：`tools/golden-raster.php` 只收
 *   「构造器无必填参数」的页（`apps/showcase/src/Pages/*.php` 自动发现），
 *   而 `AsyncTablePage` 需要注入取数闭包 ⇒ 会被 `$skippedPages` 跳过。
 *   于是这一页的「建树」从未被任何基线覆盖 —— 恰恰是最容易在 AOT 下落空的那一步
 *   （`build()` 里读 null 属性 ⇒ 进程直接消失，连 `Errors.log` 都留不下）。
 *
 * ★ **`$requested` 是观测面**：`fetchRows` 每次被调用都记一笔页码。
 *   「进页面就请求第一页」只能从提交次数上看见 —— 从树上找 Text 会受布局/裁剪影响。
 */
final class AsyncTableApplication extends Application
{
    /** @var list<int> 页面请求过的页码（按时间正序） */
    public array $requested = [];

    public function __construct(private \Closure $fetchRows)
    {
        // ★ 必须调父构造器：基类在构造器里建七个拆分片（闸门
        //   `AppSubclassCtorParent` 守着；漏了 AOT 真机 exit=255）。
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return new AsyncTablePage(ShowcaseCaps::make(null, function (int $page): Future {
            $this->requested[] = $page;

            return ($this->fetchRows)($page);
        }))->host();
    }

    public function title(): string
    {
        return '异步表格';
    }
}
