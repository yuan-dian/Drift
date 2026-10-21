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
    public const CORE = ['placeMenu', 'settle'];

    /** 全部要出现的名字（`06` §2.1 的三组九段 + settle 标记） */
    public const ALL = ['pumpIo', 'pumpTasks', 'pumpSliced', 'tickAnimations', 'placeMenu', 'settle', 'tickLateMount', 'focusCollect', 'pumpSemantics'];

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
        'placeMenu' => '浮层布局晚一帧，菜单/模态会抖一下',
        'settle' => '帧末重建没跑 —— 这一帧的 setState 全部作废',
        'tickLateMount' => '迟到挂载的 State 不被 tick ⇒ 帧时钟不上膛 ⇒ 之后没事件就永远静止',
        'focusCollect' => '焦点链可能握着已被移除的 Element ⇒ 按键静默丢给死节点',
        'pumpSemantics' => '读屏停在上一帧',
    ];

    /** 打开后每帧记 trace，帧末断言 */
    public static bool $on = false;

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
        self::assertOrder($trace);
    }

    /** 清零（测试用） */
    public static function reset(): void
    {
        self::$on = false;
        self::$booted = false;
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
            Errors::report(
                '帧阶段：本帧没有 settle 标记 —— Frame::run() 没被调用，'
                . '整帧的 setState 都没被消费。' . "\n"
                . '修法：确认 frame() 里 $frame->run(...) 那段没被提前 return 掉。',
                Errors::DIAGNOSTIC
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
                Errors::report(sprintf(
                    "帧阶段：%s 在 settle 之后跑了 —— %s。\n"
                    . '修法：把它挪到 $frame->run(...) 之前（组 A 只做"调回调"，回调里只 setState/markDirty）。',
                    $name,
                    self::CONSEQUENCE[$name] ?? '会慢一帧',
                ), Errors::DIAGNOSTIC);
            }
        }

        foreach (self::AFTER_SETTLE as $name) {
            if (!isset($index[$name])) {
                continue;
            }
            if ($index[$name] < $settleIndex) {
                Errors::report(sprintf(
                    "帧阶段：%s 在 settle 之前跑了 —— %s。\n"
                    . '修法：把它挪到 $frame->run(...) 之后（组 C 观察的是**新**树）。',
                    $name,
                    self::CONSEQUENCE[$name] ?? '会读到旧树',
                ), Errors::DIAGNOSTIC);
            }
        }

        foreach (self::ALL as $name) {
            if (!isset($index[$name])) {
                Errors::report(sprintf(
                    "帧阶段：本帧没跑 %s —— %s。\n"
                    . '修法：确认 frame() 里那段没被条件跳过；若确实要跳过，把名字从 FramePhase::ALL 里删掉并写理由。',
                    $name,
                    self::CONSEQUENCE[$name] ?? '这一帧少做一件事',
                ), Errors::DIAGNOSTIC);
            }
        }
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
    }
}
