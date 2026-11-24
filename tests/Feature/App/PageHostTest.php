<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\Routing\ActivationState;
use Drift\Dev\Smoke\Routing\ActivationWidget;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Routing\NoPage;
use Drift\Routing\PageHost;
use Drift\Routing\RouteTable;
use Drift\State\Cell;
use Drift\Widget\Expanded;
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
 * "同值写入被挡住" 与 "换值立刻标脏" 只验一条都读不出结论：前者单独成立
 * 可能是"订阅根本没联上"（症状完全一样）。旧版是 `Subject` + 手写同值门控，
 * 2026-11-22 起页名是一个 `Cell`，两条判据原样保留 —— 换成 `from(Readable)`
 * 之后，这两条同时也在守"State 的依赖采集真的接上了"。
 */
final class PageHostTest extends SmokeTestCase
{
    public function testPageSwitchLifecycle(): void
    {
        $context = smokeContext();

        $page = Cell::of('users')->named('test.page');
        $navTable = RouteTable::make()
            ->route('users', static fn (): Widget => Text::make('用户列表'))
            ->route('signup', static fn (): Widget => Text::make('注册'));
        $host = PageHost::make()->from($page)->table($navTable);
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
        $page->set('signup');
        $hostElement->settle();
        check('PageHost · 源换值 ⇒ 选中新页', $hostState !== null && $hostState->page() === 'signup');
        check(
            'PageHost · 换页 ⇒ 旧页进 Offstage、新页可见',
            $hostElement->childAt(0)?->childAt(0)?->childCount() === 0
                && $hostElement->childAt(0)?->childAt(1)?->childCount() === 1
        );

        // ★ 每页只造一次：切回来是同一个 Widget 实例（否则"保留状态"是空话）
        $firstUsers = $hostElement->childAt(0)?->childAt(0);
        $page->set('users');
        $hostElement->settle();
        $backUsers = $hostElement->childAt(0)?->childAt(0);
        check(
            'PageHost · 切回来 ⇒ 页面是同一个实例（工厂没有二次调用）',
            $firstUsers !== null && $firstUsers === $backUsers && $hostState !== null && $hostState->cachedCount() === 2,
            '缓存 ' . (string) ($hostState?->cachedCount() ?? -1) . ' 页'
        );

        // 同值写入被格子的相等门控挡住（§11.3.3）⇒ 不重建
        $page->set('users');
        check('PageHost · 同值 set 不惊动容器', $hostState !== null && !$hostState->needsBuild());
        // 反证：不同值一定标脏 —— 否则"门控挡住"与"订阅根本没联上"长得一样
        $page->set('signup');
        check('PageHost · 换值 ⇒ 立刻标脏（订阅真的联上了）', $hostState !== null && $hostState->needsBuild());
        $hostElement->settle();

        // 未命中：开放域 ⇒ 什么都不显示，不崩（§12.1 第 2 条）
        $page->set('ghost');
        $hostElement->settle();
        $ghostSlot = $hostElement->childAt(0)?->childAt(2);
        check(
            'PageHost · 未命中的页名 ⇒ 末槽是可见的 NoPage（不崩、不静默错误）',
            $ghostSlot instanceof OffstageElement
                && $ghostSlot->childCount() === 1
                // 未命中页走 NoPage 直挂，不过 pageFor() 的默认边界
                && $ghostSlot->childAt(0)?->widget() instanceof NoPage
        );

        $this->flushChecks();
    }

    public function testDirectCurrentModeKeepsPagesAlive(): void
    {
        $context = smokeContext();
        $table = RouteTable::make()
            ->route('a', static fn (): Widget => Text::make('A'))
            ->route('b', static fn (): Widget => Text::make('B'));

        $host = PageHost::make()->current('a')->table($table);
        $element = $host->createElement();
        $element->mount(null, $context);
        $first = $element->childAt(0)?->childAt(0);

        check('PageHost · current() 无需 Subject 也能选出当前页', $element->childAt(0)?->childCount() === 2);
        check('PageHost · 每个 Offstage 带稳定 Key', $first?->widget()->identity()?->value === 'page:a');

        $element->update(PageHost::make()->current('b')->table($table));
        $element->settle();
        $b = $element->childAt(0)?->childAt(1);
        check(
            'PageHost · 直接模式换页 ⇒ 旧页隐藏、新页可见',
            $element->childAt(0)?->childAt(0)?->childCount() === 0 && $b?->childCount() === 1
        );

        $element->update(PageHost::make()->current('a')->table($table));
        $element->settle();
        check('PageHost · 直接模式切回 ⇒ 同一 Element（State 没丢）', $element->childAt(0)?->childAt(0) === $first);

        $this->flushChecks();
    }

    public function testActivationFiresOnceOnFirstVisible(): void
    {
        $context = smokeContext();
        $table = RouteTable::make()
            ->route('a', static fn (): Widget => new ActivationWidget())
            ->route('b', static fn (): Widget => new ActivationWidget());

        $element = PageHost::make()->current('a')->table($table)->createElement();
        $element->mount(null, $context);
        // 默认错误边界包在页槽内，activation 控件在边界的下一层
        $a = $element->childAt(0)?->childAt(0)?->childAt(0)?->childAt(0)?->state();
        check('PageHost · 首帧可见页激活一次', $a instanceof ActivationState && $a->activations === 1);
        check('PageHost · 首帧隐藏页不在树里（ parked 不参与可见遍历）', $element->childAt(0)?->childAt(1)?->childCount() === 0);

        $element->update(PageHost::make()->current('b')->table($table));
        $element->settle();
        $b = $element->childAt(0)?->childAt(1)?->childAt(0)?->childAt(0)?->state();
        check('PageHost · 首次切到隐藏页 ⇒ 激活一次', $b instanceof ActivationState && $b->activations === 1);
        check('PageHost · 切走的页不重复激活', $a instanceof ActivationState && $a->activations === 1);

        $element->update(PageHost::make()->current('a')->table($table));
        $element->settle();
        check('PageHost · 切回 ⇒ 激活钩子幂等', $a instanceof ActivationState && $a->activations === 1 && $b->activations === 1);

        $this->flushChecks();
    }

    public function testExpandedModeCachesWrapperToo(): void
    {
        $context = smokeContext();
        $table = RouteTable::make()->route('a', static fn (): Widget => Text::make('A'));
        $element = PageHost::make()->current('a')->table($table)->expanded()->createElement();
        $element->mount(null, $context);
        $wrapper = $element->childAt(0)?->childAt(0)?->childAt(0)?->widget();
        check('PageHost · expanded() 把页包进 Expanded', $wrapper instanceof Expanded);

        $element->update(PageHost::make()->current('a')->table($table)->expanded());
        $element->settle();
        check(
            'PageHost · 包装实例也缓存（隐藏页不白跑 rebuild）',
            $element->childAt(0)?->childAt(0)?->childAt(0)?->widget() === $wrapper
        );

        $this->flushChecks();
    }
}
