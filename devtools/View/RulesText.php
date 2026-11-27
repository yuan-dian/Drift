<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

use Drift\DevTools\View\Rules\Rule;
use Drift\DevTools\View\Rules\RuleRegistry;

/**
 * 规则目录：把注册表摊成"读者能照着写命令行"的一页。
 *
 * ## 为什么由注册表生成而不是手写一份文档
 *
 * 手写的规则清单是第二份真相：加一条规则忘了更新它，读者会以为那条规则不存在，
 * 而 `--rule=<新编号>` 又确实能用 —— 两种表现互相矛盾，却都不报错。
 * 这里只做排版，编号、严重度、版本、说明全部来自 `Rule` 自己。
 *
 * ## 为什么版本要露出来
 *
 * `--json` 的每条发现都带 `version`，读者看到 `v2` 需要能查到"v1 到 v2 改了什么"。
 * 目录里给当前版本，至少能让两份报告的比较从"结论不一样"变成"规则换了一版"。
 */
final class RulesText
{
    /** 终端一页：编号、严重度、版本、说明。 */
    public static function render(): string
    {
        $rules = RuleRegistry::all();
        $lines = [\sprintf(
            '规则（%d 条，%d 个编号，按输出顺序）：',
            \count($rules),
            \count(RuleRegistry::ids()),
        )];

        // 列宽按**渲染后**的串算：多变体规则会拼成 `a / b`，只按单个 id 算宽度，
        // 那一行就会把后面的说明挤到列外。
        $width = 0;
        foreach ($rules as $rule) {
            $width = \max($width, \strlen(\implode(' / ', $rule->ids())));
        }

        foreach ($rules as $rule) {
            $lines[] = self::line($rule, $width);
        }

        $lines[] = '';
        $lines[] = '兜底结论（不可关闭；关掉等于报告为空）：';
        $lines[] = '  clean          全部规则跑过、都没触发、树完整';
        $lines[] = '  inconclusive   规则没触发，但树被截断 / 帧不完整';
        $lines[] = '  suppressed     规则触发了，但被 --rule / --without / --min-severity 挡掉';
        $lines[] = '';
        $lines[] = '用法：--rule=<编号>[,<编号>] 只跑这些；--without=<编号> 跳过；';
        $lines[] = '      --min-severity=info|warning|error 过滤呈现（判据照跑）';

        return \implode("\n", $lines) . "\n";
    }

    /**
     * 结构化版本，给 `--rules --json`。
     *
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        $rules = [];
        foreach (RuleRegistry::all() as $rule) {
            $rules[] = [
                'id' => $rule->id(),
                'ids' => $rule->ids(),
                'severity' => $rule->severity()->value,
                'version' => $rule->version(),
                'summary' => $rule->summary(),
            ];
        }

        return ['rules' => $rules, 'fallback' => RuleRegistry::FALLBACK_IDS];
    }

    /** 一条规则的呈现行。多变体规则把两个编号并列，读者不必去查映射。 */
    private static function line(Rule $rule, int $width): string
    {
        return \sprintf(
            '  %s %-*s v%d  %s',
            $rule->severity()->mark(),
            $width,
            \implode(' / ', $rule->ids()),
            $rule->version(),
            $rule->summary(),
        );
    }
}
