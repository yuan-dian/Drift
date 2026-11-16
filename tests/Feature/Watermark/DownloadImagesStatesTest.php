<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Jobs\AddTaskJob;
use App\Watermark\Jobs\FetchSignJob;
use App\Watermark\Jobs\PollResultJob;
use App\Watermark\Jobs\UploadBatchJob;
use App\Watermark\Store\WatermarkStore;
use App\Watermark\Widgets\ResultCard;
use Drift\Dev\Tests\Support\RecordingTaskHost;
use Drift\Widget\Image;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 结果图区：服务端给的每个 URL 直接成一个 `Image`，下载 / 缓存 / 三态归控件。
 *
 * ★ 判据量最终 Widget 树：`Image::source()` 必须就是 URL，不再有"先下载到本地
 *   再 asset(本地路径)"这一步；也不再有应用层的批量转圈文案。
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
    public function 每张结果图把URL直接交给Image(): void
    {
        $a = 'https://oss.example.com/r/1.png';
        $b = 'https://oss.example.com/r/2.png';

        self::assertSame([$a, $b], $this->imagesOf(ResultCard::imagesFor([$a, $b])));
    }

    #[Test]
    public function 空结果不画任何图片(): void
    {
        self::assertSame([], $this->imagesOf(ResultCard::imagesFor([])));
        self::assertSame([], $this->textsOf(ResultCard::imagesFor([])));
    }

    #[Test]
    public function 流水线终态后页面按服务端给的URL直接画(): void
    {
        $urls = ['https://oss.example.com/r/1.png', 'https://oss.example.com/r/2.png'];
        $store = $this->store($this->host($urls));

        $store->upload();
        $store->submitTask();

        $result = $store->lastResult();
        self::assertTrue($result?->isSuccess() ?? false);
        self::assertSame($urls, $this->imagesOf(ResultCard::imagesFor($result->downloadUrls)));
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

    /** @return list<string> 树里所有 Image 的 source（顺序即绘制顺序） */
    private function imagesOf(Widget $root): array
    {
        $out = [];
        $this->walk($root, static function (object $node) use (&$out): void {
            if ($node instanceof Image) {
                $out[] = $node->source();
            }
        });

        return $out;
    }

    /** @return list<string> Widget 树里所有 Text 的内容 */
    private function textsOf(Widget $root): array
    {
        $out = [];
        $this->walk($root, static function (object $node) use (&$out): void {
            if ($node instanceof Text) {
                $out[] = (string) (new \ReflectionProperty(Text::class, 'content'))->getValue($node);
            }
        });

        return $out;
    }

    /** 深度遍历 Widget 的私有字段（判据不搭 Element 树） */
    private function walk(Widget $root, callable $visit): void
    {
        $seen = [];
        $walk = function (object $node) use (&$walk, &$seen, $visit): void {
            $id = \spl_object_id($node);
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $visit($node);

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
    }
}
