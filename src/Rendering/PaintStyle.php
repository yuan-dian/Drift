<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 画笔风格（封闭域 ⇒ 使用它的 `match` 禁 `default`，§12.1 第 2 条）。
 *
 * @api
 */
enum PaintStyle: string
{
    case Fill = 'fill';
    case Stroke = 'stroke';
}
