<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Api\DownloadReport;
use App\Watermark\Jobs\AddTaskJob;
use App\Watermark\Jobs\DownloadResultsJob;
use App\Watermark\Jobs\FetchSignJob;
use App\Watermark\Jobs\PollResultJob;
use App\Watermark\Jobs\UploadBatchJob;
use App\Watermark\Store\WatermarkStore;
use App\Watermark\Widgets\ResultCard;
use Drift\Async\AsyncValue;
use Drift\Dev\Tests\Support\RecordingTaskHost;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 结果图区的四态画法（§75）。
 *
 * ★ 判据量的是**最终 Widget 树里的文本**，不是 `fold()` 内部走了哪一支：
 *   删掉 `error` 支、或把它改回逐 URL 转圈，本文件必须红。
 *   「整体下载失败后每张图还画『正在下载结果图…』」是本棒修掉的真缺口。
 *
 * ★ 部分失败仍走 `data` 态：已下载成功的图与失败条必须同时可见
 *   （成熟 GUI 的异步组件不负责批量部分失败汇总）。
 */
final class DownloadImagesStatesTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @\unlink($path);
        }
        $this->tmp = [];
    }

    #[Test]
    public function 四态画法齐全且整体失败不再转圈(): void
    {
        $a = 'https://oss.example.com/r/1.png';
        $b = 'https://oss.example.com/r/2.png';
        $urls = [$a, $b];

        self::assertSame([], $this->textsOf(ResultCard::imagesFor($urls, AsyncValue::idle())));

        $loading = $this->textsOf(ResultCard::imagesFor($urls, AsyncValue::loading()));
        self::assertContains('正在下载 0 / 2…', $loading);
        self::assertSame(2, $this->countText($loading, '正在下载结果图…'));

        $ok = $this->textsOf(ResultCard::imagesFor(
            $urls,
            AsyncValue::data(DownloadReport::of([$a => '/tmp/a.png', $b => '/tmp/b.png'], []))
                ->withProgress(1.0)
        ));
        self::assertContains($a, $ok);
        self::assertContains($b, $ok);
        self::assertNotContains('正在下载结果图…', $ok);

        $partial = $this->textsOf(ResultCard::imagesFor(
            $urls,
            AsyncValue::data(DownloadReport::of([$a => '/tmp/a.png'], [$b => 'HTTP 404']))
                ->withProgress(1.0)
        ));
        self::assertContains($a, $partial, '部分成功时已下载的图不能整块消失');
        self::assertContains('这张图没能下载：HTTP 404', $partial);
        self::assertNotContains('正在下载结果图…', $partial);

        $error = $this->textsOf(ResultCard::imagesFor($urls, AsyncValue::error('磁盘满')));
        self::assertContains('结果图下载失败：磁盘满', $error);
        self::assertNotContains('正在下载结果图…', $error, '整条失败后还在转圈 ⇒ 旧缺口复发');
    }

    #[Test]
    public function 真链路整体失败时不假装还在下载(): void
    {
        $urls = ['https://oss.example.com/r/1.png', 'https://oss.example.com/r/2.png'];
        $host = $this->host($urls);
        $store = $this->store($host);

        $store->upload();
        $store->submitTask();

        $v = $store->downloads();
        self::assertTrue($v->hasError(), '整条下载抛异常必须落到 error 态');
        self::assertSame('磁盘满', $v->message());
        self::assertSame([], $store->localPaths());
        self::assertFalse($store->isDownloading(), '失败后不能再算"正在下载"');

        $texts = $this->textsOf(ResultCard::imagesFor($urls, $v));
        self::assertNotContains('正在下载结果图…', $texts);
        self::assertContains('结果图下载失败：磁盘满', $texts);
    }

    /** @param list<string> $urls */
    private function host(array $urls): RecordingTaskHost
    {
        return new RecordingTaskHost([
            FetchSignJob::class => [
                'account' => 'acc',
                'policy' => 'pol',
                'signature' => 'sig',
                'directoryPath' => 'd/',
                'accessAddress' => 'https://oss.example.com',
                'expireTime' => (string) ((\time() + 600) * 1000),
            ],
            UploadBatchJob::class => ['keys' => ['k1']],
            AddTaskJob::class => ['taskId' => 'T-1'],
            PollResultJob::class => [
                'taskId' => 'T-1',
                'status' => 3,
                'templateName' => '修改水印',
                'imageSize' => '2K',
                'processingTime' => '34s',
                'createTime' => '2026-11-08 12:00:00',
                'updateTime' => '2026-11-08 12:00:34',
                'errorMsg' => '',
                'downloadUrls' => $urls,
            ],
            DownloadResultsJob::class => static function (): array {
                throw new \RuntimeException('磁盘满');
            },
        ]);
    }

    private function store(RecordingTaskHost $host): WatermarkStore
    {
        $file = (string) \tempnam(\sys_get_temp_dir(), 'drift-wm-');
        $path = $file . '.jpg';
        \rename($file, $path);
        $this->tmp[] = $path;

        $store = new WatermarkStore($host, 'http://gw.invalid');
        self::assertSame(1, $store->addFiles([$path]), '夹具没被选上 ⇒ 后面全靠巧合');
        $store->setTime('2026-11-08 12:00:00');
        $store->setAddress('上海市浦东新区');

        return $store;
    }

    /** @return list<string> Widget 树里所有 Text 的内容 */
    private function textsOf(Widget $root): array
    {
        $out = [];
        $seen = [];
        $walk = function (object $node) use (&$walk, &$out, &$seen): void {
            $id = \spl_object_id($node);
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;

            if ($node instanceof Text) {
                $out[] = (string) (new \ReflectionProperty(Text::class, 'content'))->getValue($node);
            }

            for ($class = new \ReflectionObject($node); $class !== false; $class = $class->getParentClass()) {
                foreach ($class->getProperties() as $prop) {
                    if (!$prop->isInitialized($node)) {
                        continue;
                    }
                    $value = $prop->getValue($node);
                    if ($value instanceof Widget) {
                        $walk($value);
                    } elseif (\is_array($value)) {
                        foreach ($value as $child) {
                            if ($child instanceof Widget) {
                                $walk($child);
                            }
                        }
                    }
                }
            }
        };
        $walk($root);

        return $out;
    }

    /** @param list<string> $texts */
    private function countText(array $texts, string $needle): int
    {
        return \count(\array_filter($texts, static fn(string $t): bool => $t === $needle));
    }
}
