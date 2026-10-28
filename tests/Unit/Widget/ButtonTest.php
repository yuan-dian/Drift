<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderButton;
use Drift\Layout\RenderText;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\IconButton;
use Drift\Widget\IconName;
use Drift\Widget\NavButton;

/**
 * Button / NavButton / IconButton：变体薄壳、回调、换肤、盒几何、图标排布。
 *
 * 判据（与 `tools/smoke.php` ㉒ 段一致）：
 *   ① `primary()`/`danger()` 只是 `make()->variant()` 的薄壳 —— 糖删掉框架契约不变
 *      （§15.4 删除测试）；
 *   ② 配色经 BuildContext 解析一次（§15.7 纪律 3），Layout 层不碰主题；
 *   ③ 图标是**加宽**不是替换文字，且标签排在图标右侧；像素判据抓"算了偏移但没画"
 *      （2026-10-25 的 `setOffset()` 未被 `paintContent()` 使用的回归）；
 *   ④ 三个类的对齐语义不同：Button 居中（Material）、NavButton 左对齐（菜单）、
 *      IconButton 纯图标是正方形且不建 RenderText。
 *
 * ★ 全在声明层 + 单盒布局/光栅，不需要帧驱动 ⇒ Unit 层。
 */
final class ButtonTest extends SmokeTestCase
{
    /** @return list<RenderBox> */
    private function kidsOf(?RenderBox $box): array
    {
        $kids = [];
        $box?->visitChildren(static function (RenderBox $kid) use (&$kids): void {
            $kids[] = $kid;
        });

        return $kids;
    }

    public function testVariantAliasesAreThinShells(): void
    {
        // "糖只转发"的删除测试：primary/danger 必须等价于 make()->variant(...)
        $primaryDirect = Button::primary('刷新');
        $primaryForward = Button::make('刷新')->variant(ButtonVariant::Primary);
        check(
            'Button · primary() 是 make()+variant() 的薄壳（糖删掉框架契约不变）',
            $primaryDirect->variantValue() === $primaryForward->variantValue()
                && $primaryDirect->variantValue() === ButtonVariant::Primary
        );
        check('Button · danger() 同形', Button::danger('删')->variantValue() === ButtonVariant::Danger);

        $this->flushChecks();
    }

    public function testOnPressedIsPassedThroughVerbatim(): void
    {
        // onPressed 是纯 UI 回调；Button 本身不依赖命令系统
        $fired = 0;
        $btn = Button::make('取消')->onPressed(function () use (&$fired): void { $fired++; });
        $handler = $btn->pressedHandler();
        check('Button · onPressed 闭包原样递下去（不是包一层的机制）', $handler !== null && $handler() === null && $fired === 1);

        $this->flushChecks();
    }

    public function testThemeColorsResolvePerContext(): void
    {
        $ctx = smokeContext();
        $darkContext = new \Drift\Widget\BuildContext(320.0, 240.0, \Drift\Theme\ThemeData::dark(), $ctx->metrics());

        // 造盒 → 配色经 BuildContext 解析一次（§15.7 纪律 3），Layout 层不碰主题
        $lightBtn = Button::primary('刷新')->createRenderObject($ctx);
        $darkBtn = Button::primary('刷新')->createRenderObject($darkContext);
        check('Button · 造出 RenderButton', $lightBtn instanceof RenderButton);
        check(
            'Button · 同一变体在明/暗下取到不同底色（换肤改表不改控件）',
            $lightBtn->boxStyle()->background?->toArgb() !== $darkBtn->boxStyle()->background?->toArgb(),
        );
        check(
            'Button · Default 变体有边框、Primary 没有（描边是 Default 的辨识手段）',
            Button::make('x')->createRenderObject($ctx)->boxStyle()->borderColor !== null
                && Button::primary('x')->createRenderObject($ctx)->boxStyle()->borderColor === null
        );

        $this->flushChecks();
    }

