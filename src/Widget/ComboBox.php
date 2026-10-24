<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\FlexAlign;
use Drift\State\Subject;

/**
 * 可编辑下拉（W8-2 第六件，2026-10-24）—— 输入过滤 + one-of-N。
 *
 * ```php
 * ComboBox::make([
 *     Option::make('北京市', '110000'),
 *     Option::make('上海市', '310000'),
 * ])->value($this->city)                        // 受控：值住应用的 State
 *   ->onChanged(fn(string $v) => $this->setState(fn() => $this->city = $v));
 * ```
 *
 * 与 `Select` 的差别只有一条：**框里能打字**。打字即过滤候选，回车取第一项。
 *
 * ## 形状从哪来（为什么是组合，不是新渲染盒）
 *
 * 与 `Select` 同一条裁决：新渲染盒是本仓最贵的改动面（内禀失真 / offset 双重
 * 平移 / AOT 常量折叠，每条都出过真机 bug）。这里交出的是一棵**现成控件**的组合：
 *
 * ```text
 * Column(keyboard)            ← 拦 Enter / Esc（`keyboard()` 沿父链解析，见下）
 *   └ SizedBox(width)
 *       └ TextField(overlayPanel)   ← 焦点在这上面；打字 = 过滤词
 * ```
 *
 * | 声明 | 谁消费 | 管什么 |
 * |---|---|---|
 * | `overlayPanel($factory)` | `Application::activateFocusedWidget()` | 激活（点击 / Enter）时浮出候选面板 |
 * | `keyboard($handler)` | `FocusManager::dispatchKey()` | Enter 取第一项 / Esc 收起；其余让位 |
 * | `overlaySignature($fn)` | `Application::syncPanelSignatures()` | 受控值（选项表 / 三态）变了 ⇒ 面板重读 |
 *
 * ★★ **`keyboard()` 声明在 `Column` 上、不声明在 `TextField` 上**：焦点落在
 *   `TextField` 上，`keyboard()` 沿**父链**找最近的声明者（2026-10-24 修，`c659d0a`）。
 *   放在外层是"包裹式控件"的正确形状 —— 将来若在框右边加一个下拉箭头按钮
 *   （它也会是焦点目标），外层声明照样收得到键，不必再改一处。
 *
 * ## 面板为什么会"跟着打字变"（本控件最不显然的一处）
 *
 * 浮层是**第二棵根渲染树**（O-20 方案 A）⇒ 主树重建**不会**带动面板重建
 * （`OverlayEntry::$panel` 的字段注释即此意："是 Widget 不是 Closure：
 * StatefulWidget 自己订阅状态自己重建"）。
 *
 * 所以查询词住在一个**广播端**（`ComboBoxQuery extends Subject`，本文件内），
 * 面板的 `State` 在 `initState()` 里 `watch()` 它 ⇒ 打字 → `notifyChanged()`
 * → 面板 `markDirty()` → 帧末 `placeOverlays()` 的 settle 段重建面板。
 * 本控件是**第一个把 `StatefulWidget` 放进浮层树**的消费者，这条链路因此
 * 在 smoke 里有一条专门的断言（只靠读代码看不出来它通不通）。
 *
 * ★ 为什么用 `Subject` 而不是 `Cell`：`Cell` 只标脏**它自己的属主**（一个
 *   `State`），跨不了树。为什么不用"面板登记回调"的手写注册表：那要在
 *   `dispose()` 里配对注销，漏一次就是永久泄漏 —— 而 `watch()` 的订阅袋
 *   （`State::dropSubscriptions()`）已经把这件事故结构性地做掉了。
 *
 * ## 字段上显示什么（`$editing` 这个开关的由来）
 *
 * | 状态 | 字段显示 | 过滤词 | 怎么进来 |
 * |---|---|---|---|
 * | 显示态（`editing = false`） | 已选值的标签（没选中 ⇒ 空 ⇒ placeholder） | — | 初始 / **面板关闭** |
 * | 编辑态（`editing = true`） | `$query`（可以是空串） | `$query` | **打开面板** / 打字 |
 *
 * 于是交互是：**点一下 ⇒ 框变搜索框、列出全部 ⇒ 打字过滤 ⇒ Enter / 点一项选中 ⇒
 * 框回到新标签**。中途点面板外或 Esc ⇒ 框回到**旧**标签（"取消"语义，丢弃打了一半的词）。
 *
 * ★ 为什么要这个开关、不直接"字段文本就是过滤词"：选中之后字段上留着标签，
 *   若拿它当过滤词，再打开面板只会剩一行 —— 看起来像"选项没了"。
 * ★ 反过来，**打开时清空**也是必须的：不清空的话打字会**追加在标签后面**
 *   （选中"北京市"再打开、打"上"⇒ 字段成了"北京市上"、一项都匹配不到）。
 * ★ 但清空**只在字段还显示标签时**做：用户可能在面板关着时就打了字（Tab 进来
 *   直接打 / Esc 之后接着改），那时点开面板不该把他的字吃掉。
 *
 * ## 远程搜索（`onSearch` + `status`，2026-10-24）
 *
 * 本地过滤只对"候选表就在手上"成立。远程搜索是另一套：**打字 ⇒ 问服务端 ⇒ 换选项表**。
 *
 * ```php
 * ComboBox::make($this->results)
 *     ->status($this->status)                      // OptionStatus：取数中 / 失败 / 空 / 正常
 *     ->onOpen(fn() => $this->search(''))          // 面板打开那一刻（可选：加载初始列表）
 *     ->onSearch(fn(string $q) => $this->search($q))
 *     ->onChanged(fn(string $v) => $this->setState(fn() => $this->city = $v));
 * ```
 *
 * **四条决定**（每条都对应一处会静默出错的写法）：
 *
 * 1. ★★ **`onSearch` 挂在"用户输入"上，不挂在"查询词变了"上**。查询词有三个写入点
 *    （打字 / 开面板时清空 / 关面板时复位），后两个是**控件自己的状态管理**。接在
 *    写入点上 ⇒ 每次开关面板都发一次"搜索空串"请求（刚选完又去取一遍）。
 * 2. ★★ **声明了 `onSearch` ⇒ 本地过滤让位**（`matches()` 原样返回）。服务端可能按
 *    拼音、按 `value`、按首字母匹配，本地再按标签子串筛一遍是**二次收缩** ——
 *    现象是"服务端返回 8 条、面板只显示 2 条"，而它看起来像服务端的问题。
 *    判据是"有没有声明回调"而不是另加一个开关：声明了就意味着选项表是权威。
 * 3. ★★ **Enter 跟着三态闭**。加载中 / 失败时面板里没有选项行，但应用很可能**保留
 *    上一批 `options`**（加载时不清旧数据以免闪烁）⇒ 不加这条闸，Enter 会选中一个
 *    用户根本没看见的项。与既有的"无匹配项时吃掉 Enter"同一条分支。
 * 4. **去抖不做**（v1）。控件侧做去抖需要定时器，而定时器只有 `Application::after()`
 *    有（§4.3 禁 `Widget → App`）；造一条"控件声明延时、框架执行"的新缝代价不小，
 *    收益只是少发几个请求。契约定为：**查询词一变就回调一次，且带上当时的词** ——
 *    应用自己比对词来丢弃过期响应（`Job` + 请求序号）。这不是省事，是分工：控件
 *    报告时机与事实，调度归应用（与 `Select::onOpen` 同一条线）。
 *
 * ★ 远程模式下 `options` 通常从空表起步，而空表 + `ready()` 会画"无匹配项" ——
 *   所以应用一般把初始状态设成 `OptionStatus::empty('输入关键词搜索')`。
 *
 * ## 受控
 *
 * `value()` 由应用给，选中只回调 `onChanged(新值)`（与 `Select` / `Checkbox` /
 * `Radio` 同纪律）。控件不自持"选了谁"。
 *
 * ## 能力边界（v1 刻意不做，别当缺陷报）
 *
 * - **方向键换高亮**：没有。**不是"忘了"** —— 焦点只能由框架移动（Tab / Shift+Tab
 *   是 `FocusManager::dispatchKey()` 自己的键），控件侧的闭包拿不到 `FocusManager`
 *   （§4.3 禁 `Widget → App`）⇒ 做不出"↓ 把高亮/焦点挪到下一行"。可行的两条路
 *   都属框架级改动（给方向键一条焦点链分支 / 给控件一条 `revealElement()` 缝），
 *   各自是独立一步。**Enter 取"过滤后的第一项"**是 v1 的键盘模型（type-ahead），
 *   它与"第一项"这个说法自洽 —— 所以面板里**不标已选项**（标了会让"回车会选谁"
 *   变含糊）。
 * - **面板里打字**：焦点在面板行上时，`Column` 的 `keyboard()` 把按键**转发给
 *   输入框的编辑器**（`forwardKey()`）⇒ 退格 / 方向键 / 选词都照旧。代价：面板里
 *   将来若放一个真正的 `TextField`，这条转发会跟它打架（届时改成"只在焦点不在
 *   输入框时转发"）。
 * - **下拉箭头**：没有。内置字模缺 164 个字符（含 `▾`），理由同 `Select`。
 * - **自定义过滤函数**：没有。v1 是"标签的大小写不敏感子串匹配"，够覆盖
 *   "选城市 / 选用户"这两类真实场景。要按拼音 / 按 `value` 匹配就走 `onSearch()`
 *   （把选项表整个换掉，本地过滤随之让位），不要指望在这里插一个 `filter()`。
 * - **打字时自动弹出面板**：没有。开面板只有"点击 / Enter"两条路（框架的
 *   `activateFocusedWidget()` 才拿得到浮层栈，控件侧够不着）。所以"Tab 进来直接
 *   打字"时看不到候选，得再点一下或按 Enter —— 别当缺陷报。
 * - **去抖**：没有，见上面"远程搜索"第 4 条。
 * - **多选**：没有 —— 形状是 `list<string>` 受控值。
 *
 * @api
 */
