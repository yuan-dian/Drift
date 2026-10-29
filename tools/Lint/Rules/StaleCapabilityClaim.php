<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\ClassTable;
use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;

/**
 * 能力缺口「诚实边界」声明的过期守卫（B2 / G2：声明了就必须为真）。
 *
 * 背景：示例页的 docblock / 注释里写着"框架没有 X"这类**能力缺口陈述**。一旦 X
 *   之后被补上（`SizedBox`、`OP_ARC`、`Text::mono()`…），陈述就成了谎言却没人提醒
 *   —— `FormPage` 曾同时存在"类注释说没有 SizedBox / 页面文案说已补 / 代码里
 *   SizedBox.php 确实在"三处打架。本规则把这类打脸前移到 lint。
 *
 * ★ 为什么用**显式标记**而不是扫自由中文：中文"没有 / 缺席 / 做不到"里混着大量
 *   **主动取舍**（Slider 不做垂直、Snackbar 不做动画…），词法扫会把"有意不为"和
 *   "能力真缺"一锅端 ⇒ 误报。本规则只认作者主动声明的一行标记，把"是否值得被守"
 *   的判断交还给人，规则只做机械核对。
 *
 * 标记（写在 PHP 注释里）：capability-gap 指令 + 冒号 + 一个紧凑 probe，
 *   行内空白之后可跟理由（忽略）。本文件刻意不在注释里写出完整字面量，
 *   否则会被本规则自己扫到（常量 MARKER 定义在代码行，不是注释）。
 *
 * probe 语法（`|` 分节，段内不含空白）：
 *     `class|FQN`              宿主类是否已出现
 *     `method|FQN|name`        宿主类（含 extends 链）是否已声明该方法
 *     `const|FQN|NAME`         宿主类（含 extends 链）是否已声明该常量
 *
 * 语义：标记 = "作者声明这个能力现在缺失，补上请提醒我"。于是——
 *     符号已存在               ⇒ ERROR（陈述过期，请删标记并更新「诚实边界」说明）
 *     符号不存在               ⇒ 绿（声明仍诚实）
 *     宿主类不存在             ⇒ 绿（连宿主都没有，能力必然缺失；不为难未来的重构）
 *     探针类型未知 / 分节数不对 ⇒ ERROR（拼写错了必须响，不静默忽略）
 *
 * 诚实边界（漏报可接受，误报不可接受）：
 *   - 方法只查**类自身 + extends 链**的声明，不追 trait 里的方法；
 *   - 常量只记每条 `const` 声明的**第一个**名字（`const A=1,B=2;` 会漏 B）；
 *   - FQN 需写全限定名（注释不是代码，没有 use 上下文可解析）。
 *
 * 服务 G2（契约破坏要响亮）：一条"说了会缺"的声明，和一条"补上了"的事实不能
 *   同时为真 —— 打破时必须响，且前移到构建期而不是等读代码的人撞见。
 */
final class StaleCapabilityClaim implements Rule
{
    private const MARKER = '@capability-gap:';

    public function name(): string
    {
        return 'stale-capability-claim';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return '能力缺口声明过期守卫：显式标记声明"缺"的符号一旦已存在即报错（不扫自由中文）';
    }

    public function check(Project $project): array
    {
        $table = ClassTable::fromProject($project);
        $out = [];

        foreach ($project->files() as $file) {
            foreach ($this->markers($file) as [$probe, $line]) {
                $parsed = $this->parseProbe($probe);
                if ($parsed === null) {
                    $out[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::ERROR,
                        $file->path(),
                        $line,
                        sprintf(
                            'capability-gap 探针格式非法：%s —— 支持 class|FQN / method|FQN|name / const|FQN|NAME',
                            $probe,
                        ),
                    );
                    continue;
                }

                [$type, $class, $member] = $parsed;
                if ($this->symbolExists($table, $type, $class, $member)) {
                    $out[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::ERROR,
                        $file->path(),
                        $line,
                        sprintf(
                            '能力缺口声明已过期：%s 已经存在 —— 请删除标记并更新这处「诚实边界」说明',
                            $probe,
                        ),
                    );
                }
            }
        }

        return $out;
    }

    /**
     * 收集文件里全部 capability-gap 标记的 [probe, 行号]。
     *
     * 只认注释 token（T_COMMENT / T_DOC_COMMENT）—— 字符串字面量里出现同样字样
     * 不算（那是文档举例，不是声明）。行号按标记前的换行数精确回推。
     *
     * @return list<array{0: string, 1: int}>
     */
    private function markers(SourceFile $file): array
    {
        $out = [];
        foreach ($file->tokens() as $tok) {
            if (!is_array($tok) || ($tok[0] !== T_COMMENT && $tok[0] !== T_DOC_COMMENT)) {
                continue;
            }
            $text = $tok[1];
            if (!str_contains($text, self::MARKER)) {
                continue;
            }
            $pattern = '/' . preg_quote(self::MARKER, '/') . '\s*(\S+)/';
            if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($m[1] as [$probe, $pos]) {
                $line = $tok[2] + substr_count(substr($text, 0, (int) $pos), "\n");
                $out[] = [(string) $probe, $line];
            }
        }

        return $out;
    }

    /**
     * @return null|array{0: string, 1: string, 2: string} [类型, 宿主FQN, 成员名或'']；格式非法 ⇒ null
     */
    private function parseProbe(string $probe): ?array
    {
        $parts = explode('|', $probe);
        $type = $parts[0];

        if ($type === 'class' && count($parts) === 2 && $parts[1] !== '') {
            return ['class', $parts[1], ''];
        }
        if (($type === 'method' || $type === 'const') && count($parts) === 3
            && $parts[1] !== '' && $parts[2] !== '') {
            return [$type, $parts[1], $parts[2]];
        }

        return null;
    }

    private function symbolExists(ClassTable $table, string $type, string $class, string $member): bool
    {
        $all = $table->all();
        $class = ltrim($class, '\\');

        if ($type === 'class') {
            return isset($all[$class]);
        }

        // method / const：宿主类都不存在 ⇒ 能力必然仍缺，不算过期
        if (!isset($all[$class])) {
            return false;
        }

        $seen = [];
        $queue = [$class];
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
            if ($type === 'method' && isset($info['methods'][strtolower($member)])) {
                return true;
            }
            if ($type === 'const' && in_array($member, $info['constants'] ?? [], true)) {
                return true;
            }
            foreach ($info['extends'] as $parent) {
                $queue[] = $parent;
            }
        }

        return false;
    }
}
