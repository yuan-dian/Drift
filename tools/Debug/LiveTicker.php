<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\App\Application;
use Drift\App\DebugSession;
use Drift\App\DebugTicker;
use Drift\Debug\Protocol\Server;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Script;
use Drift\Geometry\BoxConstraints;

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
final class LiveTicker implements DebugTicker
{
    private ?DebugSession $session = null;

    private ?Host $host = null;

    private ?LiveDrive $drive = null;

    private ?string $entryId = null;

    private bool $closed = false;

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
        $this->host = new Host($this->server, $this->token, $this->drive);
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
        if ($this->entryId !== null) {
            $this->discovery->unregister($this->entryId);
            $this->entryId = null;
        }
        $this->server->close();
    }

    /** 判据用：会话是否在跑；没装上时是 `null`。 */
    public function session(): ?DebugSession
    {
        return $this->session;
    }
}
