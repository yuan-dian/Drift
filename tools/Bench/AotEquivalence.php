<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

/**
 * **AOT 与解释器的等价裁判**：跑两侧、逐页逐通道比、不一致时自己定位到源头。
 *
 * ★ 它取代的不是"某个函数"，是**一段人肉流程**（2026-11-04 晚）：
 *
 * ```text
 * bash probes/frame_tax_aot/build.sh
 * PROBE_SECTION=geom ./frame_tax_aot_probe.exe | grep -P '\t' > build/geom-aot.tsv
 * php tools/fingerprint.php --tsv > build/geom-interp.tsv
 * diff build/geom-interp.tsv build/geom-aot.tsv        ← 人看
 * ...红了？GEOM_PAGE=X 再来一次，手工对齐两个 detail 文件找第一个不同的行
 * ```
 *
 * 那四步里，**只有最后一步是"判断"**，前四步都是"搬运"。判据的搬运工序越多，
 * 它就越不像判据 —— 一个月不跑，没人记得怎么跑；红了也没人肯手工对齐 2000 行。
 *
 * ⇒ 收成一条命令，退出码说话。**红了要说清"哪个节点/哪条命令、两侧各是多少"**，
 *   否则"红了"这个信息本身没有价值。
 *
 * ★ 三态退出码（不是两态）：
 *   - `0` 等价 —— 判据成立
 *   - `1` 不等价 —— 真问题
 *   - `2` **无法判定** —— AOT 产物不存在 / 跑不起来（构建要 1m20s，不能默认要求）
 *
 *   ★ 为什么不是"不存在就跳过（exit 0）"：`skip` 冒充 `pass` 是本仓反复栽的坑
 *   （`DEBT` §35.3「少一张网」vs「这张网本来就是空的」必须能分辨）。
 *   调用方拿到 2 就知道"这条判据今天没验"，而不是"验过了没问题"。
 *
 * ★ 两个通道（2026-11-04）各自回答一个问题，**判据也分开**：
 *   - `E③` 几何：`RenderBox` 逐值 —— 布局算对了吗
 *   - `D①` 绘制：命令流逐值 —— 画对了吗（颜色/画笔/裁剪/文字排版内部）
 *   合成一条会让"红了"失去方向：几何错和颜色错是两种修法。
 *
 * ★ 分层诊断（只在红的时候付成本）：
 *   ① 判据层：清单 / 逐值 / 下界 / 版本 / 解释器确定性
 *   ② 归因层：红的通道先跑**第二次 AOT** —— 若 AOT 自己两次就不同，那是
 *      "AOT 侧不确定"，不是"两侧不等价"。分不清这两件事，红就等于白红。
 *   ③ 定位层：两侧明细逐行比，几何报**最浅+最深**，绘制报**首处+末处**
 *      （命令是线性的，一条 `PUSH_CLIP` 错了会污染它之后的一整段）。
 *
 * @internal 量具专用，不进公共 API
 */
final class AotEquivalence
{
    public const MIN_PAGES = 25;

    /** @var array<string, list<array{page: string, hash: string, v1: string, v2: string, v3: string}>> */
    private array $aotRows = [];
    private string $aotPhp = '';

    /** @var list<array{tag: string, ok: bool, msg: string}> */
    private array $checks = [];

    /** @var array<string, list<string>> 红了的页（定位层的输入），按通道分 */
    private array $badPages = [];

    public function __construct(
        private readonly string $exe,
        private readonly string $cwd,
    ) {
        foreach (Fingerprint::CHANNELS as $ch) {
            $this->aotRows[$ch] = [];
            $this->badPages[$ch] = [];
        }
    }

