"""滚动条淡出诊断：滚动到底有没有发生、那一列到底变没变、变多少。

`probe-motion.py` 的两条滚动条断言都报 `col=None`（两张图之间没有任何列变化），
先要分清是「滚轮没生效」还是「淡出没跑」。本脚本把每一步的像素都打出来。
"""

import ctypes
import os
import subprocess
import sys
import tempfile
import time
from ctypes import wintypes

ROOT = os.path.dirname(os.path.abspath(__file__))
if os.path.basename(ROOT) == "tools":
    ROOT = os.path.dirname(ROOT)

user32 = ctypes.WinDLL("user32", use_last_error=True)
gdi32 = ctypes.WinDLL("gdi32", use_last_error=True)


class BIH(ctypes.Structure):
    _fields_ = [
        ("biSize", wintypes.DWORD), ("biWidth", wintypes.LONG), ("biHeight", wintypes.LONG),
        ("biPlanes", wintypes.WORD), ("biBitCount", wintypes.WORD), ("biCompression", wintypes.DWORD),
        ("biSizeImage", wintypes.DWORD), ("biXPelsPerMeter", wintypes.LONG), ("biYPelsPerMeter", wintypes.LONG),
        ("biClrUsed", wintypes.DWORD), ("biClrImportant", wintypes.DWORD),
    ]


def grab(hwnd):
    rect = wintypes.RECT()
    user32.GetClientRect(hwnd, ctypes.byref(rect))
    w, h = rect.right, rect.bottom
    hdc = user32.GetDC(hwnd)
    mem = gdi32.CreateCompatibleDC(hdc)
    bmp = gdi32.CreateCompatibleBitmap(hdc, w, h)
    old = gdi32.SelectObject(mem, bmp)
    gdi32.BitBlt(mem, 0, 0, w, h, hdc, 0, 0, 0x00CC0020)
    bi = BIH()
    bi.biSize = ctypes.sizeof(BIH)
    bi.biWidth = w
    bi.biHeight = -h
    bi.biPlanes = 1
    bi.biBitCount = 32
    buf = ctypes.create_string_buffer(w * h * 4)
    gdi32.GetDIBits(mem, bmp, 0, h, buf, ctypes.byref(bi), 0)
    gdi32.SelectObject(mem, old)
    gdi32.DeleteObject(bmp)
    gdi32.DeleteDC(mem)
    user32.ReleaseDC(hwnd, hdc)
    return w, h, bytearray(buf)


def px(d, w, x, y):
    i = (y * w + x) * 4
    return d[i], d[i + 1], d[i + 2]


def to_screen(hwnd, x, y):
    pt = wintypes.POINT(x, y)
    user32.ClientToScreen(hwnd, ctypes.byref(pt))
    user32.SetCursorPos(pt.x, pt.y)


def diff_count(a, b, w, h, x0, y0, x1, y1):
    n = 0
    for y in range(y0, y1, 2):
        for x in range(x0, x1, 2):
            if px(a, w, x, y) != px(b, w, x, y):
                n += 1
    return n


def save_png_crop(data, w, h, x0, x1, y0, y1, path):
    """把一块区域存成 PNG（标准库手写，不引第三方依赖）。"""
    import struct
    import zlib

    cw, ch = x1 - x0, y1 - y0
    raw = bytearray()
    for y in range(y0, y1):
        raw.append(0)   # filter type 0
        for x in range(x0, x1):
            r, g, b = px(data, w, x, y)
            raw += bytes((r, g, b))

    def chunk(tag, payload):
        c = struct.pack(">I", len(payload)) + tag + payload
        return c + struct.pack(">I", zlib.crc32(tag + payload) & 0xFFFFFFFF)

    png = b"\x89PNG\r\n\x1a\n"
    png += chunk(b"IHDR", struct.pack(">IIBBBBB", cw, ch, 8, 2, 0, 0, 0))
    png += chunk(b"IDAT", zlib.compress(bytes(raw), 9))
    png += chunk(b"IEND", b"")
    with open(path, "wb") as f:
        f.write(png)
    print(f"  存图 {path} ({cw}x{ch})")


