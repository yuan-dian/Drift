<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Api\DownloadReport;
use App\Watermark\Jobs\DownloadResultsJob;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `DownloadReport` 的形状判据 —— 任务侧（`of()`）与读取侧（`fromPayload()`）必须同源。
 *
 * ★ 钉的是**分叉**：改造前 Job 写 `paths`、Store 存 `locals`，键名对不上时两侧都
 *   退化成空数组 —— 界面只是"什么都没下到"，不报错。这里要求同一条收窄规则在
 *   往返两个方向都成立，且垃圾载荷不会让界面崩。
 */
final class DownloadReportTest extends TestCase
{
    #[Test]
    public function 往返不丢字段(): void
    {
        $report = DownloadReport::of(
            ['https://x/1.png' => '/tmp/1.png'],
            ['https://x/2.png' => 'HTTP 404'],
        );

        $back = DownloadReport::fromPayload($report->toArray());

        self::assertSame($report->paths(), $back->paths());
        self::assertSame($report->failed(), $back->failed());
        self::assertSame('/tmp/1.png', $back->pathFor('https://x/1.png'));
        self::assertSame('HTTP 404', $back->failureFor('https://x/2.png'));
    }

    /**
     * 两条规则**故意不对称**：路径空了就是没下到，失败原因空了也要留一条。
     */
    #[Test]
    public function 两条收窄规则不对称(): void
    {
        $report = DownloadReport::of(
            [
                'a' => '/tmp/a.png',
                'b' => '',          // 空路径 ⇒ 等于没下到
                'c' => 42,          // 非字符串 ⇒ 丢
                7 => '/tmp/7.png',  // 非字符串键 ⇒ 丢
            ],
            [
                'x' => '',          // 空原因也留：URL 在失败列表里却没原因，比一句兜底更让人以为界面坏了
                'y' => 500,
                'z' => 'HTTP 404',
            ],
        );

        self::assertSame(['a' => '/tmp/a.png'], $report->paths());
        self::assertSame(
            ['x' => '下载失败', 'y' => '下载失败', 'z' => 'HTTP 404'],
            $report->failed()
        );
        self::assertSame(3, $report->failedCount());
    }

    #[Test]
    public function 垃圾载荷退化成空且不崩(): void
    {
        $samples = [null, 'oops', 42, [], ['paths' => 'x'], ['locals' => ['a' => '/tmp/a']]];

        foreach ($samples as $raw) {
            $report = DownloadReport::fromPayload($raw);

            self::assertSame([], $report->paths());
            self::assertSame([], $report->failed());
            self::assertSame('', $report->pathFor('a'));
            self::assertSame('', $report->failureFor('a'));
        }
    }

    #[Test]
    public function 已是本类时原样返回(): void
    {
        $report = DownloadReport::of(['a' => '/tmp/a.png'], []);

        self::assertSame($report, DownloadReport::fromPayload($report));
    }

    /**
     * 端到端：`run()` 的**真实产出**必须能被读回。
     *
     * ★ 这条是唯一能抓住"Job 侧改回手写键名"的判据 —— 其余下载测试都在
     *   `Future::resolve()` 里手塞假载荷，Job 侧怎么写都测不到（实测：把
     *   `run()` 改回 `['locals' => …]`，那 56 个测试全绿）。
     * ★ 不发网络：本地文件 `fetchOne` 直接返回；空 URL 首句就判失败。
     */
    #[Test]
    public function 任务真实产出能被读回(): void
    {
        $src = (string) \tempnam(\sys_get_temp_dir(), 'drift-dr-');
        \file_put_contents($src, 'fake-image-bytes');

        try {
            $job = new DownloadResultsJob([$src, '']);
            $report = DownloadReport::fromPayload($job->run());

            self::assertSame($src, $report->pathFor($src), 'Job 写 / Report 读的键名分叉了');
            self::assertSame(1, $report->failedCount());
        } finally {
            @\unlink($src);
        }
    }
}
