<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

use Drift\Dev\Bench\Micro\MicroNode;
use Drift\Dev\Bench\Micro\MicroOther;
use Drift\Dev\Bench\Micro\MicroSize;

/**
 * tpc AOT vs PHP 解释器 的**阶梯微基准**：把「AOT 慢 1.73×」拆成可归因的成分。
 *
 * ★ 为什么需要它：`DEBT` §34 量出 AOT 在 12k 合成树上慢 1.73×、§35 在 27 个真实页上
 *   慢 2.24×，当时的解释是「`php::Var` 装箱」（假说，未验证）。但 `docs/reference/aot-constraints.md` §2.3
 *   的 M2 微基准早就测过 `mixed` + `toInt()` vs `int` 直读 = **0.94** —— 装箱在
 *   「属性读」这一点上几乎免费。⇒ 两个结论对不上，而「两个比值都得留着」的措辞
 *   掩盖了这件事：**减速的成分根本没定位过**。
 *
 * ★ 这一份的做法是**阶梯**：每一级只比上一级多一种操作，于是"慢"落在哪一级就说明
 *   是哪一种操作贵。不做算术建模，也不假设任何一级会赢。
 *
 * ★★ 只看**比值**，不看绝对值：绝对值随机器漂，比值才是"这两把尺子差多少"。
 *   唯一要求同进程同机器同一次跑完 —— 两侧跑的是同一份本类。
 *
 * ★ 13 级的形状全部取自 `RenderBox::layout()` 的真实形状（`src/Layout/RenderBox.php:266`）：
 *   属性读、`tighten()`/`deflate()`/`constrain()` 的**返回值对象分配**、`performLayout()`
 *   的虚调用、align 分支里 `visitChildren(closure)` 的**闭包分配**。
 *
 * @internal 量具专用，不进公共 API
 */
final class AotMicro
{
    /**
     * 全部级。返回 `['Lxx 名称' => [调用闭包, n]]`。
     *
     * ★ 每级的 `n` 是**各自定的**（让每级落在 5–30ms），所以跨级只能比**比值**。
     *   两侧读同一个 `n` —— 这是"同一件事"的前提。
     *
     * @return array<string, array{0: \Closure(int): int, 1: int}>
     */
    public static function levels(int $base = 200000): array
    {
        // L11 的树：64 节点、两个实现交替 ⇒ 调用点多态（真实树有几十种子类）
        $nodes = [];
        for ($i = 0; $i < 64; $i++) {
            $nodes[] = ($i % 2 === 0) ? new MicroNode($i, $i + 1) : new MicroOther($i);
        }

        // L04 的键：**预建**。键必须是现成的字符串而不是每轮拼接 ——
        // 拼一遍要付字符串分配，那测的就不是 hash 查找了（第一版就这么错，见 §36.3）。
        $keys = [];
        for ($i = 0; $i < 256; $i++) {
            $keys[] = 'k' . (string) $i;
        }

        return [
            'L00 fold'        => [static fn (int $n): int => self::fold($n), $base * 4],
            'L01 arith'       => [static fn (int $n): int => self::arith($n), $base * 3],
            'L02 floats'      => [static fn (int $n): int => self::floats($n), $base * 2],
            'L03 arrInt'      => [static fn (int $n): int => self::arrInt($n), $base],
            'L04 arrStr'      => [static fn (int $n): int => self::arrStr($n, $keys), \intdiv($base, 2)],
            'L05 objNew'      => [static fn (int $n): int => self::objNew($n), \intdiv($base, 2)],
            'L06 valNew'      => [static fn (int $n): int => self::valNew($n), \intdiv($base, 2)],
            'L07 mixed'       => [static fn (int $n): int => self::mixedVar($n, 7), $base * 2],
            'L08 method'      => [static fn (int $n): int => self::methodCall($n), $base],
            'L09 virt'        => [static fn (int $n): int => self::virtCall($n), $base],
            // ★ n 按 base/8 定：base/2（10 万轮）会越过 GC 阈值，
            //   实测 ns/op 从 95 跳到 200 —— 那量的是 GC 不是闭包创建。
            'L10 closure'     => [static fn (int $n): int => self::closure($n), \intdiv($base, 8)],
            'L11 treeWalk'    => [static fn (int $n): int => self::treeWalk($n, $nodes), \intdiv($base, 16)],
            'L12 mimicLayout' => [static fn (int $n): int => self::mimicLayout($n), \intdiv($base, 4)],
        ];
    }

