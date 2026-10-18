#!/usr/bin/env python
"""真机验证「后台取数」全链：窗口 + 常驻 worker + 真的等了网络。

```bash
# 先起 mock（另开一个终端 / 后台任务）：
php -S 127.0.0.1:8099 tools/app-workers/users-mock.php
# 再跑：
python tools/probe-async.py users
```

判据（四条缺一不可）：
  ① 窗口起来了（900×640，DPI V2）；
  ② **同时有 2 个 `users.exe` 进程** —— 主进程 + `--drift-worker` 子进程
     （这条是「同一份 AOT 产物靠 argv 分流」的直接证据）；
  ③ 客户区非白像素 ≥ 8% —— 列表 15 行真的画出来了（后台数据回来了）；
  ④ 关掉主窗口后，worker 跟着退出（stdin 关闭 ⇒ 正常收工，不留孤儿进程）。
"""

import ctypes
import os
import subprocess
import sys
import tempfile
import time
from ctypes import wintypes

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
EXE = "users.exe"
TITLE = "用户管理"
MOCK = "http://127.0.0.1:8099/healthz"

user32 = ctypes.WinDLL("user32", use_last_error=True)
gdi32 = ctypes.WinDLL("gdi32", use_last_error=True)


class BITMAPINFOHEADER(ctypes.Structure):
    _fields_ = [
        ("biSize", wintypes.DWORD),
        ("biWidth", wintypes.LONG),
        ("biHeight", wintypes.LONG),
        ("biPlanes", wintypes.WORD),
        ("biBitCount", wintypes.WORD),
        ("biCompression", wintypes.DWORD),
        ("biSizeImage", wintypes.DWORD),
        ("biXPelsPerMeter", wintypes.LONG),
        ("biYPelsPerMeter", wintypes.LONG),
        ("biClrUsed", wintypes.DWORD),
        ("biClrImportant", wintypes.DWORD),
    ]


def proc_count() -> int:
    """同名进程数（`tasklist` 的 CSV 输出，行数 - 1 个表头）。"""
    out = subprocess.run(
        ["tasklist", "/FI", f"IMAGENAME eq {EXE}", "/FO", "CSV", "/NH"],
        capture_output=True,
        text=True,
        errors="ignore",
    ).stdout
    return sum(1 for line in out.splitlines() if line.lower().startswith(f'"{EXE.lower()}"'))


def grab(hwnd, w, h) -> bytes:
    hdc = user32.GetDC(hwnd)
    mem = gdi32.CreateCompatibleDC(hdc)
    bmp = gdi32.CreateCompatibleBitmap(hdc, w, h)
    gdi32.SelectObject(mem, bmp)
    gdi32.BitBlt(mem, 0, 0, w, h, hdc, 0, 0, 0x00CC0020)

    bi = BITMAPINFOHEADER()
    bi.biSize = ctypes.sizeof(BITMAPINFOHEADER)
    bi.biWidth = w
    bi.biHeight = -h
    bi.biPlanes = 1
    bi.biBitCount = 32

    buf = ctypes.create_string_buffer(w * h * 4)
    gdi32.GetDIBits(mem, bmp, 0, h, buf, ctypes.byref(bi), 0)
    gdi32.DeleteObject(bmp)
    gdi32.DeleteDC(mem)
    user32.ReleaseDC(hwnd, hdc)
    return buf.raw


def non_white_ratio(data: bytes) -> float:
    total = len(data) // 4
    n = 0
    for i in range(0, len(data), 4):
        if data[i] < 245 or data[i + 1] < 245 or data[i + 2] < 245:
            n += 1
    return n / total if total else 0.0


def ink_in_rows(data: bytes, w: int, h: int, y0: int, y1: int) -> float:
    """只量 y0..y1 这几行的墨量。

    ★ 为什么必须分区量（2026-10-18 的假绿教训）：**错误态**（"加载失败" + "重试"
      两个控件）和 **Ready 态**（15 行列表）在整窗口径下都是 ~8.6% —— 顶部导航、
      工具条、卡片边框占了大头，列表本身那点文字淹在里面。整窗阈值 8% 于是
      对"取数失败"也成立，判据形同虚设。只量列表区才分得开。
    """
    total = 0
    n = 0
    for y in range(y0, min(y1, h)):
        row = y * w * 4
        for i in range(row, row + w * 4, 4):
            total += 1
            if data[i] < 245 or data[i + 1] < 245 or data[i + 2] < 245:
                n += 1
    return n / total if total else 0.0


