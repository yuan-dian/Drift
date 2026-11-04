<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

/**
 * **AOT 与解释器的几何等价裁判**：跑两侧、逐页比、不一致时自己定位到节点。
 *
 * ★ 它取代的不是"某个函数"，是**一段人肉流程**（2026-11-04 晚）：
 *
 * ```text
 * bash probes/frame_tax_aot/build.sh
 * PROBE_SECTION=geom ./frame_tax_aot_probe.exe | grep -P '\t' > build/geom-aot.tsv
 * php tools/geom-fingerprint.php --tsv > build/geom-interp.tsv
 * diff build/geom-interp.tsv build/geom-aot.tsv        ← 人看
 * ...红了？GEOM_PAGE=X 再来一次，手工对齐两个 detail 文件找第一个不同的行
 * ```
 *
 * 那四步里，**只有最后一步是"判断"**，前四步都是"搬运"。判据的搬运工序越多，
 * 它就越不像判据 —— 一个月不跑，没人记得怎么跑；红了也没人肯手工对齐 2000 行。
 *
 * ⇒ 收成一条命令，退出码说话。**红了要说清"哪个节点、哪一轴、两侧各是多少"**，
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
 * ★ 分层诊断（只在红的时候付成本）：
 *   ① 判据层：页名序列 / 逐页数值 / 节点下界 / PHP 版本 / 解释器确定性
 *   ② 归因层：红的页先跑**第二次 AOT** —— 若 AOT 自己两次就不同，那是
 *      "AOT 侧不确定"，不是"两侧不等价"。分不清这两件事，红就等于白红。
 *   ③ 定位层：两侧 `GeomFingerprint::detail()` 逐行比，报**第一个**不同的节点
 *      （后面的全部偏移，报出来只是噪音）。
 *
 * @internal 量具专用，不进公共 API
 */
final class AotEquivalence
{
    /** 页面档的下界：27 页共 2278 个 `RenderBox`，留 12% 余量抓"渲染成空" */
    public const MIN_BOXES = 2000;
    public const MIN_PAGES = 25;

    /** @var list<array{page: string, hash: string, nodes: string, sumW: string, sumH: string}> */
    private array $aotRows = [];
    private string $aotPhp = '';
    private string $aotRaw = '';

    /** @var list<array{tag: string, ok: bool, msg: string}> */
    private array $checks = [];

    /** @var list<string> 红了的页（定位层的输入） */
    private array $badPages = [];

    public function __construct(
        private readonly string $exe,
        private readonly string $cwd,
    ) {
    }

