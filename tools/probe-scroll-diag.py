"""滚动条淡出诊断 —— **先定位条，再判"淡出跑没跑"**。

★★ 上一版的坑（2026-11-01，白追一轮）：「滚动条一条都没画」这个结论是假的。
   它取 `x = w-40 .. w`（窗口**右缘**）当采样带 —— 那里是**页面底色**，整列恒
   238.9，于是"滚动前后完全一样"必然成立。而条其实在**列表视口**的右缘
   （真机 x=1370..1375），画着、也在淡（119 → 209）。
   教训只有一条：**像素判据必须先在目标内部落点**。采不到目标时说"没测到"，
   不许把它写成"没变"—— 后者会被读成"产品坏了"。

判据三条，依次收紧（前一条不成立，后一条没有意义）：
  ① **内容真的滚了**：两张图的内容区（左侧 3/4）有点差；
  ② **定位到条**：最右一串 5~7 宽、低饱和、中亮度、≥16 行连续的竖列
     （定位逻辑在 `tools/probe_pixels.py`，与 `probe-motion.py` 共用一份）；
  ③ **淡出跑过**：那一列滚后 ≈119（全亮）→ 静止 3s ≈208（IDLE 0.35），差 ≥ 40，
     且静止值 < 240（`ScrollBarFade` 明写"淡出不给 0"）。

退出码：`0` 判完了（结论在输出里）· `2` **没测到**（找不到条 / 内容没滚）。
"没测到"与"没通过"分开，是这个脚本存在的主要理由。
"""

import ctypes
import os
import subprocess
import sys
import tempfile
import time
from ctypes import wintypes

from probe_pixels import column_luma, diff_count, find_scrollbar

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


def to_screen(hwnd, x, y):
    pt = wintypes.POINT(x, y)
    user32.ClientToScreen(hwnd, ctypes.byref(pt))
    user32.SetCursorPos(pt.x, pt.y)


def save_png_crop(data, w, h, x0, x1, y0, y1, path):
    """把一块区域存成 PNG（标准库手写，不引第三方依赖）。"""
    import struct
    import zlib

    cw, ch = x1 - x0, y1 - y0
    raw = bytearray()
    for y in range(y0, y1):
        raw.append(0)   # filter type 0
        for x in range(x0, x1):
            i = (y * w + x) * 4
            raw += bytes((data[i], data[i + 1], data[i + 2]))

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
            print("SKIP: 找不到窗口")
            return 2
        user32.SetForegroundWindow(hwnd)
        time.sleep(0.3)

        # 「长列表 Big」= 导航第 6 项
        to_screen(hwnd, 25, 90 + 5 * 36)
        time.sleep(0.1)
        user32.mouse_event(0x0002, 0, 0, 0, 0)
        time.sleep(0.05)
        user32.mouse_event(0x0004, 0, 0, 0, 0)
        time.sleep(1.2)

        w, h, base = grab(hwnd)
        print(f"客户区 {w}x{h}（已切到长列表页）")

        # 真滚轮 —— 必须真挪光标 + mouse_event（PostMessage 那套 TME_LEAVE 不认）
        to_screen(hwnd, w // 2, h // 2)
        time.sleep(0.15)
        for _ in range(3):
            user32.mouse_event(0x0800, 0, 0, -120, 0)
            time.sleep(0.08)
        user32.SetCursorPos(5, 5)   # 指针移出窗口，别让 hover 掺进来

        time.sleep(0.85)
        w, h, during = grab(hwnd)
        time.sleep(2.2)
        w, h, idle = grab(hwnd)

        # ① 内容真的滚了吗
        moved = diff_count(base, during, w, 0, 60, int(w * 0.75), h - 40)
        print(f"① 内容区变化（步长 2）= {moved} 点")
        if moved <= 50:
            print("SKIP: 滚轮没生效（内容区没变）—— 后面的判据在这里没有意义")
            return 2

        # ② 定位条（共享件；定位不到就是"没测到"，不是"没通过"）
        found = find_scrollbar(during, w, h)
        if found is None:
            print("SKIP: 没定位到滚动条（5~7 宽 / 低饱和 / 中亮度 / ≥16 行）")
            save_png_crop(during, w, h, w - 200, w, 0, h, os.path.join(ROOT, "_build", "_bar-locate.png"))
            return 2
        x0, x1 = found
        x = (x0 + x1) // 2
        print(f"② 条定位: x={x0}..{x1}（宽 {x1 - x0 + 1}），取中心列 x={x}")

        # ③ 淡出跑了吗
        min_a, avg_a = column_luma(during, w, h, x)
        min_b, avg_b = column_luma(idle, w, h, x)
        print(f"③ 滚后: 最暗 {min_a:.1f} / 均值 {avg_a:.1f}   ← 期望 ≈119（全亮）")
        print(f"   静止: 最暗 {min_b:.1f} / 均值 {avg_b:.1f}   ← 期望 ≈208（IDLE 0.35）")
        print(f"   Δ最暗 = {min_b - min_a:.1f}（判据 ≥ 40，且静止值必须 < 240 ⇒ 淡出不给 0）")

        save_png_crop(during, w, h, max(0, x0 - 12), min(w, x1 + 12), 0, h,
                      os.path.join(ROOT, "_build", "_bar-during.png"))
        save_png_crop(idle, w, h, max(0, x0 - 12), min(w, x1 + 12), 0, h,
                      os.path.join(ROOT, "_build", "_bar-idle2.png"))

        ok = (min_b - min_a) >= 40.0 and min_b < 240.0
        print("PASS" if ok else "FAIL: 条在，但淡出没发生 / 淡成了 0")
        return 0
    finally:
        proc.terminate()
        time.sleep(0.3)


if __name__ == "__main__":
    sys.exit(main())
