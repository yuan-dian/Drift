<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\FlexAlign;
use Drift\State\Subject;

/**
 * 下拉选择。交出一个「Button（Default 变体）+ 三条声明」：overlayPanel 浮出选项面板、
 * keyboard 接管方向键 / Enter、variant(Default)+width 决定外观。
 * 值受控（应用持有，点击只回调 onChanged），支持三态远程数据（loading/error/empty/ready）。
 * @api
 */
final class Select extends StatefulWidget
{
    /** 面板内容最小宽（逻辑像素）—— 比这更窄的一列选项点起来太挤 */
    public const PANEL_MIN_WIDTH = 180.0;

    /**
     * 面板内容高度上限（逻辑像素）。更长的列表才滚（`RenderPopupPanel` 按实测自然高决定）。
     */
    public const PANEL_MAX_HEIGHT = 260.0;

    /** 触发框默认宽（逻辑像素）。`Button` 被定宽后标签靠左，正好是选择框的形态 */
    public const DEFAULT_WIDTH = 160.0;

    /** @var list<Option> */
    private readonly array $options;

    /**
     * 当前值（受控）。不匹配任何 `Option::$value` ⇒ 显示 `placeholder`
     * （空串可能是合法值，不能当哨兵）。
     */
    private string $value = '';

    private string $placeholder = '请选择';

    private float $width = self::DEFAULT_WIDTH;

    private ?\Closure $onChanged = null;

    /**
     * 选项表的就绪度（三态）—— ready / loading / empty / error，互斥。
     */
    private OptionStatus $status;

    /** 面板**打开那一刻**触发（应用在这里发起取数；`setState` 换 `status` / 选项表） */
    private ?\Closure $onOpen = null;

    /**
     * @param list<Option>|array<array-key, string> $options 选项表。两种形态都过
     *        `Option::normalize()`（映射形态：键是值、值是显示名）；空表 ⇒ 面板
     *         画一行内置"没有可选项"（要换文案用 `OptionStatus::empty('…')`）。
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

    /** 没有匹配项时**触发框**里的提示文字（面板里的空态是 `emptyText()`） */
    public function placeholder(string $text): static
    {
        $this->mutate();
        $this->placeholder = $text;

        return $this;
    }

    /** 触发框宽度（面板不受它影响，面板按内容 + `PANEL_MIN_WIDTH` 自尺寸） */
    public function triggerWidth(float $width): static
    {
        $this->mutate();
        $this->width = $width;

        return $this;
    }

    /** @param callable(string): void $handler 收到被点中那一项的 `Option::$value` */
    public function onChanged(callable $handler): static
    {
        $this->mutate();
        $this->onChanged = $handler(...);

        return $this;
    }

    /**
     * 选项表的就绪度（受控）—— 取数中 / 失败 / 空表 / 正常，四者互斥。
     * 这是受控属性，不是控件自己管的状态：何时开始、何时结束只有应用知道。
     */
    public function status(OptionStatus $status): static
    {
        $this->mutate();
        $this->status = $status;

        return $this;
    }

    /**
     * 面板**打开那一刻**触发一次（应用在这里发起取数）。
     * ★ 触发点是 overlayPanel 工厂 —— 不要在 build() 里取数：那会在每次应用
     *   重建时都发一次请求。
     */
    public function onOpen(callable $handler): static
    {
        $this->mutate();
        $this->onOpen = $handler(...);

        return $this;
    }

    public function createState(): State
    {
        return new SelectState();
    }

    // ── State 侧读的口径（只给同文件的 `SelectState` 调）──

    /** @internal @return list<Option> */
    public function optionsValue(): array
    {
        return $this->options;
    }

    /** @internal */
    public function valueValue(): string
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
    public function statusValue(): OptionStatus
    {
        return $this->status;
    }

    /** @internal */
    public function onOpenHandler(): ?\Closure
    {
        return $this->onOpen;
    }

    /** @internal */
    public function onChangedHandler(): ?\Closure
    {
        return $this->onChanged;
    }

