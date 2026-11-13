<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * 项目名是 `Drift`；本机工作目录名不能当项目自称（G2 命名纪律）。
 *
 * ★ S4 迁入：文档检查（docref / apidoc）随文档整体重构显式退役，但这条命名纪律
 *   不随文档体系一起消失 —— 原实现住 V1 文档检查层（S4 已删），
 *   判据逻辑（两条正则 + 代码围栏跳过 + 文档集范围）**逐字搬过来**，一行未改。
 *   「承诺未兑现却没人报错」是本项目反复吃亏的形状，见 S4 删除清单 §2.1。
 *
 * ★ 为什么不用 `Project::files()`：它只收 `.php`（`Project::phpFiles()` 按扩展名
 *   过滤），本规则要读的是 `.md`。所以自己枚举文档集，不改 `Project` 的语义，
 *   也不影响其余 32 条规则。范围与 V1 文档集枚举一致：
 *   `docs/**` + 根级四个入口 md，排除 `docs/_archive/`（历史快照不维护）。
 *
 * 允许形态（都不是自称）：路径 / 目录名、
 * 外部历史文档文件名（反引号里的 `…-…-….md`）。反引号内带路径分隔符或 `.md`
 * 的片段整段剔除；裸路径靠被检测字面量前后的分隔符排除。
 */
final class ProjectName implements Rule
{
    /** 反引号内的路径 / 文件名片段 */
    private const PATH_SPAN = '/`[^`]*(?:\/|\\\\|\.md)[^`]*`/u';

    /** 被检测字面量：前后都不是路径分隔符 ⇒ 项目自称（判据数据，不是自称） */
    private const BARE_NAME = '~(?<![\\\\/])Drift2(?![\\\\/])~u';

    public function name(): string
    {
        return 'project-name';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return '项目名统一为 Drift；本机目录名只允许作路径出现，不许作项目自称';
    }

    public function check(Project $project): array
    {
        $found = [];
        $root  = $project->root();

        foreach ($this->docFiles($root) as $doc) {
            $inFence = false;
            $lines   = file($root . '/' . $doc, FILE_IGNORE_NEW_LINES) ?: [];
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
                    '项目名是 Drift；本机目录名只应作路径出现，不应作为项目自称'
                );
            }
        }

        return $found;
    }

    /** @return list<string> 相对仓库根的 .md */
    private function docFiles(string $root): array
    {
        $out = [];
        foreach (['docs', 'DESIGN.md', 'README.md', 'CHANGELOG.md', 'AGENTS.md'] as $seed) {
            $abs = $root . '/' . $seed;
            if (is_file($abs)) {
                $out[] = $seed;
                continue;
            }
            if (!is_dir($abs)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'md') {
                    $out[] = substr(str_replace('\\', '/', $f->getPathname()), \strlen($root) + 1);
                }
            }
        }

        $out = array_values(array_filter(
            $out,
            static fn(string $p): bool => !str_starts_with($p, 'docs/_archive/')
        ));
        sort($out);

        return $out;
    }

    private function violates(string $line): bool
    {
        $rest = preg_replace(self::PATH_SPAN, '', $line) ?? $line;

        return preg_match(self::BARE_NAME, $rest) === 1;
    }
}
