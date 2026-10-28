<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\SelectStateApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\Option;
use Drift\Widget\OptionStatus;

/**
 * Select 的三态 / 方向键 / 跨树重建（2026-10-24）。
 *
 * 钉住"面板内容随状态变"这条链：浮层是**第二棵根渲染树**，主树重建不带动它
 * ⇒ 全靠 `SelectState` 的 `Subject`（方向键）与 `didUpdateWidget` 签名比对
 * （受控值 / 三态 / 选项表）。
 *
 * ★ 反例自检（2026-10-24 实测）：把 `SelectState::didUpdateWidget()` 里的
 *   `parent::didUpdateWidget($widget)` 摘掉 ⇒ 三态 5 条 + 跨树 1 条**恰好 6 条红**，
 *   而方向键 3 条**仍绿** —— 方向键走的是 `Subject` 广播、不经过那条缝。
 *   这个"红 6 绿 3"的形状就是这套用例确实守着跨树重建的证据。
 * ★ 每处要跑**两帧**：`Application::frame()` 先 `placeOverlays()`（settle 浮层 +
 *   布局）再 `Frame::run()`（settle 主树）。受控值一变，主树重建发生在后者，面板的
 *   `markDirty()` 也在后者 —— 此时浮层布局段已跑完 ⇒ 新盒下一帧才排。60fps 下不可见
 *   （16ms），但用例必须按实际帧序写，否则会把"晚一帧"误判成"没生效"。
 */
