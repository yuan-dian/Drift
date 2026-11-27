<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\App\Completion;
use Drift\App\NamedJob;
use Drift\App\SyncTaskHost;
use Drift\Debug\Session\AsyncRecord;
use Drift\DevTools\Session\Query;
use Drift\Debug\Session\RecordOptions;
use Drift\DevTools\Session\History;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * A63 判据：异步时间线只记后端真实发生的状态跃迁。
 *
 * 钉三件事：没装录制器时 tap 是空操作；`SyncTaskHost` 的 submit/drain 真的把
 * running→terminal 落进同一份记录；结算同帧的 state 写入能按 task id 归回来。
 * 不测 `Future` 的每一步——那不是这张表要回答的问题。
 */
final class AsyncTimelineTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(200.0, 120.0));
    }

    private function boot(): Application
    {
        $app = new AsyncTimelineApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());

        return $app;
    }

    public function testTraceIsOptInAndReportsNotCaptured(): void
    {
        $recorder = Recorder::install();
        $history = new History($recorder);
        try {
            check('没开预算时提交 tap 立刻返回 0', Recorder::tapAsyncSubmit('sync', 'job', 0) === 0);
            $answer = (new Query($recorder))->async();
            check('没开预算不报空任务表', ($answer['captured'] ?? true) === false);
            check('没开预算的窗口自报 jobs=0', ($answer['window']['jobs'] ?? -1) === 0);

            $miss = (new Query($recorder))->async(1);
            check('未采集时按 id 查说清原因', ($miss['miss'] ?? '') === 'async-not-captured');
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testSyncHostRecordsSubmitStartAndSettle(): void
    {
        $recorder = Recorder::install(RecordOptions::of(async: true));
        $history = new History($recorder);
        try {
            // 后端不替我们推帧：显式开一帧，帧号才是"任务落在哪一帧"的真相。
            $recorder->beginFrame(0.0);
            $host = new SyncTaskHost();
            $host->submit(new class implements NamedJob {
                public function name(): string
                {
                    return 'probe.job';
                }

                public function args(): array
                {
                    return [];
                }

                public function run(): string
                {
                    return 'done';
                }
            });

            $value = null;
            $host->drain(static function (Completion $completion) use (&$value): void {
                $value = $completion->value;
            });
            $recorder->endFrame([], 0.0);

            check('同步任务照常交付', $value === 'done', (string) $value);
            $row = ((new Query($recorder))->async()['rows'][0] ?? []);
            check('记到一条后端工作', \count((new Query($recorder))->async()['rows']) === 1);
            check('后端是 sync', ($row['backend'] ?? '') === 'sync', \json_encode($row));
            check('任务名来自 NamedJob', ($row['job'] ?? '') === 'probe.job');
            check('同一个帧内 running→ok', ($row['startedAtFrame'] ?? 0) > 0
                && ($row['settledAtFrame'] ?? 0) > 0 && ($row['state'] ?? '') === AsyncRecord::OK);
            $initiator = (string) ($row['initiator'] ?? '');
            check('发起点指到业务调用处', \str_contains($initiator, 'AsyncTimelineTest')
                && !\str_contains($initiator, 'vendor/phpunit'), \json_encode($row));
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testSettlementAttributesStateWritesToItsJob(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install(RecordOptions::of(async: true));
        $history = new History($recorder);

        try {
            $app->frame(0.0, $this->constraints());
            $state = AsyncTimelineState::$last;
            check('夹具挂上了', $state !== null);
            $node = $state?->element()?->debugId ?? 0;
            check('夹具拿到节点号', $node > 0);

            $id = Recorder::tapAsyncSubmit('process', 'load.page', 0);
            check('预算开启时 tap 发号', $id > 0);
            Recorder::tapAsyncStart($id, 1);
            Recorder::tapAsyncSettle($id, AsyncRecord::OK, '', 2.0);
            $state?->bump();
            $app->frame(16.0, $this->constraints());

            $row = (new Query($recorder))->async($id)['rows'][0] ?? [];
            check('落地节点回填到任务', \in_array($node, $row['landed'] ?? [], true), \json_encode($row['landed'] ?? []));

            $detail = '';
            foreach ($recorder->frame(2)?->causes ?? [] as $edge) {
                if ($edge->toId === $node) {
                    $detail = $edge->detail;
                    break;
                }
            }
            check('因果边也标了同一个 job', \str_contains($detail, 'job:' . $id), $detail);
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testCancelledAndTimeoutTasksKeepTheirOwnTerminalState(): void
    {
        $recorder = new Recorder(RecordOptions::of(async: true));
        $history = new History($recorder);

        $cancelled = $recorder->noteAsyncSubmit('thread', 'cancel.me', 0);
        $recorder->noteAsyncSettle($cancelled, AsyncRecord::CANCELLED, '', 0.0);
        $timeout = $recorder->noteAsyncSubmit('process', 'slow.job', 0);
        $recorder->noteAsyncSettle($timeout, AsyncRecord::TIMEOUT, 'timeout', 9.0);

        $a = $history->asyncAt($cancelled)?->select(4) ?? [];
        $b = $history->asyncAt($timeout)?->select(4) ?? [];
        check('取消不是 ok', ($a['state'] ?? '') === AsyncRecord::CANCELLED);
        check('超时保留具名码', ($b['state'] ?? '') === AsyncRecord::TIMEOUT
            && ($b['code'] ?? '') === 'timeout');
        check('派生等待帧现算，不存第二份真相', ($a['pendingFrames'] ?? -1) === 4
            && ($b['pendingFrames'] ?? -1) === 4);
    }
}

/** @internal 判据夹具 */
final class AsyncTimelineApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->children([new AsyncTimelineProbe()]);
    }
}

/** @internal 判据夹具 */
final class AsyncTimelineProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new AsyncTimelineState();
    }
}

/** @internal 判据夹具 */
final class AsyncTimelineState extends State
{
    public static ?self $last = null;

    public int $count = 0;

    public function initState(): void
    {
        self::$last = $this;
    }

    public function bump(): void
    {
        $this->setState(function (): void {
            ++$this->count;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Text::make('async=' . $this->count);
    }
}