final class ComboBox extends StatefulWidget
{
    /** 面板内容最小宽（逻辑像素）—— 比这更窄的一列候选点起来太挤 */
    public const PANEL_MIN_WIDTH = 180.0;

    /** 面板内容高度上限（逻辑像素）；"超高才滚"由 `RenderPopupPanel` 按实测自然高决定 */
    public const PANEL_MAX_HEIGHT = 260.0;

    /** 输入框默认宽（逻辑像素） */
    public const DEFAULT_WIDTH = 200.0;

    /** @var list<Option> */
    private readonly array $options;

    /** 当前值（受控）。**不匹配任何 `Option::$value` ⇒ 显示 placeholder** */
    private string $value = '';

    private string $placeholder = '请选择';

    private float $width = self::DEFAULT_WIDTH;

    private ?\Closure $onChanged = null;

    /**
     * 选项表的就绪度（三态，`OptionStatus`）—— 与 `Select` 共用同一个值类型。
     *
     * ★ 远程搜索**必然**有加载中 / 失败 / 空三种非正常态，而它们与选项行是**互斥**的
     *   （见 `OptionStatus` 类头：互斥之后就不需要"同时成立时显示谁"那条规则）。
     */
    private OptionStatus $status;

    /** 面板**打开那一刻**触发（应用在这里发起初始取数） */
    private ?\Closure $onOpen = null;