    /**
     * 跑完整个流程并返回退出码（0 等价 / 1 不等价 / 2 无法判定）。
     */
    public function verify(bool $verbose = true): int
    {
        // ★ 解释器侧**只挂一遍 27 页**，两通道一起出（`Fingerprint::snapshot()`）——
        //   几何与绘制是同一帧的两个投影，"两帧恰好相同"要另证，同帧不需要。
        $t1 = \hrtime(true);
        $interpA = Fingerprint::snapshot();
        $interpB = Fingerprint::snapshot();
        $interpMs = (\hrtime(true) - $t1) / 1_000_000.0 / 2.0;
        $unknown = Fingerprint::unknownTypes();

        // ── 前提：AOT 产物在不在 ─────────────────────────────────────
        if (!\is_file($this->exe)) {
            // ★ 没有产物**不等于什么都验不了**：E②（解释器确定性）不需要 AOT。
            //   让它照常跑 —— 确定性是等价性的**前提**，而且它独立有价值：
            //   `DEBT` §35.6 那条"同进程连跑两档会互相污染"就是它的另一个面。
            $this->checkDeterminism($interpA, $interpB);
            $this->checks[] = [
                'tag' => 'E⑥',
                'ok' => $unknown === [],
                'msg' => $unknown === []
                    ? '槽类型全部已知（指纹没有静默漏掉的命令类型）'
                    : '有槽类型没序列化：' . \implode('、', $unknown) . '（指纹对它们是瞎的）',
            ];

            if ($verbose) {
                echo "=== AOT 等价性：无法判定（未构建）===\n\n";
                echo '找不到 AOT 产物：' . $this->exe . "\n";
                echo "先构建（约 1m）：\n  bash probes/frame_tax_aot/build.sh\n\n";
                echo "— 不依赖构建的先验 —\n";
                foreach ($this->checks as $c) {
                    echo ($c['ok'] ? '  ok   ' : '  FAIL ') . $c['tag'] . ' ' . $c['msg'] . "\n";
                }
                echo "\n--- 等价性未验（E①/E③/E④/D①/D②/E⑤ 需要 AOT 产物）---\n";
            }

            return 2;
        }

        $t0 = \hrtime(true);
        if (!$this->collectAot()) {
            return 2;
        }
        $aotMs = (\hrtime(true) - $t0) / 1_000_000.0;

        // ── 判据层 ────────────────────────────────────────────────
        $this->compare($interpA, $interpB, $unknown);

        $fail = 0;
        foreach ($this->checks as $c) {
            if (!$c['ok']) {
                $fail++;
            }
        }

        if ($verbose) {
            $this->report($interpA, $aotMs, $interpMs);
        }

        // ── 归因 + 定位层（只在红时）──────────────────────────────
        if ($fail > 0 && $verbose) {
            $this->diagnose();
        }

        return $fail > 0 ? 1 : 0;
    }

    /** @return list<array{tag: string, ok: bool, msg: string}> */
    public function checks(): array
    {
        return $this->checks;
    }

    // ─────────────────────────────────────────────────────────────
    // 采集
    // ─────────────────────────────────────────────────────────────

    private function collectAot(): bool
    {
        $raw = $this->execAot();
        if ($raw === null) {
            return false;
        }
        $this->aotRows = Fingerprint::parse($raw);
        $this->aotPhp = self::parsePhp($raw);

        return true;
    }

    /**
     * 跑一次 AOT 产物，返回 stdout+stderr（失败时 null）。
     */
    private function execAot(string $page = '', string $ch = ''): ?string
    {
        $env = \getenv();
        if (!\is_array($env)) {
            $env = [];
        }
        $env['PROBE_SECTION'] = 'fingerprint';
        if ($page !== '') {
            $env['FP_PAGE'] = $page;
            $env['FP_CHANNEL'] = $ch;
        } else {
            // 全表模式下别让上一轮的环境变量漏进来
            unset($env['FP_PAGE'], $env['FP_CHANNEL'], $env['GEOM_PAGE']);
        }

        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @\proc_open([$this->exe], $desc, $pipes, $this->cwd, $env);
        if (!\is_resource($proc)) {
            return null;
        }

        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);

