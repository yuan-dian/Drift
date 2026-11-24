<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\ErrorBoundary\ConstructBoom;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Routing\PageHost;
use Drift\Routing\RouteTable;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\ErrorBoundary;
use Drift\Widget\ErrorBoundaryElement;
use Drift\Widget\ErrorPlaceholder;
use Drift\Widget\Expanded;
use Drift\Widget\Page;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * A45 判据：错误边界默认接在路由容器，页面级隔离而不是整窗红屏。
 *
 * 四条判据互相补位：
 * ① 默认开：坏页 build 抛 ⇒ 就地换占位，兄弟页照常；
 * ② 切到构造期抛的坏页也不能带走窗口；
 * ③ 边界必须在 Expanded 内层，不能切断 Flex 约束链；
 * ④ `guard(false)` 恢复严格模式：同样的异常必须照旧冒出 —— 否则默认开只是
 *    "把异常吞了"，而不是"边界接住了"。
 */
final class PageErrorBoundaryTest extends SmokeTestCase
{
    public function testDefaultGuardReplacesBadPageAndKeepsSiblingAlive(): void
    {
        $bad = new GuardBoomPage();
        $ok = new GuardOkPage();
        $table = RouteTable::make()
            ->route('bad', static fn (): Widget => $bad->host())
            ->route('ok', static fn (): Widget => $ok->host());

        // 挂载不能抛：坏页的 build 异常在边界内就被换成了占位。
        $host = PageHost::make()->current('bad')->table($table)->createElement();
        $host->mount(null, \smokeContext());

        $caught = $host->findElement(static fn (Element $e): bool =>
            $e instanceof ErrorBoundaryElement && $e->hasCaught());
        check('页面边界 · 默认开，坏页 build 异常被本页边界捕获', $caught instanceof ErrorBoundaryElement, \get_debug_type($caught));
        check(
            '页面边界 · 捕获文案保留原文（不吞不改写）',
            \str_contains((string) $caught?->caughtMessage(), 'page boom'),
            (string) $caught?->caughtMessage(),
        );
        check('页面边界 · 坏页子树换成占位', $caught?->childAt(0)?->widget() instanceof ErrorPlaceholder);
        check('页面边界 · 兄弟页照常构建（不是整窗占位）', $ok->builds === 1, 'builds=' . $ok->builds);

        // 切到兄弟页：窗口还活着，且兄弟页的 State 没被坏页牵连重建。
        $host->update(PageHost::make()->current('ok')->table($table));
        $host->settle();
        check('页面边界 · 切到兄弟页仍可用', $ok->builds === 1, 'builds=' . $ok->builds);
        $this->flushChecks();
    }

    public function testSwitchingToBadPageDoesNotTakeDownWindow(): void
    {
        $ok = new GuardOkPage();
        $table = RouteTable::make()
            ->route('ok', static fn (): Widget => $ok->host())
            ->route('bad', static fn (): Widget => ConstructBoom::make());

        $host = PageHost::make()->current('ok')->table($table)->createElement();
        $host->mount(null, \smokeContext());
        check('页面边界 · 前提：隐藏页不参与首帧可见遍历', $ok->builds === 1, 'builds=' . $ok->builds);

        // 隐藏页在切过去时才挂载；其构造期异常仍归该页的边界，不冒到窗口。
        $host->update(PageHost::make()->current('bad')->table($table));
        $host->settle();

        // 切走后旧页进 Offstage 不再参与可见遍历，所以这里只看当前页的边界。
        $caught = $host->findElement(static fn (Element $e): bool =>
            $e instanceof ErrorBoundaryElement && $e->hasCaught());
        check('页面边界 · 切到的坏页在构造期被本页边界捕获', $caught instanceof ErrorBoundaryElement, \get_debug_type($caught));
        check(
            '页面边界 · 捕获的是构造期原文',
            \str_contains((string) $caught?->caughtMessage(), 'construct boom'),
            (string) $caught?->caughtMessage(),
        );
        check('页面边界 · 兄弟页没有因此重建', $ok->builds === 1, 'builds=' . $ok->builds);
        $this->flushChecks();
    }

    public function testGuardInsideExpandedKeepsFlexConstraintChain(): void
    {
        $table = RouteTable::make()->route('a', static fn (): Widget => Text::make('A'));
        $host = PageHost::make()->current('a')->table($table)->expanded()->createElement();
        $host->mount(null, \smokeContext());

        $column = $host->childAt(0);
        $offstage = $column?->childAt(0);
        $expanded = $offstage?->childAt(0);
        check('页面边界 · 前提：expanded() 产生 Expanded 外壳', $expanded?->widget() instanceof Expanded);
        check(
            '页面边界 · 边界在 Expanded 内层，不插进 Flex 与 Expanded 之间',
            $expanded?->childAt(0)?->widget() instanceof ErrorBoundary,
            \get_debug_type($expanded?->childAt(0)?->widget()),
        );
        $this->flushChecks();
    }

    public function testGuardFalseRestoresStrictMode(): void
    {
        $table = RouteTable::make()->route('bad', static fn (): Widget => ConstructBoom::make());
        $host = PageHost::make()->guard(false)->current('bad')->table($table);

        $message = '';
        $threw = false;
        try {
            $host->createElement()->mount(null, \smokeContext());
        } catch (\Throwable $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        check('页面边界 · guard(false) 让异常照旧冒出（严格模式）', $threw, $message);
        check('页面边界 · 严格模式没把异常改写成占位', \str_contains($message, 'construct boom'), $message);
        $this->flushChecks();
    }
}

/** @internal 判据夹具：首帧 build 就抛 */
final class GuardBoomPage extends Page
{
    public function build(BuildContext $context): Widget
    {
        throw new \RuntimeException('page boom');
    }
}

/** @internal 判据夹具：健康兄弟页 */
final class GuardOkPage extends Page
{
    public int $builds = 0;

    public function build(BuildContext $context): Widget
    {
        ++$this->builds;

        return Text::make('ok');
    }
}
