<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

/**
 * 一个已解析的 PHP 源文件。
 *
 * 只抽"编译期可见"的事实（G1）：命名空间、use 别名、声明的类型、引用到的名字。
 * 规则各自读 `tokens()` 做自己的扫描，这里不预判任何规则。
 */
final class SourceFile
{
    public const KIND_CLASS = 'class';
    public const KIND_INTERFACE = 'interface';
    public const KIND_TRAIT = 'trait';
    public const KIND_ENUM = 'enum';

    private string $namespace = '';
    private int $namespaceLine = 0;

    /** @var array<string, string> 小写别名 => FQN */
    private array $uses = [];

    /** @var list<array{kind: string, name: string, fqn: string, line: int, api: bool}> */
    private array $types = [];

    /** @var list<array{name: string, line: int}> 解析后的全限定引用 */
    private array $references = [];

    /**
     * 类体内声明的具名方法（含 `@internal` / `@api` 标注）。
     *
     * `internal-leak` 的方法级判据（`07` §2.2 ②）就靠它 + `calls()`：
     * 类型级只看"引用了哪个类型"，而"引用了一个公共类型却调它的内部方法"
     * 是另一类泄漏，类型表看不出来。
     *
     * @var list<array{class: string, name: string, line: int, internal: bool, api: bool}>
     */
    private array $methods = [];

    /** @var array<string, string> 类 FQN => 解析后的父类 FQN（`extends`） */
    private array $parents = [];

    /** @var array<string, string> 变量名（带 `$`）=> 文件内可静态确定的类型 FQN */
    private array $varTypes = [];

    /**
     * 方法调用点。
     *
     * `class` 为 null 表示接收者是变量（`$this` / `$foo`），要由规则用 `owner`
     * 或 `varTypes()` 解析；**解析不出来就不猜**（见 `noteTypedVariable()`）。
     *
     * @var list<array{owner: string, class: null|string, receiver: string, method: string, line: int}>
     */
    private array $calls = [];

    /** PHP 内建类型名：`Foo $x` 的 `Foo` 位置出现这些就不是类（不记 varTypes） */
    private const BUILTIN_TYPES = [
        'int', 'float', 'string', 'bool', 'array', 'callable', 'iterable',
        'object', 'mixed', 'void', 'null', 'false', 'true', 'never',
        'self', 'static', 'parent',
    ];

    /** @var list<array<int, mixed>> */
    private array $tokens = [];

    public function __construct(
        private readonly string $root,
        private readonly string $path,
        private readonly string $code,
    ) {
        $this->tokens = token_get_all($this->code);
        $this->parse();
    }

    /** 相对仓库根的路径，报告里用它（绝对路径在 diff 里读不出重点） */
    public function path(): string
    {
        return $this->path;
    }

    public function absolutePath(): string
    {
        return $this->root . '/' . $this->path;
    }

    public function code(): string
    {
        return $this->code;
    }

    /** @return list<array<int, mixed>> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function namespaceLine(): int
    {
        return $this->namespaceLine;
    }

    /** @return array<string, string> */
    public function uses(): array
    {
        return $this->uses;
    }

    /** @return list<array{kind: string, name: string, fqn: string, line: int, api: bool}> */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * 把可能是别名的名字解析成全限定名。
     *
     * `SourceQuery` 与各规则共用这一处口径（`use` 别名 / 同命名空间回退）。
     */
    public function resolveName(string $name): string
    {
        return $this->resolve(ltrim($name, '\\'));
    }

    /** @return list<array{name: string, line: int}> */
    public function references(): array
    {
        return $this->references;
    }

    /**
     * 类体内声明的具名方法。
     *
     * @return list<array{class: string, name: string, line: int, internal: bool, api: bool}>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    /** @return array<string, string> 类 FQN => 父类 FQN */
    public function parents(): array
    {
        return $this->parents;
    }

    /** @return array<string, string> 变量名（带 `$`）=> 类型 FQN */
    public function varTypes(): array
    {
        return $this->varTypes;
    }

