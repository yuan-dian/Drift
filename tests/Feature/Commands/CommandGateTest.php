<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Commands;

use App\Users\Store\MemoryUserSource;
use App\Users\UsersApplication;
use Drift\App\Application;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandGate;
use Drift\Commands\CommandHandler;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\AcceleratorTable;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\Column;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use PHPUnit\Framework\TestCase;

/** @internal 记录型 handler：判据读公开数组，不读框架私有状态。 */
final class RecordingHandler implements CommandHandler
{
    /** @var list<string> */
    public array $seen = [];

    public function handle(string $name, CommandArg $arg): bool
    {
        $this->seen[] = $name;

        return true;
    }
}

/** @internal 记录型门控：被问了几次、问的是哪条命令都可见。 */
final class RecordingGate implements CommandGate
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(public bool $allow = false)
    {
    }

    public function allows(string $name, CommandArg $arg): bool
    {
        $this->asked[] = $name;

        return $this->allow;
    }
}

/** @internal 最小完整应用：两条命令，一条挂门控、一条不挂。 */
final class GateApplication extends Application
{
    public function __construct(
        public RecordingHandler $handler,
        public RecordingGate $gate,
        public bool $withGate = true,
        public bool $orphanGate = false,
    ) {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return Column::make()->children([Text::make('门控')]);
    }

    public function title(): string
    {
        return '门控应用';
    }

    public function commands(): iterable
    {
        return ['probe.fire' => $this->handler, 'probe.free' => $this->handler];
    }

    public function commandGates(): iterable
    {
        if ($this->orphanGate) {
            return ['ghost.cmd' => $this->gate];
        }

        return $this->withGate ? ['probe.fire' => $this->gate] : [];
    }

    public function accelerators(): AcceleratorTable
    {
        return AcceleratorTable::make()
            ->bind(KeyChord::ctrl('J'), 'probe.fire')
            ->bind(KeyChord::ctrl('K'), 'probe.free');
    }
}

/**
 * 命令启用态：一个写者、一个判定入口、所有派发路径共用。
 *
 * ★ 判据不与实现同源：读记录型具名类的公开数组 + `Errors` 环形缓冲，
 *   不读 `CommandRouter::$gates` 之类私有状态，也不读配置字段。
 * ★ 按键走 `$app->dispatchInput(KeyEvent::down(...))` 全链（`smoke.php:4059` 同款），
 *   不是直接调 `dispatch()`。
 */
final class CommandGateTest extends TestCase
{
    private function mounted(bool $allow = false, bool $withGate = true): GateApplication
    {
        $app = new GateApplication(new RecordingHandler(), new RecordingGate($allow), $withGate);
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        return $app;
    }

