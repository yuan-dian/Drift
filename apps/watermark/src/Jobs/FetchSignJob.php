<?php
declare(strict_types=1);

namespace App\Watermark\Jobs;

use App\Watermark\Api\ApiClient;
use App\Watermark\Api\Config;
use Drift\App\NamedJob;

/**
 * 取 OSS 直传签名（后台任务）。
 *
 * ★ 为什么单独一个任务而不是"上传任务里顺手取"：签名的有效期比一次上传长得多，
 *   多个文件可以共用一份（原文就是这样：页面加载时取一次，上传前只判过期）。
 *   合成一个任务 ⇒ 每传一个文件签一次，而签名接口在网关上是限流的。
 * ★ 返回值是**摊平的数组**（`SignInfo::toArray()`）：跨进程只能传 JSON。
 */
final class FetchSignJob implements NamedJob
{
    public const NAME = 'watermark.sign';

    public function __construct(private readonly string $base = Config::API_BASE)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function args(): array
    {
        return ['base' => $this->base];
    }

    public function run(): mixed
    {
        return (new ApiClient($this->base))->sign()->toArray();
    }
}
