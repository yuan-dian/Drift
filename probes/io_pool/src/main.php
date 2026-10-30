<?php
declare(strict_types=1);

namespace Probe {

use Drift\App\Job;
use Drift\App\NamedJob;
use Drift\App\ThreadTaskHost;
use Drift\Platform\WakeReason;
use Drift\Platform\Win32\Win32Platform;

use function drift_io_handled;
use function drift_io_poll;
use function drift_io_start;
use function drift_io_stop;
use function drift_io_submit;
use function drift_io_threads;
use function drift_win_now_ms;

// ── 探针：C++ IO 线程池 + 唤醒通道的真机判据（A2 / N5）──
//
// 判据（每条都要**数字**，不接受"看起来可以"）：
//   J1 唤醒延迟：1000 轮，每轮 submit(sleep 1ms) → waitEvents(-1)。要求
//      · 1000/1000 返回 WakeReason::Woken（不是 Timeout / Quit / Message）
//      · 1000/1000 的 poll() **非空** —— 被叫醒却没有结果 = 不变量 ① 被违反
//      · 单轮最大耗时 < 5ms
//   J2 0 丢失：handled() == 1000 且 poll() 累计行数 == 1000
//   J3 关池：shutdown 之后 threads() == 0
//   J4 ThreadTaskHost 端到端：sleep 任务 resolve(20)、echo 任务 resolve('hi')
//   J5 空闲 CPU：池里 4 个线程空转？挂一条 1.2s 的活，泵**只被叫醒一次**，
//      期间进程 CPU 由外部驱动器采样（探针内部拿不到 CPU 时间）
//   J6 空队列 poll 单次 < 10µs（"非阻塞"要给数字，不能只说"没卡住"）
//
// ★★ 为什么必须**外部驱动器**（`tools/probe-io-pool.py`）：
//   N5 最可能的失败形态不是"算错"，是**挂死** —— 唤醒没接上时 `waitEvents(-1)`
//   会永远阻塞。进程内的看门狗需要第二个线程，而"线程能不能用"正是被测对象
//   ⇒ 唯一诚实的做法是进程外超时。同理，CPU 时间也只能外部读
//   （`GetProcessTimes`），框架没有这个 ABI，也不该为一条判据加一个。
//
// ★ 输出落 `out.log`（每行一次 write），**盘上那份是权威**：若某一步掀掉进程，
//   前面的证据必须已经在盘上（`probes/taskhost_thread` 的教训）。

/** 唤醒延迟轮数 */
const ROUNDS = 1000;

/** 单轮唤醒预算（ms）—— sleep 1ms + PostMessage + 泵往返，实测远低于此 */
const WAKE_BUDGET_MS = 5.0;

/** 空闲窗口里那条活的时长（ms）—— 外部驱动器在这段里采 CPU */
const IDLE_JOB_MS = 1200;

/** J6 空队列 poll 的调用次数（一圈量完再平均，把时钟开销摊薄到可忽略） */
const EMPTY_POLL_CALLS = 20000;

/** J6 单次空队列 poll 的预算（微秒）—— 设计册 §9.5 第一条 */
const EMPTY_POLL_BUDGET_US = 10.0;

/**
 * 写一行到 `out.log` 并同步刷到 stdout。
 *
 * ★ `FILE_APPEND` + 每行一次 write：进程被掀掉时，已写的行一定在盘上。
 */
function log_line(string $line): void
{
    $line = $line . "\n";
    file_put_contents('out.log', $line, FILE_APPEND);
    echo $line;
}

/**
 * `WakeReason` → 短名。
 *
 * ★ 不用 `$why->name`：枚举的 `->name` 在 AOT 下未验过（`case` 存在与否都编译期
 *   就定了），而这里只要一个日志串 ⇒ 用 `===` 比出名字，零风险。
 */
function reason_name(WakeReason $why): string
{
    if ($why === WakeReason::Quit) {
        return 'quit';
    }
    if ($why === WakeReason::Timeout) {
        return 'timeout';
    }
    if ($why === WakeReason::Message) {
        return 'message';
    }
    if ($why === WakeReason::Woken) {
        return 'woken';
    }

    return 'unknown';
}

/** J1/J4 的夹具：一条 `sleep` 原生任务（`core/io_pool.cc` 的 `sleep` 分支） */
final class SleepJob implements NamedJob
{
    public function __construct(private readonly int $ms)
    {
    }

    /** ★ `name()` 就是 **C++ 侧的处理函数名**（这个后端不跑 PHP 代码） */
    public function name(): string
    {
        return 'sleep';
    }

    public function args(): array
    {
        return ['ms' => $this->ms];
    }

