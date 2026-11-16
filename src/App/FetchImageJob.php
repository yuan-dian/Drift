<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\Http;

/**
 * 框架自带的单图下载任务 —— 由 `CoreJobs` 注入注册表，应用不用登记。
 *
 * 出参是本地文件路径；失败抛异常，由 `AsyncCommand` 落成 error 态。
 * 缓存路径 UI / worker 共用同一算法；签名 URL 的稳定键由 UI 侧算好后随参数递进来，
 * worker 不读静态服务。写盘先落临时文件再改名，避免半截文件被永久命中。
 */
final class FetchImageJob implements NamedJob, TimedJob
{
    use JobArgs;

    public const NAME = 'drift.fetchImage';

    /** 顺序 == 构造器参数顺序 */
    private const FIELDS = ['url', 'cacheDir', 'cacheKey'];

    private const FETCH_TIMEOUT_S = 60.0;

    /** Host 默认 30s 会把慢图掐死；单图自己的 HTTP 上限是 60s */
    public function timeoutMs(): int
    {
        return (int) (self::FETCH_TIMEOUT_S * 1000) + 10_000;
    }

    public function __construct(
        private readonly string $url,
        private readonly string $cacheDir = '',
        private readonly string $cacheKey = '',
    ) {
    }

    /**
     * UI 侧判磁盘命中、worker 侧写盘都走这里。
     *
     * ★ `$cacheKey` 不为空时取代 URL 作为身份：CDN 签名 URL 每次的 query 都不同，
     *   按完整 URL 算键会永远不命中、还堆一目录的图。扩展名仍从 URL 的路径段推，
     *   不能把 query 里的点当成后缀。
     */
    public static function cachePath(string $url, string $dir, string $cacheKey = ''): string
    {
        $ext = 'jpg';
        $path = $url;
        $query = \strpos($path, '?');
        if ($query !== false) {
            $path = \substr($path, 0, $query);
        }
        $dot = \strrpos($path, '.');
        if ($dot !== false && $dot < \strlen($path) - 1) {
            $candidate = \strtolower(\substr($path, $dot + 1));
            if (\in_array($candidate, ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'gif'], true)) {
                $ext = $candidate;
            }
        }

        $identity = $cacheKey !== '' ? $cacheKey : $url;

        return \rtrim($dir, '\\/') . \DIRECTORY_SEPARATOR . 'drift-image-' . \md5($identity) . '.' . $ext;
    }

    /** 空文件 / 半截文件不算命中：解不出图只会显示占位，没有任何东西报错。 */
    public static function isUsableCacheFile(string $path): bool
    {
        $size = \is_file($path) ? \filesize($path) : false;

        return $size !== false && $size >= 16;
    }

    public function run(): mixed
    {
        if ($this->url === '') {
            throw new \RuntimeException('图片 URL 为空');
        }

        // 上游有时回的是本地路径（mock 后端 / 已预下载），不必走网络。
        if (\is_file($this->url)) {
            return $this->url;
        }

        $dir = $this->cacheDir !== '' ? $this->cacheDir : \sys_get_temp_dir();
        $path = self::cachePath($this->url, $dir, $this->cacheKey);
        if (self::isUsableCacheFile($path)) {
            return $path;
        }
        if (\is_file($path)) {
            @\unlink($path); // 坏文件删掉重写，别让它继续冒充命中
        }

        $response = Http::get($this->url, [
            'connectTimeout' => 10.0,
            'timeout' => self::FETCH_TIMEOUT_S,
        ]);
        $bytes = $response->body();
        if ($bytes === '') {
            throw new \RuntimeException('服务端回了空 body：' . $this->url);
        }

        if (!\is_dir($dir)) {
            @\mkdir($dir, 0777, true);
        }
        // 先写临时文件再改名：中途被杀不会留下一个"存在但解不出"的缓存。
        $tmp = $path . '.part.' . (string) \getmypid();
        $written = @\file_put_contents($tmp, $bytes);
        if ($written === false || $written !== \strlen($bytes)) {
            @\unlink($tmp);
            throw new \RuntimeException('写入图片缓存失败：' . $tmp);
        }
        if (\is_file($path)) {
            @\unlink($path); // Windows 上 rename 不能覆盖已存在文件
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new \RuntimeException('图片缓存落盘失败：' . $path);
        }

        return $path;
    }
}
