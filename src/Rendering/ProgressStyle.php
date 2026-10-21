<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 进度条的**解析后样式**（`Progress` 用）。
 *
 * ★ 三个值：填充色（`Accent`）、轨道色（`Pane` 上的浅底，取 `Border` 与 `Pane`
 *   之间更稳），条高。圆角由渲染盒按 `radius` 参数自己算（`drawRect` 的
 *   `radius` 参数就是干这个的，不新增原语）。
 *
 * ★ 没有 `disabled` / `thickness` 之外的"变体" —— 进度条只有"走到哪"一种状态，
 *   预先做 indeterminate 的滑轨几何是 A15（没有消费者不发明）。
 *
 * @api
 */
final readonly class ProgressStyle
{
    public function __construct(
        /** 已完成部分的填充色（主题 `Accent`） */
        public Color $fill,
        /** 轨道底色（未完成部分） */
        public Color $track,
        /** 条高（逻辑像素）；宽度 = 盒宽 */
        public float $height,
    ) {
    }
}
