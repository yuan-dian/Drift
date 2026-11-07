<?php
declare(strict_types=1);

namespace Drift\Routing;

use Drift\State\SubscribingWidget;
use Drift\State\Subject;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;

/**
 * **容器控件**：订阅"当前页名"，换页 = 旧页 `Offstage` 化而非卸载（§12.1 第 7 条）。
 *
 * 与 `RouteTable` 的分工：表是**数据**（名字 → 工厂，可枚举、可单测）；
 * 本类是**容器**（持当前页 Element，决定保活）。简单应用在 `Root` 里直接
 * `match` 即可 —— 那是同一件事的最简形态，不需要这个概念。
 *
 * ```php
 * PageHost::make()
 *     ->source($store)                                     // Subject
 *     ->select(fn(AppStore $s): string => $s->currentPage()) // 只跟页名
 *     ->table($routeTable)
 * ```
 *
 * ★ **每页的 Widget 只造一次**（`State` 里按名字缓存）。否则每次重建都调工厂
 *   ⇒ 切走再切回拿到的是新实例，"保留状态"直接没了 —— 这是本节最容易错的一处。
 *
 * ★ **未命中的页名原样保留**：`build('ghost')` 返回 null（开放域，§12.1 第 2 条），
 *   于是那一页什么都不显示，而不是崩。兜底页是应用自己的决定（再 route 一个
 *   名字叫 '404' 就是了）—— 框架替它选页面就是替它做了一个产品决定。
 *
 * @api
 */
final class PageHost extends StatefulWidget implements SubscribingWidget
{
    private ?Subject $source = null;

    /** @var null|callable(Subject): string */
    private $select = null;

    private ?RouteTable $table = null;

    private function __construct()
    {
        parent::__construct();
    }

    /** @api */
    public static function make(): self
    {
        return new self();
    }

    // 不窄到 PageHostState：AOT 注册期做覆盖签名检查时它还不是已注册类，Zend 会直接 Fatal
    public function createState(): State
    {
        return new PageHostState();
    }

    /**
     * @param Subject $source 被订阅的 `Subject`（页名的唯一真相源）
     *
     * @api
     */
    public function source(Subject $source): self
    {
        $this->mutate();
        $this->source = $source;

        return $this;
    }

    /**
     * 切片函数：`Subject` → 页名。
     *
     * ★ 必须返回**标量**（string）—— `===` 门控才有意义。返回每次新建的对象
     *   会让"同值不重建"失效，退化成每 notify 都换页。
     *
     * @api
     */
    public function select(callable $fn): self
    {
        $this->mutate();
        $this->select = $fn;

        return $this;
    }

    /** @api */
    public function table(RouteTable $table): self
    {
        $this->mutate();
        $this->table = $table;

        return $this;
    }

    /** @internal PageHostState 读它 —— 与 SubjectBuilder 同形 */
    public function sourceSubject(): ?Subject
    {
        return $this->source;
    }

    /** @internal */
    public function selectFn(): ?callable
    {
        return $this->select;
    }

    /** @internal */
    public function routeTable(): ?RouteTable
    {
        return $this->table;
    }
}