    /** ★ 永远不会被调用 —— `ThreadTaskHost` 只把名字 + 参数投给 C++ 线程 */
    public function run(): mixed
    {
        return $this->ms;
    }
}

/** J4 的夹具：`echo` 原生任务（回显 `text`，验字符串过 ABI 的往返） */
final class EchoJob implements NamedJob
{
    public function __construct(private readonly string $text)
    {
    }

    public function name(): string
    {
        return 'echo';
    }

    public function args(): array
    {
        return ['text' => $this->text];
    }

    public function run(): mixed
    {
        return $this->text;
    }
}

function run(): void
{
    file_put_contents('out.log', '');

    log_line('=== io_pool probe (A2 / N5) ===');
    log_line('rounds=' . ROUNDS);
    log_line('budget_ms=' . WAKE_BUDGET_MS);

    $platform = new Win32Platform();

    // ── J1 唤醒延迟 ──────────────────────────────────────────────────────
    $threads = drift_io_start(4);
    log_line('pool_threads=' . $threads);

    if ($threads < 1) {
        log_line('VERDICT=FAIL reason=pool_not_started');
        return;
    }

    $maxDt = 0.0;
    $notWoken = 0;
    $emptyPoll = 0;
    $rows = 0;

    for ($i = 1; $i <= ROUNDS; $i++) {
        $t0 = drift_win_now_ms();

        // ★★ 负载用 `echo`（**零耗时**），不是 `sleep 1`：Windows 的 `Sleep(1)` 实际
        //   睡 **~15.6ms**（系统定时器粒度，除非 `timeBeginPeriod` 抬全进程定时器）。
        //   拿它当负载量出来的是"定时器粒度"而不是"唤醒通道"—— 第一版实测 32ms，
        //   两条判据都白测。这里要量的是**通道**：submit → worker → PostMessage →
        //   GetMessage 返回。
        drift_io_submit($i, 'echo', 0, 'x');

        // ★ 传 -1：**无限等**。这条判据的全部意义就是"没有超时兜底也能被叫醒"。
        $why = $platform->waitEvents(-1.0);
        $dt = drift_win_now_ms() - $t0;

        if ($dt > $maxDt) {
            $maxDt = $dt;
        }
        if ($why !== WakeReason::Woken) {
            $notWoken++;
        }

        $raw = drift_io_poll();
        if ($raw === '[]') {
            $emptyPoll++;
        } else {
            $decoded = \json_decode($raw, true);
            $rows += \is_array($decoded) ? \count($decoded) : 0;
        }
    }

    log_line('wake.max_dt_ms=' . \round($maxDt, 2));
    log_line('wake.not_woken=' . $notWoken);
    log_line('wake.empty_poll=' . $emptyPoll);

    // ── J2 0 丢失 ────────────────────────────────────────────────────────
    // ★ 必须在下面那条"附记"之前读：附记会再投一条任务，`handled()` 是**累计**的，
    //   晚读一行就是 1001（第一版就错在这里）。
    log_line('wake.rows=' . $rows);
    log_line('handled=' . drift_io_handled());

    // ★ 附记（**不判**，只是把数字留在盘上）：`sleep 1` 的实测延迟。
    //   ~15.6ms 是 Windows 定时器粒度，与唤醒通道无关 —— 下次有人觉得"唤醒慢"，
    //   先看这一行，别去改唤醒通道。
    $sleepT0 = drift_win_now_ms();
    drift_io_submit(999001, 'sleep', 1, '');
    $platform->waitEvents(-1.0);
    drift_io_poll();
    log_line('sleep1_dt_ms=' . \round(drift_win_now_ms() - $sleepT0, 2));

    // ── J4 ThreadTaskHost 端到端 ─────────────────────────────────────────
    // ★ 池已在跑 ⇒ `drift_io_start()` 幂等（只回报线程数）。
    $host = new ThreadTaskHost(2);
    log_line('host.needs_polling=' . ($host->needsPolling() ? '1' : '0'));

    $got = [];
    $errors = [];

    $host->submit(new SleepJob(20))
        ->then(static function (mixed $v) use (&$got): void {
            $got['sleep'] = $v;
        })
        ->catch(static function (\Throwable $e) use (&$errors): void {
            $errors['sleep'] = $e->getMessage();
        });

    $host->submit(new EchoJob('hi'))
        ->then(static function (mixed $v) use (&$got): void {
            $got['echo'] = $v;
        })
        ->catch(static function (\Throwable $e) use (&$errors): void {
            $errors['echo'] = $e->getMessage();
        });

    $deadline = drift_win_now_ms() + 5000.0;
    $wakes = 0;

    while ($host->pending() > 0 && drift_win_now_ms() < $deadline) {
        $why = $platform->waitEvents(-1.0);
        if ($why === WakeReason::Woken) {
            $wakes++;
        }

        // ★ 这里替 `PumpHost::complete()` 站岗（生产路径上由它结算）——
        //   探针没有 `Application`，四行就是那份契约的全部内容。
        $host->drain(static function ($completion): void {
            $error = $completion->error;
            if ($error !== null) {
                $completion->future->reject($error);

                return;
            }
            $completion->future->resolve($completion->value);
        });
    }

    log_line('e2e.sleep=' . (isset($got['sleep']) ? (string) $got['sleep'] : 'MISSING'));
    log_line('e2e.echo=' . (isset($got['echo']) ? (string) $got['echo'] : 'MISSING'));
    log_line('e2e.errors=' . (\count($errors) === 0 ? 'none' : \implode('|', $errors)));
    log_line('e2e.wakes=' . $wakes);

    // ── J5 空闲窗口（外部驱动器在这里采 CPU）────────────────────────────
    // ★ 一条 1.2s 的活：泵被**叫醒一次**就够。旧设计（15ms 帧节奏）在这段里会醒
    //   80 次 —— 那就是"N5 到底省了什么"的可测形态。
    log_line('idle-begin');
    $idleT0 = drift_win_now_ms();
    drift_io_submit(900001, 'sleep', IDLE_JOB_MS, '');
    $idleWhy = $platform->waitEvents(-1.0);
    $idleDt = drift_win_now_ms() - $idleT0;
    drift_io_poll();   // 排空那条
    log_line('idle-end dt_ms=' . \round($idleDt, 1) . ' why=' . reason_name($idleWhy));

    // ── J6 空队列单次耗时（§9.5 第一条）─────────────────────────────────
    // ★ "非阻塞"是个**性能**判据，必须给数字：只验"没卡住"的话，一次 poll 里偷偷
    //   加了 5ms 的等待也照样"通过"（本轮的教训就是量具比断言更容易错）。
    // ★ 量法：一圈 N 次空 poll，**两端各读一次** QPC ⇒ 单次 = 总耗时 / N。
    //   把 `now_ms()` 自己的开销摊到 1/20000，可忽略；反过来若每次都读钟，
    //   量到的就是时钟的开销而不是 poll 的。
    $emptyPre = drift_io_poll() === '[]' ? 'empty' : 'NOT_EMPTY';
    $emptyT0 = drift_win_now_ms();
    for ($k = 0; $k < EMPTY_POLL_CALLS; $k++) {
        drift_io_poll();
    }
    $emptyUs = (drift_win_now_ms() - $emptyT0) * 1000.0 / (float) EMPTY_POLL_CALLS;
    log_line('empty_poll.pre=' . $emptyPre);
    // ★ `calls` 单独一行：`tools/probe-io-pool.py` 的解析是"按 `=` 切两半"，
    //   同一行放两个键值会让第二个键变成值的一部分（本轮实测踩到）。
    log_line('empty_poll.calls=' . EMPTY_POLL_CALLS);
    log_line('empty_poll_us=' . \round($emptyUs, 3));

    // ── J3 关池 ─────────────────────────────────────────────────────────
    $host->shutdown();
    log_line('threads_after_stop=' . drift_io_threads());

    // ── 判 ──────────────────────────────────────────────────────────────
    $pass = true;

    if ($notWoken !== 0) {
        $pass = false;
    }
    if ($emptyPoll !== 0) {
        $pass = false;
    }
    if ($maxDt >= WAKE_BUDGET_MS) {
        $pass = false;
    }
    if ($rows !== ROUNDS || drift_io_handled() < ROUNDS) {
        $pass = false;
    }
    if (!isset($got['sleep']) || $got['sleep'] !== 20) {
        $pass = false;
    }
    if (!isset($got['echo']) || $got['echo'] !== 'hi') {
        $pass = false;
    }
    if ($idleWhy !== WakeReason::Woken || $idleDt < (float) IDLE_JOB_MS) {
        $pass = false;
    }
    // ★ 前置条件也进判：队列非空时量到的是"解码一条结果"的耗时，不是空队列的
    //   —— 那种情况下 10µs 必然假红，而原因不在 poll 实现里。
    if ($emptyPre !== 'empty' || $emptyUs >= EMPTY_POLL_BUDGET_US) {
        $pass = false;
    }
    if (drift_io_threads() !== 0) {
        $pass = false;
    }

    log_line('VERDICT=' . ($pass ? 'PASS' : 'FAIL'));

    if (!$pass) {
        // ★ 退出码跟着判据走：只看 exit code 的人也不该看到"通过"。
        exit(1);
    }
}

}  // namespace Probe

namespace {
    function main(): void
    {
        \Probe\run();
    }
}
