"""真机黑盒验证：**两个窗口各自活着、各自记自己的状态**（2026-10-30，M1-b）。

    python tools/probe-two-windows.py

启动 `hello.exe`（`DRIFT_TWO_WINDOWS=1` ⇒ 组合根建第二个窗口），判据七条：

  ① 两个窗口都找到（标题分别是「Hello Drift」与「Hello Drift · 第二窗」）；
  ② 两个客户区各自符合 Bootstrap 声明的**逻辑**尺寸（× dpr）；
  ③ **两个**窗口都有非白像素 —— 第二个窗不是"建出来了但没画"；
  ④ 关掉第二个窗 ⇒ 它消失、**第一个窗还在、进程还在**（"关一个留一个"）；
  ⑤ 再关第一个窗 ⇒ 进程退出（壳的活窗口表空了才 `PostQuitMessage`）；
  ⑥ 退出后的状态文件里**两组并存**，且 `main.w` / `second.w` 分别等于两窗的实际
     尺寸（= 按槽位分组生效；整份覆盖时只剩一组、且值相同）；
  ⑦ 外部把两组改成**不同**尺寸后重启 ⇒ 两窗**各自**还原到各自的尺寸。

★ ④ 是这一份存在的理由：`EventLoop` 在关窗那一轮如果 `return`（M1-c 的单窗写法），
  ④ 会红；壳的 `WM_DESTROY` 如果还按"任意窗口销毁就退出"，④ 也会红。
  这两条都**不报错**，只是"关了一个窗，另一个跟着死"。
★ ⑤ 若 `EventLoop` 在表空后不等 `quit` 就收工，进程同样退出 ⇒ 它验的是
  "两个入口（表空 / WM_QUIT）都通向退出"，不是其中某一个。

★ ⑥ 顺带验了 M1-a 的**死句柄回填**：第二窗在④里先被关掉，`persist()` 跑在循环
  返回之后，那时它早已销毁 —— 能写出正确值只可能是壳的"最后已知几何"缓存接住了。
★ ⑥ 判据**不能只看"有两组键"**：整份覆盖时最后一组照样叫 `second`。必须比**值**：
  两窗默认尺寸不同（640×400 vs 480×360），覆盖的版本里两组值会相同。

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

# ── 被验的应用（第二个参数选，默认 hello）─────────────────────────────────
# ★ 一份探针验两个应用，而不是抄一份：七条判据与被验应用无关，只有
#   "exe / 环境变量 / 标题 / 尺寸 / 槽位名"是参数。抄一份的代价是"改了一处忘了另一处"，
#   而这类脚本恰恰是最没人重跑的那条路。
PROFILES = {
    "hello": {
        "exe": "hello.exe",
        "env": "DRIFT_TWO_WINDOWS",
        "main": ("Hello Drift", 640, 400),
        "second": ("Hello Drift · 第二窗", 480, 360),
        "slots": ("main", "second"),
        "restore": ((700, 420), (520, 340)),
        "settle": 3.0,
    },
    "showcase": {
        "exe": "showcase.exe",
        "env": "DRIFT_SECOND_WINDOW",
        "main": ("Drift 示例台", 960, 640),
        "second": ("多窗口 · 检查器", 420, 560),
        "slots": ("main", "inspector"),
        # ★ 比 hello 大：showcase 在 700 宽下有一个**既有的布局溢出**
        #   （`RenderFlex 放不下：子项共需 504.0，可用只有 416.0`），
        #   与本探针要验的东西无关 —— 拿它当还原尺寸会把一条正确行为判成 FAIL。
        #   那个溢出是另一件待办：`setMinSize(420, 320)` 的承诺大于内容的实际能力。
        "restore": ((1000, 700), (480, 420)),
        "settle": 5.0,
    },
}

PROFILE = PROFILES[sys.argv[1] if len(sys.argv) > 1 else "hello"]

TITLE_MAIN = PROFILE["main"][0]
TITLE_SECOND = PROFILE["second"][0]
SLOT_MAIN, SLOT_SECOND = PROFILE["slots"]

# Bootstrap 里的逻辑尺寸（`WINDOW_WIDTH` / `WINDOW_HEIGHT` 与第二窗的字面量）
LOGICAL = {TITLE_MAIN: (PROFILE["main"][1], PROFILE["main"][2]),
           TITLE_SECOND: (PROFILE["second"][1], PROFILE["second"][2])}

# ⑦ 用：两组**不同**的尺寸，且都不小于两个应用的 `setMinSize`
#   （小于最小尺寸会被 `WM_GETMINMAXINFO` 顶回去，那样判据会红在一次正确行为的副作用上）
RESTORE_MAIN, RESTORE_SECOND = PROFILE["restore"]

WM_CLOSE = 0x0010


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


def read_state(path: str) -> dict[str, str]:
    """读 kv 状态文件（`key=value` 一行一条；`#` 是注释）。缺文件 ⇒ 空表。"""
    if not os.path.isfile(path):
        return {}

    out: dict[str, str] = {}
    with open(path, encoding="utf-8") as fh:
        for raw in fh:
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            out[key.strip()] = value.strip()

    return out


def main() -> int:
    exe = os.path.join(ROOT, PROFILE["exe"])
    if not os.path.isfile(exe):
        print(f"✗ 找不到 {exe} —— 先跑 `php tools/drift-build.php {PROFILE['exe'][:-4]}`")
        return 2

    ini_dir = tempfile.mkdtemp(prefix="drift-run-")
    ini = os.path.join(ini_dir, "php.ini")
    with open(ini, "w", encoding="ascii") as fh:
        fh.write("opcache.enable=0\n")   # ASLR 下 opcache 会让 exe 在 main() 前就挂

    env = dict(os.environ)
    env["PHPRC"] = ini
    env[PROFILE["env"]] = "1"

    # ★ 状态文件是**用户的真实配置**（`%APPDATA%\Drift`）：探针要反复改它，
    #   必须先备份、结束时原样还回去。不还回去等于"跑一次测试就改掉用户的窗口位置"。
    state_path = os.path.join(
        os.environ.get("APPDATA", ""), "Drift", PROFILE["exe"][:-4] + ".window"
    )
    backup: bytes | None = None
    if os.path.isfile(state_path):
        with open(state_path, "rb") as fh:
            backup = fh.read()
        os.remove(state_path)

    fails: list[str] = []

    def check(label: str, ok: bool, detail: str) -> None:
        print(f"  {'ok  ' if ok else 'FAIL'}  {label}  —— {detail}")
        if not ok:
            fails.append(label)

    def launch(settle: float | None = None):
        proc = subprocess.Popen([exe], cwd=ROOT, env=env)
        time.sleep(PROFILE["settle"] if settle is None else settle)
        return (
            proc,
            user32.FindWindowW(None, TITLE_MAIN),
            user32.FindWindowW(None, TITLE_SECOND),
        )

    try:
        # ── 第一轮：多窗口基本盘 + 关窗语义 + 状态分组 ──────────────────────
        proc, main_hwnd, second_hwnd = launch()
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
            user32.PostMessageW(second_hwnd, WM_CLOSE, 0, 0)
            time.sleep(1.5)
            check("④ 关掉第二窗 ⇒ 它消失了", not user32.IsWindow(second_hwnd), "IsWindow(second) = 0")
            check("④ 关掉第二窗 ⇒ 第一个窗还在", bool(user32.IsWindow(main_hwnd)), "IsWindow(main) = 1")
            check("④ 关掉第二窗 ⇒ 进程还在（没有被 quit 带走）", proc.poll() is None,
                  f"poll={proc.poll()}")

            # ⑤ 再关第一个窗 ⇒ 进程退出
            user32.PostMessageW(main_hwnd, WM_CLOSE, 0, 0)
            time.sleep(1.5)
            code = proc.poll()
            # ★ 判据是**退出码 0**，不是"进程没了"（2026-10-30 修好后收紧）。
            #   在这之前这里是放水的：`0xC0000005` 是引擎 shutdown 段的崩溃，因为
            #   "早就存在"就不判 FAIL —— 那等于"崩溃也算退出"，是另一种静默失败。
            #   根因已修（组合根退出前卸下 `Errors::installFatalBox` 与
            #   `ResizeHookState::$pump` 两个静态闭包，实测 8/8 → 0/8），所以现在
            #   非 0 一律 FAIL，不再有"已知崩溃"这个豁免。
            check("⑤ 关掉最后一个窗 ⇒ 进程干净退出（退出码 0）", code == 0, f"poll={code}")

            # ⑥ 状态文件：两组并存，且值**各自**等于该窗口的实际逻辑尺寸
            state = read_state(state_path)
            main_w = state.get(f"{SLOT_MAIN}.w")
            second_w = state.get(f"{SLOT_SECOND}.w")
            print(f"state = {os.path.basename(state_path)}: {state}")

            def near(text, want) -> bool:
                try:
                    return text is not None and abs(float(text) - float(want)) <= 2.0
                except ValueError:
                    return False

            check(f"⑥ 状态文件里两窗各一组（{SLOT_MAIN} / {SLOT_SECOND} 都在）",
                  main_w is not None and second_w is not None,
                  f"{SLOT_MAIN}.w={main_w} {SLOT_SECOND}.w={second_w}")
            # ★ 比**值**而不是比"有没有键"：整份覆盖的最后一次写入照样叫 `second`
            check("⑥ 两组的 w 各自等于本窗尺寸（覆盖式写入会得到两个相同的值）",
                  near(main_w, LOGICAL[TITLE_MAIN][0]) and near(second_w, LOGICAL[TITLE_SECOND][0]),
                  f"{SLOT_MAIN}.w={main_w}（期望 {LOGICAL[TITLE_MAIN][0]}）"
                  f" {SLOT_SECOND}.w={second_w}（期望 {LOGICAL[TITLE_SECOND][0]}）")
            check("⑥ 版本号已抬到 2（v1 是平铺单窗格式）", state.get("v") == "2", f"v={state.get('v')}")

        # ── 第二轮：按状态文件各自还原 ────────────────────────────────────
        # 外部写一份 v2 文件（两组不同尺寸）⇒ 启动后两窗应当**各自**落到各自的值。
        # ★ 这是"槽位真的编进了持久化 key"的唯一硬证据：只按句柄/顺序存的应用，
        #   这里会把第二个尺寸给到第一个窗（或者两个窗都变成同一个尺寸）。
        with open(state_path, "w", encoding="utf-8") as fh:
            fh.write(
                "# Drift window state — do not edit while the app runs\n"
                "v=2\n"
                f"{SLOT_MAIN}.x=120\n{SLOT_MAIN}.y=120\n{SLOT_MAIN}.w={RESTORE_MAIN[0]}\n"
                f"{SLOT_MAIN}.h={RESTORE_MAIN[1]}\n{SLOT_MAIN}.max=0\n"
                f"{SLOT_SECOND}.x=800\n{SLOT_SECOND}.y=160\n{SLOT_SECOND}.w={RESTORE_SECOND[0]}\n"
                f"{SLOT_SECOND}.h={RESTORE_SECOND[1]}\n{SLOT_SECOND}.max=0\n"
            )

        proc2, main2, second2 = launch()
        print(f"[轮 2] hwnd(main) = {main2}   hwnd(second) = {second2}   process poll = {proc2.poll()}")

        if main2 and second2:
            for hwnd, title, want in (
                (main2, TITLE_MAIN, RESTORE_MAIN),
                (second2, TITLE_SECOND, RESTORE_SECOND),
            ):
                w, h = client_rect(hwnd)
                dpr = dpr_of(hwnd)
                ok = abs(w - want[0] * dpr) <= 2 and abs(h - want[1] * dpr) <= 2
                check(f"⑦ {title} 按状态文件还原到 {want[0]}×{want[1]}", ok,
                      f"{w}x{h}（期望 {want[0]}x{want[1]} × {dpr}）")

            user32.PostMessageW(second2, WM_CLOSE, 0, 0)
            time.sleep(0.8)
            user32.PostMessageW(main2, WM_CLOSE, 0, 0)
            time.sleep(1.5)
        else:
            check("⑦ 轮 2 两个窗口都在", False, f"main={main2} second={second2}")

        if proc2.poll() is None:
            proc2.terminate()
    finally:
        # 还原用户的真实配置（探针改的是 `%APPDATA%\Drift\hello.window`）
        if backup is None:
            if os.path.isfile(state_path):
                os.remove(state_path)
        else:
            os.makedirs(os.path.dirname(state_path), exist_ok=True)
            with open(state_path, "wb") as fh:
                fh.write(backup)

    print()
    if fails:
        print(f"✗ {len(fails)} 条失败：{' / '.join(fails)}")
        return 1

    print("✓ 全部通过（两窗各画各的、关一个不影响另一个、状态各存各的、能各自还原）")
    return 0


if __name__ == "__main__":
    sys.exit(main())
