<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 一个**可聚焦点的声明**（§9.1：「`FocusNode`；焦点是**渲染态**，不放业务值」）。
 *
 * ★ 它是**值对象**（Widget 侧声明），**不持有**"现在谁聚焦"——
 *   后者是运行态，住在 `Drift\App\FocusManager`。这条分工不是洁癖：
 *   Widget 是**不可变**的（`seal()` 之后写入即 Fatal，§10.1），把可变焦点
 *   状态放进来会立刻撞上这条纪律。
 *
 * 三个字段对应 §9.1 / §A.3.4 的三条契约：
 *   · `order`     —— `->focusOrder(int)`：同一父容器内**稳定排序**（不声明则用文档序）
 *   · `skip`      —— `->skipFocus()`：整棵子树不进 Tab 序（**显式**，如 AppBar 工具组）
 *   · `autoFocus` —— `->autoFocus()`：焦点空着时自动落到本控件（声明，不是命令）
 *
 * ★★ **这里曾经有第三个字段 `claimedKeys`（`->claimKeys()`），2026-11-03 删除**：
 *   它表达"焦点控件独占这些键，加速键让位"。但那是**逐键列举**，注定要每个控件
 *   自己想一遍要抢哪些键 —— `Table` 就没想（非编辑态的 `Ctrl+A`/`Ctrl+C` 一直是
 *   死码），而且列举也表达不了"只在编辑时才抢"。
 *   现在让位规则改由**路由顺序**表达：焦点链先于加速键，谁处理谁赢
 *   （见 `App\InputRouter::dispatch()`）。不需要声明，也就不会漏。
 *
 * ★ 「同一容器内混用有/无 `focusOrder`」是 lint 判据（§9.1），不在这里兜 ——
 *   值对象没有"兄弟"的概念，判据要有容器视野才成立。
 *
 * @api
 */
final readonly class FocusNode
{
    private function __construct(
        public ?int $order,
        public bool $skip,
        public bool $autoFocus,
    ) {
    }

    public static function make(): self
    {
        return new self(null, false, false);
    }

    public function withOrder(int $order): self
    {
        return new self($order, $this->skip, $this->autoFocus);
    }

    public function skipped(): self
    {
        return new self($this->order, true, $this->autoFocus);
    }

    public function withAutoFocus(): self
    {
        return new self($this->order, $this->skip, true);
    }

    /** 是否声明了显式顺序（§9.1 的 lint 判据看的就是"有没有它"） */
    public function hasOrder(): bool
    {
        return $this->order !== null;
    }
}
