<?php
declare(strict_types=1);

namespace Drift\App;

use function ltrim;
use function rtrim;

/**
 * 用户级文件路径（§5.4 / §A.3.8）—— **只做字符串组装，不认识平台**。
 *
 * ★ 为什么"配置目录"不由这个类自己取：§5.1 的分层纪律是"框架不持平台"。
 *   若这里直调 `drift_win_*`，`Drift\App`（共享代码）就依赖了平台实现，
 *   而 Bootstrap 之外不该有人见过平台名。目录由
 *   `Drift\Platform\Platform::configDir()` 给（每个平台都有一个，属平台能力），
 *   本类只负责"目录 + 名字"这一层，并把跨平台的分隔符差异收在这一处。
 *
 * ★ **`userConfig()` 收 `$baseDir` 而不是持有静态目录**：静态目录 = 隐藏全局态
 *   （不可测、换不了），而调平台 = 破分层。⇒ 参数从组合根传进来。
 *   DESIGN §A.3.8 的样例写成 `Paths::userConfig('users.window')`（**缺 baseDir，
 *   编译不过**），已同步修正为 `Paths::userConfig($platform->configDir(), 'users.window')`。
 *
 * @api
 */
final class Paths
{
    /** 路径组装无状态 ⇒ 不可实例化 */
    private function __construct()
    {
    }

    /**
     * 用户配置文件路径（**不创建目录** —— 读路径不该有副作用，创建归写入方）。
     *
     * @param string $baseDir 平台给的配置目录；`''` = 平台没给出 ⇒ 返回 `''`，
     *        调用方据此跳过持久化且**不报错**（§5.4 纪律 2 的静默降级）
     * @param string $name    文件名（可带子目录，如 `'users.window'`）
     *
     * @api
     */
    public static function userConfig(string $baseDir, string $name): string
    {
        if ($baseDir === '' || $name === '') {
            return '';
        }

        return rtrim($baseDir, "\\/") . '\\' . ltrim($name, "\\/");
    }
}
