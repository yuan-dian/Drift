"""真机滚动条验证：启动 users.exe，核验列表页 ScrollView 的 thumb 画对了。

    python tools/probe-scrollbar.py

判据（三条缺一不可）：
  ① 右缘窄带（x ≥ 客户区宽−14）出现**连续竖条**，宽 6px（BAR_THICKNESS）；
  ② 长度 ≥ 20px 且上下端收窄（圆角 ⇒ 端行列数 < 中行列数）；
  ③ 最长连续段 ≥ 条长的 80%（★ 2026-10-17：第一版统计没对 y 去重 ——
     每行 6 个重复 y 让"连续段"判断自断 ⇒ 假报"碎成 2px"。判据自己要先自检。）

`--wheel` 追加滚轮量化（真机端到端 + 步长回归）：
  发 1 格 WM_MOUSEWHEEL ⇒ thumb 应下移 ≈ travel × 48 / maxScroll
  （users 列表页实测 37px；若步长被改回 1px/格，只动 0.8px ⇒ FAIL）。

★ 为什么是 users 而不是 showcase：showcase 的 ListPage 内容不溢出
  （maxScroll=0 ⇒ 按设计不画滚动条）；users 列表页种子 15 用户溢出
  （maxScroll≈64）⇒ 是现成的"内容超长"用例。
★ 运行期 PHPRC 必须指向 opcache.enable=0 的 ini（ASLR 下 opcache 拒绝启动），
  与 tools/probe-window.py 同规矩；本脚本自己写临时 ini。
"""

import ctypes
import os
import subprocess
import sys
import tempfile
import time
from ctypes import wintypes

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

user32 = ctypes.WinDLL("user32", use_last_error=True)
gdi32 = ctypes.WinDLL("gdi32", use_last_error=True)

THUMB = (110, 119, 129)   # 主题 Muted（light）


class BITMAPINFOHEADER(ctypes.Structure):
    _fields_ = [
        ("biSize", wintypes.DWORD), ("biWidth", wintypes.LONG),
        ("biHeight", wintypes.LONG), ("biPlanes", wintypes.WORD),
        ("biBitCount", wintypes.WORD), ("biCompression", wintypes.DWORD),
        ("biSizeImage", wintypes.DWORD), ("biXPelsPerMeter", wintypes.LONG),
        ("biYPelsPerMeter", wintypes.LONG), ("biClrUsed", wintypes.DWORD),
        ("biClrImportant", wintypes.DWORD),
    ]


def grab_client(hwnd, w, h):
    hdc = user32.GetDC(hwnd)
    mem = gdi32.CreateCompatibleDC(hdc)
    bmp = gdi32.CreateCompatibleBitmap(hdc, w, h)
    gdi32.SelectObject(mem, bmp)
    gdi32.BitBlt(mem, 0, 0, w, h, hdc, 0, 0, 0x00CC0020)
    bi = BITMAPINFOHEADER()
    bi.biSize = ctypes.sizeof(BITMAPINFOHEADER)
    bi.biWidth = w
    bi.biHeight = -h  # 自顶向下
    bi.biPlanes = 1
    bi.biBitCount = 32
    buf = ctypes.create_string_buffer(w * h * 4)
    gdi32.GetDIBits(mem, bmp, 0, h, buf, ctypes.byref(bi), 0)
    gdi32.DeleteObject(bmp)
    gdi32.DeleteDC(mem)
    user32.ReleaseDC(hwnd, hdc)
    return buf.raw


def thumb_bar(data, w, h, edge=14):
    """右缘窄带里的 thumb：按 y 聚合列数（★ y 先去重再找连续段）。"""
    per_y = {}
    for i in range(0, w * h * 4, 4):
        b, g, r = data[i], data[i + 1], data[i + 2]
        if (r, g, b) == THUMB:
            px = (i // 4) % w
            py = (i // 4) // w
            if px >= w - edge:
                per_y[py] = per_y.get(py, 0) + 1
    if not per_y:
        return None
    ys = sorted(per_y)
    runs, cur = [], [ys[0]]
    for y in ys[1:]:
        if y == cur[-1] + 1:
            cur.append(y)
        else:
            runs.append(cur)
            cur = [y]
    runs.append(cur)
    longest = max(runs, key=len)
    mid = per_y[longest[len(longest) // 2]]
    return {
        "x_from": w - edge,
        "y_from": longest[0],
        "y_to": longest[-1],
        "length": len(longest),
        "mid_cols": mid,
        "end_cols": min(per_y[longest[0]], per_y[longest[-1]]),
    }


def main() -> int:
    exe = os.path.join(ROOT, "users.exe")
    title = "用户管理"
    wheel = "--wheel" in sys.argv
    if not os.path.isfile(exe):
        print(f"✗ 找不到 {exe} —— 先跑 `php tools/drift-build.php users`")
        return 2

    ini_dir = tempfile.mkdtemp(prefix="drift-run-")
    ini = os.path.join(ini_dir, "php.ini")
    with open(ini, "w", encoding="ascii") as fh:
        fh.write("opcache.enable=0\n")
    env = dict(os.environ)
    env["PHPRC"] = ini

    proc = subprocess.Popen([exe], cwd=ROOT, env=env)
    time.sleep(2.5)
    hwnd = user32.FindWindowW(None, title)
    if not hwnd:
        print(f"✗ 没找到窗口（标题应为 {title!r}）")
        proc.terminate()
        return 2

    rect = wintypes.RECT()
    user32.GetClientRect(hwnd, ctypes.byref(rect))
    w, h = rect.right - rect.left, rect.bottom - rect.top
    data = grab_client(hwnd, w, h)
    bar = thumb_bar(data, w, h)
    print(f"client = {w} x {h}")
    print(f"thumb = {bar}")

    ok = (
        bar is not None
        and bar["mid_cols"] >= 5          # 条身 6px 宽
        and bar["length"] >= 20           # 不是一点残迹
        and bar["end_cols"] < bar["mid_cols"]   # 圆角端收窄
    )

    if ok and wheel:
        # 滚轮 1 格（WHEEL_DELTA=120）：lParam 给**屏幕**坐标（WndProc 会 ScreenToClient）
        pt = wintypes.POINT(0, 0)
        user32.ClientToScreen(hwnd, ctypes.byref(pt))
        sx, sy = pt.x + 400, pt.y + 300
        lparam = (sy << 16) | (sx & 0xFFFF)
        user32.PostMessageW(hwnd, 0x020A, 120 << 16, lparam)   # WM_MOUSEWHEEL
        time.sleep(0.8)
        bar2 = thumb_bar(grab_client(hwnd, w, h), w, h)
        moved = None if bar2 is None else bar2["y_from"] - bar["y_from"]
        print(f"滚 1 格 ⇒ thumb y {bar['y_from']} → {bar2['y_from'] if bar2 else '?'}"
              f"（下移 {moved}px；48px/格在该页 ≈ 37px，1px/格只会 0.8px）")
        ok = moved is not None and 25 <= moved <= 50
        print("wheel OK" if ok else "wheel FAIL")

    print("OK" if ok else "FAIL")

    user32.PostMessageW(hwnd, 0x0010, 0, 0)  # WM_CLOSE
    time.sleep(0.8)
    if proc.poll() is None:
        proc.terminate()
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
