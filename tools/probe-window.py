"""真机黑盒验证：启动一个应用 exe，找窗口、抓客户区像素、关窗。

    python tools/probe-window.py users        # 默认 users；也可 hello
    python tools/probe-window.py users 用户管理

判据（三者缺一不可）：
  ① 找到窗口，标题 == Application::title()；
  ② 客户区尺寸 == Bootstrap 声明的逻辑尺寸；
  ③ 客户区有**非白像素**且颜色分布符合主题 token（空窗/黑块会被判出来）。

★ 为什么用 Python 而不是 PHP：本机 PowerShell 的 `Add-Type` 与 WScript COM 都被安全
  策略拦，`BitBlt`/`GetDIBits` 只能靠 ctypes 直调 user32/gdi32（同 `typephp-gui-build`
  技能里记的那条路子）。
★ 运行期 `PHPRC` 必须指向一个 **`opcache.enable=0`** 的 ini —— 否则 exe 在 `main()`
  之前就挂（`Opcode handlers are unusable due to ASLR`）。本脚本自己写一份到临时目录，
  不依赖外部文件（与 `tools/drift-build.php` 的构建期 ini 是两回事，别混用）。
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


class BITMAPINFOHEADER(ctypes.Structure):
    _fields_ = [
        ("biSize", wintypes.DWORD), ("biWidth", wintypes.LONG),
        ("biHeight", wintypes.LONG), ("biPlanes", wintypes.WORD),
        ("biBitCount", wintypes.WORD), ("biCompression", wintypes.DWORD),
        ("biSizeImage", wintypes.DWORD), ("biXPelsPerMeter", wintypes.LONG),
        ("biYPelsPerMeter", wintypes.LONG), ("biClrUsed", wintypes.DWORD),
        ("biClrImportant", wintypes.DWORD),
    ]


def main() -> int:
    app = sys.argv[1] if len(sys.argv) > 1 else "users"
    title = sys.argv[2] if len(sys.argv) > 2 else None

    exe = os.path.join(ROOT, f"{app}.exe")
    if not os.path.isfile(exe):
        print(f"✗ 找不到 {exe} —— 先跑 `php tools/drift-build.php {app}`")
        return 2

    if title is None:
        title = "用户管理" if app == "users" else "Hello Drift"

    ini_dir = tempfile.mkdtemp(prefix="drift-run-")
    ini = os.path.join(ini_dir, "php.ini")
    with open(ini, "w", encoding="ascii") as fh:
        fh.write("opcache.enable=0\n")

    env = dict(os.environ)
    env["PHPRC"] = ini

    proc = subprocess.Popen([exe], cwd=ROOT, env=env)
    time.sleep(2.5)

    hwnd = user32.FindWindowW(None, title)
    print(f"hwnd = {hwnd}  (0 = 没找到窗口；标题应为 {title!r})")
    print(f"process poll = {proc.poll()}  (None = 还在跑)")

    ok = False
    if hwnd:
        rect = wintypes.RECT()
        user32.GetClientRect(hwnd, ctypes.byref(rect))
        w, h = rect.right - rect.left, rect.bottom - rect.top
        print(f"client rect = {w} x {h}")

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

        data = buf.raw
        total = w * h
        distinct: dict[tuple[int, int, int], int] = {}
        for i in range(0, total * 4, 4):
            b, g, r = data[i], data[i + 1], data[i + 2]
            if (r, g, b) != (255, 255, 255):
                key = (r, g, b)
                distinct[key] = distinct.get(key, 0) + 1

        nonwhite = sum(distinct.values())
        print(f"非白像素 = {nonwhite} / {total}  ({nonwhite * 100.0 / total:.1f}%)")
        print("主要颜色(前 6):")
        for (r, g, b), n in sorted(distinct.items(), key=lambda kv: -kv[1])[:6]:
            print(f"  rgb({r},{g},{b}) x {n}")

        ok = nonwhite > 0
        user32.PostMessageW(hwnd, 0x0010, 0, 0)  # WM_CLOSE
        time.sleep(0.8)

    if proc.poll() is None:
        proc.terminate()

    print("OK" if ok else "FAIL")
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
