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
 * 窗口状态持久化 —— 启动读回一次、退出写回一次。
 * 存三样：客户区左上角屏幕坐标（逻辑像素）、客户区宽高、是否最大化。
 * 关窗时的位置和大小，下次启动原样还原。
 * 只在启动 / 退出各同步一次 IO（不在帧内）；恢复失败静默降级到默认值，
 * 不报错；存储后端可换但不进 `Platform`（本实现是可读的 kv 文本文件）。
 * 一个文件装 N 个窗口的状态，由构造时给的槽位名区分。
 * @api
 */
final class WindowStateStore
{
    /**
     * 文件格式版本。
     * v1 → v2：键加槽位前缀；必须抬版本，因为"无前缀的键 = 主窗"只在 v1 成立，
     * v2 里无前缀的行非法（两种解释并存会写出读不回的文件）。
     */
    private const FORMAT_VERSION = 2;

    /** 一个窗口存这五个字段；顺序即写入顺序（人读时稳定） */
    private const FIELDS = ['x', 'y', 'w', 'h', 'max'];

    private string $path;

    private string $slot;

    /**
     * @param string $path 配置文件路径；`''` = 平台没给配置目录 ⇒ 整个 store 退化为空操作
     * @param string $slot 槽位名（`[A-Za-z0-9_-]{1,32}`）；单窗口应用不用传
     * @api 多窗口：`new WindowStateStore($path, 'second')`
     */
    public function __construct(string $path, string $slot = 'main')
    {
        // ★ 槽位名是代码里的常量，不是用户输入 ⇒ 写错当场 Fatal；不做"净化后照用"：
        //   `main.x` 被净化成 `mainx` 会静默变成另一个槽位（数据串页，比崩难查得多）。
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
     * 启动时读回并还原窗口几何。
     * 必须在 `surface->resize()` 之前 —— 首帧就该是还原后的尺寸，否则用户会先看到
     * 一帧默认尺寸再被拽过去。
     * @param int $handle `createWindow()` 给的不透明句柄
     * @api
     */
    public function restore(WindowHost $window, int $handle): void
    {
        // 路径为空：平台没给配置目录，跳过就是
        if ($this->path === '') {
            return;
        }

        // 文件不存在：第一次运行，正常
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

        // 没有我这组：该槽位第一次出现（新加的窗口 / v1 文件里的副窗），静默用默认几何
        $group = self::groupOf($kv, $this->slot);
        if ($group === null) {
            return;
        }

        // 逐字段校验：任何一项不可信 ⇒ 整份放弃（半份状态比没有更糟）
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
     * 退出时写回一次。
     * 调用时机在组合根的循环之外（`EventLoop::run()` 返回之后）；写文件是同步 IO，
     * 放帧内会让某一帧凭空多出几毫秒。
     * ★ 只更新自己那一组：先读回文件里已有的组（别的窗口的），换掉 `$slot` 这一组
     *   再整份写。每个窗口各调一次 persist 就能拼出完整文件 —— 若整份覆盖，
     *   第二个窗口会抹掉第一个窗口记下的几何（下次启动只有一个窗还原）。
     * @param int $handle `createWindow()` 给的不透明句柄
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

        // 目录不存在 ⇒ 建它：壳只给路径不创建，创建归写入方。
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // 目录不可写 ⇒ 记一条然后放弃，不让它把退出路径搞崩（用户关窗必须成功）。
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
     * 读出文件里已存在的全部组（写回时带走别的窗口）。
     * 读不出来 / 版本不符 / 空路径 ⇒ 空数组：调用方 `persist()` 会写一份只有自己这组的
     * 文件，与"整份降级"同义。
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
     * @param array<string, string> $kv
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

        // v1 = 单窗时代：平铺的键确定属于唯一窗口 ⇒ 整体视为主窗组
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
     * @param array<string, string> $kv
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
        // ★ 行收集进数组再 `implode`：循环内 `.=` 在 tpc 的多字节路径下会堆损坏。
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

    /** 取一个数值字段；不是数 ⇒ null（调用方据此整份降级） */
    private static function number(array $kv, string $key): ?float
    {
        $raw = $kv[$key] ?? null;
        if ($raw === null || !is_numeric($raw)) {
            return null;
        }

        $value = floatval($raw);

        // 显式排 INF/NAN：`x=1e999` 经 floatval 会给 inf，传给平台会算出垃圾矩形。
        return is_finite($value) ? $value : null;
    }
}
