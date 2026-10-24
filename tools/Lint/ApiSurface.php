<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

/**
 * `@api` 面清单（ARCH-REVIEW-2026-10-24 §5.2 U1）。
 *
 * 332 个 `@api` + 253 个 `@internal` 之前只能 `grep -rc` 粗数 —— 且 grep 口径
 * **把描述性提及当标注**（实测：@internal 真标注 107，字样 253 ⇒ 146 处是
 * "它本身是 `@internal` 的实现细节"这类行内文字）。本类把"应用开发者能用
 * 什么"变成可打印清单（`php tools/lint.php --api`），按层分组，当文档用。
 *
 * ★ 为什么不是 Rule：Rule 契约是 `run(): list<Diagnostic>`（该响会响）。
 *   api-surface 不报错只列清单 —— 硬塞进 Rule 会为一种形态污染接口。
 *   与 docref 同构：独立实现，挂在 lint.php 的 mode 上。
 * ★ 为什么行扫描而不用 token 流：本仓 docblock 与声明**紧邻**是铁律
 *   （实测无 attribute 间隔），行扫描足够且可读；代价是遇到未识别的
 *   声明形态必须记 `unmatched` 并由 selftest 断言为 0（漏形态 = 静默少算）。
 */
final class ApiSurface
{
    /** 扫描范围与排除（与 DocRef/DocSet 同口径：夹具/产物/归档不是 API 面） */
    private const EXCLUDE = ['/fixtures/', '/build/', '/_build/', '/.git/', '/vendor/', '/node_modules/'];

    /** 承载成员的类型声明（interface 的方法签名也带 @api） */
    private const TYPE_KINDS = ['class', 'interface', 'trait', 'enum'];

    /**
     * 解析单个源文件。
     *
     * @return array{entries: list<array<string, string|int>>, mentions: int, unmatched: int}
     *   entries 每项：file/line/layer/symbol/kind/visibility
     */
    public static function parseSource(string $src, string $rel): array
    {
        $entries = [];
        $mentions = 0;
        $unmatched = 0;
        $layer = self::layerOf($rel);
        $currentClass = null;

        $lines = explode("\n", $src);
        $docBuf = null;   // 待归属的 docblock 行
        $docLine = 0;

        foreach ($lines as $i => $line) {
            $lineno = $i + 1;

            if ($docBuf !== null) {
                $docBuf[] = $line;
                if (preg_match('#\*/#', $line) !== 1) {
                    continue;
                }
                $mentions += self::mentionsOf($docBuf);
                [$newEntries, $newUnmatched] = self::attach($docBuf, $lines, $i, $rel, $layer, $docLine, $currentClass);
                $entries = [...$entries, ...$newEntries];
                $unmatched += $newUnmatched;
                $docBuf = null;
                continue;
            }

            $trim = trim($line);

            // 单行 docblock：`/** @internal 夹具… */` / `/** @api */`
            // （与多行同走 attach：本行即闭合，从下一行开始找声明）
            if (str_starts_with($trim, '/**') && preg_match('#\*/#', substr($trim, 3)) === 1) {
                $docBuf = [$line];
                $docLine = $lineno;
                $mentions += self::mentionsOf($docBuf);
                [$newEntries, $newUnmatched] = self::attach($docBuf, $lines, $i, $rel, $layer, $docLine, $currentClass);
                $entries = [...$entries, ...$newEntries];
                $unmatched += $newUnmatched;
                $docBuf = null;
                continue;
            }

            if (str_starts_with($trim, '/**')) {
                $docBuf = [$line];
                $docLine = $lineno;
                continue;
            }

            // 无 docblock 也要跟踪当前类（方法的 owner 靠它）
            $decl = self::parseDeclaration($trim);
            if ($decl !== null && \in_array($decl['kind'], self::TYPE_KINDS, true)) {
                $currentClass = $decl['symbol'];
            }
        }

        return ['entries' => $entries, 'mentions' => $mentions, 'unmatched' => $unmatched];
    }

