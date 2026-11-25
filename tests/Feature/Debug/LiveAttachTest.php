<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\App\DebugTicker;
use Drift\App\EventLoop;
use Drift\Debug\Protocol\ProtocolError;
use Drift\Debug\Protocol\RemoteSession;
use Drift\Dev\Debug\Discovery;
use Drift\Dev\Debug\SocketChannel;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Platform\Win32\Win32Platform;

/**
 * A57 live attach：dev 态的协作式 tick。
 *
 * ## 这一组钉什么
 *
 * | 契约 | 漏掉的后果 |
 * |---|---|
 * | 有桥时 `waitEvents()` 必有上界 | 空闲窗口无限睡，socket 上的消息永远进不来 |
 * | 每轮都 `poll()`（含暂停轮） | `resume` 自己进不来，暂停变成死锁 |
 * | 暂停连输入一起不派发 | 状态照变，只是没画 —— "暂停"是句谎话 |
 * | 单步预算在帧末扣 | 按了单步状态就说停了，界面还没动 |
 * | 真 socket 往返 | 内存通道全绿而客户端接不上真窗口 |
 *
 * ## 为什么用真 socket + 假平台
 *
 * 桥的价值全在"跨进程能接上正在跑的窗口"，所以消息面必须真走 loopback；
 * 窗口与泵仍用 smoke 假桥 —— 这里判的是调度契约，不是 Win32 ABI。
 */
