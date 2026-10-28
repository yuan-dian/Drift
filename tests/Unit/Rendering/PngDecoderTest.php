<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Errors;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\PngDecoder;

/**
 * `PngDecoder` —— 从字节流到 RGBA 的**全部规范分支**。
 *
 * ★ 判据全部对"按规范手算出来的 RGBA"，不对"再编回去的字节"：编解码共用一份
 *   实现时同一个 bug 会两边一起错（量具自检纪律）。本文件里的编码器只负责
 *   **造输入**，输出侧一律断言解析值。
 * ★ 造图器跟着被测量走：它只服务这一个被测对象，所以留在这里当私有方法，
 *   不进 `tests/Support/` —— 别处没有第二个使用者。
 */
final class PngDecoderTest extends SmokeTestCase
{
    public function testAllFiveFiltersDecodeToOriginalSamples(): void
    {
        // ① 同一份 2×2 RGBA 样本，换滤波类型编，解出来必须逐像素相同
        //    相邻像素刻意不相等 ⇒ Sub/Up/Average/Paeth 的预测器真的被用到
        $ok = true;
        $note = [];
        foreach ([0, 1, 2, 3, 4] as $filter) {
            $rows = [
                [10, 20, 30, 255, 40, 50, 60, 255],
                [70, 80, 90, 255, 100, 110, 120, 255],
            ];
            $image = PngDecoder::decode($this->png(2, 2, 6, 8, $rows, $filter), 'filter-' . $filter);
            $good = $image !== null
                && $this->px($image, 0, 0) === '10,20,30,255'
                && $this->px($image, 1, 0) === '40,50,60,255'
                && $this->px($image, 0, 1) === '70,80,90,255'
                && $this->px($image, 1, 1) === '100,110,120,255';
            if (!$good) {
                $ok = false;
            }
            $note[] = 'f' . $filter . ($good ? ' ok' : ' FAIL');
        }
        check(
            'PNG · 五种滤波（None/Sub/Up/Average/Paeth）解码 = 原始样本',
            $ok,
            implode(' / ', $note),
        );
    }

    public function testColourTypesAndBitDepths(): void
    {
        // ② 色彩类型 2（真彩无 alpha）⇒ alpha 补 255
        $rgb = PngDecoder::decode($this->png(1, 2, 2, 8, [[200, 100, 50], [10, 20, 30]]), 'rgb');
        check(
            'PNG · 色彩类型 2（RGB）⇒ alpha 补 255',
            $rgb !== null && $this->px($rgb, 0, 0) === '200,100,50,255' && $this->px($rgb, 0, 1) === '10,20,30,255',
            $rgb === null ? 'null' : $this->px($rgb, 0, 0) . ' / ' . $this->px($rgb, 0, 1),
        );

        // ③ 色彩类型 0（灰度）⇒ r=g=b
        $gray = PngDecoder::decode($this->png(1, 2, 0, 8, [[128], [255]]), 'gray');
        check(
            'PNG · 色彩类型 0（灰度 8 位）⇒ r=g=b',
            $gray !== null && $this->px($gray, 0, 0) === '128,128,128,255' && $this->px($gray, 0, 1) === '255,255,255,255',
            $gray === null ? 'null' : $this->px($gray, 0, 0) . ' / ' . $this->px($gray, 0, 1),
        );

        // ④ 色彩类型 3（调色板）+ tRNS：索引 0 全透、索引 1 半透、索引 2 不透明
        $plte = $this->chunk('PLTE', chr(10) . chr(20) . chr(30) . chr(40) . chr(50) . chr(60) . chr(70) . chr(80) . chr(90));
        $trns = $this->chunk('tRNS', chr(0) . chr(128));
        $pal = PngDecoder::decode($this->png(3, 1, 3, 8, [[0, 1, 2]], 0, $plte . $trns), 'palette');
        check(
            'PNG · 色彩类型 3（调色板）+ tRNS：索引色与 alpha 表都对',
            $pal !== null
                && $this->px($pal, 0, 0) === '10,20,30,0'
                && $this->px($pal, 1, 0) === '40,50,60,128'
                && $this->px($pal, 2, 0) === '70,80,90,255',
            $pal === null ? 'null' : $this->px($pal, 0, 0) . ' | ' . $this->px($pal, 1, 0) . ' | ' . $this->px($pal, 2, 0),
        );

        // ⑤ 16 位取高字节：0x12FF → 0x12
        $deep = PngDecoder::decode(
            $this->png(1, 1, 6, 16, [[0x12, 0xFF, 0x34, 0xFF, 0x56, 0xFF, 0xFF, 0xFF]]),
            'rgba16',
        );
        check(
            'PNG · 16 位取高字节（0x12FF→0x12）',
            $deep !== null && $this->px($deep, 0, 0) === '18,52,86,255',
            $deep === null ? 'null' : $this->px($deep, 0, 0),
        );

        // ⑥ 1 位灰度：位打包（MSB 优先），0 → 黑、1 → 白
        //    两个像素 = 1 字节：pixel0 在 bit7、pixel1 在 bit6 ⇒ [0,1] = 0b0100_0000 = 64
        $oneBit = PngDecoder::decode($this->png(2, 1, 0, 1, [[64]]), 'gray1');
        check(
            'PNG · 1 位灰度（MSB 打包）⇒ 0/1 拉伸成 0/255',
            $oneBit !== null && $this->px($oneBit, 0, 0) === '0,0,0,255' && $this->px($oneBit, 1, 0) === '255,255,255,255',
            $oneBit === null ? 'null' : $this->px($oneBit, 0, 0) . ' / ' . $this->px($oneBit, 1, 0),
        );
    }

