<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\ToggleApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\Semantics;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\SemanticsRole;
use Drift\Layout\RenderToggle;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;
use Drift\Widget\Checkbox;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Radio;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Toggle;

/**
 * 开关类控件（Checkbox / Radio / Toggle）第一批断言。
 *
 * 三条判据（与 `tools/smoke.php` ㉚ 段一致）：
 *   ① 形状只用 Core 层 op（软光栅也画得出，preview/golden 不炸）；
 *   ② 布局 = 控件 + 间距 + 标签，内禀与实排一致；
 *   ③ 激活走 `Application` 的唯一入口，回调收**新值**而不是"切换了"。
 *
 * ★ 像素断言走共享 `spinCanvas()`（tests/Support/functions.php）；
 *   语义断言走共享 `Semantics::element()`（tests/Support/Semantics.php）。
 *   这两条在 smoke 里是 `$semLay` 闭包与 `spinCanvas()` 全局函数，
 *   搬过来后各自一份、互不加载。
 */
final class ToggleTest extends SmokeTestCase
{
    public function testCheckboxMountsRenderToggleAndLaysOut(): void
    {
        $ctx = smokeContext();
        $gap = $ctx->metric(MetricToken::Gap);

        $cbEl = Checkbox::make('同意条款')->checked(true)->createElement();
        $cbEl->mount(null, $ctx);
        $cbBox = $cbEl->effectiveBox();
        check(
            'Toggle · Checkbox 挂载即建出 RenderToggle（kind = CHECKBOX）',
            $cbBox instanceof RenderToggle && $cbBox->kind() === RenderToggle::KIND_CHECKBOX,
            $cbBox === null ? 'box=null' : get_class($cbBox),
        );
        $cbBox?->layout(BoxConstraints::loose(new Size(300.0, 100.0)));
        $cbSize = $cbBox?->size();
        check(
            'Toggle · 布局 = 16 方框 + gap + 标签宽，高 = max(16, 标签高)；内禀与实排一致',
            $cbSize !== null
                && $cbSize->height >= 16.0
                && $cbSize->width > 16.0 + $gap
                && abs($cbBox->intrinsicWidth(INF) - $cbSize->width) < 0.01,
            $cbSize === null ? 'size=null' : sprintf('w=%.2f h=%.2f', $cbSize->width, $cbSize->height),
        );

        $this->flushChecks();
    }

    public function testCheckboxSelectedAndUnselectedPixels(): void
    {
        $ctx = smokeContext();
        $accent = $ctx->color(ColorToken::Accent);

        $cbEl = Checkbox::make('同意条款')->checked(true)->createElement();
        $cbEl->mount(null, $ctx);
        $cbBox = $cbEl->effectiveBox();
        $cbBox?->layout(BoxConstraints::loose(new Size(300.0, 100.0)));
        $cbSize = $cbBox?->size();
        $cbTop = max(0.0, ($cbSize?->height ?? 0.0) - 16.0) / 2.0;
        $cbCanvas = spinCanvas($cbBox, 64, 40);
        check(
            'Toggle · 选中态：方框内是强调色（drawRect 填充；勾是 drawLine，不是 drawPath）',
            $cbCanvas->pixelAt(5, (int) round($cbTop + 4.0))->equals($accent),
            'px=' . $cbCanvas->pixelAt(5, (int) round($cbTop + 4.0))->toArgb(),
        );

        $cbOff = Checkbox::make('同意条款')->checked(false)->createElement();
        $cbOff->mount(null, $ctx);
        $cbOffBox = $cbOff->effectiveBox();
        $cbOffBox?->layout(BoxConstraints::loose(new Size(300.0, 100.0)));
        $cbOffTop = max(0.0, ($cbOffBox?->size()?->height ?? 0.0) - 16.0) / 2.0;
        $cbOffCanvas = spinCanvas($cbOffBox, 64, 40);
        check(
            'Toggle · 未选中态：内部留白、边框有色（不是"什么都不画"）',
            $cbOffCanvas->pixelAt(5, (int) round($cbOffTop + 4.0))->equals(Color::white())
                && !$cbOffCanvas->pixelAt(5, (int) round($cbOffTop))->equals(Color::white()),
        );

        $this->flushChecks();
    }

    public function testRadioDerivesCheckedFromGroupValue(): void
    {
        $ctx = smokeContext();

        $rOn = Radio::make('中')->value('m')->group('m')->createElement();
        $rOn->mount(null, $ctx);
        $rOnBox = $rOn->effectiveBox();
        check(
            'Radio · 值 == 组值 ⇒ 选中；kind = RADIO',
            $rOnBox instanceof RenderToggle && $rOnBox->kind() === RenderToggle::KIND_RADIO && $rOnBox->isOn(),
        );
        $rOffEl = Radio::make('小')->value('s')->group('m')->createElement();
        $rOffEl->mount(null, $ctx);
        check(
            'Radio · 值 != 组值 ⇒ 未选中',
            $rOffEl->effectiveBox() instanceof RenderToggle && !$rOffEl->effectiveBox()->isOn(),
        );
        $rEmpty = Radio::make('空值')->group('')->createElement();
        $rEmpty->mount(null, $ctx);
        check(
            'Radio · 双方都空值也不算选中（空值不能把整组点亮）',
            $rEmpty->effectiveBox() instanceof RenderToggle && !$rEmpty->effectiveBox()->isOn(),
        );

        $this->flushChecks();
    }

