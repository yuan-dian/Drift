<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\FlexAlign;
use Drift\State\Subject;

/**
 * 可编辑下拉：输入过滤 + one-of-N。与 `Select` 的差别只有一条：框里能打字。
 * 打字即过滤候选，回车取第一项。值由应用以 `value()` 受控。
 *
 * 形状是一棵现成控件的组合（不新造渲染盒）：
 *   Column(keyboard) → SizedBox(width) → TextField(overlayPanel)
 * `keyboard()` 声明在 `Column` 上，焦点落在 `TextField` 时沿父链解析命中。
 *
 * @api
 */
final class ComboBox extends StatefulWidget
{
    /** 面板内容最小宽（逻辑像素） */
    public const PANEL_MIN_WIDTH = 180.0;

    /** 面板内容高度上限（逻辑像素）；滚动由 `RenderPopupPanel` 按实测自然高决定 */
    public const PANEL_MAX_HEIGHT = 260.0;

    /** 输入框默认宽（逻辑像素） */
    public const DEFAULT_WIDTH = 200.0;

    /** @var list<Option> */
    private readonly array $options;

    /** 当前值（受控）。不匹配任何 `Option::$value` ⇒ 显示 placeholder */
    private string $value = '';

    private string $placeholder = '请选择';

    private float $width = self::DEFAULT_WIDTH;

    private ?\Closure $onChanged = null;

    /**
     * 选项表就绪度（三态，`OptionStatus`）—— 与 `Select` 共用同一值类型。
     * 加载中 / 失败 / 空三种非正常态与选项行互斥。
     */
    private OptionStatus $status;

    /** 面板打开那一刻触发（应用在此发起初始取数） */
    private ?\Closure $onOpen = null;

    /**
     * 用户改了搜索词时触发（`TextField::onChanged` 那条路）。
     * 控件内部复位（开面板清空 / 关面板复位）不触发它。
     */
    private ?\Closure $onSearch = null;

    /**
     * @param list<Option>|array<array-key, string> $options 选项表。两种形态都过
     *        `Option::normalize()`，空表 ⇒ 面板只显示"无匹配项"，不炸。
     */
    public function __construct(array $options)
    {
        parent::__construct();
        $this->options = Option::normalize($options);
        $this->status = OptionStatus::ready();
    }

    /** @param list<Option>|array<array-key, string> $options 见 `Option::normalize()` */
    public static function make(array $options): self
    {
        return new self($options);
    }

    public function value(string $value): static
    {
        $this->mutate();
        $this->value = $value;

        return $this;
    }

    /** 没匹配项时输入框里的提示文字 */
    public function placeholder(string $text): static
    {
        $this->mutate();
        $this->placeholder = $text;

        return $this;
    }

    /** 输入框宽度（面板不受它影响，按内容 + `PANEL_MIN_WIDTH` 自尺寸） */
    public function triggerWidth(float $width): static
    {
        $this->mutate();
        $this->width = $width;

        return $this;
    }

    /** @param callable(string): void $handler 收到被选中那一项的 `Option::$value` */
    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = $handler(...);

