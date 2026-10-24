<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\FlexAlign;
use Drift\State\Subject;

/**
 * 下拉选择（W8-2 第五件，2026-10-23；2026-10-24 改为有态 + 三态 + 方向键）。
 *
 * ```php
 * Select::make([
 *     Option::make('北京市', '110000'),
 *     Option::make('上海市', '310000'),
 * ])->value($this->city)                       // 受控：值住应用的 State
 *   ->onChanged(fn(string $v) => $this->setState(fn() => $this->city = $v));
 *
 * // 短形式：键 = 值、值 = 显示名（`Option::normalize()`）
 * Select::make(['110000' => '北京市'])->value($this->city);
 *
 * // 行数组：DB fetchAll / HTTP JSON 原样喂（`Option::fromRows()`）
 * Select::make(Option::fromRows($rows, 'name', 'id'))->value($this->city);
 *
 * // 远程数据三态 + 打开才取
 * Select::make($this->cities)
 *     ->loading($this->loading)
 *     ->emptyText('没有匹配的城市')
 *     ->errorText($this->error)
 *     ->onOpen(fn() => $this->loadCities());    // 面板打开那一刻触发取数
 * ```
 *
 * ## 形状从哪来（为什么是组合，不是新渲染盒）
 *
 * `Select` 交出一个 **`Button`（`Default` 变体）+ 三条声明**：
 *
 * | 声明 | 谁消费 | 管什么 |
 * |---|---|---|
 * | `overlayPanel($factory)` | `Application::activateFocusedWidget()` | 激活时浮出选项面板 |
 * | `keyboard($handler)` | `FocusManager::dispatchKey()` | 面板开着时接管上下键 / Enter |
 * | `variant(Default)` + `width()` | `RenderButton` | 外观：有底有边的"选择框" |
 *
 * ★★ **刻意不写 `RenderSelect`**：新渲染盒是本仓最贵的改动面（每一条都出过真机
 *   bug：内禀失真、offset 双重平移、AOT 常量折叠）。而"一个有边框的、点了浮面板
 *   的按钮"用现成的 `Button` **完全表达得出来** —— 焦点、激活、光标、语义、命中
 *   全白送。代价只有一条（写进能力边界）：没有下拉箭头。
 *
 * ## 为什么是 `StatefulWidget`（2026-10-24 从 `StatelessWidget` 改过来）
 *
 * 两件事逼的，都是**结构性的**，不是"想加个状态"：
 *
 * 1. **浮层是第二棵根渲染树**（`OverlayEntry::$panel` 的字段注释就是这条设计）：
 *    主树重建**不带动**面板。`loading` / 选项表 / `emptyText` 这些受控值住在
 *    Widget 上，应用 `setState` 换了 Widget 之后，面板**不会自己重读** ⇒ 必须由
 *    `SelectState` 订阅一个 `Subject` 并在 `didUpdateWidget()` 里 publish。
 * 2. **方向键换高亮**是控件私有态（应用不该为"光标停在第几行"再存一个字段），
 *    它变化时主树根本不用重建 ⇒ 只能走同一条跨树通道。
 *
 * 于是面板的构建时机从"打开那一刻一次"变成"每次内容变化都重来"，选中高亮也就
 * 不会再停在旧值上 —— 这正是 2026-10-23 版本"方向键换值"做不了的根因。
 *
 * ★ `didUpdateWidget()` 里做了**签名比对**才 publish：应用每次重建都会造一个新的
 *   `Select`（选项对象也全是新实例），无条件 publish 会让面板每次应用重建都重建
 *   一次 —— 表现是"列表滚动位置莫名回到顶部"。签名只看面板**真的**在意的东西。
 *
 * ## 浮层归谁
 *
 * 打开 / 落位 / 关闭 / 归属全在 `Application`（`§5.1` 裁决 1）。本类只**声明**
 * 面板内容，并让面板里的每一行在点完后调 `Application` 注入进来的 `dismiss`。
 *
 * ★ 面板 `owner` = 控件自身 ⇒ 页面走了没关下拉，`placeOverlays()` 的 owner 清扫
 *   会收尸（`01` §7 的那条防线，2026-10-23 才真正被消费）。
 * ★ 面板 `barrier = true`（与菜单同）：点面板外 ⇒ 关且**吞掉该次按下**，这是
 *   下拉的标准手感，也是 `dismissOnTapOutside` 唯一生效的路径（`barrier=false`
 *   的条目在 `dispatchPointer()` 里直接穿透，永远轮不到"点在层外"那一支）。
 *
 * ## 受控
 *
 * `value()` 由应用给，点击只回调 `onChanged(新值)`（与 `Checkbox` / `Radio` /
 * `SegmentedControl` 同纪律）。控件**不自持**"选了谁" —— 自持会让"提交时读到的值"
 * 与"屏幕上显示的值"分成两份真相。
 *
 * ## 能力边界（v1 刻意不做，别当缺陷报）
 *
 * - **下拉箭头**：没有。内置字模缺 164 个字符（含 `▾`）⇒ 要画得用 `CustomPaint`，
 *   而 `Button` 的标签位只收字符串 ⇒ 得再包一层 `Row`，那会多出一个焦点目标。
 *   v1 用"有底有边的框 + 占位文字"表达可点。
 * - **可编辑（输入过滤）**：不在本类 —— 那是 `ComboBox`（`TextField` + 过滤浮层，
 *   与 `Select` 同一条"不写新渲染盒"的裁决）。
 * - **远程搜索**：没有 —— `Select` 不能打字。远程**过滤**是 `ComboBox` 的事。
 * - **多选**：没有 —— 多选是 `list<string>` 的受控值，形状不同。
 * - **方向键 = 焦点移动**：没走那条路。它要新开一条"复合控件内方向键"的框架缝
 *   （控件闭包拿不到 `FocusManager`），而焦点/手势是本仓出过最多 bug 的地方 ——
 *   为一个 nice-to-have 动它不划算。本类的做法是"高亮 + Enter"，与鼠标点选等价。
 *
 * @api
 */
