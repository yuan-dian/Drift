<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\App\OverlayEntry;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\ComboBox;
use Drift\Widget\Element;
use Drift\Widget\Option;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Drift\Widget\StatefulWidget;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;
use Drift\Widget\Widget;

/**
 * `ComboBox` 夹具（W8-2，2026-10-24）。
 *
 * 主树 = 一个 `ComboBox`（4 个城市，其中**两项都含"北"**）。4 项而不是 3 项是
 * 刻意的：只留 1 项含"北"的话，"过滤后第一项"与"过滤后唯一项"分不开 ——
 * 那样 Enter 的断言连"取第一项"和"取全部"都区分不了。
 *
 * ★ 判据全部走**真实输入链**（`dispatchInput(PointerEvent::down(...))` /
 *   `KeyEvent`），不直调私有方法 —— 否则测的是"我以为的路径"。
 * ★ 坐标一律 `effectiveBox()` + `absoluteOrigin()` 现取（不赌字模、不赌固定布局）。
 *
 * ★★ **受控值住在根 `State` 里**（`ComboBoxHostState`），`onChanged` 里
 *   `setState` 回写 —— 与真应用逐字同形。**不要**改成"测试里直接
 *   `root()->update(buildRoot())`"：那条路会**同步**重建整棵树，而 `Frame` 的
 *   "本帧有没有重建过"判据看的是帧内 settle 的计数 ⇒ 判成"没变"⇒ **跳过 layout**
 *   ⇒ 新盒永远没有尺寸 ⇒ 之后所有命中测试都落空（现象：第二次点击打不开面板，
 *   而 overlay 计数、焦点、声明全对）。2026-10-24 本夹具就是这么踩了一次。
 *
 * @internal 测试夹具
 */
final class ComboBoxApplication extends Application
{
    public function buildRoot(): Widget
    {
        return new ComboBoxHost();
    }

    /** @return list<Option> 四个城市（"北京市" / "北京南站" 都含"北"） */
    public static function cities(): array
    {
        return [
            Option::make('北京市', '110000'),
            Option::make('上海市', '310000'),
            Option::make('广州市', '440100'),
            Option::make('北京南站', '110100'),
        ];
    }

    /** 根 `State`（受控值 / 回调记录都住那里） */
    public function host(): ?ComboBoxHostState
    {
        $root = $this->root();
        if (!$root instanceof StatefulElement) {
            return null;
        }
        $state = $root->state();

        return $state instanceof ComboBoxHostState ? $state : null;
    }

    /** @return list<string> `onChanged` 收到的值（顺序 = 触发顺序） */
    public function changed(): array
    {
        return $this->host()?->changed ?? [];
    }

    /** 当前受控值 */
    public function value(): string
    {
        return $this->host()?->value ?? '';
    }

    // ── 主树 ──────────────────────────────────────────────────────────

    /** 输入框（`ComboBox` build 出来的那个 `TextField`）的 Element */
    public function field(): ?Element
    {
        return self::findField($this->root());
    }

    /** 输入框的 `TextFieldState`（读 `text()` / `caretOffset()` 用） */
    public function fieldState(): ?TextFieldState
    {
        $el = $this->field();
        if (!$el instanceof StatefulElement) {
            return null;
        }
        $state = $el->state();

        return $state instanceof TextFieldState ? $state : null;
    }

    /** 输入框的客户区中心（没排过 ⇒ `null`） */
    public function fieldCenter(): ?array
    {
        return self::center($this->field());
    }

    // ── 浮层 ──────────────────────────────────────────────────────────

    /** 浮层栈层数（`0` = 面板关着） */
    public function overlayCount(): int
    {
        return $this->overlays()->count();
    }

    /** 栈顶条目（读落点 / 参数用） */
    public function topEntry(): ?OverlayEntry
    {
        return $this->overlays()->top();
    }

