<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\ComboBoxApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Widget\Button;

/**
 * 可编辑下拉（W8-2，输入过滤 + 浮层联动，2026-10-24）。
 *
 * 钉住三件事：
 * ① **跨树重建**：输入框在主树、候选面板在浮层树（第二棵根渲染树）⇒ 主树重建
 *    **不会**带动面板。面板靠 `ComboBoxQuery`（`Subject`）自己订阅自己重建 ——
 *    这是本控件最不显然的一处，只读代码看不出来它通不通。
 * ② 字段的两个状态（显示标签 / 搜索框）之间的切换点只有"打开面板"与"关闭面板"。
 * ③ 键盘：Enter 取第一项、Esc 收起、Tab 进面板后打字仍进输入框（`forwardKey()`）。
 *
 * ★ 反例自检两条：无匹配项时 Enter **不关面板也不回调**；点面板外 ⇒ 字段**丢弃**
 *   打了一半的词回到旧标签。
 */
final class ComboBoxTest extends SmokeTestCase
{
    private float $t = 2000.0;

    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(360.0, 300.0));
    }

    private function boot(): ComboBoxApplication
    {
        $app = new ComboBoxApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $app->frame($this->t, $this->constraints());

        return $app;
    }

    /** 推进一帧（`placeOverlays()` 的 settle 段在这一帧里重建面板） */
    private function tick(ComboBoxApplication $app): void
    {
        $this->t += 16.0;
        $app->frame($this->t, $this->constraints());
    }

    /** 在客户区某点按一下（down + up 同点）并推进一帧 */
    private function tap(ComboBoxApplication $app, array $pt): void
    {
        $app->dispatchInput(PointerEvent::down($pt[0], $pt[1]));
        $app->dispatchInput(PointerEvent::up($pt[0], $pt[1]));
        $this->tick($app);
    }

    /**
     * 候选按钮的标签（按视觉顺序）
     *
     * @return list<string>
     */
    private function labels(ComboBoxApplication $app): array
    {
        $out = [];
        foreach ($app->optionButtons() as $el) {
            $w = $el->widget();
            $out[] = $w instanceof Button ? $w->label() : '?';
        }

        return $out;
    }

    /**
     * 过滤 → 提交 → 复位 → 丢弃的完整叙事。
     *
     * ★ 16 条断言在**一条状态链**上（每一步的期望都包含前一步的结果，比如"再打开
     *   又列出全部 4 项"依赖前面已经提交过"北京市"）⇒ 拆方法只能靠重放前缀，
     *   而重放本身没人验。宁可一个方法长一点。
     */
    public function testFilterCommitAndDismissChain(): void
    {
        $app = $this->boot();
        check('ComboBox · 初始没有浮层', $app->overlayCount() === 0, 'overlay=' . $app->overlayCount());

        // —— ① 点输入框 ⇒ 开面板（全部 4 项）——
        $this->tap($app, $app->fieldCenter());
        check(
            'ComboBox · 点输入框 ⇒ 开一层浮层，列出全部 4 项',
            $app->overlayCount() === 1 && $this->labels($app) === ['北京市', '上海市', '广州市', '北京南站'],
            'overlay=' . $app->overlayCount() . ' rows=[' . \implode(',', $this->labels($app)) . ']',
        );
        // ★ 打开时字段必须切成**空**的搜索框：否则打字会追加在标签后面
        //   （现象：选中"北京市"再打开、打"上"⇒ 字段成了"北京市上"、一项都匹配不到）。
        check(
            'ComboBox · 打开时字段切成空搜索框（不是留着标签让打字追加）',
            $app->fieldState()?->text() === '',
            'text=' . \var_export($app->fieldState()?->text(), true),
        );

        // —— ② 打字 ⇒ **跨树**过滤 ——
        $app->dispatchInput(KeyEvent::character('北'));
        $this->tick($app);
        check(
            'ComboBox · 打字「北」⇒ 面板跨树过滤到 2 项（主树输入、浮层树重建）',
            $this->labels($app) === ['北京市', '北京南站'],
            'rows=[' . \implode(',', $this->labels($app)) . ']',
        );
        check(
            'ComboBox · 字段显示输入的字（不是标签）',
            $app->fieldState()?->text() === '北',
            'text=' . \var_export($app->fieldState()?->text(), true),
        );

        // —— ③ Enter ⇒ 取过滤后的**第一项** ——
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        $this->tick($app);
        check(
            'ComboBox · Enter ⇒ 取过滤后的第一项（110000，不是唯一的"北京南站"）',
            $app->changed() === ['110000'] && $app->value() === '110000',
            'changed=[' . \implode(',', $app->changed()) . '] value=' . $app->value(),
        );
        check(
            'ComboBox · Enter 后浮层收掉，字段回到新标签「北京市」',
            $app->overlayCount() === 0 && $app->fieldState()?->text() === '北京市',
            'overlay=' . $app->overlayCount() . ' text=' . \var_export($app->fieldState()?->text(), true),
        );

        // —— ④ 再打开 ⇒ 过滤词复位（"点一下看全部"）——
        $this->tap($app, $app->fieldCenter());
        check(
            'ComboBox · 再打开 ⇒ 又列出全部 4 项（过滤词随打开复位，不是只剩标签那一项）',
            $app->overlayCount() === 1 && \count($this->labels($app)) === 4,
            'overlay=' . $app->overlayCount() . ' rows=' . \count($this->labels($app)),
        );

        // —— ⑤ 过滤 + Esc ⇒ 收面板且**丢弃**过滤词 ——
        $app->dispatchInput(KeyEvent::character('上'));
        $this->tick($app);
        check(
            'ComboBox · 打「上」⇒ 只剩 1 项（"北京市"不再匹配）',
            $this->labels($app) === ['上海市'],
            'rows=[' . \implode(',', $this->labels($app)) . ']',
        );
        $app->dispatchInput(KeyEvent::down(KeyChord::escape()));
        $this->tick($app);
        check(
            'ComboBox · Esc ⇒ 收面板且字段回到旧标签「北京市」（丢弃打了一半的词）',
            $app->overlayCount() === 0 && $app->fieldState()?->text() === '北京市'
                && $app->changed() === ['110000'],
            'overlay=' . $app->overlayCount() . ' text=' . \var_export($app->fieldState()?->text(), true),
        );

        // —— ⑥ 鼠标点第二项 ——
        $this->tap($app, $app->fieldCenter());
        $this->tap($app, $app->optionCenter(1));
        check(
            'ComboBox · 点面板里的第二项 ⇒ 回调收到它的 value（310000）且收面板',
            $app->changed() === ['110000', '310000'] && $app->overlayCount() === 0
                && $app->fieldState()?->text() === '上海市',
            'changed=[' . \implode(',', $app->changed()) . '] overlay=' . $app->overlayCount()
                . ' text=' . \var_export($app->fieldState()?->text(), true),
        );

        // —— ⑦ 反例：无匹配项时 Enter 不关面板也不回调 ——
        $this->tap($app, $app->fieldCenter());
        $app->dispatchInput(KeyEvent::character('z'));
        $this->tick($app);
        check(
            'ComboBox · 反例：无匹配项 ⇒ 面板里 0 个候选按钮（但面板还在，有一行"无匹配项"）',
            $app->overlayCount() === 1 && \count($this->labels($app)) === 0,
            'overlay=' . $app->overlayCount() . ' rows=' . \count($this->labels($app)),
        );
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        $this->tick($app);
        check(
            'ComboBox · 反例：无匹配项时 Enter 不关面板、也不回调（别让人以为选中了什么）',
            $app->overlayCount() === 1 && $app->changed() === ['110000', '310000'],
            'overlay=' . $app->overlayCount() . ' changed=[' . \implode(',', $app->changed()) . ']',
        );

        // —— ⑧ Tab 进面板后打字仍进输入框（`forwardKey()`）——
        $app->dispatchInput(KeyEvent::down(KeyChord::escape()));
        $this->tick($app);
        $this->tap($app, $app->fieldCenter());
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $this->tick($app);
        $focused = $app->focusedElement()?->widget();
        check(
            'ComboBox · Tab ⇒ 焦点进面板的候选行（浮层树也在焦点链里，菜单同一条路）',
            $focused instanceof Button,
            'focus=' . ($focused === null ? '(null)' : \get_class($focused)),
        );
        $app->dispatchInput(KeyEvent::character('上'));
        $this->tick($app);
        check(
            'ComboBox · 焦点在面板行上时打字仍进输入框（forwardKey ⇒ 面板过滤到 1 项）',
            $app->fieldState()?->text() === '上' && $this->labels($app) === ['上海市'],
            'text=' . \var_export($app->fieldState()?->text(), true) . ' rows=[' . \implode(',', $this->labels($app)) . ']',
        );

        // —— ⑨ 反例：点面板外 ⇒ 关且丢弃过滤词 ——
        $app->dispatchInput(PointerEvent::down(340.0, 280.0));
        $app->dispatchInput(PointerEvent::up(340.0, 280.0));
        $this->tick($app);
        check(
            'ComboBox · 反例：点面板外 ⇒ 关且字段回旧标签「上海市」（不把半截过滤词留在框里）',
            $app->overlayCount() === 0 && $app->fieldState()?->text() === '上海市'
                && $app->changed() === ['110000', '310000'],
            'overlay=' . $app->overlayCount() . ' text=' . \var_export($app->fieldState()?->text(), true),
        );
    }
}
