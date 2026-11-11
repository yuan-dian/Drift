<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * 回调槽的形参个数必须与派发点一致（G2）。
 *
 * ★ 真事故：AOT 校验闭包实参个数、解释器忽略多余实参。`FocusManager::dispatchKey()`
 *   固定传 `(KeyEvent, Element)`，而 `Slider` 等控件的 `keyboard()` 只写一参 ⇒
 *   解释器 / lint / smoke 全绿，真机 `Fatal: expects exactly 1 argument, 2 given`
 *   被错误边界接住 ⇒ 整页错误占位。本仓 `DESIGN-TABLE-2026-10-26.md` 记过这个事故。
 *
 * ★ 期望值写死在下表，不读 `WidgetInteraction` 的 docblock —— 判据与实现不同源。
 * ★ 两种形态都管：① 调用点的内联闭包；② `$this->keyHandler()` /
 *   `self::rowKeyHandler()` 这类「返回闭包的间接工厂」。只扫调用点会放过后者。
 * ★ 诚实边界：间接形态按**本文件内方法名**关联工厂；变量传入（`$handler`）、
 *   跨文件工厂、首参不是闭包字面量也不是方法调用的形状不检查，不猜。
 */
final class CallbackArity implements Rule
{
    /** 槽 => 派发点固定传几个参数 */
    private const SLOTS = ['keyboard' => 2, 'pointer' => 2, 'hover' => 1];

    /** 槽 => 报错时给出的完整签名 */
    private const SIGNATURES = [
        'keyboard' => 'function (KeyEvent $event, Element $self)',
        'pointer' => 'function (PointerEvent $event, Element $self)',
        'hover' => 'function (bool $entered)',
    ];

    public function name(): string
    {
        return 'callback-arity';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return 'keyboard/pointer 回调必须收 2 个形参、hover 收 1 个 —— AOT 校验实参个数，少一个就整页错误占位';
    }

    public function check(Project $project): array
    {
        $found = [];
        foreach ($project->files() as $file) {
            $tokens = $file->tokens();
            $factories = self::factories($tokens);
            /** @var array<string, list<array{0: int, 1: string}>> $indirect 方法名 => [期望数, 槽] */
            $indirect = [];

            foreach (SourceQuery::methodCalls($file, 0, count($tokens), array_keys(self::SLOTS)) as $hit) {
                $slot = $hit['name'];
                $want = self::SLOTS[$slot];
                $nameI = SourceQuery::nextIndex($tokens, $hit['index'] + 1);
                if ($nameI === null) {
                    continue;
                }
                $open = SourceQuery::nextIndex($tokens, $nameI + 1);
                if ($open === null || $tokens[$open] !== '(') {
                    continue;
                }
                $close = SourceQuery::matchBracket($tokens, $open);
                if ($close === null) {
                    continue;
                }

                $first = SourceQuery::nextIndex($tokens, $open + 1);
                $fn = self::closureStart($tokens, $first);
                if ($fn !== null) {
                    $got = self::closureArity($tokens, $fn);
                    if ($got !== null && $got !== $want) {
                        $found[] = self::diagnostic($file->path(), $tokens[$fn][2], $slot, $want, $got, '内联闭包');
                    }
                    continue;
                }

                foreach (self::indirectNames($tokens, $open + 1, $close - 1) as $method) {
                    $indirect[$method][] = [$want, $slot];
                }
            }

            foreach ($indirect as $method => $uses) {
                foreach ($factories[$method] ?? [] as [$line, $got]) {
                    foreach ($uses as [$want, $slot]) {
                        if ($got !== $want) {
                            $found[] = self::diagnostic($file->path(), $line, $slot, $want, $got, '工厂方法 ' . $method . '() 返回的闭包');
                        }
                    }
                }
            }
        }

        return $found;
    }