    /** 当前焦点 Element（`$focus` 是 protected） */
    public function focusedElement(): ?Element
    {
        return $this->focus?->focused();
    }

    /** 面板里的候选按钮（按视觉顺序；"无匹配项"那行是 `Text`，不在其中） */
    public function optionButtons(): array
    {
        $out = [];
        self::collectButtons($this->overlays()->top()?->element, $out);

        return $out;
    }

    /** 面板里第 `$i` 个候选按钮的客户区中心（没排过 ⇒ `null`） */
    public function optionCenter(int $i): ?array
    {
        $buttons = $this->optionButtons();

        return self::center($buttons[$i] ?? null);
    }

    // ── 小工具 ────────────────────────────────────────────────────────

    private static function center(?Element $element): ?array
    {
        $box = $element?->effectiveBox();
        if ($box === null) {
            return null;
        }

        [$x, $y] = self::absoluteOrigin($box);
        $size = $box->size();

        return [$x + ($size?->width ?? 0.0) / 2.0, $y + ($size?->height ?? 0.0) / 2.0];
    }

    private static function findField(?Element $element): ?Element
    {
        if ($element === null) {
            return null;
        }

        if ($element->widget() instanceof TextField) {
            return $element;
        }

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            $hit = self::findField($element->childAt($i));
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /** @param list<Element> $out */
    private static function collectButtons(?Element $element, array &$out): void
    {
        if ($element === null) {
            return;
        }

        if ($element->widget() instanceof Button) {
            $out[] = $element;

            return;   // 按钮没有子按钮，收一层就够
        }

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            self::collectButtons($element->childAt($i), $out);
        }
    }

    /**
     * 盒的客户区原点。
     *
     * ★ **必须走 `RenderBox::absoluteOrigin()`**，不能手抄父链累加：子盒 offset 住
     *   「内容盒」坐标（padding 位移不进 `setOffset`），手写累加会差一个 padding
     *   而且**不报错**。
     */
    private static function absoluteOrigin(\Drift\Layout\RenderBox $box): array
    {
        return \Drift\Layout\RenderBox::absoluteOrigin($box);
    }
}

/**
 * 夹具的根控件 —— **只为一件事存在**：把受控值放进 `State`。
 *
 * ★ 真应用的 `onChanged` 就是"改自己的 State 字段 + `setState`"（`setState` 只标脏、
 *   帧末统一重建）。夹具必须同形，否则测的不是真实时序 —— 见
 *   `ComboBoxApplication` 类头那段 ★★（直接 `Element::update()` 会让 `Frame`
 *   判成"没重建"从而跳过 layout）。
 *
 * @internal 测试夹具
 */
final class ComboBoxHost extends StatefulWidget
{
    public function createState(): State
    {
        return new ComboBoxHostState();
    }
}

/**
 * @internal 夹具根 State：受控值 + 回调记录。
 */
final class ComboBoxHostState extends State
{
    public string $value = '';

    /** @var list<string> */
    public array $changed = [];

    /**
     * @var list<Option> 面板内容来自它 —— 用例要在**面板开着时**换掉它
     *   （"受控值 ⇒ 面板重读"那条缝的判据，2026-10-24）
     */
    public array $options = [];

    #[\Override]
    public function initState(): void
    {
        $this->options = ComboBoxApplication::cities();
    }

    /** 换选项表（远程取数的形状：应用 setState 换掉整个表） */
    public function setOptions(array $options): void
    {
        $this->setState(function () use ($options): void {
            $this->options = $options;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->children([
            ComboBox::make($this->options)
                ->value($this->value)
                ->placeholder('请选择城市')
                ->onChanged(function (string $value): void {
                    $this->changed[] = $value;
                    // ★ 只标脏（§5.2）：本帧末的 settle 统一重建 ⇒ 字段换标签
                    $this->setState(function () use ($value): void {
                        $this->value = $value;
                    });
                }),
        ]);
    }
}
