<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * 发起 Optional 层的 op 之前，同类内必须查过能力位（G2：不许在 Raster 上炸）。
 *
 * 背景：`05` §2.5 判据推论 3。`drawImage` / `drawShadow` 是 Optional 层
 * （只有 Skia 级后端实现；Raster 遇到就响亮失败 E-OP-UNSUPPORTED）。
 * 控件 / demo 页直接发这两个 op ⇒ 同一份 Widget 在 preview / golden / smoke
 * 上当场 Fatal。正确形态是发起前 `capabilities()` 查一次位，没有就换一种
 * 呈现或跳过。
 *
 * 判据（三条同时满足才报）：
 *   ① 一个**类区间**（class / interface / trait / enum；无类的文件整份算一个
 *     区间）内出现了 `->drawImage(` / `->drawShadow(` / `::drawImage(` 调用
 *     —— `function drawImage(` 的**定义**不算（前一个 token 是 T_FUNCTION）；
 *   ② 同一个类区间内**没有**出现过 `->capabilities(` / `::capabilities(`；
 *   ③ 该文件引用了画布（出现过 `Canvas` / `CustomPainter` 类型名）——
 *      否则 `$x->drawImage()` 可能是任意同名方法，静态无从区分。
 *
 * ★ 放宽到"同类内"而**不做跨方法数据流**（`05` §2.5 的误报处置）：在 `init()`
 *   里查一次、`paint()` 里用，本规则判不出来 ⇒ 宁漏不误响（误报会训练人
 *   忽略规则）。跨方法的那一半由评审守。
 * ★ `CustomPaint` 的画家（`extends CustomPainter`）是主要消费者；普通控件
 *   拿不到 Canvas，判据 ③ 天然把它们排除在外。
 */
final class OptionalOpGuard implements Rule
{
    /** Optional 层的 op：小写方法名 => [方法名, 能力位常量名] */
    private const OPS = [
        'drawimage' => ['drawImage', 'CAP_IMAGE'],
        'drawshadow' => ['drawShadow', 'CAP_SHADOW'],
    ];

    public function name(): string
    {
        return 'optional-op-guard';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return '发 drawImage/drawShadow 前同类内必须查过 capabilities()（不许在 Raster 上炸）';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            $relative = $file->path();
            // ★ `src/Rendering/` 是画布层与命令分发层自己 —— `DisplayList::replay()`
            //   必须无条件下发每一个 op（它是管道不是消费者），`Canvas` 接口与三个
            //   后端的实现也在那里。规则守的是"控件 / demo 页不许假设后端有
            //   Optional op"，守不到管道自己头上。
            if (str_starts_with($relative, 'src/Rendering/')) {
                continue;
            }
            $tokens = $file->tokens();
            if (!$this->mentionsCanvas($tokens)) {
                continue; // 判据 ③：文件与画布无关 ⇒ 不判
            }

            foreach ($this->classRanges($tokens) as [$start, $end]) {
                $capsSeen = false;
                $calls = [];

                for ($i = $start; $i <= $end; $i++) {
                    $token = $tokens[$i];
                    if (!is_array($token) || $token[0] !== T_STRING) {
                        continue;
                    }
                    $lower = strtolower((string) $token[1]);

                    if ($lower === 'capabilities') {
                        // `x->capabilities(` / `x::capabilities(`：前是对象运算符、
                        // 后是调用括号（函数定义的前一个 token 是 T_FUNCTION）
                        $prev = $this->prevSignificant($tokens, $i);
                        $next = $this->nextSignificant($tokens, $i, $end);
                        if (($prev === '->' || $prev === '::') && $next === '(') {
                            $capsSeen = true;
                        }
                        continue;
                    }

                    if (!isset(self::OPS[$lower])) {
                        continue;
                    }
                    // 后一个有效 token 必须是 `(`（调用，不是类型标注/常量）
                    if ($this->nextSignificant($tokens, $i, $end) !== '(') {
                        continue;
                    }
                    // 前一个有效 token 是 T_FUNCTION ⇒ 是定义，不是调用
                    $prev = $this->prevSignificantToken($tokens, $i);
                    if (is_array($prev) && $prev[0] === T_FUNCTION) {
                        continue;
                    }
                    $calls[] = [$lower, $token[2]];
                }

                if ($capsSeen || $calls === []) {
                    continue;
                }

                foreach ($calls as [$lower, $line]) {
                    $op = self::OPS[$lower];
                    $found[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::ERROR,
                        $file->path(),
                        $line,
                        sprintf(
                            '这个类里直接发起了 Optional 层的 %s()，但同类内没有查过 capabilities() '
                            . '—— 软光栅后端（preview / golden / smoke）遇到它会当场 Fatal（E-OP-UNSUPPORTED）。'
                            . '两条修法：① 发起前查位（$caps = $canvas->capabilities()，'
                            . '没有 %s 就跳过或换一种呈现）；② 这个 op 改成 Extended 层的等价物。',
                            $op[0],
                            $op[1]
                        )
                    );
                }
            }
        }

