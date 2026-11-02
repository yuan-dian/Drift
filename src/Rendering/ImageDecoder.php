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
 * ## 两条路怎么分工（**2026-11-02 定序：WIC 优先**）
 *
 * | 输入 | 走谁 | 为什么 |
 * |---|---|---|
 * | **有 WIC 时（AOT 生产）** | **本类（WIC）** | 原生解码，百毫秒级；几乎不动 PHP 堆 |
 * | 没有 WIC（`php.exe` 里跑单测 / golden） | `PngDecoder`（纯 PHP） | 输出**逐字节确定** ⇒ golden 可复现 |
 * | PNG 但 WIC 解不出 | `PngDecoder` 兜底 | 宁可慢一点也别显示占位图 |
 *
 * ★★ **为什么不是"PNG 一律先走 PngDecoder"**：那个顺序在真机上崩过一次。
 *   一张 16 位色深 1792×2390 的服务端结果图，纯 PHP 解码实测 **6.6 秒 / 峰值
 *   92MB**，而它跑在 UI 线程的 `createRenderObject()` 里；再叠上 `ImageLoader`
 *   缓存里每张 17MB 的 RGBA，多张就顶穿 128M 默认上限 ⇒ OOM ⇒ `zend_bailout`
 *   打断消息循环 ⇒ `main` 返回 ⇒ 崩在 `php_request_shutdown`。
 *   表现是"任务完成后程序自己消失、图没显示"，栈却指向 shutdown，
 *   **看起来与图片毫无关系**。完整推理见 `ImageLoader::load()` 的分派表注释。
 *
 * ★ "逐字节确定"服务的是 **golden 测试**，不是生产：golden 跑在 `php.exe`
 *   （没有 WIC）⇒ 恒定走 `PngDecoder`，确定性一点没丢。生产里没有任何判据
 *   比较过解码后的字节，却一直在付纯 PHP 的代价。
 *
 * ★ 分派依据是**文件签名**不是扩展名：`a.png` 实际是 jpg 的情况在真实用户目录里
 *   太常见了（重命名、下载器改后缀）。签名判错只会多走一次 WIC，代价可忽略。
 *
 * ## 输出契约：**恒为 32bppRGBA**（行优先，每像素 4 字节）
 *
 * 这不只是"本类这么打算"，是壳里 `decodeImageToRgba()` 逐个像素保证的。
 * ★ 它曾经在这个类**看不见的地方**破掉过一次：`maxWidth` 那条路上，
 *   `IWICBitmapScaler` 不接受 `32bppRGBA`，会自己转成 `32bppBGRA` 再缩 ——
 *   于是"缩过的图"通道序反了，红色变蓝、暖白变冷蓝，而它不报错、长度也对。
 *   现在的做法是在**取像素之前**用 `GetPixelFormat()` 复核、不符就再转一次；
 *   判据是 `tools/decode-selftest`（色带图，期望值手写），进闸门的 `decode` 项 ——
 *   原来那六条"同一张图两个尺寸互相比"的判据对这个 bug **全绿**。
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
     * ★ `$maxWidth`（2026-11-02）：目标宽度上限，`0` = 原尺寸。原图更宽时
     *   **在解码期**等比缩小。传显示宽度而不是 0，是 `ImageLoader` 的默认行为 ——
     *   一张 1792×2390 的图按原尺寸解是 17MB，而预览区只有 560 宽。
     *   返回的 `ImageHandle` 尺寸就是**缩小后**的，布局按它的比例走，不受影响。
     *
     * @param string $path **已经解析过的绝对路径**（`ImageLoader::resolve()` 的产物）
     * @param int $maxWidth 目标宽度上限（像素）；`0` = 原尺寸
     */
    public static function decode(string $path, int $maxWidth = 0): ?ImageHandle
    {
        if (!self::available()) {
            return null;
        }

        // ★ 先落到局部变量再调：AOT 对"字符串函数名的动态调用"没有先例，
        //   而 `\drift_image_decode(...)` 直接写会被静态编译（stub 里有声明，
        //   所以能编过）—— 那正是我们要的：编译期就有签名检查。
        $raw = \drift_image_decode($path, $maxWidth > 0 ? $maxWidth : 0);
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
