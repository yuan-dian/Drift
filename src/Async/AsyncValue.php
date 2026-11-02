<?php
declare(strict_types=1);

namespace Drift\Async;

/**
 * **异步状态的唯一形状**（N6，2026-10-30）—— 四态：`idle` / `loading` / `data` / `error`。
 *
 * ## 为什么要有它（不是"多一个枚举"）
 *
 * `Future` 只说"将来会有一个值"，而界面要说的是"**现在该画什么**"：
 * 还没开始 / 正在等 / 有数据了 / 失败了。这两个问题之间缺一个类型 ——
 * 缺了它，每个页面就自己拼一套（`apps/users` 的 `UserStore` 就是手写版：
 * `status` + `error` + `rows` 三个字段 + `applyPage()` 六步）。
 *
 * ★★★ **手写三态的三类静默故障**（都真实发生过，见 `UserStore` 注释）：
 *   ① 忘了 `notifyChanged()` ⇒ 数据到了但界面不动（白屏）；
 *   ② 忘了 `catch()` ⇒ 任务失败没人接 ⇒ `Future` 走 Fatal（进程退出码 3）；
 *   ③ 忘了取消旧请求 ⇒ 迟到的旧结果盖掉新数据，状态仍是 Ready，用户看不出。
 *   ⇒ 把"四态"收进一个**不可变值对象**之后，①②③ 变成 `AsyncCommand` 的
 *     一件事（`State\AsyncCommand`），而不是每个页面的三件事。
 *
 * ## 对齐成熟 GUI（逐个能对上，不是自创）
 *
 * | 本仓 | Flutter `AsyncSnapshot` | Kotlin `UiState` | React Query |
 * |---|---|---|---|
 * | `idle()`    | `none`        | `Idle`    | `status: 'idle'` |
 * | `loading()` | `waiting`     | `Loading` | `isLoading` |
 * | `data($v)`  | `hasData`     | `Success` | `data` |
 * | `error($m)` | `hasError`    | `Error`   | `error` |
 *
 * ## 为什么是 int 常量而不是 enum
 *
 * AOT 下"枚举 case 不存在也不报错"是踩过的坑（`MEMORY` 第 17 条）。本类型只有
 * 四态、没有消费者需要 `match` 穷尽检查 ⇒ 用 int 常量换"零 AOT 风险"。
 *
 * ## ★★ 载荷与状态是**两个维度**（2026-10-31）
 *
 * 上一版把"正在发生什么"和"手上有什么"压进了**一个互斥的四态**，于是
 * "**刷新时列表闪空**"这件事在类型里表达不出来 —— 刷新 ⇒ `loading` ⇒ 载荷被扔掉
 * ⇒ 界面只能画转圈。翻页、点刷新、错峰重试，用户都会看到整块内容消失又出现。
 *
 * 现在四个名字不变（`fold()` 仍然四个键），但**载荷可以跨状态存活**：
 *
 * | 态 | 有没有载荷 | 界面通常画 |
 * |---|---|---|
 * | `idle` | 没有 | "尚未加载" |
 * | `loading` **无**载荷 | 没有 | 整块转圈（首屏） |
 * | `loading` **有**载荷 | **有**（上一次的值） | 旧内容 + 刷新指示器 |
 * | `data` | 有 | 内容 |
 * | `error`（可带载荷） | 可有 | 错误条（+ 可能仍有旧内容） |
 *
 * ★ 这不是本仓自创：Flutter 的 `AsyncSnapshot` 本来就是
 *   `(ConnectionState, data, error)` **两维**；Riverpod 2 的
 *   `AsyncValue.copyWithPrevious()` 正是"新状态继承上一次的载荷"，并因此有了
 *   `isRefreshing` 这个说法；React Query / SWR 的叫法是 `keepPreviousData`。
 *   本条对应 Riverpod 的 `copyWithPrevious`（本仓 {@see self::carriedFrom()}）。
 *
 * ★ 仍然只有四个名字：**没有**"第五态 refreshing"。多一个状态就多一个 `fold()`
 *   的键，"漏写一支"的失败模式会跟着翻倍（见 `fold()` 的注释）。
 *
 * ## ★ 载荷怎么变，**不是本类的事**（2026-10-31）
 *
 * 本类只说"手上有什么"，不说"新结果该替代旧的还是接在后面"—— 那是**操作**的性质：
 * 翻页是替代（`AsyncCommand::run()`），加载更多是追加（`AsyncCommand::append()`）。
 * 同一个 `data($x)` 既可能是"第 2 页"，也可能是"整份重取的结果"，值自己判不出来。
 * ⇒ 想找"合并 / 追加"的语义请去 `AsyncCommand`，本类**故意**不提供
 *   `mergedWith()` 之类的组合子（理由见 `ASYNC-ARCH-2026-10-31.md` §10.2）。
 *
 * ## 不可变
 *
 * 值对象：`data()` / `error()` / `carriedFrom()` 返回**新实例**，不就地改。原因与
 * `Future` 同类 —— 同一个 `AsyncValue` 可能被多处持有（旧的一帧、正在画的子树），
 * 就地改会让"上一帧的引用"读到下一帧的值。
 *
 * @api
 */
