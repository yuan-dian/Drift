<?php
declare(strict_types=1);

namespace Drift\Layout;

/**
 * 图片在**盒内**的适配策略（§W8-1 的 `Image` 控件）。
 *
 * ★ 与 CSS `object-fit` 同名同义（`none` / `contain` / `cover` / `fill` 四个），
 *   少一个 `scale-down`（= `none` 与 `contain` 里更小的那个，要了再议）。
 *
 * ★ **四种都不许从盒里溢出**：`Cover` / `None` 用**裁源矩形**而不是裁目标矩形
 *   来做到这一点（`drawImage` 本来就收 `?Rect $src`）⇒ 叶子不需要 `clip`
 *   修饰、也不付"每帧 pushClip"的代价。这是有意的：一个只画一张图的盒子
 *   去开裁剪栈，纯属浪费。
 *
 * @api
 */
enum ImageFit
{
    /** 等比缩放到**完全装进**盒内，居中留白（默认：不丢内容） */
    case Contain;

    /** 等比缩放到**铺满**盒，居中裁掉超出部分（不丢盒、可能丢图） */
    case Cover;

    /** 拉伸到盒的宽高（**不保比例**：会变形，只在"图就是背景"时用） */
    case Fill;

    /** 不缩放（1:1 设备像素），居中，超出盒的部分裁掉 */
    case None;
}
