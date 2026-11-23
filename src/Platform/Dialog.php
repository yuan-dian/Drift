<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 原生对话框（壳服务）。
 *
 * 四个方法都是阻塞模态：模态期间系统跑自己的内层消息循环，不会走
 * `Application::frame()`，界面不重绘，这是原生对话框的固有语义。
 * `alert()` / `confirm()` 是业务问用户，`Platform::fatalBox()` 是框架崩溃前的最后一句，
 * 调用方不同，不合并。文件对话框表达不了"失败"：真失败由壳自己弹框，
 * 不新增第三种返回值。
 *
 * @api platform
 */
interface Dialog
{
    /**
     * 选一个已有文件；用户取消 ⇒ `null`（空串是合法路径前缀，不能混用）。
     *
     * @param list<array{string, string}> $filters `[[显示名, 通配符], ...]`
     */
    public function openFile(string $title, array $filters = []): ?string;

    /**
     * 选一个或多个已有文件。取消 ⇒ `[]`；只选一个也返回单元素数组。
     *
     * 与 `openFile()` 分成两个方法：返回类型不同（`?string` vs `list<string>`），
     * 合成一个会得到本仓避免的联合类型（tpc 下退化成 `php::Var`）。
     *
     * @param list<array{string, string}> $filters 同 `openFile()`
     * @return list<string> 选中的路径（顺序 = 对话框里的顺序）
     */
    public function openFiles(string $title, array $filters = []): array;

    /** 选一个保存位置；取消 ⇒ `null`。`$defaultName` 只填文件名，不带目录 */
    public function saveFile(string $title, string $defaultName = '', array $filters = []): ?string;

    /** 只有一个"确定"的提示框（**阻塞**） */
    public function alert(string $title, string $body): void;

    /** 是 / 否（**阻塞**）；用户关掉窗口 ⇒ `false`（默认不执行破坏性动作） */
    public function confirm(string $title, string $body): bool;
}
