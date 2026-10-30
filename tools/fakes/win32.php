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
        if (isset($GLOBALS['DRIFT_SMOKE_WIN_HANDLE'])) {
            return $GLOBALS['DRIFT_SMOKE_WIN_HANDLE'];
        }

        // ★ 连续建窗依次递增（多窗口用例要能区分两个窗）；`winLoopReset()` 归零
        //   ⇒ 每个用例的**第一个**窗仍然是 1（既有断言不受影响）。
        $GLOBALS['DRIFT_SMOKE_WIN_SEQ'] = ($GLOBALS['DRIFT_SMOKE_WIN_SEQ'] ?? 0) + 1;

        return $GLOBALS['DRIFT_SMOKE_WIN_SEQ'];
    }
}

if (!function_exists('drift_win_client_size')) {
    function drift_win_client_size(int $hwnd): array
    {
        return $GLOBALS['DRIFT_SMOKE_CLIENT'] ?? ['w' => 640, 'h' => 480];
    }
}

// ★ 软光栅表面的 `present()` 一帧调一次。假桥里**必须存在**：`WindowSet::open()`
//   内部会 `attach()` 一帧，缺它整条装配链在无头下不可测（真桥传的是整块 RGBA 字节，
//   这里只记"哪个窗 + 多大"，像素内容由 golden 那套单独验）。
if (!function_exists('drift_win_present')) {
    function drift_win_present(int $hwnd, string $rgba, int $width, int $height): void
    {
        $GLOBALS['DRIFT_SMOKE_PRESENT'][] = [$hwnd, $width, $height, \strlen($rgba)];
    }
}

/** @return list<array{int, int, int, int}> 上屏记录：[hwnd, w, h, bytes] */
function winPresentCalls(): array
{
    return $GLOBALS['DRIFT_SMOKE_PRESENT'] ?? [];
}

// ── 装配链（`WindowSet`）用到的"只做记录"的那一族 ─────────────────────────
// ★ 它们此前在假桥里**不存在**：`WindowState` 那组用例走的是 `FakeWindowHost`，
//   从没让真 `Win32WindowHost` 在假桥下跑过"建窗 → 还原 → 写回"整条链。
//   `WindowSet` 的无头用例第一次需要它，缺一个就是未定义函数直接崩。

if (!function_exists('drift_win_show')) {
    function drift_win_show(int $hwnd): void
    {
    }
}

if (!function_exists('drift_win_set_title')) {
    function drift_win_set_title(int $hwnd, string $title): void
    {
    }
}

if (!function_exists('drift_win_set_min_size')) {
    function drift_win_set_min_size(int $hwnd, int $width, int $height): void
    {
        $GLOBALS['DRIFT_SMOKE_MIN_SIZE'][$hwnd] = [$width, $height];
    }
}

if (!function_exists('drift_win_begin_drag')) {
    function drift_win_begin_drag(int $hwnd): void
    {
    }
}

if (!function_exists('drift_win_minimize')) {
    function drift_win_minimize(int $hwnd): void
    {
    }
}

if (!function_exists('drift_win_maximize_toggle')) {
    function drift_win_maximize_toggle(int $hwnd): void
    {
        $GLOBALS['DRIFT_SMOKE_MAXIMIZED'] = !($GLOBALS['DRIFT_SMOKE_MAXIMIZED'] ?? false);
    }
}

if (!function_exists('drift_win_is_maximized')) {
    function drift_win_is_maximized(int $hwnd): bool
    {
        return (bool) ($GLOBALS['DRIFT_SMOKE_MAXIMIZED'] ?? false);
    }
}

if (!function_exists('drift_win_window_position')) {
    function drift_win_window_position(int $hwnd): array
    {
        // 空数组 = 最小化（`Win32WindowHost::position()` 据此回 null，`persist()` 跳过）
        if ($GLOBALS['DRIFT_SMOKE_MINIMIZED'] ?? false) {
            return [];
        }

        return $GLOBALS['DRIFT_SMOKE_POSITION'] ?? ['x' => 100, 'y' => 80];
    }
}

if (!function_exists('drift_win_place_window')) {
    function drift_win_place_window(
        int $hwnd,
        float $x,
        float $y,
        float $w,
        float $h,
        bool $maximized
    ): void {
        $GLOBALS['DRIFT_SMOKE_POSITION'] = ['x' => (int) $x, 'y' => (int) $y];
        $GLOBALS['DRIFT_SMOKE_CLIENT'] = ['w' => (int) $w, 'h' => (int) $h];
        $GLOBALS['DRIFT_SMOKE_MAXIMIZED'] = $maximized;
    }
}

