<?php
declare(strict_types=1);

namespace Drift\State;

use Drift\Errors;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/**
 * 专职订阅控件：把 `Subject` 的一个切片变成一棵子树（§11.3.3）。
 *
 * ```php
 * SubjectBuilder::make($store)
 *     ->select(fn(UserStore $s): LoadStatus => $s->status())   // 只跟 status
 *     ->builder(fn(LoadStatus $st): Widget => match ($st) { … })
 * ```
 *
 * ★ **为什么订阅要一个专职控件**：订阅粒度 = 整个 `Subject` 时，一个 10 字段的
 *   `Store` 有 10 个订阅者 ⇒ 任一字段变、10 个全重建。靠"每个切片自己是一个
 *   `Subject`"绕会让 `Store` 必须暴露 N 个 `Subject`，还隐含"每次调用返回同一
 *   实例"（不可 lint）⇒ 那是**做了个坏版本**，不是"没做"。
 *
 * ★ **相等门控比的是两次 `select` 输出**，不是 `Store` 的新旧值 —— 框架不存
 *   `Subject` 快照，旧值缓存在订阅者自己身上（§11.3.3）。三条推论直接决定用法：
 *   ① `select` 闭包**每帧都跑**，所以必须廉价（省的是子树重建，不省计算）；
 *   ② 返回值该是"代表变化的最小值"（摘要）。**返回每次新建的值对象也行** ——
 *      但要配 {@see self::equals()} 给值语义，否则默认的 `===` 永不相等（2026-11-03）；
 *   ③ 闭包不许有副作用（它每帧都跑，且要被 lint 用来推导依赖）。
 *
 * @api
 */
final class SubjectBuilder extends StatefulWidget implements SubscribingWidget
{
    private ?Subject $source = null;

    /** @var null|callable(Subject): mixed */
    private $select = null;

    /** @var null|callable(mixed, mixed): bool 相等门控（`null` ⇒ 用 `===`） */
    private $equals = null;

    /** @var null|callable(mixed): Widget */
    private $builder = null;

    private function __construct()
    {
        parent::__construct();
    }

    /**
     * @param Subject $source 被订阅的 `Subject`（必填：订阅一个"没东西"没有意义）
     *
     * @api
     */
    public static function make(Subject $source): self
    {
        $builder = new self();
        $builder->source = $source;

        return $builder;
    }

    /**
     * 切片：只关心 `Subject` 的这一部分（§11.3.3）。
     *
     * @param callable(Subject): mixed $f 闭包体内出现的属性集合 = 该订阅者**真正
     *        依赖的字段集**（§16.1 编译期推导的第一步，可 lint）
     *
     * @api
     */
    public function select(callable $f): self
    {
        $this->mutate();
        $this->select = $f;

        return $this;
    }

    /**
     * 相等门控：**怎么判"这次 select 的输出和上次一样"**（默认 `===`）。
     *
     * ```php
     * SubjectBuilder::make($store)
     *     ->select(fn(WatermarkStore $s): StatusMessage => $s->statusLine())
     *     ->equals(fn(StatusMessage $a, StatusMessage $b): bool => $a->equals($b))
     *     ->builder(fn(StatusMessage $m): Widget => …)
     * ```
     *
     * ## 为什么必须能换（2026-11-03）
     *
     * 默认的 `===` 对**值对象**恒为 `false`：`statusLine()` 每次 `new` 一个
     * `StatusMessage` ⇒ 内容没变也是两个不同实例 ⇒ 退化成"每次通知都重建"。
     * 这正是 `apps/*` 两个 `StatusBar` 宁可手写 100 行 State 也不肯用本控件的
     * 唯一原因（它们各自手写了 `equals()` 门控）—— 而手写那 100 行里，**订阅 /
     * 退订 / 首帧 / 门控**四件事与库里的这份完全重复。
     *
     * ★ 成熟框架都给了这个口子：Flutter `ValueListenableBuilder` 走 `==`、
     *   Riverpod 的 `select` 用 `==`、Compose 的 `derivedStateOf` 用 `equals`、
     *   Vue 的 `computed` 用 `hasChanged`。**判等策略是调用方的语义**，不是框架的。
     *
     * ★★ 为什么不干脆把默认改成 `==`：PHP 的 `==` 对**数组**比键值（会漏掉
     *   "值变了但类型没变"之外的差异？不，是会**多**判等：`[1] == ['1']` 为真）、
     *   对**对象**逐属性比（两个语义不同但字段恰好相同的东西也判等）。默认值
     *   的改动会让所有现有订阅者**静默少重建**——那是最贵的一种错。所以默认
     *   `===`（一个对象只有它自己与它相等），要值语义就显式给。
     *
     * @param callable(mixed, mixed): bool $f 收到**新值、旧值**，返回"算同一个"
     *
     * @api
     */
    public function equals(callable $f): self
    {
        $this->mutate();
        $this->equals = $f;

        return $this;
    }

