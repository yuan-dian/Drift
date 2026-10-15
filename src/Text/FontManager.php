<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * face 注册（§8.1 第一层）：内存字节 / 文件 / 系统族枚举。
 *
 * ★ §8.3 血账 1：GUI 仓早期 `--no-console` 启动报 `0xC0000005`，根源是
 *   `ensureFont()` 里字体句柄为空 —— 而且**无控制台程序的启动崩溃常掩盖真正的
 *   空指针/初始化错误**。所以 Drift 的对策是：初始化必须在任何绘制前完成并
 *   断言非空（G2），失败走 `Errors::report` 而不是崩。
 *
 * @api platform
 */
interface FontManager
{
    public function registerFace(FontFace $face): void;

    /** 从字体文件注册（faceIndex 支持 TTC 合集） */
    public function registerFile(string $path, int $faceIndex = 0): void;

    /** 从内存字节注册 —— 打包进 exe 的字体走这条（§8.3 的交付单文件要求） */
    public function registerBytes(string $bytes, int $faceIndex = 0): void;

    /** @return list<FontFace> */
    public function faces(): array;

    public function collection(): FontCollection;

    /** 任何绘制前必须为真；为假时渲染层必须响亮失败而不是画出空白 */
    public function isReady(): bool;
}