    /**
     * **用户改了搜索词**时触发（`TextField::onChanged` 那条路）——
     * ★ 控件的内部复位（开面板清空 / 关面板复位）**不**触发它，见类头"四条决定"第 1 条。
     */
    private ?\Closure $onSearch = null;

    /**
     * @param list<Option>|array<array-key, string> $options 选项表。两种形态都过
     *        `Option::normalize()`（映射形态 = 键是值、值是显示名），空表 ⇒ 面板
     *        里只有"无匹配项"一行，不炸。
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

    /** 输入框宽度（面板不受它影响，面板按内容 + `PANEL_MIN_WIDTH` 自尺寸） */
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
     * 选项表的就绪度（受控）—— 取数中 / 失败 / 空表 / 正常，**四者互斥**。
     *
     * ★ 与 `Select::status()` 同一个值类型、同一套语义（见 `OptionStatus` 类头）。
     */
    public function status(OptionStatus $status): static
    {
        $this->mutate();
        $this->status = $status;

        return $this;
    }

    /**
     * 面板**打开那一刻**触发一次（不是每次应用重建）。
     *
     * ★ 唯一触发点是面板工厂 —— 框架只在"要开面板"时调它一次。
     * ★ 远程搜索的常见用法是在这里取初始列表（`onSearch('')` 那种"搜索空串"）。
     */
    public function onOpen(callable $handler): static
    {
        $this->mutate();
        $this->onOpen = $handler(...);

        return $this;
    }

