<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\ClassTable;
use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;
use Drift\Dev\Lint\SourceQuery;

/**
 * 不产渲染盒的控件不许声明修饰（G2：声明了就必须生效）。
 *
 * 背景：修饰（padding / 尺寸 / 背景…）是**盒**的属性 —— `Element::syncBox()`
 * 拿到盒之后才 `setMods()`。不产盒的控件（`StatelessWidget` / 不覆写
 * `createRenderObjectFromState()` 的 `StatefulWidget` / `Offstage` / `ThemeScope`）
 * 没有任何东西接收它们 ⇒ 静默不生效，直到运行期撞 §11.7 的守卫才炸。
 *
 * ★ 为什么需要这条静态规则：那个守卫是**运行期**的，只在"那一页真的被 build"
 *   时才触发 —— `showcase` 的第 21 页要滚动/点击才走到，于是 Fatal 出现在
 *   "用户点开某一页"而不是"提交前"。同一个坑已经踩过多次（`Card.php`
 *   的注释记着"7 个页面一点就炸"）。静态规则把这个发现点前移到 `lint`。
 *
 * 判据（三条同时满足才报）：
 *   ① 出现修饰方法调用（`->padding(…)` / `->width(…)` / `->background(…)` …）；
 *   ② 接收者的**静态类型**是本仓的 `Drift\Widget\Widget` 子类（外部类 / `Modifier`
 *      自己的同名方法不算）；
 *   ③ 该类型不产盒（继承 `RenderBoxWidget` 的产盒；`StatefulWidget` 子类若声明了
 *      `createRenderObjectFromState()` 也算产盒 —— 判不出来时**不报**，宁可漏报）。
 *
 * ★ 只认内联链（`X::make(…)->padding(…)`、`new X()->padding(…)`）与
 *   `Type $x; $x->padding(…)`；`$x = X::make()` 之后的变量调用**不追**
 *   （`varTypes()` 只认参数声明）—— 漏报可接受，误报不可接受。
 * ★ **行内豁免**：该行或前两行出现 `@nonbox-decoration-allowed:` + 理由即跳过。
 *   目前唯一的用户是 `smoke` 里那个**故意**撞守卫的负向用例 ——
 *   豁免必须写清"为什么这是有意的"，否则规则会被注释淹没。
 */
final class NonBoxDecoration implements Rule
{
    /**
     * `Widget` 上那些"写进 Modifier、要靠盒生效"的方法（小写）。
     *
     * ★ 不含 `semantics*` / `focusOrder` / `pointer` 等：它们是**控件自己**消费的
     *   声明，不产盒也生效（`SemanticsBuilder` 直接读 Widget）。
     *
     * @var list<string>
     */
    private const MODIFIERS = [
        'padding', 'paddingall', 'paddingonly', 'boxsize',
        'width', 'height', 'aspect',
        'background', 'border', 'radius', 'shadow', 'opacity', 'translate',
        'clip', 'centercontent',
        'grow', 'shrink', 'basis', 'alignself',
    ];

    private const WIDGET = 'Drift\\Widget\\Widget';
    private const RENDER_BOX_WIDGET = 'Drift\\Widget\\RenderBoxWidget';
    private const STATEFUL_WIDGET = 'Drift\\Widget\\StatefulWidget';

    public function name(): string
    {
        return 'nonbox-decoration';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return '不产渲染盒的控件不许声明修饰（运行期 §11.7 守卫的静态前置）—— 用 Padding::make(…) 包装';
    }

    public function check(Project $project): array
    {
        $table = ClassTable::fromProject($project);
        $all = $table->all();
        $out = [];

        foreach ($project->files() as $file) {
            $tokens = $file->tokens();
            $hits = SourceQuery::methodCalls($file, 0, count($tokens), self::MODIFIERS);
            if ($hits === []) {
                continue;
            }

            foreach ($hits as $hit) {
                $fqn = $this->receiverFqn($file, (int) $hit['index']);
                if ($fqn === null || !isset($all[$fqn])) {
                    continue;
                }
                // 同名方法在别的类上也有（`Modifier::padding()`）⇒ 只判 Widget 树内
                if (!$table->descendsFrom($fqn, self::WIDGET)) {
                    continue;
                }
                if (!$this->isBoxCapable($table, $fqn, (string) $hit['name'])) {
                    if ($this->hasExemption($file, (int) $hit['line'])) {
                        continue;
                    }
                    $out[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::ERROR,
                        $file->path(),
                        (int) $hit['line'],
                        sprintf(
                            '%s 不产渲染盒，`->%s()` 不会生效（运行期会 Fatal）—— 改用 `Padding::make(…)->child($x)` 或该控件自己的样式参数',
                            $fqn,
                            $hit['name'],
                        ),
                    );
                }
            }
        }

        return $out;
    }

