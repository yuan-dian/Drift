<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\App\DebugInputInterceptor;
use Drift\App\DebugTicker;
use Drift\App\EventLoop;
use Drift\DevTools\Protocol\Methods;
use Drift\DevTools\Protocol\Wire;
use Drift\DevTools\Host\Discovery;
use Drift\DevTools\Host\LiveTicker;
use Drift\DevTools\Host\SingleServer;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\FakeTransport;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\InputEvent;
use Drift\Input\PointerEvent;
use Drift\Platform\Win32\Win32Platform;

/**
 * A58 活窗口拾取：dev 桥的输入闸门。
 *
 * ## 这一组钉什么
 *
 * | 契约 | 漏掉的后果 |
 * |---|---|
 * | 未布防时不拦截 | 窗口假死：所有点击都到不了应用 |
 * | 命中节点落到 `highlight` | 拾取只是"看了"，开发者拿不到可追问的身份 |
 * | down..up 整段吞 | 只吞 down 会让 up 触发按钮激活 —— 选中即点下 |
 * | 点击后模式保持 | 点第二个控件要回命令行再布一次，排查就断了 |
 * | `Ctrl+Shift+I` 可进出 | 只能命令行开、还要另发一条退出 |
 * | 应用绑了同一组合时让位 | 调试器抢了应用快捷键，功能被静默改掉 |
 * | 窗口外松开有兜底 | 收不到 up 时吞态永驻，窗口看起来死了 |
 * | Esc 取消且被吞 | 布防期按 Esc 顺手关掉了应用菜单 |
 * | 暂停态拒布防 | 回执说布防了，但闸门根本不读输入，等到超时 |
 *
 * 这里不启子进程：判的是状态机形状与输入判据，socket 往返由
 * {@see LiveAttachTest} 覆盖。
 */
