<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

/**
 * 承接"列宽拖动上报"—— 受控第 ④ 层的应用侧。
 *
 * ★ 记三件事：列 key / 上报宽 / `finished`。拖动中与抬起各报一次**同值**，
 *   应用靠 `finished` 决定"什么时候存盘"（拖动中就存会写几十次盘）。
 */
final class ResizeSink
{
    /** @var list<array{0: string, 1: float, 2: bool}> */
    public array $log = [];

    public function receive(string $key, float $width, bool $finished): void
    {
        $this->log[] = [$key, \round($width, 2), $finished];
    }
}
