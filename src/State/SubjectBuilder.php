<?php
declare(strict_types=1);

namespace Drift\State;

use Drift\Errors;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/**
 * 专职订阅控件：把 `Subject` 的一个切片变成一棵子树。
 *
 * ```php
 * SubjectBuilder::make($store)
 *     ->select(fn(UserStore $s): LoadStatus => $s->status())   // 只跟 status
 *     ->builder(fn(LoadStatus $st): Widget => match ($st) { … })
 * ```
 *
 * ★ **相等门控比的是两次 `select` 输出**，不是 `Store` 的新旧值 —— 框架不存
 *   `Subject` 快照，旧值缓存在订阅者自己身上。三条推论决定用法：
 *   ① `select` 闭包**每帧都跑**，所以必须廉价（省的是子树重建，不省计算）；
 *   ② 返回值该是"代表变化的最小值"（摘要）。每次新建的值对象实现
 *      {@see Equatable} 即自动按值比；否则配 {@see self::equals()}，默认 `===`；
 *   ③ 闭包不许有副作用（它每帧都跑，且被 lint 用来推导依赖）。
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
     * 切片：只关心 `Subject` 的这一部分。
     *
     * @param callable(Subject): mixed $f 闭包体内出现的属性集合 = 该订阅者**真正
     *        依赖的字段集**（框架编译期推导依赖的第一步，可 lint）
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
     * ★ 值对象实现 `Equatable` 后默认就按值比，本方法只是**覆盖口**：
     *   比较规则不属于该值对象、或要比的不是它自己那套语义时才用。
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
     * ★ 收不收形参**跟着 `select()` 走**：有 `select` ⇒ 调 `$f($v)`；
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
 * 订阅只在 `initState()` 里做一次；`build()` 体内**不订阅**
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
            // 判等策略由 `equals()` 给（默认走 `sameValue()`：值对象按值，其余按身份）。
            // 旧值缓存在本 State 上，框架不留 `Subject` 快照。
            $same = $equals !== null
                ? (bool) $equals($value, $this->selected)
                : self::sameValue($value, $this->selected);
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

    /**
     * 没给 `equals()` 时的默认门控。
     *
     * ★ 两边都是 `Equatable` 才按值比：`null → 对象`、类型切换仍走 `===`，
     *   否则「从没结果变成有结果」会被一个空值对象误判成没变。
     */
    private static function sameValue(mixed $a, mixed $b): bool
    {
        if ($a instanceof Equatable && $b instanceof Equatable) {
            return $a->equals($b);
        }

        return $a === $b;
    }
}