    /**
     * 方法调用点（`internal-leak` 的方法级输入）。
     *
     * @return list<array{owner: string, class: null|string, receiver: string, method: string, line: int}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /** 文件里是否出现过 `@internal-use-allowed:` 豁免 */
    public function hasInternalUseExemption(): bool
    {
        return str_contains($this->code, '@internal-use-allowed:');
    }

    /**
     * PSR-4 期望的命名空间（§4.4 纪律 3）。
     *
     * 判据来自 composer.json 的前缀表。返回 null 表示这个路径不归任何
     * PSR-4 前缀管（夹具等）。
     *
     * @param array<string, string> $dirToPrefix 目录（带尾斜杠）=> 命名空间前缀
     */
    public function expectedNamespace(array $dirToPrefix): ?string
    {
        $bestDir = null;
        $bestPrefix = null;
        foreach ($dirToPrefix as $dir => $prefix) {
            if (!str_starts_with($this->path, $dir)) {
                continue;
            }
            if ($bestDir === null || strlen($dir) > strlen($bestDir)) {
                $bestDir = $dir;
                $bestPrefix = $prefix;
            }
        }
        if ($bestDir === null) {
            return null;
        }

        $sub = substr($this->path, strlen($bestDir), -4); // 去掉 .php
        $parts = array_values(array_filter(explode('/', $sub), static fn(string $p): bool => $p !== ''));
        array_pop($parts); // 最后一段是文件名，不参与命名空间

        return $parts === [] ? $bestPrefix : $bestPrefix . '\\' . implode('\\', $parts);
    }

    private function parse(): void
    {
        $tokens = $this->tokens;
        $count = count($tokens);
        $pendingDoc = null;
        // 最后声明的类型 FQN。本仓 PSR-4 一文件一类型 ⇒ "最后一个"就是"当前这个"，
        // 不需要维护花括号深度（维护了反而要处理 heredoc / 插值里的假括号）。
        $currentClass = '';

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                // ★ 语句边界必须清 pendingDoc：属性上的
                //   `/** @internal 缓存 */ private int $n = 0;` 后面跟 `;`，
                //   不清的话下一个方法会被**误标**成 @internal ⇒ 方法级检查全是假警报。
                if ($tok === ';' || $tok === '{' || $tok === '}') {
                    $pendingDoc = null;
                }
                continue;
            }
            [$id, $text, $line] = $tok;

            if ($id === T_DOC_COMMENT) {
                $pendingDoc = $text;
                continue;
            }

            if ($id === T_NAMESPACE) {
                // ★ PHP 8 允许保留字作方法名 ⇒ `public function namespace(): string`
                //   也是一个 T_NAMESPACE。声明后面必然跟名字或 `{`，调用后面跟 `(`。
                $after = Tokens::next($tokens, $i + 1);
                if ($after === null || !self::startsNameOrBlock($after[0])) {
                    $pendingDoc = null;
                    continue;
                }
                $this->namespace = self::readName($tokens, $i + 1);
                $this->namespaceLine = $line;
                $pendingDoc = null;
                continue;
            }

            if ($id === T_USE && !$this->isClosureUse($tokens, $i)) {
                $i = $this->readUse($tokens, $i, $line);
                $pendingDoc = null;
                continue;
            }