        return $this;
    }

    /**
     * 选项表就绪度（受控）—— 取数中 / 失败 / 空表 / 正常，四者互斥。
     */
    public function status(OptionStatus $status): static
    {
        $this->mutate();
        $this->status = $status;

        return $this;
    }

    /**
     * 面板打开那一刻触发一次（不是每次应用重建）。
     * 常见于在此发起初始取数（`onSearch('')`）。
     */
    public function onOpen(callable $handler): static
    {
        $this->mutate();
        $this->onOpen = $handler(...);

        return $this;
    }

    /**
     * 用户改了搜索词 ⇒ 回调收到新词。
     *
     * ★ 声明了它 ⇒ 本地过滤让位（选项表是服务端搜索结果，权威）。
     * 控件不做去抖，每次改动回调一次；应用自己比对词丢弃过期响应。
     *
     * @param callable(string): void $handler
     */
    public function onSearch(callable $handler): static
    {
        $this->mutate();
        $this->onSearch = $handler(...);

        return $this;
    }

    public function createState(): State
    {
        return new ComboBoxState();
    }

    /** @internal 面板读：全部候选 */
    public function optionsValue(): array
    {
        return $this->options;
    }

    /** @internal 受控值（`State` 用它算"字段该显示哪个标签"） */
    public function valueText(): string
    {
        return $this->value;
    }

    /** @internal */
    public function placeholderText(): string
    {
        return $this->placeholder;
    }

    /** @internal */
    public function triggerWidthValue(): float
    {
        return $this->width;
    }

    /** @internal */
    public function changedHandler(): ?\Closure
    {
        return $this->onChanged;
    }

    /** @internal */
    public function statusValue(): OptionStatus
    {
        return $this->status;
    }

    /** @internal */
    public function openHandler(): ?\Closure
    {
        return $this->onOpen;
    }

    /** @internal 非 `null` ⇒ 远程搜索模式（本地过滤让位） */
    public function searchHandler(): ?\Closure
    {
        return $this->onSearch;
    }
}

/**
 * `ComboBox` 的纯 UI 态。它同时是跨树广播的发起端（`ComboBoxQuery`）：
 * 输入框在主树、候选面板在浮层树，两者唯一的合法联系就是这个 `Subject`。
 *
 * @api
 */
final class ComboBoxState extends State
{
    /** @internal 查询词（= 字段上打的字，也是过滤词） */
    private ComboBoxQuery $query;

    /**
     * @internal 字段此刻是"搜索框"还是"已选值的显示框"。
     * - `true`  ⇒ 字段显示 `$query`，过滤词 = `$query`；
     * - `false` ⇒ 字段显示已选值的标签。
     * 切换点只有两处：打开面板（⇒ true）与面板关闭（⇒ false）。
     */
    private bool $editing = false;

    /**
     * @internal 面板的 dismiss（开面板时由框架注入）。
     * `null` ⇔ 面板关着 —— 它是"开没开"的唯一判据，不另设布尔。
     * 复位点在面板 `State::dispose()`（三条关闭路径都汇到这里）。
     */
    private ?\Closure $dismiss = null;

    /**
     * @internal 见过的 `(value ⇒ label)` 配对 —— 只为远程搜索而存在。
     * 远程搜索换表后，当前 `options` 里找不到已选值时，用这里兜底显示标签。
     */
    private string $knownValue = '';

    /** @internal 见 `$knownValue` */
    private string $knownLabel = '';

    #[\Override]
    public function initState(): void
    {
        // 订阅端在另一棵树（面板），这个 Subject 是控件内部的一根线，
        // 不出现在公共 API 上（返回类型只是 `Subject`）。
        $this->query = new ComboBoxQuery();
    }

    public function build(BuildContext $ctx): Widget
    {
        $field = TextField::make()
            ->value($this->fieldText())
            ->placeholder($this->combo()->placeholderText())
            ->onChanged(function (string $text): void {
                $this->onInput($text);
            })
            ->overlayPanel($this->panelFactory())
            // ★ 受控值（选项表）⇒ 面板重读由框架负责（overlaySignature）。
            //   此前这里什么都没有 ⇒ 面板开着时应用换掉 options，面板纹丝不动且不报错。
            ->overlaySignature(fn (): string => $this->panelSignature());

        // `keyboard()` 在外层：焦点落在里面的 `TextField` 上，靠父链解析命中。
        return Column::make()
            ->children([
                SizedBox::make($field)->width($this->combo()->triggerWidthValue()),
            ])
            ->keyboard($this->keyHandler());
    }

    // ── 面板侧要用的（`@internal`：只给同文件的面板 State 调）──

