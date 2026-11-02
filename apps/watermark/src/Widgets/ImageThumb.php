<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\SelectedImage;
use Drift\Layout\ImageFit;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Image;
use Drift\Widget\SizedBox;
use Drift\Widget\Stack;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Widget;

/**
 * 一张 110×110 的缩略图 + 右上角"×"。
 *
 * ★ **必须自己套 `SizedBox`**：`Stack` 里的定位子（`positioned()`）**不撑大** `Stack`
 *   ⇒ 只有定位子的 `Stack` 是 0×0，子被夹到 0 就**静默消失**（本仓踩过）。
 *   这里图片本身是非定位子，所以尺寸其实由它撑开 —— 但"图片解码失败时是 1×1 占位"
 *   （见 `Image` 的类注释）⇒ 还是得套，否则一张坏图会让整格塌成 1px。
 * ★ 删除回调是**注入的闭包**而不是 `command()`：一个缩略图一个下标，命令参数得
 *   造一个 `CommandArg`，而这里没有"跨窗口 / 跨页面"的需求（同 `UsersRoot` 的
 *   `menuOpener` 那条路：Widget 不依赖 App）。
 */
final class ImageThumb extends StatelessWidget
{
    /** 缩略图边长（原文 `.preview-item { width:110px; height:110px }`） */
    private const SIDE = 110.0;

    /**
     * @param \Closure(int): void $onRemove 参数是**下标**（`selected()` 里的位置）
     */
    public function __construct(
        private readonly SelectedImage $image,
        private readonly int $index,
        private readonly \Closure $onRemove,
    ) {
        parent::__construct();
    }

    /**
     * @param \Closure(int): void $onRemove
     */
    public static function make(SelectedImage $image, int $index, \Closure $onRemove): self
    {
        return new self($image, $index, $onRemove);
    }

    public function build(BuildContext $ctx): Widget
    {
        $remove = $this->onRemove;
        $index = $this->index;

        return SizedBox::make(
            Stack::make()->children([
                // ★ `fit(Cover)`：原文是 `object-fit: cover` —— 缩略图格是正方形，
                //   图片多半不是，`Contain` 会留白边（看起来像"没填满"）。
                Image::make()
                    ->asset($this->image->path)
                    ->width(self::SIDE)
                    ->height(self::SIDE)
                    ->fit(ImageFit::Cover),

                Button::make('×')
                    ->variant(ButtonVariant::Danger)
                    ->compact()
                    ->onPressed(static function () use ($remove, $index): void {
                        $remove($index);
                    })
                    ->positioned(right: 4.0, top: 4.0),
            ])
        )->size(self::SIDE, self::SIDE);
    }
}
