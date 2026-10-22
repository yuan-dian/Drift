<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 覆盖层栈（arch-v2 `01` §2.1）。**每个 Surface 一个**（多窗口时对话框不会跑到
 * 另一个窗口）。
 *
 * ★ 只有五个操作：push / pop / popTo / remove / top。
 *   **没有"改顺序"**：栈顺序就是绘制顺序，改顺序要 pop 再 push。
 *
 * ★ 不持有 Element 生命周期（unmount 由 `Application` 的 push/pop 调用方做）：
 *   本类只管"谁在栈里、顺序如何"，是纯粹的数据结构 —— 便于无头测试。
 */
final class OverlayStack
{
    /** @var list<OverlayEntry> */
    private array $entries = [];

    public function push(OverlayEntry $e): void
    {
        $this->entries[] = $e;
    }

    /** 关最上面一个；空栈 ⇒ null */
    public function pop(): ?OverlayEntry
    {
        return array_pop($this->entries);
    }

    /**
     * 关掉 `$e` 之上的全部（不含 `$e` 本身）；`$e === null` ⇒ 全清。
     * 幂等：`$e` 不在栈里 ⇒ 无事发生。
     */
    public function popTo(?OverlayEntry $e): void
    {
        if ($e === null) {
            $this->entries = [];

            return;
        }

        $i = $this->indexOf($e);
        if ($i < 0) {
            return;
        }

        array_splice($this->entries, $i + 1);
    }

    /** 精确移除（owner 卸载时用，不要求它在栈顶）；幂等 */
    public function remove(OverlayEntry $e): void
    {
        $i = $this->indexOf($e);
        if ($i < 0) {
            return;
        }

        array_splice($this->entries, $i, 1);
    }

    public function top(): ?OverlayEntry
    {
        $n = count($this->entries);

        return $n === 0 ? null : $this->entries[$n - 1];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function at(int $i): ?OverlayEntry
    {
        return $this->entries[$i] ?? null;
    }

    /** @return list<OverlayEntry> */
    public function all(): array
    {
        return $this->entries;
    }

    private function indexOf(OverlayEntry $e): int
    {
        foreach ($this->entries as $i => $candidate) {
            if ($candidate === $e) {
                return $i;
            }
        }

        return -1;
    }
}
