<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Overlay;

use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Smoke\App\ModalApplication;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderModalHost;
use Drift\Layout\RenderPopupPanel;
use Drift\Layout\RenderProxy;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\IconButton;
use Drift\Widget\Modal;
use Drift\Widget\PopupPanel;
use Drift\Widget\Text;

/**
 * 模态：复用浮层槽位 + 紧约束盖满 + 遮罩自绘 + 拒绝 ≠ 放行。
 *
 * ★ 客户区取 480×360（不是 320）：`Modal` 默认 minWidth=320，320 宽的客户区会让
 *   面板几乎盖满 ⇒ 遮罩区只剩投影留白，"四角都是遮罩"这条判据就废了。
 * ★ `dismissible=false` 的判据必须成对（"不关" + "被吞"）：只验"不关"的话
 *   放行给下面的按钮也照样绿。
 * ★ 点面板内按钮 ⇒ 命令分发但模态**不自己关**（关不关归应用）—— 这是 §A.3.7 的裁决，
 *   不是实现细节。
 */
final class ModalTest extends SmokeTestCase
{
    private const SIZE = [480.0, 360.0];

    private static function boot(?ModalApplication &$app = null): array
    {
        [$w, $h] = self::SIZE;
        $surface = new SvgSurface();
        $surface->resize((int) $w, (int) $h, 1.0);
        $tight = BoxConstraints::tight(new Size($w, $h));
        $app = new ModalApplication();
        $app->attach($surface, $tight);

        return [$app, $surface, $tight];
    }

