<?php
declare(strict_types=1);

namespace App\Watermark\Store;

/**
 * 用户选中的一个本地图片（**不可变**）。
 *
 * ★ 为什么不在选中的那一刻就 `getimagesize()` / 解码：用户可能一次拖 20 张进来，
 *   在**选择**这一步做 IO 会让界面卡住，而这些信息今天一个都不需要（预览图由
 *   `Image` 控件懒解码，上传只读字节）。`size` 用 `filesize()`（一次 `stat`）。
 * ★ `extension` 从**文件名**取（不是从内容判）：OSS 的对象键要带扩展名，而
 *   `Http` 的 MIME 映射也是按扩展名查表 —— 两处口径统一在这里。
 */
final class SelectedImage
{
    public function __construct(
        /** 本地绝对路径（`Image::asset()` 直接吃它） */
        public readonly string $path,
        /** 文件名（`basename`，给用户看的） */
        public readonly string $name,
        /** 字节数（`-1` = stat 失败，仍允许上传 —— 让服务端去拒） */
        public readonly int $size,
        /** 小写扩展名（不含点；空串 = 文件名没有扩展名） */
        public readonly string $extension,
    ) {
    }

    /**
     * 从一个路径造一个；**路径为空 ⇒ `null`**。
     *
     * ★ `clearstatcache(true, $path)` 是必须的：PHP 按路径缓存 `stat`，同一个文件
     *   被覆盖写之后（用户重新导出一次），这里会拿到**旧的大小** —— 而上传用的是
     *   真实字节数 ⇒ "声明长度 ≠ 实际长度" ⇒ curl 一直等，服务端等一个永远不来的
     *   分隔符。本仓在 `Http::buildMultipart()` 里也踩过同一个坑。
     */
    public static function fromPath(string $path): ?self
    {
        $path = \trim($path);
        if ($path === '') {
            return null;
        }

        \clearstatcache(true, $path);
        $size = @\filesize($path);

        $name = \basename($path);
        $extension = '';
        $dot = \strrpos($name, '.');
        if ($dot !== false && $dot < \strlen($name) - 1) {
            $extension = \strtolower(\substr($name, $dot + 1));
        }

        return new self($path, $name, $size === false ? -1 : (int) $size, $extension);
    }

    /**
     * 能不能当图片上传（**按扩展名**，不读内容）。
     *
     * ★ 与 `Http` 的 MIME 表同一份口径：表里没有的扩展名，`Http` 会退回
     *   `application/octet-stream`，OSS 照样收 —— 所以这里**不拦**，
     *   只用来在界面上提示"这个可能不是图片"。真正的判定交给服务端。
     */
    public function looksLikeImage(): bool
    {
        return \in_array($this->extension, ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'gif', 'heic', 'tif', 'tiff'], true);
    }

    /** 人类可读的大小（`1.2 MB`）—— 只用于展示 */
    public function sizeLabel(): string
    {
        if ($this->size < 0) {
            return '大小未知';
        }
        if ($this->size < 1024) {
            return $this->size . ' B';
        }
        if ($this->size < 1024 * 1024) {
            return \sprintf('%.0f KB', $this->size / 1024.0);
        }

        return \sprintf('%.1f MB', $this->size / (1024.0 * 1024.0));
    }
}