final class AsyncValue
{
    private const IDLE = 0;
    private const LOADING = 1;
    private const DATA = 2;
    private const ERROR = 3;

    /** 四态之一（`self::IDLE` / `LOADING` / `DATA` / `ERROR`） */
    private int $state;

    /**
     * 载荷（"上一次拿到的值"）—— 四态都可能带着它。
     *
     * ★ 只有 `data` 态保证有；`loading` / `error` 有它当且仅当
     *   {@see self::carriedFrom()} 从上一个值继承了它。
     * ★ "有没有"必须单独一个 `bool`：载荷本身可以是 `null`
     *   （`AsyncValue::data(null)` 是合法值），拿 `$value !== null` 判"有没有"
     *   会把"值是 null"和"没有值"混成一种。
     */
    private mixed $value;

    /** 是否带载荷（见 `$value` 的注释） */
    private bool $hasValue;

    /** `error` 态的消息（其余三态恒 `''`）—— 用 string 而不是 `\Throwable`：
     *  AOT 对"可空对象字段"会读成 `php::Var`（`MEMORY` 第 41 条），而界面要的
     *  本来就是一句能画出来的话。需要堆栈的场合去 `Errors.log` 看。 */
    private string $message;

    /**
     * 进度（`0.0`~`1.0`）+ 有没有进度（2026-11-02）。
     *
     * ★ 与 `$value`/`$hasValue` **完全同一个手法**：`0.0` 是合法进度，
     *   拿它当"没有"会让"刚开始上传"和"这个任务不报进度"混成一种。
     *
     * ★ 进度是**第五个维度**，不是第五态：`fold()` 仍然四个键，`stateName()` 不变。
     *   多一个状态就多一个 `fold()` 的键，"漏写一支"的失败模式会跟着翻倍
     *   （见 `fold()` 的注释）—— 而进度不改变"该画什么大类"，只改变"loading 时
     *   画不画得出百分比"。
     *
     * ★ 谁喂它：`AsyncCommand` 在 `start()` 里订阅 `Future::onProgress()`，
     *   收到就 `emit($this->value->withProgress($f))`。完成/失败落地时
     *   `data()` / `error()` 造的新值**不带进度** —— 那时百分比已经没有意义。
     */
    private float $progress = 0.0;

    private bool $hasProgress = false;

    private function __construct(
        int $state,
        mixed $value,
        bool $hasValue,
        string $message,
        float $progress = 0.0,
        bool $hasProgress = false,
    ) {
        $this->state = $state;
        $this->value = $value;
        $this->hasValue = $hasValue;
        $this->message = $message;
        $this->progress = $progress;
        $this->hasProgress = $hasProgress;
    }

    /** 还没开始（页面初始、或"点一下才加载"的按钮态） */
    public static function idle(): self
    {
        return new self(self::IDLE, null, false, '');
    }

    /**
     * 正在等（请求已发出、还没回来）—— 界面通常画 `Spinner`。
     *
     * ★ 它**不带载荷**：这是"首屏"的形态。想保留上一次的值 ⇒
     *   `AsyncValue::loading()->carriedFrom($previous)`（`AsyncCommand` 就是这么
     *   发的，所以页面不必自己写）。
     */
    public static function loading(): self
    {
        return new self(self::LOADING, null, false, '');
    }

