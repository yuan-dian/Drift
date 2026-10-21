<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;
use Drift\Dev\Lint\SourceQuery;

/**
 * 内建编译期注解必须写全限定名（`#[\Override]`，不是 `#[Override]`）。
 *
 * 背景：编译期 Attribute 位于**根命名空间**，并完全遵循 PHP 的类名解析规则
 * （`typephp-docs/zh-cn/compile-time-attributes.md` §命名空间规则）。
 * 于是命名空间内写 `#[Override]` ⇒ 解析成 `当前命名空间\Override` ——
 * **既不是内建注解，也没有那个类**：静默无效、能编译通过、`drift-build` 抓不到。
 *
 * 这与 `instanceof-missing-use` 是同一个坑（PHP 不报未导入类的名），
 * 而且更阴：`instanceof` 漏 use 至少还有"分支不执行"可观察，
 * 注解漏 `\` 的表现是"我以为加了保护"。
 *
 * 判据（三条，缺一不可）：
 *   ① 属性名（去参数）落在内建编译期注解表里；
 *   ② 该属性**没有写全限定名**（不是 `\X` 也不是 `X\Y` 里带前导 `\` 的形态）；
 *   ③ 解析（use 别名 / 同命名空间回退）后的 FQN **不等于**根命名空间的那个注解。
 * ⇒ 报 error。
 *
 * ★ 允许的两种正确写法：`#[\Override]`（写全）与 `use \Override;` 后写 `#[Override]`。
 *   `use ... as ...` 别名同理（`resolveName()` 统一处理）。
 * ★ 全局命名空间的文件不判（那里短名天然解析到根，写 `#[Override]` 就是对的）。
 * ★ 非内建名（`#[Deprecated]` / 业务自定义注解 / 第三方）不判 —— 静态无从区分
 *   "就想用本命名空间的类"与"漏了反斜杠"，宁漏勿误（误报会训练人忽略规则）。
 * ★ `#[X]` 里带参数的（`#[Validate(...)]` / `#[StdVector(T)]`）照样判 ——
 *   只读名字，不读参数。
 */
final class AttributeUnqualified implements Rule
{
    /**
     * 内建编译期注解表（`compile-time-attributes.md` §注解列表，23 个）。
     *
     * @var array<string, true> 小写名 => true
     */
    private const BUILTINS = [
        'noexport' => true,
        'methodsfor' => true,
        'getter' => true,
        'setter' => true,
        'with' => true,
        'printer' => true,
        'arrayable' => true,
        'notnull' => true,
        'notempty' => true,
        'validate' => true,
        'override' => true,
        'mustuse' => true,
        'immutable' => true,
        'stdvector' => true,
        'stdmap' => true,
        'stdorderedmap' => true,
        'stdlist' => true,
        'stddict' => true,
        'native' => true,
        'hot' => true,
        'cold' => true,
        'constructor' => true,
        'wasmexport' => true,
    ];

    public function name(): string
    {
        return 'attribute-unqualified';
    }

    public function serves(): string
    {
        return 'A17';
    }

    public function summary(): string
    {
        return '内建编译期注解必须写全限定名（#[\X]）—— 命名空间内漏 \ 会静默无效';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            if ($file->namespace() === '') {
                continue; // 全局命名空间 ⇒ 短名天然解析到根，不需要反斜杠
            }

            foreach ($this->attributes($file->tokens()) as [$name, $line]) {
                $short = strtolower($name);
                if (!isset(self::BUILTINS[$short])) {
                    continue; // 非内建 ⇒ 诚实的边界，不报
                }
                $resolved = $file->resolveName($name);
                // ★ 大小写不敏感：PHP 类名大小写不敏感，`use \OvErRiDe;` 同样有效
                if (strtolower($resolved) === $short) {
                    continue; // use \X; 导入过 ⇒ 解析落回根命名空间，正确
                }
                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $line,
                    sprintf(
                        '#[%s] 解析成 %s —— 内建编译期注解在**根命名空间**，漏 \ 会静默无效（能编译、drift-build 抓不到）。'
                        . '改成 #[\\%s] 或加 use \\%s;',
                        $name,
                        $resolved,
                        $name,
                        $name
                    )
                );
            }
        }

        return $found;
    }

    /**
     * 全部 `#[...]` 的属性名（去参数）+ 行号。
     *
     * @param list<mixed> $tokens
     * @return list<array{0: string, 1: int}>
     */
    private function attributes(array $tokens): array
    {
        $out = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_ATTRIBUTE) {
                continue;
            }

            $j = SourceQuery::nextIndex($tokens, $i + 1);
            if ($j === null) {
                continue;
            }
            $head = $tokens[$j];
            if (!is_array($head)) {
                continue;
            }
            // 写全了（`\X` 或 `\X\Y`）不会错，跳过
            if ($head[0] === T_NAME_FULLY_QUALIFIED || $head[0] === T_NS_SEPARATOR) {
                continue;
            }
            if ($head[0] !== T_STRING && $head[0] !== T_NAME_QUALIFIED) {
                continue;
            }

            $name = SourceFile::readName($tokens, $j);
            if ($name === '') {
                continue;
            }
            $out[] = [$name, $tok[2]];
        }

        return $out;
    }
}
