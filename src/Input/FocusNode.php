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
 *   · `order` —— `->focusOrder(int)`：同一父容器内**稳定排序**（不声明则用文档序）
 *   · `skip`  —— `->skipFocus()`：整棵子树不进 Tab 序（**显式**，如 AppBar 工具组）
 *   · `claimedKeys` —— `->claimKeys([...])`：**编辑命令优先**，命中时不让加速键抢
 *
 * ★ 「同一容器内混用有/无 `focusOrder`」是 lint 判据（§9.1），不在这里兜 ——
 *   值对象没有"兄弟"的概念，判据要有容器视野才成立。
 *
 * @api
 */
final readonly class FocusNode
{
    /** @param list<KeyChord> $claimedKeys 该点独占的键集（编辑命令优先于加速键） */
    private function __construct(
        public ?int $order,
        public bool $skip,
        public array $claimedKeys,
    ) {
    }

    public static function make(): self
    {
        return new self(null, false, []);
    }

    public function withOrder(int $order): self
    {
        return new self($order, $this->skip, $this->claimedKeys);
    }

    public function skipped(): self
    {
        return new self($this->order, true, $this->claimedKeys);
    }

    /** @param list<KeyChord> $chords */
    public function claiming(array $chords): self
    {
        return new self($this->order, $this->skip, $chords);
    }

    /** 是否声明了显式顺序（§9.1 的 lint 判据看的就是"有没有它"） */
    public function hasOrder(): bool
    {
        return $this->order !== null;
    }

    /** 当前焦点是否独占这个组合（§9.1 让位规则 ②） */
    public function claims(KeyChord $chord): bool
    {
        foreach ($this->claimedKeys as $claimed) {
            if ($claimed->is($chord)) {
                return true;
            }
        }

        return false;
    }
}
