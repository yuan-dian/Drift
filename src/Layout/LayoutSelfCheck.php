<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;

/**
 * 布局自校验：同一约束连排两次，逐项比（`04` §5.2）。
 *
 * ## 它守的是什么
 *
 * `RenderBox::layout()` 的 docblock 声明"纯函数（§14.1）：同样输入 ⇒ 同样输出"，
 * 而**没有任何机制验证它**。这条声明是 `00` §5「不做布局缓存」的**唯一前提** ——
 * 布局不纯（读了上一帧的 `$this->size`）时，连"重算"都不保证正确。
 * ⇒ **先证明纯，才有资格说不缓存。**
 *
 * 原文（v5 `design/03` §6）："lint 只校验公式被调用，校验不了公式内容对不对"。
 * 本类就是那个"校验内容"的东西：快照几何 → 忽略缓存全量重排 → 逐节点比对。
 *
 * ## 为什么不做容差
 *
 * 浮点误差在纯函数里也应该是确定的（同样输入 ⇒ 同样运算顺序 ⇒ 同样结果）。
 * 加容差等于允许"轻微不纯"，而"轻微不纯"在布局里就是"偏移差 0.5px"——
 * 那正是要抓的东西。⇒ 快照用**原始 float 的字符串形**，不做 round。
 *
 * ## 开关与开销
 *
 * `build/layout.check` 文件存在 ⇒ 开（同 `apps/bench` 的 `build/m4.on` 那一套
 * **文件开关**：不用 `__DIR__`（编译期常量，AOT 下指向构建机 ⇒ 打包后静默不生效），
 * 不用 `getenv`（`nano-hygiene` 禁））。默认关，`$on` 为 false 时每次调用只多
 * 一次静态 bool 读。
 *
 * 采样 1/16：一次全量重排 ≈ 0.3–0.8ms（2200 行的树），摊到 16 帧 ≈ 0.05ms/帧。
 * 若将来树大到重排 > 4ms，把 `SAMPLE_EVERY` 改 64。
 *
 * ## 不一致怎么被看见（★ 真机判据靠这个）
 *
 * 同 `FramePhase`：`Errors::report(DIAGNOSTIC)` 只进**进程内**环形缓冲，真机上
 * `users.exe` 是独立进程 ⇒ 外面读不到 ⇒ “`mismatches === 0`”只能选择相信。
 * ⇒ 每次采样完把计数器刷进 `build/layout.check.out`（整份重写）。
 *
 * @api dev 量具入口（`04` §12.2 #14 的夹具设 `$on = true` 直接驱它）
 */
final class LayoutSelfCheck
{
    /** 打开后每 16 帧抽一帧连排两次比对 */
    public static bool $on = false;

    /** 采样频率：每 N 帧一次（v6 `04` §3.2 同频率） */
    private const SAMPLE_EVERY = 16;

    /**
     * 报告文件（与开关同目录同前缀，`getcwd()` 相对）。
     *
     * ★ 命名跟着 `apps/bench` 的 `build/bench-out.txt` 那一套 `-out` 后缀惯例。
     */
    private const REPORT = 'build/layout.check.out';

    /** 已执行的比对次数 */
    public static int $checks = 0;

    /** 不一致次数（**这个数必须恒 0**；真机判据直接读报告文件里的这一行） */
    public static int $mismatches = 0;

    /**
     * 最近几条不一致的描述。
     *
     * @var list<array{class: string, field: string}>
     */
    public static array $recent = [];

    /** 帧计数（自持，不穿 `Frame::run()` 的 `@api` 签名） */
    private static int $frames = 0;

    /** 文件开关只读一次 */
    private static bool $booted = false;

    /**
     * 主树布局完之后调一次。
     *
     * ★ **必须放在 `$box->layout($constraints)` 之后**：这里做的是"再排一次"，
     *   排在前面就等于替真实帧排了两遍。
     *
     * @param RenderBox $root 主树的有效盒（`Element::effectiveBox()`）
     */
    public static function maybeCheck(RenderBox $root, BoxConstraints $constraints): void
    {
        self::boot();

        if (!self::$on) {
            return; // 生产路径：一次静态读 + 分支，零开销
        }

        self::$frames++;

        if (self::$frames % self::SAMPLE_EVERY === 0) {
            $before = self::snapshot($root);

            try {
                // ★ 用**同一约束**再排一次。若 `performLayout()` 纯，这一步什么都不改变。
                $root->layout($constraints);
            } finally {
                // ★ `04` §10 #14：自校验时 `layout()` 抛异常 ⇒ 计数器照常推进，
                //   异常**不吞**（吞了就是"窗口冻结在上一帧且不报错"）。
                $after = self::snapshot($root);
                self::$checks++;

                if ($before !== $after) {
                    self::$mismatches++;
                    $diff = self::firstDiff($before, $after);
                    self::$recent[] = $diff;
                    if (count(self::$recent) > 8) {
                        self::$recent = array_slice(self::$recent, -8);
                    }
                    Errors::report(sprintf(
                        '布局不是纯函数：%s 用同一约束连排两次，结果不同（%s）。'
                            . "\n" . '修法：performLayout() 里只读约束与子树，'
                            . '不读 $this->size / $this->offsetX 等上一帧留下的值。',
                        $diff['class'],
                        $diff['field'],
                    ), Errors::DIAGNOSTIC);
                }

            }
        }

        // ★ **每帧都刷，不只采样帧**：报告里的 `frames` 要表达"当前跑到第几帧"。
        //   只在采样帧刷（我第一版就是这么写的）会让它比真实帧数少 0–15 ——
        //   真机上看着像"丢帧"，实际只是报告没跟上（`143 vs 128`，128 = 8×16）。
        //   代价是一次 ~200 字节的写，dev 开关下可忽略。
        // ★ 哪怕一帧都没采样到，`boot()` 也已经落了一份 `checks 0` ——
        //   "没有文件"与"跑过且干净"在外面必须分得开。
        self::writeReport();
    }

