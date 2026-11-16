<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Jobs\DownloadResultsJob;
use App\Watermark\Jobs\FetchSignJob;
use App\Watermark\Store\WatermarkStore;
use Drift\Async\Future;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 「在忙什么」只有一个来源 —— 界面门控与业务守卫必须同源。
 *
 * ## 钉住四件事（每一条都是 2026-11-16 修掉的真缺陷）
 *
 * | 用例 | 坏掉时的现象 |
 * |---|---|
 * | ① 提交按钮的可用态 == 守卫 | 上传中 / 轮询中按钮亮着，点下去才被告知"还有一步没做完" |
 * | ② 忙时加文件被拒 | 界面显示 3 张、任务里只有 1 张，**没有任何报错** |
 * | ③ 结果终结后流水线收工 | 拿到终态还挂着"在忙"，且多提交一条批量下载 |
 * | ④ 预热不占"在忙"位 | 首屏那一两秒里用户点了「上传」毫无反应 |
 *
 * ★ 用 `DeferredHost`（每任务回 `Future::pending()`，逐趟手动交付）：这些判据
 *   全部落在**中间态**上，已结算的 `RecordingTaskHost` 里根本没有那些状态。
 */
final class BusyGateTest extends TestCase
{
    /** @var list<string> 临时文件，测完清掉 */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @\unlink($path);
        }
        $this->tmp = [];
    }

    #[Test]
    public function 提交的可用态与守卫是同一份判据(): void
    {
        $host = new DeferredHost();
        $store = new WatermarkStore($host, 'http://gw.invalid');

        // ① 没选图
        self::assertFalse($store->canSubmit());
        self::assertSame('请先上传图片', $store->submitBlocker());

        // ② 选了图但还没传 ⇒ 仍然不能提交，且文案说的是"传"不是"填"
        //    （`uploadedKeys` 才是提交的输入；选中 ≠ 已上传，这条边界很容易写漏）
        $store->addFiles([$this->image()]);
        self::assertSame('请先上传图片', $store->submitBlocker());

        // ③ 上传完了，时间和地址空着
        $store->upload();
        $host->futureAt(0)->resolve(self::sign());
        $host->futureAt(1)->resolve(['keys' => ['k1']]);
        self::assertSame('请填写时间和地址', $store->submitBlocker());
        self::assertFalse($store->canSubmit(), 'canSubmit() 必须由 submitBlocker() 导出，不能另写一套条件');

        // ④ 填全 ⇒ 能提交，且"原因"为空（界面据此点亮按钮、不画提示行）
        $store->setTime('2026-11-16 09:00:00');
        $store->setAddress('上海市浦东新区');
        self::assertSame('', $store->submitBlocker());
        self::assertTrue($store->canSubmit());
    }

    #[Test]
    public function 忙的时候添加图片被拒绝且不动已选(): void
    {
        $host = new DeferredHost();
        $store = $this->filled($host);

        $store->upload();
        self::assertSame(FetchSignJob::class, $host->nameAt(0), '夹具前提：上传已在途');
        self::assertTrue($store->isBusy());

        $before = $store->selectedCount();
        $added = $store->addFiles([$this->image()]);

        self::assertSame(0, $added, '流水线在跑时加进来的图不会被上传，却在列表里 —— 这正是那个静默错');
        self::assertSame($before, $store->selectedCount(), '拒绝时不许改动已选集合');
        self::assertSame(
            '正在处理中，完成后才能添加图片',
            $store->uploadMessage()->text,
            '拒绝必须说出来，否则用户看到的是"拖进去没反应"'
        );
    }

    #[Test]
    public function 结果终结后流水线收工且不提交批量下载(): void
    {
        $host = new DeferredHost();
        $store = $this->filled($host);

        $store->upload();
        $host->futureAt(0)->resolve(self::sign());
        $host->futureAt(1)->resolve(['keys' => ['k1']]);

        $store->submitTask();
        $host->futureAt(2)->resolve(['taskId' => 'T-1']);
        $host->futureAt(3)->resolve(self::successResult());

        // 结果图下载已归 Image 控件自治：Store 拿到终态就收工，不再提交第五条任务。
        self::assertFalse($store->isBusy(), '终态后阶段没归位 ⇒ 按钮永远挂着');
        self::assertFalse($store->canStop());
        self::assertSame(0, $host->countOf(DownloadResultsJob::class), '结果图不该再走批量下载');
        self::assertTrue($store->canUpload(), '终态后应该回到可操作态');
    }

    #[Test]
    public function 预热不占在忙位(): void
    {
        $host = new DeferredHost();
        $store = new WatermarkStore($host, 'http://gw.invalid');

        $store->prewarm();
        self::assertSame(FetchSignJob::class, $host->nameAt(0), '夹具前提：预热真的发了一次取签名');

        self::assertFalse($store->isBusy(), '预热是后台动作，不该占「在忙」这一位');

        // 预热期间选图、点上传都必须照常可用（上传会作废并取消那次预热）
        self::assertSame(1, $store->addFiles([$this->image()]), '预热期间选图被拒 ⇒ 首屏那一两秒用户点不动任何东西');
        self::assertTrue($store->canUpload());

        $store->upload();
        self::assertSame(FetchSignJob::class, $host->nameAt(1), '上传该重新取签名，而不是复用预热那条已取消的');
    }

    // ══════════════════ 夹具 ══════════════════

    /** 选一张图并把表单填全 */
    private function filled(DeferredHost $host): WatermarkStore
    {
        $store = new WatermarkStore($host, 'http://gw.invalid');
        self::assertSame(1, $store->addFiles([$this->image()]), '夹具没被选上 ⇒ 后面全靠巧合');
        $store->setTime('2026-11-16 09:00:00');
        $store->setAddress('上海市浦东新区');

        return $store;
    }

    private function image(): string
    {
        $file = (string) \tempnam(\sys_get_temp_dir(), 'drift-wm-');
        $path = $file . '.jpg';
        \rename($file, $path);
        $this->tmp[] = $path;

        return $path;
    }

    /** @return array<string, mixed> */
    private static function sign(): array
    {
        return [
            'account' => 'acc',
            'policy' => 'pol',
            'signature' => 'sig',
            'directoryPath' => '10000000/2026/11/16/',
            'accessAddress' => 'https://oss.example.com',
            'expireTime' => (string) ((\time() + 600) * 1000),
        ];
    }

    /** @return array<string, mixed> */
    private static function successResult(): array
    {
        return [
            'taskId' => 'T-1',
            'status' => 3,
            'templateName' => '修改水印',
            'imageSize' => '2K',
            'processingTime' => '34s',
            'createTime' => '2026-11-16 09:00:00',
            'updateTime' => '2026-11-16 09:00:34',
            'errorMsg' => '',
            'downloadUrls' => ['https://oss.example.com/r/1.png'],
        ];
    }
}