    /**
     * **用户改了搜索词** ⇒ 回调收到新词。
     *
     * ★★ 声明了它 ⇒ **本地过滤让位**（选项表是服务端的搜索结果，权威）。
     * ★★ 控件**不做去抖**，每次改动回调一次；应用自己比对词丢弃过期响应
     *   （见类头"远程搜索"第 4 条）。
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

    /** @internal 非 `null` ⇒ 远程搜索模式（本地过滤让位，见类头第 2 条） */
    public function searchHandler(): ?\Closure
    {
        return $this->onSearch;
    }
}

/**
 * `ComboBox` 的纯 UI 态（§11.5 / §12.3）。
 *
 * ★ **它同时是"跨树广播"的发起端**（`ComboBoxQuery`）：输入框在主树、候选面板在
 *   浮层树，两者唯一的合法联系就是这个 `Subject` —— 详见 `ComboBox` 类头。
 *
 * @api
 */
final class ComboBoxState extends State
{
    /** @internal 查询词（= 字段上打的字，也是过滤词） */
    private ComboBoxQuery $query;

    /**
     * @internal 字段此刻是"搜索框"还是"已选值的显示框"。
     *
     * - `true`  ⇒ 字段显示 `$query`（可以是空串 ⇒ 显示 placeholder），过滤词 = `$query`；
     * - `false` ⇒ 字段显示已选值的标签。
     *
     * 只有两个切换点：**打开面板**（⇒ `true`，让"点一下看全部"成立）与
     * **面板关闭**（⇒ `false`，字段回到标签）。
     */
    private bool $editing = false;

    /**
     * @internal 面板的 dismiss（`overlayPanel` 工厂的形参，开面板时由框架注入）。
     *   **`null` ⇔ 面板关着** —— 它是"开没开"的唯一判据，不另设一个布尔
     *   （两个字段必然有一个会陈旧）。复位点是面板 `State::dispose()`：
     *   点面板外 / owner 清扫 / Esc 三条关闭路径都会走到那里，而只有 Esc 走我们的
     *   `cancel()` ⇒ 靠 `cancel()` 复位会漏掉另外两条。
     */
    private ?\Closure $dismiss = null;

    /**
     * @internal 见过的 `(value ⇒ label)` 配对 —— **只为远程搜索而存在**。
     *
     * ★ 为什么需要：`fieldText()` 靠"在当前 `options` 里找 `value`"得到标签。远程
     *   搜索会把选项表整个换掉 ⇒ 选中"北京市"之后搜"上"，选项表里已经没有 110000 ⇒
     *   框变成**空白**（用户看到的是"值丢了"）。这里记住最后一次真见过的标签，表里
     *   找不到时用它兜底。
     * ★ 两个字段而不是 `array<string,string>` 缓存：只需要当前值这一个（缓存全量
     *   会随搜索无限增长，且改名后永远返回旧标签）。换值 ⇒ 下一次渲染就更新。
     */
    private string $knownValue = '';

    /** @internal 见 `$knownValue` */
    private string $knownLabel = '';

