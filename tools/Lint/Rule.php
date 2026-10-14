<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

/**
 * 一条 lint 规则。
 *
 * ★ `serves()` 不许留空（§16.6.3 裁决）：规则必须服务某条公理（G1/G2/G3）
 *   或某个反模式（A#）—— 守不住任何东西的规则 = A1 的规则版。
 */
interface Rule
{
    /** 规则名，与 §16.6 的表一致 */
    public function name(): string;

    /** 服务的公理或反模式，如 `G2` / `A16` */
    public function serves(): string;

    /** 一句话判据，`--list` 与文档对账用 */
    public function summary(): string;

    /** @return list<Diagnostic> */
    public function check(Project $project): array;
}