def main() -> int:
    # ① mock 必须在跑（后台取数请求它）
    try:
        import urllib.request

        urllib.request.urlopen(MOCK, timeout=3).read()
    except Exception as exc:  # noqa: BLE001
        print(f"✗ mock 后端不可达（{MOCK}）：{exc}")
        print("  先跑：php -S 127.0.0.1:8099 tools/app-workers/users-mock.php")
        return 2

    exe = os.path.join(ROOT, EXE)
    if not os.path.isfile(exe):
        print(f"✗ 找不到 {exe} —— 先跑 `php tools/drift-build.php users`")
        return 2

    # ★★ **不能只写 `opcache.enable=0`**（2026-10-18 实测的假绿根因）：
    #   `PHPRC` 会**覆盖** exe 同目录的 `php.ini` —— 那份是 `drift-build` 写的，
    #   含 `extension=mbstring/openssl/curl`。临时 ini 只写 opcache 的话，
    #   **worker 子进程**（同一 exe + `--drift-worker`）就没有 curl ⇒ `curl_init()`
    #   未定义 ⇒ worker Fatal 退出 ⇒ 界面停在"加载失败"。
    #   ⇒ 以 exe 同目录那份为底，只追加 opcache 关掉。
    ini_dir = tempfile.mkdtemp(prefix="drift-run-")
    ini = os.path.join(ini_dir, "php.ini")
    base = os.path.join(ROOT, "php.ini")
    lines: list[str] = []
    if os.path.isfile(base):
        with open(base, "r", encoding="utf-8", errors="ignore") as fh:
            lines = [ln.rstrip("\r\n") for ln in fh if ln.strip()]
    else:
        print(f"⚠ 没有 {base}（drift-build 的运行时 ini）—— worker 可能缺扩展")
    lines.append("opcache.enable=0")
    with open(ini, "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines) + "\n")

    env = dict(os.environ)
    env["PHPRC"] = ini

    proc = subprocess.Popen([exe], cwd=ROOT, env=env)

    # ★ 窗口一出现就截一张（此刻几乎必然还在 Loading —— mock 要 1.2s），
    #   再等它加载完截第二张。**两张的墨量差**才是"数据后到"的直接证据：
    #   只截最终态的话，"一开始就有内容"和"后来才有内容"长得一样。
    hwnd = 0
    deadline = time.time() + 8.0
    while time.time() < deadline and not hwnd:
        hwnd = user32.FindWindowW(None, TITLE)
        time.sleep(0.05)
    if not hwnd:
        print(f"✗ 没找到窗口（标题应为 {TITLE!r}），进程存活={proc.poll() is None}")
        proc.terminate()
        return 2

    rect = wintypes.RECT()
    user32.GetClientRect(hwnd, ctypes.byref(rect))
    w, h = rect.right - rect.left, rect.bottom - rect.top

    # 列表区起点（逻辑像素 220 起）：工具条/搜索框在上，15 行列表在下。
    LIST_Y0, LIST_Y1 = 220, 640

    raw_early = grab(hwnd, w, h)
    early = non_white_ratio(raw_early)
    early_list = ink_in_rows(raw_early, w, h, LIST_Y0, LIST_Y1)

    time.sleep(2.5)

    raw_late = grab(hwnd, w, h)
    late = non_white_ratio(raw_late)
    late_list = ink_in_rows(raw_late, w, h, LIST_Y0, LIST_Y1)

    # ② 进程数：主 + worker
    workers = proc_count()

    print(f"client = {w} x {h}")
    print(f"进程数 = {workers}（期望 2 = 主 + --drift-worker）")
    print(f"Loading 时墨量 = {early * 100:.1f}%  列表区 = {early_list * 100:.1f}%")
    print(f"Ready   时墨量 = {late * 100:.1f}%  列表区 = {late_list * 100:.1f}%")
    print(f"列表区墨量差 = {(late_list - early_list) * 100:+.1f} 个百分点（期望 > 0：数据是**后到**的）")

    # ★ 判据只认**列表区**：整窗墨量对"加载失败"也成立（见 ink_in_rows 的注释）。
    ok = (
        workers >= 2
        and late_list >= 0.04          # 15 行文字真的画出来了
        and late_list > early_list     # 且是**后到**的（不是一开始就有）
        and w == 900
        and h == 640
    )

    # ④ 关窗 ⇒ worker 跟着退
    user32.PostMessageW(hwnd, 0x0010, 0, 0)  # WM_CLOSE
    time.sleep(1.5)
    if proc.poll() is None:
        proc.terminate()
    left = proc_count()
    print(f"关窗后残留进程 = {left}（期望 0）")
    ok = ok and left == 0

    print("OK" if ok else "FAIL")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
