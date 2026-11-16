<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 图片资源的定位 + 解码 + 缓存。
 *
 * 候选目录（按优先级）：显式基目录 → `<cwd>/assets` → `<cwd>/images`
 * → 仓库根 `assets`。绝对路径原样使用，不进候选拼接。
 * ★ 绝对路径若也丢进候选拼接，会在 Windows 上拼出永不存在的路径且不报错。
 * 缓存键是"解析后的绝对路径 + 目标宽度"，按 LRU 淘汰且有字节/条目双上限；
 * 命中只换缓存位置，不重解码。失败返回 `null` 并附 `DIAGNOSTIC`，由调用方降级。
 *
 * @api
 */
final class ImageLoader
{
    /** 默认上限：字节是主闸门（一张大图就能到十几 MB），条目数只拦小图堆积 */
    private const int DEFAULT_MAX_BYTES = 48 * 1024 * 1024;

    private const int DEFAULT_MAX_ENTRIES = 256;

    /** @var array<string, ImageHandle> 绝对路径@宽度 → 解码结果；按 LRU 顺序，最久未用在前 */
    private static array $cache = [];

    private static int $cacheBytes = 0;

    private static int $maxBytes = self::DEFAULT_MAX_BYTES;

    private static int $maxEntries = self::DEFAULT_MAX_ENTRIES;

    private static int $evictions = 0;

    private static bool $oversizedReported = false;

    /** 显式基目录（空 = 只用候选目录） */
    private static string $baseDir = '';

    /**
     * 显式指定资源根目录（优先级最高）。传 `''` 复位。
     *
     * 收**目录**不收文件：一次设定服务所有相对路径，与候选目录同语义。
     */
    public static function setBaseDir(string $dir): void
    {
        self::$baseDir = $dir;
    }

    /**
     * @return list<string> 候选目录（按优先级，末尾无斜杠）
     */
    public static function candidates(): array
    {
        $raw = [];

        if (self::$baseDir !== '') {
            $raw[] = self::$baseDir;
        }

        $cwd = getcwd();
        if ($cwd !== false && $cwd !== '') {
            $raw[] = $cwd . DIRECTORY_SEPARATOR . 'assets';
            $raw[] = $cwd . DIRECTORY_SEPARATOR . 'images';
        }

        // src/Rendering → 仓库根（`__DIR__` 两级的上一级）
        $raw[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'assets';

        // 去重：运行目录即仓库根时，基目录与仓库根候选是同一目录，
        // 不去重会让诊断消息里出现重复路径。
        $dirs = [];
        foreach ($raw as $dir) {
            if (!in_array($dir, $dirs, true)) {
                $dirs[] = $dir;
            }
        }

        return $dirs;
    }

    /**
     * 把（可能相对的）资源路径解析成真实文件路径；找不到 ⇒ `null`。
     */
    public static function resolve(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (self::isAbsolute($path)) {
            if (is_file($path)) {
                return $path;
            }

            return null;
        }

        foreach (self::candidates() as $dir) {
            $full = $dir . DIRECTORY_SEPARATOR . $path;
            if (is_file($full)) {
                return $full;
            }
        }

        return null;
    }

    /**
     * 加载并解码（带缓存）。找不到 / 解不出 ⇒ `null`（附诊断）。
     *
     * ★ `$maxWidth` 目标宽度上限，`0` = 原尺寸；它**进缓存键** —— 同一文件按
     * 不同宽度解出尺寸不同的 `ImageHandle`，共用一个键会拿到错的那份。
     */
    public static function load(string $path, int $maxWidth = 0): ?ImageHandle
    {
        $resolved = self::resolve($path);
        if ($resolved === null) {
            Errors::report(sprintf(
                'ImageLoader：找不到图片 %s（候选目录：%s）',
                $path,
                implode(' / ', self::candidates()),
            ), Errors::DIAGNOSTIC);

            return null;
        }

        $key = self::cacheKey($resolved, $maxWidth);

        $hit = self::cacheGet($key);
        if ($hit !== null) {
            return $hit;
        }

        // 先试壳里的 WIC（原生解码，省 PHP 堆），失败再退回纯 PHP 的 `PngDecoder`。
        // ★ 顺序不能反：大尺寸 / 高位深图走纯 PHP 会在 UI 线程吃光内存 → 静默崩溃。
        // 分派按文件签名而非扩展名；`isPng()` 只读 8 字节，放在 WIC 之后调也不亏。
        $image = null;
        if (ImageDecoder::available()) {
            $image = ImageDecoder::decode($resolved, $maxWidth);
        }
        if ($image === null && PngDecoder::isPng($resolved)) {
            $image = PngDecoder::decodeFile($resolved);
        }

        if ($image !== null) {
            self::cachePut($key, $image);
        } else {
            Errors::report(sprintf(
                'ImageLoader：解不出图片 %s（PNG 解码器与壳的 WIC 都没成功）',
                $resolved,
            ), Errors::DIAGNOSTIC);
        }

        return $image;
    }

    /**
     * 设内存缓存上限并立刻淘汰到上限内；`0` 表示该项不限制。
     *
     * ★ 两个闸门都要：只按字节，一堆小图标能撑爆条目字典；只按条目，一张大图就顶穿内存。
     *
     * @api
     */
    public static function setCacheLimits(int $maxBytes, int $maxEntries): void
    {
        self::$maxBytes = $maxBytes > 0 ? $maxBytes : 0;
        self::$maxEntries = $maxEntries > 0 ? $maxEntries : 0;
        self::evictIfNeeded();
    }

    /** 清缓存（测试 / 热重载） */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$cacheBytes = 0;
        self::$evictions = 0;
        self::$oversizedReported = false;
    }

