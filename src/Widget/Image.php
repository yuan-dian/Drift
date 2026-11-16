<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Layout\ImageFit;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderImage;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\ImageLoader;
use Drift\Rendering\ImageSource;

/**
 * 图片 —— `Image::make()->asset('demo/logo.png')`。
 *
 * ```php
 * Image::make()->asset('demo/logo.png')->width(120.0)->fit(ImageFit::Cover)  // 本地
 * Image::make()->asset('https://cdn/x.png')->width(120.0)                    // 网络
 * ```
 *
 * ★★ **`asset()` 是唯一字符串入口**：本地路径与 http(s) URL 同形，业务不用判断来源。
 *   网络图的下载 / 缓存 / 三态由 {@see ImageState} 承担；本类只持配置与产盒。
 *   `image($handle)` 仍收内存句柄（自绘 / 测试），不参与来源判定。
 *
 * ★ **为什么改成有态**：网络回包必须让这一张图自己重绘，而 Widget 每次重建都换新
 *   （§13.2）。加载状态只能住在跨 build 存活的 `State` 里；`createRenderObjectFromState()`
 *   走无子叶子路径（`Spinner` 已验证），外部 API 全不变。
 *
 * ★ **资源缺失 = 1×1 透明占位**（不崩、不空白报错）；可能缺图时请显式给 `width`/`height`，
 *   否则布局尺寸会缩到 1×1。
 *
 * ★ **不给语义角色**（§9.2）：要可读的名字放在外层交互控件的 `semName`。
 *
 * @api
 */
final class Image extends StatefulWidget
{
    /**
     * 组合根装配的获取端口（`ImageSource` 住在 Rendering 层，Widget 可依赖）。
     *
     * ★ 静态但不在控件里 new：App 侧 install 时推入，测试 reset 时清空。
     */
    private static ?ImageSource $source = null;

    /** 解码宽度相对逻辑宽度的倍数（覆盖到 200% DPI 缩放） */
    private const float SUPERSAMPLE = 2.0;

    /** 网络图没给 width 时的解码上限：本仓有原尺寸解码撑爆 128M 的前科 */
    private const int NETWORK_MAX_WIDTH = 2048;

    private string $path = '';

    private ImageHandle $handle;

    private float $width = 0.0;

    private float $height = 0.0;

    private ImageFit $fit = ImageFit::Contain;

    public function __construct()
    {
        parent::__construct();

        // 非空初值：AOT 下 `?Obj` 退化为 `php::Var`，属性访问全走动态路径。
        $this->handle = ImageLoader::placeholder();
    }

    public static function make(): self
    {
        return new self();
    }

    /** 由组合根调用（`ImageAssets::install()`），业务不接触 */
    public static function setSource(?ImageSource $source): void
    {
        self::$source = $source;
    }

    /** @internal 给 ImageState 取端口 */
    public static function imageSource(): ?ImageSource
    {
        return self::$source;
    }

    /**
     * 从资源加载：相对 / 绝对路径走本地，http(s) 走异步下载 + 缓存。
     * 其它 scheme 明确拒绝（诊断 + 占位），不静默当本地路径。
     */
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

    public function createState(): State
    {
        return new ImageState();
    }

    public function createRenderObjectFromState(BuildContext $context, State $state): ?RenderBox
    {
        if (!$state instanceof ImageState) {
            Errors::report('ImageState 只能配 Image（框架内部不变量被破坏）');
        }

        $handle = $state instanceof ImageState ? $state->handle() : ImageLoader::placeholder();

        return new RenderImage($handle, $this->fit, $this->width, $this->height);
    }

    /**
     * 无 Element 场景（单测 / 裸用）的同步产盒：只解析本地源，网络源给占位。
     *
     * @internal 主路径是 {@see self::createRenderObjectFromState()}；保留本方法让
     *   既有"直接拿控件产盒"的测试与工具不必搭一棵 Element 树。
     */
    public function createRenderObject(BuildContext $context): RenderBox
    {
        $image = $this->handle;
        if ($this->path !== '' && ImageLoader::classify($this->path) === 'local') {
            $loaded = ImageLoader::load($this->path, $this->decodeWidthFor('local'));
            if ($loaded !== null) {
                $image = $loaded;
            }
        }

        return new RenderImage($image, $this->fit, $this->width, $this->height);
    }

    /** @internal 给 ImageState 读 */
    public function source(): string
    {
        return $this->path;
    }

    /** @internal 给 ImageState 读 */
    public function memoryHandle(): ImageHandle
    {
        return $this->handle;
    }

    /** @internal 给 ImageState 读；空宽按来源决定用原尺寸还是安全上限 */
    public function decodeWidthFor(string $kind): int
    {
        if ($this->width > 0.0) {
            return (int) \ceil($this->width * self::SUPERSAMPLE);
        }

        return $kind === 'network' ? self::NETWORK_MAX_WIDTH : 0;
    }

    /** @internal 给 ImageState 判"没给 width"（诊断只说一次） */
    public function hasWidth(): bool
    {
        return $this->width > 0.0;
    }
}
