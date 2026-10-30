<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Geometry\Rect;
use Drift\Platform\WindowHost;

use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function floatval;
use function is_dir;
use function is_finite;
use function is_file;
use function is_numeric;
use function is_writable;
use function mkdir;
use function rtrim;
use function str_replace;
use function strpos;
use function substr;
use function trim;
use function var_export;

/**
 * 窗口状态持久化（§5.4 / §19 M5）—— **启动读回一次、退出写回一次**。
 *
 * 存的是三样：客户区左上角的**屏幕坐标**（逻辑像素）、客户区宽高、是否最大化。
 * 用户看到的效果：关窗时的位置和大小，下次启动原样还原（§19 M5 行的验收语
 * "窗口位置/尺寸跨启动保留"）。
 *
 * ## 三条纪律（§5.4 原文）
 *
 * 1. **只在启动 / 退出各一次同步 IO**（不在帧内）⇒ 不需要 `TaskHost`。
 *    组合根调用，不是 `Frame::run()` 里的一环。
 * 2. **恢复失败静默降级到默认值**，不报错 —— 显示器拔了、分辨率变了、配置
 *    文件被删，都不该让应用起不来。★ 这是 G2（"绝不静默"）的**显式例外**，
 *    已在 §24.3 例外登记表挂号；每次降级都写一条 `Errors::DIAGNOSTIC`
 *    （可控、可回查，不是无声无息）。
 * 3. **存储后端可换**但**不进 `Platform`**：换它只影响 `Drift\App` 内部实现，
 *    不影响分层。本实现是 kv 文本文件（一个物理磁盘小文件，人可读可改）。
 *
 * ## 为什么最小化时不写（`position()` 返 `null`）
 *
 * 最小化窗口的屏幕坐标是"还原后会出现的位置"，而那时用户要的是**退出前的
 * 真实位置**。Windows 自己在最小化期间会把它挪到 (-32000,-32000) 这种
 * 屏幕外坐标，照抄回来下次启动窗口就"消失"了 ⇒ 平台侧 `position()` 直接给
 * `null`，本类跳过写入（上一份状态留在文件里，是更好的选择）。
 *
 * ## 退出路径的时序陷阱（2026-10-18 真机实测）
 *
 * `persist()` 跑在 `EventLoop::run()` **返回之后**，而 WM_CLOSE 里壳是
 * **同步** `DestroyWindow` —— 等循环跳出，窗口句柄已经死了。此时任何"问窗口"
 * 的端口方法（`position()` / `clientSize()` / `isMaximized()`）对一个死句柄
 * 的 `GetClientRect` 必然失败 ⇒ 首版实现里 persist 第一关就跳过，**一个字都
 * 写不出去**，且无任何报错。解法在壳侧：`drift_win32.cc` 维护"最后已知几何"
 * 缓存（每次活窗口查询顺手刷新 + WM_DESTROY 抓最后一下），死句柄查询回填它。
 * ⇒ **写"退出后仍要问窗口"的代码时，先确认平台侧查的是活句柄还是缓存**。
 *
 * @api
 */
final class WindowStateStore
{
    /** 文件格式版本。改键名 / 加字段时抬它，旧文件整份降级，不做半兼容 */
    private const FORMAT_VERSION = 1;

    public function __construct(
        /** 配置文件路径；`''` = 平台没给配置目录 ⇒ 整个 store 退化为空操作 */
        private string $path,
    ) {
    }

    /**
     * 启动时读回并还原窗口几何（§5.4）。
     *
     * 顺序要求：**必须在 `surface->resize()` 之前** —— resize 决定首帧尺寸，
     * 而首帧就该是还原后的尺寸；放在 resize 之后，用户会先看到一帧默认尺寸
     * 再被拽过去（DESIGN §A.3.8 的样例把它放在 `attach()` 之后，同样偏晚，
     * 已一并修正）。
     *
     * @param int $handle `createWindow()` 给的不透明句柄
     *
     * @api
     */
    public function restore(WindowHost $window, int $handle): void
    {
        // ① 路径为空：平台没给配置目录 —— 这不是错误，跳过就是
        if ($this->path === '') {
            return;
        }

        // ② 文件不存在：第一次运行，正常，别刷 Diagnostic
        if (!is_file($this->path)) {
            return;
        }

        $text = file_get_contents($this->path);
        if ($text === false) {
            Errors::report(
                '窗口状态：配置文件读不出来（' . $this->path . '）—— 用默认窗口几何',
                Errors::DIAGNOSTIC
            );

            return;
        }

        $kv = self::parse($text);
        if (($kv['v'] ?? '') !== (string) self::FORMAT_VERSION) {
            Errors::report(
                '窗口状态：配置文件版本不符（期望 ' . self::FORMAT_VERSION . '，实际 '
                . var_export($kv['v'] ?? null, true) . '）—— 用默认窗口几何',
                Errors::DIAGNOSTIC
            );

            return;
        }

        // ③ 逐字段校验：任何一项不可信 ⇒ 整份放弃（半份状态比没有更糟）
        $x = self::number($kv, 'x');
        $y = self::number($kv, 'y');
        $w = self::number($kv, 'w');
        $h = self::number($kv, 'h');
        if ($x === null || $y === null || $w === null || $h === null) {
            Errors::report('窗口状态：配置文件的坐标/尺寸不是数 —— 用默认窗口几何', Errors::DIAGNOSTIC);

            return;
        }
        if (!($w > 0.0) || !($h > 0.0)) {
            Errors::report('窗口状态：配置文件里的尺寸不是正数 —— 用默认窗口几何', Errors::DIAGNOSTIC);

            return;
        }

        $maximized = ($kv['max'] ?? '0') === '1';

        $window->place($handle, new Rect($x, $y, $w, $h), $maximized);
    }

