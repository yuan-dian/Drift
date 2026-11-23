<?php
declare(strict_types=1);

namespace Drift\State;

use Drift\Diagnostics;
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
 * ★ **这是手写订阅的那条路，新代码默认不用它**：状态写成 `Cell`
 *   之后，`Bind::make(fn (BuildContext $ctx): Widget => …)` 的 builder 里读什么就订什么 —— 依赖由
 *   采集给出，漏写 `select` 这个失效模式不存在。本类只在"多个字段必须同频变化、
 *   读方要一次拿到一致的几块"时才有存在理由（见 A18），而那种形状目前 `apps/`
 *   里没有（A19）。
 *
 * ★ **相等门控比的是两次 `select` 输出**，不是 `Store` 的新旧值 —— 框架不存
 *   `Subject` 快照，旧值缓存在订阅者自己身上。四条推论决定用法：
 *   ① `select` 闭包**每帧都跑**，所以必须廉价（省的是子树重建，不省计算）；
 *   ② 返回值该是"代表变化的最小值"（摘要）。每次新建的值对象实现
 *      {@see Equatable} 即自动按值比；否则配 {@see self::equals()}，默认 `===`；
 *   ③ 闭包不许有副作用（它每帧都跑，且被 lint 用来推导依赖）；
 *   ④ 父重建换 `select` / `equals` / `source` 会自动跟随：缓存值重算、必要时重绑订阅。
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
 * 订阅绑在命名槽上：`initState()` 挂一次，换 source 时 `didUpdateWidget()`
 * 重绑；`build()` 体内**不订阅**（否则订阅数随重建次数增长）。
 */
final class SubjectBuilderState extends State
{
    private mixed $selected = null;

    /** 订阅实际绑在哪一个 Subject 上；换描述时据此判断要不要重绑 */
    private ?Subject $boundSource = null;

    /** 连续「同值不同身份」计数；一次可能是偶然，连续到阈值才说明每帧重建 */
    private const int JITTER_ALERT = 8;

    private int $jitterStreak = 0;

    public function initState(): void
    {
        /** @var SubjectBuilder $widget */
        $widget = $this->widget();
        $source = $widget->source();
        if ($source === null) {
            Errors::report('SubjectBuilder 没有 source：订阅一个"没东西"没有意义');
        }

        $this->boundSource = $source;
        $this->reselect($widget);
        $this->bindSource($source);
    }

    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);

        /** @var SubjectBuilder $newWidget */
        $newWidget = $widget;
        $source = $newWidget->source();
        if ($source === null) {
            Errors::report('SubjectBuilder 没有 source：订阅一个"没东西"没有意义');
        }

        // ★ 只在 source 真换了才重绑：每次 update 都换会让订阅 id 漂移。
        if ($source !== $this->boundSource) {
            $this->boundSource = $source;
            $this->bindSource($source);
        }

        // ★ select 换了 ⇒ 缓存值必须换语义重算；紧接着 Element::update() 会
        //   无条件 rebuild()，build() 才能把新值交给新 builder()。
        $this->reselect($newWidget);
        $this->jitterStreak = 0;
    }

    /** 绑/重绑订阅：同名槽 ⇒ 先退旧再挂新，不随 update 累加 */
    private function bindSource(Subject $source): void
    {
        $this->watchSlot('source', $source, function (): void {
            $this->onSourceChanged();
        });
    }

    /** 通知回调：每帧从当前描述现读 select/equals，不用 initState 固化的闭包 */
    private function onSourceChanged(): void
    {
        /** @var SubjectBuilder $widget */
        $widget = $this->widget();
        $select = $widget->selectFn();

        if ($select === null) {
            $this->jitterStreak = 0;
            $this->markDirty();

            return;
        }

        $value = $select($widget->source());
        // 判等策略由 `equals()` 给（默认走 `sameValue()`：值对象按值，其余按身份）。
        $equals = $widget->equalsFn();
        $same = $equals !== null
            ? (bool) $equals($value, $this->selected)
            : self::sameValue($value, $this->selected);
        if ($same) {
            $this->jitterStreak = 0;

            return; // ★ 两次 select 输出相等 ⇒ 不重建（§11.3.3 门控）
        }

        // ★ 只把「同值不同身份」算抖动：select 返回 int 且数据真在变时 `===`
        //   判不等是正确行为；两个同类对象 `==` 相等却 `===` 不等，才是数据没变
        //   却每帧重建（每次 new 的等价值对象）。显式给了 equals() 也不判 ——
        //   那时比较语义归调用方，判不等是有意的。
        if ($equals === null && self::sameContent($value, $this->selected)) {
            $this->jitterStreak++;
            if ($this->jitterStreak === self::JITTER_ALERT) {
                Diagnostics::degraded(
                    'select/unstable',
                    \sprintf(
                        '连续 %d 次 notify 都是同值不同身份（%s）—— select 每次 new 等价对象，'
                        . '`===` 门控恒不相等 ⇒ 子树每帧重建；实现 Equatable、配 equals()，或复用稳定实例',
                        self::JITTER_ALERT,
                        self::valueKind($value),
                    )
                );
            }
        } else {
            $this->jitterStreak = 0;
        }

        $this->markDirty();
        $this->selected = $value;
    }

    /**
     * 「同值不同身份」：两个**同类、无值相等契约**的对象 `==` 相等但 `===` 不等。
     *
     * ★ 收窄到对象：数组 `==` 相等而 `===` 不等可能只是键序变了；标量 `===`
     *   不等就是真变了；跨类型（null → false）也是真变了。放宽会把正常数据
     *   更新报成事故 —— 诊断一旦误报，很快就没人看了。
     */
    private static function sameContent(mixed $a, mixed $b): bool
    {
        if (!\is_object($a) || !\is_object($b)) {
            return false;
        }
        if ($a instanceof Equatable || $b instanceof Equatable) {
            return false;
        }

        return \get_class($a) === \get_class($b) && $a == $b;
    }

    private static function valueKind(mixed $v): string
    {
        return \is_object($v) ? \get_class($v) . '（无值相等契约）' : \gettype($v);
    }

    /** 用当前描述的 select 重算缓存值（首帧与换描述时各一次） */
    private function reselect(SubjectBuilder $widget): void
    {
        $select = $widget->selectFn();
        if ($select === null) {
            $this->selected = null;

            return;
        }

        $this->selected = $select($widget->source());
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
