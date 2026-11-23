<?php
declare(strict_types=1);

namespace App\Watermark\Api;

use Drift\App\Wire;
use Drift\Rendering\StatusTone;

/**
 * 一次任务查询的结果（`/task/getResult` 的 `data`）。
 *
 * ★ **`status` 用 `int` 而不是枚举**：服务端还会给 `-1`（原文里 `status === 4 ||
 *   status === -1` 都算失败），而"枚举 case 不存在 ⇒ AOT 构建通过、真机崩"是本仓
 *   踩过的坑。这里只认"已知 0–4" + 一个 `other` 兜底，未知值**不崩、不猜**。
 * ★ `downloadUrls` 是**唯一**的业务产出；其它字段都是展示用的元信息。所以"任务完成
 *   但没有 URL"是一种**失败**（原文的 `'任务完成但无结果图片'`），不是成功。
 */
final class TaskResult
{
    use Wire;

    public const STATUS_CREATED = 0;

    public const STATUS_QUEUED = 1;

    public const STATUS_RUNNING = 2;

    public const STATUS_DONE = 3;

    public const STATUS_FAILED = 4;

    /** 键名恒等于属性名（顺序 == 构造器参数顺序） */
    private const FIELDS = [
        'taskId',
        'status',
        'templateName',
        'imageSize',
        'processingTime',
        'createTime',
        'updateTime',
        'errorMsg',
        'downloadUrls',
    ];

    /**
     * @param int $status 原始状态码（0–4；`-1` 与未知值都归"失败/未知"）
     * @param list<string> $downloadUrls 结果图 URL
     */
    public function __construct(
        public readonly string $taskId,
        public readonly int $status,
        public readonly string $templateName,
        public readonly string $imageSize,
        public readonly string $processingTime,
        public readonly string $createTime,
        public readonly string $updateTime,
        public readonly string $errorMsg,
        public readonly array $downloadUrls,
    ) {
    }

    /**
     * @param mixed $data 服务端给的 `data`（形状未知，必须自己验）
     * @param string $fallbackTaskId 请求时用的 taskId（服务端可能不回这一个字段）
     */
    public static function fromArray(mixed $data, string $fallbackTaskId = ''): ?self
    {
        if (!\is_array($data)) {
            return null;
        }

        $taskId = self::wireString($data, 'taskId');
        if ($taskId === '') {
            $taskId = $fallbackTaskId;
        }

        $rawStatus = $data['status'] ?? null;
        // ★ 状态可能是数字、也可能是数字字符串。两者都认；其它一律 -1（未知）。
        //   不抛：一个状态码格式变了不该让整轮轮询停摆。
        $status = -1;
        if (\is_int($rawStatus)) {
            $status = $rawStatus;
        } elseif (\is_string($rawStatus) && \ctype_digit($rawStatus)) {
            $status = (int) $rawStatus;
        }

        return new self(
            $taskId,
            $status,
            self::wireString($data, 'templateName'),
            self::wireString($data, 'imageSize'),
            self::wireString($data, 'processingTime'),
            self::wireString($data, 'createTime'),
            self::wireString($data, 'updateTime'),
            self::wireString($data, 'errorMsg'),
            self::wireStrings($data['downloadUrls'] ?? null),
        );
    }

    /** 还在跑（0/1/2）—— 轮询要继续 */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_CREATED
            || $this->status === self::STATUS_QUEUED
            || $this->status === self::STATUS_RUNNING;
    }

    /**
     * 终点（不用再轮询）。
     *
     * ★ `-1`（未知状态）**算终点**：继续轮询一个状态码都不认识的任务，只会每 10 秒
     *   重试一次同样的错。停下来并显示原始状态码，用户才知道去找谁。
     */
    public function isTerminal(): bool
    {
        return !$this->isPending();
    }

    /** 真的成功 = 状态 3 **且**有结果图（见类注释） */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_DONE && $this->downloadUrls !== [];
    }

    /**
     * 给用户看的状态文案。
     *
     * ★★ **不能写 `switch ($this->status)`**：tpc 会把 `switch` 的判别式定型成
     *   `php::Int`，而读属性返回的是 `php::Variant` ⇒ 生成的 C++ 是
     *   `php::Int tmp = this_.attr(...)`，MSVC 当场
     *   `error C2440: 无法从 php::Variant 转换为 php::Int`（2026-11-02 实际踩到，
     *   而 `php -l` / smoke / phpunit 全绿 —— 只有 AOT 构建报）。
     *   ⇒ 读属性做分支一律 `===` 的 `if` 链（`MEMORY` 的 AOT 五坑之一）。
     */
    public function statusLabel(): string
    {
        $status = $this->status;

        if ($status === self::STATUS_CREATED) {
            return '已创建';
        }
        if ($status === self::STATUS_QUEUED) {
            return '排队中';
        }
        if ($status === self::STATUS_RUNNING) {
            return '处理中';
        }
        if ($status === self::STATUS_DONE) {
            return $this->downloadUrls === [] ? '处理完成（但没有结果图）' : '处理完成';
        }
        if ($status === self::STATUS_FAILED) {
            return '处理失败';
        }

        // ★ 未知状态把原始码带上：用户截图给运维时这是唯一线索
        return '未知状态 ' . (string) $status;
    }

    /**
     * 业务状态 → 语义档（界面据此取主题色）。
     *
     * ★ 与 `StatusMessage::tone()` 同一层职责：**业务语义住在值对象上**，视图只负责
     *   把档位翻成当前主题的颜色。放在 `ResultCard` 里会让"什么算成功"多一份判据。
     * ★ 未知状态（含 `-1`）落 `Idle`：颜色不对可以查，静默崩掉不行。
     */
    public function statusTone(): StatusTone
    {
        if ($this->isSuccess()) {
            return StatusTone::Success;
        }
        if ($this->isPending()) {
            return StatusTone::Info;
        }
        if ($this->status === self::STATUS_FAILED) {
            return StatusTone::Error;
        }

        return StatusTone::Idle;
    }

    /**
     * 失败理由（成功时为空串）。
     *
     * ★ "完成但没图" 与 "服务端说失败" 是两件事，文案要分开 —— 前者多半是模板
     *   配错，后者是任务本身出错，找的人不一样。
     */
    public function failureReason(): string
    {
        // ★ 成功 ⇒ 空串，**这一句必须在最前面**：状态 3 有图时既不是"完成但没图"、
        //   也不是 pending ⇒ 会一路掉到最后那句"未给出失败原因"，于是**成功也带
        //   一句失败理由**（`testDoneWithUrlsIsSuccess` 就是钉这一条的）。
        if ($this->isSuccess()) {
            return '';
        }
        if ($this->status === self::STATUS_DONE && $this->downloadUrls === []) {
            return '任务完成，但服务端没有返回结果图（模板配置可能有问题）';
        }
        if ($this->errorMsg !== '') {
            return $this->errorMsg;
        }
        if ($this->isPending()) {
            return '';
        }

        return '服务端未给出失败原因（状态 ' . (string) $this->status . '）';
    }
}
