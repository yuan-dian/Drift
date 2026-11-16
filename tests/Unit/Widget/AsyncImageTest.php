<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\App\FetchImageJob;
use Drift\App\ImageAssets;
use Drift\Dev\Tests\Support\RecordingTaskHost;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Layout\RenderImage;
use Drift\Rendering\ImageLoader;
use Drift\Widget\Image;

/**
 * `Image` 有态路径：`asset($src)` 一个入口，本地同步解码、网络自驱下载。
 *
 * ★ 不走 `createRenderObject()` 兼容口 —— 这里挂载真 Element，
 *   验证 `initState()` 里那条「来源判定 → 磁盘命中 → 提交核心 Job」的链。
 */
final class AsyncImageTest extends SmokeTestCase
{
    private RecordingTaskHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        ImageLoader::clearCache();
        $this->host = new RecordingTaskHost([FetchImageJob::class => '/tmp/does-not-exist.png']);
        ImageAssets::install($this->host);
    }

    protected function tearDown(): void
    {
        ImageAssets::reset();
        ImageLoader::clearCache();

        parent::tearDown();
    }

    public function testLocalSourceGoesThroughLoaderWithoutJob(): void
    {
        $root = Image::make()->asset('demo/strip.png')->width(48.0)->createElement();
        $root->mount(null, smokeContext());

        check('本地图 · 挂载即得 RenderImage', $root->effectiveBox() instanceof RenderImage);
        check('本地图 · 不提交下载 Job', $this->host->ofClass(FetchImageJob::class) === []);
        $this->flushChecks();
    }

    public function testNetworkSourceSubmitsCoreJob(): void
    {
        $root = Image::make()->asset('https://cdn.example.com/a.png')->width(48.0)->createElement();
        $root->mount(null, smokeContext());

        $jobs = $this->host->ofClass(FetchImageJob::class);
        check('网络图 · 提交一条核心下载任务', \count($jobs) === 1, 'count=' . \count($jobs));
        check(
            '网络图 · 任务收到原始 URL（参数不被改写）',
            \count($jobs) === 1 && $jobs[0]->args()['url'] === 'https://cdn.example.com/a.png',
        );
        $this->flushChecks();
    }

    public function testDiskCacheHitSkipsJob(): void
    {
        $url = 'https://cdn.example.com/cached.png';
        $source = ImageLoader::resolve('demo/strip.png');
        check('夹具缺图 ⇒ 本用例空转', $source !== null);
        $this->flushChecks();
        if ($source === null) {
            return;
        }

        $cached = FetchImageJob::cachePath($url, \sys_get_temp_dir());
        \copy($source, $cached);
        try {
            $root = Image::make()->asset($url)->width(48.0)->createElement();
            $root->mount(null, smokeContext());

            check(
                '磁盘命中 · 不再提交 Job（否则每次都重新下载）',
                $this->host->ofClass(FetchImageJob::class) === [],
            );
            check('磁盘命中 · 同步拿到真图而非占位', $root->effectiveBox() instanceof RenderImage);
        } finally {
            @\unlink($cached);
        }
        $this->flushChecks();
    }

    public function testCacheKeyCallbackReachesJob(): void
    {
        ImageAssets::install($this->host, 'C:\\cache', static fn(string $url): string => 'stable-id');
        $root = Image::make()->asset('https://cdn.example.com/a.png?sig=abc')->width(48.0)->createElement();
        $root->mount(null, smokeContext());

        $jobs = $this->host->ofClass(FetchImageJob::class);
        check(
            '网络图 · 稳定键随 Job 参数递给 worker（worker 不用注册同一个回调）',
            \count($jobs) === 1 && ($jobs[0]->args()['cacheKey'] ?? '') === 'stable-id',
            'jobs=' . \count($jobs),
        );
        $this->flushChecks();
    }

    public function testUndecodableCacheIsRemovedForRedownload(): void
    {
        $url = 'https://cdn.example.com/broken.png';
        $cached = FetchImageJob::cachePath($url, \sys_get_temp_dir());
        \file_put_contents($cached, \str_repeat('x', 64)); // 够长，但解不出

        try {
            $source = ImageAssets::current();
            check('损坏缓存 · 端口已装配', $source !== null);
            if ($source !== null) {
                check('损坏缓存 · 解不出时返回 null', $source->cached($url, 48) === null);
                check('损坏缓存 · 文件被删，下一轮能重新下载', !\is_file($cached));
            }
        } finally {
            @\unlink($cached);
        }
        $this->flushChecks();
    }

    public function testUnknownSchemeIsRejectedWithoutJob(): void
    {
        $root = Image::make()->asset('data:image/png;base64,AAAA')->width(48.0)->createElement();
        $root->mount(null, smokeContext());

        check('未知 scheme · 不当本地路径发出去', $this->host->ofClass(FetchImageJob::class) === []);
        check('未知 scheme · 仍产出占位盒（不崩）', $root->effectiveBox() instanceof RenderImage);
        $this->flushChecks();
    }

    public function testMissingServiceFallsBackToPlaceholder(): void
    {
        ImageAssets::install(null);
        $root = Image::make()->asset('https://cdn.example.com/a.png')->width(48.0)->createElement();
        $root->mount(null, smokeContext());

        check('没装配服务 · 降级为占位而不是崩', $root->effectiveBox() instanceof RenderImage);
        $this->flushChecks();
    }
}
