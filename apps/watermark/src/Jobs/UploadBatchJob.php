<?php
declare(strict_types=1);

namespace App\Watermark\Jobs;

use App\Watermark\Api\Api;
use App\Watermark\Api\ApiException;
use App\Watermark\Api\Config;
use App\Watermark\Api\SignInfo;
use Drift\App\JobArgs;
use Drift\App\JobProgress;
use Drift\App\NamedJob;
use Drift\App\TimedJob;

/**
 * 把**一批**文件逐个直传到 OSS（后台任务，**带进度**）。
 *
 * ## 为什么是"一批"而不是"一个文件一个任务"
 *
 * 原文就是一个 `for` 循环逐个传，进度是 `(i + percent/100) / total`。做成
 * 一次一个任务的话，UI 侧要手写一条 `Future` 链（`then` 里再 submit 下一个），
 * 而"四态 / 去重 / 取消"就全都绕开了 `AsyncCommand` —— 那是本仓明令不要的
 * 第二套异步簿记（见 `AsyncCommand` 类注释）。
 *
 * 循环放在 worker 里的代价：取消粒度是**整个批次**。这与原文一致（一个失败就
 * `return`），而且用户的心智也是"这批上传"。
 *
 * ## 进度
 *
 * `(已完成文件数 + 当前文件的小数进度) / 总文件数` —— 与原文逐字同构。
 * ★ 每个文件传完**再报一次** `(i+1)/total`：curl 的最后一次
 *   `CURLOPT_READFUNCTION` 不一定落在 1.0 上（最后一块可能只到 0.98，
 *   剩下的是 socket flush）⇒ 不补这一下，进度条会停在 98% 然后跳到"成功"。
 *
 * ## 失败
 *
 * 第一个失败就抛，消息里带**序号 + 文件名**：批量失败只知道"这一批挂了"的话，
 * 用户还得自己一个个试。
 */
final class UploadBatchJob implements NamedJob, TimedJob
{
    use JobArgs;

    public const NAME = 'watermark.upload';

    /** 给 worker 启动与收尾的余量（毫秒）—— 上限只用来判卡死，不必卡到秒级。 */
    private const TIMEOUT_MARGIN_MS = 10_000;

    /**
     * @param array<string, string> $sign {@see SignInfo::toArray()} 的产物
     * @param list<array{path: string, key: string, name: string}> $items
     */
    public function __construct(
        private readonly array $sign,
        private readonly array $items,
        private readonly string $base = Config::API_BASE,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function args(): array
    {
        return [
            'sign' => $this->sign,
            'items' => \array_values($this->items),
            'base' => $this->base,
        ];
    }

    /**
     * worker 侧重建（**读法与 `args()` 上下相邻**，漏字段当场看得见）。
     *
     * @param array<string, mixed> $args
     */
    public static function fromArgs(array $args): self
    {
        return new self(
            self::argMap($args, 'sign'),
            self::argList($args, 'items'),
            self::argString($args, 'base', Config::API_BASE),
        );
    }

    /**
     * 整批的上限：单文件上限 × 文件数 + 余量。
     *
     * ★ 不能用 Host 的默认 30s：本任务是**串行**传 N 张，第一张慢一点就会被打死，
     *   而 HTTP 侧允许单文件 300s（`Config::UPLOAD_TIMEOUT_S`）。
     */
    public function timeoutMs(): int
    {
        return \count($this->items) * (int) (Config::UPLOAD_TIMEOUT_S * 1000) + self::TIMEOUT_MARGIN_MS;
    }

    public function run(): mixed
    {
        $sign = SignInfo::fromArray($this->sign);
        if ($sign === null) {
            // 走到这里说明**跨进程**丢字段了（`args()` 与 `toArray()` 漂了）。
            // 不静默降级：传一个没有 policy 的表单上去，OSS 回"签名不匹配"，
            // 归因会跑到 OSS 那边去。
            throw new ApiException('上传：签名参数不完整（跨进程丢字段了？）');
        }

        $total = \count($this->items);
        if ($total === 0) {
            throw new ApiException('上传：没有要传的文件');
        }

        $client = Api::client($this->base);
        $keys = [];

        $index = 0;
        foreach ($this->items as $item) {
            $path = (string) ($item['path'] ?? '');
            $key = (string) ($item['key'] ?? '');
            $name = (string) ($item['name'] ?? '');
            $at = $index;

            try {
                $client->upload(
                    $sign,
                    $path,
                    $key,
                    static function (float $fraction) use ($at, $total): void {
                        $done = \max(0.0, \min(1.0, $fraction));

                        JobProgress::report(\min(1.0, ($at + $done) / $total));
                    },
                );
            } catch (ApiException $e) {
                throw new ApiException(
                    '第 ' . ($at + 1) . '/' . $total . ' 个文件（' . $name . '）' . $e->getMessage(),
                    0,
                    $e,
                );
            }

            $keys[] = $key;
            JobProgress::report(\min(1.0, ($at + 1) / $total));
            $index++;
        }

        return ['keys' => $keys];
    }
}
