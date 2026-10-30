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
 *   ⇒ 把"四态"收进一个**不可变值对象**之后，①②③ 变成 `FutureBuilder` 的
 *     一件事，而不是每个页面的三件事。
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
 * ## 不可变
 *
 * 值对象：`data()` / `error()` 返回**新实例**，不就地改。原因与 `Future` 同类 ——
 * 同一个 `AsyncValue` 可能被多处持有（旧的一帧、正在画的子树），就地改会让
 * "上一帧的引用"读到下一帧的值。
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

    /** `data` 态的值（其余三态恒 `null`） */
    private mixed $value;

    /** `error` 态的消息（其余三态恒 `''`）—— 用 string 而不是 `\Throwable`：
     *  AOT 对"可空对象字段"会读成 `php::Var`（`MEMORY` 第 41 条），而界面要的
     *  本来就是一句能画出来的话。需要堆栈的场合去 `Errors.log` 看。 */
    private string $message;

    private function __construct(int $state, mixed $value, string $message)
    {
        $this->state = $state;
        $this->value = $value;
        $this->message = $message;
    }

    /** 还没开始（页面初始、或"点一下才加载"的按钮态） */
    public static function idle(): self
    {
        return new self(self::IDLE, null, '');
    }

    /** 正在等（请求已发出、还没回来）—— 界面通常画 `Spinner` */
    public static function loading(): self
    {
        return new self(self::LOADING, null, '');
    }

    /** 有数据了 */
    public static function data(mixed $value): self
    {
        return new self(self::DATA, $value, '');
    }

    /** 失败了（语义上等价于 `catch` 收到的异常，这里只留一句消息） */
    public static function error(string $message): self
    {
        return new self(self::ERROR, null, $message);
    }

    public function isIdle(): bool
    {
        return $this->state === self::IDLE;
    }

    public function isLoading(): bool
    {
        return $this->state === self::LOADING;
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
     * `data` 态的值（其余三态是 `null`）。
     *
     * ★ 直接读它而不先判 `hasData()` 是**调用方的 bug**（"有值"是可空语义）。
     *   布局里更常见的是 {@see self::dataOr()} —— 它把"没数据时用什么画"写在一处。
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * 有数据就取，否则给 `$fallback`（表格 / 列表最常用的读法）。
     *
     * ★ 存在的理由：`$cols = $v->hasData() ? $v->value() : [];` 这种三元在
     *   **每个**消费点都要重复一遍，而 `value()` 的类型是 `mixed`，忘了判就是
     *   运行期拿 `null` 去 `foreach`。收成一处，判据只有一条。
     */
    public function dataOr(mixed $fallback): mixed
    {
        return $this->state === self::DATA ? $this->value : $fallback;
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
        //   `FutureBuilder` 里 `($this->builder)(...)` 属同一类（先取值后调用）。
        $key = 'loading';
        if ($this->state === self::IDLE) {
            $key = 'idle';
        } elseif ($this->state === self::DATA) {
            $key = 'data';
        } elseif ($this->state === self::ERROR) {
            $key = 'error';
        }

        $arm = $arms[$key];

        return $arm($this);
    }

    /** `error` 态的消息（其余三态是 `''`） */
    public function message(): string
    {
        return $this->message;
    }
}