    /** 有数据了 */
    public static function data(mixed $value): self
    {
        return new self(self::DATA, $value, true, '');
    }

    /** 失败了（语义上等价于 `catch` 收到的异常，这里只留一句消息） */
    public static function error(string $message): self
    {
        return new self(self::ERROR, null, false, $message);
    }

    /**
     * **让新状态继承上一次的载荷**（Riverpod `copyWithPrevious`，2026-10-31）。
     *
     * ```php
     * $this->emit(AsyncValue::loading()->carriedFrom($this->value));  // 刷新：旧值还在
     * $this->emit(AsyncValue::error($m)->carriedFrom($this->value));  // 失败：旧值还在
     * ```
     *
     * 三条规则（照抄 Riverpod 的语义，不多也不少）：
     * ① **本值已经有载荷**（`data`）⇒ 原样返回 `$this`（新数据当然盖掉旧的）；
     * ② `$previous` 没有载荷 ⇒ 原样返回 `$this`（没东西可继承）；
     * ③ 否则返回**新实例**：同样的状态/消息，载荷取 `$previous` 的。
     *
     * ★ 为什么是"继承"而不是"构造时就带上"：`loading($prev)` 这种签名会让
     *   "带/不带"变成两个调用点各自的选择，而正确做法只有一条 —— **有旧值就带上**。
     *   收成一个组合子之后，调用点只有一句，判据只有一处。
     *
     * @api
     */
    public function carriedFrom(self $previous): self
    {
        if ($this->hasValue || !$previous->hasValue) {
            return $this;
        }

        // ★ 进度**跟着走**：`carriedFrom()` 的语义是"新状态继承上一次的东西"，
        //   而进度本来就是"这一次"的过程量（不是上一次的载荷）⇒ 继承的是
        //   **本值自己的**进度（`$this->progress`），不是 `$previous` 的。
        //   写成 `$previous->progress` 会让"刷新到 50% 突然跳回上次的 100%"。
        return new self($this->state, $previous->value, true, $this->message, $this->progress, $this->hasProgress);
    }

    /**
     * 带上进度，返回**新实例**（2026-11-02）。
     *
     * ```php
     * // AsyncCommand::start() 里订阅 Future 的进度
     * $future->onProgress(function (float $f): void {
     *     $this->emit($this->value->withProgress($f));
     * });
     * ```
     *
     * ★ 只改进度，**状态 / 载荷 / 消息全部保留** —— 进度是"第五个维度"，不是新状态。
     * ★ 越界夹住而不抛：这是**数据**（来自 IPC），不是调用方的参数。
     *
     * @api
     */
    public function withProgress(float $fraction): self
    {
        $f = $fraction;
        if ($f < 0.0) {
            $f = 0.0;
        } elseif ($f > 1.0) {
            $f = 1.0;
        }

        return new self($this->state, $this->value, $this->hasValue, $this->message, $f, true);
    }

    /**
     * 有没有进度可画（`false` ⇒ 画不定长转圈，而不是 0% 的条）。
     *
     * @api
     */
    public function hasProgress(): bool
    {
        return $this->hasProgress;
    }

    /**
     * 当前进度（没报过 ⇒ `$fallback`）。与 `dataOr()` 同一手法：不返回 `?float`。
     *
     * @api
     */
    public function progressOr(float $fallback = 0.0): float
    {
        return $this->hasProgress ? $this->progress : $fallback;
    }

    public function isIdle(): bool
    {
        return $this->state === self::IDLE;
    }

    public function isLoading(): bool
    {
        return $this->state === self::LOADING;
    }

    /**
     * 有没有载荷（"上一次拿到的值还在"）。
     *
     * ★ 与 {@see self::hasData()} 的区别就是本轮的核心：`hasValue()` 问"有没有值"，
     *   `hasData()` 问"这一态是不是 data"。刷新中 `hasValue() === true` 而
     *   `hasData() === false` —— 于是列表能继续画，而"请求还没回来"这件事也没被
     *   藏起来（{@see self::isRefreshing()}）。
     */
    public function hasValue(): bool
    {
        return $this->hasValue;
    }

