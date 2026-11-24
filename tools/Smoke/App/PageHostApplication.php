<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use App\Showcase\Routes\ShowcaseRoutes;
use Drift\App\Application;
use Drift\Routing\PageRegistry;
use Drift\Widget\Widget;

/**
 * @internal 冒烟 / 调试夹具：把**示例台的任意一页**当整棵树挂起来。
 *
 * ## 为什么需要它
 *
 * `drift debug` 原先只能跑「构造器无参的 `Drift\Dev\Smoke\App\*` 夹具」。而真实
 * 场景恰恰是最需要归因的那几页：`async-table` / `load-more` / `concurrent-fetch`
 * 都要注入取数闭包，`table` 要注入环境门面 —— 一个都 `new` 不出来。
 * 于是"表格/异步页跑一轮"这件事，在工具层面根本到不了。
 *
 * ## 为什么走路由表而不是自己扫目录
 *
 * `PageHostApplication` 不猜"这页怎么造"：`PageRegistry::discover()` 已经把
 * 「默认无参构造 + 工厂表覆写依赖页」这条规则实现了一次（组合根 `ShowcaseRoot`
 * 走的就是它）。这里再抄一份，就等于给同一件事留两个真相 —— 而且抄的那份会
 * 悄悄落后（新加一个需要依赖的页，工厂表补了、这里没补，症状是构造期 Fatal）。
 *
 * 顺带拿到的两个好处：
 * - 用**路由 id**（`table` / `async-table`）而不是类名寻址，与开发者脑子里的
 *   "哪一页"一致，也不用记 `App\Showcase\Pages\` 前缀；
 * - `routes()` 直接就是可列举的能力面，敲错名字能立刻列出全部候选。
 *
 * ## 它**不**做什么
 *
 * 不装 Store / 不接真端口：页面拿到的是 `ShowcasePageWiring` 的替身（取数永远
 * pending、通知只记账）。调试要看的是"这一页自己怎么重建"，替身比真数据源更
 * 可复现；要真数据源时该用 dev 壳，不是这里。
 */
final class PageHostApplication extends Application
{
    /**
     * @param Widget $root 已经过 `PageRegistry` 的 `host()`（单类页要经 `PageShell` 才进树）
     * @param string $route 路由 id，只用于标题与自证
     */
    private function __construct(private readonly Widget $root, private readonly string $route)
    {
        parent::__construct();
    }

    /**
     * 按路由 id 建一个单页应用。
     *
     * 未知 id **抛异常并列出全部候选**：这条命令是手工敲的，静默回落到默认页会
     * 让人对着一页陌生的树找半天问题。
     *
     * @param null|\Closure(int): mixed $fetchRows 取数替身；`null` = 永远 pending
     *        （首帧稳定画 loading）。要看"数据到达那一帧"就得给个会结算的。
     */
    public static function forRoute(string $route, ?\Closure $fetchRows = null): self
    {
        $table = self::registry($fetchRows)->table();
        if (!$table->has($route)) {
            throw new \InvalidArgumentException(
                "未知页面路由：{$route}（可用：" . \implode(' / ', $table->names()) . '）',
            );
        }

        $root = $table->build($route);
        if ($root === null) {
            // `has()` 已先判过 ⇒ 走到这里说明路由表自己漂了，不能当"空页"糊过去。
            throw new \LogicException("路由 {$route} 存在但建不出页面");
        }

        return new self($root, $route);
    }

    /** @return list<string> 示例台全部路由 id（`#[Route]` 注解是唯一来源） */
    public static function routes(): array
    {
        return self::registry()->table()->names();
    }

    public function buildRoot(): Widget
    {
        return $this->root;
    }

    public function title(): string
    {
        return 'page:' . $this->route;
    }

    /**
     * 注册表每次现建：`PageRegistry` 是 `readonly`，路由表本身也不持有页面实例
     * （工厂在 `build()` 时才跑）。缓存它只会让"同一进程里换了工厂表"变得难查。
     */
    private static function registry(?\Closure $fetchRows = null): PageRegistry
    {
        return PageRegistry::discover(ShowcaseRoutes::classes(), ShowcasePageWiring::factories($fetchRows));
    }
}
