<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\Routing\NavStore;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Routing\NoPage;
use Drift\Routing\PageHost;
use Drift\Routing\RouteTable;
use Drift\Widget\OffstageElement;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * PageHost：换页 = 旧页 Offstage 化（§12.1 第 4/7 条）。
 *
 * ## 四条判据互相补位，缺一条就留一个静默失效点
 *
 * 1. **首帧就两页都挂上** —— 懒挂载会让"切过去是首帧"和"切过去是保留态"看起来一样。
 * 2. **旧页进 Offstage、新页可见** —— 换页的最小可见事实。
 * 3. **切回来是同一个实例** —— `State` 留住的充要条件；工厂被调第二次就说明缓存没生效
 *    （判据同时读 `cachedCount()`，从两头发力）。
 * 4. **未命中的页名 ⇒ 末槽是可见的 NoPage** —— 开放域不许崩、也不许静默什么都不显示。
 *
 * ## 门控那两条必须成对
 *
 * "同值 notify 被挡住" 与 "通知仍到达订阅者" 只验一条都读不出结论：
 * 前者单独成立可能是"订阅根本没联上"（症状完全一样）。
 */
final class PageHostTest extends SmokeTestCase
{
    public function testPageSwitchLifecycle(): void
    {
        $context = smokeContext();

        $nav = new NavStore();
        $navTable = RouteTable::make()
            ->route('users', static fn (): Widget => Text::make('用户列表'))
            ->route('signup', static fn (): Widget => Text::make('注册'));
        $host = PageHost::make()->source($nav)->select(static fn (NavStore $s): string => $s->page())->table($navTable);
        $hostElement = $host->createElement();
        $hostElement->mount(null, $context);

        /** @var \Drift\Routing\PageHostState $hostState */
        $hostState = $hostElement->state();
        check('PageHost · 首帧选出当前页', $hostState !== null && $hostState->page() === 'users');
        check('PageHost · 初始就两页都挂上（各一个 Offstage）', $hostElement->childCount() === 1);
        $usersOffstage = $hostElement->childAt(0)?->childAt(0);
        $signupOffstage = $hostElement->childAt(0)?->childAt(1);
        check(
            'PageHost · 当前页可见、另一页隐藏',
            $usersOffstage instanceof OffstageElement && $usersOffstage->childCount() === 1
                && $signupOffstage instanceof OffstageElement && $signupOffstage->childCount() === 0
        );

        // 换页：旧页隐藏但**不卸载**（State 留着），新页可见
        $nav->go('signup');
        check('PageHost · notify 后选中新页', $hostState !== null && $hostState->page() === 'signup');
        $hostElement->settle();
        check(
            'PageHost · 换页 ⇒ 旧页进 Offstage、新页可见',
            $hostElement->childAt(0)?->childAt(0)?->childCount() === 0
                && $hostElement->childAt(0)?->childAt(1)?->childCount() === 1
        );

        // ★ 每页只造一次：切回来是同一个 Widget 实例（否则"保留状态"是空话）
        $firstUsers = $hostElement->childAt(0)?->childAt(0);
        $nav->go('users');
        $hostElement->settle();
        $backUsers = $hostElement->childAt(0)?->childAt(0);
        check(
            'PageHost · 切回来 ⇒ 页面是同一个实例（工厂没有二次调用）',
            $firstUsers !== null && $firstUsers === $backUsers && $hostState !== null && $hostState->cachedCount() === 2,
            '缓存 ' . (string) ($hostState?->cachedCount() ?? -1) . ' 页'
        );

        // 同值 notify 被门控挡住（§11.3.3）：别的字段变了，页名没变 ⇒ 不换页
        $notifiesBefore = $nav->notifyCount();
        $nav->touchOtherField();
        check(
            'PageHost · 同值 notify 被门控挡住（两次 select 输出相等）',
            $hostState !== null && !$hostState->needsBuild()
        );
        // 反证：通知真的到了（否则"门控挡下"与"订阅没联上"长得一样）
        check(
            'PageHost · 被门控挡的通知仍到达订阅者（排除"订阅没联上"）',
            $nav->notifyCount() === $notifiesBefore + 1
        );

        // 未命中：开放域 ⇒ 什么都不显示，不崩（§12.1 第 2 条）
        $nav->go('ghost');
        $hostElement->settle();
        $ghostSlot = $hostElement->childAt(0)?->childAt(2);
        check(
            'PageHost · 未命中的页名 ⇒ 末槽是可见的 NoPage（不崩、不静默错误）',
            $ghostSlot instanceof OffstageElement
                && $ghostSlot->childCount() === 1
                && $ghostSlot->childAt(0)?->widget() instanceof NoPage
        );

        $this->flushChecks();
    }
}
