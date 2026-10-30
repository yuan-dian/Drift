<?php
declare(strict_types=1);

/**
 * Win32 桥的**解释器替身**（smoke 与 PHPUnit 共用一份）。
 *
 * ★ smoke / phpunit 从不构造真窗口，只验"PHP 侧把意图翻成了哪个 ABI 编号 / 哪个 bool"。
 * ★ 签名与 `cpp-src/drift_win32.stub.php` 逐字一致（stub 原话：签名不一致是
 *   链接期静默失败）。收敛后三个散落入口（set_opacity / set_pixel_alpha /
 *   set_backdrop）合成一个 `apply_material`，外加能力查询 `material_caps`。
 * ★ `set_frameless` / `dpi` 也假：像素 alpha 要无边框，`applyMaterial()` 的
 *   正路径测试需要先 `setFrameless()`，而它内部会读 `dpi` 折物理像素。
 *
 * ★ `drift_win_material_caps()` **可配**（`$GLOBALS['DRIFT_SMOKE_WIN_CAPS']`）：
 *   恒返"全能力"的话，"五个 getter 各对应哪一位"这条断言**证伪不了**
 *   —— 把位 1 和位 2 的解析写反，五项仍然全 true（2026-10-28 第 25 批实测）。
 *   默认仍是全能力（Win11 形态），旧断言不受影响。
 */
// ── Win32 桥的假实现（窗口外观材质，P1 收敛 2026-10-26）────────────────
// ★ smoke 从不构造真窗口，只验"PHP 侧把意图翻成了哪个 ABI 编号 / 哪个 bool"。
// ★ 签名与 `cpp-src/drift_win32.stub.php` 逐字一致（stub 原话：签名不一致是
//   链接期静默失败）。收敛后三个散落入口（set_opacity / set_pixel_alpha /
//   set_backdrop）合成一个 `apply_material`，外加能力查询 `material_caps`。
// ★ `set_frameless` / `dpi` 也假：像素 alpha 要无边框，`applyMaterial()` 的
//   正路径测试需要先 `setFrameless()`，而它内部会读 `dpi` 折物理像素。
if (!function_exists('drift_win_apply_material')) {
    function drift_win_apply_material(int $hwnd, bool $pixelAlpha, float $opacity, int $backdropKind): void
    {
        $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'][] = [$hwnd, $pixelAlpha, $opacity, $backdropKind];
    }
}

/** @return list<array{int, bool, float, int}> 材质调用记录：[hwnd, pixelAlpha, opacity, backdropKind] */
function winMaterialCalls(): array
{
    return $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'] ?? [];
}

if (!function_exists('drift_win_set_frameless')) {
    function drift_win_set_frameless(int $hwnd, int $dragHeight): void
    {
        $GLOBALS['DRIFT_SMOKE_WIN_FRAMELESS'][] = [$hwnd, $dragHeight];
    }
}

if (!function_exists('drift_win_dpi')) {
    function drift_win_dpi(int $hwnd): int
    {
        return 96;   // 假成 96 DPI ⇒ dpr 1.0；真实 Win32 的一致性由真机闸门保证
    }
}

if (!function_exists('drift_win_material_caps')) {
    function drift_win_material_caps(): int
    {
        // 默认"全能力"（Win11 形态）：opacity | pixelAlpha | blur | acrylic | 圆角。
        // ★ 夹具可以覆盖成**部分能力**，否则"位 ↔ getter 名字"这条断言证伪不了。
        return $GLOBALS['DRIFT_SMOKE_WIN_CAPS'] ?? (0x01 | 0x02 | 0x04 | 0x08 | 0x10);
    }
}

/** 清空材质调用记录（每个用例前必调，否则用例互相污染） */
function winMaterialReset(): void
{
    $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'] = [];
}

/**
 * 设定假桥报告的能力位掩码；`null` 恢复默认（全能力 = Win11 形态）。
 *
 * 位定义见 `WindowMaterialCapabilities::fromBits()`：1 opacity / 2 pixelAlpha /
 * 4 blur / 8 acrylic / 16 圆角。
 */
function winMaterialCapsFake(?int $bits = null): void
{
    if ($bits === null) {
        unset($GLOBALS['DRIFT_SMOKE_WIN_CAPS']);

        return;
    }
    $GLOBALS['DRIFT_SMOKE_WIN_CAPS'] = $bits;
}

// ══════════════════════════════════════════════════════════════════════════
// 消息泵 / 窗口 / 定时器（2026-10-30 N2：等待与派发拆开后才可能无头测）
//
// ★ 为什么这里要有**脚本化的泵**：`Win32Platform::waitEvents()` 里那套
//   "取一条 → 判断是不是我那个闹钟 → 暂存或退出" 的分支，是 N2 的**唯一**
//   新增逻辑，而它恰恰只能在"桥回什么"给定的情况下才跑得起来。把它假成
//   "按剧本回事件"，整段就能在没有窗口的情况下验 —— 这是本仓"桥可假、
//   策略不可假"的分界（`tools/fakes/skia.php` 同一条思路）。
// ══════════════════════════════════════════════════════════════════════════

