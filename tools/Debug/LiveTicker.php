<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\App\Application;
use Drift\App\DebugInputInterceptor;
use Drift\App\DebugSession;
use Drift\App\DebugTicker;
use Drift\Debug\Protocol\Server;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Script;
use Drift\Geometry\BoxConstraints;
use Drift\Input\InputEvent;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;

/**
 * dev-only 的 live attach 桥：`EventLoop` 每轮回调一次，消息走 `Host` 的同一条分派。
 *
 * ## 认第一个窗口，不假扮多 target
 *
 * 单桥只接 `$apps` 里的第一个应用。第二个窗口仍照常跑帧、仍写自己的录制器；
 * 想同时看两个窗口是 A53 留下的独立一件，不靠给这里塞数组解决 —— 那会让
 * "会话属于谁"在消息层又要判一次。
 *
 * ## 谁开会话、谁收尾
 *
 * `bind()` 在循环开始前开 `DebugSession` 并登记发现文件；`EventLoop` 的 `finally`
 * 调 `close()`。放在循环侧而不是 dev-entry 的 `try/finally` 是因为关窗路径可能
 * 从 `Quit` / 异常 / 表空三处走，任何一处漏销条目都会留下"宿主还在"的僵尸。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class LiveTicker implements DebugTicker, DebugInputInterceptor
{
    private ?DebugSession $session = null;

    private ?Host $host = null;

    private ?LiveDrive $drive = null;

    private ?string $entryId = null;

    private bool $closed = false;

    /** `PICK {enabled:true}` 布防：下一次真窗口点击转为拾取（A58）。 */
    private bool $pickArmed = false;

    /** 已吞下一次 down，正等这段点击收尾；期间所有指针输入都不进应用。 */
    private bool $pickSwallowing = false;

    private readonly Discovery $discovery;

    /**
     * @param Server                    $server socket 监听（dev-only，不进 AOT）
     * @param string                    $token  访问令牌：能不能连上这个宿主
     * @param string                    $target 发现文件里给人看的名字
     * @param int                       $port   监听端口（`Server` 接口本身不报端口）
     * @param float                     $waitMs 有桥时 `waitEvents()` 的上界
     * @param null|\Closure(string):void $report 装配失败往哪说
     */
    public function __construct(
        private readonly Server $server,
        private readonly string $token,
        private readonly string $target,
        private readonly int $port,
        private readonly float $waitMs = 25.0,
        private readonly ?\Closure $report = null,
        ?Discovery $discovery = null,
    ) {
        $this->discovery = $discovery ?? Discovery::inDirectory((string) \getcwd());
    }

    public function bind(array $apps): void
    {
        if ($this->closed || $apps === []) {
            return;
        }

        $app = \reset($apps);
        if (!$app instanceof Application) {
            return;
        }
        if ($app->debugRecorder() !== null) {
            $this->report?->__invoke('这个窗口已经有调试会话：live attach 不接管它');

            return;
        }

        $box = $app->rootBox();
        $constraints = $box?->constraints() ?? BoxConstraints::unbounded();
        $size = $box?->size();
        $script = new Script(
            $this->target,
            $size === null ? 0.0 : $size->width,
            $size === null ? 0.0 : $size->height,
        );

        $this->session = DebugSession::open(
            $app,
            $constraints,
            RecordOptions::default()->withScript($script),
        );
        $this->drive = new LiveDrive($script);
        // A58：宿主只翻译协议，拾取状态留在这里独占 —— 两边各存一个布尔必然漂开。
        $this->host = new Host(
            $this->server,
            $this->token,
            $this->drive,
            false,
            \Closure::fromCallable([$this, 'armPick']),
        );
        $this->host->adopt($this->session);
        $this->entryId = $this->discovery->register($this->target, $this->token, $this->port);

        $this->report?->__invoke(\sprintf(
            'live attach 已就绪：%s（port %d）；连上去看：drift debug brief --attach',
            $this->discovery->path(),
            $this->port,
        ));
    }

    public function waitHintMs(): ?float
    {
        return $this->closed ? null : $this->waitMs;
    }

    public function poll(): void
    {
        // `0`：EventLoop 已经为 socket 留过等待上界，这里再等就是两次睡。
        $this->host?->pollOnce(0);
    }

    public function canAdvance(): bool
    {
        // 没装上（窗口已有会话 / 没有窗口）时不能挡住主循环 —— 那时桥是旁观者。
        return $this->session === null || $this->session->canAdvance();
    }

    public function afterFrame(): void
    {
        if ($this->session !== null && $this->drive !== null) {
            $this->drive->afterFrame($this->session);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $this->host?->closeChannels();
        $this->session?->close();
        $this->session = null;
        $this->host = null;
        $this->drive = null;
        $this->pickArmed = false;
        $this->pickSwallowing = false;
        if ($this->entryId !== null) {
            $this->discovery->unregister($this->entryId);
            $this->entryId = null;
        }
        $this->server->close();
    }

    /**
     * 布防 / 撤防（由 `Host` 的 `PICK` 动词回调，A58）。
     *
     * ## 为什么暂停态直接拒
     *
     * A57 的闸门在派发之前：暂停时 `EventLoop` 连输入都不读。那时回 `armed=true`
     * 等于许诺"点一下就行"，而点击根本到不了这里 —— 客户端会拿着一个永不触发的
     * 布防等到超时。如实回 `paused`，让客户端先 `resume`。
     *
     * @return array{armed: bool, reason?: string}
     */
    public function armPick(bool $enabled): array
    {
        if ($enabled && ($this->session === null || !$this->session->canAdvance())) {
            return [
                'armed' => false,
                'reason' => $this->session === null ? 'no-window' : 'paused',
            ];
        }

        $this->pickArmed = $enabled;
        if (!$enabled) {
            $this->pickSwallowing = false;
        }

        return ['armed' => $this->pickArmed];
    }

    /**
     * 活窗口拾取的输入闸门（A58）。
     *
     * ## 为什么吞整段点击，而不是只吞 down
     *
     * `InputRouter` 的 up 分支排在很多"结算"之前，但按钮的激活并不只在 down 里：
     * 只吞 down、放过 up，会让开发者"选中了一个按钮，同时把它点了"。反过来，
     * 把 down..up 全吞就得处理**在窗口外松开**——收不到 up 时吞态永不释放，窗口
     * 看起来就死了。兜底是 move 的 `button === 0`（键已经不在按着），以及键盘输入
     * （不属于这段点击，橡皮筋式地夹在中间的按键不该被顺手吃掉）。
     */
    public function interceptInput(InputEvent $event): bool
    {
        if ($this->session === null) {
            return false;
        }

        $escape = $event instanceof KeyEvent && $event->chord->is(KeyChord::escape());

        if ($this->pickSwallowing) {
            if ($event instanceof PointerEvent) {
                if ($event->isUp() || $event->isLeave() || ($event->isMove() && $event->button === 0)) {
                    $this->pickSwallowing = false;
                }

                return true;   // 收尾的那条也吞：up 不是"配对完成"，它自己会激活
            }
            $this->pickSwallowing = false;
            if ($escape) {
                return true;
            }

            return false;      // 键盘与点击无关，恢复后照常给应用
        }

        if (!$this->pickArmed) {
            return false;
        }

        // Esc 是取消拾取，不是"Alt+F4 那类应用快捷键"：布防期先吃掉，别让应用看到。
        if ($escape) {
            $this->pickArmed = false;

            return true;
        }
        if (!$event instanceof PointerEvent) {
            return false;
        }

        // 布防期的所有指针输入都吞；只有 down 真正拾取。move 也吞是因为
        // "悬停即高亮"是 A50 失效条件里的独立一件（连续流要节流合并），这里不顺手做。
        if ($event->isDown()) {
            $this->pickAt($event->x, $event->y);
            $this->pickArmed = false;
            $this->pickSwallowing = true;
        }

        return true;
    }

    /** 坐标 → 最深有主节点 → 高亮；没命中就明确清除，不留下一次的高亮骗人。 */
    private function pickAt(float $x, float $y): void
    {
        $answer = $this->session?->query('pick', ['x' => $x, 'y' => $y]);
        if (!\is_array($answer) || ($answer['found'] ?? false) !== true) {
            $this->session?->highlight(0);

            return;
        }

        $chain = $answer['chain'] ?? [];
        // 链是根 → 最深；倒着找最近的有主盒。无主盒没有 Element 身份，描不了边，
        // 但"点在装饰盒上"仍应选中它的宿主 Widget，否则拾取会经常性地没反应。
        $node = 0;
        foreach (\is_array($chain) ? \array_reverse($chain) : [] as $fact) {
            if (!\is_array($fact)) {
                continue;
            }
            $candidate = $fact['node'] ?? null;
            if (\is_int($candidate) && $candidate > 0) {
                $node = $candidate;
                break;
            }
        }
        $this->session?->highlight($node);
    }

    /** 判据用：会话是否在跑；没装上时是 `null`。 */
    public function session(): ?DebugSession
    {
        return $this->session;
    }
}