    /**
     * @internal 面板订阅它 ⇒ 打字后自己重建（跨树的唯一一根线）
     */
    public function querySubject(): Subject
    {
        return $this->query;
    }

    /**
     * @internal 面板内容 —— `OptionPanel` 每次重建都会调它（读当前值，不是快照）。
     * 全部 `Flat`、不标已选项：Enter 取的是"第一项"，标了已选项会让"回车会选谁"变含糊。
     */
    public function panelContent(): Widget
    {
        $rows = [];
        $status = $this->combo()->statusValue();
        $statusRow = $status->row();

        // 三态与选项行互斥：状态不 ready ⇒ 只画那一行；ready ⇒ 画选项（空表画内置兜底）。
        if ($statusRow !== null) {
            $rows[] = $statusRow;
        } else {
            foreach ($this->matches() as $option) {
                $rows[] = Button::make($option->label)
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(function () use ($option): void {
                        $this->commit($option);
                    });
            }

            if ($rows === []) {
                $rows[] = Text::make('无匹配项')->caption();
            }
        }

        // `cross(Stretch)`：面板给内容有界宽 ⇒ 整行可点、高亮横贯。
        return Column::make()
            ->cross(FlexAlign::Stretch)
            ->children($rows)
            // 焦点在行上时的按键转发（见 forwardKey()）：只有焦点在这一支时命中。
            ->keyboard(function (KeyEvent $event, Element $self): bool {
                return $this->forwardKey($event);
            });
    }

    /**
     * 面板受控部分的签名 —— 交给框架（见 build() 里的 overlaySignature()）。
     *
     * 查询词刻意不进签名：它是控件私有态，走 `$this->query` 广播。
     * 只看 `(value,label)` 序列，不看对象身份；三态段由 `OptionStatus` 给。
     *
     * @internal
     */
    private function panelSignature(): string
    {
        $parts = [$this->combo()->statusValue()->signature()];
        foreach ($this->combo()->optionsValue() as $option) {
            $parts[] = $option->value . "\x1f" . $option->label;
        }

        return implode("\x1e", $parts);
    }

    /**
     * @internal 当前候选（本地模式：按查询词过滤；远程模式：原样返回）。
     *
     * 远程模式下不能再筛：服务端可能按拼音 / 按 value / 按首字母匹配，
     *   本地再按标签子串筛是二次收缩（"服务端返回 8 条、面板只显示 2 条"）。
     *   判据是"应用声明了 `onSearch`"—— 声明了就意味着选项表是权威。
     *
     * 本地匹配：标签的大小写不敏感子串，用 `mb_strtoupper()`（本仓 AOT 侧
     *   只验证过这一支字符串函数）。
     *
     * @return list<Option>
     */
    public function matches(): array
    {
        $all = $this->combo()->optionsValue();

        if ($this->combo()->searchHandler() !== null) {
            return $all;
        }

        $needle = \mb_strtoupper($this->query->text(), 'UTF-8');

        if ($needle === '') {
            return $all;
        }

        $out = [];
        foreach ($all as $option) {
            if (strpos(\mb_strtoupper($option->label, 'UTF-8'), $needle) !== false) {
                $out[] = $option;
            }
        }

        return $out;
    }

    /**
     * @internal 选中一项：收面板 + 过滤词复位 + 回调应用。
     */
    public function commit(Option $option): void
    {
        $this->closePanel();

        $handler = $this->combo()->changedHandler();
        if ($handler !== null) {
            $handler($option->value);
        }
    }

    /**
     * @internal 焦点在面板行上时的按键转发（`ComboBoxPanelState` 的 keyboard()）。
     *
     * 转给输入框的编辑器 ⇒ 退格 / 方向键 / Ctrl+A 照旧，不必重写编辑动作。
     * 返回 false 的键（Enter）继续往下走 ⇒ 选中那一行，语义天然一致。
     */
    public function forwardKey(KeyEvent $event): bool
    {
        if ($event->chord->is(KeyChord::escape())) {
            $this->closePanel();

            return true;
        }

        $field = $this->fieldState();

        return $field !== null && $field->handleKey($event);
    }

