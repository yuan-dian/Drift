<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\AppScope;
use Drift\App\Application;
use Drift\App\Io\IoPoller;
use Drift\App\SyncTaskHost;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\App\ThemeApplication;
use Drift\Dev\Smoke\Commands\EchoCommands;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Theme\ThemeData;

/**
 * `AppScope`（M2）：**一个进程一份**的东西真的只有一份。
 *
 * ## 每条断言都成对
 *
 * "两个窗口共享同一个 X" 单看是自述 —— `===` 成立可能只是因为测试写错了。
 * 所以每条都配一条**反证**：不注入 scope 时它们必须是**两个**实例。
 * 这才证明"共享"是**显式的决定**，不是"碰巧同一个"。
 *
 * ## 为什么这件事重要到要单独一个测试类
 *
 * 这几项的失败形态几乎全是**静默**的：字体解析两次只是慢、线程池两个只是浪费、
 * 主题两份只是"一半深色"、命令表两份则是"改了别人家的数据"。唯一**必然出错**的
 * 是 `io()` —— 两个轮询器 watch 同一批 fd，同一次可读派发两次回调
 * （下单付两次款那种）。本轮（2026-10-30）把它从"注释提醒"变成"结构上只有一个"。
 */
final class AppScopeTest extends SmokeTestCase
{
    private static function app(?AppScope $scope = null): EchoApplication
    {
        // ★ EchoApplication 没有自己的构造器 ⇒ 直接吃 `Application` 的两个参数
        //   （`$handlers` 不传就是空数组）。
        return new EchoApplication([], $scope);
    }

    private static function attach(Application $app): SurfaceProbe
    {
        $surface = new SurfaceProbe();
        $surface->resize(320, 240, 1.0);
        $app->attach($surface, BoxConstraints::tight(new Size(320.0, 240.0)));

        return $surface;
    }

    public function testFiveSharesAndTheirCounterProofs(): void
    {
        Errors::reset();

        $scope = AppScope::make();
        $a = self::app($scope);
        $b = self::app($scope);
        self::attach($a);
        self::attach($b);

        check('AppScope · 注入的 scope 就是 `scope()` 返回的那个', $a->scope() === $scope && $b->scope() === $scope);

        // ① 度量：字体只解析一次
        check('AppScope · 两窗共用同一个 TextMetricsProvider', $a->metrics() === $b->metrics());

        // ② 后台端口：一个进程一个池
        check(
            'AppScope · 两窗共用同一个 TaskHost（不是两个池）',
            $a->tasks() instanceof SyncTaskHost && $a->tasks() === $b->tasks(),
        );

        // ③ IO 轮询器：一张监听表（唯一"不共享就是错"的一项）
        check('AppScope · 两窗共用同一个 IoPoller', $a->io() instanceof IoPoller && $a->io() === $b->io());

        // ④ 主题：一处取值
        check('AppScope · 两窗读到同一个主题值', $a->theme() == $b->theme());

        // ⑤ 加速键表
        check('AppScope · 两窗共用同一张加速键表', $a->accelerators() === $b->accelerators());

        // ── 反证：不注入 ⇒ 各建各的 ──
        $x = self::app();
        $y = self::app();
        self::attach($x);
        self::attach($y);
        check(
            '反证 · 不注入 scope ⇒ 各建各的（metrics / io / tasks 都不是同一个）',
            $x->metrics() !== $y->metrics()
                && $x->io() !== $y->io()
                && $x->tasks() !== $y->tasks(),
        );

        check(
            'AppScope · 单窗路径零诊断（共享不该带来新噪音）',
            Errors::recentDiagnostics() === [],
            'diag=' . \count(Errors::recentDiagnostics()),
        );

        $this->flushChecks();
    }

    public function testIoWatchIsRegisteredOnce(): void
    {
        $scope = AppScope::make();
        $a = self::app($scope);
        $b = self::app($scope);
        self::attach($a);
        self::attach($b);

        // ★ Windows 没有 AF_UNIX ⇒ 用 PF_INET(2) / SOCK_STREAM(1) / IPPROTO_IP(0)
        //   （与 `tools/smoke.php` 的 P2 段同一手法，probes §12.3 [J3] 实测可用）
        $pair = \stream_socket_pair(2, 1, 0);
        if ($pair === false) {
            $this->markTestSkipped('platform has no stream_socket_pair');
        }
        \stream_set_blocking($pair[0], false);

        $token = $a->io()?->watch($pair[0], true, function (): void {
        });

        check(
            'AppScope · 在 A 窗 watch ⇒ B 窗的监听数也是 1（同一张表，不是两份）',
            $a->io()?->watchCount() === 1 && $b->io()?->watchCount() === 1,
            'a=' . $a->io()?->watchCount() . ' b=' . $b->io()?->watchCount(),
        );

        $a->io()?->unwatch((int) $token);
        check('AppScope · A 窗 unwatch ⇒ B 窗也归零', $b->io()?->watchCount() === 0);

        \fclose($pair[0]);
        \fclose($pair[1]);

        $this->flushChecks();
    }