        return $out . $err;
    }

    private static function parsePhp(string $raw): string
    {
        return \preg_match('/^php=(\S+)/m', $raw, $m) === 1 ? $m[1] : '?';
    }

    // ─────────────────────────────────────────────────────────────
    // 判据
    // ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, list<array{page: string, hash: string, v1: string, v2: string, v3: string}>> $a
     * @param array<string, list<array{page: string, hash: string, v1: string, v2: string, v3: string}>> $b
     * @param list<string> $unknown
     */
    private function compare(array $a, array $b, array $unknown): void
    {
        $this->checkLists($a);
        $this->checkDeterminism($a, $b);

        // 两个通道各一组判据：**分开报**，因为"几何错"和"绘制错"是两种修法。
        $this->channelChecks('E③', Fingerprint::GEOM, 'E④', $a[Fingerprint::GEOM]);
        $this->channelChecks('D①', Fingerprint::PAINT, 'D②', $a[Fingerprint::PAINT]);

        // E⓪ 覆盖自证：未知槽类型时两侧都序列化成 `?类名`，指纹**仍然相等** ——
        //   判据静默地少覆盖一类命令，而报告全绿。这是"绿了其实没验"，比红更难发现。
        $this->checks[] = [
            'tag' => 'E⑥',
            'ok' => $unknown === [],
            'msg' => $unknown === []
                ? '槽类型全部已知（7 类值对象 + 标量，无静默降级）'
                : '有槽类型没序列化：' . \implode('、', $unknown) . ' —— 指纹对它们是瞎的',
        ];

        // E⑤ 前提自证：版本不同则"等价"这个词没有所指
        $sameVer = $this->aotPhp === \PHP_VERSION;
        $this->checks[] = [
            'tag' => 'E⑤',
            'ok' => $sameVer,
            'msg' => $sameVer
                ? '两侧 PHP 版本相同（' . \PHP_VERSION . '）'
                : 'PHP 版本不同：解释器 ' . \PHP_VERSION . ' / AOT ' . $this->aotPhp . '（等价性无从谈起）',
        ];
    }

    /**
     * 生成某通道的"逐值 + 下界"两条判据（顺序即报告顺序）。
     *
     * ★ 两条数的是**不同的列**，别混：逐值那条报通道总量（节点数 / 命令数），
     *   下界那条报"东西真的存在"的自证量（节点数 / 落笔数）——
     *   命令数可以有 9000，落笔只有 1384，而"9000 条 TRANSLATE 一笔没画"
     *   照样能过"命令数 ≥ 1000"。
     *
     * @param list<array{page: string, hash: string, v1: string, v2: string, v3: string}> $interp
     */
    private function channelChecks(string $tagEq, string $ch, string $tagMin, array $interp): void
    {
        $diff = $this->pageDiffs($ch, $interp);
        $this->badPages[$ch] = \array_keys($diff);

        $totalI = 0;
        foreach ($interp as $r) {
            $totalI += (int) $r['v1'];
        }
        $totalA = 0;
        foreach ($this->aotRows[$ch] as $r) {
            $totalA += (int) $r['v1'];
        }

        $this->checks[] = [
            'tag' => $tagEq,
            'ok' => $diff === [],
            'msg' => $diff === []
                ? \sprintf('%d 页 / %d %s逐值相同', \count($interp), $totalI, Fingerprint::unit($ch))
                : \sprintf(
                    '%d 页不等价：%s%s',
                    \count($diff),
                    \implode('、', \array_slice($this->badPages[$ch], 0, 6)),
                    \count($diff) > 6 ? '…（共 ' . \count($diff) . ' 页）' : ''
                ),
        ];

        $b = Fingerprint::bound($ch);
        $selfI = 0;
        foreach ($interp as $r) {
            $selfI += (int) $r[$b[0]];
        }
        $selfA = 0;
        foreach ($this->aotRows[$ch] as $r) {
            $selfA += (int) $r[$b[0]];
        }

        // ★ 下界判两侧的合计，不是只判解释器：AOT 侧若一行都没解析出来
        //   （exe 输出格式变了），逐值判据会因为"全部缺页"而红 ——
        //   那是量具坏了不是代码不等价，这条把两种情况分开报。
        $this->checks[] = [
            'tag' => $tagMin,
            'ok' => $selfI >= $b[1] && $selfA >= $b[1],
            'msg' => ($selfI >= $b[1] && $selfA >= $b[1])
                ? \sprintf('两侧%s合计 %d / %d（≥%d）—— 页面真建起来了', $b[2], $selfI, $selfA, $b[1])
                : \sprintf(
                    '%s不足：解释器 %d / AOT %d（≥%d）—— 页面渲染成空，或 AOT 输出解析失败',
                    $b[2],
                    $selfI,
                    $selfA,
                    $b[1]
                ),
        ];
    }

    /**
     * E① 清单：**五方**都要一致 —— 解释器的两个通道 + AOT 的两个通道，再加页数下界。
     *
     * ★ 只比"解释器的两次"会漏掉 AOT 多产出的页；只比"解释器 vs AOT"会漏掉
     *   解释器自身的不稳定。而**两个通道的清单也必须相同** —— 它们是同一帧的两个
     *   投影，页数不一样说明有一侧挂载失败了（那会让逐值判据"缺页"而不是"不同"）。
     *
     * @param array<string, list<array{page: string, hash: string, v1: string, v2: string, v3: string}>> $a
     */
    private function checkLists(array $a): void
    {
        $base = \array_column($a[Fingerprint::GEOM], 'page');
        $lists = [
            '解释器·绘制' => \array_column($a[Fingerprint::PAINT], 'page'),
            'AOT·几何' => \array_column($this->aotRows[Fingerprint::GEOM], 'page'),
            'AOT·绘制' => \array_column($this->aotRows[Fingerprint::PAINT], 'page'),
        ];

        $why = '';
        foreach ($lists as $label => $list) {
            if ($list === $base) {
                continue;
            }
            $only = \array_merge(\array_diff($base, $list), \array_diff($list, $base));
            $why = $label . ' 与 解释器·几何 页清单不同：'
                . \implode('、', \array_slice($only === [] ? ['顺序不同'] : $only, 0, 6));

            break;
        }

        $enough = \count($base) >= self::MIN_PAGES
            && \count($this->aotRows[Fingerprint::GEOM]) >= self::MIN_PAGES;
        if ($why === '' && !$enough) {
            $why = '页数不足 ' . self::MIN_PAGES . '（解释器 ' . \count($base)
                . ' / AOT ' . \count($this->aotRows[Fingerprint::GEOM]) . '）';
        }

        $this->checks[] = [
            'tag' => 'E①',
            'ok' => $why === '',
            'msg' => $why === ''
                ? \sprintf('五方清单一致（%d 页 × 2 通道 × 2 侧，≥%d）', \count($base), self::MIN_PAGES)
                : $why,
        ];
    }

    /**
     * E② 确定性：**排在等价性之前** —— 同一侧自己都跑不出一致的数，
     * "两侧不同"就无从归因（`DEBT` §36.3 的冷启动 / GC 教训是同一件事）。
     *
     * ★ 它不需要 AOT 产物 ⇒ 无 exe 时也照跑。这是本工具唯一"永远能验"的判据。
     *
     * @param array<string, list<array{page: string, hash: string, v1: string, v2: string, v3: string}>> $a
     * @param array<string, list<array{page: string, hash: string, v1: string, v2: string, v3: string}>> $b
     */
    private function checkDeterminism(array $a, array $b): void
    {
        $why = '';
        foreach (Fingerprint::CHANNELS as $ch) {
            if (\count($a[$ch]) !== \count($b[$ch])) {
                $why = $ch . ' 两次挂载的页数不同：' . \count($a[$ch]) . ' / ' . \count($b[$ch]);

                break;
            }
            $n = \count($a[$ch]);
            for ($i = 0; $i < $n; $i++) {
                if (!self::sameRow($a[$ch][$i], $b[$ch][$i])) {
                    $why = $ch . ' 首次不等的页：' . $a[$ch][$i]['page'];

                    break 2;
                }
            }
        }

        $this->checks[] = [
            'tag' => 'E②',
            'ok' => $why === '',
            'msg' => $why === ''
                ? \sprintf('解释器侧连跑两次逐值相同（%d 页 × 2 通道，布局与绘制都无隐藏状态）', \count($a[Fingerprint::GEOM]))
                : '解释器侧两次挂载结果不同 —— ' . $why . '（先修确定性，再谈等价）',
        ];
    }

    /**
     * 逐页比五列，返回 `[页名 => [列名 => '解释器 / AOT']]`。
     *
     * ★ 报"哪一列不同"而不是"hash 不同"：`落笔 1384 / 1384` 但在 `hash` 上不同
     *   能直接指方向（**同体积、不同内容**），一串 md5 不能。定位层再往下钻。
     *
     * @param list<array{page: string, hash: string, v1: string, v2: string, v3: string}> $interp
     * @return array<string, array<string, string>>
     */
    private function pageDiffs(string $ch, array $interp): array
    {
        $cols = Fingerprint::columns($ch);
        $byPage = [];
        foreach ($this->aotRows[$ch] as $r) {
            $byPage[$r['page']] = $r;
        }

        $out = [];
        foreach ($interp as $i) {
            $page = $i['page'];
            if (!isset($byPage[$page])) {
                $out[$page] = ['存在性' => '解释器有 / AOT 缺'];

                continue;
            }
            $a = $byPage[$page];
            $d = [];
            if ($i['hash'] !== $a['hash']) {
                $d['hash'] = $i['hash'] . ' / ' . $a['hash'];
            }
            if ($i['v1'] !== $a['v1']) {
                $d[$cols[0]] = $i['v1'] . ' / ' . $a['v1'];
            }
            if ($i['v2'] !== $a['v2']) {
                $d[$cols[1]] = $i['v2'] . ' / ' . $a['v2'];
            }
            if ($i['v3'] !== $a['v3']) {
                $d[$cols[2]] = $i['v3'] . ' / ' . $a['v3'];
            }
            if ($d !== []) {
                $out[$page] = $d;
            }
        }

        return $out;
    }

    /** @param array{page: string, hash: string, v1: string, v2: string, v3: string} $x */
    private static function sameRow(array $x, array $y): bool
    {
        return $x['hash'] === $y['hash']
            && $x['v1'] === $y['v1']
            && $x['v2'] === $y['v2']
            && $x['v3'] === $y['v3'];
    }

    // ─────────────────────────────────────────────────────────────
    // 归因 / 定位
    // ─────────────────────────────────────────────────────────────

    /**
     * 红了之后：先分清"AOT 自己飘"还是"两侧真不同"，再定位到第一个不同的位置。
     */
    private function diagnose(): void
    {
        // ★ 一行都没解析出来 ⇒ 逐页定位是**在错误的前提上工作**：
        //   每页都会说"AOT 0 行"，27 遍噪音。先说清前提。
        $empty = true;
        foreach (Fingerprint::CHANNELS as $ch) {
            if ($this->aotRows[$ch] !== []) {
                $empty = false;
            }
        }
        if ($empty) {
            echo "\n--- 归因 ---\n";
            echo "  AOT 侧没解析出任何一页 —— 产物不对（这是 `frame_tax_aot` 的 exe 吗？），\n";
            echo "  或输出格式变了（`Fingerprint::row` 与探针里的解析不一致）。\n";
            echo "  ⇒ 跳过逐页定位：那会在错的前提上逐页说废话。\n";

            return;
        }

        echo "\n--- 归因：AOT 侧是不是自己就不稳定 ---\n";
        $second = $this->execAot();
        if ($second === null) {
            echo "  第二次跑 AOT **起不来** —— 归因中断（这不是\"两次不同\"，别混为一谈）\n";

            return;
        }
        $rows2 = Fingerprint::parse($second);
        $unstable = '';
        foreach (Fingerprint::CHANNELS as $ch) {
            if (\count($rows2[$ch]) !== \count($this->aotRows[$ch])) {
                $unstable = $ch . ' 两次页数不同：' . \count($this->aotRows[$ch]) . ' / ' . \count($rows2[$ch]);

                break;
            }
            foreach ($this->aotRows[$ch] as $k => $r) {
                if (!self::sameRow($r, $rows2[$ch][$k])) {
                    $unstable = $ch . ' 两次不同：' . $r['page']
                        . '（' . $r['v1'] . ' → ' . $rows2[$ch][$k]['v1'] . '）';

                    break 2;
                }
            }
        }
        if ($unstable !== '') {
            echo '  ' . $unstable . "\n";
        }
        echo $unstable === ''
            ? "  AOT 连跑两次逐值相同 ⇒ 差异是**两侧**的，继续定位\n"
            : "  AOT 自己两次就不同 ⇒ 先修 AOT 侧的不确定性，别去比两侧\n";

        if ($unstable !== '') {
            return;
        }

        foreach (Fingerprint::CHANNELS as $ch) {
            if ($this->badPages[$ch] === []) {
                continue;
            }
            echo "\n--- 定位：" . $ch . " 通道 ---\n";
            foreach (\array_slice($this->badPages[$ch], 0, 3) as $page) {
                $this->locatePage($ch, $page);
            }
            if (\count($this->badPages[$ch]) > 3) {
                echo '  （还有 ' . (\count($this->badPages[$ch]) - 3) . " 页没定位；先修好前 3 页）\n";
            }
        }
    }

    private function locatePage(string $ch, string $page): void
    {
        $interp = Fingerprint::detail($ch, $page);
        $aot = $this->detailAot($ch, $page);

        echo "\n  【" . $page . '】' . $ch . '：解释器 ' . \count($interp) . ' / AOT ' . \count($aot) . " 行\n";

        if ($aot === []) {
            echo "    AOT 侧没产出这一页的明细（FP_PAGE 没生效？）\n";

            return;
        }

        $n = \min(\count($interp), \count($aot));
        $diffs = [];
        for ($i = 0; $i < $n; $i++) {
            if ($interp[$i] !== $aot[$i]) {
                $diffs[] = $i;
            }
        }

        if ($diffs === []) {
            echo \count($interp) === \count($aot)
                ? "    逐行全同 —— 差异不在明细上（在 hash 之外？）\n"
                : '    前 ' . $n . " 行全同 ⇒ 差异是长度（多出/少掉 "
                    . \abs(\count($interp) - \count($aot)) . " 行）\n";

            return;
        }

        echo '    ' . \count($interp) . ' 行里 ' . \count($diffs) . ' 行不同';
        if (\count($diffs) > 1) {
            echo '（第 ' . ($diffs[0] + 1) . '–' . (\end($diffs) + 1) . ' 行）';
        }
        echo "\n";

        if ($ch === Fingerprint::PAINT) {
            // ★ 命令流没有"深度"这条线索，但有**因果方向**：一条 `PUSH_CLIP`/`SAVE`
            //   错了会污染它之后的一整段。所以报"首处（从哪开始）"+"末处（到哪恢复）"，
            //   与几何的"最浅+最深"同构，但依据的是线性顺序而不是树深。
            self::showPair($interp, $aot, $diffs[0], '从这条开始不同', false);
            $last = \end($diffs);
            if ($last !== $diffs[0]) {
                self::showPair($interp, $aot, $last, '到这里还不同（之后恢复）', false);
            }

            return;
        }

        // ★ 报**两个**位置：最浅的（影响从哪开始）与最深的（源头更可能在这）。
        //   只报第一个 = 报根，而根是整棵子树尺寸的综合，它变了不说明什么。
        $first = $diffs[0];
        $deepest = $first;
        foreach ($diffs as $i) {
            if (self::depthOf($interp[$i]) > self::depthOf($interp[$deepest])) {
                $deepest = $i;
            }
        }

        self::showPair($interp, $aot, $first, '最浅', true);
        if ($deepest !== $first) {
            self::showPair($interp, $aot, $deepest, '最深 ★ 源头更可能在这', true);
        }
    }

    /** @param list<string> $interp @param list<string> $aot */
    private static function showPair(array $interp, array $aot, int $i, string $label, bool $withDepth): void
    {
        $where = '第 ' . ($i + 1) . ' 行';
        if ($withDepth) {
            $where .= '，深度 ' . self::depthOf($interp[$i]);
        }
        echo '    ' . $label . '（' . $where . "）：\n";
        echo '      解释器  ' . $interp[$i] . "\n";
        echo '      AOT     ' . $aot[$i] . "\n";
    }

    /** 行首缩进是两个空格一层（`GeomFingerprint::detail()` 的约定）。 */
    private static function depthOf(string $line): int
    {
        $spaces = \strlen($line) - \strlen(\ltrim($line, ' '));

        return \intdiv($spaces, 2);
    }

    /** @return list<string> */
    private function detailAot(string $ch, string $page): array
    {
        $raw = $this->execAot($page, $ch);
        if ($raw === null) {
            return [];
        }

        // 几何明细形如 `  RenderText   384.000 x  14.400 @ 20.000,80.000`（缩进 = 深度）
        // 绘制明细形如 `#87 PUSH_CLIP R:0.000,0.000,...`（序号 = 坐标，无深度）
        $pattern = $ch === Fingerprint::PAINT
            ? '/^#\d+ [A-Z_]/'
            : '/^\s*\S+\s+(-|\d+\.\d+ x\s+-?\d+\.\d+ @)/';

        $rows = [];
        foreach (\explode("\n", $raw) as $line) {
            $line = \rtrim($line, "\r");
            if (\preg_match($pattern, $line) === 1) {
                $rows[] = $line;
            }
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────
    // 报告
    // ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, list<array{page: string, hash: string, v1: string, v2: string, v3: string}>> $interp
     */
    private function report(array $interp, float $aotMs, float $interpMs): void
    {
        $size = \filesize($this->exe);
        echo "=== AOT 等价性：几何 + 绘制指纹（" . \count($interp[Fingerprint::GEOM]) . " 页 × 2 通道）===\n\n";
        echo 'exe      ' . $this->exe . '  (' . \sprintf('%.1f', ($size === false ? 0 : $size) / 1048576.0) . " MB)\n";
        echo '解释器   php ' . \PHP_VERSION . "\n";
        echo 'AOT      php ' . $this->aotPhp . "\n";
        echo '耗时     解释器 ' . \sprintf('%.0f', $interpMs) . 'ms ×2 / AOT ' . \sprintf('%.0f', $aotMs) . "ms\n\n";

        $fail = 0;
        foreach ($this->checks as $c) {
            echo ($c['ok'] ? '  ok   ' : '  FAIL ') . $c['tag'] . ' ' . $c['msg'] . "\n";
            if (!$c['ok']) {
                $fail++;
            }
        }

        echo "\n" . ($fail === 0
            ? '--- ' . \count($this->checks) . " 项全过 —— AOT 与解释器布局 + 绘制逐值等价 ---\n"
            : '--- FAIL ' . $fail . ' / ' . \count($this->checks) . " ---\n");
    }
}
