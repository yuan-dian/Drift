<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * "谁读了谁" 的采集栈（`03` §4 的自动依赖）。
 *
 * 只做一件事：**在一段同步代码运行期间，把 `Versioned` 的读取收集起来**。
 * 谁收集、收了之后干什么，本类不管 —— 于是 `Computed`（住 Core）与
 * `State`（住 Widget）能共用同一套采集，而不互相依赖。
 *
 * ```php
 * Tracker::enter();
 * try { $value = $compute(); } finally { $deps = Tracker::leave(); }
 * ```
 *
 * ★ **栈而不是单槽**：`Computed` 求值时会再读别的 `Computed` / `Cell`，那些读
 * 必须记到**内层**（否则外层的订阅表会膨胀，且"派生值没变也重建"）。栈天然
 * 给出这个归属：读只记到最顶那一层。
 * ★ **帧外读 = 不记**：事件处理器里读值是正常需求（"先看当前值再决定"），
 * 不该被当成依赖。要显式表达"我不订阅"用 `Cell::peek()`。
 * ★ **天然去重**：按对象 id 入表，同一依赖读两次只留一份；一次 build 里重复读
 * 很常见（同一个格子出现在两个分支）。
 *
 * @internal 用户面是 `Cell` / `Computed` / `Bind`，不是本类
 */
final class Tracker
{
    /**
     * 采集帧栈；每帧 = `对象 id => Versioned`。
     *
     * 存强引用（不是 id 列表）是刻意的：依赖在帧存活期间不能被回收，否则
     * `spl_object_id` 会被新对象复用，退订时退到别人头上。
     *
     * @var list<array<int, Versioned>>
     */
    private static array $frames = [];

    /** @internal 开一帧（必须与 `leave()` 配对，用 try/finally） */
    public static function enter(): void
    {
        self::$frames[] = [];
    }

    /**
     * 关帧并交出这一帧读到的依赖（去重、保序）。
     *
     * @return array<int, Versioned>
     *
     * @internal
     */
    public static function leave(): array
    {
        return \array_pop(self::$frames) ?? [];
    }

    /**
     * 记一次读：只记到最顶那一帧；帧外调用是空操作。
     *
     * @internal `Cell::get()` / `Computed::get()` 调
     */
    public static function record(Versioned $dependency): void
    {
        if (self::$frames === []) {
            return;
        }

        $top = \count(self::$frames) - 1;
        self::$frames[$top][\spl_object_id($dependency)] = $dependency;
    }
}
