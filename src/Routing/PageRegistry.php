<?php
declare(strict_types=1);

namespace Drift\Routing;

use Drift\Route\RouteCatalog;
use Drift\Route\RouteDescriptor;
use Drift\Widget\Page;
use Drift\Widget\Widget;
use InvalidArgumentException;

/**
 * 多页面应用的公共注册表：一份路由描述符派生出路由表与默认页。
 *
 * 应用只需给出 `RouteCatalog::discover()` 产出的 descriptors；`PageRegistry`
 * 负责按 id 建页、缓存路由、给默认页。描述符是导航身份的唯一来源 ——
 * 不再投影成第二份 `NavEntry`（A28）。
 *
 * 建页两条路：默认 `new $class()`（页面自己无参构造）；需要依赖的页面在
 * `$factories` 里按类名覆写。两条都是显式构造 —— 不做反射按类型装配，
 * 写错就在构造期 Fatal。
 *
 * @api
 */
final readonly class PageRegistry
{
    /** @var list<RouteDescriptor> */
    private array $descriptors;

    private RouteTable $table;

    /**
     * 一步装配：从页面类清单反射描述符并建注册表。
     *
     * @param list<class-string> $classes 带 `#[Route]` 的页面类
     * @param array<class-string, \Closure(): Widget|Page> $factories 按页面类覆写建页方式
     *
     * @api
     */
    public static function discover(array $classes, array $factories = []): self
    {
        return new self(RouteCatalog::discover($classes)->all(), $factories);
    }

    /**
     * @param list<RouteDescriptor> $descriptorsIn
     * @param array<class-string, \Closure(): Widget|Page> $factories 按页面类覆写建页方式
     */
    public function __construct(array $descriptorsIn, array $factories = [])
    {
        if ($descriptorsIn === []) {
            throw new InvalidArgumentException('PageRegistry 至少需要一个页面');
        }

        $seen = [];
        $descriptors = [];
        $table = RouteTable::make();
        $used = [];
        foreach ($descriptorsIn as $descriptor) {
            if (isset($seen[$descriptor->id])) {
                throw new InvalidArgumentException("页面 id 重复：{$descriptor->id}");
            }
            $seen[$descriptor->id] = true;
            $descriptors[] = $descriptor;

            // 默认建页要求页面无参构造；需要依赖的页面用工厂覆写。
            // ★ 漏注册的后果是 arity 不匹配 ⇒ 构造期 Fatal，不是静默走默认。
            $class = $descriptor->pageClass;
            $factory = $factories[$class] ?? null;
            if ($factory !== null) {
                $used[$class] = true;
            }
            $build = $factory ?? static fn(): Widget|Page => new $class();

            // ★ `host()` 只在这里做：单类页面（`Page` 本身是 State）要经 `PageShell` 才进树，
            //   已经是 `Widget` 的例外页（reducer 壳）原样返回。工厂表因此只需 `new`。
            $table->route($descriptor->id, static function () use ($build): Widget {
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

        $this->descriptors = $descriptors;
        $this->table = $table;
    }

    /** @return list<RouteDescriptor> */
    public function descriptors(): array
    {
        return $this->descriptors;
    }

    public function table(): RouteTable
    {
        return $this->table;
    }

    public function defaultId(): string
    {
        return $this->descriptors[0]->id;
    }
}