    private static function diagnostic(
        string $file,
        int $line,
        string $slot,
        int $want,
        int $got,
        string $where,
    ): Diagnostic {
        return new Diagnostic(
            'callback-arity',
            Diagnostic::ERROR,
            $file,
            $line,
            sprintf(
                '->%s() 的%s只收 %d 个形参，派发点固定传 %d 个；AOT 按声明个数校验实参（多传即 Fatal）⇒ 整页错误占位。写成 %s',
                $slot,
                $where,
                $got,
                $want,
                self::SIGNATURES[$slot],
            ),
        );
    }

    /** 闭包的 token 下标；允许 `static function` / `static fn` */
    private static function closureStart(array $tokens, ?int $i): ?int
    {
        if ($i === null) {
            return null;
        }
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_STATIC) {
            $i = SourceQuery::nextIndex($tokens, $i + 1);
        }
        if ($i === null || !is_array($tokens[$i])
            || !in_array($tokens[$i][0], [T_FUNCTION, T_FN], true)) {
            return null;
        }

        return $i;
    }

    /** 闭包参数表里的顶层形参个数；命名函数返回 null */
    private static function closureArity(array $tokens, int $fnI): ?int
    {
        $open = SourceQuery::nextIndex($tokens, $fnI + 1);
        if ($open !== null && $tokens[$open] === '&') {
            $open = SourceQuery::nextIndex($tokens, $open + 1);
        }
        if ($open === null || $tokens[$open] !== '(') {
            return null;
        }
        $close = SourceQuery::matchBracket($tokens, $open);
        if ($close === null) {
            return null;
        }

        // 只数顶层 T_VARIABLE：默认值里的数组 / 构造调用含逗号，不能按逗号计数。
        $count = 0;
        $depth = 0;
        for ($i = $open + 1; $i < $close; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                if (in_array($tok, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($tok, [')', ']', '}'], true)) {
                    $depth--;
                }
                continue;
            }
            if ($depth === 0 && $tok[0] === T_VARIABLE) {
                $count++;
            }
        }

        return $count;
    }

    /** 第一实参里 `$this->foo()` / `self::foo()` 形态的方法名（小写） */
    private static function indirectNames(array $tokens, int $from, int $to): array
    {
        $out = [];
        for ($i = $from; $i <= $to; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_STRING) {
                continue;
            }
            $prev = SourceQuery::prevIndex($tokens, $i - 1);
            if ($prev === null || !is_array($tokens[$prev])
                || !in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            $next = SourceQuery::nextIndex($tokens, $i + 1);
            if ($next === null || $tokens[$next] !== '(') {
                continue;
            }
            $out[] = strtolower($tok[1]);
        }

        return $out;
    }

    /** @return array<string, list<array{0: int, 1: int}>> 小写方法名 => [行, 闭包容参数] */
    private static function factories(array $tokens): array
    {
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_FUNCTION) {
                continue;
            }
            $nameI = SourceQuery::nextIndex($tokens, $i + 1);
            if ($nameI === null || !is_array($tokens[$nameI]) || $tokens[$nameI][0] !== T_STRING) {
                continue; // 匿名闭包，不是命名工厂
            }
            $open = SourceQuery::nextIndex($tokens, $nameI + 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            $close = SourceQuery::matchBracket($tokens, $open);
            if ($close === null) {
                continue;
            }
            $brace = SourceQuery::nextIndex($tokens, $close + 1);
            while ($brace !== null && $tokens[$brace] !== '{') {
                $brace = SourceQuery::nextIndex($tokens, $brace + 1); // 跳过 `: \Closure` 返回类型
            }
            if ($brace === null) {
                continue;
            }
            $end = SourceQuery::matchBracket($tokens, $brace);
            if ($end === null) {
                continue;
            }

            for ($j = $brace + 1; $j < $end; $j++) {
                $t = $tokens[$j];
                if (!is_array($t) || $t[0] !== T_RETURN) {
                    continue;
                }
                $fn = self::closureStart($tokens, SourceQuery::nextIndex($tokens, $j + 1));
                if ($fn === null) {
                    continue;
                }
                $arity = self::closureArity($tokens, $fn);
                if ($arity !== null) {
                    $out[strtolower($tokens[$nameI][1])][] = [$t[2], $arity];
                }
            }
        }

        return $out;
    }
}
