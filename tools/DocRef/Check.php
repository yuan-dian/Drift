<?php
declare(strict_types=1);

namespace Drift\Dev\DocRef;

use Drift\Dev\DocRef\DocSet;

/**
 * 一条文档引用检查。
 *
 * ★ 与 `Drift\Dev\Lint\Rule` 同构但独立：lint 查**代码**、docref 查**文档**，
 *   两套语义不同不混接口。`Diagnostic` 复用 lint 的（字段形状通用：
 *   file=文档路径 / line=文档内行号 / rule=检查名）。
 * ★ `selftest()` 是**必须的**（§20 纪律 1）：每条检查都要能自证"该响会响、
 *   不该响不误响"—— 前提不成立的断言比没有断言更坏（假绿）。
 */
interface Check
{
    /** 检查名，`--list` 与汇总对账用 */
    public function name(): string;

    /** 一句话判据 */
    public function summary(): string;

    /**
     * 跑检查。
     *
     * @return list<\Drift\Dev\Lint\Diagnostic>
     */
    public function run(DocSet $docs): array;

    /**
     * 反例自检：构造已知违规必须红、已知合法必须绿。
     *
     * @throws \RuntimeException 自检失败（症状要写进消息）
     */
    public function selftest(): void;
}
