<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Replay;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * S4 判据：同一节点、同一显式帧，三域回显自己**实际**命中的帧号。
 *
 * ## 这批判据防的是"看起来对齐"的假答案
 *
 * 1. 三域的缺省帧语义不同（因果取最近重建帧、几何取最后一帧、网络没有缺省）。
 *    若让各域自己缺省再拼，会报出"三域都在 #N"却各取各的。
 * 2. 几何按变化存储，目标帧没采样就往前找最近一条。若直接拿结果拼，
 *    `frame=2` 的请求会得到 `frame=1` 的几何却被标成"2"。
 * 3. `geometryAt` 会把窗口外的帧号 clamp 到窗口末端再往前找。若不先判窗口，
 *    `frame=99`（窗口只到 2）会拿到 `frame=1` 的旧记录并报 found=true。
 *
 * 三者都不报错，只是答案悄悄变成假的——所以判据必须钉**实际帧号与 stale**，
 * 而不只是"有没有值"。
 */
final class AlignTest extends SmokeTestCase
{
    /** @return array{0: mixed, 1: int} session + 有盒的节点 id */
    private function session(): array
    {
        $meta = ['page' => 1];
        $drive = Plan::build('table', 800.0, 600.0, $meta, 1, 1, [['click', '400,300']]);
        $opened = Runner::open('table', 800.0, 600.0, $meta, $drive->fork());
        $session = $opened['session'];
        Replay::apply($session, $drive, [], null, static function (int $id, float $ms): void {
        });

        $rows = $session->query('boxes', ['frame' => 2])['rows'];
        $node = 0;
        foreach ($rows as $row) {
            if ((int) $row['parent'] > 0) {
                $node = (int) $row['node'];
                break;
            }
        }
        check('夹具里找得到有父链的盒节点', $node > 0, 'node=' . $node);

        return [$session, $node];
    }

