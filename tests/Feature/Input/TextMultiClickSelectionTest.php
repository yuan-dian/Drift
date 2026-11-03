<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Smoke\App\WordSelectApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Widget\EditableText;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;
use Drift\Widget\TextArea;
use Drift\Widget\TextField;

/**
 * 文本选区的**多击**：双击选词 / 三击选段 / Shift+点延伸（§24）。
 *
 * ## 三条判据为什么必须一起看
 *
 * | 用例 | 单独看时的假绿风险 |
 * |---|---|
 * | 双击 ⇒ 选一个**词** | 若实现把"双击"当"三击"，单词夹具照样过 |
 * | 三击 ⇒ 选**整段** | 若实现把"三击"当"双击"，单词夹具照样过 |
 * | 双击在**词的中间**也选中同一词 | 若实现用当前插入符而非指针偏移，只点边缘测不出来 |
 *
 * ⇒ 夹具文本刻意是 `hello world foo`（三词）与两段两行；坐标刻意取**边缘**
 *   （落点由夹取决定：左缘 ⇒ 0，右缘 ⇒ 文本长），不赌字模。
 *
 * ## 时间戳是判据的一部分
 *
 * `clickCount` 由 `TapTracker` 按"间隔 ≤ 500ms / 位移 ≤ 4px / 修饰键全同"算出来
 * （见 `Unit/Input/TapTrackerTest`）。这里给**显式毫秒**：同一个坐标隔 100ms 两次
 * ⇒ 第二下是双击；隔 1000ms ⇒ 仍是单击。所以"慢双击只落插入符"这条也顺带钉住
 * 了 —— 它是这类实现最容易漏的那一半（数了次数却没算间隔）。
 */
