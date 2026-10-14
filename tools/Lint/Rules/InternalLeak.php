<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * §10.1.3：非框架代码引用 `@internal` 类型 ⇒ error，有豁免标注则降为 warn。
 *
 * ★ 判据是「**标注 + 前缀**」，不是命名空间白名单 —— 白名单这条路走不通
 *   （组合根必须引用平台实现；PHP 没有"包内私有"）。
 *
 * ★ 源码同编形态（`-m bin`）下 @internal 类型会被编进同一个二进制，不会有
 *   链接期错误 ⇒ **这条 lint 是唯一防线**。`-m lib` 下 `#[NoExport]` 让内部
 *   符号根本不进 ABI，编译器比 lint 更强（§10.1 末段）。
 */
final class InternalLeak implements Rule
{
    public function name(): string
    {
        return 'internal-leak';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return '非 Drift\\ 前缀的代码引用了 @internal 类型（有豁免标注则降为 warn）';
    }

    public function check(Project $project): array
    {
        $found = [];
        $seen = [];

        foreach ($project->files() as $file) {
            if (str_starts_with($file->namespace(), Project::FRAMEWORK_PREFIX)) {
                continue; // 框架内部互相引用内部类型是正常的
            }
            if ($project->isToolchain($file)) {
                // 工具链与框架同属一个包（§10.1.1：Drift\Dev\ → tools/），
                // 它不是"消费者" ⇒ 引用内部类型不算泄漏。
                continue;
            }

            $exempt = $file->hasInternalUseExemption();

            foreach ($file->references() as $reference) {
                if (!$project->isInternal($reference['name'])) {
                    continue;
                }
                // 一个 (文件, 目标) 只报一次 —— 同一类型在文件里出现多次不是多条问题
                $key = $file->path() . ':' . $reference['name'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $found[] = new Diagnostic(
                    $this->name(),
                    $exempt ? Diagnostic::WARN : Diagnostic::ERROR,
                    $file->path(),
                    $reference['line'],
                    sprintf(
                        '引用了 @internal 的 %s%s',
                        $reference['name'],
                        $exempt ? '（有 @internal-use-allowed 豁免，降为 warn）' : '（漏标 @api ⇒ 默认不进公共面）'
                    )
                );
            }
        }

        return $found;
    }
}
