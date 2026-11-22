<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

use Drift\Dev\Lint\Rules\AliasMutate;
use Drift\Dev\Lint\Rules\AppBootstrapLoop;
use Drift\Dev\Lint\Rules\AppInstanceofConcrete;
use Drift\Dev\Lint\Rules\AppSubclassCtorParent;
use Drift\Dev\Lint\Rules\AppWorkerRole;
use Drift\Dev\Lint\Rules\AttributeUnqualified;
use Drift\Dev\Lint\Rules\AwaitProgress;
use Drift\Dev\Lint\Rules\AwaitScope;
use Drift\Dev\Lint\Rules\BackendBranch;
use Drift\Dev\Lint\Rules\CallbackArity;
use Drift\Dev\Lint\Rules\CancelGuard;
use Drift\Dev\Lint\Rules\CachedSubscriber;
use Drift\Dev\Lint\Rules\ClassMissingUse;
use Drift\Dev\Lint\Rules\CjkVarName;
use Drift\Dev\Lint\Rules\DeadCommand;
use Drift\Dev\Lint\Rules\DeadField;
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
use Drift\Dev\Lint\Rules\PageSelfWidget;
use Drift\Dev\Lint\Rules\PaintShift;
use Drift\Dev\Lint\Rules\ProjectName;
use Drift\Dev\Lint\Rules\LaneOwner;
use Drift\Dev\Lint\Rules\SelectPurity;
use Drift\Dev\Lint\Rules\StaleCapabilityClaim;
use Drift\Dev\Lint\Rules\StaleDerived;
use Drift\Dev\Lint\Rules\StaticPipelineFacade;
use Drift\Dev\Lint\Rules\StrConcatLoop;
use Drift\Dev\Lint\Rules\SubscriberMark;
use Drift\Dev\Lint\Rules\WorkerArgv;

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
            // 表格字段名拼写（2026-11-13 A②）：field('nmae') 从前只在渲染期 Fatal，0 行时静默空白。
            //   ★ 判据走独立路径（同文件行字段证据），不依赖生成层、不猜跨文件数据流。
            new DeadField(),
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
            // 同类病，另外三处语境（2026-11-02 真事故）：`new` / `::` / `catch (X $e)`
            //   ★ 事故：`WatermarkStore` 漏 `use DownloadResultsJob` ⇒ 真机点下载报
            //     `class 'App\Watermark\Store\DownloadResultsJob' is undefined`。
            //     四道闸门全绿的原因：该分支只在 `$host !== null`（真机）可达，
            //     smoke / phpunit 全走 `$host === null` 的同步降级路径。
            new ClassMissingUse(),
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
            // D3 收口守卫（2026-11-02）：静态门面删掉之后不许再长回来
            //   ★ 真事：22 个 `X::foo() { return Pipeline::current()->foo(); }`
            //     是 D3① 的"半个搬家" —— 字段走了、名字没走，新代码继续用旧名字。
            //     最坏的一处是 `State::boxEpoch()`：名字说 State，读的是渲染树全局序号。
            new StaticPipelineFacade(),
            // 绘制收口守卫（2026-11-03）：四行"平移后画子盒"从 10 处收成
            //   `RenderBox::paintChildAt()`，不许再手写第 11 份
            //   ★ 真事：收口前 10 份，`translate` 写反顺序或漏 `restore` 时
            //     栈配平、整帧不报错，偏的是**兄弟**的位置。
            new PaintShift(),
            // 滚动轴的宿主守卫（2026-11-03）：`new ScrollLane(...)` 少传宿主是**合法 PHP**，
            //   症状只是"滚动条不淡出"—— 界面稍微不对，没有任何东西会红。
            //   写入点四个（全量 / 懒列表 / 表格 ×2 轴 / 多行输入），第五个实现会复制邻近那个。
            new LaneOwner(),
            // await 作用域（2026-11-05 借 Drift3 async-scope-lint）：运行时 assertInside()
            //   在 AOT 嵌套函数里拦不住越界 ⇒ 作用域必须显式传参，静态可判。
            new AwaitScope(),
            // 进度断链（§68）：Job 报了 JobProgress 却没人订，界面静默退化成转圈。
            //   ★ 真事故：DownloadResultsJob 每张图报进度，await 漏第二参 ⇒ 无报错、
            //     落地结果也对，只有「下载阶段没有进度条」这一个可见症状。
            new AwaitProgress(),
            // 取消守卫顺序（§68）：宽 catch 吞掉 AsyncCancelled 后循环会多跑一轮，
            //   运行时 guardCancelled() 只在下个让出点补抛，错误文案已经写出去了。
            new CancelGuard(),
            // 中文串单引号（AGENTS 铁律，2026-11-11 机械化）：双引号里 `$n` 紧跟中文
            //   会被词法吃成 `$n项` ⇒ 静默取到未定义变量。全仓当前 0 处，纯防复发。
            new CjkVarName(),
            // AOT 回调 arity（2026-11-11 综合 review P0）：解释器忽略多余实参、
            //   AOT 当场 Fatal ⇒ 整页错误占位。内联闭包与 `\Closure` 工厂两种形态都守。
            new CallbackArity(),
            // S4：文档检查退役后，项目命名纪律不跟着消失（判据逐字迁自 V1 文档检查层）
            new ProjectName(),
            // 单类页面（Page 是 State 子类）里 `widget()` 返回的是宿主 PageShell：
            //   照旧拿它当"页面自己"会在运行期才炸，而金标快照未必覆盖那条交互路径。
            new PageSelfWidget(),
            // worker 开关只许在 WorkerProcess 一处拼（2026-11-22：三份组合根收成一处后的守卫）
            new WorkerArgv(),
            // 角色判定不许搬回应用层：`WorkerProcess` 只给 `AppEntry` 用
            new AppWorkerRole(),
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