    /**
     * @internal 面板被回收 —— 三条关闭路径的唯一汇合点（Esc / 选中 / 点面板外 / owner 清扫）。
     *
     * 复位只放这里：`removeOverlay()` 会 unmount 面板树 ⇒ dispose() 一定跑到；
     *   写 closePanel() 会漏掉"点面板外"与"owner 清扫"。
     * 复位 = 字段回到已选标签：丢弃用户打的过滤词（"点外面 = 取消"语义）。
     */
    public function onPanelClosed(): void
    {
        $this->dismiss = null;
        $this->editing = false;
        $this->query->setText('');
        $this->markDirty();
    }

    // ── 内部 ──────────────────────────────────────────────────────────

    /** 字段此刻该显示什么 */
    private function fieldText(): string
    {
        if ($this->editing) {
            return $this->query->text();
        }

        $value = $this->combo()->valueText();
        foreach ($this->combo()->optionsValue() as $option) {
            if ($option->value === $value) {
                // 顺手记住「这个值显示成什么」（远程搜索换表后兜底，见 $knownValue）。
                $this->knownValue = $value;
                $this->knownLabel = $option->label;

                return $option->label;
            }
        }

        // 当前值不在选项表里：远程搜索必然走到这里（选项是"这次搜索的结果"）。
        // 返回最后一次真见过的标签（且必须同值 —— 换了值不该拿旧标签冒充）。
        if ($value !== '' && $value === $this->knownValue) {
            return $this->knownLabel;
        }

        return '';   // 没见过 ⇒ 空串，`TextField` 自己显示 placeholder
    }

    /** 用户打了一个字（`TextFieldState::apply()` 的回调） */
    private function onInput(string $text): void
    {
        $this->editing = true;
        // 不 markDirty()：输入框编辑器已自己标脏，要变的只有另一棵树的面板（notifyChanged 的事）。
        $this->query->setText($text);

        // `onSearch` 只挂在这里 —— 这是"用户改了搜索词"的唯一入口。
        // 开面板清空 / 关面板复位是控件自己的状态管理，接在那会变成每次开关面板都发请求。
        $handler = $this->combo()->searchHandler();
        if ($handler !== null) {
            $handler($text);
        }
    }

    /**
     * 面板工厂 —— `Application` 开面板时调用，形参是它注入的 dismiss 闭包。
     *
     * 工厂里做两件"打开时该发生"的事：记下 dismiss（"开着"的判据）、把字段切成搜索框。
     *   框架的 activateFocusedWidget() 只负责"开"，不知道这个控件的打开语义。
     *
     * ★ 只在"字段还显示标签"时清空（if (!$this->editing)）：用户可能在面板关着时已打字，
     *   点开面板不该吃掉他刚打的字。反之字段显示标签时点开必须清空，否则打字会追加在标签后。
     */
    private function panelFactory(): \Closure
    {
        return function (\Closure $dismiss): Widget {
            $this->dismiss = $dismiss;

            if (!$this->editing) {
                $this->editing = true;
                $this->query->setText('');
                // 字段要从"已选标签"变成空的搜索框 ⇒ 需要一次真重建（不在 build 期）。
                $this->markDirty();
            }

            // onOpen 的唯一触发点：工厂在"要开面板"时被调一次。
            $open = $this->combo()->openHandler();
            if ($open !== null) {
                $open();
            }

            return OptionPanel::make(
                $this->query,
                fn (): Widget => $this->panelContent(),
                ComboBox::PANEL_MIN_WIDTH,
                ComboBox::PANEL_MAX_HEIGHT,
                // 面板被回收 ⇒ 复位"开着"判据（三条关闭路径都汇到 onPanelClosed）。
                fn (): mixed => $this->onPanelClosed(),
            );
        };
    }

