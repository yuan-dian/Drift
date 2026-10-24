<?php
declare(strict_types=1);

namespace Drift\Dev\DocRef\Checks;

use Drift\Dev\DocRef\Check;
use Drift\Dev\DocRef\DocSet;
use Drift\Dev\Lint\Diagnostic;

/**
 * 跨册 § 引用断链（ARCH-REVIEW-2026-10-24 §7 M2 第二条）。
 *
 * 判据（实测两种形态，2026-10-24 全仓扫描）：
 *   形态 A  反引号包住 .md 路径 + §：`` `docs/AOT-CONSTRAINTS.md` §2.1 ``
 *   形态 B  反引号整包：            `` `DESIGN.md §23.2` ``
 *   ⇒ 目标 .md 存在 **且** 该文件有对应编号的标题（`## §23.2` 或 `## 2.1` 都认）。
 *
 * ★ 诚实的边界：
 *   · **裸 `§4.2` 不查**（无册名 ⇒ 无从定位，全仓 157+143+136… 处）。
 *     统计为 `no-target` 计数 —— 强行猜目标就是假红。
 *   · 只查**跨册**引用（反引号里带 .md）；册内自引（`见 §7`）语义上指向本文，
 *     不查（那需要"当前文档上下文"，静态做不了且误报率高）。
 *   · **missing = warn**：`MIXED_CPP_PHP.md` / `HISTORY.md` 这类是 **typephp
 *     外部仓引用**（本仓无从验证其标题编号），与"本仓文档被删的真断链"
 *     静态分不开 ⇒ 降级留痕，不挡闸门（同 `FileLineRefs` 判据）。
 *   · **标题对不上 = error**（本仓内可确定的错：册子改名/拆走/编号漂移）。
 *   · **通配引用跳过**（`docs/EVAL-design-v7-*.md`）：匹配集是动态的，
 *     静态无从校验 —— 要校验就写实际文件名。
 *   · **表格描述不是引用**：PATTERN_A 的 30 字符窗口不许跨 `|`（表格
 *     单元格边界）—— `` `DESIGN.md` | 方案本体…§23 已拆出 `` 这种是
 *     描述性文字，抓它必假红（2026-10-24 实测两处）。
 */
final class SectionRefs implements Check
{
    /**
     * 形态 A：`…`x/y.md`… §2.1`（反引号闭后 30 字符内出现 §编号）。
     * ★ `u` 修饰符必须：无 `u` 时 PCRE 逐字节处理，`§`（UTF-8 两字节）会被
     *   拆开匹配 ⇒ 行为不可预期（`DocSet::headings()` 的同款坑）。
     * ★ 窗口禁 `|`：不跨表格单元格边界（见类 docblock 末条）。
     */
    private const PATTERN_A = '/`([^`]+\.md)`[^`§|\n]{0,30}§\s*(\d+(?:\.\d+)*)/u';

    /** 形态 B：`…`x.md §23.2`…`（反引号内整体） */
    private const PATTERN_B = '/`([^`]+\.md)\s*§\s*(\d+(?:\.\d+)*)`/u';

    public function name(): string
    {
        return 'section-refs';
    }

    public function summary(): string
    {
        return '跨册 § 引用：`…x.md` §2.1` 与 `x.md §23.2` 两种形态，目标册与标题编号都须存在';
    }

    public function run(DocSet $docs): array
    {
        $found = [];
        $noTarget = 0;

        foreach ($docs->docFiles() as $doc) {
            $inFence = false;
            $lines = file($docs->root() . '/' . $doc, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                $lineno = $i + 1;

                if (preg_match('/^\s*(?:```|~~~)/', $line) === 1) {
                    $inFence = !$inFence;
                    continue;
                }
                if ($inFence) {
                    continue;
                }

                $hits = [];
                // 先 B 后 A：B 是 A 的子形态（反引号内含 §），先匹配整体更准
                if (preg_match_all(self::PATTERN_B, $line, $mb, PREG_SET_ORDER) > 0) {
                    foreach ($mb as $hit) {
                        $hits[] = [$hit[1], $hit[2]];
                    }
                }
                if (preg_match_all(self::PATTERN_A, $line, $ma, PREG_SET_ORDER) > 0) {
                    foreach ($ma as $hit) {
                        // 跳过已被 B 吃掉的（同 file 同编号）
                        $dup = false;
                        foreach ($hits as [$f, $n]) {
                            if ($f === $hit[1] && $n === $hit[2]) {
                                $dup = true;
                                break;
                            }
                        }
                        if (!$dup) {
                            $hits[] = [$hit[1], $hit[2]];
                        }
                    }
                }

                foreach ($hits as [$ref, $section]) {
                    // 通配引用（`docs/EVAL-*.md`）：匹配集动态，静态无从校验
                    if (str_contains($ref, '*') || str_contains($ref, '?')) {
                        continue;
                    }
                    [$status, $target] = $docs->resolve($ref);
                    if ($status === 'external') {
                        continue;
                    }
                    if ($status === 'missing') {
                        // ★ warn 不是 error：外部仓引用（typephp 的 HISTORY.md /
                        //   MIXED_CPP_PHP.md…）本仓无从验证其标题编号，与"本仓
                        //   文档被删的真断链"静态分不开 ⇒ 降级留痕不挡路。
                        $found[] = new Diagnostic(
                            $this->name(),
                            Diagnostic::WARN,
                            $doc,
                            $lineno,
                            "§ 引用目标 `{$ref}` 不在本仓（外部仓引用或已删文档 —— 标题对不上判据不适用）"
                        );
                        continue;
                    }
                    if ($status === 'ambiguous') {
                        $found[] = new Diagnostic(
                            $this->name(),
                            Diagnostic::ERROR,
                            $doc,
                            $lineno,
                            "§ 引用目标 `{$ref}` 有多个候选（{$target}）—— 写全路径"
                        );
                        continue;
                    }
                    if (!in_array(DocSet::normalizeSection($section), $docs->headings($target), true)) {
                        $found[] = new Diagnostic(
                            $this->name(),
                            Diagnostic::ERROR,
                            $doc,
                            $lineno,
                            "`{$ref}` 没有 §{$section} 这一节（标题编号对不上）"
                        );
                    }
                }
            }
        }

        if ($noTarget > 0) {
            fwrite(STDOUT, "  （跳过 {$noTarget} 处无册名 § 引用：静态无从定位）\n");
        }

        return $found;
    }

