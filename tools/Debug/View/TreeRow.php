<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 因果树的一行（可能是一串**单链**折叠后的结果）。
 *
 * 字段口径与 `cascade` 查询逐项对齐：`count` 是同形兄弟份数，`subtree` / `omitted` /
 * `hidden` 都是**每个实例**的。行文本因此能读作"这个形状重复了 N 份，每份这么大"。
 *
 * `parentKey` 只为一件事存在：选中项在当前行表里消失时（换了帧、祖先被折叠），
 * 沿它往上找最近的还活着的祖先 —— Chrome DevTools 的 "preserve selection" 就是这个
 * 语义。找不到才退回第一行，并且**必须说出来**：静默跳到别处，读者会以为选中的
 * 还是原来那个。
 *
 * @internal 面板内部数据；文本格式与 `composer debug` 的树保持一致
 */
final class TreeRow implements Line
{
    public function __construct(
        public readonly int $nodeId,
        public readonly ?string $parentKey,
        public readonly int $depth,
        /** 单链折叠后的标签：`A > B > C`，折叠组再带 `×N #id` */
        public readonly string $label,
        public readonly int $count,
        public readonly int $subtree,
        public readonly string $kind,
        public readonly string $site,
        public readonly string $detail,
        public readonly int $omitted,
        public readonly int $hidden,
        public readonly bool $hasChildren,
    ) {
    }

    public function key(): string
    {
        return 't:' . $this->nodeId;
    }

    /** 这一行代表的实际节点数：`count × subtree`。 */
    public function instances(): int
    {
        return $this->count * $this->subtree;
    }

    /**
     * 行文本。与 CLI 的树**同一份排版**（同一个函数，不是"照着抄一遍"）：
     * 两处各写一份的话，`composer debug` 与面板迟早对不上，读者也不知道该信哪个。
     */
    public function text(): string
    {
        // 标签不截断：它是"这个节点在哪"，截掉就等于把地址删了。补位按**显示宽度**算，
        // 长标签自己把右边的数字推出去（与 `%-44s` 同义，但中文标签不会错列）。
        $line = '  '
            . Fit::pad(\str_repeat('  ', $this->depth) . $this->label, 44)
            . ' ' . Fit::padStart((string) $this->subtree, 5);
        if ($this->count > 1) {
            $line .= \sprintf('  共 %d 个', $this->instances());
        }
        if ($this->kind === 'state') {
            $line .= '  ← ' . $this->site;
            if ($this->detail !== '') {
                $line .= '  ' . $this->detail;
            }
        } elseif ($this->kind === 'unknown') {
            // `unknown` 是"重建了但找不到原因"，本身就是信号：不印出来，读者会以为
            // 那几行和别的行一样有归属。
            $line .= '  ← ? 无可见原因';
        }
        if ($this->omitted > 0) {
            // 截断标在行尾：单独一行"还有 N 个分支"每层都来一遍，会把树本身的形状冲散。
            $line .= \sprintf('  … 另 %d 支（%d 个节点）未展开', $this->omitted, $this->hidden);
        }

        return $line;
    }
}
