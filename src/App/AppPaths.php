<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Platform\Platform;

use function ltrim;
use function rtrim;

/**
 * 应用专属配置路径（§5.4）—— **平台给基础目录，应用给身份名**。
 *
 * 两层分离对齐成熟 GUI 框架：Qt 的 organizationName/applicationName、Electron 的
 * package.json `name`、Tauri 的 bundleIdentifier 都是"身份只声明一次，框架拼路径"。
 * 框架名不进用户目录 ⇒ 多个 Drift 应用不会因同名状态文件互相覆盖。
 *
 * 基础目录来自 `Platform::configDir()`（PHP 侧禁 getenv，只能平台回答）；
 * 分隔符用 `DIRECTORY_SEPARATOR`，不在共享代码里写死 `'\\'` —— X11 下反斜杠是合法
 * 文件名字符，写死会拼出 `.../.config/Drift\hello.window` 这种怪文件。
 *
 * 只组装路径、不创建目录（读路径无副作用；创建归写入方）。
 * 组合根不直接接触本类：只把应用名交给 `WindowSet`，路径由本类统一拼装。
 * @internal
 */
final class AppPaths
{
    private function __construct(
        private readonly string $baseDir,
        private readonly string $appName,
    ) {
    }

    /**
     * 组合根唯一入口：声明一次应用名，之后组件只从本对象取路径。
     * `$platform->configDir()` 为 `''` ⇒ 所有 `file()` 返回 `''`（静默跳过持久化）。
     */
    public static function forApp(Platform $platform, string $appName): self
    {
        return new self($platform->configDir(), $appName);
    }

    /** 测试 / 特殊部署：直接给基础目录，可指向临时目录而不碰真实配置区 */
    public static function at(string $baseDir, string $appName): self
    {
        return new self($baseDir, $appName);
    }

    /** 应用专属目录下的一份文件；任一段为空 ⇒ 返回 `''` 让调用方跳过 */
    public function file(string $name): string
    {
        if ($this->baseDir === '' || $this->appName === '' || $name === '') {
            return '';
        }

        $sep = \DIRECTORY_SEPARATOR;

        return rtrim($this->baseDir, "\\/") . $sep
            . ltrim($this->appName, "\\/") . $sep
            . ltrim($name, "\\/");
    }

    /** 窗口几何状态文件（框架固定名；一个文件内按窗口槽位分组） */
    public function windowState(): string
    {
        return $this->file('window.state');
    }
}
