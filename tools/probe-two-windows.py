"""真机黑盒验证：**两个窗口各自活着**（2026-10-30，M1-b）。

    python tools/probe-two-windows.py

启动 `hello.exe`（`DRIFT_TWO_WINDOWS=1` ⇒ 组合根建第二个窗口），判据五条：

  ① 两个窗口都找到（标题分别是「Hello Drift」与「Hello Drift · 第二窗」）；
  ② 两个客户区各自符合 Bootstrap 声明的**逻辑**尺寸（× dpr）；
  ③ **两个**窗口都有非白像素 —— 第二个窗不是"建出来了但没画"；
  ④ 关掉第二个窗 ⇒ 它消失、**第一个窗还在、进程还在**（"关一个留一个"）；
  ⑤ 再关第一个窗 ⇒ 进程退出（壳的活窗口表空了才 `PostQuitMessage`）。

★ ④ 是这一份存在的理由：`EventLoop` 在关窗那一轮如果 `return`（M1-c 的单窗写法），
  ④ 会红；壳的 `WM_DESTROY` 如果还按"任意窗口销毁就退出"，④ 也会红。
  这两条都**不报错**，只是"关了一个窗，另一个跟着死"。
★ ⑤ 若 `EventLoop` 在表空后不等 `quit` 就收工，进程同样退出 ⇒ 它验的是
  "两个入口（表空 / WM_QUIT）都通向退出"，不是其中某一个。

★ 为什么用 Python 而不是 PHP：同 `probe-window.py` —— 本机 PowerShell 的 `Add-Type`
  与 WScript COM 都被安全策略拦，`FindWindowW` / `BitBlt` 只能靠 ctypes 直调。
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

TITLE_MAIN = "Hello Drift"
TITLE_SECOND = "Hello Drift · 第二窗"

# Bootstrap 里的逻辑尺寸（`WINDOW_WIDTH` / `WINDOW_HEIGHT` 与第二窗的字面量）
LOGICAL = {TITLE_MAIN: (640, 400), TITLE_SECOND: (480, 360)}


class BITMAPINFOHEADER(ctypes.Structure):
    _fields_ = [
        ("biSize", wintypes.DWORD), ("biWidth", wintypes.LONG),
        ("biHeight", wintypes.LONG), ("biPlanes", wintypes.WORD),
        ("biBitCount", wintypes.WORD), ("biCompression", wintypes.DWORD),
        ("biSizeImage", wintypes.DWORD), ("biXPelsPerMeter", wintypes.LONG),
        ("biYPelsPerMeter", wintypes.LONG), ("biClrUsed", wintypes.DWORD),
        ("biClrImportant", wintypes.DWORD),
    ]


def client_rect(hwnd) -> tuple[int, int]:
    rect = wintypes.RECT()
    user32.GetClientRect(hwnd, ctypes.byref(rect))
    return rect.right - rect.left, rect.bottom - rect.top


def nonwhite_ratio(hwnd, w: int, h: int) -> float:
    """客户区非白像素占比（0.0 = 全白 / 没画东西）。"""
    hdc = user32.GetDC(hwnd)
    mem = gdi32.CreateCompatibleDC(hdc)
    bmp = gdi32.CreateCompatibleBitmap(hdc, w, h)
    gdi32.SelectObject(mem, bmp)
    gdi32.BitBlt(mem, 0, 0, w, h, hdc, 0, 0, 0x00CC0020)

    bi = BITMAPINFOHEADER()
    bi.biSize = ctypes.sizeof(BITMAPINFOHEADER)
    bi.biWidth = w
    bi.biHeight = -h            # 自顶向下
    bi.biPlanes = 1
    bi.biBitCount = 32
    buf = ctypes.create_string_buffer(w * h * 4)
    gdi32.GetDIBits(mem, bmp, 0, h, buf, ctypes.byref(bi), 0)

    data = buf.raw
    nonwhite = 0
    for i in range(0, w * h * 4, 4):
        if (data[i + 2], data[i + 1], data[i]) != (255, 255, 255):
            nonwhite += 1

    gdi32.DeleteObject(bmp)
    gdi32.DeleteDC(mem)
    user32.ReleaseDC(hwnd, hdc)

    return nonwhite / float(w * h)


def dpr_of(hwnd) -> float:
    try:
        user32.GetDpiForWindow.restype = wintypes.UINT
        user32.GetDpiForWindow.argtypes = [wintypes.HWND]
        return user32.GetDpiForWindow(hwnd) / 96.0
    except Exception:  # noqa: BLE001 —— 查不到按 1.0 算，② 的容差会兜住
        return 1.0


def main() -> int:
    exe = os.path.join(ROOT, "hello.exe")
    if not os.path.isfile(exe):
        print(f"✗ 找不到 {exe} —— 先跑 `php tools/drift-build.php hello`")
        return 2

    ini_dir = tempfile.mkdtemp(prefix="drift-run-")
    ini = os.path.join(ini_dir, "php.ini")
    with open(ini, "w", encoding="ascii") as fh:
        fh.write("opcache.enable=0\n")   # ASLR 下 opcache 会让 exe 在 main() 前就挂

    env = dict(os.environ)
    env["PHPRC"] = ini
    env["DRIFT_TWO_WINDOWS"] = "1"

    proc = subprocess.Popen([exe], cwd=ROOT, env=env)
    time.sleep(3.0)

    fails: list[str] = []

    def check(label: str, ok: bool, detail: str) -> None:
        print(f"  {'ok  ' if ok else 'FAIL'}  {label}  —— {detail}")
        if not ok:
            fails.append(label)

    main_hwnd = user32.FindWindowW(None, TITLE_MAIN)
    second_hwnd = user32.FindWindowW(None, TITLE_SECOND)
    print(f"hwnd(main) = {main_hwnd}   hwnd(second) = {second_hwnd}   process poll = {proc.poll()}")

    check("① 两个窗口都在", bool(main_hwnd) and bool(second_hwnd),
          f"main={main_hwnd} second={second_hwnd}")

    if main_hwnd and second_hwnd:
        for hwnd, title in ((main_hwnd, TITLE_MAIN), (second_hwnd, TITLE_SECOND)):
            w, h = client_rect(hwnd)
            dpr = dpr_of(hwnd)
            want_w, want_h = LOGICAL[title]
            # 允许 2px 误差：客户区尺寸由系统按 dpr 取整，不是我们算的
            ok_size = abs(w - want_w * dpr) <= 2 and abs(h - want_h * dpr) <= 2
            check(f"② {title} 客户区 = 声明的逻辑尺寸 × dpr", ok_size,
                  f"{w}x{h}（期望 {want_w}x{want_h} × {dpr}）")

            # ★ 阈值只区分"画了"与"全白"，不是"内容够多"：hello 是白底几行字
            #   （实测 ~0.2%），`users` 那种带列表/顶条的才 40%。用法错了就会把
            #   一个正常的最小应用判成空白窗 —— 判据要按**被验的是哪个应用**定。
            ratio = nonwhite_ratio(hwnd, w, h)
            check(f"③ {title} 有内容（不是空白窗口）", ratio > 0.0005, f"非白 {ratio * 100:.2f}%")

        # ④ 关第二个窗 ⇒ 它消失、第一个窗与进程都还在
        user32.PostMessageW(second_hwnd, 0x0010, 0, 0)   # WM_CLOSE
        time.sleep(1.5)
        check("④ 关掉第二窗 ⇒ 它消失了", not user32.IsWindow(second_hwnd), "IsWindow(second) = 0")
        check("④ 关掉第二窗 ⇒ 第一个窗还在", bool(user32.IsWindow(main_hwnd)), "IsWindow(main) = 1")
        check("④ 关掉第二窗 ⇒ 进程还在（没有被 quit 带走）", proc.poll() is None,
              f"poll={proc.poll()}")

        # ⑤ 再关第一个窗 ⇒ 进程退出
        user32.PostMessageW(main_hwnd, 0x0010, 0, 0)
        time.sleep(1.5)
        code = proc.poll()
        # ★ 退出码 0xC0000005 = 关窗后 teardown 期的**既有偶发崩溃**（REVIEW-2026-10-28 §19
        #   的 exit=139；单窗 `hello` 实测 8 次里 2 次）。它**不是**本轮引入的，所以不判
        #   FAIL —— 但也**不能沉默**：把码打出来，别让"崩溃也算退出"混成"通过"。
        known = code == 0xC0000005
        check("⑤ 关掉最后一个窗 ⇒ 进程结束",
              code is not None and not known,
              f"poll={code}" + ("（已知 teardown 偶发崩溃，见 REVIEW-2026-10-28 §19）" if known else ""))

    if proc.poll() is None:
        proc.terminate()

    print()
    if fails:
        print(f"✗ {len(fails)} 条失败：{' / '.join(fails)}")
        return 1

    print("✓ 全部通过（两个窗口各自活着：各画各的、关一个不影响另一个）")
    return 0


if __name__ == "__main__":
    sys.exit(main())
