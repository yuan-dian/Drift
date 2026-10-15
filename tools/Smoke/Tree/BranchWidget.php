<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Tree;

use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：**结构节点** —— 继承裸 `Widget`（§15.7），所以没有 `build()`。
 *
 * 这正是 `Column` / `Row` 落地时的形态；`Column` 会用同一个 `expand()` 扩展点，
 * 所以现在把它测掉。
 */
final class BranchWidget extends Widget
{
    /** @param list<Widget> $kidList */
    public function __construct(private readonly array $kidList)
    {
        parent::__construct();
    }

    /** @return list<Widget> */
    public function kidList(): array
    {
        return $this->kidList;
    }

    public function createElement(): Element
    {
        return new BranchElement($this);
    }
}
