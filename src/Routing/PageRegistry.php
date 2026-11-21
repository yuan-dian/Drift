<?php
declare(strict_types=1);

namespace Drift\Routing;

use Drift\Widget\Page;
use Drift\Widget\Widget;
use InvalidArgumentException;

/**
 * 多页面应用的公共注册表：一份导航项派生出导航列表与路由表。
 *
 * 应用只需声明页面类型；`PageRegistry` 负责按 id 建页、缓存路由、给默认页。
 *
 * 建页两条路：默认 `new $class()`（页面自己无参构造）；需要依赖的页面在
 * `$factories` 里按类名覆写。两条都是显式构造 —— 不做反射按类型装配，
 * 写错就在构造期 Fatal。
 *
 * @api
 */
final readonly class PageRegistry
{
    /** @var list<NavEntry> */
    private array $entries;

    private RouteTable $table;

    /**
     * @param list<NavEntry> $entriesIn
     * @param array<class-string, \Closure(): Widget|Page> $factories 按页面类覆写建页方式
     */
    public function __construct(array $entriesIn, array $factories = [])
    {
        if ($entriesIn === []) {
            throw new InvalidArgumentException('PageRegistry 至少需要一个页面');
        }

        $seen = [];
        $entries = [];
        $table = RouteTable::make();
        $used = [];
        foreach ($entriesIn as $entry) {
            if (isset($seen[$entry->id])) {
                throw new InvalidArgumentException("页面 id 重复：{$entry->id}");
            }
            $seen[$entry->id] = true;
            $entries[] = $entry;

            // 默认建页要求页面无参构造；需要依赖的页面用工厂覆写。
            // ★ 漏注册的后果是 arity 不匹配 ⇒ 构造期 Fatal，不是静默走默认。
            $factory = $factories[$entry->pageClass] ?? null;
            if ($factory !== null) {
                $used[$entry->pageClass] = true;
            }
            $build = $factory ?? fn(): Widget|Page => $entry->makePage();

            // ★ `host()` 只在这里做：单类页面（`Page` 本身是 State）要经 `PageShell` 才进树，
            //   已经是 `Widget` 的例外页（reducer 壳）原样返回。工厂表因此只需 `new`。
            $table->route($entry->id, static function () use ($build): Widget {
                $page = $build();

                return $page instanceof Page ? $page->host() : $page;
            });
        }

        // 拼错的类名会让工厂静默失效（页面退回默认构造）。只用了 env 的页面看不出差别，
        // 需要额外依赖的会 Fatal —— 但错误点跑到页面构造器，排查不到工厂表。
        $unused = \array_diff(\array_keys($factories), \array_keys($used));
        if ($unused !== []) {
            throw new InvalidArgumentException(
                '页面工厂表的键不是任何已注册页面的类名：' . \implode(', ', $unused)
            );
        }

        $this->entries = $entries;
        $this->table = $table;
    }

    /** @return list<NavEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function table(): RouteTable
    {
        return $this->table;
    }

    public function defaultId(): string
    {
        return $this->entries[0]->id;
    }
}
