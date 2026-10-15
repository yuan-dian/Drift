<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 原生对话框（壳服务，§5.1 纪律 2）。
 *
 * ★ 这四个方法是**阻塞**的（Win32 `GetOpenFileNameW` / `MessageBoxW` 都是模态）——
 *   与 §5.2"循环归平台"不冲突：模态期间系统自己跑内层消息循环，PHP 侧看不到。
 *   但要清楚它的代价：**模态期间不会走 `Application::frame()`** ⇒ 界面不重绘。
 *   这是原生对话框的固有语义，不是缺陷。
 *
 * ★ `alert` / `confirm` 与 `Platform::fatalBox()` 的分工：前者是**业务**问用户
 *   （保存吗 / 覆盖吗），走 `Errors` 之外的路径；后者是**框架**在崩之前的最后一句，
 *   不走渲染栈。两者都用 `MessageBoxW`，但**语义不同、调用方不同**，不合并。
 *
 * ⏳ **M1 不实现**：`Win32Platform::dialog()` 返回 `null`。
 *
 * @api platform
 */
interface Dialog
{
    /**
     * 选一个已有文件。**用户取消 ⇒ `null`**（不是空串 —— 空串是合法的路径前缀，
     * 两者混用会让"取消"与"选了当前目录"分不开）。
     *
     * @param list<array{string, string}> $filters `[[显示名, 通配符], ...]`，
     *        例如 `[['Markdown 文件 (*.md)', '*.md'], ['所有文件 (*.*)', '*.*']]`
     */
    public function openFile(string $title, array $filters = []): ?string;

    /** 选一个保存位置；取消 ⇒ `null`。`$defaultName` 只填文件名，不带目录 */
    public function saveFile(string $title, string $defaultName = '', array $filters = []): ?string;

    /** 只有一个"确定"的提示框（**阻塞**） */
    public function alert(string $title, string $body): void;

    /** 是 / 否（**阻塞**）；用户关掉窗口 ⇒ `false`（默认不执行破坏性动作） */
    public function confirm(string $title, string $body): bool;
}