    /**
     * 正在**刷新**（`loading` 且有上一次的值）—— 界面的"细进度条 / 小转圈"判据。
     *
     * ★ 首屏加载时它是 `false`（那时该画整块转圈，见 {@see self::fold()} 的路由）。
     */
    public function isRefreshing(): bool
    {
        return $this->state === self::LOADING && $this->hasValue;
    }

    public function hasData(): bool
    {
        return $this->state === self::DATA;
    }

    public function hasError(): bool
    {
        return $this->state === self::ERROR;
    }

    /**
     * 载荷（没有则 `null`）—— `data` / 刷新中的 `loading` / 带旧值的 `error` 都有。
     *
     * ★ 直接读它而不先判 `hasValue()` 是**调用方的 bug**（"有值"是可空语义）。
     *   布局里更常见的是 {@see self::dataOr()} —— 它把"没数据时用什么画"写在一处。
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * 有载荷就取，否则给 `$fallback`（表格 / 列表最常用的读法）。
     *
     * ★ 存在的理由：`$cols = $v->hasValue() ? $v->value() : [];` 这种三元在
     *   **每个**消费点都要重复一遍，而 `value()` 的类型是 `mixed`，忘了判就是
     *   运行期拿 `null` 去 `foreach`。收成一处，判据只有一条。
     * ★ 2026-10-31 起"有载荷"包含**刷新中的旧值** ⇒ 刷新时它们照旧画得出来
     *   （这正是"列表不闪空"在消费点的形态）。
     */
    public function dataOr(mixed $fallback): mixed
    {
        return $this->hasValue ? $this->value : $fallback;
    }

