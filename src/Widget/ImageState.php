<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\ImageLoader;
use Drift\State\AsyncCommand;

/**
 * `Image` 的加载态 —— 网络图自驱下载、两层缓存命中同步返回、失败走占位。
 *
 * ★ 状态住这里而不是 Widget：Widget 每次重建都换新（§13.2），
 *   在途请求与已解码的 handle 必须跨 build 存活。
 * ★ 命令只建一次且必须在 `initState()`：内部 `watch()` 只在初始化期放行。
 *   参数通过 `run($arg)` 传，换 src 时由 `AsyncCommand` 取消旧的那条。
 */
final class ImageState extends State
{
    private ImageHandle $handle;

    /** `src|kind|width` —— 换了才重新解析，避免重建时重发同一张图 */
    private string $loadedKey = '';

    private ?AsyncCommand $command = null;

    public function __construct()
    {
        $this->handle = ImageLoader::placeholder();
    }

    public function initState(): void
    {
        $this->command = $this->asyncCommand(function (mixed $arg) {
            if (!\is_array($arg)) {
                return;
            }
            $src = $arg['src'] ?? null;
            $width = $arg['width'] ?? 0;
            if (!\is_string($src) || !\is_int($width)) {
                return;
            }

            $source = Image::imageSource();

            return $source?->fetch($src, $width, function (ImageHandle $handle): void {
                $this->apply($handle);
            });
        });

        $widget = $this->widget();
        if ($widget instanceof Image) {
            $this->resolve($widget);
        }
    }

    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);

        if ($widget instanceof Image) {
            $this->resolve($widget);
        }
    }

    private function resolve(Image $widget): void
    {
        $src = $widget->source();
        $kind = $src === '' ? 'local' : ImageLoader::classify($src);
        $width = $widget->decodeWidthFor($kind);
        $key = $src . '|' . $kind . '|' . (string) $width;
        if ($key === $this->loadedKey) {
            return; // 同一张图、同一目标宽度 ⇒ 重建不重发
        }
        $this->loadedKey = $key;

        if ($src === '') {
            $this->handle = $widget->memoryHandle();

            return;
        }

        if ($kind === 'reject') {
            Errors::report(
                'Image：不认识的资源协议 ' . $src . '（只收本地路径与 http(s) URL）—— 已降级为占位',
                Errors::DIAGNOSTIC,
            );
            $this->handle = ImageLoader::placeholder();

            return;
        }

        if ($kind === 'local') {
            $this->handle = ImageLoader::load($src, $width) ?? ImageLoader::placeholder();

            return;
        }

        $source = Image::imageSource();
        if ($source === null) {
            Errors::report(
                'Image：网络图 ' . $src . ' 没有装配图片服务 —— 已降级为占位',
                Errors::DIAGNOSTIC,
            );
            $this->handle = ImageLoader::placeholder();

            return;
        }

        if (!$widget->hasWidth()) {
            Errors::report(
                'Image：网络图 ' . $src . ' 没给 width，按 2048 上限解码 —— '
                . '给一个显示宽度可显著省内存',
                Errors::DIAGNOSTIC,
            );
        }

        // 磁盘缓存命中 ⇒ 同步解码，不提交 Job、不闪一帧 loading。
        $cached = $source->cached($src, $width);
        if ($cached !== null) {
            $this->handle = $cached;

            return;
        }

        $this->handle = ImageLoader::placeholder();
        $command = $this->command;
        if ($command !== null) {
            $command->run(['src' => $src, 'width' => $width]);
        }
    }

    /** 当前 handle（永远是真 `ImageHandle`，RenderImage 字段不必可空） */
    public function handle(): ImageHandle
    {
        return $this->handle;
    }

    /** 下载回来后换 handle —— 只标脏，重绘统一发生在帧末 */
    public function apply(ImageHandle $handle): void
    {
        $this->setState(function () use ($handle): void {
            $this->handle = $handle;
        });
    }
}