    public function selftest(): void
    {
        $docs = new DocSet(dirname(__DIR__, 3));

        // 反例必须红：`DESIGN.md` 没有 §999 这一节
        $line = '见 `DESIGN.md` §999 的裁决';
        if (preg_match_all(self::PATTERN_A, $line, $m, PREG_SET_ORDER) !== 1) {
            throw new \RuntimeException('selftest: 形态 A 正则没抓到已知违规');
        }
        [$status, $target] = $docs->resolve($m[0][1]);
        if ($status !== 'ok') {
            throw new \RuntimeException("selftest: DESIGN.md 解析失败（{$status}）");
        }
        if (in_array(DocSet::normalizeSection($m[0][2]), $docs->headings($target), true)) {
            throw new \RuntimeException('selftest: DESIGN.md 竟有 §999 —— 反例不是反例，判据失效');
        }

        // 正例必须绿：`DESIGN.md` §0 真实存在（`## §0 一页结论`）
        [$status2, $target2] = $docs->resolve('DESIGN.md');
        if ($status2 !== 'ok' || !in_array('0', $docs->headings($target2), true)) {
            throw new \RuntimeException('selftest: DESIGN.md §0 应存在（正例不成立 ⇒ 判据本身有问题）');
        }

        // 形态 B 也要自证
        $lineB = '见 `DESIGN.md §0` 的裁决';
        if (preg_match_all(self::PATTERN_B, $lineB, $mb, PREG_SET_ORDER) !== 1) {
            throw new \RuntimeException('selftest: 形态 B 正则没抓到');
        }
        if (!in_array(DocSet::normalizeSection($mb[0][2]), $docs->headings($docs->resolve($mb[0][1])[1]), true)) {
            throw new \RuntimeException('selftest: 形态 B 正例不成立');
        }

        // 表格描述不是引用：`| `DESIGN.md` | 描述…§99 |` 不许匹配（窗口禁跨 |）
        $tableLine = '| `DESIGN.md` | 方案本体（**已瘦身**：§23 已拆出） | 什么时候读 |';
        if (preg_match_all(self::PATTERN_A, $tableLine, $mt, PREG_SET_ORDER) !== 0) {
            throw new \RuntimeException('selftest: 表格描述文字被当成 § 引用（窗口跨了单元格边界）');
        }

        // 通配引用跳过：`docs/EVAL-*.md` §1 不该进 hits（匹配集动态）
        $wildLine = '见 `docs/EVAL-design-v7-*.md` §1 的结论';
        if (preg_match_all(self::PATTERN_A, $wildLine, $mw, PREG_SET_ORDER) !== 1) {
            throw new \RuntimeException('selftest: 通配引用正则没抓到（该在 run() 里被跳过）');
        }

        // missing → warn 的输入保证：typephp 外部仓文档解析为 missing
        if ($docs->resolve('MIXED_CPP_PHP.md')[0] !== 'missing') {
            throw new \RuntimeException('selftest: MIXED_CPP_PHP.md 应解析为 missing（外部仓引用）');
        }

        // `_archive` 双语义：显式路径可引用（标题真实存在），但不参与裸名索引
        [$st, $path] = $docs->resolve('docs/_archive/changelog-v2.md');
        if ($st !== 'ok' || !in_array('24.1', $docs->headings($path), true)) {
            throw new \RuntimeException('selftest: docs/_archive/changelog-v2.md §24.1 应可显式引用');
        }
        [$st2, $cands] = $docs->resolve('04-布局.md');
        if ($st2 !== 'ambiguous' || str_contains($cands, '_archive')) {
            throw new \RuntimeException("selftest: 裸名 04-布局.md 的候选混进了 _archive（{$st2}: {$cands}）");
        }
    }
}