    #[\Override]
    public function initState(): void
    {
        // ★ 订阅端在**另一棵树**（面板），所以这个 Subject 不是"业务数据广播"，
        //   而是本控件内部的一根线 —— 它不出现在公共 API 上（`querySubject()`
        //   的返回类型是 `Subject`）。
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
            // ★★ **受控值（选项表）⇒ 面板重读由框架负责**（`Widget::overlaySignature()`，
            //   2026-10-24）。★ 2026-10-24 之前这里什么都没有 ⇒ 面板开着时应用换掉
            //   `options`（远程取数就是这个形状）**面板纹丝不动**，且不报错。
            ->overlaySignature(fn (): string => $this->panelSignature());

        // ★ `keyboard()` 在**外层**：焦点落在里面的 `TextField` 上，靠父链解析命中
        //   （见 `ComboBox` 类头的 ★★）。
        return Column::make()
            ->children([
                SizedBox::make($field)->width($this->combo()->triggerWidthValue()),
            ])
            ->keyboard($this->keyHandler());
    }

    // ── 面板侧要用的（`@internal`：只给同文件的 `ComboBoxPanelState` 调）──

    /**
     * @internal 面板订阅它 ⇒ 打字后自己重建（跨树的唯一一根线）
     */
    public function querySubject(): Subject
    {
        return $this->query;
    }

    /**
     * @internal 面板内容 —— `OptionPanel` **每次重建都会调**它（所以读的是当前值，
     *   不是建面板那一刻的快照）。
     *
     * ★ 全部 `Flat`、**不标已选项**：Enter 取的是"第一项"，标了已选项会让
     *   "回车会选谁"变含糊（见 `ComboBox` 类头的能力边界）。
     */
    public function panelContent(): Widget
    {
        $rows = [];
        $status = $this->combo()->statusValue();
        $statusRow = $status->row();

        // ★ 三态与选项行**互斥**（`OptionStatus::showsOptions()`）⇒ 这里没有优先级
        //   判断链：状态不 ready ⇒ 只画那一行；ready ⇒ 画选项（空表画内置兜底）。
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

        // ★ `cross(Stretch)`：面板给内容的是**有界宽**（`RenderPopupPanel` 先量
        //   统一宽再 tight 布局）⇒ 整行可点、高亮横贯。
        return Column::make()
            ->cross(FlexAlign::Stretch)
            ->children($rows)
            // ★ 焦点在行上时的按键转发（见 `forwardKey()`）：这条声明只有**焦点在
            //   这一支**时才会命中，不影响输入框那一侧。
            ->keyboard(function (KeyEvent $event): bool {
                return $this->forwardKey($event);
            });
    }

    /**
     * 面板**受控**部分的签名 —— 交给框架（见 `build()` 里的 `overlaySignature()`）。
     *
     * ★ 查询词**刻意不进签名**：它是控件私有态，走 `$this->query` 那条广播。
     *   两样都算进来会变成"每敲一键重建两次"（Subject 一次 + 框架签名一次）。
     * ★ 只看 `(value,label)` 序列，不看对象身份 —— 应用每次重建都造新 `Option`。
     * ★ 三态那一段由 `OptionStatus` 自己给 —— 与 `Select` 共用一处实现。
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
     * @internal 当前候选（本地模式：按查询词过滤；远程模式：**原样返回**）。
     *
     * ★★ 远程模式下**不能再筛**：服务端可能按拼音 / 按 `value` / 按首字母匹配，本地
     *   按标签子串再筛一遍是二次收缩（"服务端返回 8 条、面板只显示 2 条"，而它看
     *   起来像服务端的问题）。判据是"应用声明了 `onSearch`"—— 声明了就意味着选项表
     *   是权威，不是一个可以关的开关（两个旋钮 = 又能拼出含糊状态）。
     *
     * ★ 本地匹配规则：标签的**大小写不敏感子串**。用 `mb_strtoupper()` 而不是
     *   `mb_strtolower()` / `stripos()`：本仓 AOT 侧只有**已在 `src/` 用过的**
     *   字符串函数算"验证过"（`mb_strtoupper` 在 `KeyEvent::character()` 里），
     *   其余没跑过真机构建 —— 不值得为一个大小写折叠去赌运行时。
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
     * @internal 焦点在**面板行**上时的按键转发（`ComboBoxPanelState` 的 `keyboard()`）。
     *
     * ★ 为什么要转发：Tab 进面板选行之后，打字若没人接就成了死端（现象是
     *   "Tab 进了列表，然后键盘全哑"）。转给输入框的编辑器 ⇒ 退格 / 方向键 /
     *   Ctrl+A 全都照旧，不必在这里重写一遍编辑动作。
     * ★ 返回 `false` 的键（Enter）继续往下走：焦点在行上 ⇒ `activateFocused()`
     *   会按那一行（`Button` 的既有路径），选中语义天然一致。
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
     * @internal 面板被回收 —— **三条关闭路径的唯一汇合点**（Esc / 选中 / 点面板外，
     *   以及 owner 清扫）。
     *
     * ★ 复位只放在这里：`removeOverlay()` 会 `unmount()` 面板树 ⇒ 面板 `State` 的
     *   `dispose()` 一定跑到。若把复位写在 `closePanel()` 里，就漏掉"点面板外"
     *   与"owner 清扫"两条（它们不经过我们任何代码）。
     * ★ 复位 = 字段回到"已选标签"：点外面关掉时**丢弃**用户打的过滤词。这是
     *   "点外面 = 取消"的标准语义 —— 留着半截过滤词在框里，看起来像值被改坏了。
     */
    public function onPanelClosed(): void
    {
        $this->dismiss = null;
        $this->editing = false;
        $this->query->setText('');
        $this->markDirty();
    }

