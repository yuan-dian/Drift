<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * 夹具：**不该响**。三件"看着像门面但不是"：
 *
 * ① 树内对象：走 `$this->pipeline()`（`PipelineAccess`）—— 规范写法；
 * ② 树外的普通实例方法：直接 `Pipeline::current()->…`，不是静态、也不是转发；
 * ③ 静态但转发到**别的东西**（工厂）—— `Pipeline` 不是它的目标。
 *
 * ★ 第 ③ 条是这条规则与"所有单行静态转发"的关键区别：后者会把
 *   `make() → PopupPanel::make()` 一起误伤（本仓有 5 处这种工厂）。
 */
final class PipelineFacadeGood
{
    use PipelineAccess;

    public function insideTreeCount(): int
    {
        return $this->pipeline()->rebuiltCount();
    }

    public function outsideTreeCount(): int
    {
        return Pipeline::current()->rebuiltCount();
    }

    public static function make(): self
    {
        return new self();
    }
}