final class SelectStateTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(600.0, 400.0));
    }

    /** 挂上夹具 ⇒ `[app, host]` */
    private function boot(): array
    {
        $app = new SelectStateApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $app->frame(1000.0, $this->constraints());

        return [$app, $app->host()];
    }

    /** Tab 到触发框 ⇒ Enter 开面板 */
    private function open(SelectStateApplication $app, float $t): void
    {
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        $app->frame($t, $this->constraints());
    }

    /** 跨树重建要两帧：主树 settle 在浮层布局之后 */
    private function settle(SelectStateApplication $app, float $t): void
    {
        $app->frame($t, $this->constraints());
        $app->frame($t + 1.0, $this->constraints());
    }

    public function testEnterOpensPanel(): void
    {
        [$app, $host] = $this->boot();
        check('Select 三态 · 夹具挂上（根是 StatefulWidget，有 State）', $host !== null);

        $this->open($app, 1100.0);
        check(
            'Select 三态 · Enter 开面板，且 onOpen 恰好被调一次',
            $app->overlayCount() === 1 && $host?->openCount === 1,
            'overlay=' . $app->overlayCount() . ' open=' . ($host?->openCount ?? -1)
        );
        check('Select 三态 · 面板 3 项（三态都没设时的正常路径）', \count($app->optionButtons()) === 3);
    }

    /**
     * 方向键全链：高亮起点 → Down 一格 → Up 环绕。
     *
     * ★ 一条链而不是三个方法：`changed` 是累积记录，"Down 提交下一项"那条断言
     *   （`['310000', '440100']`）本身就包含了前一步的提交 —— 拆开就要改断言内容。
     */
    public function testArrowKeysWalkAndWrap(): void
    {
        [$app, $host] = $this->boot();
        $this->open($app, 1100.0);

        // —— 打开时高亮 = 当前值（310000 ⇒ 下标 1）⇒ Enter 直接提交它 ——
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        $app->frame(1200.0, $this->constraints());
        check(
            'Select 方向键 · 打开时高亮落在当前值上 ⇒ Enter 提交它',
            $host?->changed === ['310000'],
            'changed=' . \implode(',', $host?->changed ?? [])
        );
        check('Select 方向键 · 提交后面板自己收起', $app->overlayCount() === 0);

        // —— Down 移一格 ——
        $this->open($app, 1300.0);
        $app->dispatchInput(KeyEvent::down(KeyChord::down()));
        $app->frame(1400.0, $this->constraints());
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        $app->frame(1500.0, $this->constraints());
        check(
            'Select 方向键 · Down 一格 ⇒ 提交下一项（440100，不是当前值）',
            $host?->changed === ['310000', '440100'],
            'changed=' . \implode(',', $host?->changed ?? [])
        );

        // —— Up 环绕：下标 1 往上两次 ⇒ 1 → 0 → 末项（2）——
        $this->open($app, 1600.0);
        $app->dispatchInput(KeyEvent::down(KeyChord::up()));
        $app->frame(1700.0, $this->constraints());
        $app->dispatchInput(KeyEvent::down(KeyChord::up()));
        $app->frame(1800.0, $this->constraints());
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        $app->frame(1900.0, $this->constraints());
        check(
            'Select 方向键 · Up 到顶后环绕到末项（与 FocusManager 的 Tab 环同手感）',
            $host?->changed === ['310000', '440100', '440100'],
            'changed=' . \implode(',', $host?->changed ?? [])
        );
    }

    public function testLoadingAndErrorReplacePanelContent(): void
    {
        [$app, $host] = $this->boot();
        $this->open($app, 1100.0);

        $host?->setStatus(OptionStatus::loading());
        $this->settle($app, 2100.0);
        check(
            'Select 三态 · loading ⇒ 面板 0 个候选按钮（不是还列着旧选项）',
            $app->optionButtons() === [],
            'n=' . \count($app->optionButtons())
        );
        check(
            'Select 三态 · loading ⇒ 面板里是"正在加载…"（跨树重建的证据）',
            \in_array('正在加载…', $app->panelTexts(), true),
            'texts=' . \implode('|', $app->panelTexts())
        );

        $host?->setStatus(OptionStatus::error('加载失败'));
        $this->settle($app, 2200.0);
        check(
            'Select 三态 · error ⇒ 面板里是错误行',
            \in_array('加载失败', $app->panelTexts(), true) && $app->optionButtons() === [],
            'texts=' . \implode('|', $app->panelTexts())
        );
    }

    public function testErrorThenLoadingReplacesValue(): void
    {
        [$app, $host] = $this->boot();
        $this->open($app, 1100.0);

        $host?->setStatus(OptionStatus::error('加载失败'));
        $this->settle($app, 2200.0);

        // ★ 2026-10-24：三态收成 `OptionStatus` 之后这里**没有优先级**可测了 ——
        //   值互斥，后设的替换先设的。"重试时 error 与 loading 都真"那个场景在类型上
        //   就不存在（旧版专门有一条"loading 优先于 error"的用例，见附八）。
        $host?->setStatus(OptionStatus::loading());
        $this->settle($app, 2300.0);
        check(
            'Select 三态 · error 之后设 loading ⇒ 只剩"正在加载…"（值替换，不是优先级）',
            \in_array('正在加载…', $app->panelTexts(), true) && !\in_array('加载失败', $app->panelTexts(), true),
            'texts=' . \implode('|', $app->panelTexts())
        );
    }

    public function testEmptyFallbackAndOptionsChange(): void
    {
        [$app, $host] = $this->boot();
        $this->open($app, 1100.0);

        // 选项表空了但应用没说这是空态 ⇒ **控件内置**兜底文案（不是可配置的 `emptyText`）
        $host?->setStatus(OptionStatus::ready());
        $host?->setOptions([]);
        $this->settle($app, 2400.0);
        check(
            'Select 三态 · 空表 + ready ⇒ 内置"没有可选项"（不是一片空白）',
            \in_array('没有可选项', $app->panelTexts(), true) && $app->optionButtons() === [],
            'texts=' . \implode('|', $app->panelTexts())
        );

        // 应用**主动声明**空态 ⇒ 用它给的文案（与上面那条的区别：文案归应用）
        $host?->setStatus(OptionStatus::empty('没有匹配的城市'));
        $this->settle($app, 2450.0);
        check(
            'Select 三态 · OptionStatus::empty(文案) ⇒ 面板用应用的文案',
            \in_array('没有匹配的城市', $app->panelTexts(), true) && $app->optionButtons() === [],
            'texts=' . \implode('|', $app->panelTexts())
        );

        // 面板开着时换选项表 ⇒ 项数跟着变（框架的 `overlaySignature()` 在起作用）
        $host?->setStatus(OptionStatus::ready());
        $host?->setOptions([Option::of('甲'), Option::of('乙')]);
        $this->settle($app, 2500.0);
        check(
            'Select 跨树 · 面板开着时换选项表 ⇒ 面板项数跟着变',
            \count($app->optionButtons()) === 2,
            'n=' . \count($app->optionButtons())
        );
    }
}
