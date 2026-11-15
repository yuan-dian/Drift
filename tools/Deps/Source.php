<?php
declare(strict_types=1);

namespace Drift\Dev\Deps;

/**
 * 依赖包来源判定：给的是**网址**还是**手里已有的那个文件**。
 *
 * ★ 首个 release 发布前，唯一可行的分发是「有 `cpp/` 的机器打包 → 拷给新机器」。
 *   而 curl 收到裸 Windows 路径会报 `URL rejected: Port number was not a decimal number`
 *   —— 与真实原因（这是文件、不是网址）毫无关系。
 */
final class Source
{
    /** 带协议头（`https://` / `file://` …）；裸路径与主机名都不算 */
    public static function isUrl(string $raw): bool
    {
        return preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://#', trim($raw)) === 1;
    }

    /**
     * 本地已存在的包 ⇒ 绝对路径；网址、协议不认、文件不存在 ⇒ `null`。
     *
     * 认三种写法：裸路径（`D:/x.zip`、`\\srv\share\x.zip`、相对路径）、
     * `file:///D:/x.zip`、`file://` + 百分号编码（空格 / 中文）。
     * 网络协议一律不碰 —— 那条路由 `depsDownload()` 负责。
     */
    public static function localPath(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (stripos($raw, 'file://') === 0) {
            $path = rawurldecode(substr($raw, 7));
            // file:///D:/x.zip ⇒ D:/x.zip；POSIX 的 /tmp/x.zip 保持原样（不以盘符开头）
            $path = preg_replace('#^/([A-Za-z]:[/\\\\])#', '$1', $path) ?? $path;
        } elseif (self::isUrl($raw)) {
            return null;
        } else {
            $path = $raw;
        }

        if (!is_file($path)) {
            return null;
        }

        return realpath($path) ?: $path;
    }

    /**
     * 包旁边的 `.sha256`（与远端同形：一个文件一行的校验值）。
     *
     * 有就参与「lock ↔ 来源」交叉核对；没有不报错 —— lock 本身就是权威值。
     */
    public static function localSha(string $zipPath): ?string
    {
        $sidecar = $zipPath . '.sha256';
        if (!is_file($sidecar)) {
            return null;
        }

        $raw = trim((string) file_get_contents($sidecar));
        $token = preg_split('/\s+/', $raw)[0] ?? '';

        return preg_match('/^[0-9a-f]{64}$/i', $token) === 1 ? strtolower($token) : null;
    }
}