    public function testRealAssetDecodesQuadrants(): void
    {
        // ⑦ 真资源文件：assets/demo/quad.png（四象限 + 中心白点）
        //    ★ 走 `repoRoot()` 而不是 `__DIR__`：测试的目录深度与 smoke 不同，
        //      且 cwd 不是契约（从哪个目录启 phpunit 都得能跑）。
        $image = PngDecoder::decodeFile(repoRoot() . '/assets/demo/quad.png');
        check(
            'PNG · 真文件 assets/demo/quad.png（64×64 四象限 + 中心白点）',
            $image !== null
                && $image->width === 64 && $image->height === 64
                && $this->px($image, 8, 8) === '220,40,40,255'
                && $this->px($image, 56, 8) === '40,170,80,255'
                && $this->px($image, 8, 56) === '40,90,210,255'
                && $this->px($image, 56, 56) === '235,190,40,255'
                && $this->px($image, 32, 32) === '255,255,255,255',
            $image === null ? 'null' : $this->px($image, 8, 8) . ' / ' . $this->px($image, 32, 32),
        );
    }

    public function testBadInputsReturnNullAndEachLeavesADiagnostic(): void
    {
        // ⑧ 反例：签名错 / 截断 / 隔行 / 位深非法 / 文件不存在 ⇒ null + **每条都留痕**
        //    ★ 判据是"差 5 条"，不是绝对值：诊断环是进程级的，别的用例也会写。
        $rows = [[1, 2, 3, 4, 5, 6, 7, 8], [9, 10, 11, 12, 13, 14, 15, 16]];
        $before = count(Errors::recentDiagnostics());
        $bad1 = PngDecoder::decode('not a png at all', 'bad-signature');
        $bad2 = PngDecoder::decode(substr($this->png(2, 2, 6, 8, $rows), 0, 20), 'truncated');
        $bad3 = PngDecoder::decode($this->png(1, 1, 6, 8, [[1, 2, 3, 4]], 0, '', 1), 'interlaced');
        $bad4 = PngDecoder::decode($this->png(1, 1, 2, 4, [[1, 2]]), 'bad-depth');
        $bad5 = PngDecoder::decodeFile(repoRoot() . '/assets/demo/definitely-missing.png');
        $delta = count(Errors::recentDiagnostics()) - $before;

        check(
            'PNG · 反例五条（签名错/截断/隔行/位深非法/文件不存在）⇒ 全部 null 且各留一条诊断',
            $bad1 === null && $bad2 === null && $bad3 === null && $bad4 === null && $bad5 === null
                && $delta === 5,
            sprintf('nulls=%s 诊断 +%d', implode(',', array_map(static fn(?ImageHandle $i): string => $i === null ? 'null' : 'ok', [$bad1, $bad2, $bad3, $bad4, $bad5])), $delta),
        );
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /**
     * 造一份 PNG 字节流。`$rows` = **原始样本**（每行长度 = stride），`$filter`
     * 对所有行统一（五种滤波各造一份就能把反滤波全走一遍）。
     */
    private function png(
        int $w,
        int $h,
        int $colorType,
        int $depth,
        array $rows,
        int $filter = 0,
        string $extra = '',
        int $interlace = 0,
    ): string {
        $channels = 1;
        if ($colorType === 2) {
            $channels = 3;
        } elseif ($colorType === 4) {
            $channels = 2;
        } elseif ($colorType === 6) {
            $channels = 4;
        }
        $stride = intdiv($w * $channels * $depth + 7, 8);
        $bpp = intdiv($channels * $depth + 7, 8);
        if ($bpp < 1) {
            $bpp = 1;
        }

        $raw = '';
        $prev = array_fill(0, $stride, 0);
        foreach ($rows as $line) {
            $raw .= chr($filter);
            for ($i = 0; $i < $stride; $i++) {
                $a = $i >= $bpp ? $line[$i - $bpp] : 0;
                $b = $prev[$i];
                $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                if ($filter === 0) {
                    $v = $line[$i];
                } elseif ($filter === 1) {
                    $v = $line[$i] - $a;
                } elseif ($filter === 2) {
                    $v = $line[$i] - $b;
                } elseif ($filter === 3) {
                    $v = $line[$i] - (($a + $b) >> 1);
                } else {
                    $p = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $pred = $c;
                    if ($pa <= $pb && $pa <= $pc) {
                        $pred = $a;
                    } elseif ($pb <= $pc) {
                        $pred = $b;
                    }
                    $v = $line[$i] - $pred;
                }
                $raw .= chr($v & 0xFF);
            }
            $prev = $line;
        }

        return "\x89PNG\r\n\x1a\n"
            . $this->chunk('IHDR', pack('NN', $w, $h) . chr($depth) . chr($colorType) . "\x00\x00" . chr($interlace))
            . $extra
            . $this->chunk('IDAT', gzcompress($raw, 9))
            . $this->chunk('IEND', '');
    }

    /** 读一个像素成 "r,g,b,a" 字符串（断言里好读） */
    private function px(ImageHandle $image, int $x, int $y): string
    {
        $s = $image->rgba();
        $o = ($y * $image->width + $x) * 4;

        return ord($s[$o]) . ',' . ord($s[$o + 1]) . ',' . ord($s[$o + 2]) . ',' . ord($s[$o + 3]);
    }
}
