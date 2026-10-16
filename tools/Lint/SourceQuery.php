<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

/**
 * token 级的类结构查询 —— 需要"方法体范围 / 属性表 / 实现列表"的规则共用一处实现。
 *
 * 只抽编译期可见的事实（G1）：类名、`extends`/`implements`、声明的属性与其类型、
 * 方法体的 token 区间。不预判任何规则的判据。
 *
 * ★ 与 `SourceFile::parse()` 的分工：那边扫"文件级"事实（命名空间 / use / 引用），
 *   这边扫"类内部"事实。两边都只有 token 可见性，不引入 php-parser。
 */
final class SourceQuery
{
    private const SKIP = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /** PHP 内建类型：解析时保持小写原文，不套命名空间 */
    private const BUILTIN_TYPES = [
        'int' => true, 'float' => true, 'string' => true, 'bool' => true,
        'array' => true, 'object' => true, 'mixed' => true, 'callable' => true,
        'iterable' => true, 'void' => true, 'never' => true, 'null' => true,
        'true' => true, 'false' => true, 'self' => true, 'static' => true, 'parent' => true,
    ];

    /** 属性声明里"可见性"与 `$name` 之间允许出现的类型 token */
    private const TYPE_TOKENS = [
        T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR,
        T_ARRAY, T_CALLABLE, T_STATIC, T_READONLY,
    ];

    private function __construct()
    {
    }

