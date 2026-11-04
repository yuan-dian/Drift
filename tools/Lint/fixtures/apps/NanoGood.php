<?php
declare(strict_types=1);

namespace App;

/**
 * 夹具：`nano-hygiene` 的**不该响**形态（2026-11-04 补）。
 *
 * ★ 重点是 `Fiber` 三处（属性类型 / `new` / 静态调用）：它在 2026-11-04 从清单里
 *   移除（理由见 `NanoHygiene` 类注释）—— 这个文件是"移除"这件事的判据。
 * ★ 顺带钉：`getenv` 作**方法名**不该报（规则只抓全局函数调用）。
 */
final class NanoGood
{
    private ?\Fiber $fiber = null;

    public function start(): mixed
    {
        $this->fiber = new \Fiber(function (): void {
            \Fiber::suspend('paused');
        });

        return $this->fiber->start();
    }

    public function current(): ?\Fiber
    {
        return \Fiber::getCurrent();
    }

    public function getenv(string $name): string
    {
        return $name;
    }
}