    public function testAlignUsesTheGivenFrameForAllDomains(): void
    {
        [$session, $node] = $this->session();
        try {
            $a = $session->query('align', ['node' => $node, 'frame' => 2]);
            check('顶层回显请求的帧与节点', ($a['frame'] ?? 0) === 2 && ($a['node'] ?? 0) === $node,
                \json_encode(['frame' => $a['frame'] ?? null, 'node' => $a['node'] ?? null]));
            check('因果域用显式帧，不走最近重建帧缺省', ($a['causal']['frame'] ?? 0) === 2,
                \json_encode($a['causal']));
            check('三域都有统一 found 形状', \array_key_exists('found', $a)
                && \array_key_exists('found', $a['causal'])
                && \array_key_exists('found', $a['geometry'])
                && \array_key_exists('found', $a['network']), \json_encode($a));
            $complete = ($a['causal']['found'] ?? false) && ($a['geometry']['found'] ?? false)
                && ($a['network']['found'] ?? false);
            check('found=地址存在、complete=三域齐全，不混为一谈', ($a['found'] ?? false) === true
                && ($a['complete'] ?? null) === $complete, \json_encode($a));
            check('逐域窗口自报（帧 / 几何 / 网络）', isset($a['windows']['frames'])
                && isset($a['windows']['geometry']['capacity'])
                && isset($a['windows']['network']['capacity']), \json_encode($a['windows']));
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }

    public function testGeometryReportsItsRealFrameAndStale(): void
    {
        [$session, $node] = $this->session();
        try {
            $a = $session->query('align', ['node' => $node, 'frame' => 2]);
            $geo = $a['geometry'];
            check('几何命中时回显真实帧号（可能是更早的采样）', ($geo['found'] ?? false) === true
                && \is_int($geo['frame'] ?? null) && ($geo['frame'] ?? 0) <= 2, \json_encode($geo));
            // ★ 与独立的 boxModel 交叉核对真实帧号，而不是拿 stale 跟自己比 ——
            //   若实现同时把 frame 改成目标帧、stale 改成 false，自比仍会假绿。
            $truth = $session->query('boxModel', ['node' => $node, 'frame' => 2]);
            check('几何帧号与独立查询逐值一致', ($geo['frame'] ?? null) === ($truth['frame'] ?? null),
                'align=' . \json_encode($geo) . ' boxModel=' . \json_encode($truth));
            check('stale 由真实帧号判定，不伪称目标帧', ($geo['stale'] ?? null) === (($truth['frame'] ?? null) !== 2),
                \json_encode($geo));

            // 第 1 帧是挂载帧：几何一定有当帧采样。
            $first = $session->query('align', ['node' => $node, 'frame' => 1]);
            check('当帧采样不标 stale', ($first['geometry']['frame'] ?? 0) === 1
                && ($first['geometry']['stale'] ?? true) === false, \json_encode($first['geometry']));
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }

    public function testOutOfWindowFrameIsMissInAllDomains(): void
    {
        [$session, $node] = $this->session();
        try {
            $a = $session->query('align', ['node' => $node, 'frame' => 99]);
            check('窗口外的帧整体不成立', ($a['found'] ?? true) === false, \json_encode($a));
            check('因果域报窗口外且有 found（形状归一）', ($a['causal']['found'] ?? true) === false
                && ($a['causal']['reason'] ?? '') === 'frame-out-of-window', \json_encode($a['causal']));
            check('几何域不被"往前找最近"骗成命中', ($a['geometry']['found'] ?? true) === false
                && ($a['geometry']['reason'] ?? '') === 'frame-out-of-window', \json_encode($a['geometry']));
            check('网络域 miss 也有统一 found', ($a['network']['found'] ?? true) === false
                && \is_string($a['network']['reason'] ?? null), \json_encode($a['network']));
            // 不变式：几何只在帧存在时采，所以因果窗口外 ⇒ 几何也必然窗口外。
            // 钉住它，才敢说"顶层 found 只看因果"在本模型下是等价实现而非漏洞。
            check('因果 miss 必然伴随几何 miss（帧窗口包含几何窗口）', ($a['geometry']['found'] ?? true) === false,
                \json_encode($a['geometry']));
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }

    public function testUnknownNodeSaysSoInAllDomains(): void
    {
        [$session] = $this->session();
        try {
            $a = $session->query('align', ['node' => 99999, 'frame' => 2]);
            check('未知节点整体不成立', ($a['found'] ?? true) === false
                && ($a['reason'] ?? '') === 'unknown-node', \json_encode($a));
            foreach (['causal', 'geometry', 'network'] as $domain) {
                check("{$domain} 都报 unknown-node", ($a[$domain]['reason'] ?? '') === 'unknown-node',
                    \json_encode($a[$domain]));
            }
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }

    public function testNoLandedRequestIsNotSilentEmpty(): void
    {
        [$session, $node] = $this->session();
        try {
            $a = $session->query('align', ['node' => $node, 'frame' => 2]);
            check('没有归因时明说 no-landed-request，不冒充"没有网络"', ($a['network']['found'] ?? true) === false
                && ($a['network']['reason'] ?? '') === 'no-landed-request'
                && isset($a['network']['window']['capacity']), \json_encode($a['network']));
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }

    public function testNetworkAttributionCanBeFoundFromItsLandedNode(): void
    {
        $constraints = \Drift\Geometry\BoxConstraints::loose(new \Drift\Geometry\Size(200.0, 120.0));
        $app = new CounterApplication();
        $app->attach(new \Drift\Dev\Smoke\Frame\SurfaceProbe(), $constraints);
        $app->frame(0.0, $constraints);

        // 会话自己装录制器；再开一个会拒绝覆盖，故从会话取同一实例。
        $session = \Drift\App\DebugSession::open($app, $constraints);
        try {
            $recorder = $session->recorder();
            $id = Recorder::tapNetworkStart('GET', 'https://api.example.com/rows.php', 'inline');
            $recorder->beginFrame(16.0);
            Recorder::tapNetworkSettle($id, 200, 10, 5.0, true);
            CounterState::$last?->increment(); // 结算同帧写状态 ⇒ 这条边是请求的落地
            $app->frame(16.0, $constraints);
            $recorder->endFrame([], 0.0);

            $node = CounterState::$last?->element()?->debugId ?? 0;
            $landed = $recorder->requestsLandingOn($node);
            check('节点能反查到归因请求', $landed === [$id], \json_encode($landed));

            // 帧号以 recorder 实际为准：手工 beginFrame + app->frame 会各占一帧。
            $settledFrame = $recorder->networkAt($id)?->settledAtFrame ?? 0;
            $a = $session->query('align', ['node' => $node, 'frame' => $settledFrame]);
            check('对齐查询里网络域命中同一条请求', ($a['network']['found'] ?? false) === true
                && ($a['network']['requests'][0]['request'] ?? 0) === $id, \json_encode($a['network']));
            check('对齐查询的帧号与请求结算帧一致', ($a['network']['frame'] ?? 0) === $settledFrame,
                \json_encode($a['network']));

            // 同一节点再落一条请求：反查必须给全部，不能只给第一条。
            $id2 = Recorder::tapNetworkStart('POST', 'https://api.example.com/save', 'inline');
            $recorder->beginFrame(32.0);
            Recorder::tapNetworkSettle($id2, 201, 3, 2.0, true);
            CounterState::$last?->increment();
            $app->frame(32.0, $constraints);
            $recorder->endFrame([], 0.0);
            $all = $recorder->requestsLandingOn($node);
            check('多条落地请求都反查得到（不只读第一条）', \count($all) === 2
                && \in_array($id, $all, true) && \in_array($id2, $all, true), \json_encode($all));
        } finally {
            $session->close();
        }
        $this->flushChecks();
    }
}