    /** @return array{api: list<array<string, string|int>>, internal: list<array<string, string|int>>, mentions: int, unmatched: int, files: int} */
    public static function scan(string $root): array
    {
        $api = [];
        $internal = [];
        $mentions = 0;
        $unmatched = 0;
        $files = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
                continue;
            }
            $rel = substr(str_replace('\\', '/', $f->getPathname()), \strlen($root) + 1);
            $excluded = false;
            foreach (self::EXCLUDE as $needle) {
                if (str_contains('/' . $rel, $needle)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                continue;
            }
            ++$files;
            $r = self::parseSource((string) file_get_contents($f->getPathname()), $rel);
            $mentions += $r['mentions'];
            $unmatched += $r['unmatched'];
            foreach ($r['entries'] as $e) {
                if ($e['visibility'] === 'api') {
                    $api[] = $e;
                } else {
                    $internal[] = $e;
                }
            }
        }

        return ['api' => $api, 'internal' => $internal, 'mentions' => $mentions, 'unmatched' => $unmatched, 'files' => $files];
    }

    /** markdown 清单（当文档用：可直接贴进 docs/） */
    public static function render(array $r): string
    {
        $out = "# Drift2 API 面清单（@api / @internal）\n\n";
        $out .= "> 由 `php tools/lint.php --api` 自动生成，勿手改。\n";
        $out .= "> 口径：docblock **独立标注行**（` * @api` 或单行 `/** @api … */`）；\n";
        $out .= "> 行内描述性提及（如「它本身是 @internal 的实现细节」这类文字）不计入。\n\n";

        // 总览：按层
        $layers = [];
        foreach ([...$r['api'], ...$r['internal']] as $e) {
            $l = (string) $e['layer'];
            if (!isset($layers[$l])) {
                $layers[$l] = ['api' => 0, 'internal' => 0];
            }
            ++$layers[$l][$e['visibility'] === 'api' ? 'api' : 'internal'];
        }
        ksort($layers);

        $out .= "## 总览\n\n";
        $out .= "| 层 | @api | @internal |\n|---|---|---|\n";
        foreach ($layers as $l => $c) {
            $out .= "| {$l} | {$c['api']} | {$c['internal']} |\n";
        }
        $out .= '| **合计** | **' . \count($r['api']) . '** | **' . \count($r['internal']) . "** |\n\n";
        $out .= "覆盖 {$r['files']} 个文件 · 描述性提及 {$r['mentions']} 处（grep 口径的污染源，不计入）";
        if ($r['unmatched'] > 0) {
            $out .= " · ⚠️ {$r['unmatched']} 处 docblock 标注未能归属到声明（解析器漏形态，须修）";
        }
        $out .= "\n\n";

        foreach (array_keys($layers) as $l) {
            $out .= "## {$l} 层\n\n";
            foreach (['api' => '@api（应用可读面）', 'internal' => '@internal（框架内部）'] as $vis => $title) {
                $rows = array_values(array_filter(
                    $vis === 'api' ? $r['api'] : $r['internal'],
                    static fn(array $e): bool => $e['layer'] === $l
                ));
                if ($rows === []) {
                    continue;
                }
                $out .= "### {$title} · " . \count($rows) . "\n\n";
                $out .= "| 符号 | 种类 | 位置 |\n|---|---|---|\n";
                foreach ($rows as $e) {
                    $out .= "| `{$e['symbol']}` | {$e['kind']} | {$e['file']}:{$e['line']} |\n";
                }
                $out .= "\n";
            }
        }

        return $out;
    }

    public static function selftest(): void
    {
        // 正例：类级 @api（多行 docblock）
        $r = self::parseSource(
            "/**\n * 文本控件。\n *\n * @api\n */\nclass Text\n{\n}\n",
            'src/Widget/Text.php'
        );
        self::assert(count($r['entries']) === 1 && $r['entries'][0]['symbol'] === 'Text' && $r['entries'][0]['visibility'] === 'api', '类级 @api 没抓到');

        // 正例：单行 docblock + property + @internal 带说明文字
        $r = self::parseSource(
            "class C\n{\n    /** @internal 缓存字段；子类覆写不受影响 */\n    private array \$cache = [];\n}\n",
            'src/App/C.php'
        );
        self::assert($r['entries'] === [[
            'file' => 'src/App/C.php', 'line' => 3, 'layer' => 'App',
            'symbol' => 'C::$cache', 'kind' => 'property', 'visibility' => 'internal',
        ]], '单行 docblock property 解析不对: ' . json_encode($r['entries']));

        // 正例：方法带 owner（类名先于方法出现）
        $r = self::parseSource(
            "class D\n{\n    /**\n     * @api\n     */\n    public static function make(): void {}\n}\n",
            'src/Widget/D.php'
        );
        self::assert($r['entries'][0]['symbol'] === 'D::make()' && $r['entries'][0]['kind'] === 'method', '方法 owner 解析不对');

        // 正例：docblock 与声明之间隔空行仍归属（容错，不制造 unmatched）
        $r = self::parseSource(
            "/**\n * @api\n */\n\nclass E\n{\n}\n",
            'src/Widget/E.php'
        );
        self::assert($r['unmatched'] === 0, '空行间隔不该记 unmatched');
        self::assert(\count($r['entries']) === 1 && $r['entries'][0]['symbol'] === 'E', '空行间隔后归属失败');

        // 反例：行内描述性提及不计入（@api 字样但不是标注）
        $r = self::parseSource(
            "class F\n{\n    /** 帧计数（自持，不穿 Frame::run() 的 @api 签名） */\n    private int \$n = 0;\n}\n",
            'src/Layout/F.php'
        );
        self::assert($r['entries'] === [] && $r['mentions'] === 1, '行内提及被当成标注了');

        // 反例：`//` 行注释与 `/* */` 普通块注释里的 @api 不算
        $r = self::parseSource(
            "// @api 不是标注\n/* @internal 也不是 */\nclass G {}\n",
            'src/Widget/G.php'
        );
        self::assert($r['entries'] === [], '行注释/块注释被当成 docblock');

        // 反例：docblock 标注但后面不是声明（use 语句）⇒ unmatched，不是静默丢弃
        $r = self::parseSource(
            "/**\n * @api\n */\nuse Drift\\Widget;\n",
            'src/Widget/H.php'
        );
        self::assert($r['unmatched'] === 1 && $r['entries'] === [], '非声明行应记 unmatched');

        // 形态覆盖（2026-10-24 全仓护栏抓到过，补成夹具）：
        // final/abstract 双修饰符 / \Ns 前缀类型 property / docblock 与声明间夹 attribute
        $r = self::parseSource(
            "class I\n{\n    /**\n     * @api\n     */\n    final public function layout(): void {}\n\n    /** @internal */\n    abstract protected function paint(): void;\n\n    /** @internal */\n    private ?\\Closure \$cb = null;\n\n    /**\n     * @api\n     */\n    #[\\Override]\n    public function seal(): void {}\n}\n",
            'src/Widget/I.php'
        );
        $syms = array_column($r['entries'], 'symbol');
        self::assert($r['unmatched'] === 0, '新形态样本误报 unmatched');
        self::assert($syms === ['I::layout()', 'I::paint()', 'I::$cb', 'I::seal()'], '新形态解析不对: ' . implode(',', $syms));
        self::assert($r['entries'][3]['visibility'] === 'api' && $r['entries'][1]['visibility'] === 'internal', '新形态 visibility 不对');

        // 全仓护栏：解析器不许漏形态（unmatched 必须为 0）
        $root = dirname(__DIR__, 2);
        $full = self::scan($root);
        self::assert($full['unmatched'] === 0, "全仓有 {$full['unmatched']} 处标注未能归属 —— 解析器漏了声明形态");
        self::assert(\count($full['api']) > 300, 'api 清单异常稀少，解析器可能坏了');
        self::assert(str_contains(self::render($full), '# Drift2 API 面清单'), 'render 产出异常');

        fwrite(STDOUT, "  ok    api-surface 解析器双向自检通过（@api " . \count($full['api'])
            . " / @internal " . \count($full['internal']) . " / 提及 " . $full['mentions'] . "）\n");
    }

    private static function assert(bool $cond, string $msg): void
    {
        if (!$cond) {
            throw new \RuntimeException('selftest: ' . $msg);
        }
    }

    // ─────────────────────────── 内部 ───────────────────────────

    /**
     * 把待归属 docblock 挂到它的下一条声明上（跳过空行与纯注释行）。
     *
     * @param list<string> $docBuf docblock 全部行（含闭合行）
     * @param list<string> $lines  全文件行
     * @return array{0: list<array<string, string|int>>, 1: int} [新 entries, unmatched 增量]
     */
    private static function attach(array $docBuf, array $lines, int $i, string $rel, string $layer, int $docLine, ?string &$currentClass): array
    {
        $pending = self::annotationsOf($docBuf);
        $entries = [];
        $unmatched = 0;

        for ($j = $i + 1; $j < \count($lines); ++$j) {
            $t = trim($lines[$j]);
            // attribute（`#[\Override]`）是声明的一部分，不算间隔
            if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '/*') || str_starts_with($t, '*') || str_starts_with($t, '#[')) {
                continue;
            }
            $decl = self::parseDeclaration($t);
            if ($decl === null) {
                // 标注了却挂不上声明 ⇒ 记数不静默（selftest 断言全仓为 0）
                $unmatched = $pending === [] ? 0 : 1;
                break;
            }
            if (\in_array($decl['kind'], self::TYPE_KINDS, true)) {
                $currentClass = $decl['symbol'];
            }
            foreach ($pending as $vis) {
                $entries[] = [
                    'file' => $rel,
                    'line' => $docLine,
                    'layer' => $layer,
                    'symbol' => self::symbolOf($decl, $currentClass),
                    'kind' => $decl['kind'],
                    'visibility' => $vis,
                ];
            }
            break;
        }

        return [$entries, $unmatched];
    }

    /**
     * docblock 里的独立标注行（` * @api` / ` * @internal 说明`）。
     * 单行 docblock（`/**` 与 `@` 同行闭合）也走这：`/**` 后紧跟 @ 才算。
     *
     * @param list<string> $docBuf
     * @return list<string> ['api'|'internal', ...]
     */
    private static function annotationsOf(array $docBuf): array
    {
        $out = [];
        foreach ($docBuf as $line) {
            $t = trim($line);
            if (preg_match('/^\*\s*@(api|internal)\b/', $t, $m) === 1) {
                $out[] = $m[1];
                continue;
            }
            // 单行 docblock 的首行：`/** @internal 说明 */`
            if (preg_match('#^/\*\*\s*@(api|internal)\b#', $t, $m) === 1) {
                $out[] = $m[1];
            }
        }
        return $out;
    }

    /**
     * docblock 里**提及**（含字样但非标注）的行数 —— grep 口径的污染源。
     *
     * @param list<string> $docBuf
     */
    private static function mentionsOf(array $docBuf): int
    {
        $n = 0;
        foreach ($docBuf as $line) {
            if (preg_match('/@(api|internal)\b/', $line) !== 1) {
                continue;
            }
            $t = trim($line);
            $isAnnotation = preg_match('/^\*\s*@(api|internal)\b/', $t) === 1
                || preg_match('#^/\*\*\s*@(api|internal)\b#', $t) === 1;
            if (!$isAnnotation) {
                ++$n;
            }
        }
        return $n;
    }

    /** @return array{kind: string, symbol: string}|null kind：class/method/function/const/property */
    private static function parseDeclaration(string $trim): ?array
    {
        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(class|interface|trait|enum)\s+(\w+)/', $trim, $m) === 1) {
            return ['kind' => $m[1], 'symbol' => $m[2]];
        }
        if (preg_match('/^(?:(?:final|abstract)\s+)?(?:public\s+|protected\s+|private\s+)?(?:static\s+)?function\s+(\w+)/', $trim, $m) === 1) {
            return ['kind' => 'method', 'symbol' => $m[1] . '()'];
        }
        if (preg_match('/^function\s+(\w+)/', $trim, $m) === 1) {
            return ['kind' => 'function', 'symbol' => $m[1] . '()'];
        }
        if (preg_match('/^(?:public\s+|protected\s+|private\s+)?(?:static\s+)?(?:readonly\s+)?const\s+(\w+)/', $trim, $m) === 1) {
            return ['kind' => 'const', 'symbol' => $m[1]];
        }
        // property：类型含 `?` / `\Ns\Type` / 联合类型，一律按"非空白非 = 非 ;"吞
        if (preg_match('/^(?:public|protected|private)\s+(?:static\s+)?(?:readonly\s+)?[^\s=;]+\s+\$(\w+)/', $trim, $m) === 1) {
            return ['kind' => 'property', 'symbol' => '$' . $m[1]];
        }
        return null;
    }

    /** @param array{kind: string, symbol: string} $decl */
    private static function symbolOf(array $decl, ?string $currentClass): string
    {
        $typeKinds = ['class', 'interface', 'trait', 'enum'];
        if (\in_array($decl['kind'], $typeKinds, true) || $currentClass === null) {
            return $decl['symbol'];
        }
        return $currentClass . '::' . $decl['symbol'];
    }

    private static function layerOf(string $rel): string
    {
        $parts = explode('/', $rel);
        return \count($parts) > 1 ? $parts[1] : '(root)';
    }
}
