<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Preview\FormPage;
use Drift\Dev\Preview\Renderer;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderTextField;
use Drift\Rendering\Color;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;

/**
 * TextField：受控输入框（§15.2 / §A.2.9）。
 *
 * 判据（与 `tools/smoke.php` ㉓ 段 + "编辑态"部分一致）：
 *   ① `make()` 无参 + 链式配置；"没 label" 与 "label 是空串" 必须可区分；
 *   ② 三段分工 State（编辑态）→ TextFieldBox（解析主题）→ RenderTextField（几何 + 绘制），
 *      StatefulWidget 自己不产盒；C5：会超的容器必须声明 `Overflow::Clip`；
 *   ③ 编辑序列按**码位**走（多字节不被切半），每次真改动才通知 `onChanged`；
 *   ④ `caretRect()` 读的是最近一次 build 的盒 ⇒ 改插入符后必须 `settle()` 再读；
 *      编辑态必须**真的多出墨**（不是"状态变量对了"就算）；
 *   ⑤ §20 纪律 4：能力必须在示例里出现过 —— 表单页能无头出图。
 *
 * ★ 判据含光栅取墨与无头出图，故落 Feature 层（与 DividerProgressTest 同层）。
 */
final class TextFieldTest extends SmokeTestCase
{
    /** 数"与背景不同"的像素（与 smoke `inkCount` 同实现） */
    private function inkCount(\Drift\Rendering\RasterCanvas $canvas, ?Color $background = null): int
    {
        $background ??= Color::white();
        $ink = 0;
        for ($y = 0; $y < $canvas->height(); $y++) {
            for ($x = 0; $x < $canvas->width(); $x++) {
                if (!$canvas->pixelAt($x, $y)->equals($background)) {
                    $ink++;
                }
            }
        }

        return $ink;
    }

    /**
     * 数输入框的墨（与 smoke `fieldInk` 同实现）。
     *
     * ★★ 背景必须取**盒自己的底色**，不能写死白色：色阶拆分后输入框底是画布灰，
     *   底色本身就产墨 ⇒ 写死白底会让"编辑态多出墨"这条恒等（实测 993 = 993）。
     */
    private function fieldInk(?RenderBox $box, int $width, int $height): int
    {
        if ($box === null) {
            return 0;
        }

        $list = new \Drift\Rendering\DisplayList();
        $box->paint(new \Drift\Rendering\RecordingCanvas($list));

        $background = Color::white();
        if ($box instanceof RenderTextField) {
            $own = $box->boxStyle()->background;
            if ($own !== null) {
                $background = $own;
            }
        }

        $canvas = new \Drift\Rendering\RasterCanvas($width, $height, $background, \Drift\Geometry\Scale::of(1.0));
        $list->replay($canvas);

        return $this->inkCount($canvas, $background);
    }

    public function testDeclarationLayer(): void
    {
        // make() 不吃参数 —— 字段的必填项是 value，由链式给（§A.2.9 的 `TextField::make()`）
        $tf = TextField::make()->value('abc')->label('搜索')->placeholder('输入姓名');
        check(
            'TextField · make() 无参 + 链式配置生效',
            $tf->valueText() === 'abc' && $tf->labelText() === '搜索' && $tf->placeholderText() === '输入姓名',
        );
        check('TextField · 没给 label ⇒ null（"没标签"与"标签是空串"不是一件事）', TextField::make()->labelText() === null);
        check('TextField · 默认 = 空文本 / 无占位符', TextField::make()->valueText() === '' && TextField::make()->placeholderText() === '');

        // onChanged 是纯 UI 回调（与 Button::onPressed 同形：不包一层的机制）
        $changedText = null;
        $tfHandler = TextField::make()->onChanged(function (string $v) use (&$changedText): void {
            $changedText = $v;
        })->changedHandler();
        check('TextField · onChanged 闭包原样递下去', $tfHandler !== null && $tfHandler('x') === null && $changedText === 'x');

        $this->flushChecks();
    }

