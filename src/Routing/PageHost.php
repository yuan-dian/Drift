<?php
declare(strict_types=1);

namespace Drift\Routing;

use Drift\Core\Readable;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;

/**
 * **容器控件**：跟着一个 `Readable` 的页名，换页 = 旧页 `Offstage` 化而非卸载（§12.1 第 7 条）。
 *
 * 与 `RouteTable` 的分工：表是**数据**（名字 → 工厂，可枚举、可单测）；
 * 本类是**容器**（持当前页 Element，决定保活）。简单应用在 `Root` 里直接
 * `match` 即可 —— 那是同一件事的最简形态，不需要这个概念。
 *
 * ```php
 * PageHost::make()
 *     ->from($app->pageSource())   // Cell / Computed，读它 = 订它
 *     ->table($routeTable)
 * ```
 *
 * ★ **页名来源收成一个 `Readable`**：以前是 `source(Subject)` +
 *   `select(callable)` 两段 —— 本类自己持订阅、私有 State 里手写订阅 + 手工同值门控。
 *   那三件事 `State` 的依赖采集全都在做：build 期读一次源就订阅上了，
 *   格子自己的相等门控顶掉手工比较。⇒ 少一套订阅实现，也少一处
 *   "漏写切片就每字段变都换页"。
 * ★ 页名也可以**直接从父级传**（`current()`）：页切换是纯 UI 态时不必造格子。
 *   父级重建时传新名字即可，页面实例缓存仍在 State 里（不随 Widget 重建而丢）。
 *
 * ★ **未命中的页名原样保留**：什么都不显示，而不是崩。兜底页是应用自己的决定
 *   （再 route 一个名字叫 '404' 的就是了）—— 框架替它选页面就是替它做了一个产品决定。
 *
 * @api
 */
final class PageHost extends StatefulWidget
{
    private ?Readable $source = null;

    private ?RouteTable $table = null;

    /** 纯 UI 态的当前页名；给定时优先于 `from()` 的读值 */
    private ?string $current = null;

    private bool $expanded = false;

    /** 页面子树是否默认套错误边界（A45；严格模式可关） */
    private bool $guarded = true;

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
     * 页名的通道：`Cell` / `Computed` 都行（读它 = 订它）。
     *
     * ★ 收 `Readable` 而不是 `Subject` + `select`：派生页名（"登录了就显示 A"）
     *   用 `Computed::of()` 表达，不必让本类再认识切片函数。
     *
     * @api
     */
    public function from(Readable $source): self
    {
        $this->mutate();
        $this->source = $source;

        return $this;
    }

    /** @api */
    public function table(RouteTable $table): self
    {
        $this->mutate();
        $this->table = $table;

        return $this;
    }

    /**
     * 直接给当前页名：页切换是纯 UI 态时不必造格子。
     *
     * ★ 与 `from()` 二选一；同时给定时本方法优先。
     *
     * @api
     */
    public function current(string $page): self
    {
        $this->mutate();
        $this->current = $page;

        return $this;
    }

    /**
     * 每页是否撑满内容区高度。嵌在固定高度的 Row/Column 里时必须开，
     * 否则页根 `ScrollView` 量到的是全部内容高，外层滚不动。
     *
     * @api
     */
    public function expanded(bool $value = true): self
    {
        $this->mutate();
        $this->expanded = $value;

        return $this;
    }

    /**
     * 路由页面是否默认套错误边界：默认开，一页崩只换该页占位，窗口与兄弟页继续。
     *
     * ★ 关掉它得到的是**严格模式**：页面构造 / build / 渲染异常照旧冒到帧循环
     *   走 Fatal 序列，适合 CI 与开发期把页面错误当硬失败。开关只决定"包不包"，
     *   不改 `Frame` 对无边界异常的策略（G2）。
     *
     * @api
     */
    public function guard(bool $value = true): self
    {
        $this->mutate();
        $this->guarded = $value;

        return $this;
    }

    /** @internal PageHostState 读它 */
    public function guardsPages(): bool
    {
        return $this->guarded;
    }

    /** @internal PageHostState 读它 */
    public function currentPage(): ?string
    {
        return $this->current;
    }

    /** @internal PageHostState 在 build 期读它（读 = 订阅） */
    public function source(): ?Readable
    {
        return $this->source;
    }

    /** @internal */
    public function isExpanded(): bool
    {
        return $this->expanded;
    }

    /** @internal */
    public function routeTable(): ?RouteTable
    {
        return $this->table;
    }
}
