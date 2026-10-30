<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

use Drift\Dev\Lint\Rules\AliasMutate;
use Drift\Dev\Lint\Rules\AppBootstrapLoop;
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
use Drift\Dev\Lint\Rules\NonBoxDecoration;
use Drift\Dev\Lint\Rules\OptionalOpGuard;
use Drift\Dev\Lint\Rules\OrphanTrait;
use Drift\Dev\Lint\Rules\SelectPurity;
use Drift\Dev\Lint\Rules\StaleCapabilityClaim;
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
            // I2 闸门（2026-10-25 立，2026-10-30 N2 改判据）：组合根必须走 EventLoop::run()
            //   ★ 真事故：apps/tutorial 只 frame() ⇒ after() 在真机上永不触发，
            //     而 smoke 全绿（用例里每帧都是显式跑的，不需要"有人叫醒循环"）
            //   ★ N2 之后"等 / 派发 / 跑帧 / 光标 / IME / 退出"六条契约只在 EventLoop
            //     里各写一次 ⇒ 判据从"建 FrameClock 并 tick()"改成"走 EventLoop"
            new AppBootstrapLoop(),
            // 内建编译期注解漏 \（2026-10-21 W0/A0：`#[Override]` 解析成 `当前命名空间\Override`，静默无效）
            new AttributeUnqualified(),
            // W1/L3-6（`05` §2.5 两条）：禁后端分叉 / 发 Optional op 前查能力位
            new BackendBranch(),
            new OptionalOpGuard(),
            // 不产盒的控件套修饰（2026-10-25：运行期 §11.7 守卫的静态前置）
            //   ★ 真事故：`showcase` 第 21 页 `ScrollView->paddingAll()` —— 点开才炸，
            //     `Card.php` 的注释记着同一个坑已咬过"7 个页面"
            new NonBoxDecoration(),
            // 能力缺口「诚实边界」声明过期守卫（2026-10-30 B2）：显式标记 + 类型化 probe，
            //   符号已补即报错；不扫自由中文，避免误伤主动取舍文案。
            new StaleCapabilityClaim(),
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
