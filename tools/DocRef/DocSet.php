<?php
declare(strict_types=1);

namespace Drift\Dev\DocRef;

/**
 * 文档集 —— docref 三条检查的薄共享层。
 *
 * ★ 只做"读一次、查多次"的共享索引，不藏判据（判据在各 Check 里）：
 *   · `lines()`    文件行数（`file:line` 越界判据的右端）
 *   · `headings()` 标题编号集合（§ 断链判据的右端）
 *   · `resolve()`  引用 → 真实路径（完整路径直查 / 裸 basename 白名单解析）
 *
 * ★ **裸 basename 为什么不扫全仓**（2026-10-24 实测）：全仓 3556 个
 *   php/md/cc/h 文件里有 **518 个 basename 重复** —— `tools/Lint/fixtures/`
 *   （lint 的假副本）、`apps/<app>/build/`（AOT 产物）、`tools/Preview/` vs
 *   `apps/showcase/`（真实重复）。扫全仓 ⇒ `Element.php` 会因 fixtures 副本
 *   报**假歧义**。所以只扫白名单目录并排除 fixtures / build。
 */
final class DocSet
{
    /** 裸 basename 的解析范围（按优先级从左到右） */
    private const SEARCH_DIRS = ['src', 'tools', 'apps', 'docs'];

    /** 排除：lint 夹具 / 构建产物 / 归档 / 仓库元数据（这些里的同名文件不是引用目标） */
    private const EXCLUDE = ['/fixtures/', '/build/', '/_build/', '/.git/', '/vendor/', '/node_modules/', '/docs/_archive/'];

    /** @var array<string, int> 路径 → 行数 */
    private array $lineCounts = [];

    /** @var array<string, list<string>> 路径 → 标题编号集合（如 '2.1' / '23.2'） */
    private array $headingSets = [];

    /** @var array<string, list<string>> basename → 路径列表 */
    private array $basenameIndex = [];

    /** @var list<string> 全部受检文档（docs/** + 根级入口文档） */
    private array $docFiles = [];

    public function __construct(private readonly string $root)
    {
    }

    /** 仓库根（Check 读文档原文用） */
    public function root(): string
    {
        return $this->root;
    }

    // ─────────────────────────── 文档集 ───────────────────────────

    /** @return list<string> 受检 .md（相对仓库根，正斜杠） */
    public function docFiles(): array
    {
        if ($this->docFiles === []) {
            $this->docFiles = $this->collectDocs();
        }

        return $this->docFiles;
    }

    // ─────────────────────────── 行数 ───────────────────────────

    public function lines(string $path): ?int
    {
        $rel = $this->normalize($path);
        if ($rel === null) {
            return null;
        }
        if (!isset($this->lineCounts[$rel])) {
            $abs = $this->root . '/' . $rel;
            if (!is_file($abs)) {
                return null;
            }
            // 行数 = 换行符个数 + 末行无换行时的补 1（与 wc -l 口径一致：
            // wc -l 数的是 \n 个数；文件以 \n 结尾时两者相等）
            $text = (string) file_get_contents($abs);
            $n = substr_count($text, "\n");
            if ($text !== '' && !str_ends_with($text, "\n")) {
                ++$n;
            }
            $this->lineCounts[$rel] = $n;
        }

        return $this->lineCounts[$rel];
    }

    // ─────────────────────────── 标题编号 ───────────────────────────

