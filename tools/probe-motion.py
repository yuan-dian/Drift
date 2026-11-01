"""真机验收：悬停浮起（盒外阴影）与滚动条空闲淡出。

两件事都是"状态变化有一条斜坡"，而 smoke 用的是**解释器里的合成盒** —— 它证明
不了 AOT 产物里帧时钟真的会为盒级动画上膛（`Application::wantsFrame()` 的第四
判据来自 `tickSubtree`，那条路径只有真机跑得到）。

判据设计的两条纪律（沿用 `probe-modal.py`）：
- 只判"**某个位置的内容变了**"，不判"这类像素没有了"；
- 阴影必须在**盒外**数像素：盒内数到的是状态色，两者在盒内无法区分。
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

WM_MOUSEMOVE = 0x0200
WM_LBUTTONDOWN = 0x0201
WM_LBUTTONUP = 0x0202
WM_MOUSEWHEEL = 0x020A

# showcase 的 ThemeScope 把 Accent 覆盖成墨绿（LayUI 风）—— Primary 实心按钮就是这个色
PRIMARY = (0x00, 0x96, 0x88)

FAILS = []


def check(name, ok, detail=""):
    print(f"  {'ok  ' if ok else 'FAIL'}  {name}" + (f"  —— {detail}" if detail else ""))
    if not ok:
        FAILS.append(name)


class BITMAPINFOHEADER(ctypes.Structure):
    _fields_ = [
        ("biSize", wintypes.DWORD), ("biWidth", wintypes.LONG), ("biHeight", wintypes.LONG),
        ("biPlanes", wintypes.WORD), ("biBitCount", wintypes.WORD), ("biCompression", wintypes.DWORD),
        ("biSizeImage", wintypes.DWORD), ("biXPelsPerMeter", wintypes.LONG), ("biYPelsPerMeter", wintypes.LONG),
        ("biClrUsed", wintypes.DWORD), ("biClrImportant", wintypes.DWORD),
    ]


def grab(hwnd):
    """客户区截图（top-down DIB，32bpp）。"""
    rect = wintypes.RECT()
    user32.GetClientRect(hwnd, ctypes.byref(rect))
    w, h = rect.right - rect.left, rect.bottom - rect.top
    hdc = user32.GetDC(hwnd)
    mem = gdi32.CreateCompatibleDC(hdc)
    bmp = gdi32.CreateCompatibleBitmap(hdc, w, h)
    gdi32.SelectObject(mem, bmp)
    gdi32.BitBlt(mem, 0, 0, w, h, hdc, 0, 0, 0x00CC0020)
    bi = BITMAPINFOHEADER()
    bi.biSize = ctypes.sizeof(BITMAPINFOHEADER)
    bi.biWidth, bi.biHeight = w, -h
    bi.biPlanes, bi.biBitCount = 1, 32
    buf = ctypes.create_string_buffer(w * h * 4)
    gdi32.GetDIBits(mem, bmp, 0, h, buf, ctypes.byref(bi), 0)
    gdi32.DeleteObject(bmp)
    gdi32.DeleteDC(mem)
    user32.ReleaseDC(hwnd, hdc)
    return w, h, buf.raw


def px(data, w, x, y):
    i = (y * w + x) * 4
    return data[i + 2], data[i + 1], data[i]


def near(c, ref, tol):
    return all(abs(c[i] - ref[i]) <= tol for i in range(3))


def post(hwnd, msg, wparam, lparam):
    user32.PostMessageW(hwnd, msg, wparam, lparam)


def focus_window(hwnd):
    """把窗口顶到前台，**并确认真的到了**。

    ★★ 真实点击只送到前台窗口。`SetForegroundWindow` 对"非前台进程"可能被系统
    拒绝（返回 0 也不报错）⇒ 后续 `mouse_event` 落在**别的窗口**上 ⇒ 导航没切页，
    而探针照旧去截当前页面 ⇒ 报"一个像素都不变"（2026-11-01 第二次假红：
    `blocks` 从上一次的 3 个变成 2 个，目标 bbox 整个换了地方）。
    """
    user32.ShowWindow(hwnd, 9)   # SW_RESTORE

    # ★ `SetForegroundWindow` 对"非前台进程"会被系统拒绝且**不报错**。解锁办法是
    #   把自己的输入队列临时挂到前台窗口的线程上（`AttachThreadInput`），切完再摘。
    kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
    fg = user32.GetForegroundWindow()
    if fg == hwnd:
        return True

    cur = kernel32.GetCurrentThreadId()
    target = user32.GetWindowThreadProcessId(fg, None) if fg else 0
    attached = False
    if target and target != cur:
        attached = bool(user32.AttachThreadInput(cur, target, True))

    try:
        for _ in range(40):
            user32.BringWindowToTop(hwnd)
            user32.SetForegroundWindow(hwnd)
            if user32.GetForegroundWindow() == hwnd:
                return True
            time.sleep(0.05)
    finally:
        if attached:
            user32.AttachThreadInput(cur, target, False)

    return False


def to_screen(hwnd, x, y):
    """客户区坐标 ⇒ 真实光标位置。"""
    pt = wintypes.POINT(x, y)
    user32.ClientToScreen(hwnd, ctypes.byref(pt))
    user32.SetCursorPos(pt.x, pt.y)


def click(hwnd, x, y):
    """真实点击（理由同 `move()`）。"""
    to_screen(hwnd, x, y)
    time.sleep(0.05)
    user32.mouse_event(0x0002, 0, 0, 0, 0)   # LEFTDOWN
    time.sleep(0.05)
    user32.mouse_event(0x0004, 0, 0, 0, 0)   # LEFTUP


def move(hwnd, x, y):
    """**真实**移动系统光标（不是伪造 `WM_MOUSEMOVE`）。

    ★★ 为什么必须是真的（2026-11-01 定位）：`PostMessage(WM_MOUSEMOVE)` 只让应用
    **知道**指针在哪，而 `TrackMouseEvent(TME_LEAVE)` 依据的是**真实光标位置**。
    两条信息不一致时，应用照常把悬停态推到盒上、`tickAnimations()` 也把进度从
    0 推到 1（trace 里数到 43 帧），但 `paint` 记录里从来没有那个盒 —— 于是本
    探针连报"一个像素都不变"，而**真实鼠标移上去是正常的**（`tools/probe-hover-
    screen.py` 用真实光标 + 屏幕级截图实测：盒内 1813 px、环带 2859 px 变化）。
    换言之，之前那份"悬停不出画面"的红，有一半是探针自己的输入通道造成的。
    """
    to_screen(hwnd, x, y)


def wheel(hwnd, x, y, delta):
    """真实滚轮（理由同 `move()`）。

    ★ 伪造的 `WM_MOUSEWHEEL` 走不到 `ScrollBarFade::noteScroll()` ⇒ 滚动条**从来
    没开始计算空闲**，而画面看起来"滚过了"（`x=1398` 那一列本来就画着）。
    """
    to_screen(hwnd, x, y)
    time.sleep(0.05)
    user32.mouse_event(0x0800, 0, 0, delta, 0)   # MOUSEEVENTF_WHEEL


def primary_blocks(data, w, h, x_min, tol=26):
    """把 Accent 像素聚成块，返回 [(x0, y0, x1, y1), ...]。

    ★ 不能用全图 bbox：左侧导航的选中带是同一个 Accent，且一个页面里往往有
      好几个墨绿物件（按钮 / 标题 / 徽标）⇒ bbox 会把它们连成一大片，中心落空。
    ★★ 也不能只按"行"取 min/max（第一版就这么写，2026-11-01 修）：同一行里
      **两个并排的按钮**会被合成一个块，块中心正好落在两者的**空隙**上 ⇒ 悬停
      测试点到了一个什么都没有的位置，判据永远红（而它看起来像"悬停坏了"）。
      正确做法：先把每行的 Accent 像素切成**连续段**（间距 > 6px 就断开），
      再按 x 有重叠把相邻行的段接成块。断段阈值取 45px：按钮**内部**的
      标签文字会把连续段打断（十来像素），而两个并排按钮之间是几十像素的空白
      —— 阈值必须落在两者中间，否则要么把按钮切碎、要么把两个按钮粘成一个。
    """
    blocks = []        # 每个块是可变 list，随扫描继续扩
    open_blocks = []   # 上一行还活着的块（本行有重叠段就续上）

    for y in range(0, h, 2):
        xs = [x for x in range(x_min, w, 2) if near(px(data, w, x, y), PRIMARY, tol)]
        if not xs:
            open_blocks = []
            continue

        runs = []
        start = prev = xs[0]
        for x in xs[1:]:
            if x - prev > 45:
                runs.append((start, prev))
                start = x
            prev = x
        runs.append((start, prev))
        runs = [r for r in runs if r[1] - r[0] >= 20]

        next_open = []
        for r in runs:
            hit = None
            for b in open_blocks:
                if not (r[0] > b[2] + 4 or r[1] < b[0] - 4):
                    hit = b
                    break
            if hit is not None:
                open_blocks.remove(hit)
                hit[0] = min(hit[0], r[0])
                hit[2] = max(hit[2], r[1])
                hit[3] = y
            else:
                hit = [r[0], y, r[1], y]
                blocks.append(hit)
            next_open.append(hit)
        open_blocks = next_open

    return [tuple(b) for b in blocks]


def ring_diff(data_a, data_b, w, box, pad_from, pad_to):
    """盒矩形从 pad_from 扩到 pad_to 那一圈里，两张图有多少像素不同。"""
    x0, y0, x1, y1 = box
    n = 0
    for y in range(max(0, y0 - pad_to), min(10 ** 9, y1 + pad_to + 1)):
        for x in range(max(0, x0 - pad_to), min(10 ** 9, x1 + pad_to + 1)):
            inside_inner = x0 - pad_from <= x <= x1 + pad_from and y0 - pad_from <= y <= y1 + pad_from
            if inside_inner:
                continue
            if px(data_a, w, x, y) != px(data_b, w, x, y):
                n += 1
    return n


def main():
    ini_dir = tempfile.mkdtemp(prefix="drift-run-")
    ini = os.path.join(ini_dir, "php.ini")
    with open(ini, "w", encoding="utf-8") as f:
        f.write("opcache.enable=0\n")
    env = dict(os.environ)
    env["PHPRC"] = ini

    exe = os.path.join(ROOT, "showcase.exe")
    if not os.path.exists(exe):
        print("showcase.exe 不存在：先跑 php tools/drift-build.php showcase")
        return 2

    proc = subprocess.Popen([exe], cwd=ROOT, env=env)
    try:
        time.sleep(2.5)
        hwnd = user32.FindWindowW(None, "Drift 示例台")
        if not hwnd:
            print("找不到窗口「Drift 示例台」")
            return 2

        # ★ 真实输入（`SetCursorPos` / `mouse_event`）只送到**前台**窗口
        if not focus_window(hwnd):
            print("窗口抢不到前台 —— 真实点击会落到别的窗口上，先别测")
            return 2

        w, h, data = grab(hwnd)
        print(f"客户区 {w}x{h}")

        # ── ① 导航到「按钮 Button」（第 3 项：首项中心 90、pitch 36 —— probe-modal 实测）──
        nav_y = 90 + 2 * 36
        click(hwnd, 25, nav_y)
        time.sleep(0.8)
        move(hwnd, w - 40, h - 40)   # 指针先挪到空白，免得开局就悬在按钮上
        time.sleep(0.3)
        w, h, before = grab(hwnd)

        blocks = primary_blocks(before, w, h, 250)
        # 按钮形态：宽 60..300、高 20..60（两个像素步长）
        btn_blocks = [b for b in blocks if 60 <= b[2] - b[0] <= 300 and 16 <= b[3] - b[1] <= 60]
        blob = btn_blocks[0] if btn_blocks else None
        check(
            "按钮页 · 找得到一个 Primary（墨绿实心）按钮块",
            blob is not None,
            f"blocks={blocks[:4]}",
        )
        if blob is None:
            return 1

        cx = (blob[0] + blob[2]) // 2
        cy = (blob[1] + blob[3]) // 2
        print(f"  目标按钮 bbox={blob} 中心=({cx},{cy})")

        # ── ② 悬停 ⇒ 过渡跑完后盒外那一圈出现影子 ──
        move(hwnd, cx, cy)
        time.sleep(0.6)   # 过渡 ~370ms 收口，多等一轮
        w, h, hovered = grab(hwnd)
        ring = ring_diff(before, hovered, w, blob, 2, 10)
        check(
            "悬停浮起 · 收口后盒外 2..10px 那一圈变了（影子落在盒外）",
            ring > 40,
            f"ring diff = {ring} px",
        )

        # ★ 反例：盒**内**的变化不能替代它 —— 状态色也在盒内，两者必须分开数
        inner = 0
        for y in range(blob[1] + 2, blob[3] - 1):
            for x in range(blob[0] + 2, blob[2] - 1):
                if px(before, w, x, y) != px(hovered, w, x, y):
                    inner += 1
        check(
            "悬停浮起 · 盒内同时变了（状态色也参与了，别把两者混成一个判据）",
            inner > 100,
            f"inner diff = {inner} px",
        )

        # ── ③ 移开 ⇒ 回到基线（收口后不该留下半个影子）──
        move(hwnd, w - 40, h - 40)
        time.sleep(0.8)
        w, h, after = grab(hwnd)
        ring_back = ring_diff(before, after, w, blob, 2, 10)
        check(
            "悬停浮起 · 移开后影子收回去（静止态与基线一致，差 < 40px）",
            ring_back < 40,
            f"ring diff = {ring_back} px",
        )

        # ── ④ 滚动条空闲淡出：滚一下全亮，停手 1.6s 后变淡 ──
        #    ★ 必须换到**真的会溢出**的页（按钮页一屏放得下 ⇒ `maxScroll = 0`
        #      ⇒ 滚动条根本不画，第一版就是在按钮页上找它，0 命中）。
        #      「长列表 Big」是第 6 项：90 + 5 × 36 = 270。
        click(hwnd, 25, 90 + 5 * 36)
        time.sleep(0.9)
        move(hwnd, w // 2, h // 2)
        wheel(hwnd, w // 2, h // 2, -120)
        time.sleep(0.25)
        w, h, scrolled = grab(hwnd)

        # ★★ 判据**不能**要求"暗列"（`c[0] < 200`）：本主题的滚动条是**浅灰**
        #   （实测 luma≈239），照旧阈值找永远是"0 命中"，看起来像"滚动条没画"
        #   （2026-11-01 就是这样假红了一轮）。软滚动条只要"比背景深一点"就够 ——
        #   数右带里**非纯白**的像素即可。
        bar_col, bar_count = None, 0
        for x in range(w - 40, w):
            n = 0
            for y in range(60, h - 10, 2):
                c = px(scrolled, w, x, y)
                if max(c) < 250 and max(c) - min(c) < 24:
                    n += 1
            if n > bar_count:
                bar_col, bar_count = x, n
        check(
            "滚动条 · 滚动后右带里找得到那一列（浅灰，不用\"暗列\"判据）",
            bar_col is not None and bar_count >= 40,
            f"x={bar_col} 非白像素={bar_count}",
        )

        # ★ 判据不能用"右带最暗的那一列"：视口左侧还有一条恒定的深灰线（实测
        #   x=1896, luma≈131），它永远比 thumb 暗 ⇒ "最暗列"从头到尾不变（第一版
        #   就这么假红了一次）。正确做法：**先找出两张图之间真的变了的列**，
        #   再看那一列变浅了多少 —— 变化本身就是"哪一列是滚动条"的证据。
        def changed_col(data_a, data_b):
            best = None
            for x in range(w - 30, w, 1):
                before, after = [], []
                for y in range(60, h - 10, 2):
                    ca, cb = px(data_a, w, x, y), px(data_b, w, x, y)
                    if ca != cb:
                        before.append(sum(ca) / 3.0)
                        after.append(sum(cb) / 3.0)
                if len(before) < 6:
                    continue
                ma = sum(before) / len(before)
                mb = sum(after) / len(after)
                if best is None or (mb - ma) > best[1]:
                    best = (x, mb - ma, ma, mb)
            return best

        time.sleep(1.8)   # 无任何输入：这里同时在验"帧时钟会自己上膛"
        w, h, idle = grab(hwnd)
        moved = changed_col(scrolled, idle)
        check(
            "滚动条 · 停手 1.8s 后那一列明显变浅（帧时钟在无输入时把动画推完了）",
            moved is not None and moved[1] > 40.0,
            f"col={None if moved is None else (moved[0], round(moved[2], 1), round(moved[3], 1))}",
        )
        bar_x = moved[0] if moved is not None else None
        live_luma = moved[2] if moved is not None else None

        # ── ⑤ 再滚一下 ⇒ 回到全亮（淡出不是单程票）──
        wheel(hwnd, w // 2, h // 2, -120)
        time.sleep(0.5)
        w, h, again = grab(hwnd)
        if bar_x is not None and live_luma is not None:
            vals = [
                sum(px(again, w, bar_x, y)) / 3.0
                for y in range(60, h - 10, 2)
                if sum(px(again, w, bar_x, y)) / 3.0 < 225
            ]
            back = sum(vals) / len(vals) if vals else None
            check(
                "滚动条 · 再滚一下又亮回来（淡出不是单程票）",
                back is not None and abs(back - live_luma) < 25.0,
                f"luma={None if back is None else round(back, 1)}（全亮时 {round(live_luma, 1)}）",
            )
        else:
            check("滚动条 · 再滚一下又亮回来（淡出不是单程票）", False, '上文没定位到滚动条列')
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except Exception:
            pass

    print()
    if FAILS:
        print(f"--- {len(FAILS)} FAIL ---")
        return 1
    print("--- 0 FAIL ---")
    return 0


if __name__ == "__main__":
    sys.exit(main())
