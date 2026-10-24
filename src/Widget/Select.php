<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Input\KeyEvent;
use Drift\Layout\FlexAlign;

/**
 * 下拉选择（W8-2 第五件，2026-10-23）—— 受控的 one-of-N。
 *
 * ```php
 * Select::make([
 *     Option::make('北京市', '110000'),
 *     Option::make('上海市', '310000'),
 * ])->value($this->city)                       // 受控：值住应用的 State
 *   ->onChanged(fn(string $v) => $this->setState(fn() => $this->city = $v));
 *
 * // 短形式（2026-10-24）：键 = 值、值 = 显示名。DB / HTTP 拿来就是 `id => name`
 * // 时不用再手写一遍 `array_map`。键是数字也照收（内部 `(string)` 收口）。
 * Select::make(['110000' => '北京市', '310000' => '上海市'])->value($this->city);
 * ```
 *
 * ## 形状从哪来（为什么是组合，不是新渲染盒）
 *
 * `Select` 是 `StatelessWidget`，`build()` 交出一个 **`Button`（`Default` 变体）
 * + 三个声明**：
 *
 * | 声明 | 谁消费 | 管什么 |
 * |---|---|---|
 * | `overlayPanel($factory)` | `Application::activateFocusedWidget()` | 激活时浮出选项面板 |
 * | `keyboard($handler)` | `FocusManager::dispatchKey()` | ESC 之外一律让位（见下） |
 * | `variant(Default)` + `width()` | `RenderButton` | 外观：有底有边的"选择框" |
 *
 * ★★ **刻意不写 `RenderSelect`**：新渲染盒是本仓最贵的改动面（每一条都出过真机
 *   bug：内禀失真、offset 双重平移、AOT 常量折叠）。而"一个有边框的、点了浮面板
 *   的按钮"用现成的 `Button` **完全表达得出来** —— 焦点、激活、光标、语义、命中
 *   全白送。代价只有一条（写进能力边界）：没有下拉箭头。
 *
 * ★ 外观实测（2026-10-23 真机）：`Button` 被 `width()` 定宽后标签**靠左**（与
 *   示例台侧栏的导航按钮同一条路径）—— 正好是选择框该有的样子，不需要额外对齐。
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
 * - **方向键换值**：没有。它需要"面板随控件重建"（现在面板只在**开的那一刻**建
 *   一次，选中高亮会停在旧值上 ⇒ 宁可不做，也不做一个会自相矛盾的）。补齐的前提
 *   写在 `Widget::overlayPanel()` 的注释里（给 `OverlayEntry` 加字段，别加闭包形参）。
 * - **下拉箭头**：没有。内置字模缺 164 个字符（含 `▾`）⇒ 要画得用 `CustomPaint`，
 *   而 `Button` 的标签位只收字符串 ⇒ 得再包一层 `Row`，那会多出一个焦点目标。
 *   v1 用"有底有边的框 + 占位文字"表达可点。
 * - **可编辑（输入过滤）**：不在本类 —— 那是 `ComboBox`（2026-10-24 已建：
 *   `TextField` + 过滤浮层，与 `Select` 同一条"不写新渲染盒"的裁决）。
 * - **远程数据三态**（正在加载 / 空 / 出错）：没有 —— 面板只会画选项行。现在能做
 *   的是应用自己在 `overlayPanel` 工厂里触发取数（工厂在**开面板那一刻**被调），
 *   但"正在加载"这一行**无处可放**（空数组只会得到一个空面板，与"没加载完"分不开）。
 *   补的时候加 `loading()` / `emptyText()` 两个受控属性就够，**不需要新机制**
 *   —— 取数走已有的 `Job` + `TaskHost`，`onDone` 里 `setState` 换选项表。
 * - **滚动**：有了 —— 面板内容套 `ScrollView` + `PopupPanel::maxHeight(260)`，
 *   **贴着内容还是滚动由实测自然高决定**（不是按条目数猜）。超过一屏仍建议应用
 *   自己做搜索 / 分页（几百项靠滚屏找仍然慢，且没有虚拟化）。
 * - **多选**：没有 —— 多选是 `list<string>` 的受控值，形状不同。
 *
 * @api
 */