    public function testBoxSizeIsPaddingPlusLabel(): void
    {
        $ctx = smokeContext();

        // 端到端：盒子尺寸 = 内边距 + 标签，且 Label 是它的孩子（按钮文字换行规则与 Text 一致）
        $btnRoot = Button::primary('刷新')->createElement();
        $btnRoot->mount(null, $ctx);
        $btnBox = $btnRoot->renderObject();
        $btnBox?->layout(BoxConstraints::loose(new Size(200.0, 60.0)));
        $btnKids = $this->kidsOf($btnBox);
        check(
            'Button · 标签是它的唯一孩子（RenderText）',
            count($btnKids) === 1 && $btnKids[0] instanceof RenderText,
        );
        check(
            'Button · 盒尺寸 = 内边距 + 标签（不是 0，也不是填满约束）',
            ($btnBox?->size()?->width ?? 0.0) > 0.0 && ($btnBox?->size()?->width ?? 0.0) < 200.0,
            sprintf('实际 %.2f × %.2f', $btnBox?->size()?->width ?? -1.0, $btnBox?->size()?->height ?? -1.0)
        );

        $this->flushChecks();
    }

    public function testIconWidensInsteadOfReplacingLabel(): void
    {
        $ctx = smokeContext();

        // ── 图标按钮（2026-10-25）：图标与标签在**同一个盒**里排 ───────────────────
        //   ★ 不是 `Row(图标, 文字)`：`Flex::createRenderObject()` 只建空壳、子盒由 Element
        //     挂 ⇒ 手工拼出来的 `RenderFlex` 没有孩子（详见 `RenderButton` 类注释）。
        $iconDecl = Button::make('列表')->icon(IconName::ListBullets);
        check(
            'Button · icon() 把图标声明留在 Widget 上',
            $iconDecl->iconValue() === IconName::ListBullets,
        );

        $plainIconBox = Button::make('列表')->createRenderObject($ctx);
        $withIconBox = $iconDecl->createRenderObject($ctx);
        check(
            'Button · 声明图标后造出的盒带图标（未声明的不带）',
            $withIconBox instanceof RenderButton && $withIconBox->hasIcon()
                && $plainIconBox instanceof RenderButton && !$plainIconBox->hasIcon(),
        );

        $plainIconBox->layout(BoxConstraints::loose(new Size(300.0, 60.0)));
        $withIconBox->layout(BoxConstraints::loose(new Size(300.0, 60.0)));
        check(
            'Button · 图标是**加宽**（图标 + 间距），不是替换文字',
            ($withIconBox->size()?->width ?? 0.0) > ($plainIconBox->size()?->width ?? 0.0) + 16.0,
            sprintf(
                'plain=%.1f icon=%.1f',
                $plainIconBox->size()?->width ?? -1.0,
                $withIconBox->size()?->width ?? -1.0,
            ),
        );

        // ★ 回归：2026-10-25 之前 `setOffset()` 的偏移**从未被 `paintContent()` 使用**
        //   （那里只 `translate($insets)`）⇒ 文字永远贴内容区左上；加图标后表现为
        //   "图标压在首字上"。判据：标签的 offsetX 至少是一个图标宽。
        $iconKids = $this->kidsOf($withIconBox);
        check(
            'Button · 图标排在文字左侧（标签 offsetX ≥ 图标宽，不是 0）',
            $iconKids !== [] && $iconKids[0]->offsetX() >= 18.0,
            'offsetX=' . ($iconKids === [] ? 'no-child' : (string) $iconKids[0]->offsetX()),
        );

        $this->flushChecks();
    }

