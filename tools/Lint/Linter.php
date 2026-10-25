<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

use Drift\Dev\Lint\Rules\AliasMutate;
use Drift\Dev\Lint\Rules\AppBootstrapFrameClock;
use Drift\Dev\Lint\Rules\AppInstanceofConcrete;
use Drift\Dev\Lint\Rules\AppSubclassCtorParent;
use Drift\Dev\Lint\Rules\AttributeUnqualified;
use Drift\Dev\Lint\Rules\BackendBranch;
use Drift\Dev\Lint\Rules\CachedSubscriber;
use Drift\Dev\Lint\Rules\DeadCommand;
use Drift\Dev\Lint\Rules\DeadSubscription;
use Drift\Dev\Lint\Rules\DependencyDirection;
use Drift\Dev\Lint\Rules\InternalLeak;
use Drift\Dev\Lint\Rules\InstanceofMissingUse;
use Drift\Dev\Lint\Rules\NamespaceMismatch;
use Drift\Dev\Lint\Rules\NestedSubscribe;
use Drift\Dev\Lint\Rules\NanoHygiene;
use Drift\Dev\Lint\Rules\OptionalOpGuard;
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
            // I2 闸门（2026-10-25 S1 第 10 步）：App 层不得认下层实现的具体类
            //   ★ 判据是"不许认具体类"，不是"只许接口" —— 见规则类头的两条反例
            new AppInstanceofConcrete(),
            // I2 闸门（2026-10-25）：Application 子类覆写构造器必须调父构造器
            //   ★ 真事故：ShowcaseApplication 漏调 ⇒ 七个拆分片未初始化 ⇒ 真机
            //     启动 exit=255 且无行号，而 smoke 79 处 attach() 全绿
            new AppSubclassCtorParent(),
            // I2 闸门（2026-10-25）：组合根必须建 FrameClock 并 tick()
            //   ★ 真事故：apps/tutorial 只 frame() ⇒ after() 在真机上永不触发，
            //     而 smoke 全绿（用例里每帧都是显式跑的，不需要"有人叫醒循环"）
            new AppBootstrapFrameClock(),
            // 内建编译期注解漏 \（2026-10-21 W0/A0：`#[Override]` 解析成 `当前命名空间\Override`，静默无效）
            new AttributeUnqualified(),
            // W1/L3-6（`05` §2.5 两条）：禁后端分叉 / 发 Optional op 前查能力位
            new BackendBranch(),
            new OptionalOpGuard(),
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
