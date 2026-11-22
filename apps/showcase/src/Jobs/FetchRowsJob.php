<?php
declare(strict_types=1);

namespace App\Showcase\Jobs;

use Drift\App\JobArgs;
use Drift\App\NamedJob;

/**
 * 在 worker 进程里造一页"慢"表格行 —— 零依赖的慢任务演示。
 *
 * 真 HTTP 取数不在这里：小 JSON 由组合根切到 UI 侧 `AsyncHttp`（帧内 curl_multi），
 * 大 body / multipart 才由 `HttpRequestJob` 留在 worker。本 Job 只负责"慢在 worker"。
 */
final class FetchRowsJob implements NamedJob
{
    use JobArgs;

    /** 注册表里的键（UI 与 worker 必须一致） */
    public const NAME = 'showcase.rows';

    /** 顺序 == 构造器参数顺序 */
    private const FIELDS = ['page', 'perPage'];

    private const LOCAL_DELAY_US = 1_200_000;

    public function __construct(
        private readonly int $page = 1,
        private readonly int $perPage = 20,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, source: string}
     */
    public function run(): mixed
    {
        \usleep(self::LOCAL_DELAY_US);
        $page = RowsSeed::page($this->page, $this->perPage);

        return [
            'items' => $page['items'],
            'total' => $page['total'],
            'source' => '本地慢任务',
        ];
    }
}
