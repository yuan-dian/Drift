<?php
declare(strict_types=1);

namespace Drift\Bench;

use Drift\Errors;

/**
 * M0-2：`?Obj` 字段是否退化成 `php::Var`（`08` §3.4）。
 *
 * ── 为什么这个夹具**不计时** ────────────────────────────────────────────
 * 答案在生成的 C++ 里，是**二值**的：字段声明写成 `php::Var` / `php::Variant`
 * 就是退化，写成具体类型就是没退化。计时会被内联 / 分支预测 / 缓存干扰，
 * 而类型名不会。⇒ 这是"先看死、再测快"里的"看死"。
 *
 * ── 官方文档已经给了答案 ────────────────────────────────────────────────
 * `10` §2 A2：`null` / `callable` / `iterable` / 联合 / `?A` / 交叉
 * 在编译期退化为 `php::Var`。所以 `02` §3.1 已按"会退化"改掉
 * （`?Color` → `Color` + `bool`）。
 *
 * ⇒ 本夹具的作用只是"**本仓生成物确实如此**"的一次确认（`09` W0 第 2 步）。
 *
 * ── 两组 ────────────────────────────────────────────────────────────────
 *   A  `private ?RO $maybe;`                  ← 可空对象字段（候选形态）
 *   B  `private RO $always;` + `bool $has`    ← 非空 + 哨兵（`02`/`03` 采用形态）
 *
 * ── 为什么必须真的跑一遍 ────────────────────────────────────────────────
 * AOT 只编译**可达**代码。如果 `get()` 从来没被调用，tpc 可能把整个类
 * 连字段一起优化掉 ⇒ grep 找不到目标，得到的是"不存在"而不是"退化了"。
 * ⇒ `run()` 里**断言读回值正确**，既证明夹具活着，也顺带验了两种形态
 *   的功能等价（这正是 `03` 敢把 `Cell::$owner` 从 `?State` 改成
 *   `State` + `bool` 的前提）。
 */
final class M0Nullable
{
    /**
     * 跑一遍并断言。**不返回计时**，只返回两个读回值（供报告里证明夹具活着）。
     *
     * @return array<string, float>
     */
    public static function run(): array
    {
        $a = new NullableA();
        $a->set(new RO(1.5));
        $av = $a->get();

        $b = new NullableB();
        $b->set(new RO(2.5));
        $bv = $b->get();

        if ($av !== 1.5 || $bv !== 2.5) {
            Errors::report(
                'M0-2 夹具自检失败：NullableA::get()=' . $av . '（应 1.5）、'
                . 'NullableB::get()=' . $bv . '（应 2.5）',
                Errors::DIAGNOSTIC
            );
        }

        return ['A ?RO' => $av, 'B RO+bool' => $bv];
    }

    /**
     * 给报告用的两行说明（结论由**读生成物**补，不在这里判）。
     *
     * @return list<string>
     */
    public static function notes(): array
    {
        return [
            'A `private ?RO $maybe`  NullableA::$maybe 的生成物声明类型（grep apps/bench/build/src/M0Nullable.cc）',
            'B `private RO $always` + `bool $has`  NullableB::$always 的生成物声明类型（应为具体类型）',
        ];
    }
}

/**
 * A 组：可空对象字段。
 *
 * ★ `set()` 收 `RO` 而不是 `?RO`：要观察的是**字段存储**退化，不是形参退化。
 */
final class NullableA
{
    private ?RO $maybe = null;

    public function set(RO $r): void
    {
        $this->maybe = $r;
    }

    public function get(): float
    {
        $m = $this->maybe;
        if ($m === null) {
            return 0.0;
        }

        return $m->v;
    }
}

/**
 * B 组：非空对象字段 + `bool` 哨兵。
 *
 * ★★ `$always` **不给默认值**（未初始化类型化属性）：`RO` 没有可写的默认实例，
 *    而写成 `?RO $always = null` 就退回 A 组的形态、观察不到东西了。
 *    `set()` 永远先于 `get()` 调用（`run()` 里紧邻两行），语义安全。
 */
final class NullableB
{
    private RO $always;

    private bool $has = false;

    public function set(RO $r): void
    {
        $this->always = $r;
        $this->has = true;
    }

    public function get(): float
    {
        if (!$this->has) {
            return 0.0;
        }

        return $this->always->v;
    }
}
