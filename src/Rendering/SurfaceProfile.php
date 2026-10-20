<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 表面级计时：`beginFrame` / `toRgba` / `present` 三段。
 *
 * ★ **默认关闭，零开销**：`$on` 为 false 时各调用点只做一次静态 bool 读
 *   （AOT 下是一次全局读 + 分支），不取时钟、不写数组。
 *
 * ★ **为什么计时器住 `Rendering` 而不是 `App`**：这三段全在 `RenderSurface`
 *   的实现里，而 `RenderSurface` 属于 `Rendering`（§7.1）。放 `App` 会让
 *   `Platform/Win32/*` 反向依赖 `App` —— 那是 §4.3 明令禁止的方向。
 *
 * ★ 为什么需要它：`26` §3.2 指出 M3 的分母（`settle + layout + paint`）**不含
 *   present**，而真机上一帧的大头很可能就在这里。要判断"该不该拆布局"，必须先
 *   知道 present 占端到端多少 —— 那就是这个类唯一的用途。
 *
 * @api platform 量具入口（`apps/bench` 的 M4 用它）
 */
final class SurfaceProfile
{
    /** 打开后各表面实现开始采样 */
    public static bool $on = false;

    /** @var array<string, int> 段名 → 累计纳秒 */
    public static array $ns = [];

    /** @var array<string, int> 段名 → 调用次数 */
    public static array $calls = [];

    public static function add(string $key, int $elapsedNs): void
    {
        self::$ns[$key] = (self::$ns[$key] ?? 0) + $elapsedNs;
        self::$calls[$key] = (self::$calls[$key] ?? 0) + 1;
    }

    public static function reset(): void
    {
        self::$ns = [];
        self::$calls = [];
    }

    /**
     * 段名 → 每次调用中位纳秒（累计 / 次数）。
     *
     * @return array<string, int>
     */
    public static function perCall(): array
    {
        $out = [];
        foreach (self::$ns as $key => $total) {
            $n = self::$calls[$key] ?? 0;
            $out[$key] = $n > 0 ? (int) \round((float) $total / (float) $n) : 0;
        }

        return $out;
    }
}
