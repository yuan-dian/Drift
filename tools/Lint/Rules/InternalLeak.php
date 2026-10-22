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
 *
 * ★ **两级判据**（`07` §2.2）：①类型级（引用 `@internal` **类型** ⇒ error/warn）；
 *   ②方法级（调用 `@internal` **方法** ⇒ warn）。第二级存在的原因：引用了公共
 *   类型却调它的内部方法，类型表看不出来 —— `State::markDirty()` 被应用代码调了
 *   5 次、lint 全绿就是实例。
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
        return array_merge($this->checkTypes($project), $this->checkMethods($project));
    }

    /**
     * 类型级：非框架代码引用 `@internal` 类型（§10.1.3）。
     *
     * @return list<Diagnostic>
     */
    private function checkTypes(Project $project): array
    {
        $found = [];
        $seen = [];

        foreach ($project->files() as $file) {
            if ($project->isFramework($file)) {
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

    /**
     * 方法级：`@internal` 方法被非框架代码调用 ⇒ WARN（`07` §2.2 ②）。
     *
     * ★★ **绝不按方法名匹配**。`src/` 里有 93 个"只标了 @internal"的方法名，
     *   其中 `tick` / `start` / `finish` / `reset` / `box` / `slot` / `of` 这类
     *   到处重名 —— 按名字报会把应用自己的 `$ctrl->reset()` 全标成问题，
     *   那是把量具砸了（误报比漏报更贵：人会开始忽略这条规则）。
     *
     *   ⇒ 只报**接收者能静态解析**的调用：
     *     - `Cls::m()` / `self::m()` / `static::m()` / `parent::m()`
     *     - `$this->m()`（沿 `extends` 链找声明）
     *     - `$var->m()`（`$var` 的类型来自 `new` 赋值 / 参数类型 / 属性类型）
     *   解析不出来就**跳过**，不猜。
     *
     * ★ 报 WARN 不报 ERROR：方法级是"标记与用法打架"的提示，不是链接期风险。
     * ★ 文件级 `@internal-use-allowed:` 在这里是**完全跳过**（不是降级）——
     *   豁免了还在报告，豁免就没有意义。
     *
     * @return list<Diagnostic>
     */
    private function checkMethods(Project $project): array
    {
        $methods = [];   // class fqn => [小写方法名 => 标注]
        $parents = [];   // class fqn => 父类 fqn
        foreach ($project->files() as $file) {
            foreach ($file->methods() as $method) {
                $methods[$method['class']][strtolower($method['name'])] = [
                    'internal' => $method['internal'],
                    'api' => $method['api'],
                ];
            }
            foreach ($file->parents() as $child => $parent) {
                $parents[$child] = $parent;
            }
        }

        $found = [];
        foreach ($project->files() as $file) {
            if ($project->isFramework($file)) {
                continue; // 框架内部互调内部方法是正常的
            }
            if ($project->isToolchain($file)) {
                continue;
            }
            if ($file->hasInternalUseExemption()) {
                continue;
            }

            $varTypes = $file->varTypes();
            foreach ($file->calls() as $call) {
                $target = $call['class'];
                if ($target === null) {
                    $target = $call['receiver'] === '$this'
                        ? $call['owner']
                        : ($varTypes[$call['receiver']] ?? null);
                }
                if ($target === null || $target === '') {
                    continue;
                }
                $declaration = self::lookup($target, $call['method'], $methods, $parents);
                if ($declaration === null || !$declaration['internal'] || $declaration['api']) {
                    continue;
                }
                // ★ 只报**声明在框架类里**的内部方法。应用自己给自家方法标
                //   `@internal`（"同包私有"）是合法用法 —— 报它就是误报，
                //   实测 `apps/users` 有 3 处 `$this->mutate()` 撞在这条上。
                if (!$project->isFrameworkFqn($declaration['class'])) {
                    continue;
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::WARN,
                    $file->path(),
                    $call['line'],
                    sprintf(
                        '调用了 @internal 方法 %s::%s()（框架内部面；确需调用请在文件头标 @internal-use-allowed: <理由>）',
                        $declaration['class'],
                        $call['method']
                    )
                );
            }
        }

        return $found;
    }

    /**
     * 沿 `extends` 链找方法声明。
     *
     * 子类覆写优先：覆写没标 `@internal` 就说明它已经是应用自己的方法，不该报。
     *
     * @param array<string, array<string, array{internal: bool, api: bool}>> $methods
     * @param array<string, string> $parents
     * @return null|array{class: string, internal: bool, api: bool} 声明类 + 标注
     */
    private static function lookup(string $class, string $method, array $methods, array $parents): ?array
    {
        $lower = strtolower($method);
        $current = $class;
        for ($hop = 0; $hop < 32 && $current !== ''; $hop++) {
            if (isset($methods[$current][$lower])) {
                $hit = $methods[$current][$lower];
                $hit['class'] = $current;
                return $hit;
            }
            $current = $parents[$current] ?? '';
        }
        return null;
    }
}