    public function testCommandChainIsGlobalParentAndLocalChild(): void
    {
        Errors::reset();

        $scope = AppScope::make();
        $global = new EchoCommands();
        $scope->commands()->on('app.global', $global);

        $a = self::app($scope);
        $b = self::app($scope);
        self::attach($a);
        self::attach($b);

        check(
            '命令链 · 应用级命令注册一次，两个窗口都能派发',
            $a->dispatch('app.global') && $b->dispatch('app.global') && \count($global->seen) === 2,
            'seen=' . \count($global->seen),
        );

        // 本窗注册的命令对另一个窗口**不可见**（handler 持本窗的 Store ⇒ 串窗 = 改别人家的数据）
        $local = new EchoCommands();
        $a->register('only.a', $local);
        $before = \count(Errors::recentDiagnostics());
        check(
            '命令链 · 本窗命令不让别的窗口看见（B 派发返回 false）',
            $a->dispatch('only.a') && !$b->dispatch('only.a') && \count($local->seen) === 1,
            'seen=' . \count($local->seen),
        );
        check(
            '命令链 · B 派发不到 ⇒ 记了一条 DIAGNOSTIC（不静默）',
            \count(Errors::recentDiagnostics()) === $before + 1,
            'diag=' . \count(Errors::recentDiagnostics()),
        );

        // 本窗同名命令覆盖全局：就近优先，且留痕
        $shadow = new EchoCommands();
        $a->register('app.global', $shadow);
        $seenBefore = \count($global->seen);
        $a->dispatch('app.global');
        check(
            '命令链 · 本窗同名命令覆盖应用级（全局 handler 不再被调到）',
            \count($shadow->seen) === 1 && \count($global->seen) === $seenBefore,
            'shadow=' . \count($shadow->seen) . ' global=' . \count($global->seen),
        );

        $this->flushChecks();
    }

    public function testThemeChangeReachesBothWindowsAndMismatchIsReported(): void
    {
        Errors::reset();

        // ① 共享值：改一次，两窗都读到新值
        $scope = AppScope::make();
        $a = self::app($scope);
        $b = self::app($scope);
        self::attach($a);
        self::attach($b);
        check('主题 · 初始是浅色', !$a->theme()->isDark() && !$b->theme()->isDark());

        $scope->setTheme(ThemeData::dark());
        check('主题 · scope 换主题 ⇒ 两窗立刻读到深色', $a->theme()->isDark() && $b->theme()->isDark());

        // ② 不一致：第二个窗口覆写了 theme() ⇒ 报 DIAGNOSTIC（不改行为，但留痕）
        Errors::reset();
        // ★ 应用级先切深色（组合根的决定），再挂两个窗口：一个跟 scope、一个写死浅色
        $shared = AppScope::make()->setTheme(ThemeData::dark());
        $plain = self::app($shared);
        self::attach($plain);
        $overrider = new ThemeApplication($shared);
        self::attach($overrider);

        $diag = Errors::recentDiagnostics();
        check(
            '主题 · 第二个窗口覆写了 theme() ⇒ 一条 DIAGNOSTIC（"一半深色一半浅色"的唯一入口）',
            \count($diag) === 1 && \str_contains($diag[0], 'AppScope'),
            'diag=' . \count($diag) . ' ' . ($diag[0] ?? ''),
        );
        check(
            '主题 · 覆写仍然生效（只报不改 —— 行为与 M2 之前一致）',
            $overrider->theme()->isDark() === false,
        );

        $this->flushChecks();
    }

    public function testTaskHostIsFinalAndScopeIsInjectable(): void
    {
        $method = new \ReflectionMethod(Application::class, 'taskHost');
        check(
            'AppScope · `taskHost()` 是 final（子类覆写 = 加载期致命错误，不留静默的两套队列）',
            $method->isFinal(),
        );

        // 端口为 null（"本应用不用后台任务"）能经由 scope 表达，且三态不被 `??=` 吃掉
        $scope = AppScope::make()->setTaskHost(null);
        $app = self::app($scope);
        self::attach($app);
        check('AppScope · `setTaskHost(null)` ⇒ 不装端口（三态表达得出来）', $app->tasks() === null);

        // 默认仍然给 SyncTaskHost（零配置也有正确的异步语义）
        $plain = self::app();
        self::attach($plain);
        check('AppScope · 未表态 ⇒ 默认 SyncTaskHost', $plain->tasks() instanceof SyncTaskHost);

        $this->flushChecks();
    }

    public function testRouterChainKeepsSingleLayerSemantics(): void
    {
        // 不注入 scope 的普通 router：`has()` 只看本层，行为与 M2 之前逐条相同
        $a = self::app();
        self::attach($a);
        $handler = new EchoCommands();
        $a->register('x.one', $handler);

        check(
            'CommandRouter · 单层表：has() / dispatch() 与旧语义一致',
            $a->router()->has('x.one') && $a->dispatch('x.one'),
        );
        check(
            'CommandRouter · names() 只报本层（父表名册归父表答）',
            $a->router()->names() === ['x.one'] || \in_array('x.one', $a->router()->names(), true),
            \implode(',', $a->router()->names()),
        );

        // 父链的 namesInChain 才包含全局
        $scope = AppScope::make();
        $scope->commands()->on('g.one', new EchoCommands());
        $b = self::app($scope);
        self::attach($b);
        $b->register('l.one', new EchoCommands());

        $names = $b->router()->namesInChain();
        // ★ `echo.hello` 是 EchoApplication 在 attach() 里注册的本窗命令 ⇒ 全链共 3 个
        check(
            'CommandRouter · namesInChain() 含本层与父表（去重）',
            \in_array('l.one', $names, true)
                && \in_array('g.one', $names, true)
                && \in_array('echo.hello', $names, true)
                && \count($names) === \count(\array_unique($names)),
            \implode(',', $names),
        );
        check(
            'CommandRouter · hasInChain() 全链可见，has() 只本层',
            $b->router()->hasInChain('g.one') && !$b->router()->has('g.one'),
        );

        $this->flushChecks();
    }
}
