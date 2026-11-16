<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Rendering\ImageLoader;

/**
 * `ImageLoader` 的两条契约：**候选目录**与**绝对路径原样**。
 *
 * ★ 缓存是静态的（进程级）⇒ 每条用例前后都要清，否则"第二次命中缓存"那条
 *   会因为前一个文件已经解过而假绿（cacheSize 从 0 变 1 还是从 1 变 1 分不清）。
 */
final class ImageLoaderTest extends SmokeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ImageLoader::clearCache();
    }

    protected function tearDown(): void
    {
        ImageLoader::setCacheLimits(48 * 1024 * 1024, 256);
        ImageLoader::clearCache();
        parent::tearDown();
    }

    public function testRelativePathResolvesThroughCandidatesAndCaches(): void
    {
        $a = ImageLoader::load('demo/quad.png');
        $b = ImageLoader::load('demo/quad.png');
        check(
            'ImageLoader · 相对路径按候选目录解析，第二次命中缓存（同一实例，不重复解码）',
            $a !== null && $a === $b && ImageLoader::cacheSize() === 1,
            sprintf('a=%s b=%s cache=%d', $a === null ? 'null' : 'ok', $b === null ? 'null' : 'ok', ImageLoader::cacheSize()),
        );
    }

    public function testAbsolutePathIsUsedAsIs(): void
    {
        $absolute = repoRoot() . '/assets/demo/quad.png';
        $resolved = ImageLoader::resolve($absolute);
        check(
            'ImageLoader · 绝对路径原样使用（不往候选目录上拼）',
            $resolved !== null && realpath($resolved) === realpath($absolute),
            'resolved=' . ($resolved ?? 'null'),
        );
    }

    /**
     * 两条解码路径的**分派**（2026-11-02）。
     *
     * ★ 为什么分派依据必须是**文件签名**而不是扩展名：`a.png` 实际是 jpg 在真实
     *   用户目录里太常见（重命名、下载器改后缀）。按扩展名分派会让一张 JPEG 被
     *   送进 `PngDecoder` ⇒ 解不出 ⇒ 显示占位图，而**没有任何东西报错**
     *   （`PngDecoder` 只留一条 DIAGNOSTIC，界面上就是一张看不见的图）。
     */
    public function testPngSignatureDecidesTheDecoder(): void
    {
        // ① 真 PNG ⇒ 走纯 PHP 那条（确定性），解得出来
        $png = ImageLoader::load('demo/quad.png');
        check(
            'ImageLoader · 真 PNG 解得出来（走 `PngDecoder`，逐字节确定）',
            $png !== null && $png->width > 0,
            $png === null ? 'null' : $png->width . 'x' . $png->height,
        );

        // ② 一个**扩展名是 .png、内容不是 PNG** 的文件：签名说了算 ⇒ 不会被
        //    `PngDecoder` 认领（解释器里 WIC 不可用 ⇒ 最终 null，这是预期的）
        $tmp = (string) \tempnam(\sys_get_temp_dir(), 'drift-fake-');
        \file_put_contents($tmp, "\xFF\xD8\xFF\xE0" . \str_repeat('J', 64)); // JPEG 魔数 + 垃圾
        $fakePng = $tmp . '.png';
        \rename($tmp, $fakePng);

        $loaded = ImageLoader::load($fakePng);
        check(
            'ImageLoader · 扩展名是 .png 但内容是 JPEG ⇒ 不送进 `PngDecoder`（解释器里 WIC 不可用 ⇒ null）',
            $loaded === null,
            $loaded === null ? 'null（预期）' : '意外解出 ' . $loaded->width . 'x' . $loaded->height,
        );

        @\unlink($fakePng);

        check(
            'ImageLoader · 解释器里 `ImageDecoder::available()` 是 false（壳没加载，不是平台问题）',
            !\Drift\Rendering\ImageDecoder::available(),
            'available=' . (\Drift\Rendering\ImageDecoder::available() ? 'true' : 'false'),
        );
    }

    /**
     * LRU 与 FIFO 的分别：中间命中 A 之后再把 C 装进来，该被挤掉的是 B。
     *
     * ★ 判据用字节数而不是"再取一次看还在不在" —— 后者在 A 被挤掉又重新解码时
     *   依旧是绿着的，量具本身会骗人。quad 与它的副本字节相同、strip 不同，
     *   留谁走谁的字节数不一样。
     */
    public function testLruKeepsRecentlyHitEntry(): void
    {
        $quad = ImageLoader::resolve('demo/quad.png');
        $strip = ImageLoader::resolve('demo/strip.png');
        check('LRU 判据 · 两张夹具都在', $quad !== null && $strip !== null);
        $this->flushChecks();
        if ($quad === null || $strip === null) {
            return;
        }

        $quadHandle = ImageLoader::load($quad, 0);
        $stripHandle = ImageLoader::load($strip, 0);
        $quadBytes = $quadHandle === null ? 0 : $quadHandle->width * $quadHandle->height * 4;
        $stripBytes = $stripHandle === null ? 0 : $stripHandle->width * $stripHandle->height * 4;
        check('LRU 判据 · 两张夹具字节数不同（否则分不出留的是谁）', $quadBytes > 0 && $stripBytes > 0 && $quadBytes !== $stripBytes);
        ImageLoader::clearCache();

        $copy = (string) \tempnam(\sys_get_temp_dir(), 'drift-lru-');
        \copy($quad, $copy);

        try {
            ImageLoader::setCacheLimits(0, 2); // 只按条目，字节不限
            ImageLoader::load($quad, 0);       // A
            ImageLoader::load($strip, 0);      // B
            ImageLoader::load($quad, 0);       // 命中 A ⇒ A 成最近使用
            $before = ImageLoader::cacheBytes();
            ImageLoader::load($copy, 0);       // C ⇒ 该淘汰 B

            check(
                'LRU · 命中过的条目留在缓存（FIFO 会先挤掉它，字节数会更低）',
                ImageLoader::cacheBytes() === $quadBytes * 2,
                'before=' . $before . ' after=' . ImageLoader::cacheBytes() . ' expected=' . ($quadBytes * 2),
            );
            check('LRU · 只淘汰一条', ImageLoader::cacheEvictions() === 1, 'evictions=' . ImageLoader::cacheEvictions());
            check('LRU · 条目数守在上限', ImageLoader::cacheSize() === 2, 'size=' . ImageLoader::cacheSize());
        } finally {
            @\unlink($copy);
        }
        $this->flushChecks();
    }

    /** 字节上限与单张超限：超限时返回真图但不占缓存，且只报一次诊断。 */
    public function testByteLimitEvictsAndOversizedStaysOut(): void
    {
        $quad = ImageLoader::resolve('demo/quad.png');
        $strip = ImageLoader::resolve('demo/strip.png');
        check('字节上限判据 · 两张夹具都在', $quad !== null && $strip !== null);
        $this->flushChecks();
        if ($quad === null || $strip === null) {
            return;
        }

        $quadHandle = ImageLoader::load($quad, 0);
        $stripHandle = ImageLoader::load($strip, 0);
        $quadBytes = $quadHandle === null ? 0 : $quadHandle->width * $quadHandle->height * 4;
        $stripBytes = $stripHandle === null ? 0 : $stripHandle->width * $stripHandle->height * 4;
        ImageLoader::clearCache();

        ImageLoader::setCacheLimits($quadBytes + $stripBytes - 1, 0);
        ImageLoader::load($quad, 0);
        ImageLoader::load($strip, 0);
        check(
            '字节上限 · 放不下时淘汰最久未用，留下的字节数与剩下那张一致',
            ImageLoader::cacheSize() === 1 && ImageLoader::cacheBytes() === $stripBytes,
            'size=' . ImageLoader::cacheSize() . ' bytes=' . ImageLoader::cacheBytes() . ' expected=' . $stripBytes,
        );
        check('字节上限 · 淘汰计数记下来', ImageLoader::cacheEvictions() === 1, 'evictions=' . ImageLoader::cacheEvictions());

        ImageLoader::setCacheLimits($quadBytes - 1, 0);
        ImageLoader::clearCache();
        $loaded = ImageLoader::load($quad, 0);
        check('单张超限 · 仍返回真图（不能因此降级成占位）', $loaded !== null && $loaded->width > 0);
        check(
            '单张超限 · 不进缓存（否则一张图就能顶穿上限）',
            ImageLoader::cacheSize() === 0 && ImageLoader::cacheBytes() === 0,
            'size=' . ImageLoader::cacheSize() . ' bytes=' . ImageLoader::cacheBytes(),
        );
        $this->flushChecks();
    }
}