    /** 缓存条目数（测试用：证明"第二次不再解码"） */
    public static function cacheSize(): int
    {
        return count(self::$cache);
    }

    /** @internal 当前缓存的解码字节数 */
    public static function cacheBytes(): int
    {
        return self::$cacheBytes;
    }

    /** @internal 自上次清缓存以来的淘汰次数 */
    public static function cacheEvictions(): int
    {
        return self::$evictions;
    }

    /** @internal */
    private static function cacheKey(string $resolved, int $maxWidth): string
    {
        return $maxWidth > 0 ? $resolved . '@' . (string) $maxWidth : $resolved;
    }

    /** 命中即移到队尾：PHP 数组保序，删了重插就是 MRU。 */
    private static function cacheGet(string $key): ?ImageHandle
    {
        if (!isset(self::$cache[$key])) {
            return null;
        }
        $hit = self::$cache[$key];
        unset(self::$cache[$key]);
        self::$cache[$key] = $hit;

        return $hit;
    }

    private static function cachePut(string $key, ImageHandle $handle): void
    {
        $bytes = $handle->width * $handle->height * 4;
        if (self::$maxBytes > 0 && $bytes > self::$maxBytes) {
            // 单张就超上限：不缓存。这种情况会反复发生，只报一次免得刷屏。
            if (!self::$oversizedReported) {
                self::$oversizedReported = true;
                Errors::report(sprintf(
                    'ImageLoader：单张解码结果 %d 字节超过缓存上限 %d，本张不进缓存（可调用 setCacheLimits 调高）',
                    $bytes,
                    self::$maxBytes,
                ), Errors::DIAGNOSTIC);
            }

            return;
        }

        if (isset(self::$cache[$key])) {
            self::$cacheBytes -= self::bytesOf(self::$cache[$key]);
        }
        self::$cache[$key] = $handle;
        self::$cacheBytes += $bytes;
        self::evictIfNeeded();
    }

    private static function bytesOf(ImageHandle $handle): int
    {
        return $handle->width * $handle->height * 4;
    }

    /** 从队头淘汰，直到两个上限都满足。 */
    private static function evictIfNeeded(): void
    {
        while ((self::$maxBytes > 0 && self::$cacheBytes > self::$maxBytes)
            || (self::$maxEntries > 0 && count(self::$cache) > self::$maxEntries)) {
            $oldest = null;
            foreach (self::$cache as $key => $_) {
                $oldest = $key;
                break;
            }
            if ($oldest === null) {
                break;
            }
            $victim = self::$cache[$oldest];
            unset(self::$cache[$oldest]);
            self::$cacheBytes -= self::bytesOf($victim);
            self::$evictions++;
        }
    }

    /**
     * 1×1 全透明占位图。
     *
     * `Image` 控件在资源缺失时用它顶上，使 `RenderImage` 的字段恒为非空
     * `ImageHandle`，免引 `?ImageHandle`（AOT 下 `?Obj` 退化为 `php::Var`）。
     */
    public static function placeholder(): ImageHandle
    {
        return ImageHandle::fromRgba("\x00\x00\x00\x00", 1, 1);
    }

    /**
     * 来源三分类：`local` / `network` / `reject`（未知 scheme 明确拒绝，不静默当本地）。
     *
     * ★ 盘符判断必须排在 scheme 之前：`C:\x` 长得像 `C:` scheme，
     *   当网络或拒绝处理会让 Windows 上所有绝对路径失效。
     *
     * @api
     */
    public static function classify(string $src): string
    {
        if (\strpos($src, 'http://') === 0 || \strpos($src, 'https://') === 0) {
            return 'network';
        }
        if (\strlen($src) >= 2 && $src[1] === ':') {
            return 'local';
        }

        return \strpos($src, ':') !== false ? 'reject' : 'local';
    }

    /** `C:\x` / `\\server\x` / `/x` 都算绝对 */
    private static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $first = $path[0];
        if ($first === '/' || $first === '\\') {
            return true;
        }
        if (strlen($path) >= 2 && $path[1] === ':') {
            return true;
        }

        return false;
    }
}
