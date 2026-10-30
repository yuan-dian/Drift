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
use function implode;
use function ksort;
use function mkdir;
use function preg_match;
use function strlen;
use function str_replace;
use function strpos;
use function strncmp;
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
 * ## 槽位：一个文件装 N 个窗口的状态（2026-10-30，多窗口）
 *
 * 一个应用开两个窗时，"上次的几何"是**两份**，但**仍在一个文件里**：
 *
 * ```php
 * $state = new WindowStateStore(Paths::userConfig($dir, 'showcase.window'), 'main');
 * $inspector = new WindowStateStore(Paths::userConfig($dir, 'showcase.window'), 'inspector');
 * ```
 *
 * - **为什么槽位是构造参数而不是 `restore($w, $h, 'main')` 的第三个参数**：
 *   `$handle` 说"这次运行里的哪个窗口"（每次启动都变），槽位说"跨运行稳定的
 *   哪个窗口"（写进文件）。放进同一个签名，两个"窗口身份"并排出现 ⇒ 迟早传反，
 *   而传反的后果是"副窗的几何还原到主窗上"—— 不报错，只是窗口乱跳。
 *   ★ 构造参数管"我是谁"，方法参数管"这次做什么"，这是本仓的一贯切法。
 * - **为什么不是"每窗一个文件"**：文件是**应用级**的持久化单元（`Paths::userConfig`
 *   收到的 `name` 是应用名）。拆成 `showcase.main.window` / `showcase.inspector.window`
 *   就把窗口名混进了应用身份，退出时还要写 N 个文件（部分失败 ⇒ 一半新一半旧）。
 * - **槽位名由应用给**：框架只有句柄（每次运行都不同）和创建序号（关一个再开一个
 *   就会漂移）——**都不能当跨运行的 key**。只有应用知道"这个窗口是什么"。
 * - **默认 `'main'`**：单窗口应用写 `new WindowStateStore($path)` 一字不改，
 *   语义就是"这个应用唯一的那个窗口"。
 *
 * ## 格式 v2（v1 文件整体吸收，不是"半兼容"）
 *
 * ```text
 * v=2
 * main.x=120
 * main.w=1000
 * inspector.x=760
 * inspector.w=420
 * ```
 *
 * v1（单窗时代）是平铺的 `x=` / `y=` / ...，没有组前缀。它的语义**100% 确定**
 * = 主窗那一组 ⇒ 读的时候整体补上 `main.` 前缀（`upgradeFromV1()`），用户升级后
 * 窗口位置不丢。★ 这与类开头纪律 3 说的"不做半兼容"不冲突：那条禁的是"读一半
 * （能读的字段用、不能读的丢）"，而这里是**整份识别**。
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
    /**
     * 文件格式版本。
     *
     * v1 → v2（2026-10-30）：键加槽位前缀。**必须抬版本**，因为"无前缀的键 = 主窗"
     * 这个解释只在 v1 成立；v2 里无前缀的行是非法的（否则两种解释并存，写出来的
     * 文件自己都读不回去）。
     */
    private const FORMAT_VERSION = 2;

    /** 一个窗口存这五个字段；顺序即写入顺序（人读时稳定） */
    private const FIELDS = ['x', 'y', 'w', 'h', 'max'];

    private string $path;

    private string $slot;

    /**
     * @param string $path 配置文件路径；`''` = 平台没给配置目录 ⇒ 整个 store 退化为空操作
     * @param string $slot 槽位名（`[A-Za-z0-9_-]{1,32}`）；单窗口应用不用传
     *
     * @api 多窗口的关键一行：`new WindowStateStore($path, 'second')`
     */
    public function __construct(string $path, string $slot = 'main')
    {
        // ★ 槽位名是**代码里的常量**，不是用户输入 ⇒ 写错当场 Fatal（`Errors::report`
        //   单参 = 致命），与 `Color::fromHex` 同口径。不做"净化后照用"：`main.x`
        //   被净化成 `mainx` 就会静默变成另一个槽位 —— 那是数据串页，比崩难查得多。
        //   禁点号是因为点号是"槽位与字段"的分隔符。
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $slot) !== 1) {
            Errors::report(
                '窗口状态：槽位名不合法（' . var_export($slot, true)
                . '）—— 只允许 A-Z a-z 0-9 _ -，1~32 个字符'
            );
        }

        $this->path = $path;
        $this->slot = $slot;
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

        $kv = self::normalize(self::parse($text));
        if ($kv === null) {
            Errors::report(
                '窗口状态：配置文件版本不符（' . $this->path . '）—— 用默认窗口几何',
                Errors::DIAGNOSTIC
            );

            return;
        }

        // ③ 没有我这组：这个槽位第一次出现（新加的窗口 / v1 文件里的副窗）
        //    ⇒ 正常路径，静默用默认几何，不刷 Diagnostic
        $group = self::groupOf($kv, $this->slot);
        if ($group === null) {
            return;
        }

        // ④ 逐字段校验：任何一项不可信 ⇒ 整份放弃（半份状态比没有更糟）
        $x = self::number($group, 'x');
        $y = self::number($group, 'y');
        $w = self::number($group, 'w');
        $h = self::number($group, 'h');
        if ($x === null || $y === null || $w === null || $h === null) {
            Errors::report('窗口状态：配置文件的坐标/尺寸不是数 —— 用默认窗口几何', Errors::DIAGNOSTIC);

            return;
        }
        if (!($w > 0.0) || !($h > 0.0)) {
            Errors::report('窗口状态：配置文件里的尺寸不是正数 —— 用默认窗口几何', Errors::DIAGNOSTIC);

            return;
        }

        $maximized = ($group['max'] ?? '0') === '1';

        $window->place($handle, new Rect($x, $y, $w, $h), $maximized);
    }

    /**
     * 退出时写回一次（§5.4）。
     *
     * ★ **调用时机在组合根的循环之外**：`EventLoop::run()` 返回之后。
     *   写文件是同步 IO，放帧内会让某一帧凭空多出几毫秒。
     *
     * ★ **只更新自己那一组**：先读回文件里已有的组（别的窗口的），换掉 `$slot`
     *   这一组再整份写。这样"每个窗口各调一次 persist"就能拼出完整文件 ——
     *   若整份覆盖，第二个窗口会**抹掉第一个窗口**记下的几何（不报错，
     *   下次启动只有一个窗还原、另一个跳回默认位置）。
     *   代价是 N 窗退出时 N 次读 + N 次写；退出路径本来就不在帧内，几次 IO 无所谓。
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

        $groups = $this->readGroups();
        $groups[$this->slot] = [
            'x' => (string) $origin->x,
            'y' => (string) $origin->y,
            'w' => (string) $size->width,
            'h' => (string) $size->height,
            'max' => $window->isMaximized($handle) ? '1' : '0',
        ];
        ksort($groups);   // 槽位名字典序：同一份状态写两次得到同一份字节（diff / 人读都友好）

        // 目录不存在 ⇒ 建它（一次性副作用）：壳只给**路径**不创建（`Platform::configDir()`
        //   的契约），创建归**写入方**也就是这里。少了这一行，第一次运行永远写不出去 ——
        //   真机实测：`is_writable()` 对不存在的目录返回 false ⇒ 静默跳过 ⇒ 无痕无文件。
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // 目录不可写 ⇒ 记一条然后放弃。**不让它把退出路径搞崩**：
        // 用户关窗必须成功，"记住窗口位置"只是附带服务。
        if (!is_writable($dir)) {
            Errors::report(
                '窗口状态：配置目录不可写（' . $dir . '）—— 本次不保存窗口几何',
                Errors::DIAGNOSTIC
            );

            return;
        }

        if (file_put_contents($this->path, self::serialize($groups)) === false) {
            Errors::report(
                '窗口状态：写不进配置文件（' . $this->path . '）—— 本次不保存窗口几何',
                Errors::DIAGNOSTIC
            );
        }
    }

    /**
     * 读出文件里**已存在的全部组**（写回时带走别的窗口）。
     *
     * 读不出来 / 版本不符 / 空路径 ⇒ 空数组。这不是"静默失败"：调用方是
     * `persist()`，它接着会写一份**只有自己这组**的文件 —— 与"整份降级"同义，
     * 而那个文件本来就已经不可信了。
     *
     * @return array<string, array<string, string>>
     */
    private function readGroups(): array
    {
        if ($this->path === '' || !is_file($this->path)) {
            return [];
        }

        $text = file_get_contents($this->path);
        if ($text === false) {
            return [];
        }

        $kv = self::normalize(self::parse($text));
        if ($kv === null) {
            return [];
        }

        $groups = [];
        foreach ($kv as $key => $value) {
            if ($key === 'v') {
                continue;
            }
            $pos = strpos($key, '.');
            if ($pos === false || $pos === 0) {
                continue;   // 无槽位前缀的行：v2 里非法，跳过
            }
            $groups[substr($key, 0, $pos)][substr($key, $pos + 1)] = $value;
        }

        return $groups;
    }

    /**
     * 把"任意版本"的 kv 归一到 v2 形状（`v` + `槽位.字段`）。
     *
     * @param array<string, string> $kv
     *
     * @return array<string, string>|null `null` = 版本不认识 ⇒ 调用方整份降级
     */
    private static function normalize(array $kv): ?array
    {
        $version = $kv['v'] ?? '';

        if ($version === (string) self::FORMAT_VERSION) {
            return $kv;
        }

        if ($version !== '1') {
            return null;
        }

        // v1 = 单窗时代：平铺的键**确定**属于唯一那个窗口 ⇒ 整体视为主窗组
        $out = ['v' => (string) self::FORMAT_VERSION];
        foreach ($kv as $key => $value) {
            if ($key !== 'v') {
                $out['main.' . $key] = $value;
            }
        }

        return $out;
    }

    /**
     * 取某一个槽位的字段（去掉前缀）。
     *
     * @param array<string, string> $kv
     *
     * @return array<string, string>|null `null` = 这个槽位在文件里没有记录
     */
    private static function groupOf(array $kv, string $slot): ?array
    {
        $prefix = $slot . '.';
        $length = strlen($prefix);

        $out = [];
        foreach ($kv as $key => $value) {
            if (strncmp($key, $prefix, $length) === 0) {
                $out[substr($key, $length)] = $value;
            }
        }

        return $out === [] ? null : $out;
    }

    /** @param array<string, array<string, string>> $groups */
    private static function serialize(array $groups): string
    {
        // ★ 行收集进数组再 `implode`：循环内 `.=` 在 tpc 的多字节路径下会堆损坏
        //   （§2.4 纪律 2，`tools/lint.php` 的 str-concat-loop 就是拦这个）。
        $lines = [
            '# Drift window state — do not edit while the app runs',
            'v=' . self::FORMAT_VERSION,
        ];

        foreach ($groups as $slot => $fields) {
            foreach (self::FIELDS as $field) {
                if (isset($fields[$field])) {
                    $lines[] = $slot . '.' . $field . '=' . $fields[$field];
                }
            }
        }

        return implode("\n", $lines) . "\n";
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
