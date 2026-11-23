<?php
declare(strict_types=1);

namespace App\Watermark\Api;

use Drift\App\JobProgress;

/**
 * 离线 mock 后端（对外发布版默认开启，见 {@see Config::MOCK}）。
 *
 * ★ 不发起任何网络请求：签名给假凭证、上传只模拟进度、建任务回假 id、
 *   查结果回**本地示例图**（随仓库发布、运行时解到临时目录）。让 clone 仓库的人
 *   不用任何后端就能跑通整条 UI 闭环，且仓库里没有任何真实后端地址。
 *
 * ★ 示例图用 base64 内嵌、运行时写临时文件：规避 AOT 下"资源目录在哪"的不确定。
 *   回本地路径即可被 `Image` 显示 —— `asset()` 收本地路径与 http(s) URL 同形。
 */
final class MockApiClient implements WatermarkApi
{
    /** 内嵌示例图（base64 PNG）。改图请重跑生成脚本，不要手敲。 */
    private const SAMPLES = [
        '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',
    ];

    public function sign(): SignInfo
    {
        // 假凭证：upload() 在 mock 下不真正用它们，只是让类型与真实路径一致。
        return new SignInfo(
            account: 'MOCK_ACCESS_KEY',
            policy: 'MOCK_POLICY',
            signature: 'MOCK_SIGNATURE',
            directoryPath: 'mock/',
            accessAddress: 'https://oss.example.invalid/upload',
            expireTime: (string) ((\time() + 600) * 1000),
        );
    }

    public function upload(SignInfo $sign, string $localPath, string $objectKey, ?callable $onProgress = null): void
    {
        // 模拟上传进度（无网络）：分 10 步从 0 到 1。
        $steps = 10;
        for ($i = 1; $i <= $steps; $i++) {
            if ($onProgress !== null) {
                $onProgress($i / $steps);
            }
        }
    }

    public function addTask(array $objectKeys, string $time, string $address, string $imageSize): string
    {
        return 'mock-task-' . \md5(\implode(',', $objectKeys) . '|' . $time . '|' . $address . '|' . $imageSize);
    }

    public function getResult(string $taskId): TaskResult
    {
        return new TaskResult(
            taskId: $taskId,
            status: TaskResult::STATUS_DONE,
            templateName: 'mock-template',
            imageSize: '2K',
            processingTime: '1.2s',
            createTime: \date('Y-m-d H:i:s'),
            updateTime: \date('Y-m-d H:i:s'),
            errorMsg: '',
            downloadUrls: $this->sampleImages(),
        );
    }

    /**
     * 把内嵌示例图解到临时目录，回本地绝对路径（供 `Image` 直接显示）。
     *
     * @return list<string>
     */
    private function sampleImages(): array
    {
        $dir = \sys_get_temp_dir();
        $urls = [];
        foreach (self::SAMPLES as $index => $b64) {
            $path = $dir . \DIRECTORY_SEPARATOR . 'drift-wm-mock-' . $index . '.png';
            if (!\is_file($path)) {
                $written = @\file_put_contents($path, \base64_decode($b64));
                if ($written === false) {
                    continue;
                }
            }
            $urls[] = $path;
        }

        return $urls;
    }
}