    /**
     * 文件的标题编号集合。
     *
     * ★ 兼容两种实测形态：`## §23.2 xxx`（根 `DESIGN.md`）与 `## 2.1 xxx`
     *   （`docs/architecture/` / `docs/design-v7/`）—— § 前缀可有可无，
     *   编号后必须跟 空格/冒号/句点/行尾（`## 2.1` / `## 2. ` / `## 2`）。
     *   `## 2` 这种"只有一级编号"的也收（§ 引用 `§2` 是高频形态）。
     * ★ 正则必须带 `u` 修饰符：无 `u` 时 PCRE **逐字节**处理，`§?` 实际是
     *   "`\xc2` 必需 + `\xa7` 可选" ⇒ 没有 § 的标题**全都不匹配**（2026-10-24
     *   实测踩到：`(?:§)?` 能修，但 `u` 才是正解）。
     * ★ 编号归一化：`00` → `0`（`## 00. xxx` 与引用 `§0` 对齐）。
     *
     * @return list<string>
     */
    public function headings(string $path): array
    {
        $rel = $this->normalize($path);
        if ($rel === null) {
            return [];
        }
        if (!isset($this->headingSets[$rel])) {
            $abs = $this->root . '/' . $rel;
            if (!is_file($abs)) {
                return [];
            }
            $out = [];
            foreach (file($abs, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                // `^#{1,6}\s*§?\s*(\d+(\.\d+)*)` 后面必须跟 空格/冒号/句点/行尾
                if (preg_match('/^#{1,6}\s*§?\s*(\d+(?:\.\d+)*)(?=[\s:：.]|$)/u', $line, $m) === 1) {
                    $out[] = self::normalizeSection($m[1]);
                }
            }
            $this->headingSets[$rel] = $out;
        }

        return $this->headingSets[$rel];
    }

    /** 标题编号归一：单级去前导零（`00`→`0`）；多级原样（`10.2` 不动） */
    public static function normalizeSection(string $section): string
    {
        if (!str_contains($section, '.')) {
            return (string) (int) $section;
        }

        return $section;
    }

    // ─────────────────────────── 引用解析 ───────────────────────────

    /**
     * 把文档里的文件引用解析成仓库内真实路径。
     *
     * @return array{0: string, 1: string} [状态, 路径]
     *         状态：`ok`（路径已解析） / `external`（外部仓或绝对路径，不检查）
     *               / `missing`（文件不存在） / `ambiguous`（裸名多义，附候选列表）
     */
    public function resolve(string $ref): array
    {
        $ref = str_replace('\\', '/', $ref);

        // 绝对路径 / 别的仓（`D:/…` / `typephp/…` / `../` 出仓库）⇒ 不检查
        if (preg_match('#^[a-zA-Z]:/#', $ref) === 1 || str_starts_with($ref, 'typephp/')) {
            return ['external', $ref];
        }

        // 含目录分隔符 ⇒ 相对仓库根直查
        if (str_contains($ref, '/')) {
            $rel = ltrim($ref, './');
            return is_file($this->root . '/' . $rel) ? ['ok', $rel] : ['missing', $ref];
        }

        // 裸 basename：先试仓库根本身（`DESIGN.md` 住根，不在 SEARCH_DIRS 里）
        if (is_file($this->root . '/' . $ref)) {
            return ['ok', $ref];
        }

        // 裸 basename ⇒ 白名单索引（src 优先）
        $cands = $this->basenameIndex()[$ref] ?? [];
        if ($cands === []) {
            return ['missing', $ref];
        }
        if (count($cands) === 1) {
            return ['ok', $cands[0]];
        }
        // src/ 下唯一 ⇒ 用 src 的（文档引用绝大多数指框架源码）
        $srcCands = array_values(array_filter($cands, static fn(string $p): bool => str_starts_with($p, 'src/')));
        if (count($srcCands) === 1) {
            return ['ok', $srcCands[0]];
        }

        return ['ambiguous', implode(', ', $cands)];
    }

    // ─────────────────────────── 内部 ───────────────────────────

    /** @return array<string, list<string>> */
    private function basenameIndex(): array
    {
        if ($this->basenameIndex !== []) {
            return $this->basenameIndex;
        }
        foreach (self::SEARCH_DIRS as $dir) {
            $absDir = $this->root . '/' . $dir;
            if (!is_dir($absDir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', $f->getPathname());
                $rel = substr($path, \strlen($this->root) + 1);
                // ★ rel 无前导斜杠，EXCLUDE 的 needle 全带前导斜杠 ⇒ 必须拼 '/' 再比
                //   （2026-10-24 实测：直接 str_contains($rel, '/docs/_archive/') 恒 false，
                //   整个排除列表静默失效，`_bak-v7` 归档副本混进裸名候选）
                $probe = '/' . $rel;
                $excluded = false;
                foreach (self::EXCLUDE as $needle) {
                    if (str_contains($probe, $needle)) {
                        $excluded = true;
                        break;
                    }
                }
                if ($excluded) {
                    continue;
                }
                $this->basenameIndex[$f->getFilename()][] = $rel;
            }
        }
        return $this->basenameIndex;
    }

    /** @return list<string> */
    private function collectDocs(): array
    {
        $out = [];
        // 根级入口文档必须受检：README / CHANGELOG / AGENTS 是读者第一站，
        // 漏掉它们 = 死链没人守（2026-11-11 实测 README 的 tutorial.md 死链）。
        foreach (['docs', 'DESIGN.md', 'README.md', 'CHANGELOG.md', 'AGENTS.md'] as $seed) {
            $abs = $this->root . '/' . $seed;
            if (is_file($abs)) {
                $out[] = $seed;
                continue;
            }
            // 文件型 seed 不存在就跳过（精简工作区 / selftest 的临时目录）：
            // 否则会被当成目录递归，RecursiveDirectoryIterator 直接抛异常。
            if (!is_dir($abs)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'md') {
                    $out[] = substr(str_replace('\\', '/', $f->getPathname()), \strlen($this->root) + 1);
                }
            }
        }
        // ★ 排除 `docs/_archive/`：归档是**历史快照**，引用有效性不维护
        //   （`_archive/` 里 34 个文档指向当时存在的文件与行号，改了反而失真）。
        $out = array_values(array_filter(
            $out,
            static fn(string $p): bool => !str_starts_with($p, 'docs/_archive/')
        ));
        sort($out);
        return $out;
    }

    private function normalize(string $path): ?string
    {
        $rel = str_replace('\\', '/', $path);
        // 只认仓库内相对路径；带盘符/外部前缀的不归 DocSet 管
        if (preg_match('#^[a-zA-Z]:/#', $rel) === 1 || str_starts_with($rel, 'typephp/')) {
            return null;
        }
        return ltrim($rel, './');
    }
}
