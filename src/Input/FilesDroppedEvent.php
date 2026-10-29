<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 系统拖放：用户把一个或多个文件从资源管理器拖进窗口（Win32 `WM_DROPFILES`）。
 *
 * ★ 与 `ThemeChangedEvent` / `WindowDeactivatedEvent` 同属"系统信号 ⇒ 框架语义"
 *   的一族，区别是它**带载荷**：一批路径 + 落点坐标。它是这条新通道的第一个
 *   成员（§7 A5-②，落地记录见 §27）。
 *
 * ★ 为什么载荷存**字符串**而不是 `array $paths`：数组字段跨实例读在 AOT 上是
 *   `php::Var` / C2679 的高发区（§24 踩过 `Float += Var`），而字符串字段有
 *   `SemanticsSetValueEvent::$value` 的现成先例。`'\n'`(U+000A) 在 Win32 文件名
 *   里**非法** ⇒ 多路径用 `'\n'` 分隔是无损的，`paths()` 里 `explode` 还原。
 *   这样"原始串 → 数组"的解析还能被单测钉住（同 A5-① `filterText` 的思路）。
 *
 * ★ 落点坐标是**逻辑单位**（客户区物理坐标 ÷ dpr，由 `Win32Platform::pump()`
 *   换算），与 `PointerEvent` / `ScrollEvent` 同口径 —— 命中测试拿它直接用。
 *
 * @api
 */
final readonly class FilesDroppedEvent implements InputEvent
{
    /**
     * @param string $pathsText `'\n'` 分隔的多路径原始串（空串 = 零个文件）
     * @param float  $x         落点 x（逻辑单位）
     * @param float  $y         落点 y（逻辑单位）
     */
    public function __construct(
        public string $pathsText,
        public float $x,
        public float $y,
    ) {
    }

    /**
     * @return list<string> 路径列表（空串 ⇒ 空数组；壳只解真实文件路径，
     *                      不会产出空元素）
     */
    public function paths(): array
    {
        // ★ 落局部再 explode：`$this->pathsText` 跨实例读到的是标量，先
        //   `(string)` 落局部再喂内建函数，避免 AOT 侧把 Var 直接传参（§24 纪律③）。
        $text = (string) $this->pathsText;
        if ($text === '') {
            return [];
        }

        return explode("\n", $text);
    }

    /** 拖进来的文件个数（`paths()` 的轻量版，不构造数组）。 */
    public function fileCount(): int
    {
        $text = (string) $this->pathsText;
        if ($text === '') {
            return 0;
        }

        return substr_count($text, "\n") + 1;
    }
}