    /**
     * 把计数器刷进报告文件（同 `FramePhase::writeReport()`）。
     *
     * ★ 整份重写而不是追加：报告表达"**当前**状态；进程被杀时留下的最后一份
     *   恰好就是当时的真实状态。
     * ★ 开关与报告同在 `build/` 下 ⇒ 开关被 `is_file()` 找到时目录一定存在，
     *   写不会因目录缺失而静默失败。
     */
    private static function writeReport(): void
    {
        $lines = [
            'switch     build/layout.check',
            'frames     ' . self::$frames,
            'checks     ' . self::$checks,
            'mismatches ' . self::$mismatches,
        ];
        foreach (self::$recent as $r) {
            $lines[] = '  · ' . $r['class'] . ' · ' . $r['field'];
        }
        \file_put_contents(self::REPORT, implode("\n", $lines) . "\n");
    }

    /** 清零（bench / 测试每轮开头调） */
    public static function reset(): void
    {
        self::$frames = 0;
        self::$checks = 0;
        self::$mismatches = 0;
        self::$recent = [];
    }

    /**
     * 文件开关：只读一次，且**只会把 `$on` 从 false 翻成 true**。
     *
     * ★ 为什么不直接 `self::$on = \is_file(...)`：夹具（`04` §12.2 #14）要
     *   手动设 `$on = true` 来驱反例，直接赋值会把夹具设的值冲掉。
     */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        // ★ `getcwd()` 而不是 `__DIR__`：后者是**编译期常量**，AOT 下指向
        //   构建机源码树 ⇒ 开发机"恰好能用"、打包后静默不生效（`10` §2 A8）。
        self::$on = self::$on || \is_file('build/layout.check');
        if (self::$on) {
            // ★ 立刻落一份 `checks 0`：否则"没有报告文件"与"跑过且干净"
            //   在外面看起来一模一样。
            self::writeReport();
        }
    }

    /**
     * 扁平化快照：深度优先，每盒一项 `类名|w|h|ox|oy`。
     *
     * @return list<string>
     */
    private static function snapshot(RenderBox $root): array
    {
        $out = [];
        /** @var list<RenderObject> $stack */
        $stack = [$root];

        while ($stack !== []) {
            $node = array_pop($stack);
            if (!$node instanceof RenderBox) {
                continue;
            }

            $size = $node->size();
            $out[] = sprintf(
                '%s|%s|%s|%s|%s',
                get_class($node),
                $size === null ? 'null' : (string) $size->width,
                $size === null ? 'null' : (string) $size->height,
                (string) $node->offsetX(),
                (string) $node->offsetY(),
            );

            /** @var list<RenderObject> $kids */
            $kids = [];
            $node->visitChildren(static function (RenderObject $child) use (&$kids): void {
                $kids[] = $child;
            });
            // 逆序入栈 ⇒ 弹出顺序 = 文档顺序（确定性是比对的前提）
            for ($i = count($kids) - 1; $i >= 0; $i--) {
                $stack[] = $kids[$i];
            }
        }

        return $out;
    }

    /**
     * 第一条差异：类名 + 哪个字段 + 两个值。
     *
     * @param list<string> $before
     * @param list<string> $after
     * @return array{class: string, field: string}
     */
    private static function firstDiff(array $before, array $after): array
    {
        $n = max(count($before), count($after));
        for ($i = 0; $i < $n; $i++) {
            $a = $before[$i] ?? '(缺)';
            $b = $after[$i] ?? '(缺)';
            if ($a === $b) {
                continue;
            }
            $pa = explode('|', $a);
            $pb = explode('|', $b);
            $class = $pa[0] === $pb[0] ? $pa[0] : $pa[0] . ' / ' . $pb[0];
            $field = '节点 ' . $i . '：' . $a . ' ⇒ ' . $b;
            return ['class' => $class, 'field' => $field];
        }

        return ['class' => '(长度不同)', 'field' => count($before) . ' ⇒ ' . count($after)];
    }
}