final class Select extends StatelessWidget
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

    /**
     * @param list<Option>|array<array-key, string> $options 选项表。两种形态都过
     *        `Option::normalize()`（映射形态 = 键是值、值是显示名），空表 ⇒ 面板
     *        里一行都没有，不炸。
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

    /** 没有匹配项时显示的提示文字 */
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

    public function build(BuildContext $context): Widget
    {
        $index = $this->selectedIndex();
        $label = $index < 0 ? $this->placeholder : $this->labelAt($index);

        return Button::make($label)
            ->variant(ButtonVariant::Default)
            ->width($this->width)
            ->overlayPanel($this->panelFactory())
            ->keyboard($this->keyHandler());
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
     */
    private function labelAt(int $index): string
    {
        if ($index < 0 || $index >= \count($this->options)) {
            return '';
        }

        return $this->options[$index]->label;
    }

    /**
     * 面板工厂 —— `Application` 开面板时调用，形参是它注入的 dismiss 闭包。
     *
     * ★ 面板在**开的那一刻**建一次（因此选中高亮 = 打开时的值）。这就是"方向键
     *   换值"不能做的原因，见类头的能力边界。
     */
    private function panelFactory(): \Closure
    {
        return function (\Closure $dismiss): Widget {
            $rows = [];

            foreach ($this->options as $option) {
                // ★ 选中项用 Primary 底（强调色），不用 "✓" 前缀：内置字模子集里
                //   没有 U+2713（"画得出但宽度是猜的"，soft 后端直接画不出）。
                $variant = $option->value === $this->value
                    ? ButtonVariant::Primary
                    : ButtonVariant::Flat;

                $rows[] = Button::make($option->label)
                    ->variant($variant)
                    ->onPressed(function () use ($option, $dismiss): void {
                        $this->emit($option->value);
                        $dismiss();
                    });
            }

            // ★ `cross(Stretch)`：面板给内容的是**有界宽**（`RenderPopupPanel` 先量
            //   统一宽再 tight 布局）⇒ 整行可点、高亮横贯。这与 `ShowcaseRoot` 里
            //   "宽度已被 SizedBox 定死才敢 Stretch" 是同一条前提。
            // ★ **必须套 `ScrollView`**：`PopupPanel::maxHeight()` 的"超高就滚"是靠
            //   内容自己是滚动视口实现的（它只负责给上限，不会替内容滚）。
            return PopupPanel::make(
                ScrollView::make(
                    Column::make()->cross(FlexAlign::Stretch)->children($rows)
                )
            )
                ->minWidth(self::PANEL_MIN_WIDTH)
                ->maxHeight(self::PANEL_MAX_HEIGHT)
                ->padding(EdgeInsets::symmetric(4.0, 4.0))
                ->elevation(4.0);
        };
    }

    /**
     * 键盘缝：**一律返回 `false`**（让位给框架）。
     *
     * ★ 不是"没实现" —— 是**刻意让位**：`dispatchKey()` 跑在 `activateFocused()`
     *   之前，返回 `false` 才能让 Enter/Space 走到 `activateFocusedWidget()` 把面板
     *   开起来，让 ESC 走到浮层的 `dismissOnEsc` 把面板收掉。返回 `true` 会把这两条
     *   路都堵死（现象：键盘完全打不开下拉，而鼠标正常）。
     *
     * ★ 那为什么还要声明它？**留一条可读的痕迹**：下一个人想加方向键换值时，
     *   这里就是入口（前提是"面板随控件重建"，见类头）。
     * ★ 形参**必须声明出来**（哪怕不用）：tpc 生成的 C++ 侧闭包 arity 没有系统
     *   验证过，`function (): bool` 被 `$handler($event)` 调用属于未验证形态。
     */
    private function keyHandler(): \Closure
    {
        return static function (KeyEvent $event): bool {
            return false;
        };
    }

    private function emit(string $value): void
    {
        if ($this->onChanged === null) {
            return;
        }

        ($this->onChanged)($value);
    }
}
