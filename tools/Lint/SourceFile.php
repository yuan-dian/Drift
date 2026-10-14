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

    /** @return list<array{name: string, line: int}> */
    public function references(): array
    {
        return $this->references;
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

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
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
                if ($after === null || !$this->startsNameOrBlock($after[0])) {
                    $pendingDoc = null;
                    continue;
                }
                $this->namespace = $this->readName($tokens, $i + 1);
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
                $name = $this->readName($tokens, $i + 1);
                if ($name !== '') {
                    $this->types[] = [
                        'kind' => $kind,
                        'name' => $name,
                        'fqn' => ltrim($this->namespace . '\\' . $name, '\\'),
                        'line' => $line,
                        'api' => $pendingDoc !== null && preg_match('/@api\b/', $pendingDoc) === 1,
                    ];
                }
                $pendingDoc = null;
                continue;
            }

            if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                $this->references[] = ['name' => $this->resolve(ltrim($text, '\\')), 'line' => $line];
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
            }
        }
    }

    /** 命名空间声明后面只能跟名字（`namespace A\B;`）或块（`namespace {`） */
    private function startsNameOrBlock(int|string $token): bool
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
    private function readName(array $tokens, int $i): string
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
        $short = $alias ?? substr($fqn, (int) strrpos($fqn, '\\') + 1);
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
