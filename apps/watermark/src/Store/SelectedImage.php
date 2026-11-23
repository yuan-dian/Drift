<?php
declare(strict_types=1);

namespace App\Watermark\Store;

/**
 * 用户选中的一个本地图片（**不可变**）。
 *
 * ★ 这里**零 IO**：既不 `getimagesize()` / 解码，也不 `filesize()`。用户可能一次
 *   拖 20 张进来，选择这一步的 IO 会直接卡住界面，而这两样信息今天一个都不需要
 *   —— 预览图由 `Image` 控件懒解码，上传按真实字节读文件（长度由 `Http` 自己
 *   从文件句柄取，不靠这里声明）。
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
        /** 小写扩展名（不含点；空串 = 文件名没有扩展名） */
        public readonly string $extension,
    ) {
    }

    /** 从一个路径造一个；**路径为空 ⇒ `null`** */
    public static function fromPath(string $path): ?self
    {
        $path = \trim($path);
        if ($path === '') {
            return null;
        }

        $name = \basename($path);
        $extension = '';
        $dot = \strrpos($name, '.');
        if ($dot !== false && $dot < \strlen($name) - 1) {
            $extension = \strtolower(\substr($name, $dot + 1));
        }

        return new self($path, $name, $extension);
    }
}