    /**
     * 下一个有效 token 的下标。
     *
     * @param list<mixed> $tokens
     */
    public static function nextIndex(array $tokens, int $i): ?int
    {
        for ($n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok) && in_array($tok[0], self::SKIP, true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * 上一个有效 token 的下标。
     *
     * @param list<mixed> $tokens
     */
    public static function prevIndex(array $tokens, int $i): ?int
    {
        for (; $i >= 0; $i--) {
            $tok = $tokens[$i];
            if (is_array($tok) && in_array($tok[0], self::SKIP, true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * 区间内的**实例方法调用**：`->name(` 且 `name` 命中 `$names`。
     *
     * ★ 为什么放在 `SourceQuery` 而不是各规则自己写：`subscriber-mark` 与
     *   `nested-subscribe` 判的是同一件事（"这里有没有发起订阅"），一个在方法体里
     *   找、一个在 `items()` 参数里找 ⇒ **同一个口径，两个落点**。
     * ★ 只认 `->`（实例调用）。静态调用 `Foo::select(` 是 `::`，不在此列 ——
     *   它不产生"谁在订阅"的问题。
     *
     * @param list<mixed> $tokens
     * @param list<string> $names 小写方法名
     * @return list<array{name: string, line: int}>
     */
    public static function methodCalls(SourceFile $file, int $from, int $to, array $names): array
    {
        $tokens = $file->tokens();
        $hits = [];

        for ($i = $from; $i < $to; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $nameI = self::nextIndex($tokens, $i + 1);
            if ($nameI === null || $tokens[$nameI][0] !== T_STRING) {
                continue;
            }
            // ★ `->` 之后的属性名/方法名是 **T_STRING**（不是 T_VARIABLE —— 只有 `$foo` 才是）
            $name = strtolower($tokens[$nameI][1]);
            if (!in_array($name, $names, true)) {
                continue;
            }
            $callI = self::nextIndex($tokens, $nameI + 1);
            if ($callI === null || $tokens[$callI] !== '(') {
                continue;
            }
            // ★ `index` 收 `->` 的下标：调用方要判**接收者**（`$this->select(...)`
            //   与 `SubjectBuilder::make()->select(...)` 是两件不同的事 —— 前者
            //   "自己承担订阅"，后者"配置一个专职订阅者"）。
            $hits[] = ['name' => $name, 'line' => $tokens[$i][2], 'index' => $i];
        }

        return $hits;
    }

    /**
     * 括号匹配：从 `(` / `{` / `[` 的下标找对应闭合下标。
     *
     * @param list<mixed> $tokens
     * @return null|int 找不到（源码不完整）时返回 null
     */
    public static function matchBracket(array $tokens, int $open): ?int
    {
        $openChar = is_array($tokens[$open]) ? null : $tokens[$open];
        $closeChar = match ($openChar) {
            '(' => ')',
            '{' => '}',
            '[' => ']',
            default => null,
        };
        if ($closeChar === null) {
            return null;
        }
        $depth = 0;
        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok)) {
                continue;
            }
            if ($tok === $openChar) {
                $depth++;
                continue;
            }
            if ($tok === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * 文件内每个类的结构。
     *
     * ★ **多命名空间文件**：fqn 用的是**该类所在位置当时生效的** namespace，
     *   不是 `$file->namespace()`（后者给的是文件里最后一个）。单命名空间文件
     *   两者相同所以看不出来；`drift_lib.stub.php` 有 11 个 namespace ⇒
     *   不按位置算的话，第 2 个之后的类全被算进最后一个命名空间。
     *   （`export-surface-drift` 要读这个产物才暴露出来。）
     *
     * @return list<array{
     *     name: string,
     *     fqn: string,
     *     line: int,
     *     readonly: bool,
     *     extends: list<string>,
     *     implements: list<string>,
     *     properties: array<string, string>,
     *     methods: array<string, array{start: int, end: int, line: int}>
     * }>
     *         `properties`：小写属性名 => 解析后的类型 FQN（无类型声明 ⇒ 空字符串，不入表）
     *         `methods`：小写方法名 => 方法体的 `{`…`}` token 区间（无体的不记）
     */
    public static function classes(SourceFile $file): array
    {
        $tokens = $file->tokens();
        $out = [];
        $ns = '';

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                continue;
            }
            $id = $tok[0];

            // namespace 声明：更新"当前生效的命名空间"（`public function namespace()`
            // 那类用法后面跟 `(`，声明后面跟名字或 `{`，据此区分）
            if ($id === T_NAMESPACE) {
                $after = self::nextIndex($tokens, $i + 1);
                if ($after !== null && SourceFile::startsNameOrBlock($tokens[$after][0])) {
                    $ns = SourceFile::readName($tokens, $i + 1);
                }
                continue;
            }

            if ($id !== T_CLASS && $id !== T_INTERFACE && $id !== T_TRAIT && $id !== T_ENUM) {
                continue;
            }
            // `Foo::class` 与 `new class {…}` 都不是声明
            // ★ 单字符 token（`{` / `;` …）没有 [1]/[2]，且 `$tok[0]` 取到的是字符而非 id
        //   ⇒ 先用 is_array 护一下，否则分支永远不命中。
            $prev = self::prevIndex($tokens, $i - 1);
            $prevId = $prev !== null && is_array($tokens[$prev]) ? $tokens[$prev][0] : null;
            if ($prevId !== null && in_array($prevId, [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            $readonly = $prevId === T_READONLY;

            $info = self::readClass($file, $i, $id, $ns, $readonly);
            if ($info !== null) {
                $out[] = $info;
                $i = $info['bodyEnd'];
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $tokens
     * @return null|array{
     *     name: string, fqn: string, line: int, readonly: bool,
     *     extends: list<string>, implements: list<string>,
     *     properties: array<string, string>,
     *     methods: array<string, array{start: int, end: int, line: int}>,
     *     bodyStart: int, bodyEnd: int
     * }
     * @param string $ns 该类位置当时生效的命名空间（多命名空间文件按位置算，见 classes() 注释）
     */
    private static function readClass(SourceFile $file, int $classToken, int $id, string $ns, bool $readonly): ?array
    {
        $tokens = $file->tokens();
        $nameI = self::nextIndex($tokens, $classToken + 1);
        if ($nameI === null || $tokens[$nameI][0] !== T_STRING) {
            return null;
        }
        $name = $tokens[$nameI][1];

        $extends = [];
        $implements = [];

        // 读到类体的 `{`；enum 可能带 `: int` 备份类型，一并放行
        $j = $nameI + 1;
        $open = null;
        for (; $j < count($tokens); $j++) {
            $tok = $tokens[$j];
            if (is_array($tok)) {
                if ($tok[0] === T_EXTENDS) {
                    $j = self::readNameList($file, $j + 1, $extends) - 1;
                    continue;
                }
                if ($tok[0] === T_IMPLEMENTS) {
                    $j = self::readNameList($file, $j + 1, $implements) - 1;
                    continue;
                }
                continue;
            }
            if ($tok === '{') {
                $open = $j;
                break;
            }
            if ($tok === ';') {
                return null; // `class Foo;` 之类不完整声明
            }
        }
        if ($open === null) {
            return null;
        }
        $close = self::matchBracket($tokens, $open);
        if ($close === null) {
            return null;
        }

        // ★ `$id` 是**整数** token 常量（T_INTERFACE 等），不是字符串
        //   —— 写成字符串分支会全部落空（除非定义了同名常量）。
        $kind = match ($id) {
            T_INTERFACE => 'interface',
            T_TRAIT => 'trait',
            T_ENUM => 'enum',
            default => 'class',
        };
        $info = [
            'name' => $name,
            'fqn' => ltrim($ns . '\\' . $name, '\\'),
            'line' => $tokens[$classToken][2],
            'kind' => $kind,
            'readonly' => $readonly,
            'extends' => $extends,
            'implements' => $implements,
            'properties' => [],
            'methods' => [],
            'bodyStart' => $open,
            'bodyEnd' => $close,
        ];

        // ★ 不能用 `+=`：`$info` 里 properties/methods 两个键已存在（空数组），
        //   联合运算符对已存在的键不做任何事 ⇒ 整个类体都会被静默丢掉。
        $body = self::readBody($file, $open, $close);
        $info['properties'] = $body['properties'];
        $info['methods'] = $body['methods'];

        return $info;
    }

    /**
     * 读 `extends` / `implements` 后逗号分隔的名字表，解析为 FQN。
     *
     * @param list<string> $into
     * @param list<mixed> $tokens
     * @return int 名字表之后第一个 token 的下标
     */
    private static function readNameList(SourceFile $file, int $from, array &$into): int
    {
        $tokens = $file->tokens();
        $i = self::nextIndex($tokens, $from);
        $name = '';
        $lastContent = $from;

        // ★ 必须走 nextIndex：`implements Foo` 的 `implements` 与 `Foo` 之间有空白，
        //   裸 for 会被 T_WHITESPACE 打断 ⇒ 整张表读成空（类层级全部丢失）。
        while ($i !== null) {
            $tok = $tokens[$i];
            if (is_array($tok) && in_array($tok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $tok[1];
                $lastContent = $i;
                $i = self::nextIndex($tokens, $i + 1);
                continue;
            }
            if (!is_array($tok) && $tok === ',') {
                if ($name !== '') {
                    $into[] = $file->resolveName(trim($name, '\\'));
                    $name = '';
                }
                $lastContent = $i;
                $i = self::nextIndex($tokens, $i + 1);
                continue;
            }
            break;
        }
        if ($name !== '') {
            $into[] = $file->resolveName(trim($name, '\\'));
        }

        return $lastContent + 1;
    }

    /**
     * 类体：属性表 + 方法体区间表。
     *
     * ★ 单次前进：命中 `function` 就整块跳过方法体 ⇒ 构造器提升参数
     *   （`__construct(private int $x)`）不会被误读成属性。
     *
     * @param list<mixed> $tokens
     * @return array{properties: array<string, string>, methods: array<string, array{start: int, end: int, line: int}>}
     */
    private static function readBody(SourceFile $file, int $open, int $close): array
    {
        $tokens = $file->tokens();
        $properties = [];
        $methods = [];

        for ($j = $open + 1; $j < $close; $j++) {
            $tok = $tokens[$j];
            if (!is_array($tok)) {
                continue;
            }

            if ($tok[0] === T_FUNCTION) {
                $nameI = self::nextIndex($tokens, $j + 1);
                $isAnonymous = $nameI === null || $tokens[$nameI][0] !== T_STRING;
                if ($isAnonymous) {
                    continue; // 闭包：属性规则看不见它，方法体也不属任何名字
                }
                $name = strtolower($tokens[$nameI][1]);
                // ★ 方法名的下一个有效 token 是参数列表的 `(`，不是方法体的 `{`
                //   （中间还夹着返回类型）⇒ 必须先匹配括号，再往后找 `{`。
                $parenI = self::nextIndex($tokens, $nameI + 1);
                $afterParen = $parenI !== null && $tokens[$parenI] === '('
                    ? self::matchBracket($tokens, $parenI)
                    : null;
                // ★ `)` 之后夹着返回类型（`: float` / `: static` / `: ?Foo`）⇒ 要把
                //   `:` 与类型 token 一起跳过，直到 `{`（有体）或 `;`（抽象/接口方法）。
                $braceI = null;
                if ($afterParen !== null) {
                    for ($k = $afterParen + 1, $n = count($tokens); $k < $n; $k++) {
                        $t = $tokens[$k];
                        if (is_array($t)) {
                            if ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT) {
                                continue;
                            }
                            if (in_array($t[0], self::TYPE_TOKENS, true)) {
                                continue; // 返回类型的名字部分
                            }
                            break; // 不是声明的一部分 ⇒ 停
                        }
                        if ($t === '{' || $t === ';') {
                            $braceI = $k;
                            break;
                        }
                        if ($t === ':' || $t === '?' || $t === '|' || $t === '&') {
                            continue; // 返回类型的标点部分
                        }
                        break;
                    }
                }
                if ($braceI !== null && $tokens[$braceI] === '{') {
                    $end = self::matchBracket($tokens, $braceI);
                    if ($end !== null) {
                        $methods[$name] = [
                            'start' => $braceI,
                            'end' => $end,
                            'line' => $tok[2],
                        ];
                        $j = $end;
                    }
                }
                continue;
            }

            if ($tok[0] === T_PUBLIC || $tok[0] === T_PROTECTED || $tok[0] === T_PRIVATE) {
                $parsed = self::readProperty($file, $j, $close);
                if ($parsed !== null) {
                    [$propName, $type, $after] = $parsed;
                    if ($propName !== '' && $type !== '') {
                        $properties[$propName] = $type;
                    }
                    $j = $after;
                }
            }
        }

        return ['properties' => $properties, 'methods' => $methods];
    }

    /**
     * 读一条属性声明：可见性… 类型 $name。
     *
     * @param list<mixed> $tokens
     * @return null|array{0: string, 1: string, 2: int} [小写属性名, 类型 FQN, 声明末尾下标]
     */
    private static function readProperty(SourceFile $file, int $visibility, int $classEnd): ?array
    {
        $tokens = $file->tokens();
        $classEnd = self::nextIndex($tokens, $classEnd + 1) ?? $classEnd;
        $i = self::nextIndex($tokens, $visibility + 1);
        if ($i === null) {
            return null;
        }

        // static / readonly 修饰
        while ($i !== null && in_array($tokens[$i][0], [T_STATIC, T_READONLY], true)) {
            $i = self::nextIndex($tokens, $i + 1);
        }

        // ★ 从修饰符之后一路走到 `$name`——中间夹着的是**类型**（`private Cell $x`）。
        //   原稿在这里直接判断 T_VARIABLE，于是每条属性都被当成"无类型声明"丢掉。
        while ($i !== null && $i < $classEnd) {
            $tok = $tokens[$i];
            if (is_array($tok)) {
                if ($tok[0] === T_VARIABLE) {
                    break;
                }
                // 撞上这些 ⇒ 这不是属性声明（是方法 / 常量 / 静态访问）
                if (in_array($tok[0], [T_FUNCTION, T_CONST, T_DOUBLE_COLON], true)) {
                    return null;
                }
                if (!in_array($tok[0], self::TYPE_TOKENS, true)) {
                    return null;
                }
                $i = self::nextIndex($tokens, $i + 1);
                continue;
            }
            if ($tok === '?') { // 可空标记
                $i = self::nextIndex($tokens, $i + 1);
                continue;
            }

            return null;
        }

        if ($i === null || $i >= $classEnd || !is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE) {
            return null; // 无类型声明 ⇒ 本类规则无从判断，跳过
        }

        $propName = strtolower(ltrim($tokens[$i][1], '$'));
        $type = self::readTypeBefore($file, $visibility + 1, $i);

        return [$propName, $type, $i];
    }

    /**
     * 读 `visibility` 与 `$name` 之间的类型，解析成 FQN。
     *
     * @param list<mixed> $tokens
     */
    private static function readTypeBefore(SourceFile $file, int $from, int $before): string
    {
        $tokens = $file->tokens();
        $text = '';
        for ($i = self::nextIndex($tokens, $from); $i !== null && $i < $before; $i = self::nextIndex($tokens, $i + 1)) {
            $tok = $tokens[$i];
            // ★ 单字符 token（`?`）不是数组：`$tok[0]` 是字符本身，`$tok[1]` 不存在
            if (is_array($tok)) {
                if (in_array($tok[0], [T_STATIC, T_READONLY, T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                    continue;
                }
                $text .= $tok[1];
                continue;
            }
            $text .= $tok;
        }
        $text = trim(str_replace(' ', '', $text));
        if ($text === '') {
            return '';
        }

        // `?T`：规则只关心"指向哪个类型"，可空性不是它们判据的一部分
        $bare = ltrim($text, '?');
        // ★ 内建类型不是命名空间里的类：`Apprray` 这种形态谁都匹配不上
        if (isset(self::BUILTIN_TYPES[strtolower($bare)])) {
            return strtolower($bare);
        }

        return $file->resolveName($bare);
    }
}