    /**
     * 键盘缝：Enter 取第一项 / Esc 收起，其余一律让位。
     *
     * 面板关着时 Enter 必须让位：dispatchKey() 跑在 activateFocused() 之前，
     *   返回 true 就把"Enter 开面板"堵死（鼠标点得开、键盘打不开）。
     * 面板开着但无匹配项 / 三态非 ready 时 Enter 吃掉：让那行留在眼前，
     *   否则 Enter 会走到 toggleWidgetPanel() 把面板关掉，用户以为选中了什么。
     * Esc 在面板关着时也让位：TextFieldState::handleKey() 用它清选区。
     */
    private function keyHandler(): \Closure
    {
        return function (KeyEvent $event, Element $self): bool {
            $open = $this->dismiss !== null;

            if ($event->chord->is(KeyChord::escape())) {
                if (!$open) {
                    return false;
                }
                $this->closePanel();

                return true;
            }

            if (!$event->chord->is(KeyChord::enter()) || !$open) {
                return false;
            }

            // 三态不是 ready ⇒ 吃掉 Enter（什么都不做）。远程搜索里必需：
            //   加载中 / 失败时面板里没选项行，但应用可能还留着上一批 options，
            //   不加闸 Enter 会选中用户没看见的项。
            if (!$this->combo()->statusValue()->showsOptions()) {
                return true;
            }

            $first = $this->firstMatch();
            if ($first === null) {
                return true;
            }

            $this->commit($first);

            return true;
        };
    }

    /** 过滤后的第一项（`null` = 一项都没有） */
    private function firstMatch(): ?Option
    {
        foreach ($this->matches() as $option) {
            return $option;
        }

        return null;
    }

    /**
     * 收面板（Esc / 选中 共用）—— 复位不在本方法，在 onPanelClosed()。
     *
     * $dismiss() 走 closeWidgetPanel()，按 owner 找条目，找不到就什么都不做
     *   （面板可能已被别的路径收掉）⇒ 那时没人调 dispose() ⇒ 字段卡在搜索框。
     *   兜底显式补一次 onPanelClosed()，代价是一次幂等调用。
     */
    private function closePanel(): void
    {
        $dismiss = $this->dismiss;
        if ($dismiss !== null) {
            $dismiss();
        }

        if ($this->dismiss !== null) {
            $this->onPanelClosed();
        }
    }

    /**
     * 自己的子树里那个 TextFieldState（焦点在面板里时要转发按键给它）。
     * 不缓存：面板可能开着而输入框所在子树刚被换过，现取一次只花 O(子树)。
     */
    private function fieldState(): ?TextFieldState
    {
        return self::findFieldState($this->element());
    }

    private static function findFieldState(?Element $element): ?TextFieldState
    {
        if ($element === null) {
            return null;
        }

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            $child = $element->childAt($i);
            if ($child === null) {
                continue;
            }

            if ($child instanceof StatefulElement) {
                $state = $child->state();
                if ($state instanceof TextFieldState) {
                    return $state;
                }
            }

            $hit = self::findFieldState($child);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    private function combo(): ComboBox
    {
        $widget = $this->widget();
        if (!$widget instanceof ComboBox) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            \Drift\Errors::report('ComboBoxState 只能配 ComboBox（框架内部不变量被破坏）');
        }

        return $widget;
    }
}

/**
 * 查询词的广播端 —— 专门为了跨树（主树输入、浮层树重建）。
 *
 * 它不承载业务数据（不是 Store）：只装一个 UI 态字符串，不出现在公共 API 上。
 *
 * @internal
 */
final class ComboBoxQuery extends Subject
{
    private string $text = '';

    public function text(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        if ($text === $this->text) {
            return;   // 同值不广播（省一次面板重建）
        }

        $this->text = $text;
        $this->notifyChanged();
    }
}
