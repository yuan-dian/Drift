<?php
declare(strict_types=1);

namespace App\Watermark\Jobs;

use App\Watermark\Api\DownloadReport;
use Drift\App\Io\Http;
use Drift\App\JobArgs;
use Drift\App\JobProgress;
use Drift\App\NamedJob;
use Drift\App\TimedJob;

/**
 * 把**一批**结果图下载到本地临时文件（后台任务）。
 *
 * ## 什么时候用
 *
 * `Image::make()->asset($url)` 已经能自己下载、缓存并显示单张网络图，展示结果图
 * 不需要先跑这个 Job。这里服务的是"一次性拿到整批本地路径"的场景（离线复用、
 * 导出、交给外部工具），由一个任务管住进度与部分失败。
 *
 * ★ 网络 IO 始终在后台：`Image` 的网络分支把下载交给核心 Job，渲染层只读解码缓存；
 *   本 Job 同理，跑在 worker 侧。
 *
 * ## 为什么是"一批"而不是"一张一个任务"
 *
 * 一个任务 = 一条 `Future` = `AsyncCommand` 管得住。N 张图起 N 个任务的话，
 * UI 侧要自己维护"N 条在途 Future"的簿记 —— 那是本仓明令不要的第二套异步状态机。
 *
 * ## 部分失败
 *
 * **一张失败不拖累别的**：返回 `['paths' => [...], 'failed' => [...]]`。
 * 用户至少要看到成功的那几张（原文是一张都不显示，因为一张失败就抛）。
 */
final class DownloadResultsJob implements NamedJob, TimedJob
{
    use JobArgs;

    public const NAME = 'watermark.download';

    /** 顺序 == 构造器参数顺序 */
    private const FIELDS = ['urls', 'cacheDir'];

    /** 单张结果图的 HTTP 上限（秒）—— 4K 图可能几 MB。 */
    private const FETCH_TIMEOUT_S = 120.0;

    /** 给 worker 启动与写盘的余量（毫秒）。 */
    private const TIMEOUT_MARGIN_MS = 10_000;

    /**
     * @param list<string> $urls
     */
    public function __construct(
        private readonly array $urls,
        private readonly string $cacheDir = '',
    ) {
    }

    /**
     * 整批的上限：单张上限 × 张数 + 余量。
     *
     * ★ 与 `UploadBatchJob::timeoutMs()` 同构：串行批量 + 单条上限远大于 Host 默认 30s。
     */
    public function timeoutMs(): int
    {
        return \count($this->urls) * (int) (self::FETCH_TIMEOUT_S * 1000) + self::TIMEOUT_MARGIN_MS;
    }

    public function run(): mixed
    {
        $total = \count($this->urls);
        if ($total === 0) {
            return DownloadReport::none()->toArray();
        }

        $dir = $this->cacheDir !== '' ? $this->cacheDir : \sys_get_temp_dir();

        $paths = [];
        $failed = [];
        $done = 0;

        foreach ($this->urls as $url) {
            try {
                $paths[$url] = self::fetchOne($url, $dir);
            } catch (\RuntimeException $e) {
                // ★ 单张失败**记下来继续**：结果图里有一张 404 不该让其余的全不显示。
                $failed[$url] = $e->getMessage();
            }

            $done++;
            JobProgress::report(\min(1.0, $done / $total));
        }

        // ★ 用 `of()` 而不是手写数组字面量：形状由 `DownloadReport` 定义，读回那一侧
        //   也是它 —— 键名不会再出现"Job 写 paths、Store 存 locals"这种分叉。
        return DownloadReport::of($paths, $failed)->toArray();
    }

    /** 下一张，返回本地路径 */
    private static function fetchOne(string $url, string $dir): string
    {
        if ($url === '') {
            throw new \RuntimeException('URL 为空');
        }

        // ★ 已是本地文件（mock 后端回的就是本地示例图路径，或上游已预下载）：
        //   直接返回，不走网络。这也让"复用已下载文件"成为一等能力。
        if (\is_file($url)) {
            return $url;
        }

        $response = Http::get($url, [
            'connectTimeout' => 10.0,
            // ★ 结果图可能几 MB（4K），比普通接口宽裕得多
            'timeout' => self::FETCH_TIMEOUT_S,
        ]);

        $bytes = $response->body();
        if ($bytes === '') {
            throw new \RuntimeException('服务端回了空 body');
        }

        // ★ 文件名用 `md5(url)`：同一张图重复下载是**覆盖**而不是堆积，而且
        //   UI 侧能立刻看出"这个 URL 已经下过了"。
        $path = \rtrim($dir, '\\/') . \DIRECTORY_SEPARATOR
            . 'drift-watermark-' . \md5($url) . '.' . self::extensionOf($url, $response->header('content-type'));

        // ★ `@`：目录不可写时 `file_put_contents` 会发 warning。这条路径**有**
        //   返回值可判（false），warning 只会把日志搞脏 —— 判据在返回值上。
        $written = @\file_put_contents($path, $bytes);
        if ($written === false || $written !== \strlen($bytes)) {
            throw new \RuntimeException('写入临时文件失败（' . $path . '）');
        }

        return $path;
    }

    /**
     * 扩展名：先看 URL 路径，再看 `Content-Type`，都不认识就 `jpg`。
     *
     * ★ 顺序不能反：URL 上的扩展名是**服务端给的**（`.../result.png`），而
     *   `Content-Type` 有时是 `application/octet-stream`（OSS 没配 metadata 时）。
     *   反过来会让一张 png 存成 `.jpg` —— `ImageLoader` 按**文件签名**分派，
     *   所以画面仍是对的、只是扩展名撒谎。不致命，但没必要。
     */
    private static function extensionOf(string $url, ?string $contentType): string
    {
        $path = $url;
        $q = \strpos($path, '?');
        if ($q !== false) {
            $path = \substr($path, 0, $q);
        }
        $dot = \strrpos($path, '.');
        if ($dot !== false && $dot < \strlen($path) - 1) {
            $ext = \strtolower(\substr($path, $dot + 1));
            if (\in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'gif'], true)) {
                return $ext;
            }
        }

        $type = $contentType === null ? '' : \strtolower($contentType);
        if (\str_contains($type, 'png')) {
            return 'png';
        }
        if (\str_contains($type, 'webp')) {
            return 'webp';
        }
        if (\str_contains($type, 'bmp')) {
            return 'bmp';
        }
        if (\str_contains($type, 'gif')) {
            return 'gif';
        }

        return 'jpg';
    }
}
