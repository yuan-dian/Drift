<?php
declare(strict_types=1);

namespace Drift\Routing;

use Drift\Widget\Widget;
use InvalidArgumentException;

/**
 * 多页面应用的公共注册表：一份导航项派生出导航列表与路由表。
 *
 * 应用只需声明页面类型；`PageRegistry` 负责按 id 建页、缓存路由、给默认页。
 * 页面构造器统一收一个上下文对象，框架不关心它具体是能力束还是别的组合根产物。
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
     */
    public function __construct(array $entriesIn, private object $pageContext)
    {
        if ($entriesIn === []) {
            throw new InvalidArgumentException('PageRegistry 至少需要一个页面');
        }

        $seen = [];
        $entries = [];
        $table = RouteTable::make();
        foreach ($entriesIn as $entry) {
            if (isset($seen[$entry->id])) {
                throw new InvalidArgumentException("页面 id 重复：{$entry->id}");
            }
            $seen[$entry->id] = true;
            $entries[] = $entry;
            $table->route(
                $entry->id,
                fn(): Widget => $entry->makePage($this->pageContext)
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
