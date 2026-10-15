<?php
declare(strict_types=1);

namespace Drift\Layout;

/**
 * 交叉轴对齐（§13.1：`RenderFlex` 的垂直/水平对齐语义）。
 *
 * ★ 没有 `Baseline`：它要拿文本度量才知道基线在哪，而 `Layout` 不许依赖 `Text`
 *   （§4.3）⇒ 留到控件层与文本一起做（M3）。
 *
 * @api
 */
enum FlexAlign
{
    /** 交叉轴起点 */
    case Start;
    case Center;
    case End;
    /** 拉满交叉轴 —— 交叉轴无界时退化为 `Start`（拉不满空气） */
    case Stretch;
}