final class LivePickTest extends SmokeTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = \sys_get_temp_dir() . '/drift-pick-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && \is_dir($this->dir)) {
            foreach (\glob($this->dir . '/.drift/*') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($this->dir . '/.drift');
            @\rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function ticker(?Application $app = null): LiveTicker
    {
        $transport = new FakeTransport([]);
        $discovery = Discovery::inDirectory($this->dir);
        $ticker = new LiveTicker(
            new SingleServer($transport),
            'token',
            'pick-test',
            0,
            5.0,
            null,
            $discovery,
        );
        $app ??= new EchoApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $ticker->bind([1 => $app]);
        self::assertNotNull($ticker->session(), '夹具没有装上会话');

        return $ticker;
    }

    /** 与实现不同的独立路径：从 `pick` 查询的链里取最深有主节点。 */
    private static function deepestNode(LiveTicker $ticker, float $x, float $y): int
    {
        $answer = $ticker->session()?->query('pick', ['x' => $x, 'y' => $y]) ?? [];
        $chain = $answer['chain'] ?? [];
        foreach (\is_array($chain) ? \array_reverse($chain) : [] as $fact) {
            $node = \is_array($fact) ? ($fact['node'] ?? 0) : 0;
            if (\is_int($node) && $node > 0) {
                return $node;
            }
        }

        return 0;
    }

    public function testUnarmedBridgeNeverIntercepts(): void
    {
        $ticker = $this->ticker();
        check('未布防时 down 照常进应用', !$ticker->interceptInput(PointerEvent::down(10.0, 10.0)));
        check('未布防时 Esc 照常进应用', !$ticker->interceptInput(KeyEvent::down(KeyChord::escape())));
        $ticker->close();
    }

    public function testArmThenDownPickIntoHighlight(): void
    {
        $ticker = $this->ticker();
        $armed = $ticker->armPick(true);
        check('布防成功', $armed['armed'] === true, self::show($armed));

        $expected = self::deepestNode($ticker, 160.0, 120.0);
        check('夹具里确实有可命中的节点', $expected > 0, self::show($expected));
        check('布防期的 down 被吞', $ticker->interceptInput(PointerEvent::down(160.0, 120.0)));

        $highlight = $ticker->session()?->query('highlight') ?? [];
        check('命中落到高亮', ($highlight['node'] ?? 0) === $expected, self::show($highlight));
        $ticker->close();
    }

    public function testWholeClickIsSwallowedAndModeStays(): void
    {
        $ticker = $this->ticker();
        $ticker->armPick(true);
        $ticker->interceptInput(PointerEvent::down(160.0, 120.0));

        check('up 也吞，不能让按钮在拾取时激活', $ticker->interceptInput(PointerEvent::up(160.0, 120.0)));
        // 模式保持：这是 DevTools 的 inspect，不是"只接一发"的布防。收尾之后
        // 下一次 down 仍然归调试器；要放行只能显式撤防（Esc / 快捷键 / --clear）。
        check('收尾后模式仍在：下一次 down 仍被吞', $ticker->interceptInput(PointerEvent::down(160.0, 120.0)));
        $ticker->interceptInput(PointerEvent::up(160.0, 120.0));
        $ticker->armPick(false);
        check('撤防后才放行', !$ticker->interceptInput(PointerEvent::down(160.0, 120.0)));
        $ticker->close();
    }

    public function testMoveWithNoButtonReleasesMissingUp(): void
    {
        $ticker = $this->ticker();
        $ticker->armPick(true);
        $ticker->interceptInput(PointerEvent::down(160.0, 120.0));

        // 在窗口外松开时这里收不到 up；下一条 move 的 button=0 就是兜底。
        check('无按键的 move 仍吞，但释放吞态', $ticker->interceptInput(PointerEvent::move(200.0, 90.0)));
        // 释放的是"这段点击的收尾"，不是模式本身：下一次点击仍应被调试器接住。
        check('模式保持，不会把后续点击漏给应用', $ticker->interceptInput(PointerEvent::down(160.0, 120.0)));
        $ticker->interceptInput(PointerEvent::up(160.0, 120.0));
        $ticker->armPick(false);
        check('撤防后才不会永久假死残留', !$ticker->interceptInput(PointerEvent::down(160.0, 120.0)));
        $ticker->close();
    }

    public function testCtrlShiftITogglesPickMode(): void
    {
        $ticker = $this->ticker();
        $chord = KeyChord::make('i', ctrl: true, shift: true);

        check('快捷键进入模式并被吞', $ticker->interceptInput(KeyEvent::down($chord)));
        check('进入后点击被吞', $ticker->interceptInput(PointerEvent::down(160.0, 120.0)));
        $ticker->interceptInput(PointerEvent::up(160.0, 120.0));

        check('再按一次退出并被吞', $ticker->interceptInput(KeyEvent::down($chord)));
        check('退出后点击放行', !$ticker->interceptInput(PointerEvent::down(160.0, 120.0)));
        $ticker->close();
    }

    public function testShortcutYieldsToApplicationBinding(): void
    {
        $transport = new FakeTransport([]);
        $ticker = new LiveTicker(
            new SingleServer($transport),
            'token',
            'pick-test',
            0,
            5.0,
            null,
            Discovery::inDirectory($this->dir),
        );
        $app = new EchoApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        $chord = KeyChord::make('i', ctrl: true, shift: true);
        $app->scope()->accelerators()->bind($chord, 'app.command');
        $ticker->bind([1 => $app]);

        check('应用占用时快捷键不拦', !$ticker->interceptInput(KeyEvent::down($chord)));
        check('也没有偷偷进入模式', !$ticker->interceptInput(PointerEvent::down(160.0, 120.0)));
        $ticker->close();
    }

    public function testEscapeCancelsPickAndIsSwallowed(): void
    {
        $ticker = $this->ticker();
        $ticker->armPick(true);
        check('布防期 Esc 被吞', $ticker->interceptInput(KeyEvent::down(KeyChord::escape())));
        check('Esc 撤销布防', !$ticker->interceptInput(PointerEvent::down(160.0, 120.0)));
        $ticker->close();
    }

    public function testPauseRejectsArmingWithReason(): void
    {
        $ticker = $this->ticker();
        $ticker->session()?->markState('paused', 'requested');
        $armed = $ticker->armPick(true);
        check('暂停态不布防', $armed['armed'] === false, self::show($armed));
        check('理由如实报 paused', ($armed['reason'] ?? '') === 'paused', self::show($armed));
        $ticker->close();
    }

    public function testMissClearsPreviousHighlight(): void
    {
        $ticker = $this->ticker();
        $previous = self::deepestNode($ticker, 160.0, 120.0);
        self::assertGreaterThan(0, $previous);
        $ticker->session()?->highlight($previous);
        $ticker->armPick(true);
        $ticker->interceptInput(PointerEvent::down(-100.0, -100.0));
        check('没命中就清除旧高亮', ($ticker->session()?->query('highlight') ?? [])['node'] === 0);
        $ticker->close();
    }

    public function testCloseDisarms(): void
    {
        $ticker = $this->ticker();
        $ticker->armPick(true);
        $ticker->close();
        check('关闭后没有会话可拦', !$ticker->interceptInput(PointerEvent::down(160.0, 120.0)));
        check('关闭后布防被清掉', $ticker->armPick(false)['armed'] === false);
    }

    /**
     * 从协议动词一路走到桥：钉住 `bind()` 把布防回调交给 `Host` 的那一行。
     * 漏传第 5 个构造参数时，直接调 `armPick()` 的判据照样全绿，而真运行时
     * `PICK` 只会回 `not_applicable` —— 典型的"两组测试都绿、接缝是断的"。
     */
    public function testPickVerbReachesBridgeThroughHost(): void
    {
        $transport = new FakeTransport([
            Wire::encode([
                'id' => 1,
                'method' => Methods::PICK,
                'params' => ['enabled' => true],
            ]),
        ]);
        $ticker = new LiveTicker(
            new SingleServer($transport),
            'token',
            'pick-test',
            0,
            5.0,
            null,
            Discovery::inDirectory($this->dir),
        );
        $app = new EchoApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $ticker->bind([1 => $app]);
        $ticker->poll();   // Host::pollOnce(0) 就地消化这一条

        $replies = $transport->replies();
        check('PICK 动词真的到了桥', ($replies[0]['result']['armed'] ?? false) === true, self::show($replies));
        check('桥已布防，输入会被吞', $ticker->interceptInput(PointerEvent::down(160.0, 120.0)));
        $ticker->close();
    }

    /**
     * 把 `EventLoop` 那三行钩子也钉住：前面的判据直接调桥，把它们删掉照样全绿。
     * 这里用一个只记不拦的假 ticker，证明主循环在派发之前真的问过拦截器。
     */
    public function testEventLoopAsksInterceptorBeforeDispatch(): void
    {
        winLoopReset();
        $ticker = new class implements DebugTicker, DebugInputInterceptor {
            /** @var list<InputEvent> */
            public array $seen = [];

            public function waitHintMs(): ?float
            {
                return null;
            }

            public function bind(array $apps): void
            {
            }

            public function poll(): void
            {
            }

            public function canAdvance(): bool
            {
                return true;
            }

            public function afterFrame(): void
            {
            }

            public function close(): void
            {
            }

            public function interceptInput(InputEvent $event): bool
            {
                $this->seen[] = $event;

                return false;   // 放行，免得这条判据依赖"吞掉之后应用会怎样"
            }
        };
        EventLoop::setTickerFactory(static fn(): DebugTicker => $ticker);
        try {
            $platform = new Win32Platform();
            $handle = $platform->window()->createWindow('pick', 640, 480);
            $app = new EchoApplication();
            $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
            winPumpScript([
                ['type' => 'key', 'key' => 'A'],
                ['type' => 'quit'],
            ]);
            EventLoop::run($platform, [$handle => $app]);
        } finally {
            EventLoop::setTickerFactory(null);
        }

        check('主循环在派发前问了拦截器', \count($ticker->seen) === 1, self::show($ticker->seen));
        check('问的是真输入事件', ($ticker->seen[0] ?? null) instanceof KeyEvent);
        $this->flushChecks();
    }

    private static function show(mixed $value): string
    {
        return \json_encode($value, \JSON_UNESCAPED_UNICODE) ?: \get_debug_type($value);
    }
}
