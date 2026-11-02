<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 图片资源的**定位 + 解码 + 缓存**（W8-1 的 `Image` 控件背后那一层）。
 *
 * ```php
 * $image = ImageLoader::load('demo/logo.png'); // ?ImageHandle
 * ```
 *
 * ## 为什么要它（而不是各处拼路径 + 各调解码）
 *
 * "图片放哪儿"是**分发形态**的知识，只该有一处答案 —— 与 `FontLocator`
 * 同一个理由（那边是字体，这边是图片）。散在 `Application` / 演示页 / 测试里，
 * 改一次要改三处，漏一处就是"某个形态下图找不到、控件静默空白"。
 *
 * 候选目录（**分发形态优先**）：
 *   ⓪ `ImageLoader::setBaseDir()` 显式指定的目录（测试 / 嵌入方用）
 *   ① `<cwd>/assets` —— exe 旁的 `assets/`（打包形态：`app.exe` + `assets/*.png`）
 *   ② `<cwd>/images` —— 次要约定（同档的两种常见命名都认）
 *   ③ `<src>/../../assets` —— 源码树（`__DIR__` 在 AOT 下是构建期路径，
 *      找不到也无害，只是多试一次）
 *
 * ★★ **绝对路径原样使用**：`setBaseDir` 与候选目录只服务**相对**路径。
 *   把绝对路径也丢进候选目录拼接，会在 Windows 上拼出 `D:\x\assets\D:\y\a.png`
 *   这种永远不存在的路径 —— 而且不报错，只是"图没显示"。
 *
 * ★★★ **必须缓存**：`Widget::createRenderObject()` 在**每次重建**时都会跑
 *   （§13.2 盒换新），没有缓存就是每帧重新 inflate 整张图 —— 帧率直接归零。
 *   键是**解析后的绝对路径**（不同相对写法指向同一文件时共享一份）。
 *   缓存不按 mtime 失效：热重载图片请 `ImageLoader::clearCache()`（测试也用它）。
 *
 * ★ 失败一律 `DIAGNOSTIC` + `null`（与 `PngDecoder` / `FontLocator` 同档）：
 *   图坏了不该让应用崩，但必须留痕。调用方拿 null 自己决定降级形态 ——
 *   `Image` 控件的选择是"退回 1×1 透明占位"，见 `placeholder()`。
 *
 * @api
 */
final class ImageLoader
{
    /** @var array<string, ImageHandle> 绝对路径 → 解码结果 */
    private static array $cache = [];

    /** 显式基目录（空 = 只用候选目录） */
    private static string $baseDir = '';

    /**
     * 显式指定资源根目录（优先级最高）。传 `''` 复位。
     *
     * ★ 收**目录**不收文件：这样一次设定服务所有相对路径，与候选目录同语义。
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

        // ★ 去重：仓库根跑时 ① 与 ③ 是同一个目录 —— 不去重会让诊断消息里
        //   出现同一路径两遍，看着像"配置错了"。
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
     */
    public static function load(string $path): ?ImageHandle
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

        if (isset(self::$cache[$resolved])) {
            return self::$cache[$resolved];
        }

        // ── 两条解码路径（2026-11-02）────────────────────────────────────────
        //
        // | 输入 | 走谁 | 为什么 |
        // |---|---|---|
        // | PNG | `PngDecoder`（纯 PHP） | 输出**逐字节确定** ⇒ golden / 单测可复现 |
        // | 其它 | 壳里的 WIC | 纯 PHP 解 JPEG 要 100~300ms，且不支持渐进式 |
        // | PNG 但解不出（隔行 / 罕见位深） | **回退 WIC** | 宁可慢一点也别显示占位图 |
        //
        // ★ 分派依据是**文件签名**不是扩展名：`a.png` 实际是 jpg 太常见。
        // ★ `ImageDecoder::available()` 在 `php.exe` 里是 false（扩展没加载）⇒
        //   单测里非 PNG 一律解不出，那是**预期**的（WIC 的判据只能在 AOT 里跑）。
        $image = null;
        if (PngDecoder::isPng($resolved)) {
            $image = PngDecoder::decodeFile($resolved);
        }
        if ($image === null) {
            $image = ImageDecoder::decode($resolved);
        }

        if ($image !== null) {
            self::$cache[$resolved] = $image;
        } else {
            Errors::report(sprintf(
                'ImageLoader：解不出图片 %s（PNG 解码器与壳的 WIC 都没成功）',
                $resolved,
            ), Errors::DIAGNOSTIC);
        }

        return $image;
    }

    /** 清缓存（测试 / 热重载） */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /** 缓存条目数（测试用：证明"第二次不再解码"） */
    public static function cacheSize(): int
    {
        return count(self::$cache);
    }

    /**
     * 1×1 全透明占位图。
     *
     * ★ `Image` 控件在资源缺失时用它顶上 —— 这样 `RenderImage` 的字段永远
     *   是非空 `ImageHandle`，不必引 `?ImageHandle`（AOT 下 `?Obj` 会退化成
     *   `php::Var`，属性访问全走动态路径）。
     * ★ 每次新建而不缓存：它只有 4 字节，比一次静态查找还便宜。
     */
    public static function placeholder(): ImageHandle
    {
        return ImageHandle::fromRgba("\x00\x00\x00\x00", 1, 1);
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