            if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
                // `Foo::class` 与匿名类不算声明
                if ($id === T_CLASS && $this->isClassConstant($tokens, $i)) {
                    continue;
                }
                $kind = match ($id) {
                    T_INTERFACE => self::KIND_INTERFACE,
                    T_TRAIT => self::KIND_TRAIT,
                    T_ENUM => self::KIND_ENUM,
                    default => self::KIND_CLASS,
                };
                $name = self::readName($tokens, $i + 1);
                if ($name !== '') {
                    $fqn = ltrim($this->namespace . '\\' . $name, '\\');
                    $this->types[] = [
                        'kind' => $kind,
                        'name' => $name,
                        'fqn' => $fqn,
                        'line' => $line,
                        'api' => $pendingDoc !== null && preg_match('/@api\b/', $pendingDoc) === 1,
                    ];
                    $currentClass = $fqn;
                    $this->readParent($tokens, $i, $fqn);
                }
                $pendingDoc = null;
                continue;
            }

            if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                $this->references[] = ['name' => $this->resolve(ltrim($text, '\\')), 'line' => $line];
                $this->noteTypedVariable($tokens, $i, $text);
                continue;
            }

            if ($id === T_FUNCTION) {
                $this->readMethod($tokens, $i, $line, $pendingDoc, $currentClass);
                $pendingDoc = null;
                continue;
            }

            if ($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR) {
                $this->readInstanceCall($tokens, $i, $line, $currentClass);
                continue;
            }

            if ($id === T_DOUBLE_COLON) {
                $this->readStaticCall($tokens, $i, $line, $currentClass);
                continue;
            }

            if ($id === T_STRING) {
                $next = Tokens::next($tokens, $i + 1);
                $isStaticAccess = is_array($next) && $next[0] === T_DOUBLE_COLON;
                $prev = Tokens::prev($tokens, $i - 1);
                $isNew = is_array($prev) && $prev[0] === T_NEW;
                if ($isStaticAccess || $isNew) {
                    $this->references[] = ['name' => $this->resolve($text), 'line' => $line];
                }
                $this->noteTypedVariable($tokens, $i, $text);
                if ($isNew) {
                    $this->noteNewAssignment($tokens, $i, $text);
                }
            }
        }
    }

    /**
     * 读一个方法声明（`T_FUNCTION` 分支）。
     *
     * ★ 只收**类体内**的具名方法：闭包的下一个有效 token 是 `(`，
     *   顶层函数没有 owner（`$owner === ''`）⇒ 都不进方法表。
     */
    private function readMethod(array $tokens, int $i, int $line, ?string $doc, string $owner): void
    {
        if ($owner === '') {
            return;
        }
        $j = Tokens::nextIndex($tokens, $i + 1);
        // `function &name()` —— `&` 是单字符 token 或 T_AMPERSAND_*，两种都跳过
        while ($j !== null) {
            $tok = $tokens[$j];
            $isAmp = $tok === '&'
                || (is_array($tok) && in_array($tok[0], [T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true));
            if (!$isAmp) {
                break;
            }
            $j = Tokens::nextIndex($tokens, $j + 1);
        }
        if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            return; // 闭包 / 匿名函数
        }
        $this->methods[] = [
            'class' => $owner,
            'name' => $tokens[$j][1],
            'line' => $line,
            'internal' => $doc !== null && preg_match('/@internal\b/', $doc) === 1,
            'api' => $doc !== null && preg_match('/@api\b/', $doc) === 1,
        ];
    }

    /**
     * 读 `extends` 的父类（类型声明分支调用）。
     *
     * 只认 `{` 之前的 `T_EXTENDS`；`implements` 后面的名字不算（接口方法表
     * 不在本规则的解析范围内 —— 认了就会把"接口方法"当成"类方法"去查）。
     */
    private function readParent(array $tokens, int $i, string $owner): void
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            $tok = $tokens[$j];
            if (!is_array($tok)) {
                if ($tok === '{' || $tok === ';') {
                    return;
                }
                continue;
            }
            if ($tok[0] === T_EXTENDS) {
                $parent = self::readName($tokens, $j + 1);
                if ($parent !== '') {
                    $this->parents[$owner] = $this->resolve($parent);
                }
                return;
            }
            if ($tok[0] === T_IMPLEMENTS) {
                return;
            }
        }
    }

    /**
     * 读一个实例调用点（`->` / `?->` 分支）。
     *
     * 接收者只认**变量**：`$obj->m()` 收；`foo()->m()` / `$a['k']->m()` 这类
     * 链式接收者一律不收 —— 本规则**不猜**类型（猜错的代价是误报）。
     */
    private function readInstanceCall(array $tokens, int $i, int $line, string $owner): void
    {
        $prev = Tokens::prev($tokens, $i - 1);
        if ($prev === null || $prev[0] !== T_VARIABLE) {
            return;
        }
        $j = Tokens::nextIndex($tokens, $i + 1);
        if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            return;
        }
        $k = Tokens::nextIndex($tokens, $j + 1);
        if ($k === null || $tokens[$k] !== '(') {
            return; // 属性访问，不是调用
        }
        $this->calls[] = [
            'owner' => $owner,
            'class' => null,
            'receiver' => $prev[1],
            'method' => $tokens[$j][1],
            'line' => $line,
        ];
    }

    /**
     * 读一个静态调用点（`::` 分支）。
     *
     * `self::` / `static::` 解析成当前类，`parent::` 解析成父类 —— 这三种是
     * 唯一能**零推断**确定的接收者。
     */
    private function readStaticCall(array $tokens, int $i, int $line, string $owner): void
    {
        $prev = Tokens::prev($tokens, $i - 1);
        if ($prev === null || !is_array($prev)) {
            return;
        }
        if (!in_array($prev[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return;
        }
        $j = Tokens::nextIndex($tokens, $i + 1);
        if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            return;
        }
        $k = Tokens::nextIndex($tokens, $j + 1);
        if ($k === null || $tokens[$k] !== '(') {
            return; // 类常量 / 静态属性
        }
        $head = strtolower(ltrim($prev[1], '\\'));
        if ($head === 'self' || $head === 'static') {
            $class = $owner;
        } elseif ($head === 'parent') {
            $class = $this->parents[$owner] ?? null;
        } else {
            $class = $this->resolve(ltrim($prev[1], '\\'));
        }
        if ($class === null || $class === '') {
            return;
        }
        $this->calls[] = [
            'owner' => $owner,
            'class' => $class,
            'receiver' => $prev[1],
            'method' => $tokens[$j][1],
            'line' => $line,
        ];
    }

    /**
     * 记录"可静态确定的变量类型"：`Foo $x` 形态（参数 / 属性 / 提升属性）。
     *
     * ★ 只收三种前缀（`(` / `,` / 可见性等修饰符 / `{`）—— 收窄是为了不把
     *   `return Foo $x`（不合法但半成品代码里常见）之类的噪声收进来。
     * ★ 收不到就不记：规则会**跳过**不可解析的接收者，而不是按方法名瞎匹配
     *   （`src/` 里 93 个 @internal 方法名中有 `tick` / `start` / `reset` /
     *   `box` / `slot` 这类到处重名的，按名字报等于把量具砸了）。
     */
    private function noteTypedVariable(array $tokens, int $i, string $name): void
    {
        if (in_array(strtolower($name), self::BUILTIN_TYPES, true)) {
            return;
        }
        $next = Tokens::next($tokens, $i + 1);
        if ($next === null || $next[0] !== T_VARIABLE) {
            return;
        }
        $prev = Tokens::prev($tokens, $i - 1);
        if ($prev === null) {
            return;
        }
        $ok = in_array($prev[0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_READONLY, T_VAR], true)
            || $prev[0] === '(' || $prev[0] === ',' || $prev[0] === '{';
        if (!$ok) {
            return;
        }
        $this->varTypes[$next[1]] = $this->resolve(ltrim($name, '\\'));
    }

    /**
     * 记录 `$x = new Foo(...)` 形态（`new` 分支调用）。
     *
     * 只认"直接赋值给变量"：`$this->p = new Foo` 是属性赋值、`foo(new Bar)`
     * 没有变量可命名 ⇒ 都不记。
     */
    private function noteNewAssignment(array $tokens, int $i, string $name): void
    {
        $new = Tokens::prevIndex($tokens, $i - 1);
        if ($new === null || !is_array($tokens[$new]) || $tokens[$new][0] !== T_NEW) {
            return;
        }
        $eq = Tokens::prevIndex($tokens, $new - 1);
        if ($eq === null || $tokens[$eq] !== '=') {
            return;
        }
        $var = Tokens::prevIndex($tokens, $eq - 1);
        if ($var === null || !is_array($tokens[$var]) || $tokens[$var][0] !== T_VARIABLE) {
            return;
        }
        $this->varTypes[$tokens[$var][1]] = $this->resolve(ltrim($name, '\\'));
    }

    /** 命名空间声明后面只能跟名字（`namespace A\B;`）或块（`namespace {`） */
    public static function startsNameOrBlock(int|string $token): bool
    {
        return in_array($token, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, '{'], true);
    }

    /** 把可能是别名的名字解析成全限定名 */
    private function resolve(string $name): string
    {
        if (str_contains($name, '\\')) {
            $head = strtolower(substr($name, 0, (int) strpos($name, '\\')));
            $tail = substr($name, (int) strpos($name, '\\') + 1);
            if (isset($this->uses[$head])) {
                return $this->uses[$head] . '\\' . $tail;
            }
            return $name;
        }

        $lower = strtolower($name);
        if (isset($this->uses[$lower])) {
            return $this->uses[$lower];
        }
        return ltrim($this->namespace . '\\' . $name, '\\');
    }

    /** @param list<mixed> $tokens */
    public static function readName(array $tokens, int $i): string
    {
        $name = '';
        for (; $i < count($tokens); $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                break;
            }
            if (in_array($tok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $tok[1];
                continue;
            }
            if ($tok[0] === T_WHITESPACE) {
                continue;
            }
            break;
        }
        return trim($name, '\\');
    }

    /**
     * 读一条 `use` 语句，登记别名。
     *
     * @return int 该语句最后一个 token 的下标
     */
    private function readUse(array $tokens, int $i, int $line): int
    {
        $count = count($tokens);
        $fqn = '';
        $alias = null;
        $expectAlias = false;

        for ($i = $i + 1; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok)) {
                if ($tok[0] === T_WHITESPACE) {
                    continue;
                }
                if (in_array($tok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                    if ($expectAlias) {
                        $alias = $tok[1];
                        $expectAlias = false;
                    } else {
                        $fqn .= $tok[1];
                    }
                    continue;
                }
                if ($tok[0] === T_AS) {
                    $expectAlias = true;
                    continue;
                }
                if ($tok[0] === T_FUNCTION || $tok[0] === T_CONST) {
                    // use function / use const —— 不是类型导入，本规则不关心
                    return $this->skipToSemicolon($tokens, $i);
                }
                continue;
            }
            if ($tok === ',') {
                $this->registerUse($fqn, $alias, $line);
                $fqn = '';
                $alias = null;
                continue;
            }
            if ($tok === ';') {
                $this->registerUse($fqn, $alias, $line);
                return $i;
            }
        }
        return $count - 1;
    }

    private function registerUse(string $fqn, ?string $alias, int $line): void
    {
        $fqn = trim($fqn, '\\');
        if ($fqn === '') {
            return;
        }
        // ★ `strrpos` 找不到分隔符时返回 **false**，`(int) false === 0` ⇒
        //   `substr($fqn, 1)` 会把首字母吃掉：`use \Override;` 登记的别名键曾是 `verride`
        //   ⇒ `resolveName('Override')` 查不到，落回"同命名空间回退"。
        //   2026-10-21 修（写 `attribute-unqualified` 夹具时撞到；本仓 src/ 零处
        //   `use \X;` 所以一直潜伏）。
        $sep = strrpos($fqn, '\\');
        $short = $alias ?? ($sep === false ? $fqn : substr($fqn, $sep + 1));
        $this->uses[strtolower($short)] = $fqn;

        // ★ import 本身就是一次引用：依赖方向与 internal-leak 主要靠它判 ——
        //   非限定名要能用，必然先 import（否则就在同命名空间内）。
        $this->references[] = ['name' => $fqn, 'line' => $line];
    }

    /** @param list<mixed> $tokens */
    private function skipToSemicolon(array $tokens, int $i): int
    {
        for (; $i < count($tokens); $i++) {
            if ($tokens[$i] === ';') {
                return $i;
            }
        }
        return $i - 1;
    }

    /** @param list<mixed> $tokens */
    private function isClosureUse(array $tokens, int $i): bool
    {
        // ★ 括号是单字符 token，没有 T_OPEN/CLOSE_PARENTHESIS 常量；
        //   Tokens 助手把它规整成 [$char, $char, 0] ⇒ 直接比字符串。
        $prev = Tokens::prev($tokens, $i - 1);
        return $prev !== null && $prev[0] === ')';
    }

    /** @param list<mixed> $tokens */
    private function isClassConstant(array $tokens, int $i): bool
    {
        $prev = Tokens::prev($tokens, $i - 1);
        return is_array($prev) && $prev[0] === T_DOUBLE_COLON;
    }

}