    // ── 内部 ──────────────────────────────────────────────────────────

    /** 字段此刻该显示什么（见类头"字段上显示什么"） */
    private function fieldText(): string
    {
        if ($this->editing) {
            return $this->query->text();
        }

        $value = $this->combo()->valueText();
        foreach ($this->combo()->optionsValue() as $option) {
            if ($option->value === $value) {
                // ★ 顺手记住"这个值显示成什么"（远程搜索换表之后靠它兜底，见 $knownValue）
                $this->knownValue = $value;
                $this->knownLabel = $option->label;

                return $option->label;
            }
        }

        // ★★ 当前值**不在**选项表里。远程搜索必然走到这里：选项表是"这次搜索的结果"，
        //   已选值通常不在其中。此时若返回空串，框会变空白 ⇒ 用户以为值丢了。
        //   返回最后一次真见过的标签（且必须同值 —— 换了值就不该拿旧标签冒充）。
        if ($value !== '' && $value === $this->knownValue) {
            return $this->knownLabel;
        }

        return '';   // 没见过 ⇒ 空串，`TextField` 自己显示 placeholder
    }

    /** 用户打了一个字（`TextFieldState::apply()` 的回调） */
    private function onInput(string $text): void
    {
        $this->editing = true;
        // ★ **不 `markDirty()`**：输入框的编辑器已经在自己身上改完并标脏了，
        //   字段显示不需要本 State 再重建一遍；要变的只有**另一棵树**里的面板，
        //   而那是 `notifyChanged()` 的事。少一次全子树重建。
        $this->query->setText($text);

        // ★★ `onSearch` 只挂在这里 —— 这是"用户改了搜索词"的**唯一**入口。
        //   查询词另外两个写入点（开面板清空 / 关面板复位）是控件自己的状态管理，
        //   接在那里会变成"每次开关面板都发一次搜索空串请求"。见类头第 1 条。
        $handler = $this->combo()->searchHandler();
        if ($handler !== null) {
            $handler($text);
        }
    }