final class TextMultiClickSelectionTest extends SmokeTestCase
{
    /** 挂夹具并返回 [app, 单行字段 state, 单行字段盒, 多行框 state, 多行框盒] */
    private function mount(): array
    {
        $app = new WordSelectApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(340.0, 260.0)));

        $tfEl = findElement($app->rootElement(), static fn(Element $e): bool => $e->widget() instanceof TextField);
        $taEl = findElement($app->rootElement(), static fn(Element $e): bool => $e->widget() instanceof TextArea);

        $tfState = $tfEl instanceof StatefulElement ? $tfEl->state() : null;
        $taState = $taEl instanceof StatefulElement ? $taEl->state() : null;

        return [
            $app,
            $tfState instanceof EditableText ? $tfState : null,
            $tfEl?->effectiveBox(),
            $taState instanceof EditableText ? $taState : null,
            $taEl?->effectiveBox(),
        ];
    }

    /** 一次完整的点击（down + up），带回时间戳 */
    private function tap(WordSelectApplication $app, float $x, float $y, float $ms, bool $shift = false): void
    {
        $app->dispatchInput(PointerEvent::down($x, $y, 0, false, false, $shift), $ms);
        $app->dispatchInput(PointerEvent::up($x, $y, 0, false, false, $shift), $ms + 10.0);
    }

    public function testDoubleClickSelectsWord(): void
    {
        [$app, $field, $box] = $this->mount();

        check(
            '多击 · 夹具就绪（单行字段在 padding 24 处）',
            $box instanceof RenderBox && RenderBox::absoluteOrigin($box) === [24.0, 24.0],
            'origin=' . \implode(',', $box === null ? [] : RenderBox::absoluteOrigin($box)),
        );

        [$bx, $by] = RenderBox::absoluteOrigin($box);
        $left = $bx + 1.0;
        $right = $bx + $box->size()->width - 1.0;
        $y = $by + 4.0;

        // 单击左缘 ⇒ 插入符 0、无选区（先证明"落点由夹取决定"，后面的判据才有底）
        $this->tap($app, $left, $y, 1000.0);
        check(
            '多击 · 单击左缘 ⇒ 插入符 0、无选区',
            $field?->caretOffset() === 0 && $field?->hasSelection() === false,
            'caret=' . ($field?->caretOffset() ?? -1),
        );

        // 同一点 100ms 后再点 ⇒ 双击 ⇒ 选中 "hello"
        $this->tap($app, $left, $y, 1100.0);
        check(
            '多击 · 双击左缘 ⇒ 选中第一个词（不是整串 —— 整串是三击的事）',
            $field?->selectedText() === 'hello',
            'sel=' . \var_export($field?->selectedText(), true)
                . ' range=' . ($field?->selectionStart() ?? -1) . '..' . ($field?->selectionEnd() ?? -1),
        );

        // 右缘双击 ⇒ 最后一个词
        $this->tap($app, $right, $y, 2000.0);
        $this->tap($app, $right, $y, 2100.0);
        check(
            '多击 · 双击右缘 ⇒ 选中最后一个词（左右两侧都测：只测一侧时"恒选整串"也能过）',
            $field?->selectedText() === 'foo',
            'sel=' . \var_export($field?->selectedText(), true),
        );

        $this->flushChecks();
    }

    /**
     * 三击选段 —— 与双击**必须落在不同区间**，否则这条判据没牙。
     */
    public function testTripleClickSelectsParagraph(): void
    {
        [$app, $field, $box] = $this->mount();

        [$bx, $by] = RenderBox::absoluteOrigin($box);
        $left = $bx + 1.0;
        $y = $by + 4.0;

        $this->tap($app, $left, $y, 1000.0);
        $this->tap($app, $left, $y, 1100.0);
        $this->tap($app, $left, $y, 1200.0);

        check(
            '多击 · 三击左缘 ⇒ 选中整段（单行框里 = 整串）',
            $field?->selectedText() === 'hello world foo',
            'sel=' . \var_export($field?->selectedText(), true),
        );

        // 第四下 ⇒ **仍是整段**（`TapTracker` 会继续往上数，而"第四下"不是"回到单击"）
        $this->tap($app, $left, $y, 1300.0);
        check(
            '多击 · 第四下仍是整段（判据是 `>= 3` 不是 `=== 3`）',
            $field?->selectedText() === 'hello world foo',
            'sel=' . \var_export($field?->selectedText(), true),
        );

        $this->flushChecks();
    }

    /**
     * **慢双击**（> 500ms）不算双击 —— 钉住"数了次数也算了间隔"。
     *
     * ★ 这条是唯一能区分"实现读 `clickCount`"与"实现自己数两次按下"的判据。
     */
    public function testSlowDoubleClickDoesNotSelectWord(): void
    {
        [$app, $field, $box] = $this->mount();

        [$bx, $by] = RenderBox::absoluteOrigin($box);
        $left = $bx + 1.0;
        $y = $by + 4.0;

        $this->tap($app, $left, $y, 1000.0);
        $this->tap($app, $left, $y, 1600.0);   // 600ms > 500ms 窗口

        check(
            '多击 · 两次点击隔 600ms ⇒ **不算双击**（只落插入符，无选区）',
            $field?->hasSelection() === false && $field?->caretOffset() === 0,
            'caret=' . ($field?->caretOffset() ?? -1)
                . ' sel=' . \var_export($field?->selectedText(), true),
        );

        $this->flushChecks();
    }

    /**
     * Shift+点 = 延伸到指针处，锚点不动（与 Shift+方向键同一个选区模型）。
     *
     * ★ 中间那个 x 对应的偏移是**现量**的（先用普通点击问一次），不赌字模：
     *   字体换了、内边距变了，`$probe` 跟着变，判据仍然成立。
     * ★ 第二段（"Shift+点回中间"）才是有牙的那条：若实现忽略 Shift 而走了
     *   `moveCaret()`，结果是"插入符落在中间、无选区"⇒ 报红。只测"到右缘"
     *   那一段时，"忽略 Shift 退回单击"的现象与正确实现**恰好同形**（都是右缘），
     *   所以那一条单独看是恒真判据。
     */
    public function testShiftClickExtendsSelection(): void
    {
        [$app, $field, $box] = $this->mount();

        [$bx, $by] = RenderBox::absoluteOrigin($box);
        $left = $bx + 1.0;
        $right = $bx + $box->size()->width - 1.0;
        $mid = $bx + $box->size()->width * 0.5;
        $y = $by + 4.0;

        // 量具自证：中间那个 x 落在文本内部（否则下面的"锚点 + 中间"退化成两端）
        $this->tap($app, $mid, $y, 900.0);
        $probe = $field?->caretOffset() ?? -1;
        check(
            '多击 · 量具自证：中间那个 x 真的落在文本内部（0 < 偏移 < 15）',
            $probe > 0 && $probe < 15,
            'probe=' . $probe,
        );

        // 锚点 0 ⇒ Shift+点到右缘 ⇒ 整串
        $this->tap($app, $left, $y, 1000.0);
        $this->tap($app, $right, $y, 1400.0, true);
        check(
            '多击 · Shift+点右缘 ⇒ 锚点在左缘、活动端到右缘（选中整串）',
            $field?->selectionStart() === 0 && $field?->selectionEnd() === 15
                && $field?->selectedText() === 'hello world foo',
            'range=' . ($field?->selectionStart() ?? -1) . '..' . ($field?->selectionEnd() ?? -1),
        );

        // 锚点 0 ⇒ Shift+点到**中间** ⇒ [0, probe]（忽略 Shift 的实现会变成"无选区"）
        $this->tap($app, $left, $y, 2000.0);
        $this->tap($app, $mid, $y, 2400.0, true);
        check(
            '多击 · Shift+点中间 ⇒ [0, 指针处]（锚点没被挪走；忽略 Shift 时这里是"无选区"）',
            $field?->selectionStart() === 0 && $field?->selectionEnd() === $probe,
            'range=' . ($field?->selectionStart() ?? -1) . '..' . ($field?->selectionEnd() ?? -1)
                . ' probe=' . $probe,
        );

        $this->flushChecks();
    }

    /**
     * 多行框同理 —— 这条证明"能力落在接口上"而不是"落在单行框上"。
     *
     * ★ 多行框的落点必须看 `y`：三击只选中**那一段**（`alpha beta`），不是整串。
     */
    public function testTextAreaWordAndParagraph(): void
    {
        [$app, , , $area, $areaBox] = $this->mount();

        check(
            '多击 · 多行框就绪（`RenderTextArea` 有盒）',
            $areaBox instanceof RenderBox,
            $areaBox === null ? 'box=null' : 'box=' . \get_class($areaBox),
        );

        [$ax, $ay] = RenderBox::absoluteOrigin($areaBox);
        $left = $ax + 1.0;
        $y = $ay + 1.0;

        $this->tap($app, $left, $y, 1000.0);
        $this->tap($app, $left, $y, 1100.0);
        check(
            '多击 · 多行框双击左缘 ⇒ 选中第一段的第一个词',
            $area?->selectedText() === 'alpha',
            'sel=' . \var_export($area?->selectedText(), true),
        );

        $this->tap($app, $left, $y, 1200.0);
        check(
            '多击 · 多行框三击 ⇒ 只选**这一段**（不是整串：硬换行把它分成了两段）',
            $area?->selectedText() === 'alpha beta',
            'sel=' . \var_export($area?->selectedText(), true),
        );

        $this->flushChecks();
    }
}
