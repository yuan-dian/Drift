<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;
use Drift\Errors;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\ImageLoader;
use Drift\Rendering\ImageSource;
use Drift\Widget\Image;

/**
 * 异步图片端口 —— 由 `AppScope::setTaskHost()` 自动装配，业务不接触。
 *
 * 用静态端口而不是 ThemeExtension：`ThemeScope` 会整体替换 `ThemeData`，
 * 挂在主题上的服务在第一层覆盖点就丢了；`ImageLoader` 本身已是静态缓存 +
 * 静态基目录，端口同形，不引入第二套注入机制。AppScope 每进程一份（多窗口共享），
 * 与 TaskHost 的生命期一致，不会串。
 */
final class ImageAssets implements ImageSource
{
    private static ?self $current = null;

    public function __construct(
        private readonly ?TaskHost $host,
        private readonly string $cacheDir = '',
    ) {
    }

    public static function install(?TaskHost $host, string $cacheDir = ''): void
    {
        self::$current = new self($host, $cacheDir);
        Image::setSource(self::$current);
    }

    /** 测试复位用；生产路径由组合根装配一次 */
    public static function reset(): void
    {
        self::$current = null;
        Image::setSource(null);
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public function cached(string $url, int $width): ?ImageHandle
    {
        $path = $this->cachePath($url);

        return \is_file($path) ? ImageLoader::load($path, $width) : null;
    }

    /** UI 侧判磁盘命中，算法与 worker 写盘共用 */
    public function cachePath(string $url): string
    {
        $dir = $this->cacheDir !== '' ? $this->cacheDir : \sys_get_temp_dir();

        return FetchImageJob::cachePath($url, $dir);
    }

    /**
     * 下载 + 解码 + 诊断全在 App 侧完成，Widget 只收一个 handle。
     *
     * ★ `Future` 不穿 Widget 层（§4.3 的 Widget→Async 禁令）；这条缝只递回调。
     *
     * @param callable(ImageHandle): void $onReady 帧内回调
     */
    public function fetch(string $url, int $width, callable $onReady): mixed
    {
        if ($this->host === null) {
            return Future::rejected(new \RuntimeException('没有后台执行端口，网络图不加载：' . $url));
        }

        $future = $this->host->submit(new FetchImageJob($url, $this->cacheDir));
        $future->catch(static function (\Throwable $error) use ($url): void {
            Errors::report(
                'Image：网络图加载失败 ' . $url . ' —— ' . $error->getMessage(),
                Errors::DIAGNOSTIC,
            );
        });

        return $future->then(static function (mixed $path) use ($width, $onReady): mixed {
            $handle = \is_string($path)
                ? (ImageLoader::load($path, $width) ?? ImageLoader::placeholder())
                : ImageLoader::placeholder();
            $onReady($handle);

            return $handle;
        });
    }
}