    /**
     * 退出时写回一次（§5.4）。
     *
     * ★ **调用时机在组合根的循环之外**：`EventLoop::run()` 返回之后。
     *   写文件是同步 IO，放帧内会让某一帧凭空多出几毫秒。
     *
     * @param int $handle `createWindow()` 给的不透明句柄
     *
     * @api
     */
    public function persist(WindowHost $window, int $handle): void
    {
        if ($this->path === '') {
            return;
        }

        $origin = $window->position($handle);
        if ($origin === null) {
            return;   // 最小化：位置无意义，保留上一份（见类注释）
        }

        $size = $window->clientSize($handle);
        if (!($size->width > 0.0) || !($size->height > 0.0)) {
            return;   // 尺寸非正：客户区还没建起来（或已被销毁），没什么可记的
        }

        $text = "# Drift window state — do not edit while the app runs\n"
            . 'v=' . self::FORMAT_VERSION . "\n"
            . 'x=' . $origin->x . "\n"
            . 'y=' . $origin->y . "\n"
            . 'w=' . $size->width . "\n"
            . 'h=' . $size->height . "\n"
            . 'max=' . ($window->isMaximized($handle) ? '1' : '0') . "\n";

        // 目录不存在 / 不可写 ⇒ 记一条然后放弃。**不让它把退出路径搞崩**：
        // 用户关窗必须成功，"记住窗口位置"只是附带服务。
        // 目录不存在 ⇒ 建它（一次性副作用）：壳只给**路径**不创建（`Platform::configDir()`
        //   的契约），创建归**写入方**也就是这里。少了这一行，第一次运行永远写不出去 ——
        //   真机实测：`is_writable()` 对不存在的目录返回 false ⇒ 静默跳过 ⇒ 无痕无文件。
        // 目录不存在 / 不可写 ⇒ 记一条然后放弃。**不让它把退出路径搞崩**：
        // 用户关窗必须成功，"记住窗口位置"只是附带服务。
        // 目录不存在 ⇒ 建它（一次性副作用）：壳只给**路径**不创建（`Platform::configDir()`
        //   的契约），创建归**写入方**也就是这里。少了这一行，第一次运行永远写不出去 ——
        //   真机实测：`is_writable()` 对不存在的目录返回 false ⇒ 静默跳过 ⇒ 无痕无文件。
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (!is_writable($dir)) {
            Errors::report(
                '窗口状态：配置目录不可写（' . $dir . '）—— 本次不保存窗口几何',
                Errors::DIAGNOSTIC
            );

            return;
        }

        if (file_put_contents($this->path, $text) === false) {
            Errors::report(
                '窗口状态：写不进配置文件（' . $this->path . '）—— 本次不保存窗口几何',
                Errors::DIAGNOSTIC
            );
        }
    }

    /**
     * kv 文本 → 数组（`key=value` 一行一条；`#` 起头是注释）。
     *
     * ★ 不用 JSON：字段就五个，`json_decode` 引入的"整个文件要合法"这条约束
     *   比它省的事更贵（坏一个字符整份降级 vs 坏一行只丢一行 —— 而任何一行
     *   坏了我们都整份降级，所以格式强弱在此没有差别）。
     *
     * @return array<string, string>
     */
    private static function parse(string $text): array
    {
        $out = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false || $pos === 0) {
                continue;   // 没有 '=' 或以 '=' 起头：坏行，跳过
            }
            $out[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }

        return $out;
    }

    /** 取一个数值字段；不是数 ⇒ `null`（调用方据此整份降级） */
    private static function number(array $kv, string $key): ?float
    {
        $raw = $kv[$key] ?? null;
        if ($raw === null || !is_numeric($raw)) {
            return null;
        }

        $value = floatval($raw);

        // 显式排 INF/NAN：`is_numeric('INF')` 是 false，但手写文件里的人可能
        // 写 `x=1e999` ⇒ PHP 的 floatval 会给 inf，而 inf 传给平台会算出垃圾矩形。
        return is_finite($value) ? $value : null;
    }
}