    /**
     * 四态穷尽式分支：**一次调用写完四种画法**。
     *
     * ```php
     * $label = $value->fold([
     *     'idle'    => static fn(AsyncValue $v): string => '尚未加载',
     *     'loading' => static fn(AsyncValue $v): string => '加载中…',
     *     'data'    => static fn(AsyncValue $v): string => '共 ' . \count($v->value()) . ' 行',
     *     'error'   => static fn(AsyncValue $v): string => '失败：' . $v->message(),
     * ]);
     * ```
     *
     * ## 为什么值得多这个方法
     *
     * 手写 `if (isLoading) … elseif (hasError) … else …` 有三处可以错：
     * ① 漏掉某一态 ⇒ 落进 `else` 用 `dataOr()` 的兜底值画一个"空表"，
     *    **看起来像没数据**，其实是没写完分支；② 判断顺序颠倒（`hasError` 写到
     * `dataOr` 之后 ⇒ 永远走不到）；③ `dataOr()` 的兜底值各写各的。
     * `fold()` 把"四种画法"变成**一个表达式的四个键**，漏一个当场抛（见下）。
     *
     * ## ★★ 每支统一收一个 `AsyncValue`（不是"data 收值、error 收消息"）
     *
     * 2026-10-30 的第一版是**混合签名**：`idle` / `loading` 零参，`data` 收值、
     * `error` 收消息。它看着更省事，但埋了一个**只在 AOT 暴露**的坑：
     * `fold()` 给 `data` 传一个参数，而调用方若给 `data` 写零参闭包，
     * **解释器静默通过**（PHP 允许多传参）、**AOT 直接崩**
     * （`{closure}() expects exactly 0 arguments, 1 given`）。
     * 真机就是这么炸出来的 —— phpunit 全绿、gate 全绿，只有 exe 报。
     *
     * ⇒ 现在四支签名**完全一致**：`\Closure(AsyncValue): mixed`。
     *   写不出参数个数错误，也不需要任何人记住"哪支收什么"。
     *   代价是 `data` 分支多写一次 `->value()` —— 用一次多余的方法调用，
     *   换掉一整类"解释器测不出、真机才崩"的故障。
     *
     * ## 缺键会立刻炸，不是静默
     *
     * 本方法在**入口**就校验四个键齐全。为什么不是"用到哪个查哪个"：那样只有
     * 真的走到缺的那一态才炸 —— 而"失败时界面空白"正是缺 `error` 键的症状，
     * 那等于把错误推迟到最难复现的时刻。宁可第一次调用（几乎必然是 `loading`）
     * 就抛，让问题停在开发期。
     *
     * ## ★★ 刷新中（`loading` 且有旧值）走 **`data`** 支（2026-10-31）
     *
     * 四支的名字描述的是"**现在该画哪一块**"，不是"后端处于什么阶段"。所以
     * `loading` 支的含义被收窄成**首屏**（没有旧值可画，只能转圈）；一旦有旧值，
     * 该画的就是内容 —— 与 Riverpod 的 `when(skipLoadingOnRefresh: true)`（默认值）
     * 同一条规则。
     *
     * ```php
     * // 想在"刷新中"加细粒度提示，就在 data 支里问一句
     * 'data' => fn(AsyncValue $v): Widget => Column::children([
     *     $v->isRefreshing() ? Text::make('刷新中…')->caption() : Text::make(''),
     *     $this->table($v->dataOr([])),
     * ]),
     * ```
     *
     * ★ 为什么不让调用方自己判：不判的页面**看不到任何异常**（列表闪空只是"慢"，
     *   不是"错"），于是这条会永远留在每个页面里。默认给对，特例才写代码。
     * ★ `error` **不**这样路由：失败必须让用户看见，哪怕旧内容还在
     *   （与 Riverpod 的 `skipError: false` 一致）。
     *
     * ★ PHP 没有 sealed class，也没有 `match` 的穷尽性检查（AOT 下枚举 case
     *   缺失不报错，见类注释）。所以"强制写全"只能靠**运行期校验 + 立刻抛**，
     *   这是本仓能做到的最接近 Kotlin `when` / Rust `match` 的形态。
     *
     * @param array{idle?: callable(AsyncValue): mixed, loading?: callable(AsyncValue): mixed,
     *              data?: callable(AsyncValue): mixed, error?: callable(AsyncValue): mixed} $arms
     *
     * @throws \InvalidArgumentException 四个键没写全（**故意的**：见上）
     *
     * @api
     */
    public function fold(array $arms): mixed
    {
        // ★ 只用 `isset`，不用 `is_callable`：后者在 AOT 下没验证过，而"键在不在"
        //   已经足够表达"写全四态"—— 键在但给的不是函数是明显 bug，调用点会当场
        //   报 "not callable"，不比这里检查更晚。
        foreach (['idle', 'loading', 'data', 'error'] as $required) {
            if (!isset($arms[$required])) {
                throw new \InvalidArgumentException(
                    'AsyncValue::fold() 少了一个分支：\'' . $required . '\'（四态必须写全：'
                    . 'idle / loading / data / error）—— 漏写会让那一态落进 data 兜底值，'
                    . '表现为"界面空白"而不是报错'
                );
            }
        }

        // ★ 一律"先取到变量再调用"，不写 `($arms['data'])(...)` —— 后者是"对下标
        //   表达式加括号调用"，AOT 下没验证过；`$arm(...)` 与已验证的
        //   `AsyncCommand` 里 `$task($arg)` 属同一类（先取值后调用）。
        $key = 'loading';
        if ($this->state === self::IDLE) {
            $key = 'idle';
        } elseif ($this->state === self::DATA) {
            $key = 'data';
        } elseif ($this->state === self::ERROR) {
            $key = 'error';
        } elseif ($this->hasValue) {
            // ★ 刷新中：旧值还在 ⇒ 画内容（见上面"刷新中走 data 支"一节）
            $key = 'data';
        }

        $arm = $arms[$key];

        return $arm($this);
    }

    /**
     * 四态的名字（`'idle'` / `'loading'` / `'data'` / `'error'`）—— **只给诊断**。
     *
     * ★ 存在的理由：日志 / 断言 / `Errors.log` 里要说"当时是哪一态"，而除了
     *   写一个四分支三元之外没有别的写法 —— 那个三元在每个需要打日志的地方
     *   各写一遍，比一个方法更容易写错。
     * ★★ **不要拿它做分支**（`if ($v->stateName() === 'data')`）：那是把
     *   "编译期/抛错期能查的穷尽性"换成**字符串比较**，写错一个字母不报错。
     *   界面画法一律走 {@see self::fold()}。
     *
     * @api
     */
    public function stateName(): string
    {
        if ($this->state === self::IDLE) {
            return 'idle';
        }
        if ($this->state === self::LOADING) {
            return 'loading';
        }

        return $this->state === self::DATA ? 'data' : 'error';
    }

    /** `error` 态的消息（其余三态是 `''`） */
    public function message(): string
    {
        return $this->message;
    }
}
