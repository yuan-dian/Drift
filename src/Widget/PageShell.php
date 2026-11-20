<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * `Page` 的宿主：把不在 Widget 树里的 `State` 包成可挂载节点。
 *
 * 唯一职责是 `createState()` 交出构造时注入的 Page 实例，不含任何逻辑。
 *
 * ★ 不要直接 new 它 —— 用 {@see Page::host()}。装配路径只留一条，否则
 *   "哪里会包壳"又散回调用点。
 * ★ 与 `Drift\Routing\PageHost` 无关：后者管多页 Offstage 保活。
 *
 * @internal 由 `Page::host()` 使用
 */
final class PageShell extends StatefulWidget
{
    public function __construct(private readonly Page $page)
    {
        parent::__construct();
    }

    public function createState(): State
    {
        return $this->page;
    }
}
