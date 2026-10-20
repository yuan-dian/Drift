<?php
declare(strict_types=1);

namespace Drift\Bench;

use App\Showcase\ShowcaseRoot;
use Drift\Errors;
use Drift\Widget\Widget;

/**
 * 三个基准的编排 + 结果落盘。
 *
 * ★ **没有 stdout**：`build/base.yml` 里 `no-console: true`（AOT 产物是 GUI 子系统），
 *   所以结果一律写文件 —— 见 `11` §5.4。
 *
 * 输出：`build/bench-out.txt`（相对仓库根；`drift-build` 与运行都以仓库根为 cwd）。
 *
 * ★★ **每完成一个基准就落盘一次**（`flush()`）。第一版只在最后写一次，结果 M3 的
 *   夹具踩了 C5 Fatal ⇒ 文件根本没生成，M1/M2 白跑了。量具必须"跑一段留一段"。
 *
 * ★★ **必须装 fatalBox**：不装的话 `Errors::report` 会弹原生消息框等人点确定 ——
 *   无人值守跑基准时表现为"进程挂三分钟然后被 kill，且没有输出"（实测踩过）。
 *   这里换成"把已攒的行 + Fatal 正文一起写文件，然后 exit(2)"。
 *
 * ★ 报告行攒在**静态属性**里，不用 `array &$lines` 引用参数：AOT 下引用参数的
 *   读写路径更长，而且这里没有任何性能诉求。
 *
 * @api 应用入口（`main.php`）调它，与三个 app 的 `Bootstrap::run()` 同层。
 */
final class Runner
{
    private const OUT = 'build/bench-out.txt';

    /** @var list<string> */
    private static array $lines = [];

    public static function run(): void
    {
        self::$lines = [];

        $out = self::OUT;
        Errors::installFatalBox(static function (string $title, string $body) use ($out): void {
            \file_put_contents(
                $out,
                \implode("\n", Runner::reportLines()) . "\n\n[FATAL] " . $title . "\n" . $body . "\n"
            );
            exit(2);
        });

        // ★★ 真机模式：`build/m4.on` 存在 ⇒ 只跑 M4（要窗口，不能与无头基准同进程跑）。
        //   用**文件开关**而不是 `$_SERVER['argv']`：GUI 子系统的 AOT 产物拿 argv 要过
        //   CRT 的初始化，而 `main()` 是 tpc 生成的入口 —— 不值得为一行参数去赌。
        if (\is_file('build/m4.on')) {
            self::$lines = M4::run();
            \file_put_contents('build/bench-m4.txt', \implode("\n", self::$lines) . "\n");
            exit(0);
        }

        self::line('════════════════════════════════════════════════════════════════');
        self::line(' Drift2 bench —— v7 阶段 0 微基准（AOT 产物）');
        self::line(' 规格：docs/design-v7/25-推进路线.md §3');
        self::line('════════════════════════════════════════════════════════════════');
        self::line('');
        self::flush();

        self::m1();
        self::flush();

        self::m2();
        self::flush();

        self::m3();

        self::line('════════════════════════════════════════════════════════════════');
        self::line(' 按 25-推进路线.md §4 的决策树分支。一次只动一条线（§7）。');
        self::line('════════════════════════════════════════════════════════════════');
        self::flush();

        exit(0);
    }

    /**
     * @return list<string>
     */
    public static function reportLines(): array
    {
        return self::$lines;
    }

    private static function line(string $s): void
    {
        self::$lines[] = $s;
    }

    private static function flush(): void
    {
        \file_put_contents(self::OUT, \implode("\n", self::$lines) . "\n");
    }

    private static function m1(): void
    {
        $m1 = M1::run();
        self::line('## M1  toObject 的成本（V3）');
        self::line('   5000 节点 packed array，每组 200 轮取中位，归一化为 ns/节点');
        self::line('');

        $a = (int) $m1['A toObject+static']['ns'];
        $b = (int) $m1['B dynamic dispatch']['ns'];
        $c = (int) $m1['C typed refs x8']['ns'];
        $d = (int) $m1['D foreach only']['ns'];

        self::line(\sprintf('   %-22s %8d ns/node', 'A toObject+static', $a));
        self::line(\sprintf('   %-22s %8d ns/node', 'B dynamic dispatch', $b));
        self::line(\sprintf('   %-22s %8d ns/node', 'C typed refs x8', $c));
        self::line(\sprintf('   %-22s %8d ns/node', 'D foreach only', $d));
        self::line('');
        self::line(\sprintf(
            '   A / B = %s   （判据：≤1.0 一棵树更便宜 · 1.0–1.5 同量级 · >1.5 关掉 I1）',
            Stats::ratio($a, $b)
        ));
        self::line(\sprintf(
            '   A / C = %s   （信息量：PHP 层 vs C struct 的固有差距，现状也有）',
            Stats::ratio($a, $c)
        ));
        self::line(\sprintf('   A - D = %d ns/node（toObject + 静态调用的净成本，扣掉迭代）', $a - $d));
        self::line(\sprintf('   B - D = %d ns/node（动态分派的净成本，扣掉迭代）', $b - $d));
        self::line('');
        self::line('   结论：' . self::m1Verdict($a, $b));
        self::line('');
    }

