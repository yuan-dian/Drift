<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;

/**
 * 帧的阶段声明（§5.2 / `06` §2）。
 *
 * ## 它守的是什么
 *
 * 九段的顺序里只有"跨过 settle"是硬约束（A 组全在 settle 前、C 组全在其后），
 * 而这句话**只写在注释里**。声明成一张表之后，dev 期可以断言"谁越界了"。
 *
 * 越界的后果都不是"报错"，而是**慢一帧 / 读屏停住 / 焦点握着死节点**：
 * - `pumpTasks` 跑到 settle 之后 ⇒ 那次 `setState` 等下一帧 ⇒ "loading → ready"
 *   闪一帧空（用户看得见）
 * - `focusCollect` 跑到 settle 之前 ⇒ 焦点链可能握着已被移除的 Element
 *   ⇒ 按键静默丢给死节点
 * - `pumpSemantics` 没跑 ⇒ 读屏停在上一帧
 *
 * ## 它**不是**调度器（`06` §2.3）
 *
 * 把九段改成"注册进数组按优先级执行"的代价：顺序从**代码可见**变成**运行时数据**、
 * 每次多一次间接调用、调度器本身要测 —— 而它解决的问题是"顺序可能被写错"，
 * 那是**一次写对就不用管**的问题。
 *
 * ★ 判据（进 `07`）：**能靠"一次写对 + dev 断言"守住的，不要造运行时机制。**
 *   帧阶段顺序是正确性约束，不是偏好 ⇒ 不该可配置。
 *
 * ## 开关与开销
 *
 * `build/frame.trace` 文件存在 ⇒ 开（同 `apps/bench` 的 `build/m4.on`、`04` §5.4 的
 * `build/layout.check` 那一套**文件开关**：不用 `__DIR__`（编译期常量，AOT 下指向
 * 构建机 ⇒ 打包后静默不生效），不用 `getenv`（`nano-hygiene` 禁））。
 *
 * 关的时候开销 = `begin()` 返回 null + 每段一次 `if ($trace !== null)` ⇒ 可忽略。
 *
 * ## 违规怎么被看见（★ 真机判据靠这个）
 *
 * `Errors::report(DIAGNOSTIC)` 只进**进程内**环形缓冲，而真机上 `users.exe` 是
 * 独立进程 ⇒ 外面一条都读不到 ⇒ “0 条违规”只能选择相信，**那正是 G2「绝不静默」
 * 的反面：断言输出了，但没有人看得见**。⇒ 开着的每一帧都把计数器刷进
 * `build/frame.trace.out`（整份重写，与开关同目录同前缀）。
 *
 * @api dev 量具入口（`06` §11.2 #1/#2 的夹具直接驱 `assertOrder()`）
 */
final class FramePhase
{
    /** 必须在 settle 之前（会产脏；组内顺序任意 —— 四个泵写的是不同的 State） */
    public const BEFORE_SETTLE = ['pumpIo', 'pumpTasks', 'pumpSliced', 'tickAnimations'];

    /** 必须在 settle 之后（观察新树；组内顺序任意，但 ⑧ 要在 ⑨ 前 —— 语义树要带焦点） */
    public const AFTER_SETTLE = ['tickLateMount', 'focusCollect', 'pumpSemantics'];

    /**
     * 组 B 的固定段（顺序由直写代码保证，这里只要求"出现过"）。
     *
     * ★ `settle` 是**标记**不是真段：它记在 `$frame->run()` 之前，
     *   而 `Frame::run()` 内部才是 settle → layout → paint → present。
     */
    public const CORE = ['placeOverlays', 'settle'];

    /** 全部要出现的名字（`06` §2.1 的三组九段 + settle 标记） */
    public const ALL = ['pumpIo', 'pumpTasks', 'pumpSliced', 'tickAnimations', 'placeOverlays', 'settle', 'tickLateMount', 'focusCollect', 'pumpSemantics'];

    /**
     * 每个名字越界/缺失的后果 —— 断言消息要能直接回答"所以呢"。
     *
     * @var array<string, string>
     */
    private const CONSEQUENCE = [
        'pumpIo' => '外部数据晚一帧到，回调里的 setState 要等下一帧',
        'pumpTasks' => '后台结果晚一帧生效 —— "loading → ready" 会闪一帧空',
        'pumpSliced' => '分片完成晚一帧，长任务收尾可见地迟一拍',
        'tickAnimations' => '动画晚一帧起手，第一帧不动',
        'placeOverlays' => '浮层布局晚一帧，菜单/模态会抖一下',
        'settle' => '帧末重建没跑 —— 这一帧的 setState 全部作废',
        'tickLateMount' => '迟到挂载的 State 不被 tick ⇒ 帧时钟不上膛 ⇒ 之后没事件就永远静止',
        'focusCollect' => '焦点链可能握着已被移除的 Element ⇒ 按键静默丢给死节点',
        'pumpSemantics' => '读屏停在上一帧',
    ];

    /** 打开后每帧记 trace，帧末断言 */
    public static bool $on = false;

    /**
     * 报告文件（与开关同目录同前缀，`getcwd()` 相对）。
     *
     * ★ 命名跟着 `apps/bench` 的 `build/bench-out.txt` / `build/bench-m4.txt`
     *   那一套 `-out` 后缀惯例。
     */
    private const REPORT = 'build/frame.trace.out';

    /** 已断言帧数 */
    private static int $frames = 0;

    /** 违规次数（**这个数必须恒 0**；真机判据直接读报告文件里的这一行） */
    public static int $violations = 0;

