<?php
declare(strict_types=1);

namespace Drift\Dev\DocRef\Checks;

use Drift\Dev\DocRef\Check;
use Drift\Dev\DocRef\DocSet;
use Drift\Dev\Lint\Diagnostic;

/**
 * markdown 相对链接死链。
 *
 * ★ **为什么这条优先做**：2026-10-24 文档归档（`design-proposals.md` 等移入
 *   `_archive/`）后，我是**手工**验了 `docs/00-README.md` 的 11 个链接、修了
 *   `DESIGN.md` 6 处悬空引用的 —— docref 本该干的就是这件事。归档/移动文档
 *   是高频操作，每次手工验一遍不可持续。
 *
 * 判据（实测形态，全仓 321 个相对链接 / 0 http / 0 anchor）：
 *   · `](X)`          同目录相对（297 个，主流形态）
 *   · `](../X)`       上一级（2 个）
 *   · `](dir/X)`      子目录（`architecture/X` / `design-v7/X` 等）
 *   · `](../../X)`    跨目录（`../../design-v7/X` 12 个）
 *   ⇒ 先按**相对文档自身所在目录**解析，失败再按**相对仓库根**解析
 *     （两种基准都试 ⇒ 不误报；都失败 ⇒ 死链）。
 *
 * ★ 诚实的边界：
 *   · http(s) / 纯 `#anchor` 跳过（无文件可查）。
 *   · 代码块内跳过（示例里的 `](...)` 不是真链接）。
 *   · **mailto: 等非文件协议跳过**。
 */
final class MarkdownLinks implements Check
{
    /** `](target)` —— 排除 `![...]` 图片与 `<...>` 自动链接 */
    private const LINK = '/(?<!!)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/';

    public function name(): string
    {
        return 'markdown-links';
    }

    public function summary(): string
    {
        return 'markdown 相对链接死链：先按文档所在目录解析，再按仓库根解析，两者都失败才报';
    }

    public function run(DocSet $docs): array
    {
        $found = [];
        $root = $docs->root();

        foreach ($docs->docFiles() as $doc) {
            $inFence = false;
            $lines = file($root . '/' . $doc, FILE_IGNORE_NEW_LINES) ?: [];
            $docDir = \dirname($doc);

            foreach ($lines as $i => $line) {
                $lineno = $i + 1;

                if (preg_match('/^\s*(?:```|~~~)/', $line) === 1) {
                    $inFence = !$inFence;
                    continue;
                }
                if ($inFence || preg_match_all(self::LINK, $line, $m) === 0) {
                    continue;
                }

                foreach ($m[1] as $target) {
                    // 非文件协议 / 纯锚点 ⇒ 无文件可查
                    if (preg_match('#^(?:https?|mailto|ftp|tel):#i', $target) === 1
                        || str_starts_with($target, '#')) {
                        continue;
                    }

                    // 去掉锚点后缀（`x.md#sec` ⇒ `x.md`）
                    $pathPart = explode('#', $target, 2)[0];
                    if ($pathPart === '') {
                        continue;
                    }

                    // 基准 1：文档所在目录；基准 2：仓库根
                    $cand1 = $this->join($docDir, $pathPart);
                    $cand2 = ltrim($pathPart, './');
                    if (is_file($root . '/' . $cand1) || is_file($root . '/' . $cand2)) {
                        continue;
                    }

                    $found[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::ERROR,
                        $doc,
                        $lineno,
                        "死链 `{$target}`（试过 {$cand1} 与 {$cand2}，都不存在）"
                    );
                }
            }
        }

        return $found;
    }

    public function selftest(): void
    {
        // 反例必须红：`./nope-does-not-exist.md` 两种基准都不存在
        $target = './nope-does-not-exist.md';
        $cand1 = $this->join('docs', $target);
        $cand2 = ltrim($target, './');
        if (is_file($cand1) || is_file($cand2)) {
            throw new \RuntimeException('selftest: 反例文件居然存在');
        }

        // 正例必须绿：`docs/00-README.md` 同目录的 `architecture/00-README.md` 真实存在
        if (!is_file('docs/architecture/00-README.md')) {
            throw new \RuntimeException('selftest: 正例文件缺失（architecture/00-README.md）');
        }
    }

    /** 目录 + 相对路径归一（处理 `..`/`.`，不 resolve 符号链接——仓库内无跨盘） */
    private function join(string $dir, string $rel): string
    {
        $base = $dir === '.' ? [] : explode('/', $dir);
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                \array_pop($base);
                continue;
            }
            $base[] = $seg;
        }

        return implode('/', $base);
    }
}
