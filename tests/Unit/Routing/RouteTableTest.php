<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Routing;

use Drift\Dev\Smoke\LeafProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Routing\RouteTable;
use Drift\Widget\Widget;

/**
 * RouteTable：路由表是**纯数据**（§12.1 第 4/7 条）。
 *
 * 判据只有值语义：链式登记、可枚举、工厂真被调、未命中返回 null（开放域 ⇒
 * 交给调用方的 `default()` 处理，路由表不替它决定）。
 *
 * ★ "同名路由重复注册 ⇒ Fatal"用子进程验 `exit 3` + Errors.log，留在 smoke。
 */
final class RouteTableTest extends SmokeTestCase
{
    public function testRouteTableIsData(): void
    {
        $table = RouteTable::make()
            ->route('users', static fn (): Widget => new LeafProbe(1.0))
            ->route('signup', static fn (): Widget => new LeafProbe(2.0));
        check(
            'Routing · route() 链式 + names() 可枚举（devtools 列举的输入）',
            $table->names() === ['users', 'signup']
        );
        $built = $table->build('users');
        check('Routing · build() 调工厂造页', $built instanceof LeafProbe && $built->width === 1.0);
        check('Routing · 未命中 ⇒ null（开放域 ⇒ 由调用方 default 处理）', $table->build('ghost') === null);
        check('Routing · has() 与 names() 一致', $table->has('users') && !$table->has('ghost'));

        $this->flushChecks();
    }
}
