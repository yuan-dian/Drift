<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\ClassTable;
use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * 单类页面里不许 `$this->widget()` 访问自己。
 *
 * 页面继承 `Page`（`State` 子类）后，`widget()` 返回的是宿主 `PageShell`，
 * 不再是页面本身。照旧写 `widget() instanceof XxxPage` 或读它的公开方法，
 * 拿到的是宿主类型 —— 编译期不报，运行期才炸在交互路径上，而金标快照
 * 往往覆盖不到那条路径。
 *
 * 判据：`Drift\Widget\Page` 的后代里出现 `$this->widget()` ⇒ ERROR。
 * 单类页面访问自己就是 `$this`，没有需要例外的形态。
 *
 * ★ 只对 `Page` 后代生效：走 `ReducerState` 的例外页里，State 经 `widget()`
 *   读壳是正常写法，它不继承 `Page`，不在本规则范围内。
 */
final class PageSelfWidget implements Rule
{
    private const PAGE = 'Drift\Widget\Page';

    public function name(): string
    {
        return 'page-self-widget';
    }

    public function serves(): string
    {
        return 'A15';
    }

    public function summary(): string
    {
        return 'Page 子类里不许 $this->widget() —— 它返回宿主 PageShell 而不是页面自己；访问自己用 $this';
    }

    public function check(Project $project): array
    {
        $found = [];
        $table = ClassTable::fromProject($project);

        foreach ($project->files() as $file) {
            $pageClasses = $this->pageClassesIn($file, $table);
            if ($pageClasses === []) {
                continue;
            }

            $tokens = $file->tokens();
            foreach (SourceQuery::methodCalls($file, 0, \count($tokens), ['widget']) as $hit) {
                if (!$this->isSelfReceiver($tokens, $hit['index'])) {
                    continue;
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $hit['line'],
                    sprintf(
                        '%s 里用了 $this->widget()：Page 是 State 子类，widget() 返回宿主 PageShell 而不是页面自己 —— 访问自己请用 $this',
                        \implode(' / ', $pageClasses),
                    ),
                );
            }
        }

        return $found;
    }

    /**
     * 本文件里声明的 Page 后代（短名，供报错文案）。
     *
     * @return list<string>
     */
    private function pageClassesIn(\Drift\Dev\Lint\SourceFile $file, ClassTable $table): array
    {
        $names = [];
        foreach (SourceQuery::classes($file) as $class) {
            $fqn = \ltrim((string) $class['fqn'], '\\');
            if ($fqn === self::PAGE || !$table->descendsFrom($fqn, self::PAGE)) {
                continue;
            }
            $names[] = (string) $class['name'];
        }

        return $names;
    }

    /** `->` 的接收者是不是 `$this`。 */
    private function isSelfReceiver(array $tokens, int $arrowIndex): bool
    {
        $recv = SourceQuery::prevIndex($tokens, $arrowIndex - 1);
        if ($recv === null || !\is_array($tokens[$recv])) {
            return false;
        }

        return $tokens[$recv][0] === T_VARIABLE && $tokens[$recv][1] === '$this';
    }
}
