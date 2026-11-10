<?php
declare(strict_types=1);

namespace Drift\Async;

/**
 * 异步状态的唯一形状：四态 `idle` / `loading` / `data` / `error`。
 *
 * 载荷与状态是两个维度：四态都可有载荷（`carriedFrom()` 继承上一次的）。
 * 没有"第五态 refreshing"—— 多一个态就多一个 `fold()` 的键，"漏写一支"失败模式翻倍。
 *
 * 不可变：构造子返回新实例，不就地改（同一引用可能被多处持有）。
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
     * ★ 只有 `data` 态保证有；`loading` / `error` 有它当且仅当 `carriedFrom()` 继承。
     * "有没有"必须单独一个 `bool`：载荷本身可以是 `null`，拿 `$value !== null` 判
     *   会把"值是 null"和"没有值"混成一种。
     */
    private mixed $value;

    /** 是否带载荷（见 `$value` 的注释） */
    private bool $hasValue;

    /** `error` 态的消息（其余三态恒 `''`）—— 用 string 而不是 `\Throwable`：
     *  AOT 对"可空对象字段"会读成 `php::Var`，而界面要的就是一句能画的消息。 */
    private string $message;

    /** `error` 态的机器可判分类（其余三态恒 `''`）；`''` = 源异常没给分类 */
    private string $code;

    /**
     * 进度（`0.0`~`1.0`）—— 与 `$value`/`$hasValue` 同手法：`0.0` 是合法进度，
     *   单独一个 `bool` 区分"有没有进度"，否则"刚开始"和"不报进度"会混。
     * 进度是第五个维度，不是第五态：`fold()` 仍四个键。
     * 谁喂它：`AsyncCommand::start()` 订阅 `Future::onProgress()`。
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
        string $code = '',
    ) {
        $this->state = $state;
        $this->value = $value;
        $this->hasValue = $hasValue;
        $this->message = $message;
        $this->code = $code;
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
     * 它不带载荷：这是"首屏"形态。想保留上一次的值 ⇒
     *   `AsyncValue::loading()->carriedFrom($previous)`（`AsyncCommand` 就是这么发的）。
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

    /** 失败了（语义上等价于 `catch` 收到的异常，这里只留一句消息 + 一个分类码） */
    public static function error(string $message, string $code = ''): self
    {
        return new self(self::ERROR, null, false, $message, 0.0, false, $code);
    }

    /**
     * 让新状态继承上一次的载荷（Riverpod `copyWithPrevious`）。
     *
     * 三条规则：① 本值已有载荷（`data`）⇒ 原样返回；② `$previous` 无载荷 ⇒ 原样返回；
     * ③ 否则返回新实例，载荷取 `$previous` 的。
     *
     * @api
     */
    public function carriedFrom(self $previous): self
    {
        if ($this->hasValue || !$previous->hasValue) {
            return $this;
        }

        // 进度跟着走：继承的是本值自己的进度，不是 `$previous` 的。
        // 写成 `$previous->progress` 会让"刷新到 50% 突然跳回上次的 100%"。
        return new self($this->state, $previous->value, true, $this->message, $this->progress, $this->hasProgress, $this->code);
    }

    /**
     * 带上进度，返回新实例。
     *
     * 只改进度，状态 / 载荷 / 消息全部保留—— 进度是"第五个维度"，不是新状态。
     * 越界夹住而不抛：这是来自 IPC 的数据，不是调用方的参数。
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

        return new self($this->state, $this->value, $this->hasValue, $this->message, $f, true, $this->code);
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
     * 当前进度（没报过 ⇒ `$fallback`）。与 `dataOr()` 同手法：不返回 `?float`。
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
     * 与 `hasData()` 的区别：`hasValue()` 问"有没有值"，`hasData()` 问"这一态是不是 data"。
     * 刷新中 `hasValue() === true` 而 `hasData() === false` —— 列表继续画，且"请求没回来"不藏。
     */
    public function hasValue(): bool
    {
        return $this->hasValue;
    }

    /**
     * 正在刷新（`loading` 且有上一次的值）—— "细进度条 / 小转圈"判据。
     *
     * 首屏加载时它是 `false`（那时该画整块转圈）。
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
     * 直接读它而不先判 `hasValue()` 是调用方的 bug（"有值"是可空语义）。
     * 布局里更常见的是 `dataOr()` —— 它把"没数据时用什么画"写在一处。
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * 有载荷就取，否则给 `$fallback`（表格 / 列表最常用）。
     *
     * 收成一处：避免 `$cols = $v->hasValue() ? $v->value() : [];` 在每个消费点重复，
     *   而 `value()` 是 `mixed`，忘了判就是运行期拿 `null` 去 `foreach`。
     * "有载荷"包含刷新中的旧值 ⇒ 刷新时照旧画得出来（"列表不闪空"的消费点形态）。
     */
    public function dataOr(mixed $fallback): mixed
    {
        return $this->hasValue ? $this->value : $fallback;
    }

    /**
     * 四态穷尽式分支：一次调用写完四种画法，漏写一支当场抛。
     *
     * 手写 `if (isLoading) … else …` 会错：① 漏态落进 `else` 画空表；
     *   ② 判断顺序颠倒（`hasError` 写到 `dataOr` 之后走不到）；③ 兜底值各写各的。
     *
     * ★ 四支签名完全一致 `\Closure(AsyncValue): mixed`，不混签：解释器静默放行
     *   多传参，但 AOT 直接崩（`expects exactly 0 arguments, 1 given`）—— phpunit 绿、真机炸。
     *
     * 入口校验四个键齐全，缺键立刻抛：把"界面空白"这类错停在开发期，而不是推迟到
     *   走到缺的那一态才炸。
     *
     * 刷新中（`loading` 且有旧值）走 **`data`** 支：四支名描述"该画哪一块"，首屏
     *   才转圈，有旧值就画内容。`error` 不这样路由：失败必须让用户看见，哪怕旧内容还在。
     *
     * @param array{idle?: callable(AsyncValue): mixed, loading?: callable(AsyncValue): mixed,
     *              data?: callable(AsyncValue): mixed, error?: callable(AsyncValue): mixed} $arms
     *
     * @throws \InvalidArgumentException 四个键没写全（故意的：漏写会让那一态落进 data 兜底值，
     *          表现为"界面空白"而不是报错）
     *
     * @api
     */
    public function fold(array $arms): mixed
    {
        // 只用 `isset`，不用 `is_callable`：后者 AOT 下没验证过，"键在不在"已够表达写全四态。
        foreach (['idle', 'loading', 'data', 'error'] as $required) {
            if (!isset($arms[$required])) {
                throw new \InvalidArgumentException(
                    'AsyncValue::fold() 少了一个分支：\'' . $required . '\'（四态必须写全：'
                    . 'idle / loading / data / error）—— 漏写会让那一态落进 data 兜底值，'
                    . '表现为"界面空白"而不是报错'
                );
            }
        }

        // 一律先取到变量再调用，不写 `($arms['data'])(...)`：后者 AOT 下没验证过。
        $key = 'loading';
        if ($this->state === self::IDLE) {
            $key = 'idle';
        } elseif ($this->state === self::DATA) {
            $key = 'data';
        } elseif ($this->state === self::ERROR) {
            $key = 'error';
        } elseif ($this->hasValue) {
            // 刷新中：旧值还在 ⇒ 走 data 支画内容。
            $key = 'data';
        }

        $arm = $arms[$key];

        return $arm($this);
    }

    /**
     * 四态名字（`'idle'` / `'loading'` / `'data'` / `'error'`）—— 只给诊断（日志 / 断言）。
     *
     * ★ 不要拿它做分支（`if ($v->stateName() === 'data')`）：那是把穷尽性检查换成
     *   字符串比较，写错一个字母不报错。界面画法一律走 `fold()`。
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

    /**
     * `error` 态的**机器可判分类**（其余三态是 `''`）。
     *
     * ★ 别拿 `message()` 做分支：文案会改、同类错误可能不同措辞。重试 / 跳登录 /
     *   显示空页这类策略一律看 `code()`。
     * ★ `''` 是「这条异常没给分类」，不是一种错误类型；配
     *   `AsyncCommand::mapError()` 让应用侧补上语义。
     *
     * @api
     */
    public function code(): string
    {
        return $this->code;
    }
}
