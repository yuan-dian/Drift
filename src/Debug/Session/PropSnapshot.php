<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一次按需属性取证的结果。
 *
 * ★ 为什么是快照而不是"活树引用"：`props(node)` 读的是当前活树，但返回值会进
 *   `Recorder` 的独立窗口、随证据包导出。如果只存引用，导出后树没了，读者拿到
 *   的是"查不到"；如果存标量，读者能回答"第 N 帧时这个控件配的是什么"。
 *
 * ★ 字段值一律是**带 tag 的数组**（见 {@see PropReader}），不裸放原值：
 *   `json_encode` 对 NAN / INF 直接失败，闭包与资源根本不能序列化。
 *
 * @api
 */
final readonly class PropSnapshot
{
    /**
     * @param list<array{name: string, owner: string, value: array<string, mixed>}> $fields
     */
    public function __construct(
        public int $node,
        public int $frame,
        /** Widget 的**实类**短名（`Table`），不是 `StatefulElement` —— 面板要认控件。 */
        public string $widget,
        /** Element 短名，供诊断"为什么没有自己的 Widget 类"。 */
        public string $element,
        public ?string $state,
        public array $fields,
        /** 字段数超过 `maxFields` 被丢掉的数量。 */
        public int $omitted,
        /** 深度 / 节点预算耗尽处数（树被截断）。 */
        public int $cut,
        /** 命中循环引用的次数。 */
        public int $cycles,
        /** 换掉几处凭证（键名或值形态命中 {@see Redactor}）。 */
        public int $redacted,
        /** `false` 时 `redacted: 0` 表示"没扫"，不是"扫了没发现"。 */
        public bool $redact,
        public float $costMs,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'node' => $this->node,
            'frame' => $this->frame,
            'widget' => $this->widget,
            'element' => $this->element,
            'state' => $this->state,
            'fields' => $this->fields,
            'omitted' => $this->omitted,
            'cut' => $this->cut,
            'cycles' => $this->cycles,
            'redacted' => $this->redacted,
            'redact' => $this->redact,
            'costMs' => $this->costMs,
        ];
    }

    /**
     * 证据包里的形状：字段全是标量 / 带 tag 数组，与 {@see self::toArray()} 相同。
     *
     * 分开保留是为了与其它记录同形——调用处不必记"这个类能不能直接 encode"。
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
        /** @var list<array{name: string, owner: string, value: array<string, mixed>}> $fields */
        $fields = \array_values(\array_filter((array) ($raw['fields'] ?? []), '\is_array'));

        return new self(
            (int) ($raw['node'] ?? 0),
            (int) ($raw['frame'] ?? 0),
            (string) ($raw['widget'] ?? ''),
            (string) ($raw['element'] ?? ''),
            isset($raw['state']) ? (string) $raw['state'] : null,
            $fields,
            (int) ($raw['omitted'] ?? 0),
            (int) ($raw['cut'] ?? 0),
            (int) ($raw['cycles'] ?? 0),
            (int) ($raw['redacted'] ?? 0),
            (bool) ($raw['redact'] ?? true),
            (float) ($raw['costMs'] ?? 0.0),
        );
    }
}