final class LiveAttachTest extends SmokeTestCase
{
    private string $dir = '';

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    protected function setUp(): void
    {
        parent::setUp();
        winLoopReset();
        EventLoop::setTickerFactory(null);
        $this->dir = \sys_get_temp_dir() . '/drift-live-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->stopHost();
        EventLoop::setTickerFactory(null);
        if ($this->dir !== '' && \is_dir($this->dir)) {
            foreach (\glob($this->dir . '/.drift/*') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($this->dir . '/.drift');
            @\rmdir($this->dir);
        }
        parent::tearDown();
    }

    /**
     * @return array{0: Win32Platform, 1: Application, 2: int}
     */
    private static function mount(Application $app): array
    {
        $platform = new Win32Platform();
        $handle = $platform->window()->createWindow('live', 640, 480);
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        return [$platform, $app, $handle];
    }

    /**
     * 起一个"真窗口的替身"子进程：真 socket + 每轮一帧的轮询。
     *
     * @return array{port: int, token: string, pid: int, entries: list<string>}
     */
    private function startHost(): array
    {
        $root = \dirname(__DIR__, 3);
        $devNull = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = \proc_open(
            [\PHP_BINARY, $root . '/tests/Support/live-attach-host.php', $this->dir],
            [
                0 => ['file', $devNull, 'r'],
                1 => ['file', $devNull, 'w'],
                2 => ['file', $devNull, 'w'],
            ],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            self::fail('live 宿主起不来');
        }
        $this->process = $process;
        $this->pipes = $pipes;

        $deadline = \microtime(true) + 10.0;
        $ready = $this->dir . '/ready.json';
        while (\microtime(true) < $deadline) {
            if (\is_file($ready)) {
                $raw = (string) \file_get_contents($ready);
                $data = \json_decode($raw, true);

                return \is_array($data) ? $data : [];
            }
            \usleep(20_000);
        }
        self::fail('live 宿主 10 秒内没有就绪');

        return [];
    }

    private function stopHost(): void
    {
        \file_put_contents($this->dir . '/stop', '1');
        if ($this->process !== null) {
            $deadline = \microtime(true) + 2.0;
            while (\microtime(true) < $deadline && \proc_get_status($this->process)['running']) {
                \usleep(20_000);
            }
            if (\proc_get_status($this->process)['running']) {
                @\proc_terminate($this->process, 9);
            }
            @\proc_close($this->process);
            $this->process = null;
        }
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
        $this->pipes = [];
    }

    /** 假桥：只记账，不认识 socket。用来钉主循环的调度形状。 */
    private static function spy(?callable $canAdvance = null): DebugTicker
    {
        return new class($canAdvance) implements DebugTicker {
            public int $polls = 0;

            public int $frames = 0;

            public int $closes = 0;

            public function __construct(private readonly ?\Closure $gate)
            {
            }

            public function waitHintMs(): ?float
            {
                return 7.0;
            }

            public function bind(array $apps): void
            {
            }

            public function poll(): void
            {
                ++$this->polls;
            }

            public function canAdvance(): bool
            {
                return $this->gate === null ? true : ($this->gate)($this->frames);
            }

            public function afterFrame(): void
            {
                ++$this->frames;
            }

            public function close(): void
            {
                ++$this->closes;
            }
        };
    }

    /** 判据 1：有桥时等待必须有上界、每轮收一次消息、退出时关一次。 */
    public function testTickerBoundsWaitAndIsPolledEveryWake(): void
    {
        [$platform, $app, $handle] = self::mount(new EchoApplication());
        $spy = self::spy();
        EventLoop::setTickerFactory(static fn(array $apps): DebugTicker => $spy);

        // 两次 timeout 各跑一帧，`quit` 那一轮直接跳出（不 poll）。
        winPumpScript([['type' => 'timeout'], ['type' => 'timeout'], ['type' => 'quit']]);
        EventLoop::run($platform, [$handle => $app]);

        check('每轮等完都 poll（timeout 轮两次）', $spy->polls === 2, (string) $spy->polls);
        check('每帧跑完通知一次', $spy->frames === 2, (string) $spy->frames);
        check('退出时关一次桥', $spy->closes === 1, (string) $spy->closes);
        $waits = winWaitCalls();
        // 三轮等待（含最后那轮 `quit`）：桥在时不许有一轮落回无限等。
        check('三次限时等待都取桥给的 7ms', $waits === [7.0, 7.0, 7.0], \json_encode($waits));

        $this->flushChecks();
    }

    /** 判据 2：桥暂停时帧冻结，但 poll 继续 —— 否则 resume 永远进不来。 */
    public function testPauseFreezesFramesButKeepsPolling(): void
    {
        [$platform, $app, $handle] = self::mount(new EchoApplication());
        $spy = self::spy(static fn(int $frames): bool => $frames < 1);
        EventLoop::setTickerFactory(static fn(array $apps): DebugTicker => $spy);

        winPumpScript([
            ['type' => 'timeout'],
            ['type' => 'timeout'],
            ['type' => 'timeout'],
            ['type' => 'quit'],
        ]);
        EventLoop::run($platform, [$handle => $app]);

        check('只跑了一帧', $spy->frames === 1, (string) $spy->frames);
        check('暂停后仍有三次 poll（quit 前每轮一次）', $spy->polls === 3, (string) $spy->polls);
        check('主循环没有把暂停当作退出', $spy->closes === 1, (string) $spy->closes);

        $this->flushChecks();
    }

    /** 判据 3：没有桥时仍是无限等（老路径一分不让）。 */
    public function testNoTickerKeepsInfiniteWait(): void
    {
        [$platform, $app, $handle] = self::mount(new EchoApplication());
        winPumpScript([['type' => 'quit']]);
        EventLoop::run($platform, [$handle => $app]);

        check('空闲路径零次限时等待', winWaitCalls() === [], \json_encode(winWaitCalls()));
        $this->flushChecks();
    }

    /**
     * 判据 4：真 socket 上 attach → query → pause → resume(1) → 帧被拒。
     *
     * 不跑 `EventLoop`（它在这个用例里是假的），直接调 `bind()` / `poll()`，
     * 因为这里判的是**消息面接上了真窗口的会话**，不是调度。
     */
    public function testLiveSessionIsAttachableOverLoopback(): void
    {
        $ready = $this->startHost();
        $discovery = Discovery::inDirectory($this->dir);
        check('发现文件登记了一条', \count($discovery->sessions()) === 1, \json_encode($discovery->sessions()));

        $remote = new RemoteSession(SocketChannel::connect((int) $ready['port']));
        $remote->attach((string) $ready['token']);
        check('回执说这是 live 会话', $remote->live() === true, \json_encode($remote->live()));
        check('回执说它不可重放', $remote->replayable() === false, \json_encode($remote->replayable()));
        check('回执说帧由宿主推', $remote->driven() === true, \json_encode($remote->driven()));

        $before = (int) ($remote->query('state')['lastFrame'] ?? 0);
        \usleep(60_000);
        $later = (int) ($remote->query('state')['lastFrame'] ?? 0);
        check('观察期间帧自己在涨', $later > $before, "{$before} → {$later}");

        $paused = $remote->pause();
        check('pause 生效', ($paused['state'] ?? '') === 'paused', \json_encode($paused));
        check('理由是 requested', ($paused['reason'] ?? '') === 'requested', \json_encode($paused));
        $frozen = (int) ($paused['lastFrame'] ?? 0);
        \usleep(80_000);
        $still = (int) ($remote->query('state')['lastFrame'] ?? 0);
        check('暂停后帧真的不动', $still === $frozen, "{$frozen} → {$still}（暂停期间仍在收消息）");

        $step = $remote->resume(1);
        check('resume(1) 回到 running', ($step['state'] ?? '') === 'running', \json_encode($step));
        $stopped = $this->waitForPause($remote);
        check('一帧后回到 paused', ($stopped['state'] ?? '') === 'paused', \json_encode($stopped));
        check('理由是 step', ($stopped['reason'] ?? '') === 'step', \json_encode($stopped));
        check('恰好走了一帧', (int) ($stopped['lastFrame'] ?? 0) === $frozen + 1, \json_encode($stopped));

        try {
            $remote->pump(1000.0);
            check('live 会话不许客户端喂 t', false, '居然收了');
        } catch (ProtocolError $e) {
            check('frame 报 not_applicable', $e->errorCode === ProtocolError::NOT_APPLICABLE, $e->errorCode);
        }

        $remote->detach();
        $this->stopHost();
        check('关闭后发现文件清空', $discovery->sessions() === [], \json_encode($discovery->sessions()));
        $this->flushChecks();
    }

    /** 判据 5：令牌不对的连不上 live 窗口（与 serve 宿主同一道门）。 */
    public function testLiveAttachRequiresTheToken(): void
    {
        $ready = $this->startHost();
        $wrong = new RemoteSession(SocketChannel::connect((int) $ready['port']));
        try {
            $wrong->attach(\str_repeat('0', 32));
            check('错误令牌进不来', false, '居然接上了');
        } catch (ProtocolError $e) {
            check('错误令牌报 unauthorized', $e->errorCode === ProtocolError::UNAUTHORIZED, $e->errorCode);
        } finally {
            $wrong->detach();
        }
        $this->flushChecks();
    }

    /** @return array<string, mixed> */
    private function waitForPause(RemoteSession $session, float $seconds = 2.0): array
    {
        $deadline = \microtime(true) + $seconds;
        while (\microtime(true) < $deadline) {
            $state = $session->query('state');
            if (($state['state'] ?? '') === 'paused') {
                return $state;
            }
            \usleep(10_000);
        }

        return ['state' => 'timeout'];
    }
}