if (!function_exists('drift_win_pump')) {
    /**
     * 按剧本回一条桥事件；剧本空了 ⇒ `quit`（测试不会挂死）。
     *
     * ★ 剧本里写 `'id' => 'armed'` 的 `timer` 事件会被替换成**最近一次
     *   `SetTimer` 用的那个 id** —— 现实里壳回的正是那个 id，而测试不该
     *   去硬编码 `Win32Platform` 的私有常量（那是实现细节）。
     */
    function drift_win_pump(): array
    {
        $script = &$GLOBALS['DRIFT_SMOKE_PUMP'];

        if (!\is_array($script) || $script === []) {
            return ['type' => 'quit'];
        }

        $event = \array_shift($script);

        if (($event['type'] ?? '') === 'timer' && ($event['id'] ?? null) === 'armed') {
            $event['id'] = $GLOBALS['DRIFT_SMOKE_LAST_TIMER_ID'] ?? 0;
        }

        return $event;
    }
}

/** 设定泵剧本（每次调用重置剧本位置） */
function winPumpScript(array $events): void
{
    $GLOBALS['DRIFT_SMOKE_PUMP'] = $events;
}

/** 剧本还剩几条（验"泵只取了一条"） */
function winPumpRemaining(): int
{
    return \count($GLOBALS['DRIFT_SMOKE_PUMP'] ?? []);
}

if (!function_exists('drift_win_create')) {
    function drift_win_create(string $title, int $width, int $height): int
    {
        return $GLOBALS['DRIFT_SMOKE_WIN_HANDLE'] ?? 1;
    }
}

if (!function_exists('drift_win_client_size')) {
    function drift_win_client_size(int $hwnd): array
    {
        return $GLOBALS['DRIFT_SMOKE_CLIENT'] ?? ['w' => 640, 'h' => 480];
    }
}

if (!function_exists('drift_win_set_cursor')) {
    function drift_win_set_cursor(int $hwnd, int $shape): void
    {
        $GLOBALS['DRIFT_SMOKE_CURSOR'][] = [$hwnd, $shape];
    }
}

/** @return list<array{int, int}> 光标调用记录：[hwnd, shape] */
function winCursorCalls(): array
{
    return $GLOBALS['DRIFT_SMOKE_CURSOR'] ?? [];
}

if (!function_exists('drift_win_ime_caret')) {
    function drift_win_ime_caret(float $x, float $y, float $h): void
    {
        $GLOBALS['DRIFT_SMOKE_IME'][] = [$x, $y, $h];
    }
}

if (!function_exists('drift_win_set_timer')) {
    function drift_win_set_timer(int $hwnd, int $id, int $ms): bool
    {
        $GLOBALS['DRIFT_SMOKE_TIMERS'][] = ['start', $id, $ms];
        $GLOBALS['DRIFT_SMOKE_LAST_TIMER_ID'] = $id;

        return true;
    }
}

if (!function_exists('drift_win_kill_timer')) {
    function drift_win_kill_timer(int $hwnd, int $id): void
    {
        $GLOBALS['DRIFT_SMOKE_TIMERS'][] = ['stop', $id, 0];
    }
}

/** @return list<array{0: string, 1: int, 2: int}> 定时器调用记录：['start'|'stop', id, ms] */
function winTimerCalls(): array
{
    return $GLOBALS['DRIFT_SMOKE_TIMERS'] ?? [];
}

if (!function_exists('drift_win_now_ms')) {
    /**
     * 单调毫秒（可被夹具推动）。
     *
     * ★ 必须**可控**：`EventLoop` 的每一轮都问一次"还要等多久"，而那个答案取决于
     *   `Platform::now()` 与定时器到点时刻之差。假成 `microtime()` 的话，断言只能
     *   写"大约 400ms"（在 CI 上就是随机红）。这里给夹具一个可写的时钟。
     */
    function drift_win_now_ms(): float
    {
        return (float) ($GLOBALS['DRIFT_SMOKE_NOW'] ?? 0.0);
    }
}

/** 拨假时钟（毫秒，绝对刻度；`0` = 用例起点） */
function winNowSet(float $ms): void
{
    $GLOBALS['DRIFT_SMOKE_NOW'] = $ms;
}

/** @return list<array{0: float, 1: float, 2: float}> IME 上报记录：[x, y, h] */
function winImeCalls(): array
{
    return $GLOBALS['DRIFT_SMOKE_IME'] ?? [];
}

/** 清空泵剧本 / 定时器 / 光标 / IME 记录（每个用例前必调） */
function winLoopReset(): void
{
    $GLOBALS['DRIFT_SMOKE_PUMP'] = [];
    $GLOBALS['DRIFT_SMOKE_TIMERS'] = [];
    $GLOBALS['DRIFT_SMOKE_CURSOR'] = [];
    $GLOBALS['DRIFT_SMOKE_IME'] = [];
    unset($GLOBALS['DRIFT_SMOKE_LAST_TIMER_ID'], $GLOBALS['DRIFT_SMOKE_WIN_HANDLE'], $GLOBALS['DRIFT_SMOKE_CLIENT']);

    // ★ 时钟回到 0：每个用例都从同一个原点开始，`after(400)` 的到点时刻才可断言。
    $GLOBALS['DRIFT_SMOKE_NOW'] = 0.0;
}
