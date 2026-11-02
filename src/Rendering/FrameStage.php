<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 一帧里各段的**唯一名字表**。
 *
 * ## 为什么要有它
 *
 * 同一段的名字原先散在五处：`FramePhase` 的四张名单、`frame()` 里的
 * `$trace[] = '…'`、`SurfaceProfile::add('…')`、`Frame::run()` 的三处计时、
 * `apps/bench` 的 `$pumpKeys`。已经漂了两处，而且都不报错：
 *
 * · **三个段有两个名字** —— 顺序校验叫 `tickAnimations` / `focusCollect` /
 *   `pumpSemantics`，计时叫 `anim` / `focus` / `semantics`。于是
 *   `build/frame.trace.out` 与 `build/bench-m4.txt` 对不上，交叉分析靠人脑。
 * · `M4` 的 `$pumpKeys` 里写着 `placeMenu` —— **这个名字不存在**（真名
 *   `placeOverlays`）⇒ 浮层布局的耗时从未进过泵合计，一直被算进
 *   「未归属(帧头尾)」。量具把归因算错了，自己去不报错（同 §9.4 的
 *   `HttpTest` 页对齐阈值、`formatNs` 的 1000 倍，同一族）。
 *
 * ## 为什么是常量不是 enum
 *
 * enum 有穷尽性检查，但 20 个调用点都得写 `->value`；而 `SurfaceProfile::add()`
 * 还要收任意段名（测试用 `probe`）⇒ 签名一放宽，检查就没了。
 * 常量表的防护来自"拼错 = 未定义常量"（编译期 Error）；表自身的完整性
 * （常量集 == 表集、后果不缺、无重名）由 `FrameStageTest` 逐条钉住。
 *
 * ## 分层
 *
 * 住 `Rendering` 不住 `App`：表面三段在 `Platform/Win32` 计时，而 `Platform`
 * 不依赖 `App`（§4.3）。选址理由同 `SurfaceProfile`。
 *
 * @api dev 量具入口（`apps/bench` 与各 probe 的段名从这里取）
 */
final class FrameStage
{
    // ── 九段：有顺序约束，见 FramePhase ──────────────────────────────
    public const PUMP_IO = 'pumpIo';
    public const PUMP_TASKS = 'pumpTasks';
    public const PUMP_TIMERS = 'pumpTimers';
    public const TICK_ANIMATIONS = 'tickAnimations';
    public const PLACE_OVERLAYS = 'placeOverlays';
    public const SETTLE = 'settle';
    public const TICK_LATE_MOUNT = 'tickLateMount';
    public const FOCUS_COLLECT = 'focusCollect';
    public const PUMP_SEMANTICS = 'pumpSemantics';

    // ── 只计时：不在 frame() 的顺序名单里 ────────────────────────────
    /** `Frame::run()` 内部 */
    public const LAYOUT = 'layout';
    public const PAINT = 'paint';

    /** `Platform` 侧的表面三段 */
    public const BEGIN_FRAME = 'beginFrame';
    public const PRESENT = 'present';

    /** settle 之前（会产脏；组内顺序任意 —— 各泵写的是不同的 State） */
    private const BEFORE = 'before';

    /** 顺序由直写代码保证，这里只要求"出现过" */
    private const CORE = 'core';

    /** settle 之后（观察**新**树；`focusCollect` 要在 `pumpSemantics` 前 —— 语义树要带焦点） */
    private const AFTER = 'after';

    /**
     * 有顺序约束的九段：名字 => [组, 越界/缺失的后果]。
     *
     * ★ 后果写在这里而不是断言处：断言消息要能直接回答"所以呢"，而"所以呢"
     *   是**段的属性**，不是某条检查的属性。
     *
     * @var array<string, array{string, string}>
     */
    private const ORDERED = [
        self::PUMP_IO => [self::BEFORE, '外部数据晚一帧到，回调里的 setState 要等下一帧'],
        self::PUMP_TASKS => [self::BEFORE, '后台结果晚一帧生效 —— "loading → ready" 会闪一帧空'],
        self::PUMP_TIMERS => [self::BEFORE, '延时回调晚一帧；跑到 settle 之后则回调里的 setState 要等下一帧（tooltip 晚出、snackbar 晚走）'],
        self::TICK_ANIMATIONS => [self::BEFORE, '动画晚一帧起手，第一帧不动'],
        self::PLACE_OVERLAYS => [self::CORE, '浮层布局晚一帧，菜单/模态会抖一下'],
        self::SETTLE => [self::CORE, '帧末重建没跑 —— 这一帧的 setState 全部作废'],
        self::TICK_LATE_MOUNT => [self::AFTER, '迟到挂载的 State 不被 tick ⇒ 帧时钟不上膛 ⇒ 之后没事件就永远静止'],
        self::FOCUS_COLLECT => [self::AFTER, '焦点链可能握着已被移除的 Element ⇒ 按键静默丢给死节点'],
        self::PUMP_SEMANTICS => [self::AFTER, '读屏停在上一帧'],
    ];

