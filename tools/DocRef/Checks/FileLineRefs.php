<?php
declare(strict_types=1);

namespace Drift\Dev\DocRef\Checks;

use Drift\Dev\DocRef\Check;
use Drift\Dev\DocRef\DocSet;
use Drift\Dev\Lint\Diagnostic;

/**
 * `file:line` 引用越界 + 裸文件名歧义（ARCH-REVIEW-2026-10-24 §7 M2 第一条）。
 *
 * 判据（文档里形如 `Element.php:159-175` / `src/Widget/Text.php:282` 的引用）：
 *   ① 文件在仓库内**存在**（裸 basename 走 `DocSet::resolve()` 白名单解析）
 *   ② 引用**起始行 ≤ 文件总行数** —— 范围引用（`159-175`）只查起始行：
 *      "159-175 那段注释"是语义描述不是精确锚点，但起始行越界必炸
 *
 * ★ 诚实的边界（静态无从判断，别假装能判）：
 *   · **越界 / 歧义 = error**（本仓内可确定的错：行号漂移 / 裸名多候选）。
 *   · **missing = warn**：`Translator.php:1010` 这类是 **typephp / Drift3
 *     外部仓引用**（本仓无从验证其行号），与"本仓文件被删的真死链"静态
 *     分不开 ⇒ 降级留痕，不挡闸门。
 *   · **外部仓引用不查**（显式 `typephp/` 前缀 / `D:/` 绝对路径）——
 *     统计为 `external` 计数，连 warn 都不算。
 *   · **`docs/_archive/` 整体排除**：归档是历史快照，引用有效性不维护。
 *   · 代码块内跳过（示例里的 `$x->foo()` 不是引用）。
 *   · `.md:line` 形态同样查（文档互引也带行号时）。
 */
final class FileLineRefs implements Check
{
    /** 代码块围栏（``` 或 ~~~），块内不是引用 */
    private const FENCE = '/^\s*(?:```|~~~)/';

    /** file:line 或 file:line-line（php/md/cc/h/js/ts/json/yml） */
    private const REF = '/([\w.\/-]+\.(?:php|md|cc|h|js|ts|json|yml)):(\d+)(?:-(\d+))?/';

    public function name(): string
    {
        return 'file-line-refs';
    }

    public function summary(): string
    {
        return '文档里的 file:line 引用：文件须存在、起始行不越界、裸文件名不许歧义';
    }

    public function run(DocSet $docs): array
    {
        $found = [];
        $external = 0;

        foreach ($docs->docFiles() as $doc) {
            $inFence = false;
            $lines = file($docs->root() . '/' . $doc, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                $lineno = $i + 1;

                if (preg_match(self::FENCE, $line) === 1) {
                    $inFence = !$inFence;
                    continue;
                }
                if ($inFence) {
                    continue;
                }

                if (preg_match_all(self::REF, $line, $m, PREG_SET_ORDER) === 0) {
                    continue;
                }
                foreach ($m as $hit) {
                    [$all, $ref, $start] = $hit;
                    [$status, $target] = $docs->resolve($ref);

                    if ($status === 'external') {
                        ++$external;
                        continue;
                    }
                    if ($status === 'ambiguous') {
                        $found[] = new Diagnostic(
                            $this->name(),
                            Diagnostic::ERROR,
                            $doc,
                            $lineno,
                            "裸文件名 `{$ref}` 有多个候选（{$target}）—— 写全路径"
                        );
                        continue;
                    }
                    if ($status === 'missing') {
                        // ★ warn 不是 error：`Translator.php:1010` 这类引用指的是
                        //   **typephp / Drift3 外部仓**（本仓无从验证其行号）——
                        //   与"本仓文件被删的真死链"静态分不开，降级留痕不挡路。
                        //   越界/歧义仍是 error（本仓内可确定的错）。
                        $found[] = new Diagnostic(
                            $this->name(),
                            Diagnostic::WARN,
                            $doc,
                            $lineno,
                            "引用 `{$ref}` 不在本仓（外部仓引用或已删文件 —— 越界判据不适用）"
                        );
                        continue;
                    }

                    $total = $docs->lines($target);
                    if ($total !== null && $start > $total) {
                        $found[] = new Diagnostic(
                            $this->name(),
                            Diagnostic::ERROR,
                            $doc,
                            $lineno,
                            "引用 `{$all}` 越界：{$target} 只有 {$total} 行"
                        );
                    }
                }
            }
        }

        if ($external > 0) {
            fwrite(STDOUT, "  （跳过 {$external} 处外部仓引用：typephp/ 与绝对路径，本仓无从验证）\n");
        }

        return $found;
    }

    public function selftest(): void
    {
        $docs = new DocSet(dirname(__DIR__, 3));

        // 反例必须红：`src/Widget/Text.php` 远不到 99999 行
        $bad = '`src/Widget/Text.php:99999`';
        if (preg_match_all(self::REF, $bad, $m, PREG_SET_ORDER) !== 1) {
            throw new \RuntimeException('selftest: 正则没抓到已知违规引用');
        }
        [$status, $target] = $docs->resolve($m[0][1]);
        if ($status !== 'ok') {
            throw new \RuntimeException("selftest: src/Widget/Text.php 解析失败（{$status}）");
        }
        $total = $docs->lines($target);
        if ($total === null || $total >= 99999) {
            throw new \RuntimeException('selftest: Text.php 行数异常，判据不可信');
        }

        // 正例必须绿：第 1 行永远合法
        if (1 > $total) {
            throw new \RuntimeException('selftest: 正例不成立');
        }
    }
}
