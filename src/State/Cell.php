<?php
declare(strict_types=1);

namespace Drift\State;

use Drift\Core\Readable;
use Drift\Core\Tracker;

/**
 * 一个**会通知的格子**：值 + 版本 + 订阅者。
 *
 * 它替掉两样东西 ——
 * - `private int $count = 0;` + `$this->setState(function () { $this->count++; })`：
 *   现在 `$this->count->update(fn(int $n): int => $n + 1)`，**不需要** `setState`；
 * - `Subject` 子类 + `notifyChanged()`：现在值存在格子里，写的时候自动通知。
 *
 * ```php
 * final class CounterPage extends Page
 * {
 *     private Cell $count;                       // ← 声明
 *
 *     public function initState(): void
 *     {
 *         $this->count = Cell::of(0)->named('count');
 *     }
 *
 *     public function build(BuildContext $ctx): Widget
 *     {
 *         return Column::make()->children([
 *             Bind::make(fn (BuildContext $ctx): Widget => Text::make('计数 ' . $this->count->get())),
 *             Button::make('+1')->onPressed(fn() => $this->count->update(
 *                 static fn(int $n): int => $n + 1,
 *             )),
 *         ]);
 *     }
 * }
 * ```
 *
 * ## 三条契约
 *
 * ★ **`get()` 只在 build 里订阅**：读的那一刻若在采集栈上（`State` / `Bind` 的
 *   `build()` 期间），记成依赖；在事件处理器里读什么都不订 —— 那是"看一眼当前值"，
 *   不是依赖。要显式表达后者用 `peek()`，它同时让意图可读。
 * ★ **相等门控默认按值**：标量 `===`；两个都实现 {@see Equatable} 时问它自己
 *   （与 `SubjectBuilder` 的门控同一套语义）；数组按 `===` **深比较** —— 存大数组
 *   时注意，一次 `set()` 会走一遍元素。写等值不通知、不重建。
 * ★ **build 期写会走 `State` 的原有守卫**：`set()` 通知订阅者 ⇒ 订阅者的
 *   `markDirty()` 若发现"订阅者正是当前正在 build 的 State"就 Fatal（E-FLUSH-CYCLE）。
 *   不再往前挪到" Tracker 非空就报" —— 那会把**合法的跨 State build 期写**一起误杀
 *   （框架原有的 `chainwrite` 契约）。没被任何 State 读的格子在 build 期写是无害的。
 *
 * ## 与 `Subject` 的关系
 *
 * 继承 `Subject` 而不是另造一套订阅表：退订按属主整批退（`unsubscribeAllOf()`）、
 * 订阅者异常隔离、`version()` 都在那里，且 `Computed` 的版本和判据直接可用。
 * 唯一新增的是"值"和"相等门控"。
 *
 * @api
 */
final class Cell extends Subject implements Readable
{
    /** @var null|callable(mixed, mixed): bool 自定义相等；`null` ⇒ 标量 `===` / `Equatable` */
    private $equals = null;

    private bool $distinct = true;

    /** 诊断用名字（报错与 Inspector 显示；空 = 用类名 + id） */
    private string $name = '';

    public function __construct(private mixed $value = null)
    {
    }

    /** @api */
    public static function of(mixed $value = null): self
    {
        return new self($value);
    }

    /**
     * 读值（**在 build 里读 = 订阅**）。
     *
     * @api
     */
    public function get(): mixed
    {
        Tracker::record($this);

        return $this->value;
    }

    /**
     * 读值但**不订阅**：事件处理器里"先看当前值再决定"用它。
     *
     * ★ 存在的理由不是省一次记录，而是**让意图在代码里可见** —— 评审时看到
     *   `peek()` 就知道"这里读了不打算跟着变"，看到 `get()` 就知道这里有依赖。
     *
     * @api
     */
    public function peek(): mixed
    {
        return $this->value;
    }

    /**
     * 写值。值没变（按门控）⇒ 什么都不做。
     *
     * @api
     */
    public function set(mixed $value): void
    {
        if ($this->distinct && $this->same($this->value, $value)) {
            return;
        }

        $this->value = $value;
        $this->notifyChanged();
    }

    /**
     * 返回一个"把本格子写成 `$value`"的无参动作，可直接喂给事件 API。
     *
     * ```php
     * Button::make('确定')->onPressed($this->lastPressed->setTo('已点'));
     * ```
     *
     * ★ 与 `set()` 的分别：`set()` 当场写；`setTo()` 只打包，等接收方调用时才写。
     *   没有逻辑的一次性写入不该逼人写 `fn() => $cell->set(...)`。
     *
     * @api
     */
    public function setTo(mixed $value): CellWrite
    {
        return new CellWrite($this, $value);
    }

    /**
     * 按旧值算新值，一趟读写。
     *
     * ```php
     * $this->count->update(fn(int $n): int => $n + 1);
     * ```
     *
     * @param callable(mixed): mixed $f
     *
     * @api
     */
    public function update(callable $f): void
    {
        $this->set($f($this->value));
    }

    /**
     * 自定义相等（例如"两个数组按内容比"或"只看某个字段"）。
     *
     * @param callable(mixed, mixed): bool $equals
     *
     * @api
     */
    public function equalsBy(callable $equals): self
    {
        $this->equals = $equals;

        return $this;
    }

    /**
     * 关掉相等门控：**每次 `set()` 都通知**（值对象每次都新建、但你想让订阅者
     * 无条件重读时用）。
     *
     * @api
     */
    public function distinct(bool $on): self
    {
        $this->distinct = $on;

        return $this;
    }

    /** 诊断名（报错与 Inspector 显示）@api */
    public function named(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /** @internal 报错文案用 */
    public function describe(): string
    {
        return $this->name !== '' ? 'Cell(' . $this->name . ')' : 'Cell#' . \spl_object_id($this);
    }

    private function same(mixed $a, mixed $b): bool
    {
        if ($this->equals !== null) {
            return (bool) ($this->equals)($a, $b);
        }

        if ($a instanceof Equatable && $b instanceof Equatable) {
            return $a->equals($b);
        }

        return $a === $b;
    }
}