    /** 当前值在选项表里的下标；`-1` = 没有匹配项（显示 placeholder） */
    public function selectedIndex(): int
    {
        foreach ($this->options as $i => $option) {
            if ($option->value === $this->value) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * 下标 → 显示名（越界返回空串）。
     * ★ 单独的带返回类型方法：`$this->options[$i]->label` 这种"数组下标再取属性"
     *   在 AOT 侧会被推成 php::Var。
     * `public` 只为同文件的 `SelectState` 能调（PHP 的 private 类内可见性不跨类）；
     *   不属于公共 API，应用侧别用。
     * @internal
     */
    public function labelAt(int $index): string
    {
        if ($index < 0 || $index >= \count($this->options)) {
            return '';
        }

        return $this->options[$index]->label;
    }
}

/**
 * `Select` 的私有 State。持三样跨 build 活且只影响这一棵子树的东西：
 * highlight（方向键光标）、signal（跨树广播端）、dismiss（面板开着 ⇔ 非 null）。
 * @internal
 */
final class SelectState extends State
{
    /** @internal 面板订阅它 ⇒ **高亮**一变就跨树重建（受控值那条走框架的签名） */
    private SelectPanelSignal $signal;

    /**
     * @internal 面板的 dismiss（`overlayPanel` 工厂的形参，开面板时由框架注入）。
     *   **`null` ⇔ 面板关着** —— 它是"开没开"的唯一判据，不另设布尔（两个字段
     *   必然有一个会陈旧）。
     */
    private ?\Closure $dismiss = null;

    /** @internal 方向键光标（选项表下标）。打开面板时复位成当前值的下标 */
    private int $highlight = 0;

    #[\Override]
    public function initState(): void
    {
        $this->signal = new SelectPanelSignal();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Button::make($this->label())
            ->variant(ButtonVariant::Default)
            ->width($this->select()->triggerWidthValue())
            ->overlayPanel($this->panelFactory())
            // 受控值 ⇒ 面板重读由框架负责（`Widget::overlaySignature()`：三态 / 选项表 /
            //   受控值都住在 Widget 上，框架比对签名变了就标脏面板）。
            // `keyboard()` 声明在外层：焦点落在这个 `Button` 上，靠父链解析命中。
            ->overlaySignature(fn (): string => $this->contentSignature())
            ->keyboard($this->keyHandler());
    }

    /** 触发框上显示什么：有匹配项 ⇒ 它的标签；否则 ⇒ placeholder */
    private function label(): string
    {
        $index = $this->select()->selectedIndex();

        return $index < 0 ? $this->select()->placeholderText() : $this->select()->labelAt($index);
    }

    /**
     * 面板工厂 —— `Application` 开面板时调用，形参是它注入的 dismiss 闭包。
     * 这里是 `onOpen` 的唯一触发点：工厂在"要开面板"时被调一次，不在 build() 里。
     */
    private function panelFactory(): \Closure
    {
        return function (\Closure $dismiss): Widget {
            $this->dismiss = $dismiss;

            $this->highlight = $this->select()->selectedIndex();
            if ($this->highlight < 0) {
                $this->highlight = 0;
            }

            $open = $this->select()->onOpenHandler();
            if ($open !== null) {
                $open();
            }

            return OptionPanel::make(
                $this->signal,
                fn (): Widget => $this->panelContent(),
                Select::PANEL_MIN_WIDTH,
                Select::PANEL_MAX_HEIGHT,
                fn (): mixed => $this->onPanelClosed(),
            );
        };
    }

    /**
     * 面板内容 —— `OptionPanel` **每次重建都会调**它，所以读的全是当前值。
     * 三态与选项行互斥（`OptionStatus::showsOptions()`），没有优先级判断链：
     *   状态不 ready ⇒ 画那一行；ready ⇒ 画选项（空表时画内置兜底文案）。
     */
    private function panelContent(): Widget
    {
        $select = $this->select();
        $rows = [];
        $status = $select->statusValue();
        $statusRow = $status->row();

        if ($statusRow !== null) {
            $rows[] = $statusRow;
        } else {
            $options = $select->optionsValue();

            if ($options === []) {
                // 选项表空但应用没声明空态 ⇒ 画内置兜底文案（与 `OptionStatus::empty()` 区分）。
                $rows[] = Text::make('没有可选项')->caption();
            } else {
                foreach ($options as $index => $option) {
                    // 选中项用 Primary 底，方向键光标也用它 —— 键盘与鼠标用户看到的高亮一致。
                    // 不用 "✓" 前缀：内置字模子集里没有 U+2713。
                    $rows[] = Button::make($option->label)
                        ->variant($index === $this->highlight ? ButtonVariant::Primary : ButtonVariant::Flat)
                        ->onPressed(function () use ($option): void {
                            $this->commit($option);
                        });
                }
            }
        }

        // `cross(Stretch)`：面板给内容有界宽，整行可点、高亮横贯。
        return Column::make()
            ->cross(FlexAlign::Stretch)
            ->children($rows)
            // 焦点在行上（Tab 进来）时按键转发到同一套导航
            ->keyboard(function (KeyEvent $event, Element $self): bool {
                return $this->handleNav($event);
            });
    }

    /**
     * 键盘缝（焦点在**触发框**上时）。
     * ★ 面板关着 ⇒ 一律返回 `false`（让位给框架）：`dispatchKey()` 跑在
     *   `activateFocused()` 之前，返回 `true` 会把"Enter/Space 开面板"那条路堵死
     *   （现象：鼠标点得开、键盘打不开）。
     * 面板开着 ⇒ 上下键移高亮、Enter 提交；ESC 仍返回 `false`，让浮层的
     *   `dismissOnEsc` 去关（自己关会与框架关闭路径重复）。
     */
    private function keyHandler(): \Closure
    {
        return function (KeyEvent $event, Element $self): bool {
            if ($this->dismiss === null) {
                return false;
            }

            return $this->handleNav($event);
        };
    }

    /** 上下键 / Enter 的唯一实现（触发框与面板行共用一套） */
    private function handleNav(KeyEvent $event): bool
    {
        $chord = $event->chord;

        if ($chord->is(KeyChord::down())) {
            $this->moveHighlight(1);

            return true;
        }

        if ($chord->is(KeyChord::up())) {
            $this->moveHighlight(-1);

            return true;
        }

        if ($chord->is(KeyChord::enter())) {
            $this->commitHighlighted();

            return true;
        }

        return false;
    }

    /**
     * 移光标（**环绕**：末项往下 ⇒ 第一项，与 `FocusManager` 的 Tab 环同手感）。
     * 不调 `setState()`：高亮只影响面板那棵树，标脏主树会每按一次方向键重排整个页面。
     */
    private function moveHighlight(int $delta): void
    {
        $count = \count($this->select()->optionsValue());
        if ($count === 0) {
            return;
        }

        $next = $this->highlight + $delta;
        if ($next < 0) {
            $next = $count - 1;
        } elseif ($next >= $count) {
            $next = 0;
        }

        if ($next === $this->highlight) {
            return;
        }

        $this->highlight = $next;
        $this->signal->notifyChanged();
    }

    /** Enter：提交光标那一项。空表 / 三态行 ⇒ **什么都不做**（别把面板关掉） */
    private function commitHighlighted(): void
    {
        $options = $this->select()->optionsValue();
        if (!isset($options[$this->highlight])) {
            return;
        }

        $this->commit($options[$this->highlight]);
    }

    /**
     * 选中一项：**先收面板，再回调应用**。
     * 反过来 ⇒ 应用 `setState` 让面板带新值重建一帧才被收掉，"点一项列表闪一下"。
     */
    private function commit(Option $option): void
    {
        $handler = $this->select()->onChangedHandler();
        $dismiss = $this->dismiss;
        $this->dismiss = null;

        if ($dismiss !== null) {
            $dismiss();
        }

        if ($handler !== null) {
            $handler($option->value);
        }
    }

    /**
     * 面板被回收 —— **三条关闭路径的唯一汇合点**（点面板外 / owner 清扫 / Esc）。
     * 高亮不用复位：下次打开时 `panelFactory()` 会按当前值重算。
     */
    private function onPanelClosed(): void
    {
        $this->dismiss = null;
    }

    /**
     * 面板**真正**在意的东西的签名 —— 交给框架（`build()` 里的 `overlaySignature()`）。
     * 只比对 `(value,label)` 序列：重排 / 换文案才算变，重建造新实例不触发。
     */
    private function contentSignature(): string
    {
        $select = $this->select();
        $parts = [
            $select->statusValue()->signature(),
            $select->valueValue(),
        ];

        foreach ($select->optionsValue() as $option) {
            $parts[] = $option->value . "\x1f" . $option->label;
        }

        return implode("\x1e", $parts);
    }

    private function select(): Select
    {
        $widget = $this->widget();
        if (!$widget instanceof Select) {
            \Drift\Errors::report('SelectState 只能配 Select（框架内部不变量被破坏）');
        }

        return $widget;
    }
}

/**
 * `Select` 面板的广播端 —— **专门为了跨树**（主树是触发框、浮层是另一棵树）。
 * 只服务**控件私有态**（方向键高亮）：受控值那条路归框架（`Widget::overlaySignature()`）。
 * 不承载业务数据：只表达"面板该重读了"，且不在公共 API 上。
 * @internal
 */
final class SelectPanelSignal extends Subject
{
}