    /**
     * 面板工厂 —— `Application` 开面板时调用，形参是它注入的 dismiss 闭包。
     *
     * ★ 工厂里做两件"打开时该发生"的事：记下 dismiss（"开着"的判据）、
     *   把字段切成**搜索框**。**这两件都必须在这里做**：框架的
     *   `activateFocusedWidget()` 只负责"开"，不知道这个控件的打开语义。
     *
     * ★★ **只在"字段还显示标签"时清空**（`if (!$this->editing)`）：用户可能
     *   在面板关着的时候已经打了字（Tab 进来直接打 / Esc 之后接着改），
     *   那时点开面板若把词清掉，等于**吃掉他刚打的字**。
     *   反过来，"字段显示着标签时点开"必须清空 —— 否则打字会**追加在标签后面**
     *   （现象：选中"北京市"再点开、打"上"⇒ 字段成了"北京市上"、一项都匹配不到）。
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

            // ★ `onOpen` 的唯一触发点（与 `Select` 同形）：工厂在"要开面板"时被调一次。
            $open = $this->combo()->openHandler();
            if ($open !== null) {
                $open();
            }

            return OptionPanel::make(
                $this->query,
                fn (): Widget => $this->panelContent(),
                ComboBox::PANEL_MIN_WIDTH,
                ComboBox::PANEL_MAX_HEIGHT,
                // ★ 面板被回收 ⇒ 复位"开着"的唯一判据（三条关闭路径都汇到这里）
                fn (): mixed => $this->onPanelClosed(),
            );
        };
    }

    /**
     * 键盘缝：Enter 取第一项 / Esc 收起，**其余一律让位**。
     *
     * ★ 面板关着时 Enter **必须让位**：`dispatchKey()` 跑在 `activateFocused()`
     *   之前，返回 `true` 就把"Enter 开面板"那条路堵死了（现象：鼠标点得开、
     *   键盘打不开）。
     * ★ 面板开着但**没有匹配项**时 Enter 吃掉（什么都不做）：让"无匹配项"这一行
     *   留在眼前；否则 Enter 会走到 `toggleWidgetPanel()` 把面板关掉，用户以为
     *   自己选中了什么。
     * ★ 面板开着但**三态不是 ready** 时同样吃掉 —— 理由见下面那段 ★★。
     * ★ Esc 在面板**关着**时也让位：`TextFieldState::handleKey()` 用它清选区
     *   （既有行为，别在这里抢）。
     */
    private function keyHandler(): \Closure
    {
        return function (KeyEvent $event): bool {
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

            // ★★ 三态不是 ready ⇒ 吃掉 Enter（什么都不做）。远程搜索里这条是必需的：
            //   加载中 / 失败时面板里没有选项行，但应用**很可能还留着上一批 options**
            //   （加载时不清旧数据以免闪烁）⇒ 不加闸，Enter 会选中一个用户根本没看见的项。
            //   与下面"无匹配项时吃掉 Enter"同一条分支：让那一行留在眼前，别让
            //   Enter 走到 `toggleWidgetPanel()` 把面板关掉。
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
     * 收面板（Esc / 选中 共用）—— **复位不在这里**，在 `onPanelClosed()`。
     *
     * ★ 为什么要那条兜底：`$dismiss()` 走的是 `closeWidgetPanel()`，它按 owner 找
     *   条目，找不到就什么都不做（面板可能已经被别的路径收掉了）⇒ 那时没人调
     *   `dispose()` ⇒ 字段会卡在"搜索框"状态。兜底显式补一次，代价是一次幂等调用。
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
     * 自己的子树里那个 `TextFieldState`（焦点在面板里时要转发按键给它）。
     *
     * ★ 不缓存：`Element::update()` 会换盒，但 `State` 对象在同一个位置是稳定的；
     *   不过面板可能开着而输入框所在子树刚被换过 —— 现取一次只花 O(子树)。
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
 * 查询词的广播端 —— **专门为了跨树**（主树输入、浮层树重建）。
 *
 * ★ 为什么不是一个普通的 `Cell`：`Cell::setIfChanged()` 只标脏它自己的属主
 *   `State`（住同一个 `State` 里，因此也只能影响那一棵子树）。面板在**另一棵树**，
 *   需要的是"广播"而不是"标脏自己" ⇒ 用 `Subject` 这一族。
 * ★ 它不承载业务数据（不是 `Store`）：只装一个 UI 态字符串，且**不出现在
 *   公共 API 上**（`ComboBoxState::querySubject()` 的返回类型是 `Subject`）。
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
