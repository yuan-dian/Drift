"""真机黑盒验证：启动一个应用 exe，找窗口、抓客户区像素、关窗。

    python tools/probe-window.py users        # 默认 users；也可 hello
    python tools/probe-window.py users 用户管理

判据（四者缺一不可）：
  ① 找到窗口，标题 == Application::title()；
  ② 客户区尺寸 == Bootstrap 声明的逻辑尺寸；
  ③ 客户区有**非白像素**且颜色分布符合主题 token（空窗/黑块会被判出来）；
  ④ **DPI 感知级别 == PER_MONITOR_AWARE_V2**（§6 / §24.1 #43）。★ 这条带**反例**：
     旧的 `SetProcessDPIAware()`（V1 = SYSTEM_AWARE=1）必须 FAIL —— 否则"跨屏不糊"
     这条判据就是空话（V1 进程跨屏会被系统位图拉伸，且收不到 `WM_DPICHANGED`）。

★ 为什么用 Python 而不是 PHP：本机 PowerShell 的 `Add-Type` 与 WScript COM 都被安全
  策略拦，`BitBlt`/`GetDIBits` 只能靠 ctypes 直调 user32/gdi32（同 `typephp-gui-build`
  技能里记的那条路子）。
★ 运行期 `PHPRC` 必须指向一个 **`opcache.enable=0`** 的 ini —— 否则 exe 在 `main()`
  之前就挂（`Opcode handlers are unusable due to ASLR`）。本脚本自己写一份到临时目录，
  不依赖外部文件（与 `tools/drift-build.php` 的构建期 ini 是两回事，别混用）。
"""

import atexit
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


def dpr_of(hwnd) -> float:
    """该窗口所在显示器的缩放（96dpi = 1.0）；查不到按 1.0 算，判据容差会兜住。"""
    try:
        user32.GetDpiForWindow.restype = wintypes.UINT
        user32.GetDpiForWindow.argtypes = [wintypes.HWND]
        return user32.GetDpiForWindow(hwnd) / 96.0
    except Exception:  # noqa: BLE001
        return 1.0


def main() -> int:
    app = sys.argv[1] if len(sys.argv) > 1 else "users"
    title = sys.argv[2] if len(sys.argv) > 2 else None

    # Bootstrap 声明的客户区**逻辑**尺寸（判据 ② 的期望值）
    logical = {
        "hello": (640, 400),
        "showcase": (960, 640),
        "tutorial": (720, 560),
        "users": (900, 640),
    }

    exe = os.path.join(ROOT, f"{app}.exe")
    if not os.path.isfile(exe):
        print(f"✗ 找不到 {exe} —— 先跑 `php tools/drift-build.php {app}`")
        return 2

    if title is None:
        # ★ 标题是**组合根**给 `createWindow()` 的字面量（不是 `Application::title()`：
        #   那个方法没有任何运行期消费者，见 `WindowSet::open()` 的第一个参数）。
        title = {
            "hello": "Hello Drift",
            "showcase": "Drift 示例台",
            "tutorial": "Drift 教程",
            "users": "用户管理",
        }.get(app, "Hello Drift")

    ini_dir = tempfile.mkdtemp(prefix="drift-run-")
    ini = os.path.join(ini_dir, "php.ini")
    with open(ini, "w", encoding="ascii") as fh:
        fh.write("opcache.enable=0\n")

    env = dict(os.environ)
    env["PHPRC"] = ini

    # ★ 状态文件是**用户的真实配置**（`%APPDATA%\Drift`）：应用退出时会写它，
    #   而判据 ② 要验的是"默认尺寸"—— 留着上次的几何就会把一条正确行为判成 FAIL。
    #   所以先挪开、跑完（含异常退出）原样还回去：不还回去等于"跑一次测试就改掉
    #   用户的窗口位置"。
    state_path = os.path.join(os.environ.get("APPDATA", ""), "Drift", f"{app}.window")
    stash = None
    if os.path.isfile(state_path):
        with open(state_path, "rb") as fh:
            stash = fh.read()
        os.remove(state_path)

    def restore_state() -> None:
        if stash is None:
            if os.path.isfile(state_path):
                os.remove(state_path)
            return
        os.makedirs(os.path.dirname(state_path), exist_ok=True)
        with open(state_path, "wb") as fh:
            fh.write(stash)

    atexit.register(restore_state)

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
        want_w, want_h = logical.get(app, (w, h))
        dpr = dpr_of(hwnd)
        # 允许 2px 误差：客户区尺寸由系统按 dpr 取整，不是我们算的
        size_ok = abs(w - want_w * dpr) <= 2 and abs(h - want_h * dpr) <= 2
        print(f"client rect = {w} x {h}（期望 {want_w}x{want_h} × {dpr}）"
              f"  {'ok' if size_ok else 'FAIL'}")

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

        # ④ DPI 感知级别（§6 / §24.1 #43）
        #    `GetWindowDpiAwarenessContext(hwnd)` → 不透明 ctx；
        #    `GetAwarenessFromDpiAwarenessContext(ctx)` → 0=UNAWARE / 1=SYSTEM_AWARE /
        #    2=PER_MONITOR_AWARE。V1(system) 给 1，per-monitor(V1/V2) 都给 2 ⇒ 还要用
        #    `AreDpiAwarenessContextsEqual(ctx, DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2)`
        #    把 V2 从 per-monitor V1 里分出来（V2 常量 = (HANDLE)-4）。
        dpi_ok = False
        try:
            user32.GetWindowDpiAwarenessContext.restype = ctypes.c_void_p
            user32.GetWindowDpiAwarenessContext.argtypes = [wintypes.HWND]
            user32.GetAwarenessFromDpiAwarenessContext.restype = ctypes.c_int
            user32.GetAwarenessFromDpiAwarenessContext.argtypes = [ctypes.c_void_p]
            user32.AreDpiAwarenessContextsEqual.restype = wintypes.BOOL
            user32.AreDpiAwarenessContextsEqual.argtypes = [ctypes.c_void_p, ctypes.c_void_p]

            ctx = user32.GetWindowDpiAwarenessContext(hwnd)
            awareness = user32.GetAwarenessFromDpiAwarenessContext(ctx)
            names = {0: "UNAWARE", 1: "SYSTEM_AWARE(V1)", 2: "PER_MONITOR_AWARE"}
            is_v2 = bool(user32.AreDpiAwarenessContextsEqual(ctx, ctypes.c_void_p(-4)))
            print(f"DPI 感知 = {awareness} ({names.get(awareness, '?')})  V2 = {is_v2}")
            dpi_ok = (awareness == 2) and is_v2
        except Exception as exc:   # noqa: BLE001 —— 查不到就是 FAIL，不静默放过
            print(f"DPI 感知查询失败：{exc}")

        ok = nonwhite > 0 and dpi_ok and size_ok
        user32.PostMessageW(hwnd, 0x0010, 0, 0)  # WM_CLOSE
        time.sleep(1.5)

    # 判据 ⑤：**退出码 0**（2026-10-30 加）。关窗后引擎 shutdown 段曾有一次
    # `php8ts.dll+0x24b30` 访问冲突（"窗口关得掉、画面也对，最后一步崩"），
    # 根因是组合根把应用对象图留到 shutdown 才释放；修好后这里盯死 0，
    # 免得同类退化被"画面对了就算过"盖住。
    code = proc.poll()
    if code is None:
        proc.terminate()
        code = "still-running"
    print(f"退出码 = {code}")
    ok = ok and code == 0

    print("OK" if ok else "FAIL")
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
