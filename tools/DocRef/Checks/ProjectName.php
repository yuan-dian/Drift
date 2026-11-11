<?php
declare(strict_types=1);

namespace Drift\Dev\DocRef\Checks;

use Drift\Dev\DocRef\Check;
use Drift\Dev\DocRef\DocSet;
use Drift\Dev\Lint\Diagnostic;

/**
 * 项目名是 Drift；`Drift2` 只是本机目录名，不能当项目自称。
 *
 * 允许形态（都不是自称）：路径 / 目录名（`D:/TypePHP/Drift2`、`Drift2/cpp/skia/`）、
 * 外部历史文档文件名（反引号里的 `…-Drift2-….md`）。反引号内带路径分隔符或 `.md`
 * 的片段整段剔除；裸路径靠 `Drift2` 前后的分隔符排除。
 */
final class ProjectName implements Check
{
    /** 反引号内的路径 / 文件名片段 */
    private const PATH_SPAN = '/`[^`]*(?:\/|\\\\|\.md)[^`]*`/u';

    /** 前后都不是路径分隔符的 Drift2 = 项目自称 */
    private const BARE_NAME = '~(?<![\\\\/])Drift2(?![\\\\/])~u';

    public function name(): string
    {
        return 'project-name';
    }

    public function summary(): string
    {
        return '项目名统一为 Drift；Drift2 只允许作目录名 / 路径出现';
    }

    public function run(DocSet $docs): array
    {
        $found = [];
        foreach ($docs->docFiles() as $doc) {
            $inFence = false;
            $lines = file($docs->root() . '/' . $doc, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*(?:```|~~~)/', $line) === 1) {
                    $inFence = !$inFence;
                    continue;
                }
                if ($inFence || !$this->violates($line)) {
                    continue;
                }
                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $doc,
                    $i + 1,
                    '项目名是 Drift；Drift2 只是目录名，不应作为项目自称'
                );
            }
        }

        return $found;
    }

    public function selftest(): void
    {
        foreach (['# Drift2', 'Drift2 是一个 GUI 框架', '一律以 **`Drift2`** 为准'] as $bad) {
            if (!$this->violates($bad)) {
                throw new \RuntimeException('selftest: 项目自称没被咬出来 —— ' . $bad);
            }
        }
        foreach ([
            'cd /d/TypePHP/Drift2',
            '包在 `Drift2/cpp/skia/`',
            '`docs/analysis/17-Drift2-渲染与布局层-深挖.md`',
            '`21-成熟GUI分析与Drift2重构路线图.md`',
            '`D:/TypePHP/Drift2` 源码',
            '# Drift 设计',
        ] as $ok) {
            if ($this->violates($ok)) {
                throw new \RuntimeException('selftest: 合法形态被误报 —— ' . $ok);
            }
        }
    }

    private function violates(string $line): bool
    {
        $rest = preg_replace(self::PATH_SPAN, '', $line) ?? $line;

        return preg_match(self::BARE_NAME, $rest) === 1;
    }
}
