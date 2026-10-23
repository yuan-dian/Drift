<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 纯 PHP 的 PNG 解码（W8-1 资源管线的入口）。
 *
 * ```php
 * $image = PngDecoder::decodeFile('assets/demo/logo.png'); // ?ImageHandle
 * ```
 *
 * ## 为什么是纯 PHP（而不是 GD / 原生 codec）
 *
 * 本机没有 GD、没有 PIL（`php -m` 里无 `gd`，pip 无索引装不了 Pillow），而
 * `ImageHandle` 要的是**内存 RGBA**。所以这里自带一份"zlib inflate + 扫描线
 * 反滤波"的实现：`zlib_decode()` 由 phpx 提供（`vendor/swoole/phpx/src/func/
 * zlib.cc`），剩下全是整数运算。
 *
 * ★ 它与 `SkiaCanvas` **共用同一个解码结果**：`drawImage` 收的是 `ImageHandle`
 *   （原始 RGBA），Skia 桥再把它包成 `SkImage`。⇒ 两个后端看到的像素**同源**，
 *   "解码差异"这一类 bug 结构上不可能出现。
 *
 * ## 支持范围（够用即止，超范围响亮失败）
 *
 * | 项 | 支持 |
 * |---|---|
 * | 位深 | 8 / 16（16 取高字节）；色彩类型 0/3 另支持 1/2/4 |
 * | 色彩类型 | 0 灰度 / 2 真彩 / 3 调色板 / 4 灰度+alpha / 6 真彩+alpha |
 * | tRNS | 三种都支持（调色板 alpha 表 / 灰度键 / RGB 键） |
 * | 滤波 | 0–4 全部（None / Sub / Up / Average / Paeth） |
 * | 隔行 | **不支持**（Adam7）—— 报告 + 返回 null |
 *
 * ★ **失败一律 `DIAGNOSTIC` + `null`，不 Fatal**：一张图坏了不该让应用崩
 *   （与 `FontLocator` 找不到字体同一档）。但**必须留痕** —— 静默返回 null
 *   会让"图没显示"变成无头案。调用方拿 null 自己决定降级形态。
 *
 * ★ **不校验 chunk CRC**：CRC 只防传输损坏，而这里的数据源是本地文件；
 *   真正的结构损坏（截断 / 尺寸不符 / zlib 失败）由下面三道闸门抓 —— 它们
 *   已经覆盖"解出来会静默错位"的全部情形。
 *
 * ★★ **不用 `$x .= …` 累加字符串**（§2.4 纪律 2：增长累加器在多字节下堆损坏，
 *   且崩在无关代码）—— 一律 `list<string>` 收集 + 一次 `implode`。这也是为什么
 *   扫描线在内部是 `list<int>`（样本）而不是字节串。
 *
 * @api
 */
final class PngDecoder
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * 从文件解码。文件不存在 / 读不出 / 内容不是 PNG ⇒ `null`（附诊断）。
     */
    public static function decodeFile(string $path): ?ImageHandle
    {
        if (!is_file($path)) {
            return self::fail($path, '文件不存在');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return self::fail($path, '读不出内容（空文件？权限？）');
        }

        return self::decode($bytes, $path);
    }

    /**
     * 从字节串解码。`$origin` 只用于诊断消息（是文件路径还是 `<memory>`）。
     */
    public static function decode(string $bytes, string $origin = '<memory>'): ?ImageHandle
    {
        $total = strlen($bytes);
        if ($total < 8) {
            return self::fail($origin, '太短，连签名都不够');
        }
        if (substr($bytes, 0, 8) !== self::SIGNATURE) {
            return self::fail($origin, '不是 PNG（签名不符）');
        }

        $width = 0;
        $height = 0;
        $depth = 0;
        $colorType = 0;
        $interlace = 0;
        $palette = '';
        $trns = '';
        $idatParts = [];
        $seenHeader = false;

        $pos = 8;
        while ($pos + 8 <= $total) {
            $len = self::u32($bytes, $pos);
            $type = substr($bytes, $pos + 4, 4);
            $body = $pos + 8;
            if ($len < 0 || $body + $len > $total) {
                return self::fail($origin, 'chunk 越界（文件被截断？）');
            }

            if ($type === 'IHDR') {
                if ($len < 13) {
                    return self::fail($origin, 'IHDR 不足 13 字节');
                }
                $width = self::u32($bytes, $body);
                $height = self::u32($bytes, $body + 4);
                $depth = ord($bytes[$body + 8]);
                $colorType = ord($bytes[$body + 9]);
                $interlace = ord($bytes[$body + 12]);
                $seenHeader = true;
            } elseif ($type === 'PLTE') {
                $palette = substr($bytes, $body, $len);
            } elseif ($type === 'tRNS') {
                $trns = substr($bytes, $body, $len);
            } elseif ($type === 'IDAT') {
                $idatParts[] = substr($bytes, $body, $len);
            } elseif ($type === 'IEND') {
                break;
            }

            $pos = $body + $len + 4; // 跳过 CRC
        }

        if (!$seenHeader) {
            return self::fail($origin, '没有 IHDR');
        }
        if ($width <= 0 || $height <= 0) {
            return self::fail($origin, sprintf('尺寸非法 %d×%d', $width, $height));
        }
        if ($interlace !== 0) {
            return self::fail($origin, '隔行 PNG（Adam7）不支持，请用非隔行导出');
        }
        if (!self::depthAllowed($colorType, $depth)) {
            return self::fail($origin, sprintf('色彩类型 %d 配位深 %d 不支持', $colorType, $depth));
        }
        if ($colorType === 3 && $palette === '') {
            return self::fail($origin, '调色板图缺 PLTE');
        }
        if ($idatParts === []) {
            return self::fail($origin, '没有 IDAT');
        }

        $channels = self::channelsFor($colorType);
        $stride = intdiv($width * $channels * $depth + 7, 8);

        $raw = zlib_decode(implode('', $idatParts));
        $rawStr = '';
        if (is_string($raw)) {
            $rawStr = $raw;
        }
        if (strlen($rawStr) !== $height * ($stride + 1)) {
            return self::fail($origin, sprintf(
                'IDAT 解压长度不符：期望 %d，得到 %d（zlib 失败或尺寸不符）',
                $height * ($stride + 1),
                strlen($rawStr),
            ));
        }

        // 反滤波：bpp = 一个完整像素占几字节（亚字节位深时向上取 1）
        $bpp = intdiv($channels * $depth + 7, 8);
        if ($bpp < 1) {
            $bpp = 1;
        }

        $outRows = [];
        $prev = [];
        for ($i = 0; $i < $stride; $i++) {
            $prev[$i] = 0;
        }
        $p = 0;
        for ($y = 0; $y < $height; $y++) {
            $filter = ord($rawStr[$p]);
            $p++;

            $line = [];
            for ($i = 0; $i < $stride; $i++) {
                $line[$i] = ord($rawStr[$p + $i]);
            }
            $p += $stride;

            for ($i = 0; $i < $stride; $i++) {
                $a = 0;
                if ($i >= $bpp) {
                    $a = $line[$i - $bpp];
                }
                $b = $prev[$i];
                $c = 0;
                if ($i >= $bpp) {
                    $c = $prev[$i - $bpp];
                }

                if ($filter === 0) {
                    // None：原样
                } elseif ($filter === 1) {
                    $line[$i] = ($line[$i] + $a) & 0xFF;
                } elseif ($filter === 2) {
                    $line[$i] = ($line[$i] + $b) & 0xFF;
                } elseif ($filter === 3) {
                    $line[$i] = ($line[$i] + (($a + $b) >> 1)) & 0xFF;
                } elseif ($filter === 4) {
                    // Paeth：选 a/b/c 里离 (a+b-c) 最近的那个
                    $pp = $a + $b - $c;
                    $pa = $pp - $a;
                    if ($pa < 0) {
                        $pa = -$pa;
                    }
                    $pb = $pp - $b;
                    if ($pb < 0) {
                        $pb = -$pb;
                    }
                    $pc = $pp - $c;
                    if ($pc < 0) {
                        $pc = -$pc;
                    }
                    $pred = $c;
                    if ($pa <= $pb && $pa <= $pc) {
                        $pred = $a;
                    } elseif ($pb <= $pc) {
                        $pred = $b;
                    }
                    $line[$i] = ($line[$i] + $pred) & 0xFF;
                } else {
                    return self::fail($origin, sprintf('未知滤波类型 %d（第 %d 行）', $filter, $y));
                }
            }

            $outRows[] = self::expand($line, $width, $depth, $colorType, $palette, $trns);
            $prev = $line;
        }

        return ImageHandle::fromRgba(implode('', $outRows), $width, $height);
    }

    /** 每个色彩类型有几个通道（1/2/3/4） */
    private static function channelsFor(int $colorType): int
    {
        if ($colorType === 2) {
            return 3;
        }
        if ($colorType === 3) {
            return 1;
        }
        if ($colorType === 4) {
            return 2;
        }
        if ($colorType === 6) {
            return 4;
        }

        return 1; // 0 灰度
    }

    /** 位深合法性（PNG 规范表 11.1；只认我们真支持的那些） */
    private static function depthAllowed(int $colorType, int $depth): bool
    {
        if ($colorType === 0) {
            return $depth === 1 || $depth === 2 || $depth === 4 || $depth === 8 || $depth === 16;
        }
        if ($colorType === 3) {
            return $depth === 1 || $depth === 2 || $depth === 4 || $depth === 8;
        }
        if ($colorType === 2 || $colorType === 4 || $colorType === 6) {
            return $depth === 8 || $depth === 16;
        }

        return false;
    }

    /** 大端 4 字节无符号（用 ord 手拼，不用 unpack：数组返回值的类型推断在 AOT 下更啰嗦） */
    private static function u32(string $bytes, int $pos): int
    {
        return (ord($bytes[$pos]) << 24)
            | (ord($bytes[$pos + 1]) << 16)
            | (ord($bytes[$pos + 2]) << 8)
            | ord($bytes[$pos + 3]);
    }

    /** 从亚字节位深里取第 `$index` 个样本（1/2/4 位，MSB 优先） */
    private static function bits(array $line, int $index, int $depth): int
    {
        $bitPos = $index * $depth;
        $byte = $line[$bitPos >> 3];
        $shift = 8 - $depth - ($bitPos & 7);

        return ($byte >> $shift) & ((1 << $depth) - 1);
    }

    /** 亚字节灰度样本拉伸到 0..255（1 位 → 0/255，2 位 → ×85，4 位 → ×17） */
    private static function scaleTo8(int $value, int $depth): int
    {
        if ($depth === 1) {
            return $value * 255;
        }
        if ($depth === 2) {
            return $value * 85;
        }
        if ($depth === 4) {
            return $value * 17;
        }

        return $value;
    }

    /**
     * 一行原始样本 → 一行 RGBA（`$width * 4` 字节）。
     *
     * ★ 16 位**取高字节**：`ImageHandle` 是 8 位/通道（与两个后端的 `toRgba()`
     *   同格式）。高字节 = 值 / 257 的近似（差 1/256 的舍入），对显示够用；
     *   要真 16 位得换 `ImageHandle` 的像素格式，那是另一件事。
     *
     * @param list<int> $line 已反滤波的样本（长度 = stride）
     */
    private static function expand(
        array $line,
        int $width,
        int $depth,
        int $colorType,
        string $palette,
        string $trns,
    ): string {
        $parts = [];

        if ($colorType === 3) {
            $count = intdiv(strlen($palette), 3);
            $tlen = strlen($trns);
            for ($x = 0; $x < $width; $x++) {
                $idx = 0;
                if ($depth === 8) {
                    $idx = $line[$x];
                } else {
                    $idx = self::bits($line, $x, $depth);
                }
                $r = 0;
                $g = 0;
                $b = 0;
                $a = 255;
                if ($idx < $count) {
                    $r = ord($palette[$idx * 3]);
                    $g = ord($palette[$idx * 3 + 1]);
                    $b = ord($palette[$idx * 3 + 2]);
                }
                if ($idx < $tlen) {
                    $a = ord($trns[$idx]);
                }
                $parts[] = chr($r) . chr($g) . chr($b) . chr($a);
            }

            return implode('', $parts);
        }

        $step = 1;
        if ($depth === 16) {
            $step = 2;
        }

        if ($colorType === 0) {
            // tRNS 灰度键：PNG 里是 2 字节；16 位时我们只比高字节（见 expand 文档）
            $key = -1;
            if (strlen($trns) >= 2) {
                $key = ord($trns[0]);
            }
            for ($x = 0; $x < $width; $x++) {
                $gray = 0;
                if ($depth < 8) {
                    $gray = self::scaleTo8(self::bits($line, $x, $depth), $depth);
                } else {
                    $gray = $line[$x * $step];
                }
                $a = 255;
                if ($key >= 0 && $gray === $key) {
                    $a = 0;
                }
                $parts[] = chr($gray) . chr($gray) . chr($gray) . chr($a);
            }

            return implode('', $parts);
        }

        if ($colorType === 4) {
            for ($x = 0; $x < $width; $x++) {
                $o = $x * 2 * $step;
                $gray = $line[$o];
                $a = $line[$o + $step];
                $parts[] = chr($gray) . chr($gray) . chr($gray) . chr($a);
            }

            return implode('', $parts);
        }

        if ($colorType === 2) {
            $kr = -1;
            $kg = -1;
            $kb = -1;
            if (strlen($trns) >= 6) {
                $kr = ord($trns[0]);
                $kg = ord($trns[2]);
                $kb = ord($trns[4]);
            }
            for ($x = 0; $x < $width; $x++) {
                $o = $x * 3 * $step;
                $r = $line[$o];
                $g = $line[$o + $step];
                $b = $line[$o + 2 * $step];
                $a = 255;
                if ($kr === $r && $kg === $g && $kb === $b) {
                    $a = 0;
                }
                $parts[] = chr($r) . chr($g) . chr($b) . chr($a);
            }

            return implode('', $parts);
        }

        // colorType 6（真彩 + alpha）：直取
        for ($x = 0; $x < $width; $x++) {
            $o = $x * 4 * $step;
            $parts[] = chr($line[$o]) . chr($line[$o + $step]) . chr($line[$o + 2 * $step]) . chr($line[$o + 3 * $step]);
        }

        return implode('', $parts);
    }

    private static function fail(string $origin, string $why): ?ImageHandle
    {
        // ★ DIAGNOSTIC 而不是 Fatal：坏图不该让应用崩（与找不到字体同档），
        //   但必须留痕 —— 否则"图没显示"变成无头案。
        Errors::report(sprintf('PngDecoder：%s —— %s', $why, $origin), Errors::DIAGNOSTIC);

        return null;
    }
}