    public function testBoxGeometryAndTheme(): void
    {
        $ctx = smokeContext();
        $darkContext = new BuildContext(320.0, 240.0, ThemeData::dark(), $ctx->metrics());

        // 三段分工：State（编辑态）→ TextFieldBox（解析主题）→ RenderTextField（几何 + 绘制）
        $fieldRoot = TextField::make()->value('abc')->placeholder('输入姓名')->createElement();
        $fieldRoot->mount(null, $ctx);
        $fieldBox = $fieldRoot->effectiveBox();
        check('TextField · 造出 RenderTextField（StatefulWidget 自己不产盒）', $fieldBox instanceof RenderTextField);

        $fieldBox?->layout(BoxConstraints::loose(new Size(200.0, 60.0)));
        $fieldW = $fieldBox?->size()?->width ?? 0.0;
        $fieldH = $fieldBox?->size()?->height ?? 0.0;
        check(
            'TextField · 盒尺寸 = 内边距 + 内容（不是 0，也不填满约束）',
            $fieldW > 0.0 && $fieldW < 200.0 && $fieldH > 0.0,
            sprintf('实际 %.2f × %.2f', $fieldW, $fieldH),
        );
        check(
            'TextField · 有文本时显示真实文本（不是占位符）',
            $fieldBox instanceof RenderTextField && !$fieldBox->isShowingPlaceholder(),
        );
        check('TextField · 显式声明溢出策略 Clip（C5：会超的容器必须声明）', $fieldBox?->overflow() === Overflow::Clip);

        // 空值 ⇒ 占位符，且高度不塌（行高由样式推，不靠内容）
        $emptyRoot = TextField::make()->placeholder('输入姓名')->createElement();
        $emptyRoot->mount(null, $ctx);
        $emptyBox = $emptyRoot->effectiveBox();
        $emptyBox?->layout(BoxConstraints::loose(new Size(200.0, 60.0)));
        check(
            'TextField · 空值时显示占位符，且高度不塌成 0',
            $emptyBox instanceof RenderTextField
                && $emptyBox->isShowingPlaceholder()
                && ($emptyBox->size()?->height ?? 0.0) > 0.0,
        );

        // 主题经 BuildContext 解析一次（§15.7 纪律 3）—— Layout 层不碰 Theme
        $lightField = TextField::make()->value('x')->createElement();
        $lightField->mount(null, $ctx);
        $darkField = TextField::make()->value('x')->createElement();
        $darkField->mount(null, $darkContext);
        $lightFieldBox = $lightField->effectiveBox();
        $darkFieldBox = $darkField->effectiveBox();
        check(
            'TextField · 明/暗下取到不同底色（换肤改表不改控件）',
            $lightFieldBox instanceof RenderTextField
                && $darkFieldBox instanceof RenderTextField
                && $lightFieldBox->boxStyle()->background?->toArgb() !== $darkFieldBox->boxStyle()->background?->toArgb(),
        );
        check(
            'TextField · 输入"井"有描边（与 Button::Default 的凸起观感区分开）',
            $fieldBox instanceof RenderTextField && $fieldBox->boxStyle()->borderColor !== null,
        );

        // 有 label ⇒ build 出 Column（标签在上、字段在下），根盒是 RenderFlex
        $labelRoot = TextField::make()->value('x')->label('搜索')->createElement();
        $labelRoot->mount(null, $ctx);
        check('TextField · 有 label ⇒ 根是 Column（标签与字段上下排）', $labelRoot->effectiveBox() instanceof RenderFlex);

        $this->flushChecks();
    }

    public function testEditingState(): void
    {
        $ctx = smokeContext();

        $seen = [];
        $editRoot = TextField::make()->value('ab')->onChanged(function (string $v) use (&$seen): void {
            $seen[] = $v;
        })->createElement();
        $editRoot->mount(null, $ctx);
        $editState = $editRoot->state();
        check('TextField · element->state() 是 TextFieldState', $editState instanceof TextFieldState);
        check('TextField · 初值来自 value()，插入符在末尾', $editState?->text() === 'ab' && $editState?->caretOffset() === 2);
        check('TextField · 默认不在编辑态（插入符不画）', $editState?->isEditing() === false);

        $editState?->beginEditing();
        check('TextField · beginEditing() 进入编辑态', $editState?->isEditing() === true);

        $editState?->insert('X');       // ab   → abX（插入符 3）
        $editState?->deleteBackward();  // abX  → ab （插入符 2）
        $editState?->moveCaret(1);      // 插入符 1
        $editState?->insert('Z');       // ab   → aZb（插入符 2）
        check(
            'TextField · 编辑序列 ab → abX → ab → aZb（插入符跟随）',
            $editState?->text() === 'aZb' && $editState?->caretOffset() === 2,
            '实际 ' . ($editState?->text() ?? 'null') . ' @' . (string) ($editState?->caretOffset() ?? -1),
        );
        check(
            'TextField · 每次真改动都通知 onChanged（无变化不通知）',
            $seen === ['abX', 'ab', 'aZb'],
            '实际 ' . json_encode($seen),
        );

        $this->flushChecks();
    }