    /**
     * 由选出的值造子树。
     *
     * ★ 收不收形参**跟着 `select()` 走**（§11.3.3）：有 `select` ⇒ 调 `$f($v)`；
     *   没 `select` ⇒ 调 `$f()`。**不是**统一传 `null` —— 那会凭空多出"传 null
     *   给有类型形参"这条静默路径。
     *
     * @api
     */
    public function builder(callable $f): self
    {
        $this->mutate();
        $this->builder = $f;

        return $this;
    }

    /** @internal 给 `SubjectBuilderState` 读 */
    public function source(): ?Subject
    {
        return $this->source;
    }

    /** @internal */
    public function selectFn(): ?callable
    {
        return $this->select;
    }

    /** @internal */
    public function builderFn(): ?callable
    {
        return $this->builder;
    }

    /** @internal 相等门控（`null` ⇒ `===`） */
    public function equalsFn(): ?callable
    {
        return $this->equals;
    }

    public function createState(): State
    {
        return new SubjectBuilderState();
    }
}

/**
 * @internal `SubjectBuilder` 的私有 State —— 和控件同文件，不进公共面。
 *
 * 订阅只在 `initState()` 里做一次（§11.3 RT-1 / A4）；`build()` 体内**不订阅**
 * —— 否则每重建一次就多订一份，订阅数随重建次数增长。
 */
final class SubjectBuilderState extends State
{
    private bool $hasSelected = false;
    private mixed $selected = null;

    public function initState(): void
    {
        /** @var SubjectBuilder $widget */
        $widget = $this->widget();
        $source = $widget->source();
        if ($source === null) {
            Errors::report('SubjectBuilder 没有 source：订阅一个"没东西"没有意义');
        }

        $select = $widget->selectFn();
        $equals = $widget->equalsFn();

        if ($select !== null) {
            // 首次直接取，不进门控 —— 否则第一帧的子树拿不到值
            $this->selected = $select($source);
        }
        $this->hasSelected = true;

        $this->watch($source, function () use ($source, $select, $equals): void {
            if ($select === null) {
                // 没给 select ⇒ 整个 Subject 都是依赖，任何 notify 都重建
                $this->markDirty();

                return;
            }

            $value = $select($source);
            // ★ 判等策略由 `equals()` 给（默认 `===`）：值对象自己知道"算不算同一个"。
            //   旧值缓存在本 State 上，框架不留 `Subject` 快照（§11.3.3）。
            $same = $equals !== null
                ? (bool) $equals($value, $this->selected)
                : $value === $this->selected;
            if ($same) {
                return; // ★ 两次 select 输出相等 ⇒ 不重建（§11.3.3 门控）
            }

            $this->markDirty();
            $this->selected = $value;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        /** @var SubjectBuilder $widget */
        $widget = $this->widget();
        $builder = $widget->builderFn();
        if ($builder === null) {
            Errors::report('SubjectBuilder 没给 builder()：订阅了却没有子树，等于一个空盒子');
        }

        return $widget->selectFn() !== null
            ? $builder($this->selected)
            : $builder();
    }
}
