"""把 PNG 解成 RGB 并抽样打印 —— 用来当 WIC 解码的对照物。

只做本次排障需要的最小实现：16/8 位、非隔行、colortype 0/2/4/6。
不做缩放、不做 gamma、不做色彩管理 —— 也就是说它给出的是**文件里字面的字节**，
这正是"颜色对不对"该比的基准。
"""
import struct
import sys
import zlib

CH = {0: 1, 2: 3, 4: 2, 6: 4}


def load(path):
    d = open(path, 'rb').read()
    pos = 8
    idat = b''
    ihdr = None
    while pos < len(d):
        ln = struct.unpack('>I', d[pos:pos + 4])[0]
        typ = d[pos + 4:pos + 8]
        data = d[pos + 8:pos + 8 + ln]
        if typ == b'IHDR':
            ihdr = struct.unpack('>IIBBBBB', data)
        elif typ == b'IDAT':
            idat += data
        pos += 12 + ln
        if typ == b'IEND':
            break

    w, h, bd, ct, _comp, _filt, inter = ihdr
    if inter != 0:
        raise SystemExit('interlaced not supported')
    if bd not in (8, 16):
        raise SystemExit('bitdepth %d not supported' % bd)

    ch = CH[ct]
    bpp = ch * bd // 8          # 每像素字节（滤波用）
    stride = w * bpp
    raw = zlib.decompress(idat)
    if len(raw) != (stride + 1) * h:
        raise SystemExit('size mismatch %d != %d' % (len(raw), (stride + 1) * h))

    out = bytearray(stride * h)
    prev = bytearray(stride)
    off = 0
    for y in range(h):
        ft = raw[off]
        off += 1
        line = bytearray(raw[off:off + stride])
        off += stride
        if ft == 1:
            for i in range(bpp, stride):
                line[i] = (line[i] + line[i - bpp]) & 0xFF
        elif ft == 2:
            for i in range(stride):
                line[i] = (line[i] + prev[i]) & 0xFF
        elif ft == 3:
            for i in range(stride):
                left = line[i - bpp] if i >= bpp else 0
                line[i] = (line[i] + ((left + prev[i]) >> 1)) & 0xFF
        elif ft == 4:
            for i in range(stride):
                a = line[i - bpp] if i >= bpp else 0
                b = prev[i]
                c = prev[i - bpp] if i >= bpp else 0
                p = a + b - c
                pa, pb, pc = abs(p - a), abs(p - b), abs(p - c)
                pr = a if (pa <= pb and pa <= pc) else (b if pb <= pc else c)
                line[i] = (line[i] + pr) & 0xFF
        elif ft != 0:
            raise SystemExit('bad filter %d at row %d' % (ft, y))
        out[y * stride:(y + 1) * stride] = line
        prev = line

    # 16 位取高字节（与"肉眼看到 8 位结果"一致）
    step = 2 if bd == 16 else 1

    def rgb(x, y):
        base = y * stride + x * ch * step
        if ch >= 3:
            return (out[base], out[base + step], out[base + 2 * step])
        g = out[base]
        return (g, g, g)

    return w, h, rgb


def main():
    path = sys.argv[1]
    w, h, rgb = load(path)
    print('%s  %dx%d' % (path, w, h))
    pts = []
    if len(sys.argv) > 2 and sys.argv[2] == '--probe':
        # 打印一组均匀抽样点
        for j in range(1, 10):
            for i in range(1, 10):
                pts.append((w * i // 10, h * j // 10))
    else:
        for a in sys.argv[2:]:
            x, y = a.split(',')
            pts.append((int(x), int(y)))
    for x, y in pts:
        r, g, b = rgb(x, y)
        print('%4d,%4d  #%02X%02X%02X  (%3d %3d %3d)' % (x, y, r, g, b, r, g, b))


if __name__ == '__main__':
    main()