    /**
     * 跑一整张表。
     *
     * @return list<array<string, mixed>>
     */
    public static function run(int $base = 200000, int $reps = 3): array
    {
        $rows = [];
        foreach (self::levels($base) as $name => $spec) {
            $rows[] = self::measure($name, $spec[0], $spec[1], $reps);
        }

        return $rows;
    }

    /**
     * 量一级：`$reps` 次取**最小**（min 抗噪 —— 抖动只会加时间，不会减）。
     *
     * @param \Closure(int): int $fn
     *
     * @return array<string, mixed>
     */
    public static function measure(string $label, \Closure $fn, int $n, int $reps = 3): array
    {
        // 预热：AOT 的内联缓存与分支预测需要几轮才稳；解释器侧便宜。
        // ★ 结果丢掉，只为了让第一次正式计时不是"冷"的。
        $sink = $fn(\max(1, \intdiv($n, 20)));

        $best = \PHP_INT_MAX;
        $worst = 0;
        $ck = 0;
        for ($r = 0; $r < $reps; $r++) {
            $t0 = \hrtime(true);
            $ck = $fn($n);
            $ns = \hrtime(true) - $t0;

            if ($ns < $best) {
                $best = $ns;
            }
            if ($ns > $worst) {
                $worst = $ns;
            }
        }

        // 线性自证用 n/4：大到让过程序完成初始化，小到不把总时长拖长一倍。
        $linear = self::linearity($fn, \max(1, \intdiv($n, 4)));

        // ★ jitter 与 warmup 一起并进 checksum：不参与判据，但要**挡住**编译器
        //   把 `$sink` / 预热当死代码删掉（AOT 侧 DCE 是否发生从未验证过）。
        $ck += ($sink & 1);

        return [
            'label' => $label,
            'n' => $n,
            'minUs' => \round($best / 1000.0, 2),
            // ★★ 必须显式 `(float)`：tpc 把 `int / int` 编译成**整数除法**
            //   （2026-11-04 实测 `7 / 2 === 3`，见 `docs/reference/aot-constraints.md` §1.2）。
            //   不写这两个 cast，本量具在 AOT 侧的 ns/op 全是整数、线性自证永远算出 1.00 ——
            //   量具自己踩了被测对象的坑。
            'nsPerOp' => \round((float) $best / (float) $n, 2),
            'jitter' => $best > 0 ? \round((float) $worst / (float) $best, 2) : 0.0,
            'linear' => $linear,
            'checksum' => $ck,
        ];
    }

