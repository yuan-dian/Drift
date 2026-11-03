<?php
declare(strict_types=1);

namespace Drift\Dev\Support;

/**
 * 一条判据的结果 —— `CheckReport` 的唯一元素类型。
 *
 * ★ 为什么要有它（2026-11-03）：`tools/` 里"编号 + 通过? + 一行说明"这套三元组
 *   被手写了 **6 份**（`frame-tax-probe` 两处、`rva2sym`、`M2Conformance`、`smoke`
 *   的 `check()`，外加 `gate` 的编排项）。每份都自带 `$fail++` 与自己的收尾打印，
 *   ⇒ "判了多少条""红了几条""退出码是多少"是**三个各自维护的数**。
 *   同一个事实记三处，改一处必漂 —— 与 §15「九段的两个账本」是同一个病。
 */
final class CheckItem
{
    /**
     * @param string $id   判据编号或短名（`①` / `golden` / 用例名）。同一份报告里必须唯一。
     * @param bool   $ok   没失败。★ `$skipped` 的项也传 `true`：它没失败，只是没测到。
     * @param string $text 一行说明。★ 失败时必须说清"实际是什么"，不能只说"不对"。
     * @param array<string, mixed> $data 给机器看的结构化补充。
     *        ★ 只放**文本表达不了**的（失败页名、耗时、退出码）；
     *          能写进 `$text` 的不必再放一份 —— 那又是两个账本。
     * @param bool   $skipped 这一项**没测到**（依赖没装 / 退出码 2）。
     *        ★ 必须能与"通过"分辨：本仓反复强调"跳过不是通过"（`gate` 的 `$may_skip`）。
     *          文本印 `skip`，JSON 给 `status:"skip"`，两边都不许伪装成 `ok`。
     */
    public function __construct(
        public readonly string $id,
        public readonly bool $ok,
        public readonly string $text,
        public readonly array $data = [],
        public readonly bool $skipped = false,
    ) {
    }

    /** 三态：`ok` / `fail` / `skip`。★ 加一态就改这里，`text()` 与 `json()` 都读它。 */
    public function status(): string
    {
        if ($this->skipped) {
            return 'skip';
        }

        return $this->ok ? 'ok' : 'fail';
    }

    /** @return array{id: string, status: string, ok: bool, text: string, data?: array<string, mixed>} */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'status' => $this->status(),
            'ok' => $this->ok,
            'text' => $this->text,
        ];
        if ($this->data !== []) {
            $out['data'] = $this->data;
        }

        return $out;
    }
}
