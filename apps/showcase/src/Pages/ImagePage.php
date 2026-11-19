<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Layout\ImageFit;
use Drift\Rendering\ImageLoader;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Image;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Spacer;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 页 12「图片 Image」—— W8-1 资源管线的控件侧（2026-10-23）。
 *
 * 四段各演示一种 `ImageFit`，全部用 `assets/demo/` 下的两张真图：
 *
 *   ① Contain（默认）：等比装进盒、居中留白 —— 不丢内容；
 *   ② Cover：等比铺满盒、居中**裁源** —— 不丢盒、可能丢图；
 *   ③ Fill：拉伸到盒的宽高（不保比例，只在"图就是背景"时用）；
 *   ④ None：1:1 不缩放，超出盒的部分裁掉。
 *
 * ★ 这一页同时是**能力边界的如实记录**：没有网络图、没有 SVG、没有九宫格
 *   拉伸、没有圆角（要圆角请用 `clip` 修饰）、没有 EXIF 方向。见页末。
 *
 * ★ 资源路径按 `<cwd>/assets` 找 —— **跑这个示例时仍要以仓库根为 cwd 启动**。
 *   找不到就是 1×1 透明占位（字体已不依赖 cwd，见 `cpp-src/skia/drift_skia.cc`）。
 */
final class ImagePage extends StatelessWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        // 内存句柄那条演示：先解码一次，再交给 Image::image()（同一份缓存）
        $quad = ImageLoader::load('demo/quad.png');

        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('图片 Image')->title(),
            Text::make('资源管线：PNG 解码（纯 PHP）+ 两个后端都能 drawImage。源住 Widget，盒在重建时换新。')
                ->caption(),

            $this->sectionBar('Contain · 等比装进盒（默认）'),
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                Image::make()->asset('demo/quad.png')->width(96.0)->height(96.0)->fit(ImageFit::Contain),
                Column::make()->spacing(6.0)->children([
                    Text::make('64×64 方图放进 96×96 方盒：等比放大 1.5×，四边都不留白。')
                        ->caption(),
                    Text::make('放进 160×96 的扁盒时会在左右留白 —— 图不变形。')->caption(),
                ]),
            ]))->paddingAll(12.0),

            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                Image::make()->asset('demo/quad.png')->width(160.0)->height(96.0)->fit(ImageFit::Contain),
                Text::make('同一张图，盒变扁 ⇒ 左右留白（Contain 不裁图）。')->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('Cover · 等比铺满盒（裁源，不裁目标）'),
            Card::make(Column::make()->spacing(8.0)->children([
                Image::make()->asset('demo/strip.png')->width(240.0)->height(120.0)->fit(ImageFit::Cover),
                Text::make('96×24 的四色横条（红/绿/蓝/黄，每段 24px）铺进 240×120 的盒：'
                    . '盒的比例 2:1 比图的 4:1 更方 ⇒ 保高、左右各裁掉一整段，只剩中间的绿与蓝。'
                    . '裁的是**源矩形**，所以绝不会溢出盒。')
                    ->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('Fill · 拉伸到盒（不保比例）'),
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                Image::make()->asset('demo/quad.png')->width(120.0)->height(40.0)->fit(ImageFit::Fill),
                Text::make('四象限被压扁：中间的白色方块变成扁矩形。只在"图就是背景"时用。')
                    ->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('None · 1:1 不缩放（超出即裁）'),
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                Image::make()->asset('demo/quad.png')->width(32.0)->height(32.0)->fit(ImageFit::None),
                Text::make('64×64 的图放进 32×32 的盒：取正中的 32×32（正好是中心白点那一片）。')
                    ->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('内存句柄 · Image::image()'),
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                Image::make()->image($quad)->width(48.0)->height(48.0)->fit(ImageFit::Contain),
                Text::make('已经在内存里的 ImageHandle（自绘 / 生成图 / 图集切片）走 image()，不碰文件系统。')
                    ->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('能力边界（如实记录）'),
            Text::make('① 只认 PNG：JPEG / WebP / GIF 要各自加解码器（GIF 还要帧）。')
                ->caption(),
            Text::make('② 没有网络图 / SVG / 九宫格拉伸 / EXIF 方向。')
                ->caption(),
            Text::make('③ 没有圆角与半透明：要圆角用 clip 修饰，要半透明用 Opacity 包一层。')
                ->caption(),
            Text::make('④ 放大是最近邻（Raster）与双线性（Skia）—— 有意的双端差异，1:1 时逐字节相同。')
                ->caption(),
            Text::make('⑤ 示例资源按 <cwd>/assets 找：从仓库根启动，否则退回 1×1 占位。')
                ->caption(),
        ]);
    }

    private function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
