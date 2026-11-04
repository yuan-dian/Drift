<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;

/**
 * 布局纯函数自检：同一约束对主树连排两次，逐项比对几何。
 *
 * 文件开关（build/layout.check 存在即开，默认关，生产路径零开销）。
 *
 * ★ 报告整份重写进 build/layout.check.out：Diagnostic 只进进程内环形
 *   缓冲，真机独立进程读不到，mismatches 恒 0 只能靠文件看。
 *
 * @api dev 量具入口
 */
final class LayoutSelfCheck
{
    /** 自检开关 */
    public static bool $on = false;

    /** 采样间隔（帧） */
    private const SAMPLE_EVERY = 16;

    /**
     * 报告文件（getcwd() 相对）。
     */
    private const REPORT = 'build/layout.check.out';

    /** 已执行比对次数 */
    public static int $checks = 0;

    /** 不一致次数（必须恒 0） */
    public static int $mismatches = 0;

    /**
     * @var list<array{class: string, field: string}>
     */
    public static array $recent = [];

    /** 自持帧计数（不依赖 Frame 公开入口） */
    private static int $frames = 0;

    /** 初始化守卫（开关只读一次） */
    private static bool $booted = false;

    /**
     * 主树布局完之后调一次。
     *
     * 必须放在 $box->layout($constraints) 之后：这里再排一次，
     * 排在前面就等于替真实帧排了两遍。
     *
     * @param RenderBox $root 主树的有效盒（Element::effectiveBox()）
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
                // 用同一约束再排一次；若 layout() 纯，这一步什么都不改变。
                $root->layout($constraints);
            } finally {
                // ★ 自校验时 layout() 抛异常也要照常推进计数，异常不吞：
                //   吞了 = 窗口冻结在上一帧且不报错。
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

        // 每帧都刷报告，不只采样帧：frames 要表达"当前跑到第几帧"，
        // 否则真机上看着像丢帧（实际只是报告没跟上）。
        self::writeReport();
    }

    /**
     * 把计数器刷进报告文件。
     *
     * 开关与报告同在 build/ 下 ⇒ 开关被 is_file() 找到时目录一定存在，
     * 写不会因目录缺失而静默失败。
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

    /** 清零（测试 / bench 每轮开头调） */
    public static function reset(): void
    {
        self::$frames = 0;
        self::$checks = 0;
        self::$mismatches = 0;
        self::$recent = [];
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        // ★ 用 getcwd() 而非 __DIR__：后者是编译期常量，AOT 下指向构建机源码树，
        //   打包后静默不生效。
        self::$on = self::$on || \is_file('build/layout.check');
        if (self::$on) {
            self::writeReport();
        }
    }

    /**
     * 扁平化快照：深度优先，每盒一项 类名|w|h|ox|oy。
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