        return $found;
    }

    /**
     * 类区间表：`list<array{int, int}>`（token 下标，闭区间）。
     *
     * 无类的文件（脚本 / fixtures 里的过程代码）返回整份一个区间 ——
     * 全局代码同样要守"查过能力位再发 op"。
     *
     * @param list<mixed> $tokens
     * @return list<array{int, int}>
     */
    private function classRanges(array $tokens): array
    {
        $ranges = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] !== T_CLASS
                && $token[0] !== T_INTERFACE
                && $token[0] !== T_TRAIT
                && $token[0] !== T_ENUM
            ) {
                continue;
            }
            // 匿名类 `new class {` 与命名的都算；往下找第一个 `{` 再配对
            $open = $this->nextCurlyOpen($tokens, $i + 1);
            if ($open === null) {
                continue;
            }
            $close = $this->matchCurly($tokens, $open);
            if ($close === null) {
                continue;
            }
            $ranges[] = [$i, $close];
            $i = $close;
        }

        if ($ranges === []) {
            return [[0, $count - 1]];
        }

        return $ranges;
    }

    /** @param list<mixed> $tokens */
    private function nextCurlyOpen(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if ($tokens[$i] === '{') {
                return $i;
            }
        }

        return null;
    }

    /** 花括号配对（token 流里字符串/注释已是独立 token ⇒ 直接数就行） */
    private function matchCurly(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i] === '{') {
                $depth++;
                continue;
            }
            if ($tokens[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 前一个有效 token 的**字面值**（字符串 token 原样、数组 token 取文本）。
     *
     * @param list<mixed> $tokens
     */
    private function prevSignificant(array $tokens, int $i)
    {
        $token = $this->prevSignificantToken($tokens, $i);
        if ($token === null) {
            return null;
        }

        return is_array($token) ? $token[1] : $token;
    }

    /**
     * @param list<mixed> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function prevSignificantToken(array $tokens, int $i)
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if (is_array($token) && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function nextSignificantToken(array $tokens, int $i, int $limit)
    {
        $count = min($limit + 1, count($tokens));
        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if (is_array($token) && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private function nextSignificant(array $tokens, int $i, int $limit)
    {
        $token = $this->nextSignificantToken($tokens, $i, $limit);
        if ($token === null) {
            return null;
        }

        return is_array($token) ? $token[1] : $token;
    }

    /**
     * 文件是否与画布有关（判据 ③）。
     *
     * @param list<mixed> $tokens
     */
    private function mentionsCanvas(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] !== T_STRING
                && $token[0] !== T_NAME_QUALIFIED
                && $token[0] !== T_NAME_FULLY_QUALIFIED
            ) {
                continue;
            }
            $short = strtolower((string) $token[1]);
            $pos = strrpos($short, '\\');
            if ($pos !== false) {
                $short = substr($short, $pos + 1);
            }
            if ($short === 'canvas' || $short === 'custompainter') {
                return true;
            }
        }

        return false;
    }
}
