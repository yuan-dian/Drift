<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;
use Drift\Dev\Lint\Tokens;

/**
 * §2.4 纪律 6 / §16.7：`--nano` 的禁用清单，作为源码纪律。
 *
 * ★ 诚实边界（§16.6.3）：这条规则只保证**源码纪律**，查不到 tpc 自己注入的
 *   include 或链接期问题。加了它 ≠ 能切 nano —— 最终判据只能是真机 `--nano` 构建。
 *   它的定位是"零成本保留将来可切的可选性"，不是"nano 就绪"的证明。
 *
 * ★★ **`Fiber` 于 2026-11-04 解禁**（不在清单里了），三条理由：
 *   ① **nano 已明确不做**（`DESIGN.md` §16.7.5，2026-10-14 裁决，重估时机"不设"）
 *      ⇒ 这条禁令守的是一条不打算走的路；
 *   ② 它**不再零成本** —— 挡住 `AsyncScope`（线性异步）这条唯一能治
 *      `apps/watermark` 那种"N 步流水线拆成三份"的路，代价远大于可选性收益；
 *   ③ **实测通行**：`probes/fiber_scope` 在 AOT `-m bin` 产物里十一条边界全绿
 *      （跨帧 / 操作 tpc 编译类 / throw+finally / 回调栈恢复 / 多 Fiber / 嵌套 /
 *      深栈 / 传对象 / 局部消化 / getCurrent），且变异自检证明判据有牙。
 *      `docs/design-v7/11-编译器约束映射.md` §5.4 早就写着"`--nano` 不支持 Fiber
 *      ⇒ 走 `-m bin`" —— 二者本就互斥，本仓走的是 `-m bin`。
 *
 * ★ `Generator` / `yield` **仍然禁**：nano 之外没有第二条论据支持生成器，
 *   而"懒序列改用显式分页/窗口结构"已经落地。**没有消费者来推翻，就不动它。**
 */
final class NanoHygiene implements Rule
{
    /** 禁用函数：名字 => 展示名 */
    private const BANNED_FUNCTIONS = [
        'getenv' => 'getenv',
        'putenv' => 'putenv',
    ];

    /**
     * 禁用类型。
     *
     * ★ `Fiber` 曾在此（"C++17 无栈切换，无法承载"）—— 2026-11-04 移除，见类注释。
     */
    private const BANNED_TYPES = ['Generator'];

    /**
     * dev 壳与它的动态入口：`require` / `getenv` 正是它们存在的理由
     * （变量路径加载业务 PHP），且两者永不进 `--nano` 产物。按文件精确豁免，
     * 不按目录 —— 以后往 apps/dev/ 放业务代码仍受纪律约束。
     * ★ 纯运行期量具入口放 tools/（toolchain 豁免），别放 apps/dev/：
     *   那边是 drift-build.php 的输入指纹范围，放进去会触发壳重编。
     */
    private const DEV_SHELL_FILES = [
        'apps/dev/dev-shell.php',
        'apps/dev/dev-entry.php',
    ];

    public function name(): string
    {
        return 'nano-hygiene';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return '禁用匿名类 / yield / Generator / getenv / putenv / eval / include·require';
    }

    public function check(Project $project): array
    {
        $found = [];
        foreach ($project->files() as $file) {
            if ($project->isToolchain($file) || in_array($file->path(), self::DEV_SHELL_FILES, true)) {
                continue; // 工具 / dev 壳：不适用产物纪律（后者见 DEV_SHELL_FILES 注释）
            }
            foreach ($this->scan($file) as $hit) {
                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $hit['line'],
                    sprintf('禁用的构造：%s（§16.7）', $hit['what'])
                );
            }
        }
        return $found;
    }

    /** @return list<array{what: string, line: int}> */
    private function scan(SourceFile $file): array
    {
        $tokens = $file->tokens();
        $count = count($tokens);
        $hits = [];

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];

            if (!is_array($tok)) {
                if ($tok === '`') {
                    $hits[] = ['what' => '反引号 shell', 'line' => $this->lineAt($tokens, $i)];
                }
                continue;
            }

            [$id, $text, $line] = $tok;

            switch ($id) {
                case T_YIELD:
                case T_YIELD_FROM:
                    $hits[] = ['what' => 'yield', 'line' => $line];
                    break;
                case T_EVAL:
                    $hits[] = ['what' => 'eval', 'line' => $line];
                    break;
                case T_INCLUDE:
                case T_INCLUDE_ONCE:
                case T_REQUIRE:
                case T_REQUIRE_ONCE:
                    $hits[] = ['what' => strtolower($text), 'line' => $line];
                    break;
                case T_NEW:
                    $next = Tokens::next($tokens, $i + 1);
                    if ($next !== null && $next[0] === T_CLASS) {
                        $hits[] = ['what' => '匿名类', 'line' => $line];
                    }
                    break;
                case T_STRING:
                    $hit = $this->classifyString($tokens, $i, $text, $line);
                    if ($hit !== null) {
                        $hits[] = $hit;
                    }
                    break;
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                    $short = substr($text, (int) strrpos($text, '\\') + 1);
                    foreach (self::BANNED_TYPES as $type) {
                        if (strcasecmp($short, $type) === 0) {
                            $hits[] = ['what' => $type, 'line' => $line];
                            break;
                        }
                    }
                    break;
                default:
                    break;
            }
        }

        return $hits;
    }

    /** @return null|array{what: string, line: int} */
    private function classifyString(array $tokens, int $i, string $text, int $line): ?array
    {
        $lower = strtolower($text);
        $prev = Tokens::prev($tokens, $i - 1);
        $next = Tokens::next($tokens, $i + 1);

        if (isset(self::BANNED_FUNCTIONS[$lower])) {
            $isCall = $next !== null && $next[0] === '(';
            $isMember = $prev !== null && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
            if ($isCall && !$isMember) {
                return ['what' => self::BANNED_FUNCTIONS[$lower] . '()', 'line' => $line];
            }
            return null;
        }

        foreach (self::BANNED_TYPES as $type) {
            if (strcasecmp($text, $type) !== 0) {
                continue;
            }
            $asType = $prev !== null && in_array(
                $prev[0],
                [T_NEW, T_NS_SEPARATOR, T_IMPLEMENTS, T_EXTENDS, T_INSTANCEOF, T_FUNCTION],
                true
            );
            $asStatic = $next !== null && $next[0] === T_DOUBLE_COLON;
            if ($asType || $asStatic) {
                return ['what' => $type, 'line' => $line];
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private function lineAt(array $tokens, int $i): int
    {
        for ($n = $i; $n >= 0; $n--) {
            if (is_array($tokens[$n])) {
                return $tokens[$n][2];
            }
        }
        return 1;
    }
}