    public function testMultibyteEditingFollowsCodePoints(): void
    {
        $ctx = smokeContext();

        // ★ 多字节：退格与移动都按**码位**走，不会把字符切成半个
        $cjkSeen = [];
        $cjkRoot = TextField::make()->value('中文')->onChanged(function (string $v) use (&$cjkSeen): void {
            $cjkSeen[] = $v;
        })->createElement();
        $cjkRoot->mount(null, $ctx);
        $cjkState = $cjkRoot->state();
        $cjkState?->deleteBackward();   // 删掉「文」（3 字节），不是 1 字节
        check(
            'TextField · 退格按码位删（多字节不被切半）',
            $cjkState?->text() === '中' && $cjkState?->caretOffset() === 3,
            '实际 ' . ($cjkState?->text() ?? 'null') . ' @' . (string) ($cjkState?->caretOffset() ?? -1),
        );
        $cjkState?->moveCaret(2);       // 落在「中」字符内部 ⇒ 吸附到 0
        check('TextField · moveCaret 吸附到字符边界（不会切半字符）', $cjkState?->caretOffset() === 0);
        $cjkState?->insert('甲');
        check('TextField · 在字符边界插入多字节字符', $cjkState?->text() === '甲中', '实际 ' . ($cjkState?->text() ?? 'null'));

        $this->flushChecks();
    }

    public function testCaretGeometryAndPaint(): void
    {
        $ctx = smokeContext();

        $seen = [];
        $editRoot = TextField::make()->value('ab')->onChanged(function (string $v) use (&$seen): void {
            $seen[] = $v;
        })->createElement();
        $editRoot->mount(null, $ctx);
        $editState = $editRoot->state();
        $editState?->beginEditing();

        // 插入符几何（IME 定位候选窗的来源，§8.4）
        // ★ 读 caretRect() 之前必须 settle()：它读的是**最近一次 build 的盒**，
        //   而 moveCaret() 只标脏（§5.2 裁决 3：重建发生在帧末）。
        $editState?->moveCaret(0);
        $editRoot->settle();
        $caretAt0 = $editState?->caretRect();
        $editState?->moveCaretToEnd();
        $editRoot->settle();
        $caretAtEnd = $editState?->caretRect();
        check(
            'TextField · caretRect() 给出盒内几何（高度 > 0，且在盒内）',
            $caretAtEnd !== null && $caretAtEnd->height > 0.0 && $caretAtEnd->left >= 0.0,
        );
        check(
            'TextField · 插入符越靠右 x 越大（几何真的跟着插入符走）',
            $caretAt0 !== null && $caretAtEnd !== null && $caretAtEnd->left > $caretAt0->left,
            sprintf('x %.2f → %.2f', $caretAt0?->left ?? -1.0, $caretAtEnd->left),
        );

        // ★ 端到端：编辑态真的改变画面（rebuild → 新盒 → 多出插入符的墨）
        $editRoot->settle();
        $caretPaintBox = $editRoot->effectiveBox();
        $caretPaintBox?->layout(BoxConstraints::loose(new Size(200.0, 60.0)));
        $caretInk = $this->fieldInk($caretPaintBox, 120, 40);

        $editState?->endEditing();
        $editRoot->settle();
        $noCaretBox = $editRoot->effectiveBox();
        $noCaretBox?->layout(BoxConstraints::loose(new Size(200.0, 60.0)));
        $noCaretInk = $this->fieldInk($noCaretBox, 120, 40);
        check(
            'TextField · 插入符真的画上了（编辑态比非编辑态多出墨）',
            $caretPaintBox instanceof RenderTextField && $caretInk > $noCaretInk,
            '编辑 ' . $caretInk . ' / 非编辑 ' . $noCaretInk,
        );

        // 受控回推：外部换 value ⇒ State 采纳（§A.2.9 的官方订阅形态靠这条闭环）
        $editRoot->update(TextField::make()->value('新值')->onChanged(function (string $v) use (&$seen): void {
            $seen[] = $v;
        }));
        check('TextField · 外部推新 value ⇒ State 采纳并重建', $editState?->text() === '新值');

        $this->flushChecks();
    }

