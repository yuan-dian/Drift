<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\AppScope;
use Drift\App\CoreJobs;
use Drift\App\FetchImageJob;
use Drift\App\ImageAssets;
use Drift\App\SyncTaskHost;
use Drift\Rendering\ImageLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 异步资源入口的三条缝：来源判定、缓存路径、装配。
 *
 * ★ 缓存路径必须 UI / worker 同一算法：一边算出来的文件另一边找不到，
 *   症状只是"每次都重新下载"，没有任何报错。
 */
final class FetchImageJobTest extends TestCase
{
    protected function tearDown(): void
    {
        ImageAssets::reset();

        parent::tearDown();
    }

    #[Test]
    public function 来源三分类(): void
    {
        self::assertSame('local', ImageLoader::classify('demo/logo.png'));
        self::assertSame('local', ImageLoader::classify('/var/tmp/a.png'));
        self::assertSame('local', ImageLoader::classify('C:\\img\\a.png'));
        self::assertSame('network', ImageLoader::classify('http://cdn/a.png'));
        self::assertSame('network', ImageLoader::classify('https://cdn/a.png?x=1'));
        self::assertSame('reject', ImageLoader::classify('file:///tmp/a.png'));
        self::assertSame('reject', ImageLoader::classify('data:image/png;base64,AAAA'));
    }

    #[Test]
    public function 缓存路径由URL唯一决定(): void
    {
        $a = FetchImageJob::cachePath('https://cdn/a.png', 'C:\\cache');
        $b = FetchImageJob::cachePath('https://cdn/a.png', 'C:\\cache');
        $c = FetchImageJob::cachePath('https://cdn/b.png', 'C:\\cache');

        self::assertSame($a, $b, '同一 URL 两次解析不一样 ⇒ 磁盘缓存永远不命中');
        self::assertNotSame($a, $c);
        self::assertStringEndsWith('.png', $a);
        self::assertStringEndsWith('.jpg', FetchImageJob::cachePath('https://cdn/a', 'C:\\cache'));
    }

    #[Test]
    public function 签名URL由稳定键收敛(): void
    {
        $dir = 'C:\\cache';
        $first = FetchImageJob::cachePath('https://cdn/a.png?X-Amz-Signature=aaa', $dir);
        $second = FetchImageJob::cachePath('https://cdn/a.png?X-Amz-Signature=bbb', $dir);
        $stableFirst = FetchImageJob::cachePath('https://cdn/a.png?X-Amz-Signature=aaa', $dir, 'cdn/a.png');
        $stableSecond = FetchImageJob::cachePath('https://cdn/a.png?X-Amz-Signature=bbb', $dir, 'cdn/a.png');

        self::assertNotSame($first, $second, '按完整 URL 算键时，签名不同必须落到不同文件');
        self::assertSame($stableFirst, $stableSecond, '给了稳定键，不同签名必须命中同一文件');
        self::assertStringEndsWith('.png', $stableFirst, '稳定键不能把扩展名带走');
    }

    #[Test]
    public function 扩展名只看路径段不看query(): void
    {
        self::assertStringEndsWith(
            '.png',
            FetchImageJob::cachePath('https://cdn/a.png?sig=1.2', 'C:\\cache'),
            'query 里的点不能当成后缀'
        );
    }

    #[Test]
    public function 半截缓存不算命中(): void
    {
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'drift-cache-' . \uniqid('', true);
        @\mkdir($dir, 0777, true);
        $path = FetchImageJob::cachePath('https://cdn/a.png', $dir);

        try {
            \file_put_contents($path, 'short');
            self::assertFalse(FetchImageJob::isUsableCacheFile($path), '短文件不能再冒充命中');
            \file_put_contents($path, \str_repeat('x', 32));
            self::assertTrue(FetchImageJob::isUsableCacheFile($path), '完整文件应当命中');
        } finally {
            @\unlink($path);
            @\rmdir($dir);
        }
    }

    #[Test]
    public function 组合根配缓存时装配顺序不影响稳定键(): void
    {
        $scope = AppScope::make();
        $scope->setImageCache('C:\\imgcache', static fn(string $url): string => 'stable-id');
        $scope->setTaskHost(new SyncTaskHost());

        self::assertNotNull(ImageAssets::current());
        self::assertSame(
            FetchImageJob::cachePath('https://cdn/a.png?sig=xyz', 'C:\\imgcache', 'stable-id'),
            ImageAssets::current()->cachePath('https://cdn/a.png?sig=xyz'),
            'setTaskHost 重新装配时必须带着此前配置的稳定键'
        );
    }

    #[Test]
    public function 本地文件直通且不发请求(): void
    {
        $file = (string) \tempnam(\sys_get_temp_dir(), 'drift-img-');
        try {
            self::assertSame($file, (new FetchImageJob($file))->run());
        } finally {
            @\unlink($file);
        }
    }

    #[Test]
    public function 空URL当场失败(): void
    {
        $this->expectException(\RuntimeException::class);
        (new FetchImageJob(''))->run();
    }

    #[Test]
    public function 核心任务由CoreJobs注入且应用表不被覆盖(): void
    {
        $map = CoreJobs::register([]);
        self::assertArrayHasKey(FetchImageJob::NAME, $map);

        $factory = static fn(array $args): \Drift\App\Job => new FetchImageJob('x');
        self::assertSame($factory, CoreJobs::register([FetchImageJob::NAME => $factory])[FetchImageJob::NAME]);
    }

    #[Test]
    public function 装配后台端口时图片服务跟着装好(): void
    {
        self::assertNull(ImageAssets::current());

        AppScope::make()->setTaskHost(new SyncTaskHost());
        self::assertNotNull(ImageAssets::current());
        self::assertStringContainsString('drift-image-', ImageAssets::current()->cachePath('https://cdn/a.png'));
    }

    #[Test]
    public function 没有后台端口时不发网络请求(): void
    {
        ImageAssets::install(null);
        $future = ImageAssets::current()->fetch('https://cdn/a.png', 0, static function (): void {
        });

        // 同步结算成失败（rejected）；不能退回同步下载把 UI 线程占住。
        self::assertTrue($future->isDone());
        self::assertFalse($future->isCancelled());
    }
}
