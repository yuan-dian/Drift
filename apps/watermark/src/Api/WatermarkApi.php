<?php
declare(strict_types=1);

namespace App\Watermark\Api;

/**
 * watermark 后端契约（签名 / 上传 / 建任务 / 查结果）。
 *
 * ★ 真实实现是 {@see ApiClient}（打真实网关），离线实现是 {@see MockApiClient}
 *   （不触网，供对外发布版 demo 用）。两套走同一接口 ⇒ 切换只对 {@see Api}
 *   工厂可见，Job 与 Store 不感知。
 */
interface WatermarkApi
{
    /** 取 OSS 直传签名 */
    public function sign(): SignInfo;

    /**
     * 直传一个文件到 OSS（或模拟上传）。
     *
     * @param null|callable(float): void $onProgress 0..1
     */
    public function upload(SignInfo $sign, string $localPath, string $objectKey, ?callable $onProgress = null): void;

    /**
     * 建任务。
     *
     * @param list<string> $objectKeys 已上传的对象键
     *
     * @return string 任务 id
     */
    public function addTask(array $objectKeys, string $time, string $address, string $imageSize): string;

    /** 查任务结果 */
    public function getResult(string $taskId): TaskResult;
}
