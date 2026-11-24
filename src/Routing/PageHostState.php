<?php
declare(strict_types=1);

namespace Drift\Routing;

use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\ErrorBoundary;
use Drift\Widget\Expanded;
use Drift\Widget\Offstage;
use Drift\Widget\State;
use Drift\Widget\Widget;

/**
 * `PageHost` 的 State：页名 + 页面实例缓存（§12.1 第 4/7 条）。
 *
 * 两件事必须由它（而不是控件）持有：
 * ① **页面 Widget 缓存** —— 控件每次重建都可能是新实例描述，缓存放控件上会在
 *    重建时被重新填；放 State 上才跨重建活着。
 * ② **路由表** —— 首次挂载时冻结。表是数据、一次给定；跟着重建换表会让已缓存的
 *    页面与表不同源（旧表造的页留在缓存里，新表却以为自己是权威）。
 *
 * ★ **页名不是字段状态**：`build()` 里读一次源（`Readable::get()`）就是订阅，
 *   源变 ⇒ 本 State 标脏 ⇒ 重建时重新读。以前这里是 `watch()` + 手工同值门控，
 *   与 `State` 的依赖采集是同一件事的两份实现（2026-11-22 删掉）。
 *
 * @internal
 */
final class PageHostState extends State
{
    private ?RouteTable $table = null;

    /** 最近一次 build 用的页名（`page()` 的读法，测试与 devtools 用） */
    private string $page = '';

    /** @var array<string, Widget> 页名 → 页面 Widget。**只造一次** */
    private array $pages = [];

    public function initState(): void
    {
        /** @var PageHost $widget */
        $widget = $this->widget();
        $this->table = $widget->routeTable();
    }

    public function page(): string
    {
        return $this->page;
    }

    /** @internal 冒烟夹具读它，证明缓存真的只造一次 */
    public function cachedCount(): int
    {
        return count($this->pages);
    }

    public function build(BuildContext $ctx): Widget
    {
        /** @var PageHost $widget */
        $widget = $this->widget();

        $current = $widget->currentPage();
        if ($current === null) {
            // ★ 读 = 订阅：源（格子 / 派生值）变化会让本 State 重建，重新读一次。
            $value = $widget->source()?->get();
            $current = \is_string($value) ? $value : '';
        }
        $this->page = $current;

        $table = $this->table;

        if ($table === null || $current === '') {
            return Offstage::make(new NoPage(), true);
        }

        $hosted = [];
        foreach ($table->names() as $name) {
            // ★ Key 是刚需：所有 Offstage 同类，无 Key 时 diff 退化为按位置配对，
            //   路由表顺序一变（插页/重排）State 就跟着错位到别的页。
            $hosted[] = Offstage::make($this->pageFor($name), $name === $current)->key('page:' . $name);
        }

        // 当前页不在表里 ⇒ 什么都不显示（开放域，§12.1 第 2 条）
        if (!in_array($current, $table->names(), true)) {
            $hosted[] = Offstage::make(new NoPage(), true);
        }

        return Column::make()->children($hosted);
    }

    private function pageFor(string $name): Widget
    {
        if (!isset($this->pages[$name])) {
            /** @var PageHost $widget */
            $widget = $this->widget();
            $page = $this->table?->build($name) ?? new NoPage();
            // 默认给每个路由页面套一层边界（A45）：这里而非 Page::host() 才是
            // 页面生命周期的唯一漏斗 —— 缓存与 Offstage 保活都在这里，包在
            // `Expanded` 内层也才不把 Flex 与 Expanded 之间的约束链切断。
            if ($widget->guardsPages()) {
                $page = ErrorBoundary::make($page);
            }
            // 包装后的实例一起缓存：否则每次切页都 new Expanded，隐藏页的
            // OffstageElement 会误判"换了新描述"而对隐藏页白跑 rebuild。
            $this->pages[$name] = $widget->isExpanded() ? Expanded::make($page) : $page;
        }

        return $this->pages[$name];
    }
}