final class Select extends StatefulWidget
{
    /** 面板内容最小宽（逻辑像素）—— 比这更窄的一列选项点起来太挤 */
    public const PANEL_MIN_WIDTH = 180.0;

    /**
     * 面板内容高度上限（逻辑像素）。
     *
     * ★ 8 行 × 约 33px ≈ 264，取 260 ⇒ 常见"一次看得完"的列表**贴着内容**不滚动，
     *   更长的才滚（`RenderPopupPanel` 按实测自然高决定，不猜条目数）。
     */
    public const PANEL_MAX_HEIGHT = 260.0;

    /** 触发框默认宽（逻辑像素）。`Button` 被定宽后标签靠左，正好是选择框的形态 */
    public const DEFAULT_WIDTH = 160.0;

    /** @var list<Option> */
    private readonly array $options;

    /**
     * 当前值（受控）。**不匹配任何 `Option::$value` ⇒ 显示 `placeholder`**
     * （不用空串当哨兵：值域是应用给的，任何字符串都可能合法）。
     */
    private string $value = '';

    private string $placeholder = '请选择';

    private float $width = self::DEFAULT_WIDTH;

    private ?\Closure $onChanged = null;

    /** 远程取数中 ⇒ 面板只显示一行"正在加载…"（优先于 errorText 与选项行） */
    private bool $loading = false;

    /** 选项表为空时面板里那一行（不是 placeholder：那是**触发框**里的字） */
    private string $emptyText = '没有可选项';

    /** 非空 ⇒ 面板只显示这一行（优先于 emptyText 与选项行，低于 loading） */
    private string $errorText = '';

    /** 面板**打开那一刻**触发（应用在这里发起取数；`setState` 换 `loading` / 选项表） */
    private ?\Closure $onOpen = null;