    /** @var list<string> 只计时、不参与顺序校验的段 */
    private const TIMED_ONLY = [self::LAYOUT, self::PAINT, self::BEGIN_FRAME, self::PRESENT];

    /**
     * 九段全名单，顺序 = 一帧内的正确顺序。
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return \array_keys(self::ORDERED);
    }

    /** @return list<string> */
    public static function beforeSettle(): array
    {
        return self::inGroup(self::BEFORE);
    }

    /** @return list<string> */
    public static function afterSettle(): array
    {
        return self::inGroup(self::AFTER);
    }

    /** @return list<string> */
    public static function core(): array
    {
        return self::inGroup(self::CORE);
    }

    /**
     * 这一段越界/缺失的后果；`null` = 表里没登记。
     *
     * ★ 返回 `?string` 而不是兜底一句话：兜底会让"表漏了一项"看起来和正常
     *   一样（原先的 `?? '会慢一帧'` 就是这个形状）。调用方负责把 `null` 说出来。
     */
    public static function consequence(string $stage): ?string
    {
        return self::ORDERED[$stage][1] ?? null;
    }

    /**
     * 帧泵段：九段里除 `settle`。`apps/bench` 的「未归属」归因用 —— 原先那里
     * 手写了一份名单，还写错了名字（见类注释）。
     *
     * @return list<string>
     */
    public static function pumpStages(): array
    {
        return \array_values(\array_filter(
            self::all(),
            static fn (string $stage): bool => $stage !== self::SETTLE,
        ));
    }

    /**
     * **表完备性自检**：把"表本身对不对"变成可断言的，而不是靠人肉记四张表。
     *
     * `$declared` 由调用方从反射取全部 public 常量（类自己看不见"有没有漏进表
     * 的常量"，只有反射能看见）。
     *
     * @param list<string> $declared 全部段名常量的值
     * @return list<string> 失败项（空数组 = 通过）
     */
    public static function selfTest(array $declared): array
    {
        $fail = [];
        $inTable = [...self::all(), ...self::TIMED_ONLY];

        foreach ($declared as $name) {
            if (!\in_array($name, $inTable, true)) {
                $fail[] = \sprintf('常量 "%s" 没进表 —— 它既不在 ORDERED 也不在 TIMED_ONLY', $name);
            }
        }

        foreach ($inTable as $name) {
            if (!\in_array($name, $declared, true)) {
                $fail[] = \sprintf('表里的 "%s" 没有对应常量 —— 调用点无法引用它', $name);
            }
        }

        if (\count(\array_unique($inTable)) !== \count($inTable)) {
            $fail[] = 'ORDERED / TIMED_ONLY 之间有重名段';
        }

        foreach (self::all() as $name) {
            $why = self::consequence($name);
            if ($why === null || $why === '') {
                $fail[] = \sprintf('段 "%s" 没有登记后果 —— 越界时断言只能给一句废话', $name);
            }
        }

        foreach (self::all() as $name) {
            $group = self::ORDERED[$name][0];
            if (!\in_array($group, [self::BEFORE, self::CORE, self::AFTER], true)) {
                $fail[] = \sprintf('段 "%s" 的组 "%s" 不合法', $name, $group);
            }
        }

        if (self::ORDERED[self::SETTLE][0] !== self::CORE) {
            $fail[] = 'settle 必须在 CORE —— 它是所有越界判定的参照点';
        }

        // 跨组重名会让"越界"与"缺失"两条检查对同一段各说一次，且顺序检查失效
        $grouped = [...self::beforeSettle(), ...self::afterSettle(), ...self::core()];
        if (\count(\array_unique($grouped)) !== \count($grouped)) {
            $fail[] = '同一段同时出现在多个组里';
        }

        return $fail;
    }

    /**
     * @return list<string>
     */
    private static function inGroup(string $group): array
    {
        $out = [];
        foreach (self::ORDERED as $name => [$g]) {
            if ($g === $group) {
                $out[] = $name;
            }
        }

        return $out;
    }
}
