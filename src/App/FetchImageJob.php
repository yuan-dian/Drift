<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\Http;

/**
 * 框架自带的单图下载任务 —— 由 `CoreJobs` 注入注册表，应用不用登记。
 *
 * 出参是本地文件路径；失败抛异常，由 `AsyncCommand` 落成 error 态。
 * 缓存路径的算法必须 UI / worker 共用（见 {@see self::cachePath()}），
 * 两边各算一遍会在扩展名上分叉，缓存命中永远失败。
 */
final class FetchImageJob implements NamedJob, TimedJob
{
    use JobArgs;

    public const NAME = 'drift.fetchImage';

    /** 顺序 == 构造器参数顺序 */
    private const FIELDS = ['url', 'cacheDir'];

    private const FETCH_TIMEOUT_S = 60.0;

    /** Host 默认 30s 会把慢图掐死；单图自己的 HTTP 上限是 60s */
    public function timeoutMs(): int
    {
        return (int) (self::FETCH_TIMEOUT_S * 1000) + 10_000;
    }

    public function __construct(
        private readonly string $url,
        private readonly string $cacheDir = '',
    ) {
    }

    /** UI 侧判磁盘命中、worker 侧写盘都走这里 */
    public static function cachePath(string $url, string $dir): string
    {
        $ext = 'jpg';
        $dot = \strrpos($url, '.');
        if ($dot !== false && $dot < \strlen($url) - 1) {
            $candidate = \strtolower(\substr($url, $dot + 1));
            if (\in_array($candidate, ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'gif'], true)) {
                $ext = $candidate;
            }
        }

        return \rtrim($dir, '\\/') . \DIRECTORY_SEPARATOR . 'drift-image-' . \md5($url) . '.' . $ext;
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
        $path = self::cachePath($this->url, $dir);
        if (\is_file($path)) {
            return $path;
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
        $written = @\file_put_contents($path, $bytes);
        if ($written === false || $written !== \strlen($bytes)) {
            throw new \RuntimeException('写入图片缓存失败：' . $path);
        }

        return $path;
    }
}
