<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\BuildContext;
use Drift\Widget\Page;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 单类页面宿主：Page 自己就是 State，宿主只负责把它挂进 Widget 树。
 *
 * ★ 判据从**对象身份与生命周期钩子**看，不从画面看：同样的像素也可能来自
 *   "每帧重建一个新 State"，那会让表单草稿与滚动位置在切页后静默丢失。
 */
final class PageShellTest extends SmokeTestCase
{
    public function testPageStateSurvivesUpdateAndCleansUpOnUnmount(): void
    {
        // 挂载：宿主 createState() 交出的就是注入的 Page 实例
        $page = new PageShellProbePage();
        $element = $page->host()->createElement();
        $element->mount(null, \smokeContext());

        check('PageShell · createState 交出注入的 Page', $element->state() === $page);
        check('PageShell · initState 只跑一次', $page->inits === 1, 'inits=' . $page->inits);
        check('PageShell · 首帧可见即激活一次', $page->activations === 1, 'act=' . $page->activations);
        $first = $element->childAt(0)?->widget();
        check('PageShell · Page::build 的结果进树', $first instanceof Text && $first->content() === 'probe-0');

        // setState 只标脏，帧末收敛成一帧；连点两次不重建两次
        $page->bump();
        $page->bump();
        $element->settle();
        $after = $element->childAt(0)?->widget();
        check('PageShell · 连点两次只重建一帧', $page->builds === 2, 'builds=' . $page->builds);
        check('PageShell · 新值进树', $after instanceof Text && $after->content() === 'probe-2');

        // 宿主换新描述：State 实例保留，initState 不重跑
        $same = $element->state();
        $element->update($page->host());
        $element->settle();
        check('PageShell · update 后 State 不换', $element->state() === $same && $element->state() === $page);
        check('PageShell · update 不重跑 initState', $page->inits === 1, 'inits=' . $page->inits);
        check('PageShell · update 不重置本地态', $page->count === 2, 'count=' . $page->count);

        // 卸载：页面钩子与框架侧清理袋都跑，且不依赖页面记得调 parent
        $element->unmount();
        check('PageShell · dispose 跑一次', $page->disposes === 1, 'disposes=' . $page->disposes);
        check('PageShell · onDispose 清理跑一次', $page->cleanups === 1, 'cleanups=' . $page->cleanups);

        // 重挂：新页面完全独立，不靠静态残留
        $page2 = new PageShellProbePage();
        $element2 = $page2->host()->createElement();
        $element2->mount(null, \smokeContext());
        check(
            'PageShell · 重挂是新实例、各自走完整生命周期',
            $page2->inits === 1 && $page2->activations === 1 && $page2->builds === 1,
        );
        $element2->unmount();

        $this->flushChecks();
    }

    /**
     * 同一 Page 被两个 Element 持有 ⇒ 当场 Fatal。
     *
     * ★ 这是 `Page::host()` 的**独有**风险：交出的是外部注入的实例，若被挂两处，
     *   后一次静默覆盖 element，先卸载的那处 `dropDisposers()` 会把后者的订阅和
     *   命令一并退掉 —— 表现为"切回这页请求再也不触发"，无异常、无日志。
     */
    public function testSamePageMountedTwiceIsFatal(): void
    {
        [$code, $out, $text] = $this->runFatal('state-double-mount');
        check(
            'PageShell · 同一 Page 挂两处 ⇒ 响亮失败',
            $code === 3 && \str_contains($text, '同一个 State 实例不能同时挂两处'),
            'code=' . $code . ' out=' . $out,
        );

        $this->flushChecks();
    }

    /**
     * 卸载后重挂同一个 Page ⇒ 也 Fatal，文案必须点到"重挂"。
     *
     * ★ 这一条不是同义反复：`State::initState()` 只跑一次（幂等），重挂不会重新
     *   订阅 ⇒ 静默得到一个不再收通知的页面。缓存 `host()` 在条件分支里来回切的
     *   写法正是这么坏的，报错文案得指到那条路上（只报"挂两处"会把人引偏）。
     */
    public function testRemountingUnmountedPageIsFatal(): void
    {
        [$code, $out, $text] = $this->runFatal('state-remount');
        check(
            'PageShell · 卸载后重挂同一实例 ⇒ 响亮失败且指出"重挂"',
            $code === 3 && \str_contains($text, '也不能卸载后重挂'),
            'code=' . $code . ' out=' . $out,
        );

        $this->flushChecks();
    }

    /**
     * 跑一个 fatal 夹具（子进程），返回 [退出码, 标准输出, 日志文本]。
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runFatal(string $case): array
    {
        $log = \sys_get_temp_dir() . '/drift-page-' . $case . '.log';
        [$code, $out] = runFatalCase($case, $log);
        $text = \is_file($log) ? (string) \file_get_contents($log) : '';
        @\unlink($log);

        return [$code, $out, $text];
    }
}

/** 单类页面的最小替身：本地态 + 生命周期钩子都在自己身上。 */
final class PageShellProbePage extends Page
{
    public int $inits = 0;
    public int $activations = 0;
    public int $disposes = 0;
    public int $cleanups = 0;
    public int $builds = 0;
    public int $count = 0;

    public function initState(): void
    {
        ++$this->inits;
        $this->onDispose(function (): void { ++$this->cleanups; });
    }

    public function onActivate(): void
    {
        ++$this->activations;
    }

    public function dispose(): void
    {
        ++$this->disposes;
    }

    public function bump(): void
    {
        $this->setState(function (): void { ++$this->count; });
    }

    public function build(BuildContext $ctx): Widget
    {
        ++$this->builds;

        return Text::make('probe-' . $this->count);
    }
}