    /**
     * 量具自证（两侧跑同一份）。**判的是"这次测量可信吗"，不是"谁更快"** ——
     * 结论是报告出来的，不是判出来的；判据只负责挡住"测错了还煞有介事"。
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{tag: string, ok: bool, msg: string}>
     */
    public static function check(array $rows): array
    {
        $noCalc = [];
        $nonLinear = [];
        $noisy = [];
        $phantom = [];
        foreach ($rows as $r) {
            if ($r['checksum'] === 0) {
                $noCalc[] = (string) $r['label'];
            }
            // ★ 只判**下界**：比值 ≈ 1.0 才是"没在算"（常量折叠/整段删除）。
            //   上界不判 —— 分配型级（L05/L06/L10）超线性是 **GC 分批触发**，
            //   属于真实行为而非量具故障（实测 L10 在 n=100k 时 ns/op 从 95 跳到 200）。
            // ★ 太短的级不判：`hrtime` 对 <5µs 的段噪声占主导（L00 就是 0.0µs / 1.50）。
            //   注意 `minUs` 的单位是**微秒**（表头印的 us），别按毫秒写阈值。
            if ($r['minUs'] >= 5.0 && $r['linear'] < 1.3) {
                $nonLinear[] = $r['label'] . '(' . $r['linear'] . ')';
            }
            // ★★ 每轮 <0.5ns 在物理上不可能真跑过一轮（一次迭代至少一条指令）。
            //   这是"被折叠"最硬的判据 —— checksum 抓不住它（折叠后结果仍正确）。
            //   `L00 fold` 是**故意**可折叠的探针，不算事故。
            if ($r['nsPerOp'] < 0.5 && !\str_starts_with((string) $r['label'], 'L00')) {
                $phantom[] = $r['label'] . '(' . $r['nsPerOp'] . 'ns)';
            }
            // ★ 阈值 2.0 不是"宽容"，是**与取 min 的策略配套**：抖动只会加时间，
            //   3 次里的最小值已经把"机器被别人占了一下"排除掉了。
            //   1.4 会把正常的调度抖动（实测 1.46–1.68）判成事故，那是判据在撒谎。
            // ★ 太短的级同样不判：0.00 与 0.10µs 相除会得到 5.0，那是 hrtime 分辨率。
            if ($r['minUs'] >= 5.0 && $r['jitter'] > 2.0) {
                $noisy[] = $r['label'] . '(' . $r['jitter'] . ')';
            }
        }

        return [
            [
                'tag' => 'M①',
                'ok' => $noCalc === [],
                'msg' => $noCalc === []
                    ? \sprintf('%d 级全部有非零 checksum（结果被使用了）', \count($rows))
                    : 'checksum 为 0（结果没被使用／整段被删）：' . \implode(' ', $noCalc),
            ],
            [
                'tag' => 'M②',
                'ok' => $nonLinear === [],
                'msg' => $nonLinear === []
                    ? '可判的每级 t(2n)/t(n) ≥ 1.3（没有循环外提／整段删除）'
                    : '时间不随 n 增长（可能被优化掉）：' . \implode(' ', $nonLinear),
            ],
            [
                'tag' => 'M③',
                'ok' => $phantom === [],
                'msg' => $phantom === []
                    ? '除 L00 折叠探测外，每级 ns/op ≥ 0.5（没有整段折叠）'
                    : '每轮耗时低于物理下限（整段被折叠）：' . \implode(' ', $phantom),
            ],
            [
                'tag' => 'M④',
                'ok' => $noisy === [],
                'msg' => $noisy === []
                    ? '每级 3 次抖动 ≤ 2.0（取值取 min，抗噪足够）'
                    : '抖动过大：' . \implode(' ', $noisy),
            ],
        ];
    }

    /**
     * 线性自证：`t(2n) / t(n)`。
     *
     * ★ 这一条抓的是**"这一级根本没在算"**：常量折叠、循环外提、整段删除，
     *   在 n 翻倍时都表现为「时间不变」（比值 ≈ 1.0）。没有这条，
     *   AOT 侧那个 0.6× 到底是"真的快"还是"被优化掉了"就分不出来。
     *
     * ★★ **必须先预热**（2026-11-04 实测）：被测函数第一次执行要付类/方法初始化，
     *   `mimicLayout` 首跑 6250 轮要 4.86ms，第二跑 12500 轮只要 5.08ms ——
     *   拿首跑当分母会算出 1.09，看起来像"被优化掉了"，其实是**冷启动**。
     *   所以线性检查固定用 `n/4` 规模（大到大过程序被初始化，小到不拖慢总时长），
     *   并且跑两次预热。
     *
     * @param \Closure(int): int $fn
     */
    public static function linearity(\Closure $fn, int $n): float
    {
        $small = \max(1, $n);

        $fn($small);   // 预热 1：类/方法/内联缓存的初始化
        $fn($small);   // 预热 2：GC 阈值 / 分配器状态

        // ★ 各测 2 次取 min：单次读数在 1–3ms 量级上会被调度抖动主导，
        //   实测出过 0.77（两个真在算的规模相除得出"没在算"）。
        $a = \PHP_INT_MAX;
        $b = \PHP_INT_MAX;
        for ($r = 0; $r < 2; $r++) {
            $t0 = \hrtime(true);
            $fn($small);
            $a = \min($a, \hrtime(true) - $t0);

            $t1 = \hrtime(true);
            $fn($small * 2);
            $b = \min($b, \hrtime(true) - $t1);
        }

        return $a > 0 ? \round((float) $b / (float) $a, 2) : 0.0;
    }