    private static function m2(): void
    {
        $m2 = M2::run();
        self::line('## M2  mixed 装箱的成本（Cell 分裂）');
        self::line('   10 万次读 × 80 轮取中位，归一化为 ns/读');
        self::line('');

        $mi = (int) $m2['A mixed+toInt'];
        $ii = (int) $m2['B int direct'];
        $fi = (int) $m2['C float direct'];
        $si = (int) $m2['D mixed str+toInt'];

        self::line(\sprintf('   %-22s %8d ns/read', 'A mixed(int)+toInt', $mi));
        self::line(\sprintf('   %-22s %8d ns/read', 'B int direct', $ii));
        self::line(\sprintf('   %-22s %8d ns/read', 'C float direct', $fi));
        self::line(\sprintf('   %-22s %8d ns/read', 'D mixed(str)+toInt', $si));
        self::line('');
        self::line(\sprintf(
            '   A / B = %s   （判据：>5 ⇒ Cell 分裂优先 · ≈int ⇒ 顺手做）',
            Stats::ratio($mi, $ii)
        ));
        self::line(\sprintf('   A / C = %s', Stats::ratio($mi, $fi)));
        self::line(\sprintf(
            '   D / A = %s   （反例：mixed 装 string 时还免费吗；明显 >1 ⇒ 结论要按值类型分档）',
            Stats::ratio($si, $mi)
        ));
        self::line('');
        self::line('   结论：' . self::m2Verdict($mi, $ii));
        self::line('');
    }

    private static function m3(): void
    {
        self::line('## M3  每阶段耗时分解');
        self::line('   无头 RecordingCanvas（只记录命令，不做光栅化）；present≈0（真机 Skia 的 present 不在本基准）');
        self::line('   占比分母 = settle + layout + paint（PHP 侧可优化部分）');
        self::line('');

        // ★ 不用 `foreach ($cases as [$label, $widget, ...])`：数组里存 Widget 对象，
        //   取出来是 `php::Var`，再传给 `Widget` 形参要靠运行期定型 —— 没必要。
        //   四次直调，形状清楚、零类型恢复。
        self::oneCase('showcase（真实应用根）', new ShowcaseRoot(), 1280.0, 800.0, 200);
        self::oneCase('参数化 20×3', Trees::build(20, 3), 1280.0, 800.0, 200);
        self::oneCase('参数化 50×8', Trees::build(50, 8), 1280.0, 800.0, 120);
        self::oneCase('参数化 100×14', Trees::build(100, 14), 1280.0, 800.0, 60);
    }

    private static function oneCase(
        string $label,
        Widget $widget,
        float $w,
        float $h,
        int $frames,
    ): void {
        $r = M3::runTree($label, $widget, $w, $h, $frames);
        $nodes = \max(1, (int) $r['nodes']);

        self::line(\sprintf('   ── %s', $label));
        self::line(\sprintf('      节点 %d · 帧 %d', (int) $r['nodes'], (int) $r['frames']));
        self::line(\sprintf('      settle  %10s ms', Stats::ms((int) $r['settle'])));
        self::line(\sprintf('      layout  %10s ms', Stats::ms((int) $r['layout'])));
        self::line(\sprintf('      paint   %10s ms', Stats::ms((int) $r['paint'])));
        self::line(\sprintf('      present %10s ms', Stats::ms((int) $r['present'])));
        self::line(\sprintf('      total   %10s ms', Stats::ms((int) $r['total'])));
        self::line(\sprintf(
            '      每节点 layout %s us',
            \number_format((float) $r['layout'] / 1000.0 / (float) $nodes, 3, '.', '')
        ));
        self::line('      ' . M3::verdict($r));
        self::line('');

        // 每档单独落盘：下一档崩了也不丢前面几档
        self::flush();
    }

    private static function m1Verdict(int $a, int $b): string
    {
        if ($b <= 0) {
            return 'n/a';
        }

        if ($a <= $b) {
            return '一棵树的遍历成本不高于现状（1 次定型换掉 N 次动态分派）⇒ I1 有实测支撑';
        }

        $r = (float) $a / (float) $b;
        if ($r <= 1.5) {
            return '与现状同量级 ⇒ 不构成推翻理由，按可读性 / 可调试性决策';
        }

        return '比现状更贵 ⇒ 关掉 I1（`24` 加结论、`13` 的 D1 加推翻条件）';
    }

    private static function m2Verdict(int $mixed, int $int): string
    {
        if ($int <= 0) {
            return 'n/a';
        }

        $r = (float) $mixed / (float) $int;
        if ($r > 5.0) {
            return 'Cell 分裂优先（1 周见效）';
        }
        if ($r > 2.0) {
            return '分裂有收益但不悬殊 ⇒ 与 19op 分层并列，谁先顺手谁先做';
        }

        return '与类型化属性同阶 ⇒ Cell 分裂降级为顺手做';
    }
}