    public function testKeyPathIsGated(): void
    {
        $app = $this->mounted();
        $before = count(Errors::recentDiagnostics());

        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('J')));

        self::assertSame([], $app->handler->seen, '门控拒绝 ⇒ handler 不该被调用');
        self::assertSame(['probe.fire'], $app->gate->asked, '门控恰好被问一次');
        self::assertSame($before + 1, count(Errors::recentDiagnostics()), '被拦要留诊断，不能静默');
    }

    public function testClickPathSharesTheSameGate(): void
    {
        $app = $this->mounted();
        $before = count(Errors::recentDiagnostics());

        $app->dispatch('probe.fire');

        self::assertSame([], $app->handler->seen, '点击路径同样被拦');
        self::assertSame(['probe.fire'], $app->gate->asked, '两条入口问的是同一个门控');
        self::assertSame($before + 1, count(Errors::recentDiagnostics()));
    }

    public function testGateIsAskedOncePerDispatch(): void
    {
        $app = $this->mounted();

        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('J')));
        $app->dispatch('probe.fire');

        self::assertSame(['probe.fire', 'probe.fire'], $app->gate->asked, '每次派发只判一次');
        self::assertSame([], $app->handler->seen);
    }

    public function testUngatedCommandStillDispatches(): void
    {
        $app = $this->mounted();

        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('K')));

        self::assertSame(['probe.free'], $app->handler->seen, '没挂门控 ⇒ 默认放行（兼容既有命令）');
        self::assertSame([], $app->gate->asked, '没挂门控的命令不该被问');
    }

    public function testAllowingGateDispatchesWithoutDiagnostic(): void
    {
        $app = $this->mounted(true);
        $before = count(Errors::recentDiagnostics());

        $app->dispatch('probe.fire');

        self::assertSame(['probe.fire'], $app->handler->seen);
        self::assertSame(['probe.fire'], $app->gate->asked);
        self::assertSame($before, count(Errors::recentDiagnostics()), '放行不该报诊断');
    }

    public function testOrphanGateIsReported(): void
    {
        $before = count(Errors::recentDiagnostics());
        $app = new GateApplication(new RecordingHandler(), new RecordingGate(), false, true);
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        self::assertSame($before + 1, count(Errors::recentDiagnostics()), '门控键没对应命令 ⇒ 响亮留痕');
    }

    public function testRealConsumerBackGuard(): void
    {
        $app = new UsersApplication(new MemoryUserSource());
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(900.0, 640.0)));

        // 栈底：旧行为是 pop() 返回 false 却什么都不报 ⇒ 按 Alt+Left 静默无反应
        $before = count(Errors::recentDiagnostics());
        $app->dispatchInput(KeyEvent::down(KeyChord::alt('Left')));
        self::assertSame('users', $app->appStore()->currentPage());
        self::assertSame($before + 1, count(Errors::recentDiagnostics()), '栈底返回被门控拦下且留痕');

        // 有历史：照常返回，不误拦
        $app->appStore()->navigate('settings');
        $before = count(Errors::recentDiagnostics());
        $app->dispatchInput(KeyEvent::down(KeyChord::alt('Left')));
        self::assertSame('users', $app->appStore()->currentPage());
        self::assertSame($before, count(Errors::recentDiagnostics()), '有历史时放行且不报诊断');
    }

    /** 生产接线：判定必须在冒泡之前，且门控经 on() 第三参进同一张表。 */
    public function testProductionWiring(): void
    {
        $root = dirname(__DIR__, 3);
        $application = (string) file_get_contents($root . '/src/App/Application.php');
        $router = (string) file_get_contents($root . '/src/Commands/CommandRouter.php');

        $enabled = strpos($application, '$this->router->enabled($name, $arg)');
        $bubble = strpos($application, '$this->bubble($root, $name, $arg)');
        self::assertNotFalse($enabled, 'dispatch() 必须查门控');
        self::assertNotFalse($bubble);
        self::assertLessThan($bubble, $enabled, '门控判定必须在冒泡之前');

        self::assertStringContainsString(
            '$this->router->on($name, $handler, $gates[$name] ?? null)',
            $application,
            '门控必须与 handler 同一写者注册',
        );
        self::assertStringContainsString(
            '$this->parent?->gateInChain($name)',
            $router,
            '门控必须沿父链（全局表挂的门控也要生效）',
        );
        self::assertSame(
            1,
            preg_match('/public function on\(string \$name, CommandHandler \$handler, \?CommandGate \$gate = null\)/', $router),
            'on() 第三参必须可选，否则全部既有注册点要改',
        );
    }

    /** 菜单/UIA 声明的每个 chord 都必须真绑进键表，否则按了静默无反应。 */
    public function testChordDeclarationsAreBound(): void
    {
        $apps = glob(dirname(__DIR__, 3) . '/apps/*/src');
        self::assertIsArray($apps);

        foreach ($apps as $srcDir) {
            $chords = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    foreach (self::keysIn((string) file_get_contents($file->getPathname()), 'chord') as $key) {
                        $chords[] = $key;
                    }
                }
            }
            if ($chords === []) {
                continue;
            }

            $binds = [];
            foreach (glob($srcDir . '/*Application.php') ?: [] as $appFile) {
                foreach (self::keysIn((string) file_get_contents($appFile), 'bind') as $key) {
                    $binds[] = $key;
                }
            }

            foreach (array_unique($chords) as $key) {
                self::assertContains(
                    $key,
                    $binds,
                    basename(dirname($srcDir)) . ' 菜单声明了 ' . $key . ' 但键表没绑',
                );
            }
        }
    }

    /** @return list<string> 规范化为 "工厂|参数"，只解析声明文本，不调用 KeyChord。 */
    private static function keysIn(string $source, string $method): array
    {
        preg_match_all('/->' . $method . '\(KeyChord::([a-zA-Z]+)\(([^)]*)\)/', $source, $hits, PREG_SET_ORDER);
        $keys = [];
        foreach ($hits as $hit) {
            $keys[] = $hit[1] . '|' . trim($hit[2], " \t\n\r\"'");
        }

        return $keys;
    }
}