    /**
     * 除法语义事实：**不参与判据，只打印**。
     *
     * ★ 为什么不判红：`int / int` 在 tpc AOT 下是**整数除法**（`7 / 2 === 3`），
     *   这是被测运行时的已知偏差，不是本量具的故障。判红会让每次跑都 FAIL，
     *   噪音会盖掉真正的红。但**必须每次都印出来**：它决定了本表里所有
     *   `ns/op` / `ratio` 是否可信（所以计算处都写了 `(float)`）。
     * ★ 哪天 tpc 修了，这行会变成 `3.5` —— 那时可以把那些 cast 去掉，
     *   但**不要急**：cast 无害，且能防回退。
     */
    public static function divisionFact(): string
    {
        $a = 7;
        $b = 2;
        $d = $a / $b;
        // ★ 字面量除法走的是**编译期常量折叠**，与上面那次运行期除法是两条不同的生成路径
        //   （2026-11-04：AspectPage 的 `16.0 / 9.0` 在 AOT 下算出了不同的比例）。
        $lit = 16.0 / 9.0;

        return 'int/int=' . \sprintf('%.4f', $d)
            . '  16.0/9.0=' . \sprintf('%.6f', $lit)
            . '（期望 3.5000 / 1.777778）';
    }

    /**
     * 打印整张表 + 判据。**两侧共用**：输出格式一致，逐级相除时不会错行。
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array{tag: string, ok: bool, msg: string}> $checks
     */
    public static function report(array $rows, array $checks, string $side, int $base): void
    {
        echo '=== AOT 微基准阶梯 · ' . $side . " ===\n";
        echo 'php=' . \PHP_VERSION
            . '  memory_limit=' . (string) \ini_get('memory_limit')
            . '  base=' . $base . "\n";
        echo '前提：' . self::divisionFact() . "\n\n";

        \printf("%-16s %9s %10s %9s %7s %7s %12s\n", 'level', 'n', 'minUs', 'ns/op', 'jitter', 'linear', 'checksum');
        echo \str_repeat('-', 76) . "\n";
        foreach ($rows as $r) {
            \printf(
                "%-16s %9d %10.2f %9.2f %7.2f %7.2f %12d\n",
                (string) $r['label'],
                (int) $r['n'],
                (float) $r['minUs'],
                (float) $r['nsPerOp'],
                (float) $r['jitter'],
                (float) $r['linear'],
                (int) $r['checksum']
            );
        }

        echo "\n";
        $fail = 0;
        foreach ($checks as $c) {
            echo ($c['ok'] ? '  ok   ' : '  FAIL ') . $c['tag'] . ' ' . $c['msg'] . "\n";
            if (!$c['ok']) {
                $fail++;
            }
        }
        echo "\n" . ($fail === 0
            ? '--- ' . \count($checks) . ' 项自证全过 ---'
            : '--- FAIL ' . $fail . ' ---') . "\n";
    }

    /**
     * 判据里失败了几条（两侧入口拿它定退出码）。
     *
     * @param list<array{tag: string, ok: bool, msg: string}> $checks
     */
    public static function failCount(array $checks): int
    {
        $n = 0;
        foreach ($checks as $c) {
            if (!$c['ok']) {
                $n++;
            }
        }

        return $n;
    }

    // ── 阶梯：每一级只比上一级多一种操作 ──────────────────────────────

