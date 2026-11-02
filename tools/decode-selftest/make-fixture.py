"""生成 fixtures/rgb-bands.png：一条**手写期望值**的色带图。

为什么需要它：`decode-selftest` 原来的 6 条判据都是"同一张图的两个尺寸互相比"，
只比得出"一致 / 不一致"，比不出"颜色是谁"。所以 R/B 互换能让 6 条全绿 ——
真机就是这样把蓝色当成暖白送到屏幕上的。

这张图的期望值是**写死的**（红色带必须是红），与任何解码器无关：

    带 0  红色   #FF0000
    带 1  绿色   #00FF00
    带 2  蓝色   #0000FF
    带 3  中灰   #808080     ← 顺带钉住 16 位 -> 8 位 的取值（0x8080 -> 128）

几何：64x64、16 位 RGB、每条带 16 行 ⇒ 缩到 32 宽后每条带仍有 8 行，
在带中央取样时**不会**被插值混到邻带的颜色。

    python tools/decode-selftest/make-fixture.py
"""
import os
import struct
import zlib

W = 64
H = 64
BAND = 16

BANDS = [
    (0xFFFF, 0x0000, 0x0000),   # 红
    (0x0000, 0xFFFF, 0x0000),   # 绿
    (0x0000, 0x0000, 0xFFFF),   # 蓝
    (0x8080, 0x8080, 0x8080),   # 中灰
]


def chunk(typ, data):
    return (struct.pack('>I', len(data)) + typ + data
            + struct.pack('>I', zlib.crc32(typ + data) & 0xFFFFFFFF))


def main():
    rows = []
    for y in range(H):
        r, g, b = BANDS[min(y // BAND, len(BANDS) - 1)]
        row = struct.pack('>HHH', r, g, b) * W
        rows.append(b'\x00' + row)          # 滤波类型 0（None）

    ihdr = struct.pack('>IIBBBBB', W, H, 16, 2, 0, 0, 0)
    png = (b'\x89PNG\r\n\x1a\n'
           + chunk(b'IHDR', ihdr)
           + chunk(b'IDAT', zlib.compress(b''.join(rows), 9))
           + chunk(b'IEND', b''))

    out = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fixtures', 'rgb-bands.png')
    os.makedirs(os.path.dirname(out), exist_ok=True)
    with open(out, 'wb') as fh:
        fh.write(png)
    print('%s  %d bytes  %dx%d 16-bit RGB' % (out, len(png), W, H))


if __name__ == '__main__':
    main()
