<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Column;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 冒烟夹具：**会回答"要不要关"**的应用（2026-10-30，可拦截关闭）。
 *
 * ★ 它存在的理由是"拒绝"这一支：默认实现恒 `true`，如果夹具也恒 `true`，
 *   `EventLoop` 里那个 `if ($app->closeRequested())` 的**反例**就永远跑不到 ——
 *   把条件写成恒真、或者把 `destroy()` 提到判断外面，测试都会全绿。
 * ⇒ `$allow` 可写，且 `$asked` 记账：断言"问了几次"与"怎么答的"两件事。
 */
final class CloseGuardApplication extends Application
{
    /** 被问过几次（断言"关一次只问一次"） */
    public int $asked = 0;

    /**
     * @param bool $allow 下一次被问时回答什么。
     *   ★ 默认 **`false`**（拒绝）—— 与 `Application` 的默认实现相反，
     *     否则"拒绝"这一支永远跑不到，条件写错也全绿。
     */
    public function __construct(public bool $allow = false)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return Column::make()->children([Text::make('关闭守卫夹具')]);
    }

    public function title(): string
    {
        return '关闭守卫';
    }

    protected function onCloseRequested(): bool
    {
        ++$this->asked;

        return $this->allow;
    }
}
