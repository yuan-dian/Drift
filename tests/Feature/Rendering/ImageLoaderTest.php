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
}
