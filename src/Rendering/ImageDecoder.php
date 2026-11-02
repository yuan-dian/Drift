<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * **壳里的图片解码器**（WIC，2026-11-02）—— `ImageLoader` 的第二条解码路径。
 *
 * ## 为什么需要第二条
 *
 * `PngDecoder` 是纯 PHP、只认 PNG（`W8-1` 的产物）。而 `apps/watermark` 要显示的
 * 是服务端返回的 **JPEG**（AI 出图几乎不可能是 PNG），用户上传的原图也多是 jpg。
 * 四条路的取舍见 `cpp-src/win32/image_decode.h` 的表格 —— 结论是 WIC：
 * 零体积增量（`windowscodecs.lib` 是系统组件）、格式全覆盖、系统级速度。
 *
 * ## 两条路怎么分工（**不是"新的取代旧的"**）
 *
 * | 输入 | 走谁 | 为什么 |
 * |---|---|---|
 * | PNG | `PngDecoder`（纯 PHP） | 输出**逐字节确定** ⇒ golden / 单测可复现，不依赖系统组件 |
 * | 其它 | WIC | 纯 PHP 解 JPEG 要 100~300ms 且不支持渐进式 |
 * | PNG 但 `PngDecoder` 解不出（隔行 / 罕见位深） | **回退到 WIC** | 宁可慢一点也别显示占位图 |
 *
 * ★ 分派依据是**文件签名**不是扩展名：`a.png` 实际是 jpg 的情况在真实用户目录里
 *   太常见了（重命名、下载器改后缀）。签名判错只会多走一次 WIC，代价可忽略。
 *
 * ## 解释器里没有这个能力
 *
 * 单测 / 工具跑在 `php.exe` 里，没有 `drift_win32` 扩展 ⇒ `available()` 是 `false`。
 * 那时 `decode()` 直接返回 `null`，**不报错**（`PngDecoder` 那条路照常工作）。
 * 判据要测 WIC 就必须在 AOT 产物里跑（T2），这与本仓"真机判据"的分级一致。
 *
 * @api
 */
final class ImageDecoder
{
    /** 壳函数名（只有 AOT 产物 / 加载了 `drift_win32` 的进程里才有） */
    private const FN = 'drift_image_decode';

    /**
     * 本进程有没有 WIC 解码能力。
     *
     * ★ 用 `function_exists` 而不是"平台是不是 Windows"：`Windows` 上跑
     *   `php.exe`（单测 / lint / 探针）**同样没有**这个函数 —— 那是扩展没加载，
     *   与平台无关。按平台判会让单测走进一条必然失败的路径。
     */
    public static function available(): bool
    {
        return \function_exists(self::FN);
    }

    /**
     * 解码一张图片。失败 ⇒ `null`（附 `DIAGNOSTIC`，不抛）。
     *
     * ★ **不抛**：解不出图是**预期内**的结果（用户可能选了个 `.psd` / 文件损坏 /
     *   超大图）。调用方（`ImageLoader`）的降级是"1×1 透明占位"，与"文件找不到"
     *   走同一条路 —— 两种失败对界面是同一种结果，不该有两种处理。
     *
     * @param string $path **已经解析过的绝对路径**（`ImageLoader::resolve()` 的产物）
     */
    public static function decode(string $path): ?ImageHandle
    {
        if (!self::available()) {
            return null;
        }

        // ★ 先落到局部变量再调：AOT 对"字符串函数名的动态调用"没有先例，
        //   而 `\drift_image_decode(...)` 直接写会被静态编译（stub 里有声明，
        //   所以能编过）—— 那正是我们要的：编译期就有签名检查。
        $raw = \drift_image_decode($path);
        if (!\is_array($raw)) {
            return null;
        }

        $w = $raw['w'] ?? 0;
        $h = $raw['h'] ?? 0;
        $rgba = $raw['rgba'] ?? null;

        if (!\is_int($w) || !\is_int($h) || $w <= 0 || $h <= 0 || !\is_string($rgba)) {
            Errors::report('ImageDecoder：壳返回的形状不对（' . $path . '）', Errors::DIAGNOSTIC);

            return null;
        }

        // ★ 长度校验不能省：`ImageHandle::fromRgba()` 按 `w * h * 4` 读，
        //   短一截就是越界读（PHP 里是 warning + 垃圾像素，画出来是彩色雪花）。
        //   壳已经判过溢出边界，这里判"它说的和它给的是一回事"。
        $need = $w * $h * 4;
        if (\strlen($rgba) !== $need) {
            Errors::report(
                'ImageDecoder：像素长度不符（' . $path . '：' . \strlen($rgba) . ' ≠ ' . $need . '）',
                Errors::DIAGNOSTIC,
            );

            return null;
        }

        return ImageHandle::fromRgba($rgba, $w, $h);
    }
}
