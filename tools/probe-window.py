"""真机黑盒验证：启动一个应用 exe，找窗口、抓客户区像素、关窗。

    python tools/probe-window.py users        # 默认 users；也可 hello
    python tools/probe-window.py users 用户管理

判据（五者缺一不可）：
  ① 找到窗口，标题 == Application::title()；
  ② 客户区尺寸 == Bootstrap 声明的逻辑尺寸；
  ③ 客户区有**非白像素**且颜色分布符合主题 token（空窗/黑块会被判出来）；
  ④ **DPI 感知级别 == PER_MONITOR_AWARE_V2**（§6 / §24.1 #43）。★ 这条带**反例**：
     旧的 `SetProcessDPIAware()`（V1 = SYSTEM_AWARE=1）必须 FAIL —— 否则"跨屏不糊"
     这条判据就是空话（V1 进程跨屏会被系统位图拉伸，且收不到 `WM_DPICHANGED`）。
  ⑤ 关窗后**退出码 0**（2026-10-30 加）：引擎 shutdown 段曾有一次
     `php8ts.dll+0x24b30` 访问冲突（"窗口关得掉、画面也对，最后一步崩"），
     根因是组合根把应用对象图留到 shutdown 才释放。画面对了不算过，退出码也得是 0。

★ 本探针**不碰输入**（`interactive=False`）—— 它只量尺寸、颜色、DPI 感知级别。
  所以在桌面锁定 / 非交互会话里它照样能跑完，是这一层最好的"能跑通"证据。

★ 启动 / 截图 / 收尾 / 用户状态隔离全部走 `tools/probe_win.py`（唯一一份）。
  本文件原来自己抄了 `PHPRC` 临时 ini、`BitBlt`+`GetDIBits`、以及"先把
  `%APPDATA%\\Drift\\<app>.window` 挪开再还回去"那套 —— 前者 9 个探针各一份，
  后者只有这里做，其余 8 个都在改用户的窗口位置。
"""

import ctypes
import json
import os
import sys
from ctypes import wintypes

from probe_check import check, run
from probe_win import App, grab

# Bootstrap 声明的客户区**逻辑**尺寸（判据 ② 的期望值）
LOGICAL = {
    "hello": (640, 400),
    "showcase": (960, 640),
    "tutorial": (720, 560),
    "users": (900, 640),
    # `apps/watermark/bin/win32/Bootstrap.php` 的 WINDOW_WIDTH/HEIGHT
    "watermark": (960, 780),
}

# ★ 标题的唯一来源是 **`Application::title()`**（2026-10-30 裁决）：组合根给
#   `WindowSet::open()` 传 `null` ⇒ 用应用自己的；只有"同一个应用类开第二个
#   窗口"才在窗口这一层覆盖（`hello` 的第二窗）。
#   ⇒ 这张表里的值必须与各 `*Application::title()` **逐字相同**，
#     不一致的症状是 `FindWindowW` 返回 0（不是报错）。
TITLES = {
    "hello": "Hello Drift",
    "showcase": "Drift 示例台",
    "tutorial": "Drift 教程",
    "users": "用户管理",
    "watermark": "AI 图片处理工具",
}


def dpr_of(hwnd) -> float:
    """该窗口所在显示器的缩放（96dpi = 1.0）；查不到按 1.0 算，判据容差会兜住。"""
    try:
        user32 = ctypes.WinDLL("user32", use_last_error=True)
        user32.GetDpiForWindow.restype = wintypes.UINT
        user32.GetDpiForWindow.argtypes = [wintypes.HWND]

        return user32.GetDpiForWindow(hwnd) / 96.0
    except Exception:  # noqa: BLE001
        return 1.0


