<?php
declare(strict_types=1);

namespace App\Watermark\Api;

use Drift\App\Wire;

/**
 * 一批结果图的下载结果 —— `DownloadResultsJob` 的产出，也是它**唯一**的读法。
 *
 * ★ 为什么要有这一层：`run()` 的返回值跨进程只能是一个数组，而"这个数组长什么样"
 *   以前有两套名字（Job 写 `paths`、Store 存成 `locals`），解包被抄了三份
 *   （Store 三个 getter 各解一次 + `ResultCard` 再解一次）。形状一分叉就是**静默**错：
 *   键名对不上时两侧都退化成空数组，界面只是"什么都没下到"，不报错。
 *   ⇒ 构造（`of()`）与读回（`fromPayload()`）共用这里的收窄规则，键名只有一份。
 *
 * ★ 收窄规则不对称：路径必须是**非空字符串**（空路径画不出图，等于没下到）；
 *   失败原因可以是任何标量（异常消息 / 状态码），空的也要留一条 —— URL 出现在
 *   失败列表里却"没有原因"，比给一句兜底文案更容易让人以为界面坏了。
 */
final class DownloadReport
{
    use Wire;

    /** 键名恒等于属性名（顺序 == 构造器参数顺序） */
    private const FIELDS = ['paths', 'failed'];

    /**
     * @param array<string, string> $paths url => 本地路径
     * @param array<string, string> $failed url => 失败原因
     */
    private function __construct(
        private readonly array $paths,
        private readonly array $failed,
    ) {
    }

    /** 什么都没有（`loading` 态画占位、失败后无产物） */
    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * 收窄成合法形状。**任务侧与读取侧共用**（规则只写一遍）。
     *
     * @param array<array-key, mixed> $paths url => 本地路径
     * @param array<array-key, mixed> $failed url => 失败原因
     */
    public static function of(array $paths, array $failed): self
    {
        $ok = [];
        foreach ($paths as $url => $path) {
            if (\is_string($url) && \is_string($path) && $path !== '') {
                $ok[$url] = $path;
            }
        }

        $bad = [];
        foreach ($failed as $url => $reason) {
            if (!\is_string($url)) {
                continue;
            }
            $bad[$url] = \is_string($reason) && $reason !== '' ? $reason : '下载失败';
        }

        return new self($ok, $bad);
    }

    /**
     * 从跨进程回来的载荷读回。
     *
     * ★ 已经是本类 ⇒ 原样返回（不是捷径，是语义）：Store 把 `of()` 的产物直接存进
     *   `AsyncValue`，消费方再取时不该重跑一遍收窄 —— 每个 getter 各解一遍正是
     *   这次要治的病。
     */
    public static function fromPayload(mixed $raw): self
    {
        if ($raw instanceof self) {
            return $raw;
        }
        if (!\is_array($raw)) {
            return self::none();
        }

        $paths = $raw['paths'] ?? null;
        $failed = $raw['failed'] ?? null;

        return self::of(\is_array($paths) ? $paths : [], \is_array($failed) ? $failed : []);
    }

    /** 本地路径（空串 = 这张还没下到） */
    public function pathFor(string $url): string
    {
        $path = $this->paths[$url] ?? '';

        return \is_string($path) ? $path : '';
    }

    /** 失败原因（空串 = 这张没失败） */
    public function failureFor(string $url): string
    {
        $reason = $this->failed[$url] ?? '';

        return \is_string($reason) ? $reason : '';
    }

    /** @return array<string, string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return array<string, string> */
    public function failed(): array
    {
        return $this->failed;
    }

    public function failedCount(): int
    {
        return \count($this->failed);
    }
}