if (!function_exists('drift_win_uia_push')) {
    function drift_win_uia_push(int $hwnd, array $nodes): void
    {
        $GLOBALS['DRIFT_SMOKE_UIA'][] = [$hwnd, \count($nodes)];
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
    function drift_win_ime_caret(int $hwnd, float $x, float $y, float $h): void
    {
        $GLOBALS['DRIFT_SMOKE_IME'][] = [$hwnd, $x, $y, $h];
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

// ══════════════════════════════════════════════════════════════════════════
// 关闭（2026-10-30：请求 / 执行二分 + 可拦截）
//
// ★ 假桥**不模拟**"销毁之后句柄失效"：那会让每个用到它的用例都要改夹具
//   （`clientSize()` 突然 0×0）。这里只录事实 —— "框架有没有真的执行销毁"，
//   而这正是 `EventLoop` 那条分支唯一要回答的问题。
// ══════════════════════════════════════════════════════════════════════════

if (!function_exists('drift_win_close')) {
    function drift_win_close(int $hwnd): void
    {
        $GLOBALS['DRIFT_SMOKE_CLOSE'][] = ['request', $hwnd];
    }
}

if (!function_exists('drift_win_destroy')) {
    function drift_win_destroy(int $hwnd): void
    {
        $GLOBALS['DRIFT_SMOKE_CLOSE'][] = ['destroy', $hwnd];
        winDestroyedByShell($hwnd);   // 销毁之后就不活了（与真壳的 `IsWindow` 一致）
    }
}

if (!function_exists('drift_win_is_alive')) {
    /**
     * 假壳的存活查询：默认活着，被 `winDestroyedByShell()` 标记过的不活。
     *
     * ★ **不能靠 `drift_win_destroy()` 的记账推断**：没有声明关闭守卫的窗口是**壳
     *   自己** `DestroyWindow` 的，那条路上 `drift_win_destroy()` 根本不会被调用。
     *   多窗口用例里用 `winDestroyedByShell($h)` 显式模拟这一支。
     */
    function drift_win_is_alive(int $hwnd): bool
    {
        return !\in_array($hwnd, $GLOBALS['DRIFT_SMOKE_DEAD'] ?? [], true);
    }
}

/** 模拟"**壳自己**销毁了这个窗口"：没有事件、没有 `destroy()` 调用，就是没了 */
function winDestroyedByShell(int $hwnd): void
{
    $GLOBALS['DRIFT_SMOKE_DEAD'][] = $hwnd;
}

if (!function_exists('drift_win_set_close_guard')) {
    function drift_win_set_close_guard(int $hwnd, bool $armed): void
    {
        $GLOBALS['DRIFT_SMOKE_CLOSE'][] = ['guard', $hwnd, $armed];
    }
}

if (!function_exists('drift_win_live_window')) {
    /**
     * 假壳的"任一活窗口"= 那个假句柄。
     *
     * ★ 不模拟"活窗口表"：假壳里 `destroy()` 只记账、窗口不会真的消失，而本函数
     *   唯一用途是给**等待定时器**找个能挂的窗口（`SetTimer` 需要 hwnd）。
     *   真要验"关一个留一个"的行为，走 `apps/hello` 的双窗真机探针 —— 那才是
     *   这条语义的验收位置（假壳里没有"活"这个概念）。
     */
    function drift_win_live_window(): int
    {
        return $GLOBALS['DRIFT_SMOKE_WIN_HANDLE'] ?? 1;
    }
}

/** @return list<array{0: string, 1: int, 2?: bool}> 关闭调用记录：['request'|'destroy'|'guard', hwnd, armed] */
function winCloseCalls(): array
{
    return $GLOBALS['DRIFT_SMOKE_CLOSE'] ?? [];
}

// ── 原生模态记账（2026-10-30：帧时钟要从"两帧间隔"里剔除模态时间）────────
// ★ 假桥**可以停留**（`winMessageBoxLatencyFake(ms)`）：`fatalBox()` 的账本
//   记的是"进桥到出桥"，而那个数字是否>0 只有让桥真的耗掉一点时间才验得着
//   —— 不停留的话 `hrtime` 差值小到无法断言（">= 0"是恒真的空判据）。

if (!function_exists('drift_win_message_box')) {
    function drift_win_message_box(string $title, string $body): void
    {
        $ms = (float) ($GLOBALS['DRIFT_SMOKE_MSGBOX_MS'] ?? 0.0);
        if ($ms > 0.0) {
            \usleep((int) ($ms * 1000));
        }
        $GLOBALS['DRIFT_SMOKE_MSGBOX'][] = [$title, $body];
    }
}

/** @return list<array{0: string, 1: string}> 消息框调用记录：[title, body] */
function winMessageBoxCalls(): array
{
    return $GLOBALS['DRIFT_SMOKE_MSGBOX'] ?? [];
}

/** 让假消息框停留 N 毫秒（模拟"用户在框里看了一会儿"）；`0` = 立刻返回 */
function winMessageBoxLatencyFake(float $ms): void
{
    $GLOBALS['DRIFT_SMOKE_MSGBOX_MS'] = $ms;
}

/** 清空泵剧本 / 定时器 / 光标 / IME 记录（每个用例前必调） */
function winLoopReset(): void
{
    $GLOBALS['DRIFT_SMOKE_PUMP'] = [];
    $GLOBALS['DRIFT_SMOKE_TIMERS'] = [];
    $GLOBALS['DRIFT_SMOKE_CURSOR'] = [];
    $GLOBALS['DRIFT_SMOKE_IME'] = [];
    $GLOBALS['DRIFT_SMOKE_CLOSE'] = [];
    $GLOBALS['DRIFT_SMOKE_MSGBOX'] = [];
    $GLOBALS['DRIFT_SMOKE_PRESENT'] = [];
    unset($GLOBALS['DRIFT_SMOKE_LAST_TIMER_ID'], $GLOBALS['DRIFT_SMOKE_WIN_HANDLE'], $GLOBALS['DRIFT_SMOKE_CLIENT']);
    unset($GLOBALS['DRIFT_SMOKE_WIN_SEQ'], $GLOBALS['DRIFT_SMOKE_DEAD']);

    // ★ 时钟回到 0：每个用例都从同一个原点开始，`after(400)` 的到点时刻才可断言。
    $GLOBALS['DRIFT_SMOKE_NOW'] = 0.0;
}