def main():
    ini_dir = tempfile.mkdtemp(prefix="drift-run-")
    ini = os.path.join(ini_dir, "php.ini")
    with open(ini, "w", encoding="utf-8") as f:
        f.write("opcache.enable=0\n")
    env = dict(os.environ)
    env["PHPRC"] = ini

    proc = subprocess.Popen([os.path.join(ROOT, "showcase.exe")], cwd=ROOT, env=env)
    try:
        time.sleep(3.0)
        hwnd = user32.FindWindowW(None, "Drift 示例台")
        if not hwnd:
            print("找不到窗口")
            return 2
        user32.SetForegroundWindow(hwnd)
        time.sleep(0.3)

        # 「长列表 Big」= 第 6 项
        to_screen(hwnd, 25, 90 + 5 * 36)
        time.sleep(0.1)
        user32.mouse_event(0x0002, 0, 0, 0, 0)
        time.sleep(0.05)
        user32.mouse_event(0x0004, 0, 0, 0, 0)
        time.sleep(1.0)

        w, h, base = grab(hwnd)
        print(f"客户区 {w}x{h}  （切到长列表页）")

        # 右带 40px 里找"非纯白且低饱和"的列（滚动条）
        def bar_cols(data):
            cols = []
            for x in range(w - 40, w):
                n = 0
                for y in range(60, h - 10, 2):
                    c = px(data, w, x, y)
                    if max(c) < 250 and max(c) - min(c) < 24:
                        n += 1
                if n >= 40:
                    cols.append((x, n))
            return cols

        print("滚动前右带候选列:", bar_cols(base)[:6])

        # 真实滚轮
        to_screen(hwnd, w // 2, h // 2)
        time.sleep(0.15)
        user32.mouse_event(0x0800, 0, 0, -120, 0)
        time.sleep(0.3)
        w, h, scrolled = grab(hwnd)
        save_png_crop(scrolled, w, h, 0, w, 0, h, os.path.join(ROOT, "_build", "_bar-scrolled.png"))

        moved_content = diff_count(base, scrolled, w, h, 200, 100, w - 60, h - 40)
        print(f"滚轮后内容区变化（步长2）={moved_content} 点  ⇒ {'滚动了' if moved_content > 50 else '没滚动'}")
        print("滚动后右带候选列:", bar_cols(scrolled)[:6])

        # 该列在滚动后的 luma
        cols = bar_cols(scrolled)
        if cols:
            x = cols[0][0]
            sample = [sum(px(scrolled, w, x, y)) / 3.0 for y in range(60, h - 10, 4)]
            print(f"滚动条列 x={x} luma 均值={sum(sample) / len(sample):.1f} 最小={min(sample):.1f}")

        # 等 1.8s 无输入
        user32.SetCursorPos(10, 10)   # 指针移出窗口，避免 hover 干扰
        time.sleep(1.8)
        w, h, idle = grab(hwnd)
        save_png_crop(idle, w, h, w - 80, w, 0, h, os.path.join(ROOT, "_build", "_bar-idle.png"))
        print(f"空闲后内容区变化={diff_count(scrolled, idle, w, h, 200, 100, w - 60, h - 40)} 点")
        print("空闲后右带候选列:", bar_cols(idle)[:6])
        if cols:
            x = cols[0][0]
            sample = [sum(px(idle, w, x, y)) / 3.0 for y in range(60, h - 10, 4)]
            print(f"空闲后同列 luma 均值={sum(sample) / len(sample):.1f} 最小={min(sample):.1f}")

        # 逐列找"变了多少"
        best = []
        for x in range(w - 30, w):
            bs, as_ = [], []
            for y in range(60, h - 10, 2):
                ca, cb = px(scrolled, w, x, y), px(idle, w, x, y)
                if ca != cb:
                    bs.append(sum(ca) / 3.0)
                    as_.append(sum(cb) / 3.0)
            if bs:
                best.append((x, len(bs), sum(as_) / len(as_) - sum(bs) / len(bs)))
        best.sort(key=lambda t: -t[1])
        print("变化最多的右带列（x, 变化行数, Δluma）:", best[:5] if best else "无")

        return 0
    finally:
        proc.terminate()


if __name__ == "__main__":
    sys.exit(main())