    /**
     * L00 **折叠探测**（不是"循环税基线"）。
     *
     * ★ 原意是测"每轮固定税"，但 AOT 侧实测 **800,000 轮 0.00µs** ——
     *   tpc 把 `$s = $s + 1` 的循环直接折叠成了常量（`checksum` 仍正确，
     *   所以 M① 照绿）。⇒ AOT 的"每轮固定税"**不存在**，这一级作为基线无效。
     *   留着它，是因为"被折叠"本身就是结论：**tpc 有循环级优化**，
     *   而这也解释了 L01/L02 那 20× 的快（不是折叠，是真的把 zval 算术降成了
     *   int64/float64 算术 —— 时间仍随 n 线性增长）。
     */
    private static function fold(int $n): int
    {
        $s = 0;
        for ($i = 0; $i < $n; $i++) {
            $s = $s + 1;
        }

        return $s;
    }

    /** L01 纯 int 算术（乘加与掩码，防溢出）。 */
    private static function arith(int $n): int
    {
        $s = 1;
        for ($i = 0; $i < $n; $i++) {
            $s = ($s * 31 + $i) & 0xFFFFFF;
        }

        return $s;
    }

    /** L02 float 算术 + 分支：几何计算的最小形状。 */
    private static function floats(int $n): int
    {
        $x = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = $x + (float) $i * 0.5 - 0.25;
            if ($x > 1000.0) {
                $x = $x - 1000.0;
            }
        }

