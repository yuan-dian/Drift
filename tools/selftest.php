<?php
declare(strict_types=1);

/**
 * drift-lint 的量具双向自检（§20 纪律 1）。
 *
 * 每条规则都配一组夹具：既验"该响会响"（已知错误），也验"不该响不误响"
 * （已知正确）。没有这层的 lint 只能证明"跑了"，不能证明"测得准"。
 *
 * @return bool 全部通过为 true
 */

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Linter;
use Drift\Dev\Lint\Project;

function drift_lint_selftest(string $root, Linter $linter): bool
{
    $fixtures = $root . '/tools/Lint/fixtures';
    $project = Project::scan($fixtures, [], ['src', 'apps']);
    $diagnostics = $linter->run($project);

    $actual = [];
    foreach ($diagnostics as $diagnostic) {
        $actual[$diagnostic->rule][] = $diagnostic->file . ':' . $diagnostic->severity;
    }

    // 每条规则的"已知错误"用例 —— 文件:严重度
    $expected = [
        'dependency-direction' => [
            'src/Geometry/Bad.php:' . Diagnostic::ERROR,
            'src/Widget/UsesTheme.php:' . Diagnostic::ERROR,
            'src/Widget/UsesCommands.php:' . Diagnostic::ERROR,
        ],
        'internal-leak' => [
            'apps/Bad.php:' . Diagnostic::ERROR,
            'apps/Exempt.php:' . Diagnostic::WARN,
            // 方法级（`07` §2.2 ②，W7 第 40 步）：三种可解析接收者各一条。
            // ★ `apps/MethodGood.php` 一条都不该有 —— 它验的是三件"看着像但不是"：
            //   调 @api 方法 / 调自己覆写的同名方法 / 接收者是 `foo()->m()`（不猜）。
            'apps/MethodBad.php:' . Diagnostic::WARN,
            'apps/MethodBad.php:' . Diagnostic::WARN,
            'apps/MethodBad.php:' . Diagnostic::WARN,
        ],
        'namespace-mismatch' => [],
        // 2026-11-04 补夹具（此前一直是空数组 ⇒ 只验过"能报"，没验过"不误报"）。
        // 5 条 = `\Generator` 返回类型 1 + `yield` 2 + `eval` 1 + `getenv()` 1。
        // ★ `apps/NanoGood.php` 一条都不该有：它钉的是 `Fiber` 已解禁
        //   （属性类型 / `new` / 静态调用三处）+ `getenv` 作方法名不误报。
        'nano-hygiene' => [
            'apps/NanoBad.php:' . Diagnostic::ERROR,
            'apps/NanoBad.php:' . Diagnostic::ERROR,
            'apps/NanoBad.php:' . Diagnostic::ERROR,
            'apps/NanoBad.php:' . Diagnostic::ERROR,
            'apps/NanoBad.php:' . Diagnostic::ERROR,
        ],
        'orphan-trait' => [],
        // §16.6.3 第二批（2026-10-15 按优先级补）
        'dead-command' => [
            'apps/CommandFixture.php:' . Diagnostic::ERROR,
            'apps/CommandFixture.php:' . Diagnostic::ERROR,
        ],
        'dead-subscription' => [
            'src/Widget/DeadSubscriptionFixture.php:' . Diagnostic::ERROR,
        ],
        // 2026-11-07 补 `static fn` / `static function` 形态（+5）：
        // 从前 isClosure() 漏认 T_STATIC ⇒ 全仓 24 处真实 select 从未进过判据。
        // 2026-11-08（+4）：readonly 白名单从「返回类型 / 体内 new」两条通道移除，
        // 夹具各补一条 Bad 形态（fn + static fn 的 ReadonlyBox）⇒ 8 → 12。
        // Good 侧钉住 `array` / static function 标量 / readonly + Equatable 不误报。
        'select-purity' => [
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
            'apps/SelectFixture.php:' . Diagnostic::ERROR,
        ],
        'stale-derived' => [
            'apps/CartStore.php:' . Diagnostic::ERROR,
        ],
        'cached-subscriber' => [
            'apps/CachedSubscriberFixture.php:' . Diagnostic::ERROR,
            'apps/CachedSubscriberFixture.php:' . Diagnostic::ERROR,
        ],
        'alias-mutate' => [
            'apps/AliasChildrenFixture.php:' . Diagnostic::ERROR,
        ],
        // §16.6.3 第三批（2026-10-15 按优先级补）
        'subscriber-mark' => [
            'apps/SubscriberMarkFixture.php:' . Diagnostic::ERROR,
            'apps/SubscriberMarkFixture.php:' . Diagnostic::ERROR,
        ],
        'str-concat-loop' => [
            'apps/StrConcatFixture.php:' . Diagnostic::ERROR,
            'apps/StrConcatFixture.php:' . Diagnostic::ERROR,
        ],
        'nested-subscribe' => [
            'apps/NestedSubscribeFixture.php:' . Diagnostic::WARN,
        ],
        // instanceof 漏 use（2026-10-19）
        'instanceof-missing-use' => [
            'apps/InstanceofBad.php:' . Diagnostic::ERROR,
        ],
        // 漏 use 的另外三处语境（2026-11-02 真事故：watermark 下载结果图）
        // ★ Bad 三条：new / `::` / catch 各一条；`ClassMissingUseGood.php` 一条都不该有 ——
        //   它验的五件"看着像但不是"：use 过的新建与静态访问、全限定名、内建类 catch、
        //   `self::` 与变量左侧、匿名类。
        'class-missing-use' => [
            'apps/ClassMissingUseBad.php:' . Diagnostic::ERROR,
            'apps/ClassMissingUseBad.php:' . Diagnostic::ERROR,
            'apps/ClassMissingUseBad.php:' . Diagnostic::ERROR,
        ],
        // 内建编译期注解漏 \（2026-10-21 W0/A0）
        'attribute-unqualified' => [
            'apps/AttributeBad.php:' . Diagnostic::ERROR,
            'apps/AttributeBad.php:' . Diagnostic::ERROR,
            'apps/AttributeBad.php:' . Diagnostic::ERROR,
        ],
        // W1/L3-6（`05` §2.5 两条）
        // ★ backend-branch 报 2 条：`use ...RasterCanvas`（import 本身就是依赖
        //   具体后端）+ `instanceof RasterCanvas`（分叉本体）。删 import 不解决问题，
        //   写全限定名一样被 T_NAME_FULLY_QUALIFIED 抓到。
        'backend-branch' => [
            'src/Widget/BackendBranchBad.php:' . Diagnostic::ERROR,
            'src/Widget/BackendBranchBad.php:' . Diagnostic::ERROR,
        ],
        'optional-op-guard' => [
            'apps/OptionalOpBad.php:' . Diagnostic::ERROR,
        ],
        // 不产盒的控件套修饰（运行期 §11.7 守卫的静态前置）
        // ★ 只报 Bad 的两条；`NonBoxDecorationGood.php` 一条都不该有 ——
        //   它验的四件"看着像但不是"：产盒 / 覆写转发（`Card` 形态，误报过一次）/
        //   StatefulWidget 产盒 / 接收者是变量（不猜）。
        'nonbox-decoration' => [
            'apps/NonBoxDecorationBad.php:' . Diagnostic::ERROR,
            'apps/NonBoxDecorationBad.php:' . Diagnostic::ERROR,
        ],
        // 能力缺口「诚实边界」声明过期守卫（B2，2026-10-30）
        // ★ Bad 五条：class/method/const 三种"符号已存在" + 未知探针类型 + 分节数不足；
        //   `StaleCapabilityClaimGood.php` 一条都不该有 —— 它验的三件"看着像但绿"：
        //   符号确实还缺（method/const 探针查不到）/ 宿主类不存在 / 自由中文"没有"但没打标记。
        'stale-capability-claim' => [
            'apps/StaleCapabilityClaimBad.php:' . Diagnostic::ERROR,
            'apps/StaleCapabilityClaimBad.php:' . Diagnostic::ERROR,
            'apps/StaleCapabilityClaimBad.php:' . Diagnostic::ERROR,
            'apps/StaleCapabilityClaimBad.php:' . Diagnostic::ERROR,
            'apps/StaleCapabilityClaimBad.php:' . Diagnostic::ERROR,
        ],
        // 组合根必须走 `EventLoop::run()`（2026-10-25 立，2026-10-30 N2 改判据）
        // ★ 这条规则之前**一条夹具都没有** ⇒ selftest 里恒是"该响 0 / 不该响 0"，
        //   也就是说"它能不能响"从来没被证过（路径型规则最容易写成永不触发的空壳）。
        'app-bootstrap-loop' => [
            'apps/Loop/bin/win32/Bootstrap.php:' . Diagnostic::ERROR,
        ],
        // 子类覆写构造器必须调 `parent::__construct()`。
        // ★ 到 2026-10-30 为止这条规则**一条夹具都没有** ⇒ 恒是"该响 0"，
        //   也就是"它能不能响"从来没被证过。当天把 `Widget` 收进基类表时，
        //   顺手补了反例：`Widget` 漏调父构造器的后果比 `Application` 更难看懂
        //   （AOT 报 `The parameter object must be object, got null`，指向
        //   `Widget::mods()`，看不出是哪一层）。
        'app-subclass-ctor-parent' => [
            'apps/WidgetCtorWithoutParent.php:' . Diagnostic::ERROR,
        ],
        // 静态门面回流守卫（2026-11-02 D3 收口）。
        // ★ 两个"该响"覆盖读/写两种形态；`PipelineFacadeGood.php` 一条都不该有 ——
        //   它验的是三件"看着像但不是"：`$this->pipeline()` / 实例方法直调 / 工厂转发。
        //   第 ③ 件很重要：判据若写成"所有单行静态转发"，本仓 5 处工厂会一起误伤。
        'static-pipeline-facade' => [
            'src/Core/PipelineFacadeBad.php:' . Diagnostic::ERROR,
            'src/Core/PipelineFacadeBad.php:' . Diagnostic::ERROR,
        ],
        // 绘制收口守卫（2026-11-03）：四行从 10 处收成 `RenderBox::paintChildAt()`。
        // ★ `PaintShiftGood.php` 一条都不该有 —— 它验的四件"看着像但不是"：
        //   走 paintChildAt / 平移量是普通数值 / 两个参数不同源（`\1` 反向引用）/
        //   offset 恒 0 时直接 paintComposed。
        //   第 ③ 件最关键：判据若只写"translate 里出现 offsetX"，`RenderButton`
        //   那种"`$this->dx`"以外的写法会一起误伤。
        'paint-shift' => [
            'src/Layout/PaintShiftBad.php:' . Diagnostic::ERROR,
        ],
        // await 作用域（2026-11-05）：三条「该响」覆盖属性链 / 函数内赋值两种越界；
        // AwaitScopeGood.php 一条都不该有 —— 钉死「不能要求 await 直接写在 run 闭包体内」。
        'await-scope' => [
            'apps/AwaitScopeBad.php:' . Diagnostic::ERROR,
            'apps/AwaitScopeBad.php:' . Diagnostic::ERROR,
            'apps/AwaitScopeBad.php:' . Diagnostic::ERROR,
        ],
        // §68（2026-11-07）：Job 报进度但 await 没订（内联 / 变量两种形状）；
        // AwaitProgressGood.php 三条都不该响 —— 钉死「已订第二参 / 静默 Job」不误报。
        'await-progress' => [
            'apps/AwaitProgressBad.php:' . Diagnostic::ERROR,
            'apps/AwaitProgressBad.php:' . Diagnostic::ERROR,
        ],
        // 可取消上下文的宽 catch（await / sleep 两种），Good 侧钉住「有守卫 / 无让出点」。
        'cancel-guard' => [
            'apps/CancelGuardBad.php:' . Diagnostic::ERROR,
            'apps/CancelGuardBad.php:' . Diagnostic::ERROR,
        ],
    ];

    $ok = true;
    foreach ($linter->rules() as $rule) {
        $name = $rule->name();
        $got = $actual[$name] ?? [];
        $want = $expected[$name] ?? [];
        sort($got);
        sort($want);

        if ($got === $want) {
            printf("  ok    %-22s 该响 %d / 不该响 0\n", $name, count($want));
            continue;
        }

        $ok = false;
        printf("  FAIL  %-22s\n", $name);
        printf("        期望: %s\n", $want === [] ? '(无)' : implode(', ', $want));
        printf("        实际: %s\n", $got === [] ? '(无)' : implode(', ', $got));
    }

    foreach (array_keys($actual) as $name) {
        if (!isset($expected[$name])) {
            $ok = false;
            printf("  FAIL  规则 %s 产出了发现，但没有配对的夹具断言\n", $name);
        }
    }

    return $ok;
}