    /** 沿盒树找第一个 PopupPanel（模态不自造 chrome，形体复用菜单那套） */
    private static function findPanel(?RenderBox $node): ?RenderPopupPanel
    {
        if ($node === null) {
            return null;
        }
        if ($node instanceof RenderPopupPanel) {
            return $node;
        }
        foreach (childBoxes($node) as $kid) {
            $hit = self::findPanel($kid);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    public function testOpensAndCoversClientArea(): void
    {
        [$app, , $tight] = self::boot();
        check(
            '模态 · attach 后默认没开（槽位与菜单共用，两个开关各自诚实）',
            !$app->isModalOpen() && $app->modal() === null && !$app->isMenuOpen(),
        );

        $app->openConfirm();
        $app->frame(16.0, $tight);
        check(
            '模态 · showModal ⇒ 打开（浮层槽位被占，但 isMenuOpen() 仍为假）',
            $app->isModalOpen() && $app->overlays()->top()?->element !== null && !$app->isMenuOpen(),
        );

        $box = $app->overlays()->top()?->element?->effectiveBox();
        check('模态 · 浮层盒是 RenderModalHost（遮罩 + 盖满住同一个盒）', $box instanceof RenderModalHost);
        check(
            '模态 · 紧约束盖满客户区（不是自尺寸 —— 盖不满就"后面还能点"）',
            $box?->size() !== null
                && abs($box->size()->width - 480.0) < 0.01
                && abs($box->size()->height - 360.0) < 0.01,
            sprintf('实际 %.6g × %.6g', $box?->size()?->width ?? -1.0, $box?->size()?->height ?? -1.0),
        );
        $top = $app->overlays()->top();
        check('模态 · 原点归零（模态不钉锚点）', $top !== null && $top->x === 0.0 && $top->y === 0.0);
    }

    public function testPanelIsCenteredPopupPanel(): void
    {
        [$app, , $tight] = self::boot();
        $app->openConfirm();
        $app->frame(16.0, $tight);

        $hostBox = $app->overlays()->top()?->element?->effectiveBox();
        $panel = self::findPanel($hostBox);
        check('模态 · 面板是 PopupPanel（形体复用，模态不自造一套 chrome）', $panel instanceof RenderPopupPanel);

        $rect = $panel?->panelRect();
        [$px, $py] = $panel instanceof RenderBox ? boxOrigin($panel) : [0.0, 0.0];
        $cx = $px + ($rect?->left ?? 0.0) + ($rect?->width ?? 0.0) / 2.0;
        $cy = $py + ($rect?->top ?? 0.0) + ($rect?->height ?? 0.0) / 2.0;
        check(
            '模态 · 面板居中（Center 组合出来的，不是盒里写死的偏移）',
            abs($cx - 240.0) < 1.0 && abs($cy - 180.0) < 1.0,
            sprintf('面板中心 (%.6g, %.6g)，客户区中心 (240, 180)', $cx, $cy),
        );
    }

    public function testBarrierIsTranslucentAndBelowPanel(): void
    {
        [$app, , $tight] = self::boot();
        $app->openConfirm();
        $app->frame(16.0, $tight);

        $hostBox = $app->overlays()->top()?->element?->effectiveBox();
        $panel = self::findPanel($hostBox);
        $rect = $panel?->panelRect();
        [$px, $py] = $panel instanceof RenderBox ? boxOrigin($panel) : [0.0, 0.0];

        $raster = new RasterCanvas(480, 360, Color::white(), Scale::of(1.0));
        $hostBox?->paint($raster);
        $barrier = $raster->pixelAt(2, 2);
        check(
            '模态 · 遮罩半透明混色（白底 × 35% 黑 ⇒ 灰；纯白/纯黑都说明 alpha 没走通）',
            $barrier->r === $barrier->g && $barrier->g === $barrier->b
                && $barrier->r > 120 && $barrier->r < 230,
            sprintf('角落 rgb(%d,%d,%d)', $barrier->r, $barrier->g, $barrier->b),
        );

        $cornersOk = true;
        foreach ([[2, 2], [477, 2], [2, 357], [477, 357]] as $pt) {
            if (!$raster->pixelAt($pt[0], $pt[1])->equals($barrier)) {
                $cornersOk = false;
            }
        }
        check('模态 · 遮罩覆盖四角（盖满是真的盖满，不是画了个中间块）', $cornersOk);

        $panelPx = $raster->pixelAt(
            (int) round($px + ($rect?->left ?? 0.0) + 8.0),
            (int) round($py + ($rect?->top ?? 0.0) + 8.0),
        );
        check(
            '模态 · 面板画在遮罩之上（面板内像素 ≠ 遮罩色）',
            !$panelPx->equals($barrier),
            sprintf('面板内 rgb(%d,%d,%d)', $panelPx->r, $panelPx->g, $panelPx->b),
        );
    }

    public function testDismissibleTrueClosesOnEscAndClearsSlot(): void
    {
        [$app, , $tight] = self::boot();
        $app->openConfirm();
        $app->frame(16.0, $tight);

        $panel = self::findPanel($app->overlays()->top()?->element?->effectiveBox());
        $rect = $panel?->panelRect();
        [$px, $py] = $panel instanceof RenderBox ? boxOrigin($panel) : [0.0, 0.0];

        $app->dispatchInput(PointerEvent::down($px + ($rect?->left ?? 0.0) + 8.0, $py + ($rect?->top ?? 0.0) + 8.0));
        check('模态 · 点面板内 ⇒ 不关（遮罩只挡面板外）', $app->isModalOpen());

        $app->dispatchInput(KeyEvent::down(KeyChord::escape()));
        check('模态 · ESC ⇒ 关（dismissible 默认 true）', !$app->isModalOpen());
        check(
            '模态 · 关后槽位清空 + 模态标记归零（不留脏状态）',
            $app->overlays()->top()?->element === null && $app->modal() === null && !$app->isMenuOpen(),
        );
        $app->closeModal();
        check('模态 · closeModal 幂等（没开再关一次不炸）', !$app->isModalOpen());
    }

    public function testDismissibleFalseSwallowsBothPaths(): void
    {
        [$app, , $tight] = self::boot();
        $app->dismissible = false;
        $app->openConfirm();
        $app->frame(16.0, $tight);
        $app->seen = [];
        check(
            '模态 · dismissible=false 的模态已打开（前置）',
            $app->isModalOpen() && $app->modal()?->isDismissible() === false,
        );

        $app->dispatchInput(KeyEvent::down(KeyChord::escape()));
        check(
            '模态 · dismissible=false ⇒ ESC 不关且被吞（不落到第 ① 环加速键）',
            $app->isModalOpen() && $app->seen === [],
            'open=' . ($app->isModalOpen() ? 'yes' : 'no') . ' seen=' . implode(',', $app->seen),
        );

        $app->dispatchInput(PointerEvent::down(5.0, 5.0));
        check(
            '模态 · dismissible=false ⇒ 点遮罩不关且被吞（拒绝 ≠ 放行给下面的按钮）',
            $app->isModalOpen() && $app->seen === [],
            'open=' . ($app->isModalOpen() ? 'yes' : 'no') . ' seen=' . implode(',', $app->seen),
        );
    }

    public function testClickOnBarrierClosesAndSwallows(): void
    {
        [$app, , $tight] = self::boot();
        $app->openConfirm();
        $app->frame(16.0, $tight);
        $app->seen = [];
        $app->dispatchInput(PointerEvent::down(5.0, 5.0));
        check(
            '模态 · dismissible=true ⇒ 点遮罩关 + 吞掉该次按下（不穿透到下面的按钮）',
            !$app->isModalOpen() && $app->seen === [],
            'open=' . ($app->isModalOpen() ? 'yes' : 'no') . ' seen=' . implode(',', $app->seen),
        );
    }

    public function testConfirmButtonDispatchesButModalStaysOpen(): void
    {
        [$app, , $tight] = self::boot();
        $app->openConfirm();
        $app->frame(16.0, $tight);
        $app->seen = [];

        $el = findElement(
            $app->overlays()->top()?->element,
            fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->commandName() === 'modal.confirm',
        );
        $box = $el?->effectiveBox();
        [$x, $y] = $box === null ? [0.0, 0.0] : boxOrigin($box);
        $app->dispatchInput(PointerEvent::down(
            $x + ($box?->size()?->width ?? 0.0) / 2.0,
            $y + ($box?->size()?->height ?? 0.0) / 2.0,
        ));
        check(
            '模态 · 点面板内按钮 ⇒ 命令分发，模态**不自己关**（§A.3.7：关不关归应用）',
            $app->seen === ['modal.confirm'] && $app->isModalOpen(),
            'seen=' . implode(',', $app->seen) . ' open=' . ($app->isModalOpen() ? 'yes' : 'no'),
        );
    }

    public function testCloseIconIsInjectedOnlyWhenDismissible(): void
    {
        [$app, , $tight] = self::boot();
        $app->openConfirm();
        $app->frame(16.0, $tight);

        // ① 框架注入了 ⇒ 画；② 按钮真在树上；③ 点它 ⇒ 模态关（端到端，不是"盒在那儿"）
        $modal = $app->modal();
        check(
            '模态 · showModal 注入了 dismiss ⇒ showsCloseIcon() 为真',
            $modal instanceof Modal && $modal->showsCloseIcon(),
        );
        $closeEl = findElement(
            $app->overlays()->top()?->element,
            fn (Element $e): bool => $e->widget() instanceof IconButton && $e->widget()->label() === '关闭',
        );
        check('模态 · 关闭按钮在树上（按可访问名「关闭」）', $closeEl !== null);

        $box = $closeEl?->effectiveBox();
        [$x, $y] = $box === null ? [0.0, 0.0] : boxOrigin($box);
        $app->dispatchInput(PointerEvent::down(
            $x + ($box?->size()?->width ?? 0.0) / 2.0,
            $y + ($box?->size()?->height ?? 0.0) / 2.0,
        ));
        check(
            '模态 · 点关闭按钮 ⇒ 模态关（框架注入的 dismiss 真的接了线）',
            !$app->isModalOpen(),
            'open=' . ($app->isModalOpen() ? 'yes' : 'no'),
        );

        // 反例：`dismissible=false` ⇒ 没有 X（不是"有个点了没反应的 X"）
        $app->dismissible = false;
        $app->openConfirm();
        $app->frame(16.0, $tight);
        check(
            '模态 · dismissible=false ⇒ 不画关闭按钮（与"拒绝用户关闭"同一条语义）',
            $app->modal()?->showsCloseIcon() === false,
        );
    }

    public function testModalStacksAboveMenuInsteadOfClearingIt(): void
    {
        [$app, , $tight] = self::boot();
        // ★ W3 行为变化（`01` §8）：旧语义"同一槽位 ⇒ 打开模态先清菜单"是槽位的产物，
        //   不是需求：在下拉里点「新建分类…」弹出对话框，关掉后下拉应该还在。
        $app->showMenu(PopupPanel::make(Text::make('菜单项')));
        check('模态 · 前置：菜单开着', $app->isMenuOpen() && !$app->isModalOpen());

        $app->openConfirm();
        check(
            '模态 · 模态盖在菜单之上（不再清菜单 —— `01` §8 的行为变化）',
            $app->isModalOpen() && $app->isMenuOpen() && $app->overlays()->count() === 2,
            'modal=' . ($app->isModalOpen() ? 'yes' : 'no')
            . ' menu=' . ($app->isMenuOpen() ? 'yes' : 'no')
            . ' depth=' . $app->overlays()->count(),
        );

        $app->closeModal();
        check(
            '模态 · 关掉对话框后菜单还在（栈没被连坐清掉）',
            !$app->isModalOpen() && $app->isMenuOpen() && $app->overlays()->count() === 1,
            'modal=' . ($app->isModalOpen() ? 'yes' : 'no')
            . ' menu=' . ($app->isMenuOpen() ? 'yes' : 'no')
            . ' depth=' . $app->overlays()->count(),
        );
    }

    public function testBuildShapeIsHostCenterPanel(): void
    {
        $el = Modal::make()->child(Text::make('只有正文'))->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();

        $center = false;
        foreach (childBoxes($box) as $child) {
            $mods = $child->mods();
            $center = $child instanceof RenderProxy && $mods !== null && $mods->alignment() !== null;
        }
        check(
            'Modal · build = ModalHost → Center → PopupPanel（居中靠组合，不是盒里的开关）',
            $box instanceof RenderModalHost && $center,
        );
    }
}