    /**
     * @param list<Option>|array<array-key, string> $options 选项表。两种形态都过
     *        `Option::normalize()`（映射形态 = 键是值、值是显示名），空表 ⇒ 面板
     *        显示 `emptyText`，不炸。
     */
    public function __construct(array $options)
    {
        parent::__construct();
        $this->options = Option::normalize($options);
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
     * 远程取数中（受控）。
     *
     * ★ 这是**受控属性**，不是控件自己管的状态：什么时候开始、什么时候算结束，
     *   只有应用知道（`Job` + `TaskHost` 的 `onDone`）。控件只负责"显示成什么样"。
     */
    public function loading(bool $on): static
    {
        $this->mutate();
        $this->loading = $on;

        return $this;
    }

    /** 选项表为空时面板里那一行（例如"没有匹配的城市"） */
    public function emptyText(string $text): static
    {
        $this->mutate();
        $this->emptyText = $text;

        return $this;
    }

    /** 非空 ⇒ 面板只显示这一行（取数失败时用）。优先级低于 `loading()` */
    public function errorText(string $text): static
    {
        $this->mutate();
        $this->errorText = $text;

        return $this;
    }

    /**
     * 面板**打开那一刻**触发一次（应用在这里发起取数）。
     *
     * ★ 触发点是 `overlayPanel` 工厂 —— 框架在"要开面板"时调它一次。不要在
     *   `build()` 里取数：那会在每次应用重建时都发一次请求。
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

    // ── State 侧读的口径（`@internal`：只给同文件的 `SelectState` 调）──

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
    public function loadingValue(): bool
    {
        return $this->loading;
    }

    /** @internal */
    public function emptyTextValue(): string
    {
        return $this->emptyText;
    }

    /** @internal */
    public function errorTextValue(): string
    {
        return $this->errorText;
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
     *
     * ★ 单独一个**带返回类型**的方法：`$this->options[$i]->label` 这种"数组下标再取
     *   属性"在 AOT 侧会被推成 `php::Var`（同 `Application::shiftSnackQueue()` 的理由）。
     * ★ `public` 只是为了让同文件的 `SelectState` 能调（PHP 的 `private` 类内可见性
     *   不跨类）；**不属于公共 API**，应用侧别用。
     *
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
 * `Select` 的私有 State（2026-10-24）。
 *
 * 它持三样东西，都是"跨 build 活且只影响这一棵子树"的（§11.5 的判据）：
 * `highlight`（方向键光标）、`signal`（跨树广播端）、`dismiss`（面板开着 ⇔ 非 null）。
 *
 * @internal
 */
final class SelectState extends State
{
    /** @internal 面板订阅它 ⇒ 高亮 / 受控值一变就跨树重建 */
    private SelectPanelSignal $signal;

    /**
     * @internal 面板的 dismiss（`overlayPanel` 工厂的形参，开面板时由框架注入）。
     *   **`null` ⇔ 面板关着** —— 它是"开没开"的唯一判据，不另设布尔（两个字段
     *   必然有一个会陈旧）。
     */
    private ?\Closure $dismiss = null;

    /** @internal 方向键光标（选项表下标）。打开面板时复位成当前值的下标 */
    private int $highlight = 0;

    /** @internal 上一次见过的"面板真正在意的东西"的快照（见 `didUpdateWidget`） */
    private string $signature = '';

    #[\Override]
    public function initState(): void
    {
        $this->signal = new SelectPanelSignal();
        $this->signature = $this->contentSignature();
    }

    /**
     * 受控值 / 选项表 / 三态都住 Widget 上 ⇒ Widget 一换，面板就得重读。
     *
     * ★★★ **必须先 `parent::didUpdateWidget($widget)`**：基类那一句是**唯一**把
     *   新 Widget 落到 `State::$widget` 的地方（`State::widget()` 读的就是它）。
     *   漏掉 parent 的后果完全静默：`widget()` 永远返回**旧**对象 ⇒ 这里算出的
     *   签名恒等 ⇒ 面板永不更新，而 `didUpdateWidget()` 看起来被调了、也不报错。
     *   （2026-10-24 实测踩中：`didUpdate=2 / published=0`。）
     *
     * ★ **必须比对签名**：应用每次重建都会造一个新的 `Select`（选项对象也全是新
     *   实例），无条件 publish 会让面板跟着应用每次重建都重建 —— 表现是"列表滚动
     *   位置莫名回到顶部"（`RenderScrollView` 换了新盒，offset 归零）。
     *   面板**真的**在意的只有：三态三个值 + 受控值 + 选项表的 (value,label) 序列。
     */
    #[\Override]
    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);

        $signature = $this->contentSignature();
        if ($signature === $this->signature) {
            return;
        }

        $this->signature = $signature;
        $this->signal->notifyChanged();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Button::make($this->label())
            ->variant(ButtonVariant::Default)
            ->width($this->select()->triggerWidthValue())
            ->overlayPanel($this->panelFactory())
            // ★ `keyboard()` 声明在外层：焦点落在这个 `Button` 上，靠父链解析命中
            //   （与 `ComboBox` 同构，见 `FocusManager::dispatchKey()`）。
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
     *
     * ★ 这里是 `onOpen` 的**唯一**触发点：工厂在"要开面板"时被调一次，不在
     *   `build()` 里（那会在每次应用重建时都发一次请求）。
     */
    private function panelFactory(): \Closure
    {
        return function (\Closure $dismiss): Widget {
            $this->dismiss = $dismiss;

            // 打开时高亮 = 当前值的下标（没匹配 ⇒ 第一项）
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
     *
     * ★ 优先级：`loading` > `errorText` > 空表 `emptyText` > 选项行。
     *   加载中盖过错误是刻意的：重试时两个都真，用户该看到的是"正在加载"。
     */
    private function panelContent(): Widget
    {
        $select = $this->select();
        $rows = [];

        if ($select->loadingValue()) {
            $rows[] = Text::make('正在加载…')->caption();
        } elseif ($select->errorTextValue() !== '') {
            $rows[] = Text::make($select->errorTextValue())->caption();
        } else {
            $options = $select->optionsValue();

            if ($options === []) {
                $rows[] = Text::make($select->emptyTextValue())->caption();
            } else {
                foreach ($options as $index => $option) {
                    // ★ 选中项用 Primary 底（强调色），方向键光标也用它 —— 两者同形
                    //   是刻意的：键盘用户看到的"我要选这个"与鼠标用户的"已选"一致。
                    //   不用 "✓" 前缀：内置字模子集里没有 U+2713。
                    $rows[] = Button::make($option->label)
                        ->variant($index === $this->highlight ? ButtonVariant::Primary : ButtonVariant::Flat)
                        ->onPressed(function () use ($option): void {
                            $this->commit($option);
                        });
                }
            }
        }

        // ★ `cross(Stretch)`：面板给内容的是**有界宽**（`RenderPopupPanel` 先量
        //   统一宽再 tight 布局）⇒ 整行可点、高亮横贯。
        return Column::make()
            ->cross(FlexAlign::Stretch)
            ->children($rows)
            // ★ 焦点在行上（Tab 进来）时按键转发到同一套导航
            ->keyboard(function (KeyEvent $event): bool {
                return $this->handleNav($event);
            });
    }

    /**
     * 键盘缝（焦点在**触发框**上时）。
     *
     * ★ 面板关着 ⇒ **一律返回 `false`**（让位给框架）：`dispatchKey()` 跑在
     *   `activateFocused()` 之前，返回 `true` 就把"Enter/Space 开面板"那条路
     *   堵死了（现象：鼠标点得开、键盘打不开）。
     * ★ 面板开着 ⇒ 上下键移高亮、Enter 提交；**ESC 仍返回 `false`**，让浮层的
     *   `dismissOnEsc` 去关（自己关会与框架的关闭路径重复）。
     */
    private function keyHandler(): \Closure
    {
        return function (KeyEvent $event): bool {
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
     *
     * ★ 这里**故意不调 `setState()`**：高亮只影响面板那棵树，主树（触发框上的字）
     *   一个字都不会变。标脏主树等于每按一次方向键重排整个页面。
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
     *
     * ★ 顺序是刻意的（2026-10-24 改）：反过来的话，应用的 `setState` 会先让面板
     *   带着新值重建一帧、随后才被收掉 —— 表现是"点一项，列表闪一下"。先收面板
     *   则 `$this->dismiss = null` 已经落地，`notifyChanged()` 即使被触发也没人听
     *   （`OptionPanelState::dispose()` 已退订）。
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
     *
     * ★ 高亮不用复位：下次打开时 `panelFactory()` 会按当前值重算。
     */
    private function onPanelClosed(): void
    {
        $this->dismiss = null;
    }

    /**
     * 面板**真正**在意的东西的签名（见 `didUpdateWidget()` 的 ★）。
     *
     * ★ 用 `list<string>` + `implode` 而不是循环里 `.=`：后者会被 lint 的
     *   `str-concat-loop` 判错（循环体内 `.=` 是 error）。
     */
    private function contentSignature(): string
    {
        $select = $this->select();
        $parts = [
            $select->loadingValue() ? 'L1' : 'L0',
            $select->errorTextValue(),
            $select->emptyTextValue(),
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
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            \Drift\Errors::report('SelectState 只能配 Select（框架内部不变量被破坏）');
        }

        return $widget;
    }
}

/**
 * `Select` 面板的广播端 —— **专门为了跨树**（主树是触发框、浮层是另一棵树）。
 *
 * ★ 为什么不是 `Cell`：`Cell::setIfChanged()` 只标脏**它自己的属主** `State`
 *   （住同一个 State 里 ⇒ 只能影响那一棵子树）。面板在另一棵树，要的是"广播"。
 * ★ 它不承载业务数据（不是 `Store`）：只表达"面板该重读了"，且**不出现在公共
 *   API 上**（`SelectState::$signal` 是私有的）。
 *
 * @internal
 */
final class SelectPanelSignal extends Subject
{
}