        return (int) $x;
    }

    /** L03 int 键数组：写满再读满（`children` / `$list` 的形状）。 */
    private static function arrInt(int $n): int
    {
        $a = [];
        for ($i = 0; $i < $n; $i++) {
            $a[$i] = $i & 0xFF;
        }

        $s = 0;
        for ($i = 0; $i < $n; $i++) {
            $s += $a[$i];
        }

        return $s;
    }

    /**
     * L04 string 键数组。
     *
     * ★ 键**预建**、不在循环里拼：第一版写的是 `$a['k' . (string) $i]`，
     *   那样每轮多付一次字符串分配，AOT 侧读数 218ns 里有一半是拼接 ——
     *   测出来的不是 hash 查找。改成从 `$keys` 数组取现成字符串。
     *   真实代码里也是这个形状（`get_str(189)` 那种常量键）。
     */
    private static function arrStr(int $n, array $keys): int
    {
        $a = [];
        for ($i = 0; $i < $n; $i++) {
            $a[$keys[$i & 0xFF]] = $i & 0xFF;
        }

        $s = 0;
        for ($i = 0; $i < $n; $i++) {
            $s += $a[$keys[$i & 0xFF]];
        }

        return $s;
    }

    /** L05 可变对象分配 + 字段写读（`RenderBox` 的 `$this->size = ...` 形状）。 */
    private static function objNew(int $n): int
    {
        $s = 0;
        for ($i = 0; $i < $n; $i++) {
            $o = new MicroNode($i, $i + 1);
            $o->w = (float) $i;
            $s += (int) $o->w;
        }

        return $s;
    }

    /** L06 不可变值对象分配（`Size` / `BoxConstraints`，本仓 32 个 readonly 之一）。 */
    private static function valNew(int $n): int
    {
        $s = 0;
        for ($i = 0; $i < $n; $i++) {
            $v = new MicroSize((float) $i, (float) ($i + 1));
            $s += (int) $v->width;
        }

        return $s;
    }

    /**
     * L07 `mixed` 拆箱。
     *
     * ★ 形参写 `mixed` 而不是让 `$m = $i` 推成 int：tpc 的形参层对 `mixed` 生成
     *   `php::Var`（`docs/reference/aot-constraints.md` §2.2 证据一），这才真的在测装箱/拆箱。
     *   如果写成 `$m = $i`，AOT 会直接推成 `php::Int`，测出来的是 1.0× —— 没意义。
     */
    private static function mixedVar(int $n, mixed $seed): int
    {
        $s = 0;
        for ($i = 0; $i < $n; $i++) {
            $m = $seed;
            $s += (int) $m;
        }

        return $s;
    }

    /** L08 同类 final 方法调用（`$box->layout()` 之外的"已知接收者"调用）。 */
    private static function methodCall(int $n): int
    {
        $o = new MicroNode(3, 5);
        $s = 0;
        for ($i = 0; $i < $n; $i++) {
            $s += $o->plus($i);
        }

        return $s;
    }

    /**
     * L09 接口方法调用（**多态**）。
     *
     * ★ 从数组取实现再调：数组元素在 tpc 里是 `php::Var`，方法调用只能走
     *   `typephp_call_method` —— 这正是 `visitChildren()` / `performLayout()`
     *   在编译产物里的形态。用 `$sh = $a; $sh = $b;` 直接赋值会撞静态类型
     *   （同一变量先当 `MicroNode` 再当 `MicroOther`）。
     */
    private static function virtCall(int $n): int
    {
        $shapes = [new MicroNode(3, 5), new MicroOther(2)];
        $s = 0;
        for ($i = 0; $i < $n; $i++) {
            $sh = $shapes[$i & 1];
            $s += $sh->scaled($i);
        }

        return $s;
    }

    /**
     * L10 闭包**创建**+ 调用。
     *
     * ★ 这一级对的是 `RenderBox::layout()` 第 ⑦ 步：
     *   `$this->visitChildren(function (RenderObject $child) use ($dx, $dy) {...})`
     *   —— 每层 layout 都**新造一个绑定闭包**。`use ($i)` 是故意的：
     *   不捕获的闭包可能被提成常量，测不出分配。
     */
    private static function closure(int $n): int
    {
        $s = 0;
        for ($i = 0; $i < $n; $i++) {
            $f = static function () use ($i): int {
                return $i * 3 + 1;
            };
            $s += $f();
        }

        return $s;
    }

    /** L11 整树遍历：数组读 + 多态虚调用 + 属性读（≈ `tickAnimations` / `collect`）。 */
    private static function treeWalk(int $n, array $nodes): int
    {
        $s = 0;
        $cnt = \count($nodes);
        for ($r = 0; $r < $n; $r++) {
            for ($i = 0; $i < $cnt; $i++) {
                $sh = $nodes[$i];
                $s += $sh->scaled($i);
            }
        }

        return $s;
    }

    /**
     * L12 **复刻 `RenderBox::layout()` 的调用/分配序列**。
     *
     * 序列（与 `src/Layout/RenderBox.php:266` 一一对应）：
     *   `tighten()` → 新约束 · `$inner = $constraints`（引用计数）·
     *   `performLayout()` → 虚调用且**返回新值对象** · `constrain()` → 又一个新值对象。
     *
     * ★ 这是全表最关键的一级：如果它的比值 ≈ 宏观 `layout` 的 1.73×，
     *   那"慢"就完全由这条**调用+分配形状**解释掉了，与"树大""装箱"都无关。
     *   如果它明显低于 1.73×，说明还有别的成分没被这 13 级覆盖 —— 那也是个结论。
     */
    private static function mimicLayout(int $n): int
    {
        $box = new MicroNode(3, 5);
        $s = 0;
        for ($i = 0; $i < $n; $i++) {
            $c = new MicroSize((float) ($i & 0xFF), 100.0);   // ① tighten
            $inner = $c;                                       // ② 传下去
            $measured = $box->measure($inner, 0.5);            // ③ performLayout（分配）
            $boxSize = self::constrain($c, $measured);         // ④ constrain（分配）
            $s += (int) $boxSize->width;
        }

        return $s;
    }

    /** `BoxConstraints::constrain()` 的形状：比较 + 夹取 + **新实例**。 */
    private static function constrain(MicroSize $c, MicroSize $m): MicroSize
    {
        $w = $m->width > $c->width ? $c->width : $m->width;
        $h = $m->height > $c->height ? $c->height : $m->height;

        return new MicroSize($w, $h);
    }
}