    /**
     * 选区渲染：几何 + 反查 + 真的有墨。
     *
     * ★ 与 `testCaretGeometryAndPaint` 分开：那条锁插入符，这条锁高亮块。
     *   两条共用同一个 `fieldInk()` —— "多出墨"必须用同一量具才可判。
     */
    public function testSelectionGeometryAndPaint(): void
    {
        $ctx = smokeContext();

        $selVisRoot = TextField::make()->value('abcd')->createElement();
        $selVisRoot->mount(null, $ctx);
        $selVisState = $selVisRoot->state();
        $selVisState?->beginEditing();
        $selVisState?->selectAll();
        $selVisRoot->settle();
        $selVisBox = $selVisRoot->effectiveBox();
        $selVisBox?->layout(BoxConstraints::loose(new Size(300.0, 60.0)));

        check(
            '选区渲染 · 有选区 ⇒ selectionRect() 非 null 且宽度 > 0',
            $selVisBox instanceof RenderTextField
                && ($selVisBox->selectionRect()?->width ?? 0.0) > 0.0,
            var_export($selVisBox instanceof RenderTextField ? $selVisBox->selectionRect() : null, true)
        );

        // ★ 同源性：选区右边缘必须与"插入符在选区终点"的插入符左边缘是同一个 x ——
        //   两个换算若分开写，这里会差出一个亚像素级的缝（选到头时肉眼可见错位）。
        //   **不能挪插入符来凑**：moveCaret() 会折叠选区，比的时候就只能跟 null 比
        //   （第一版就这么红的）。全选后插入符本来就在选区终点，直接可比。
        check(
            '选区渲染 · 选区右边缘 == 同偏移插入符左边缘（两者共用一次换算）',
            $selVisBox instanceof RenderTextField
                && $selVisBox->caretRect()->left === ($selVisBox->selectionRect()?->left ?? -1.0)
                + ($selVisBox->selectionRect()?->width ?? -1.0),
            'caret=' . ($selVisBox instanceof RenderTextField ? (string) $selVisBox->caretRect()->left : 'n/a')
        );

        // offsetAt() 反查：**真实往返** —— 插入符在 i 时的 x，反查必须回到 i。
        // （第一版只查"返回值落在 0..4"，那等于没查：中点判据写反了也过。）
        $selVisState?->clearSelection();
        $roundTripOk = true;
        $roundTripLog = '';
        for ($i = 0; $i <= 4; $i++) {
            $selVisState?->moveCaret($i);
            $selVisRoot->settle();
            $probeBox = $selVisRoot->effectiveBox();
            $probeBox?->layout(BoxConstraints::loose(new Size(300.0, 60.0)));
            if (!$probeBox instanceof RenderTextField) {
                $roundTripOk = false;
                $roundTripLog .= ' no-box';
                break;
            }
            $x = $probeBox->caretRect()->left;
            $back = $probeBox->offsetAt($x);
            if ($back !== $i) {
                $roundTripOk = false;
                $roundTripLog .= ' ' . $i . '->' . $back;
            }
        }
        check(
            '选区渲染 · offsetAt() 真实往返（插入符在 i 的 x 反查回 i）',
            $roundTripOk,
            'mismatch:' . $roundTripLog
        );

        // 真的有墨：全选后的像素比无选区多（高亮块）
        $selInkWith = $this->fieldInk($selVisBox, 200, 40);
        $selVisState?->clearSelection();
        $selVisRoot->settle();
        $selPlainBox = $selVisRoot->effectiveBox();
        $selPlainBox?->layout(BoxConstraints::loose(new Size(300.0, 60.0)));
        $selInkWithout = $this->fieldInk($selPlainBox, 200, 40);
        check(
            '选区渲染 · 全选后真的多出墨（高亮块画上了）',
            $selInkWith > $selInkWithout,
            'with=' . $selInkWith . ' without=' . $selInkWithout
        );
        check(
            '选区渲染 · 无选区时 selectionRect() 为 null（相等即无选区，不画）',
            $selPlainBox instanceof RenderTextField && $selPlainBox->selectionRect() === null,
        );

        $this->flushChecks();
    }

    public function testExamplePageRendersHeadless(): void
    {
        $ctx = smokeContext();

        // §20 纪律 4：能力必须在**示例**里出现过（没出现 = 没交付）。
        // FormPage 是 TextField 的契约示例 —— 这条锁住"示例页不静默腐烂"。
        $formSvg = (new Renderer(new SvgSurface(), $ctx->metrics(), ThemeData::light()))
            ->render(new FormPage(), 360.0, 200.0, 1.0);
        check(
            'TextField · 表单示例页能无头出图（§20 纪律 4）',
            substr_count($formSvg, '<rect x=') > 0,
            'run 数 ' . (string) substr_count($formSvg, '<rect x=')
        );

        $this->flushChecks();
    }
}
