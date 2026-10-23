<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\ImageFit;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderImage;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\ImageLoader;

/**
 * 图片 —— `Image::make()->asset('demo/logo.png')`。
 *
 * ```php
 * Image::make()->asset('demo/logo.png')->width(120.0)->fit(ImageFit::Cover)
 * ```
 *
 * ★ **源有两种**：
 *   - `asset($path)` —— 走 `ImageLoader`（候选目录 + 解码缓存）。相对路径按
 *     `<cwd>/assets`、`<cwd>/images`、仓库根 `assets/` 依次找；绝对路径原样用。
 *   - `image($handle)` —— 已经在内存里的 `ImageHandle`（自绘 / 测试 / 生成图）。
 *
 * ★★ **解码在 `createRenderObject()` 里做，但不会重复解**：盒每次重建都换新
 *   （§13.2），所以这里每帧都会调一次 `ImageLoader::load()` —— 它按**解析后的
 *   绝对路径**缓存，命中就是一次数组查找。没有这层缓存，每帧 inflate 一张图
 *   会把帧率直接打穿。
 *
 * ★ **资源缺失 = 1×1 透明占位**（不崩、不空白报错）：`ImageLoader` 已经
 *   `DIAGNOSTIC` 留痕，控件这边的降级是"几乎看不见的占位"。所以**可能缺图时
 *   请显式给 `width`/`height`** —— 否则布局尺寸会缩到 1×1。
 *
 * ★ **不给语义角色**（§9.2：`Text`/`Icon`/`Image` 这类叶子一律不给，
 *   否则"按角色找控件"会被淹没）。要可读的名字，放在外层交互控件的 `semName`。
 *
 * @api
 */
final class Image extends RenderBoxWidget
{
    /** 资源路径（空 = 用内存句柄） */
    private string $path = '';

    /** 内存句柄（`$path` 非空时被 `asset()` 的结果顶替） */
    private ImageHandle $handle;

    /** `<= 0` = 用原图宽（只给高时按比例推） */
    private float $width = 0.0;

    /** `<= 0` = 用原图高（只给宽时按比例推） */
    private float $height = 0.0;

    private ImageFit $fit = ImageFit::Contain;

    public function __construct()
    {
        parent::__construct();

        // ★ 非空初值：这样字段永远是真 `ImageHandle`，不必引 `?ImageHandle`
        //   （AOT 下 `?Obj` 退化成 `php::Var`，属性访问全走动态路径）。
        $this->handle = ImageLoader::placeholder();
    }

    public static function make(): self
    {
        return new self();
    }

    /** 从资源文件加载（相对路径按 `ImageLoader::candidates()` 找） */
    public function asset(string $path): static
    {
        $this->mutate();
        $this->path = $path;

        return $this;
    }

    /** 直接用内存里的图（给了它就忽略 `asset()` 的路径） */
    public function image(ImageHandle $image): static
    {
        $this->mutate();
        $this->path = '';
        $this->handle = $image;

        return $this;
    }

    /** 固定宽；只给宽 ⇒ 高按原图比例推 */
    public function width(float $width): static
    {
        $this->mutate();
        $this->width = $width;

        return $this;
    }

    /** 固定高；只给高 ⇒ 宽按原图比例推 */
    public function height(float $height): static
    {
        $this->mutate();
        $this->height = $height;

        return $this;
    }

    /** 适配策略（默认 `Contain`：等比装进盒、居中留白） */
    public function fit(ImageFit $fit): static
    {
        $this->mutate();
        $this->fit = $fit;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        $image = $this->handle;
        if ($this->path !== '') {
            $loaded = ImageLoader::load($this->path);
            if ($loaded !== null) {
                $image = $loaded;
            }
        }

        return new RenderImage($image, $this->fit, $this->width, $this->height);
    }
}