    public function testPixelsShowContentCentered(): void
    {
        $ctx = smokeContext();

        // ★★ 像素判据：`setOffset()` 设对了 ≠ 画的时候用了它。上面那条只看布局期，
        //   而 2026-10-25 的 bug 恰好是"布局算了偏移、绘制时只用 insets" ⇒ 必须看墨。
        //   判据：紧约束（200 宽）下内容**整体居中** ⇒ 左右留白相等；贴左时右留白远大于左。
        $pixelBtn = Button::make('列表')
            ->variant(ButtonVariant::Flat)   // 不画盒 ⇒ 墨只来自图标与文字
            ->icon(IconName::ListBullets)
            ->createRenderObject($ctx);
        $pixelBtn->layout(BoxConstraints::tight(new Size(200.0, 40.0)));
        $pixelRgba = spinCanvas($pixelBtn, 200, 40)->toRgba();

        $firstInk = -1;
        $lastInk = -1;
        for ($x = 0; $x < 200; $x++) {
            for ($y = 0; $y < 40; $y++) {
                $o = ($y * 200 + $x) * 4;
                if (ord($pixelRgba[$o]) < 200 || ord($pixelRgba[$o + 1]) < 200 || ord($pixelRgba[$o + 2]) < 200) {
                    if ($firstInk < 0) {
                        $firstInk = $x;
                    }
                    $lastInk = $x;
                    break;
                }
            }
        }
        check(
            'Button · 图标+文字整体居中（左右留白差 ≤ 3px —— 抓"算了偏移但没画"）',
            $firstInk >= 0 && $lastInk > $firstInk && abs($firstInk - (199 - $lastInk)) <= 3,
            sprintf('ink=[%d,%d] left=%d right=%d', $firstInk, $lastInk, $firstInk, 199 - $lastInk),
        );

        $this->flushChecks();
    }

    public function testNavButtonLeftAlignsContent(): void
    {
        $ctx = smokeContext();

        // ★ 导航左对齐（2026-10-25）：`Button` 内容居中（Material 语义），`NavButton`
        //   左对齐（菜单语义）。判据是**不同长度标签的 label offset 相同** —— 居中时
        //   短标签会明显靠右，一列图标参差不齐（实测 49 / 59.5 / 33.5）。
        $navXs = [];
        foreach (['布局 Layout', '日志 Log', '分段 Segmented'] as $navLabel) {
            $navBox = NavButton::make($navLabel)->icon(IconName::Grid)->createRenderObject($ctx);
            $navBox->layout(BoxConstraints::tight(new Size(180.0, 40.0)));
            $navKids = $this->kidsOf($navBox);
            $navXs[] = $navKids === [] ? -1.0 : $navKids[0]->offsetX();
        }
        check(
            'NavButton · 内容左对齐（三种长度标签的 offset 相同 —— 居中会各不相同）',
            count(array_unique($navXs)) === 1 && ($navXs[0] ?? -1.0) >= 0.0,
            'xs=' . implode(',', $navXs),
        );

        $this->flushChecks();
    }

    public function testIconButtonIsSquareWithoutText(): void
    {
        $ctx = smokeContext();

        // 纯图标：正方形、不建 RenderText
        $iconOnlyBox = IconButton::of(IconName::Grid, '网格')->createRenderObject($ctx);
        $iconOnlyBox->layout(BoxConstraints::loose(new Size(300.0, 60.0)));
        $iconOnlyW = $iconOnlyBox->size()?->width ?? 0.0;
        $iconOnlyH = $iconOnlyBox->size()?->height ?? 0.0;
        $iconOnlyKids = $this->kidsOf($iconOnlyBox);
        check(
            'IconButton · 纯图标是正方形（横向内边距不翻倍）',
            $iconOnlyW > 0.0 && abs($iconOnlyW - $iconOnlyH) < 0.01,
            sprintf('%.1f × %.1f', $iconOnlyW, $iconOnlyH),
        );
        check(
            'IconButton · 不建 RenderText（空标签会占一行行高、把正方形撑成竖条）',
            $iconOnlyKids === [],
        );
        check(
            'IconButton · 可访问名来自 label（纯图标没有可读文本，名必须有）',
            IconButton::of(IconName::Close, '关闭')->semanticsName() === '关闭',
        );

        $this->flushChecks();
    }
}
