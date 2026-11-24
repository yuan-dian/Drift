<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一次异步 HTTP 请求的骨架事实 —— 能做帧对齐，不含报文。
 *
 * 为什么要独立成记录：抓包已经能回答"发了什么、回了什么"，但没有帧号，
 * 永远回答不了"响应落在哪一帧、让谁重建了"。这条记录存在的唯一理由是那根
 * 对齐轴：startedAtFrame / settledAtFrame 与帧因果共用同一个 frameId。
 *
 * 为什么不记 body / header：体积与隐私，而且抓包做得更好。证据是骨架，
 * 不是副本。请求体可能有上传文件，响应体可能有用户隐私，进了证据包就再
 * 也收不回；而定位问题需要的只是"哪条请求、何时落地、改了谁"。
 *
 * 为什么用帧号而不是墙钟：证据要能在另一台机器重演。墙钟每次都不同，帧号
 * 是确定的；"第 41 帧返回"在任何回放里都是第 41 帧。
 *
 * `landed` 是落地后**同帧**被状态写入的节点 id（归因边在 `noteStateWrite`
 * 里当场写进去）：这是抓包、Network 面板、火焰图都给不了的那一层。
 *
 * @api
 */
final readonly class NetworkRecord
{
    /**
     * @param list<int> $landed 结算同帧被写状态的节点 id（去重、按发生序）
     */
    public function __construct(
        public int $requestId,
        public string $method,
        public string $url,
        public string $host,
        public string $path,
        public int $startedAtFrame,
        public int $settledAtFrame,
        public int $status,
        public int $bytes,
        public float $elapsedMs,
        public string $initiator,
        public bool $ok,
        public array $landed = [],
        public string $progress = '',
    ) {
    }

    /**
     * 帧末把"同帧落地节点"补进记录 —— 记录本身是不可变的，所以返回新对象。
     *
     * 为什么要分两步：`tapNetworkSettle` 发生在 `Future::resolve()` 里（links 跑之前），
     * 而落地节点由随后的 `setState` 才知道。帧末统一回填，避免让观测钩子去改记录。
     *
     * @param list<int> $landed
     */
    public function withLanded(array $landed): self
    {
        return new self(
            $this->requestId, $this->method, $this->url, $this->host, $this->path,
            $this->startedAtFrame, $this->settledAtFrame, $this->status, $this->bytes,
            $this->elapsedMs, $this->initiator, $this->ok, $landed, $this->progress,
        );
    }

    /** 结算帧延迟内有没有落地（成功但没人写状态 ⇒ 0，不猜）。 */
    public function pendingFrames(): int
    {
        if ($this->settledAtFrame <= 0) {
            return 0;
        }

        return \max(0, $this->settledAtFrame - $this->startedAtFrame);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'request' => $this->requestId,
            'method' => $this->method,
            'url' => $this->url,
            'host' => $this->host,
            'path' => $this->path,
            'startedAtFrame' => $this->startedAtFrame,
            'settledAtFrame' => $this->settledAtFrame,
            'pendingFrames' => $this->pendingFrames(),
            'status' => $this->status,
            'bytes' => $this->bytes,
            'elapsedMs' => $this->elapsedMs,
            'initiator' => $this->initiator,
            'ok' => $this->ok,
            'landed' => $this->landed,
            'progress' => $this->progress,
        ];
    }

    /**
     * 证据包里的形状：与 {@see self::toArray()} 只差一个 `pendingFrames`。
     *
     * 那是派生量（`settledAtFrame - startedAtFrame`），存进包里就成了第二份真相：
     * 改一个字段忘了改另一个，包就自相矛盾。
     *
     * @return array<string, mixed>
     */
    public function encode(): array
    {
        return [
            'request' => $this->requestId,
            'method' => $this->method,
            'url' => $this->url,
            'host' => $this->host,
            'path' => $this->path,
            'startedAtFrame' => $this->startedAtFrame,
            'settledAtFrame' => $this->settledAtFrame,
            'status' => $this->status,
            'bytes' => $this->bytes,
            'elapsedMs' => $this->elapsedMs,
            'initiator' => $this->initiator,
            'ok' => $this->ok,
            'landed' => $this->landed,
            'progress' => $this->progress,
        ];
    }

    /** @param array<string, mixed> $raw */
    public static function decode(array $raw): self
    {
        return new self(
            (int) ($raw['request'] ?? 0),
            (string) ($raw['method'] ?? ''),
            (string) ($raw['url'] ?? ''),
            (string) ($raw['host'] ?? ''),
            (string) ($raw['path'] ?? ''),
            (int) ($raw['startedAtFrame'] ?? 0),
            (int) ($raw['settledAtFrame'] ?? 0),
            (int) ($raw['status'] ?? 0),
            (int) ($raw['bytes'] ?? 0),
            (float) ($raw['elapsedMs'] ?? 0.0),
            (string) ($raw['initiator'] ?? ''),
            (bool) ($raw['ok'] ?? false),
            \array_map('\intval', \array_values((array) ($raw['landed'] ?? []))),
            (string) ($raw['progress'] ?? ''),
        );
    }
}