    /**
     * 接收者表达式的静态类型（解析后 FQN）；判不出来 ⇒ null。
     *
     * 处理的形态：`X::make(…)` / `new X(…)` / `X::make(…)->foo(…)` 链。
     * 变量与函数返回值一律放弃（理由见类注释）。
     */
    private function receiverFqn(SourceFile $file, int $opIndex): ?string
    {
        $tokens = $file->tokens();
        $j = SourceQuery::prevIndex($tokens, $opIndex - 1);

        while ($j !== null) {
            $tok = $tokens[$j];

            if (!is_array($tok)) {
                if ($tok === ')') {
                    $open = SourceQuery::matchOpenBracket($tokens, $j);
                    if ($open === null) {
                        return null;
                    }
                    $beforeI = SourceQuery::prevIndex($tokens, $open - 1);
                    $before = $beforeI === null ? null : $tokens[$beforeI];
                    $beforeName = is_array($before)
                        && in_array($before[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);

                    if ($beforeName) {
                        // `X::make(…)` / `new X(…)` / `…->foo(…)`：`(` 属于调用
                        $j = $beforeI;
                        continue;
                    }
                    // 分组括号 `(new X())`：剥掉外层，从括号内最后一个 token 继续
                    $j = SourceQuery::prevIndex($tokens, $j - 1);
                    continue;
                }

                return null;
            }

            $id = $tok[0];

            if ($id === T_VARIABLE) {
                return null;   // `$x->padding()`：类型不在静态可确定范围内
            }

            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                $prevI = SourceQuery::prevIndex($tokens, $j - 1);
                $prev = $prevI === null ? null : $tokens[$prevI];
                $prevId = is_array($prev) ? $prev[0] : $prev;

                // `X::make(…)` —— 当前 token 是方法名，类名在 `::` 左边
                if ($prevId === T_DOUBLE_COLON) {
                    $clsI = SourceQuery::prevIndex($tokens, $prevI - 1);

                    return $clsI === null ? null : $this->nameAt($file, $clsI);
                }
                // `new X(…)`
                if ($prevId === T_NEW) {
                    return $this->nameAt($file, $j);
                }
                // `X::make(…)->foo(…)` 的外层：当前是 `foo`，继续往左找接收者
                if ($prevId === T_OBJECT_OPERATOR) {
                    $j = SourceQuery::prevIndex($tokens, $prevI - 1);
                    continue;
                }

                return $this->nameAt($file, $j);
            }

            if ($id === T_OBJECT_OPERATOR || $id === T_DOUBLE_COLON) {
                $j = SourceQuery::prevIndex($tokens, $j - 1);
                continue;
            }

            return null;
        }

        return null;
    }

    /** 该行或前两行是否带行内豁免标记（`@nonbox-decoration-allowed:`） */
    private function hasExemption(SourceFile $file, int $line): bool
    {
        $lines = explode("\n", $file->code());
        $from = max(0, $line - 3);

        for ($i = $from; $i < min(count($lines), $line); $i++) {
            if (str_contains($lines[$i], '@nonbox-decoration-allowed:')) {
                return true;
            }
        }

        return false;
    }

    /** 取某个 token 位置的类名并解析成 FQN（非名字 token ⇒ null） */    private function nameAt(SourceFile $file, int $index): ?string
    {
        $tokens = $file->tokens();
        $tok = $tokens[$index] ?? null;
        if (!is_array($tok)) {
            return null;
        }
        $id = $tok[0];
        if ($id !== T_STRING && $id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED) {
            return null;
        }

        return $file->resolveName($tok[1]);
    }

    /**
     * 这个类能否把修饰交给盒。
     *
     * 三种"能"：
     *   ① 继承 `RenderBoxWidget`（盒就是自己）；
     *   ② **自己声明了这个修饰方法** —— 覆写 = 自己消费（`Card` 是 `StatelessWidget`，
     *      但它把 `padding/shadow/background/…` 转发给内部代理盒 `SizedBox`）；
     *   ③ `StatefulWidget` 子类声明了 `createRenderObjectFromState()`
     *      （静态只看"有没有声明"就够；少报远好过多报）。
     */
    private function isBoxCapable(ClassTable $table, string $fqn, string $method): bool
    {
        $all = $table->all();

        if (isset($all[$fqn]['methods'][$method])) {
            return true;
        }

        if ($table->descendsFrom($fqn, self::RENDER_BOX_WIDGET)) {
            return true;
        }

        if (!$table->descendsFrom($fqn, self::STATEFUL_WIDGET)) {
            return false;
        }

        $seen = [];
        $queue = [$fqn];

        while ($queue !== []) {
            $current = array_pop($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            $info = $all[$current] ?? null;
            if ($info === null) {
                continue;
            }
            // 基类自己的默认实现恒返回 null ⇒ 不算"声明了"
            if ($current !== self::STATEFUL_WIDGET
                && isset($info['methods']['createrenderobjectfromstate'])) {
                return true;
            }
            foreach ($info['extends'] as $parent) {
                $queue[] = $parent;
            }
        }

        return false;
    }
}
