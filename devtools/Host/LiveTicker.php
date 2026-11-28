<?php
declare(strict_types=1);

namespace Drift\DevTools\Host;

use Drift\App\Application;
use Drift\App\DebugInputInterceptor;
use Drift\DevTools\App\DebugSession;
use Drift\App\DebugTicker;
use Drift\DevTools\Protocol\Server;
use Drift\DevTools\Session\History;
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

    /**
     * 拾取模式（A58）。**模式保持**，不是一次性布防：进入后每次点击都换选中，
     * 直到 `Ctrl+Shift+I` 再按一次或 Esc。
     */
    private bool $pickArmed = false;

    /** 已吞下一次 down，正等这段点击收尾；期间所有指针输入都不进应用。 */
    private bool $pickSwallowing = false;

    /**
     * `Ctrl+Shift+I` 能不能用。应用自己绑了同一组合时让位 —— 只留 CLI，
     * 不能因为调试器抢了一个应用快捷键。
     */
    private bool $pickChordEnabled = false;

    /**
     * 最近一次拾取的命中链（最深 → 根）与当前层。
     *
     * ★ `↑/↓` 在这条链上走：点中的永远是最深节点，而"这一圈 padding 是谁的"
     *   往往在父级。没有这条链，读者只能再点一次（还是点到同一个最深节点）。
     *
     * @var list<int>
     */
    private array $pickChain = [];

    private int $pickIndex = 0;

    private readonly Discovery $discovery;

    /**
     * 帧出口：把画布拍成 PNG 给 web 看。
     *
     * ★ 只在"有人订阅"时才真的取像素（`Shot` 自己节流 + 每 500 ms 问一次标记文件）。
     *   挂载本身零成本：闭包里第一个判断就是标记，没有订阅者时连画布都不碰。
     */
    private ?Shot $shot = null;

    /** 摘 observer 用：`close()` 之后这条闭包不该再往 `.drift/` 写。 */
    private ?Application $bound = null;

    /** 上次为"还没有第一张图"请求重绘的时刻（节流：不能每轮都强制画）。 */
    private float $lastStarveAt = -1e9;

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

        // 预算在宿主开场时定死，会话中途不改（RecordOptions 不可变）。attach 侧传来的
        // --bodies / --async 到这里已经太晚：录制器是宿主开的，web 只是读它。
        // 于是把可选域的选择放在真正做选择的地方 —— 启动宿主的环境变量。
        //
        // 几何保持默认的 `Changed`：静止帧不重复记整棵树，`geometryBaseline` 负责给
        // `geometryAt()` 兜底。曾经这里注释写 `All` 而代码是默认档，照注释改成 `All`
        // 后每帧全树造记录，几十秒就撞上 128MB —— 那份注释是过时的，不是漏实现。
        $options = RecordOptions::default()->withScript($script);
        if (\getenv('DRIFT_DEBUG_BODIES') === '1') {
            $options = $options->withBodies(true);
        }
        if (\getenv('DRIFT_DEBUG_ASYNC') === '1') {
            $options = $options->withAsync(true);
        }

        $this->session = DebugSession::open($app, $constraints, $options);
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

        // 画面通道：与证据通道并列，互不依赖。截图失败（后端读不回像素）只意味着
        // web 上没底图，几何照旧 —— 不报错、不降级会话。
        $this->shot = new Shot(\dirname($this->discovery->path()), Shot::MIN_GAP_MS, $this->entryId);
        $this->bound = $app;
        // 让位规则只有一条：应用已经声明了这个组合，调试器就不抢。CLI 仍可用。
        $this->pickChordEnabled = $app->scope()->accelerators()->resolve(self::pickChord()) === null;
        $app->setFrameObserver(\Closure::fromCallable([$this, 'onPainted']));

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

        // 画面通道的"第一张图"：树没变时 `Frame::run()` 整段跳过绘制，静止的应用
        // 永远拍不到。这里主动要一帧 —— 但只在**还没拍到过**的时候要，且节流，
        // 否则就是每轮都强制重绘，把空闲 CPU 打回满格。
        if ($this->shot !== null && $this->bound !== null) {
            $nowMs = \hrtime(true) / 1e6;
            if ($this->shot->starving($nowMs) && $nowMs - $this->lastStarveAt > 500.0) {
                $this->lastStarveAt = $nowMs;
                $this->bound->requestPaint();
            }
        }
    }

    public function canAdvance(): bool
    {
        // 没装上（窗口已有会话 / 没有窗口）时不能挡住主循环 —— 那时桥是旁观者。
        return $this->session === null || $this->session->canAdvance();
    }

    public function afterFrame(): void
    {
        if ($this->session === null) {
            return;
        }

        // 帧是 `EventLoop` 推的，所以"推进了一帧"这个事实只在这里知道 ——
        // `Host::pollOnce()` 的空闲分支在 live 宿主上是空转（`LiveDrive::advance()`
        // 故意不做事），它比帧号时什么都不会变。不在这里广播，live 宿主就一条
        // 事件都不会发。
        $this->host?->publishFrame();

        if ($this->drive !== null) {
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
        // 先让屏幕与状态面知道"模式关了"，再拆会话 —— 反过来的话最后一帧会留着 PICK。
        $this->session?->markPicking(false);
        $this->bound?->setDebugPickArmed(false);
        $this->session?->close();
        $this->session = null;
        $this->host = null;
        $this->drive = null;
        $this->bound?->setFrameObserver(null);
        $this->bound = null;
        $this->shot = null;
        $this->pickArmed = false;
        $this->pickSwallowing = false;
        $this->pickChain = [];
        $this->pickIndex = 0;
        if ($this->entryId !== null) {
            $this->discovery->unregister($this->entryId);
            $this->entryId = null;
        }
        $this->server->close();
    }

    /**
     * 帧出口回调（`AppShell::capturePainted()` 调，在调试叠加之前）。
     *
     * ★ 拍的是"应用长什么样"，不是"应用 + 调试标记"：高亮与检查器层都画在这一刻
     *   之后（A81）。反过来说，任何新增的调试装饰都必须画在快照之后，否则底图
     *   会带上它。
     *
     * ★ 时间用 `hrtime` 而不是注入的帧时间戳：拍多密是**观察策略**，不是被测行为。
     *   拿注入时钟去限流，暂停 / 单步时时钟不走，画面反而全拍下来了。
     *
     * @internal 只由帧出口调用
     */
    public function onPainted(\Drift\Rendering\Canvas $canvas, int $frame): void
    {
        // 快照的锚点必须落在几何窗口里：live 几何只在结构变化时落账（Changed），
        // 而主循环在空闲窗口里照常推进。拿 `frame` 配对，静止几十秒后它就会跑到
        // 60 帧窗口之外，`boxes?frame=N` 回 frame-out-of-window，前端退回缺省帧，
        // 于是当前画面套上了另一页的线框。几何窗口最新帧才是这张图能配上的几何。
        $history = $this->session === null ? null : new History($this->session->recorder());
        $coverage = $history?->geometryCoverage();
        // 空几何窗口的 `to` 是 0，不是 null —— `?? $frame` 兜不住它。锚点 0 写进
        // meta 后，`boxes?frame=0` 只会回 frame-out-of-window，前端照样退回缺省帧。
        $anchor = $coverage !== null && $coverage['to'] > 0 ? $coverage['to'] : $frame;
        $this->shot?->capture($canvas, $anchor, \hrtime(true) / 1e6);
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
        // 屏幕与状态面都跟着走：HUD 的 PICK、Web 顶栏的"拾取中"读的是这两处。
        $this->session?->markPicking($this->pickArmed);
        $this->bound?->setDebugPickArmed($this->pickArmed);

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
        $toggle = $this->pickChordEnabled && $event instanceof KeyEvent && $event->chord->is(self::pickChord());

        // 模式开关排在最前：它不属于任何一段点击，点击收尾期间也必须生效。
        if ($toggle) {
            $this->pickSwallowing = false;
            $this->armPick(!$this->pickArmed);

            return true;
        }

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

        // Esc 是退出拾取，不是"Alt+F4 那类应用快捷键"：模式开着就先吃掉。
        if ($escape) {
            $this->armPick(false);

            return true;
        }

        // ↑/↓ 在命中链上换层：↑ 向父级、↓ 向子级。点中的永远是最深节点，
        // 而"这圈 padding 是谁的"通常在父级 —— 没有这条，读者只能再点一次。
        if ($event instanceof KeyEvent
            && ($event->chord->is(KeyChord::up()) || $event->chord->is(KeyChord::down()))) {
            if ($this->pickChain === []) {
                return false;   // 还没点过，方向键留给应用
            }
            $this->movePick($event->chord->is(KeyChord::up()) ? 1 : -1);

            return true;
        }

        if (!$event instanceof PointerEvent) {
            return false;
        }

        // 模式期的所有指针输入都吞；只有 down 真正拾取。move 也吞是因为
        // "悬停即高亮"是 A50 失效条件里的独立一件（连续流要节流合并），这里不顺手做。
        if ($event->isDown()) {
            $this->pickAt($event->x, $event->y);
            // ★ 不撤防：这是 DevTools 的 inspect 模式，点第二下要换第二个控件。
            $this->pickSwallowing = true;
        }

        return true;
    }

    /** 拾取模式的窗口内开关：与 Chrome DevTools 的 inspect 键位一致。 */
    private static function pickChord(): KeyChord
    {
        return KeyChord::make('i', ctrl: true, shift: true);
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
        // 链是根 → 最深；倒着收集全部有主盒的节点。无主盒没有 Element 身份，描不了边，
        // 但"点在装饰盒上"仍应选中它的宿主 Widget，否则拾取会经常性地没反应。
        $nodes = [];
        foreach (\is_array($chain) ? \array_reverse($chain) : [] as $fact) {
            if (!\is_array($fact)) {
                continue;
            }
            $candidate = $fact['node'] ?? null;
            if (\is_int($candidate) && $candidate > 0) {
                $nodes[] = $candidate;
            }
        }
        $nodes = \array_values(\array_unique($nodes));
        $this->pickChain = $nodes;
        $this->pickIndex = 0;
        $this->session?->highlight($nodes[0] ?? 0);
    }

    /** 命中链是"最深 → 根"：↑ 往父级走（+1），↓ 往子级走（-1）。 */
    private function movePick(int $delta): void
    {
        $next = $this->pickIndex + $delta;
        if ($next < 0 || $next >= \count($this->pickChain)) {
            return;
        }

        $this->pickIndex = $next;
        $this->session?->highlight($this->pickChain[$next]);
    }

    /** 判据用：会话是否在跑；没装上时是 `null`。 */
    public function session(): ?DebugSession
    {
        return $this->session;
    }
}