    public function testToggleTrackCapsulePixels(): void
    {
        $ctx = smokeContext();
        $accent = $ctx->color(ColorToken::Accent);

        // 开关：轨道 18 高 × 34.2 宽（1.9:1 胶囊），选中时整条轨道是强调色
        $swEl = Toggle::make('通知')->checked(true)->createElement();
        $swEl->mount(null, $ctx);
        $swBox = $swEl->effectiveBox();
        $swBox?->layout(BoxConstraints::loose(new Size(300.0, 100.0)));
        $swCanvas = spinCanvas($swBox, 80, 40);
        $swMinX = PHP_INT_MAX;
        $swMaxX = -1;
        $swMinY = PHP_INT_MAX;
        $swMaxY = -1;
        for ($sy = 0; $sy < 40; $sy++) {
            for ($sx = 0; $sx < 80; $sx++) {
                if (!$swCanvas->pixelAt($sx, $sy)->equals($accent)) {
                    continue;
                }
                $swMinX = min($swMinX, $sx);
                $swMaxX = max($swMaxX, $sx);
                $swMinY = min($swMinY, $sy);
                $swMaxY = max($swMaxY, $sy);
            }
        }
        $swW = $swMaxX - $swMinX + 1;
        $swH = $swMaxY - $swMinY + 1;
        check(
            'Toggle · 开关轨道 34.2×18（胶囊：drawRect + radius = 高/2）',
            $swW >= 33 && $swW <= 35 && $swH >= 17 && $swH <= 19,
            sprintf('bbox=%dx%d', $swW, $swH),
        );

        $swOffEl = Toggle::make('通知')->checked(false)->createElement();
        $swOffEl->mount(null, $ctx);
        $swOffBox = $swOffEl->effectiveBox();
        $swOffBox?->layout(BoxConstraints::loose(new Size(300.0, 100.0)));
        $swOffCanvas = spinCanvas($swOffBox, 80, 40);
        $swOffAccent = 0;
        for ($sy = 0; $sy < 40; $sy++) {
            for ($sx = 0; $sx < 80; $sx++) {
                if ($swOffCanvas->pixelAt($sx, $sy)->equals($accent)) {
                    $swOffAccent++;
                }
            }
        }
        check('Toggle · 开关关闭态：轨道用空闲色，没有强调色像素', $swOffAccent === 0, 'accentPx=' . $swOffAccent);

        $this->flushChecks();
    }

    public function testTabSpaceActivationChain(): void
    {
        // ★★ 激活链：Tab 走到控件 + Space ⇒ `Application::activateFocusedWidget()` 的
        //   ToggleBase 分支 ⇒ 回调收新值。只测 `activate()` 测不到分支写错/顺序放错。
        $tglApp = new ToggleApplication();
        $tglApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 400.0)));
        // ★ 四步（不是五步）：夹具第五项是禁用复选框，2026-10-29 起**不在 Tab 序里**
        foreach ([1, 2, 3, 4] as $step) {
            $tglApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
            $tglApp->dispatchInput(KeyEvent::down(KeyChord::make('Space')));
        }
        check(
            'Toggle · Tab+Space 四步：未选→on、单选→自己的值、开关→off',
            $tglApp->events === [
                ['checkbox', 'on'],
                ['radio', 's'],
                ['switch', 'off'],
            ],
            json_encode($tglApp->events),
        );

        // 反例（新行为）：禁用项**不占 Tab 位** —— 五次 Tab 停在最后一个可用控件。
        // ★ 旧行为是"禁用仍在 Tab 序、只是不响应 Space"，那样 Tab 要走五格才绕过它，
        //   用户按四次 Tab 就到不了最后一个可用控件。
        $tglApp2 = new ToggleApplication();
        $tglApp2->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 400.0)));
        $tglFm = $tglApp2->focus();
        $tglVisited = [];
        for ($step = 0; $step < 5; $step++) {
            $tglApp2->dispatchInput(KeyEvent::down(KeyChord::tab()));
            $tglVisited[] = $tglFm?->focused()?->widget()->semanticsName() ?? '';
        }
        check(
            'Toggle · 禁用项不在 Tab 序：五次 Tab = 同意→小→中→通知→通知（末尾停住）',
            $tglVisited === ['同意', '小', '中', '通知', '通知'],
            json_encode($tglVisited, \JSON_UNESCAPED_UNICODE),
        );

        $this->flushChecks();
    }

    public function testSemanticsRoles(): void
    {
        // 语义：角色 + name = label + value 跟选中态（a11y 的"读得出来"）
        $semTgl = SemanticsBuilder::build(Semantics::element(Column::make()->children([
            Checkbox::make('同意')->checked(true),
            Radio::make('中')->value('m')->group('m'),
            Toggle::make('通知')->checked(false)->enabled(false),
        ])));
        $semCb = $semTgl->findByRoleAndName(SemanticsRole::Checkbox->value, '同意');
        $semRadio = $semTgl->findByRoleAndName(SemanticsRole::Radio->value, '中');
        $semSwitch = $semTgl->findByRoleAndName(SemanticsRole::Switch->value, '通知');
        check(
            'Sem · Checkbox：角色 checkbox、name = 标签、value = checked',
            $semCb >= 0 && $semTgl->node($semCb)->value === 'checked',
            'idx=' . $semCb,
        );
        check(
            'Sem · Radio：value = 选中项自己的值',
            $semRadio >= 0 && $semTgl->node($semRadio)->value === 'm',
            'idx=' . $semRadio,
        );
        check(
            'Sem · Toggle：value = unchecked 且 enabled = false',
            $semSwitch >= 0
                && $semTgl->node($semSwitch)->value === 'unchecked'
                && $semTgl->node($semSwitch)->enabled === false,
            'idx=' . $semSwitch,
        );

        $this->flushChecks();
    }
}
