<?php
declare(strict_types=1);

namespace Drift\Bench;

use Drift\Platform\Dialog;

/**
 * @internal 基准用空对话框端口。
 *
 * ★ 基准只量"建树 + 布局 + 绘制"（`Trees::showcase()` 的注释），原生对话框在
 *   无头进程里既弹不出来也不该弹 ⇒ 给一个**行为为空**的实现，让 `ShowcaseRoot`
 *   的构造签名能凑齐。
 * ★ 三个返回值都取"用户取消 / 否"这一支：空实现也不能假装成功（返回假路径会让
 *   页面显示一条不存在的文件，反而掩盖问题）。
 */
final class NullDialog implements Dialog
{
    public function openFile(string $title, array $filters = []): ?string
    {
        return null;
    }

    /** @return list<string> 空数组 = 用户取消（同 `openFile()` 返回 null 的那一支） */
    public function openFiles(string $title, array $filters = []): array
    {
        return [];
    }

    public function saveFile(string $title, string $defaultName = '', array $filters = []): ?string
    {
        return null;
    }

    public function alert(string $title, string $body): void
    {
    }

    public function confirm(string $title, string $body): bool
    {
        return false;
    }
}
