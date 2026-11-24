<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一条因果边：本帧某个节点**为什么**被重建。
 *
 * 四种 kind 覆盖了实际能观测到的全部来源：
 * - `state`   自己的 State 写了（`setState` / `markDirty`），`site` 是调用点
 * - `parent`  自己没写，父节点重建带着重建（声明式重建的常规路径）
 * - `unknown` 既没写也没找到重建中的父节点（浮层 / 跨树 / 计数漂移，值得看）
 *
 * 没有 `input` / `async` 两种：它们最终都表现为某处 `setState`，单独记会与 `state`
 * 重复。要追"哪个输入引起的"，看 `site` 与同帧的 effect 流。
 *
 * @api
 */
final readonly class Edge
{
    public function __construct(
        public string $kind,
        public ?int $fromId,
        public int $toId,
        public string $site,
        public string $detail,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'from' => $this->fromId,
            'to' => $this->toId,
            'site' => $this->site,
            'detail' => $this->detail,
        ];
    }

    /**
     * 证据包里的形状：与 {@see self::toArray()} 目前相同。
     *
     * 分开两个方法不是重复，是**允许它们将来不同**：投影可以加派生字段、可以改名，
     * 而证据包必须逐值往返。现在相同不代表以后相同。
     *
     * @return array<string, mixed>
     */
    public function encode(): array
    {
        return $this->toArray();
    }

    /** @param array<string, mixed> $raw */
    public static function decode(array $raw): self
    {
        return new self(
            (string) ($raw['kind'] ?? 'unknown'),
            isset($raw['from']) ? (int) $raw['from'] : null,
            (int) ($raw['to'] ?? 0),
            (string) ($raw['site'] ?? ''),
            (string) ($raw['detail'] ?? ''),
        );
    }
}