    /** @var list<string> 最近几条违规的一行摘要（真机报告文件里同格式） */
    public static array $recent = [];

    /** 文件开关只读一次 */
    private static bool $booted = false;

    /**
     * 帧首调一次：开 ⇒ 返回空数组（往里记名字），关 ⇒ 返回 null。
     *
     * @return list<string>|null
     */
    public static function begin(): ?array
    {
        self::boot();

        return self::$on ? [] : null;
    }

    /**
     * 帧末调一次：把 trace 与名单比对。
     *
     * @param list<string> $trace 本帧实际执行过的段名（含 `settle` 标记）
     */
    public static function end(array $trace): void
    {
        self::$frames++;
        self::assertOrder($trace);
        self::writeReport();
    }

    /** 清零（测试用） */
    public static function reset(): void
    {
        self::$on = false;
        self::$booted = false;
        self::$frames = 0;
        self::$violations = 0;
        self::$recent = [];
    }

    /**
     * 三项检查（`06` §2.4）。
     *
     * @param list<string> $trace
     */
    public static function assertOrder(array $trace): void
    {
        $settleIndex = array_search('settle', $trace, true);
        if ($settleIndex === false) {
            self::flag(
                '帧阶段：本帧没有 settle 标记 —— Frame::run() 没被调用，'
                . '整帧的 setState 都没被消费。' . "\n"
                . '修法：确认 frame() 里 $frame->run(...) 那段没被提前 return 掉。',
                '缺 settle 标记'
            );

            return;
        }

        $index = [];
        foreach ($trace as $i => $name) {
            $index[$name] = $i;
        }

        foreach (self::BEFORE_SETTLE as $name) {
            if (!isset($index[$name])) {
                continue; // 缺失由下面的"必须出现"检查报
            }
            if ($index[$name] > $settleIndex) {
                self::flag(\sprintf(
                    "帧阶段：%s 在 settle 之后跑了 —— %s。\n"
                    . '修法：把它挪到 $frame->run(...) 之前（组 A 只做"调回调"，回调里只 setState/markDirty）。',
                    $name,
                    self::CONSEQUENCE[$name] ?? '会慢一帧',
                ), $name . ' 在 settle 之后');
            }
        }

        foreach (self::AFTER_SETTLE as $name) {
            if (!isset($index[$name])) {
                continue;
            }
            if ($index[$name] < $settleIndex) {
                self::flag(\sprintf(
                    "帧阶段：%s 在 settle 之前跑了 —— %s。\n"
                    . '修法：把它挪到 $frame->run(...) 之后（组 C 观察的是**新**树）。',
                    $name,
                    self::CONSEQUENCE[$name] ?? '会读到旧树',
                ), $name . ' 在 settle 之前');
            }
        }

        foreach (self::ALL as $name) {
            if (!isset($index[$name])) {
                self::flag(\sprintf(
                    "帧阶段：本帧没跑 %s —— %s。\n"
                    . '修法：确认 frame() 里那段没被条件跳过；若确实要跳过，把名字从 FramePhase::ALL 里删掉并写理由。',
                    $name,
                    self::CONSEQUENCE[$name] ?? '这一帧少做一件事',
                ), '缺 ' . $name);
            }
        }
    }

    /**
     * 记一条违规：计数 + 一行摘要 + 一条 Diagnostic。
     *
     * ★ 摘要与 Diagnostic 是**两个出口**，缺一不可：
     *   · Diagnostic 进进程内环形缓冲（`Errors::recentDiagnostics()`，测试读）
     *   · 摘要进报告文件（真机可读）
     *   只有前者 ⇒ 真机上"看得见"等于不存在（见类 docblock）。
     */
    private static function flag(string $message, string $short): void
    {
        self::$violations++;
        self::$recent[] = \sprintf('帧 %d：%s', self::$frames, $short);
        if (count(self::$recent) > 8) {
            self::$recent = array_slice(self::$recent, -8);
        }
        Errors::report($message, Errors::DIAGNOSTIC);
    }

    /**
     * 把计数器刷进报告文件。
     *
     * ★ 整份重写而不是追加：报告要表达"**当前**状态"，追加会无限增长；
     *   而且进程被杀时留下的最后一份，恰好就是当时的真实状态。
     * ★ 为什么不会因目录缺失而静默失败：开关与报告同在 `build/` 下，
     *   开关能被 `is_file()` 找到 ⇒ `build/` 一定存在。
     * ★ `getcwd()` 相对，不用 `__DIR__`（编译期常量 ⇒ 打包后静默不生效，`10` §2 A8）。
     */
    private static function writeReport(): void
    {
        $lines = [
            'switch     build/frame.trace',
            'frames     ' . self::$frames,
            'violations ' . self::$violations,
        ];
        foreach (self::$recent as $line) {
            $lines[] = '  · ' . $line;
        }
        \file_put_contents(self::REPORT, implode("\n", $lines) . "\n");
    }

    /**
     * 文件开关：只读一次，且**只会把 `$on` 从 false 翻成 true**。
     *
     * ★ 同 `LayoutSelfCheck::boot()`：夹具（`06` §11.2 #1）要手动设 `$on = true`
     *   驱反例，直接赋值会把夹具设的值冲掉。
     */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$on = self::$on || \is_file('build/frame.trace');
        if (self::$on) {
            // ★ 立刻落一份 `frames 0`：否则"没有报告文件"与"跑过 300 帧且干净"
            //   两种完全不同的情况在外面看起来一模一样 —— 那正是这个文件要消除的歧义。
            self::writeReport();
        }
    }
}