    /**
     * 跑完整个流程并返回退出码（0 等价 / 1 不等价 / 2 无法判定）。
     */
    public function verify(bool $verbose = true): int
    {
        // ── 前提：AOT 产物在不在 ─────────────────────────────────────
        if (!\is_file($this->exe)) {
            // ★ 没有产物**不等于什么都验不了**：E②（解释器确定性）不需要 AOT。
            //   让它照常跑 —— 确定性是等价性的**前提**，而且它独立有价值：
            //   `DEBT` §35.6 那条"同进程连跑两档会互相污染"就是它的另一个面。
            //   一次 1.2s，没有理由不给。
            $a = GeomFingerprint::allPages();
            $b = GeomFingerprint::allPages();
            $this->checkDeterminism($a, $b);

            if ($verbose) {
                echo "=== AOT 等价性：无法判定（未构建）===\n\n";
                echo '找不到 AOT 产物：' . $this->exe . "\n";
                echo "先构建（约 1m）：\n  bash probes/frame_tax_aot/build.sh\n\n";
                echo "— 不依赖构建的那条先验 —\n";
                foreach ($this->checks as $c) {
                    echo ($c['ok'] ? '  ok   ' : '  FAIL ') . $c['tag'] . ' ' . $c['msg'] . "\n";
                }
                echo "\n--- 等价性未验（E③/E④/E⑤ 需要 AOT 产物）---\n";
            }

            return 2;
        }

        $t0 = \hrtime(true);
        if (!$this->collectAot()) {
            return 2;
        }
        $aotMs = (\hrtime(true) - $t0) / 1_000_000.0;

        // ── 判据层 ────────────────────────────────────────────────
        $t1 = \hrtime(true);
        $interpA = GeomFingerprint::allPages();
        $interpB = GeomFingerprint::allPages();
        $interpMs = (\hrtime(true) - $t1) / 1_000_000.0 / 2.0;

        $this->compare($interpA, $interpB);

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
        if ($fail > 0 && $verbose && $this->badPages !== []) {
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
        $this->aotRaw = $raw;
        $this->aotRows = self::parseTsv($raw);
        $this->aotPhp = self::parsePhp($raw);

        return true;
    }

    /**
     * 跑一次 AOT 产物，返回 stdout+stderr（失败时 null）。
     */
    private function execAot(): ?string
    {
        $env = \getenv();
        if (!\is_array($env)) {
            $env = [];
        }
        $env['PROBE_SECTION'] = 'geom';

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

    /**
     * 从混着标题/判据的输出里抽出 TSV 行（5 列且首列是页名）。
     *
     * @return list<array{page: string, hash: string, nodes: string, sumW: string, sumH: string}>
     */
    private static function parseTsv(string $raw): array
    {
        $rows = [];
        foreach (\explode("\n", $raw) as $line) {
            $line = \rtrim($line, "\r");
            if (\substr_count($line, "\t") !== 4) {
                continue;
            }
            $c = \explode("\t", $line);
            // hash 是 32 位十六进制 —— 用它当"这一行确实是数据"的判据，
            // 比"列数够"更硬（detail 行的类名里不会有 tab，但斜杠路径可能）。
            if (\strlen($c[1]) !== 32 || !\ctype_xdigit($c[1])) {
                continue;
            }
            $rows[] = ['page' => $c[0], 'hash' => $c[1], 'nodes' => $c[2], 'sumW' => $c[3], 'sumH' => $c[4]];
        }

        return $rows;
    }

    private static function parsePhp(string $raw): string
    {
        return \preg_match('/^php=(\S+)/m', $raw, $m) === 1 ? $m[1] : '?';
    }

    // ─────────────────────────────────────────────────────────────
    // 判据
    // ─────────────────────────────────────────────────────────────

    /**
     * @param list<array{page: string, hash: string, nodes: int, sumW: float, sumH: float}> $a
     * @param list<array{page: string, hash: string, nodes: int, sumW: float, sumH: float}> $b
     */
    private function compare(array $a, array $b): void
    {
        $namesA = \array_column($a, 'page');
        $namesB = \array_column($b, 'page');
        $namesAot = \array_column($this->aotRows, 'page');

        // E① 清单：**三方**都要一致 —— 解释器的两次 + AOT。
        //   ★ 只比"解释器的两次"会漏掉 AOT 多产出的页；只比"解释器 vs AOT"
        //     会漏掉解释器自身的不稳定。三方一致才是"同一批页、同一棵树"。
        $sameList = $namesA === $namesB && $namesA === $namesAot;
        $enough = \count($a) >= self::MIN_PAGES && \count($this->aotRows) >= self::MIN_PAGES;
        $why = '';
        if ($namesA !== $namesB) {
            $why = '解释器两次挂载的页清单不同';
        } elseif ($namesA !== $namesAot) {
            $only = \array_merge(
                \array_diff($namesA, $namesAot),
                \array_diff($namesAot, $namesA)
            );
            $why = 'AOT 与解释器页清单不同：' . \implode('、', \array_slice($only, 0, 6));
        } elseif (!$enough) {
            $why = '页数不足 ' . self::MIN_PAGES;
        }
        $this->checks[] = [
            'tag' => 'E①',
            'ok' => $sameList && $enough,
            'msg' => $sameList && $enough
                ? \sprintf('三方清单一致（%d 页，≥%d）', \count($a), self::MIN_PAGES)
                : $why,
        ];

        $this->checkDeterminism($a, $b);

        // E③ 等价性：**核心判据**
        $diff = $this->pageDiffs($a);
        $this->badPages = \array_keys($diff);
        $this->checks[] = [
            'tag' => 'E③',
            'ok' => $diff === [],
            'msg' => $diff === []
                ? \sprintf(
                    '%d 页 / %d 个 RenderBox 逐值相同（宽、高、x、y 各三位小数）',
                    \count($a),
                    self::nodeTotal($a)
                )
                : \sprintf(
                    '%d 页不等价：%s%s',
                    \count($diff),
                    \implode('、', \array_slice($this->badPages, 0, 6)),
                    \count($diff) > 6 ? '…（共 ' . \count($diff) . ' 页）' : ''
                ),
        ];

        // E④ 下界自证：E③ 是"逐值相同"，**两棵树都空也会相同**。
        //   ★ 判**两侧各自的**合计，不是只判解释器：AOT 侧若一行都没解析出来
        //     （exe 输出格式变了），E③ 会因为"全部缺页"而红，那是量具坏了不是代码不等价 ——
        //     这条把两种情况分开报。
        $totalI = self::nodeTotal($a);
        $totalA = 0;
        foreach ($this->aotRows as $r) {
            $totalA += (int) $r['nodes'];
        }
        $this->checks[] = [
            'tag' => 'E④',
            'ok' => $totalI >= self::MIN_BOXES && $totalA >= self::MIN_BOXES,
            'msg' => ($totalI >= self::MIN_BOXES && $totalA >= self::MIN_BOXES)
                ? \sprintf('两侧节点合计 %d / %d（≥%d）—— 页面真建起来了', $totalI, $totalA, self::MIN_BOXES)
                : \sprintf(
                    '节点数不足：解释器 %d / AOT %d（≥%d）—— 页面渲染成空，或 AOT 输出解析失败',
                    $totalI,
                    $totalA,
                    self::MIN_BOXES
                ),
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
     * E② 确定性：**排在等价性之前** —— 同一侧自己都跑不出一致的数，
     * "两侧不同"就无从归因（`DEBT` §36.3 的冷启动 / GC 教训是同一件事）。
     *
     * ★ 它不需要 AOT 产物 ⇒ 无 exe 时也照跑。这是本工具唯一"永远能验"的判据。
     *
     * @param list<array{page: string, hash: string, nodes: int, sumW: float, sumH: float}> $a
     * @param list<array{page: string, hash: string, nodes: int, sumW: float, sumH: float}> $b
     */
    private function checkDeterminism(array $a, array $b): void
    {
        $why = '';
        $n = \min(\count($a), \count($b));
        $ok = \count($a) === \count($b);
        if (!$ok) {
            $why = '两次挂载的页数不同：' . \count($a) . ' / ' . \count($b);
        } else {
            for ($i = 0; $i < $n; $i++) {
                if (GeomFingerprint::tsvLine($a[$i]) !== GeomFingerprint::tsvLine($b[$i])) {
                    $ok = false;
                    $why = '首次不等的页：' . $a[$i]['page'];
                    break;
                }
            }
        }

        $this->checks[] = [
            'tag' => 'E②',
            'ok' => $ok,
            'msg' => $ok
                ? \sprintf('解释器侧连跑两次逐值相同（%d 页，布局无隐藏状态）', \count($a))
                : '解释器侧两次挂载结果不同 —— ' . $why . '（先修确定性，再谈等价）',
        ];
    }

    /**
     * 逐页逐列比，返回 `[页名 => [列 => '解释器 / AOT']]`。
     *
     * ★ 报"哪一列不同"而不是"hash 不同"：`sumW` 差 94 能直接指方向，
     *   `hash 3f2a…` 不能。定位层再往下钻到节点。
     *
     * @param list<array{page: string, hash: string, nodes: int, sumW: float, sumH: float}> $interp
     * @return array<string, array<string, string>>
     */
    private function pageDiffs(array $interp): array
    {
        $byPage = [];
        foreach ($this->aotRows as $r) {
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
            $cols = [];
            if ($i['hash'] !== $a['hash']) {
                $cols['hash'] = $i['hash'] . ' / ' . $a['hash'];
            }
            if ((string) $i['nodes'] !== $a['nodes']) {
                $cols['节点数'] = (string) $i['nodes'] . ' / ' . $a['nodes'];
            }
            if (\sprintf('%.3f', $i['sumW']) !== $a['sumW']) {
                $cols['宽和'] = \sprintf('%.3f', $i['sumW']) . ' / ' . $a['sumW'];
            }
            if (\sprintf('%.3f', $i['sumH']) !== $a['sumH']) {
                $cols['高和'] = \sprintf('%.3f', $i['sumH']) . ' / ' . $a['sumH'];
            }
            if ($cols !== []) {
                $out[$page] = $cols;
            }
        }

        return $out;
    }

    /** @param list<array{nodes: int}> $rows */
    private static function nodeTotal(array $rows): int
    {
        $n = 0;
        foreach ($rows as $r) {
            $n += $r['nodes'];
        }

        return $n;
    }

    // ─────────────────────────────────────────────────────────────
    // 归因 / 定位
    // ─────────────────────────────────────────────────────────────

    /**
     * 红了之后：先分清"AOT 自己飘"还是"两侧真不同"，再定位到第一个不同节点。
     */
    private function diagnose(): void
    {
        // ★ 一行都没解析出来 ⇒ 逐页定位是**在错误的前提上工作**：
        //   每页都会说"AOT 0 个节点"，27 遍噪音。先说清前提。
        if ($this->aotRows === []) {
            echo "\n--- 归因 ---\n";
            echo "  AOT 侧没解析出任何一页 —— 产物不对（这是 `frame_tax_aot` 的 exe 吗？），\n";
            echo "  或输出格式变了（`GeomFingerprint::tsvLine` 与探针里的解析不一致）。\n";
            echo "  ⇒ 跳过逐页定位：那会在错的前提上逐页说废话。\n";

            return;
        }

        echo "\n--- 归因：AOT 侧是不是自己就不稳定 ---\n";
        $second = $this->execAot();
        if ($second === null) {
            echo "  第二次跑 AOT **起不来** —— 归因中断（这不是\"两次不同\"，别混为一谈）\n";

            return;
        }
        $rows2 = self::parseTsv($second);
        $stable = \count($rows2) === \count($this->aotRows);
        if (!$stable) {
            echo '  两次解析出的页数不同：' . \count($this->aotRows) . ' / ' . \count($rows2) . "\n";
        }
        if ($stable) {
            foreach ($this->aotRows as $k => $r) {
                $r2 = $rows2[$k];
                if ($r['hash'] !== $r2['hash'] || $r['sumW'] !== $r2['sumW'] || $r['sumH'] !== $r2['sumH']) {
                    $stable = false;
                    echo '  AOT 两次不同：' . $r['page'] . '（' . $r['sumW'] . ' → ' . $r2['sumW'] . "）\n";
                    break;
                }
            }
        }
        echo $stable
            ? "  AOT 连跑两次逐值相同 ⇒ 差异是**两侧**的，继续定位\n"
            : "  AOT 自己两次就不同 ⇒ 先修 AOT 侧的不确定性，别去比两侧\n";

        if (!$stable) {
            return;
        }

        echo "\n--- 定位：第一个不同的节点 ---\n";
        foreach (\array_slice($this->badPages, 0, 3) as $page) {
            $this->locatePage($page);
        }
        if (\count($this->badPages) > 3) {
            echo '  （还有 ' . (\count($this->badPages) - 3) . " 页没定位；先修好前 3 页）\n";
        }
    }

    private function locatePage(string $page): void
    {
        $root = GeomFingerprint::mountPage($page);
        $interp = $root === null ? [] : GeomFingerprint::detail($root);
        $aot = $this->detailAot($page);

        echo "\n  【" . $page . '】解释器 ' . \count($interp) . ' 个节点 / AOT ' . \count($aot) . " 个节点\n";

        if ($aot === []) {
            echo "    AOT 侧没产出这一页的明细（GEOM_PAGE 没生效？）\n";

            return;
        }

        if (\count($interp) !== \count($aot)) {
            // 结构就不同 —— 逐行比会满屏错位，先说结论
            $n = \min(\count($interp), \count($aot));
            echo '    两侧节点数不同，前 ' . $n . " 个逐个比：\n";
            for ($i = 0; $i < $n; $i++) {
                if ($interp[$i] !== $aot[$i]) {
                    echo '      第 ' . ($i + 1) . " 个起：\n";
                    echo '        解释器  ' . $interp[$i] . "\n";
                    echo '        AOT     ' . $aot[$i] . "\n";

                    return;
                }
            }
            echo '      前 ' . $n . " 个全同 ⇒ 差异是长度（多出/少掉一棵子树）\n";

            return;
        }

        $diffs = [];
        foreach ($interp as $i => $line) {
            if ($line !== $aot[$i]) {
                $diffs[] = $i;
            }
        }

        if ($diffs === []) {
            echo "    逐行全同 —— 差异不在节点上（在 \`nodes\`/\`sumW\` 之外？）\n";

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

        echo '    ' . \count($interp) . ' 个节点里 ' . \count($diffs) . " 个不同";
        if (\count($diffs) > 1) {
            echo '（第 ' . ($first + 1) . '–' . (\end($diffs) + 1) . ' 个）';
        }
        echo "\n";

        self::showPair($interp, $aot, $first, '最浅');
        if ($deepest !== $first) {
            self::showPair($interp, $aot, $deepest, '最深 ★ 源头更可能在这');
        }
    }

    /** @param list<string> $interp @param list<string> $aot */
    private static function showPair(array $interp, array $aot, int $i, string $label): void
    {
        echo '    ' . $label . '（第 ' . ($i + 1) . ' 个，深度 ' . self::depthOf($interp[$i]) . "）：\n";
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
    private function detailAot(string $page): array
    {
        $env = \getenv();
        if (!\is_array($env)) {
            $env = [];
        }
        $env['PROBE_SECTION'] = 'geom';
        $env['GEOM_PAGE'] = $page;

        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @\proc_open([$this->exe], $desc, $pipes, $this->cwd, $env);
        if (!\is_resource($proc)) {
            return [];
        }
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);

        // detail 行形如 `  RenderText   384.000 x  14.400 @ 20.000,80.000`（缩进 = 深度）
        $rows = [];
        foreach (\explode("\n", $out . $err) as $line) {
            $line = \rtrim($line, "\r");
            if (\preg_match('/^\s*\S+\s+(-|\d+\.\d+ x\s+-?\d+\.\d+ @)/', $line) === 1) {
                $rows[] = $line;
            }
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────
    // 报告
    // ─────────────────────────────────────────────────────────────

    /**
     * @param list<array{page: string, hash: string, nodes: int, sumW: float, sumH: float}> $interp
     */
    private function report(array $interp, float $aotMs, float $interpMs): void
    {
        $size = \filesize($this->exe);
        echo "=== AOT 等价性：几何指纹（" . \count($interp) . " 页）===\n\n";
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
            ? '--- ' . \count($this->checks) . " 项全过 —— AOT 与解释器几何逐值等价 ---\n"
            : '--- FAIL ' . $fail . ' / ' . \count($this->checks) . " ---\n");
    }
}
