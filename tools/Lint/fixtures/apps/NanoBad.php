<?php
declare(strict_types=1);

namespace App;

/**
 * 夹具：`nano-hygiene` 的**已知错误**（2026-11-04 补）。
 *
 * ★ 这条规则从 M1 起就在，但一直没有夹具（`selftest.php` 里是空数组）⇒
 *   只验过"能报"、没验过"不误报"。解禁 `Fiber` 的同一棒把它补上：
 *   解禁本身也要有判据，否则下次有人把 `Fiber` 加回去没人拦得住。
 *
 * 本文件预期 **5 条 error**：`\Generator`（返回类型）× 1、`yield` × 2、
 * `eval` × 1、`getenv()` × 1。
 */
final class NanoBad
{
    /** @return \Generator<int> */
    public function items(): \Generator
    {
        yield 1;
        yield 2;
    }

    public function run(string $code): void
    {
        eval($code);
    }

    public function home(): string
    {
        return (string) getenv('HOME');
    }
}
