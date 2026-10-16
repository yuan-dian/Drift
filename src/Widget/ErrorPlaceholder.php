<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 错误边界的**占位**（O-12 A′：捕获后该子树显示的东西）。
 *
 * ```php
 * ErrorPlaceholder::make('Unhandled Match Error')
 * ```
 *
 * ★ 用现成控件组合（`Center` + `Text::error()`）—— 不新造渲染盒：占位自己崩的
 *   概率必须趋近零，而它越简单越接近零（O-12：占位是"最可见的那条痕迹"，
 *   不是功能）。
 *
 * ★ **消息原文上屏**：不吞、不改写 —— 用户与开发者看到同一句话（G2）。
 *
 * @api
 */
final class ErrorPlaceholder extends StatelessWidget
{
    public function __construct(private readonly string $message)
    {
        parent::__construct();
    }

    public static function make(string $message): self
    {
        return new self($message);
    }

    public function message(): string
    {
        return $this->message;
    }

    public function build(BuildContext $context): Widget
    {
        return Center::make(
            Text::make('此内容出错：' . $this->message)->error(),
        );
    }
}