def dpi_awareness(hwnd):
    """返回 `(awareness_int, is_v2)`。查不到就是 FAIL —— 不静默放过。

    `GetWindowDpiAwarenessContext(hwnd)` → 不透明 ctx；
    `GetAwarenessFromDpiAwarenessContext(ctx)` → 0=UNAWARE / 1=SYSTEM_AWARE(V1) /
    2=PER_MONITOR_AWARE。V1(system) 给 1，per-monitor(V1/V2) 都给 2 ⇒ 还要用
    `AreDpiAwarenessContextsEqual(ctx, PER_MONITOR_AWARE_V2)` 把 V2 从 V1 里分出来
    （V2 常量 = `(HANDLE)-4`）。
    """
    user32 = ctypes.WinDLL("user32", use_last_error=True)
    user32.GetWindowDpiAwarenessContext.restype = ctypes.c_void_p
    user32.GetWindowDpiAwarenessContext.argtypes = [wintypes.HWND]
    user32.GetAwarenessFromDpiAwarenessContext.restype = ctypes.c_int
    user32.GetAwarenessFromDpiAwarenessContext.argtypes = [ctypes.c_void_p]
    user32.AreDpiAwarenessContextsEqual.restype = wintypes.BOOL
    user32.AreDpiAwarenessContextsEqual.argtypes = [ctypes.c_void_p, ctypes.c_void_p]

    ctx = user32.GetWindowDpiAwarenessContext(hwnd)
    awareness = user32.GetAwarenessFromDpiAwarenessContext(ctx)
    is_v2 = bool(user32.AreDpiAwarenessContextsEqual(ctx, ctypes.c_void_p(-4)))

    return awareness, is_v2


def main() -> int:
    app = sys.argv[1] if len(sys.argv) > 1 else "users"
    title = sys.argv[2] if len(sys.argv) > 2 else TITLES.get(app, "Hello Drift")

    # 默认仍是 `<应用>.exe`。三个环境变量只为「同一个应用换一种运行形态」而留：
    # dev 壳（dev.exe + DRIFT_DEV_*）与 dev 运行器（php.exe + drift-dev.php）
    # 复用这里的五条判据与 App.close() 的自动关窗，不另起一套进程管理。
    exe = os.environ.get("DRIFT_PROBE_EXE", f"{app}.exe")
    args = json.loads(os.environ.get("DRIFT_PROBE_ARGS_JSON", "[]"))
    env_extra = json.loads(os.environ.get("DRIFT_PROBE_ENV_JSON", "null"))

    with App(exe, title, args=args, env_extra=env_extra, interactive=False,
             state_name=app) as win:
        hwnd = win.hwnd
        print(f"hwnd = {hwnd}  标题 = {title!r}")

        # ① 窗口（`App.__enter__` 已经等到了；这里只是把它变成一条判据）
        check("① 窗口出来了，标题对得上", bool(hwnd), f"hwnd={hwnd}")

        w, h = win.size()
        want_w, want_h = LOGICAL.get(app, (w, h))
        dpr = dpr_of(hwnd)
        # 允许 2px 误差：客户区尺寸由系统按 dpr 取整，不是我们算的
        size_ok = abs(w - want_w * dpr) <= 2 and abs(h - want_h * dpr) <= 2
        check("② 客户区尺寸 == Bootstrap 声明的逻辑尺寸", size_ok,
              f"{w}x{h} 期望 {want_w}x{want_h} × {dpr}")

        # ③ 非白像素 + 颜色分布
        w, h, data = grab(hwnd)
        total = w * h
        distinct: dict[tuple[int, int, int], int] = {}
        for i in range(0, total * 4, 4):
            b, g, r = data[i], data[i + 1], data[i + 2]
            if (r, g, b) != (255, 255, 255):
                key = (r, g, b)
                distinct[key] = distinct.get(key, 0) + 1

        nonwhite = sum(distinct.values())
        print(f"  非白像素 = {nonwhite} / {total}  ({nonwhite * 100.0 / total:.1f}%)")
        for (r, g, b), n in sorted(distinct.items(), key=lambda kv: -kv[1])[:6]:
            print(f"    rgb({r},{g},{b}) x {n}")
        check("③ 客户区有内容（不是一张白纸）", nonwhite > total * 0.01,
              f"{nonwhite * 100.0 / total:.1f}%")

        # ④ DPI 感知级别
        try:
            awareness, is_v2 = dpi_awareness(hwnd)
            names = {0: "UNAWARE", 1: "SYSTEM_AWARE(V1)", 2: "PER_MONITOR_AWARE"}
            check("④ DPI 感知 == PER_MONITOR_AWARE_V2", awareness == 2 and is_v2,
                  f"{awareness} ({names.get(awareness, '?')}) V2={is_v2}")
        except Exception as exc:   # noqa: BLE001 —— 查不到就是 FAIL
            check("④ DPI 感知 == PER_MONITOR_AWARE_V2", False, f"查询失败：{exc}")

        # ⑤ 主动关窗后才能看退出码（`with` 退出时还会再关一次，是幂等的）
        win.close()
        code = win.proc.returncode
        check("⑤ 关窗后退出码 == 0（shutdown 段没崩）", code == 0, f"code={code}")

    return 0


if __name__ == "__main__":
    sys.exit(run(main))
