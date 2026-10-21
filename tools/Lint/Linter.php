<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

use Drift\Dev\Lint\Rules\AliasMutate;
use Drift\Dev\Lint\Rules\AttributeUnqualified;
use Drift\Dev\Lint\Rules\CachedSubscriber;
use Drift\Dev\Lint\Rules\DeadCommand;
use Drift\Dev\Lint\Rules\DeadSubscription;
use Drift\Dev\Lint\Rules\DependencyDirection;
use Drift\Dev\Lint\Rules\InternalLeak;
use Drift\Dev\Lint\Rules\InstanceofMissingUse;
use Drift\Dev\Lint\Rules\NamespaceMismatch;
use Drift\Dev\Lint\Rules\NestedSubscribe;
use Drift\Dev\Lint\Rules\NanoHygiene;
use Drift\Dev\Lint\Rules\OrphanTrait;
use Drift\Dev\Lint\Rules\SelectPurity;
use Drift\Dev\Lint\Rules\StaleDerived;
use Drift\Dev\Lint\Rules\StrConcatLoop;
use Drift\Dev\Lint\Rules\SubscriberMark;

/**
 * 规则集与执行器。
 */
final class Linter
{
    /** @param list<Rule> $rules */
    public function __construct(private readonly array $rules)
    {
    }

    public static function defaultRules(): self
    {
        return new self([
            // 公理守卫（G1/G2）
            new DependencyDirection(),
            new InternalLeak(),
            new NamespaceMismatch(),
            new NanoHygiene(),
            new OrphanTrait(),
            // §16.6.3 第二批（2026-10-15 按优先级补）
            new DeadCommand(),
            new DeadSubscription(),
            new SelectPurity(),
            new StaleDerived(),
            new CachedSubscriber(),
            new AliasMutate(),
            // §16.6.3 第三批（2026-10-15 按优先级补）
            new SubscriberMark(),
            new StrConcatLoop(),
            new NestedSubscribe(),
            // instanceof 漏 use（2026-10-19：静默恒 false 缺陷模式，本仓咬过 ≥3 次）
            new InstanceofMissingUse(),
            // 内建编译期注解漏 \（2026-10-21 W0/A0：`#[Override]` 解析成 `当前命名空间\Override`，静默无效）
            new AttributeUnqualified(),
        ]);
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @return list<Diagnostic> */
    public function run(Project $project): array
    {
        $all = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->check($project) as $diagnostic) {
                $all[] = $diagnostic;
            }
        }

        usort($all, static function (Diagnostic $a, Diagnostic $b): int {
            return [$a->file, $a->line, $a->rule] <=> [$b->file, $b->line, $b->rule];
        });

        return $all;
    }
}
