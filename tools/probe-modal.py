"""真机验收：Modal 关闭按钮（X）——开模态 → 面板右上角有 X → 点 X → 关得上。

    python tools/probe-modal.py

判据（六条，缺一即 FAIL）：
  ① 点「打开对话框」⇒ 遮罩生效：面板外一块画布亮度降 ≥ 15%（黑 35% 遮罩）
  ② 屏幕中央出现近白大面板（Surface #FFFFFF，不是画布 #E9EBEF）
  ③ 面板右上角色块里有深色墨迹（X 图标）—— 反例对照见 ⑥
  ④ 点 X ⇒ 模态真的关了：遮罩区亮度恢复、中央大白面板消失
  ⑤ 「打开不可关闭的对话框」⇒ 有遮罩有面板，但右上角**没有** X
  ⑥ （⑥ 的反面即 ③：同一个右上角色块，dismissible(false) 时必须是空的）

★ 为什么滚轮：Feedback 页是一整块滚动卡片，「打开对话框」在页面底部，
  首屏看不见 ⇒ 在客户区中央发 WM_MOUSEWHEEL 把它滚进来。
★ 为什么杀进程收尾：`dismissible(false)` 的模态三条关闭路全被吞
  （X 不画 / ESC 吞 / 遮罩吞），面板里的「知道了」也没接线
  （§A.3.7：关不关归应用）⇒ 验证完只能 terminate。
★ PHPRC 必须指向 opcache.enable=0 的 ini（ASLR 下 opcache 拒绝启动），
  与 tools/probe-window.py 同规矩。
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

WM_LBUTTONDOWN = 0x0201
WM_LBUTTONUP = 0x0202
WM_MOUSEWHEEL = 0x020A
WHEEL_DELTA = 120
WM_CLOSE = 0x0010

ACCENT = (0x09, 0x69, 0xDA)   # 框架默认 Primary 实底（light）—— 示例台不用它！
PRIMARY = (0x00, 0x96, 0x88)  # 示例台 ThemeScope 覆盖后的 Accent（墨绿，LayUI 风）
SEL = (0x00, 0x96, 0x88)      # 导航选中带（NavButton 选中态，实测 #009688）
INK_DARK = 150                # 「深色墨迹」门槛（X 图标 / 文字）

failures = 0


def check(name, ok, detail=""):
    global failures
    if ok:
        print(f"  ok    {name}")
    else:
        failures += 1
        print(f"  FAIL  {name}" + (f"  —— {detail}" if detail else ""))


class BITMAPINFOHEADER(ctypes.Structure):
    _fields_ = [
        ("biSize", wintypes.DWORD), ("biWidth", wintypes.LONG),
        ("biHeight", wintypes.LONG), ("biPlanes", wintypes.WORD),
        ("biBitCount", wintypes.WORD), ("biCompression", wintypes.DWORD),
        ("biSizeImage", wintypes.DWORD), ("biXPelsPerMeter", wintypes.LONG),
        ("biYPelsPerMeter", wintypes.LONG), ("biClrUsed", wintypes.DWORD),
        ("biClrImportant", wintypes.DWORD),
    ]


class Grab:
    """客户区位图抓取（自顶向下 32bpp，行主序，每像素 BGRA）。"""

    def __init__(self, hwnd):
        self.hwnd = hwnd
        self.hdc = user32.GetDC(hwnd)
        self.mem = gdi32.CreateCompatibleDC(self.hdc)
        self.bmp = gdi32.CreateCompatibleBitmap(self.hdc, 1, 1)

    def shot(self):
        rect = wintypes.RECT()
        user32.GetClientRect(self.hwnd, ctypes.byref(rect))
        w, h = rect.right - rect.left, rect.bottom - rect.top
        bmp = gdi32.CreateCompatibleBitmap(self.hdc, w, h)
        gdi32.SelectObject(self.mem, bmp)
        gdi32.BitBlt(self.mem, 0, 0, w, h, self.hdc, 0, 0, 0x00CC0020)
        bi = BITMAPINFOHEADER()
        bi.biSize = ctypes.sizeof(BITMAPINFOHEADER)
        bi.biWidth = w
        bi.biHeight = -h
        bi.biPlanes = 1
        bi.biBitCount = 32
        buf = ctypes.create_string_buffer(w * h * 4)
        gdi32.GetDIBits(self.mem, bmp, 0, h, buf, ctypes.byref(bi), 0)
        gdi32.DeleteObject(bmp)
        return w, h, buf.raw


def px(data, w, x, y):
    i = (y * w + x) * 4
    return data[i + 2], data[i + 1], data[i]   # BGRX → RGB


def near(a, b, tol=14):
    return all(abs(a[i] - b[i]) <= tol for i in range(3))


def mean_luma(data, w, x0, y0, x1, y1):
    """区域平均亮度（0-255）。"""
    n = s = 0
    for y in range(max(0, y0), min(y1, 10 ** 9)):
        for x in range(max(0, x0), min(x1, 10 ** 9)):
            r, g, b = px(data, w, x, y)
            s += (r + g + b) // 3
            n += 1
    return s / n if n else 0.0


def find_blobs(data, w, h, target, tol=16, min_area=300):
    """目标色连通块（行扫描 + 跨行按 x 重叠合并）⇒ [(x0,y0,x1,y1,area)]。"""
    rows = []
    for y in range(h):
        segs = []
        x = 0
        while x < w:
            if near(px(data, w, x, y), target, tol):
                x0 = x
                while x < w and near(px(data, w, x, y), target, tol):
                    x += 1
                segs.append((x0, x - 1))
            else:
                x += 1
        rows.append(segs)

    blobs = []
    for y in range(h):
        for (x0, x1) in rows[y]:
            hit = None
            for b in blobs:
                # 与上一批同 y 邻域的块按 x 重叠合并
                if b["y1"] >= y - 2 and not (x1 < b["x0"] - 2 or x0 > b["x1"] + 2):
                    hit = b
                    break
            if hit is None:
                blobs.append({"x0": x0, "y0": y, "x1": x1, "y1": y, "area": x1 - x0 + 1})
            else:
                hit["x0"] = min(hit["x0"], x0)
                hit["x1"] = max(hit["x1"], x1)
                hit["y1"] = y
                hit["area"] += x1 - x0 + 1
    out = [(b["x0"], b["y0"], b["x1"], b["y1"], b["area"]) for b in blobs if b["area"] >= min_area]
    return out


def find_panel(data, w, h):
    """中央近白大面板：Screen 中部 60% 区域里近白像素的包围盒（None = 没面板）。"""
    x0, x1 = int(w * 0.2), int(w * 0.8)
    y0, y1 = int(h * 0.15), int(h * 0.85)
    minx, miny, maxx, maxy, cnt = 10 ** 9, 10 ** 9, -1, -1, 0
    for y in range(y0, y1):
        for x in range(x0, x1):
            r, g, b = px(data, w, x, y)
            if r >= 248 and g >= 248 and b >= 248:
                cnt += 1
                minx, miny = min(minx, x), min(miny, y)
                maxx, maxy = max(maxx, x), max(maxy, y)
    if cnt < 40000:      # 面板至少 ~200×200，画布 #E9EBEF(233,235,239) 不算近白
        return None
    return (minx, miny, maxx, maxy, cnt)


def ink_in(data, w, x0, y0, x1, y1):
    """区域里深色墨迹像素的质心与数量。"""
    sx = sy = n = 0
    for y in range(max(0, y0), min(y1, 10 ** 9)):
        for x in range(max(0, x0), min(x1, 10 ** 9)):
            r, g, b = px(data, w, x, y)
            if (r + g + b) // 3 < INK_DARK:
                sx += x
                sy += y
                n += 1
    return (sx // n, sy // n, n) if n else (None, None, 0)


def diff_ratio(a, b, w, box):
    """两幅位图在同一区域的像素差异率（容差 8/255，抗锯齿边不算）。"""
    x0, y0, x1, y1 = box
    n = t = 0
    for y in range(max(0, y0), min(y1, 10 ** 9)):
        for x in range(max(0, x0), min(x1, 10 ** 9)):
            pa, pb = px(a, w, x, y), px(b, w, x, y)
            if any(abs(pa[i] - pb[i]) > 8 for i in range(3)):
                n += 1
            t += 1
    return n / t if t else 0.0


def post(hwnd, msg, wparam, lparam):
    user32.PostMessageW(hwnd, msg, wparam, lparam)


def click(hwnd, x, y):
    post(hwnd, WM_LBUTTONDOWN, 0x0001, (y << 16) | (x & 0xFFFF))
    time.sleep(0.05)
    post(hwnd, WM_LBUTTONUP, 0x0000, (y << 16) | (x & 0xFFFF))


def wheel(hwnd, x, y, delta):
    # ★ lParam 必须是**屏幕**坐标（WndProc 会 ScreenToClient 拿回客户区）——
    #   给客户区坐标 ⇒ 命中测试解析错位置，滚轮被路由丢（第一版就这样，页面纹丝不动）。
    pt = wintypes.POINT(x, y)
    user32.ClientToScreen(hwnd, ctypes.byref(pt))
    lparam = (pt.y << 16) | (pt.x & 0xFFFF)
    post(hwnd, WM_MOUSEWHEEL, (delta << 16) & 0xFFFFFFFF, lparam)


def main():
    exe = os.path.join(ROOT, "showcase.exe")
    if not os.path.isfile(exe):
        print("✗ 找不到 showcase.exe —— 先跑 `php tools/drift-build.php showcase`")
        return 2

    ini_dir = tempfile.mkdtemp(prefix="drift-run-")
    ini = os.path.join(ini_dir, "php.ini")
    with open(ini, "w", encoding="ascii") as fh:
        fh.write("opcache.enable=0\n")
    env = dict(os.environ)
    env["PHPRC"] = ini

    proc = subprocess.Popen([exe], cwd=ROOT, env=env)
    time.sleep(2.5)

    hwnd = user32.FindWindowW(None, "Drift 示例台")
    print(f"hwnd = {hwnd}  (0 = 没找到窗口)")
    if not hwnd:
        proc.terminate()
        return 2

    g = Grab(hwnd)
    w, h, data = g.shot()
    print(f"client = {w} x {h}")
    time.sleep(0.3)

    # ── ① 导航到 Feedback ──
    #    ★ 选中带是 #009688（NavButton 选中态），不是 Primary 蓝 —— 拿 Accent 扫
    #      会一条都找不到（第一版就这样，假红）。
    #    ★ 坐标必须重新量：W1 时 Feedback(7) 中心 313（Layout(0)=69、pitch 35）；
    #      2026-10-25 AppBar 加了右侧动作位后顶条变高 ⇒ 整列下移 21px，
    #      现测 Layout(0)=90、pitch≈36 ⇒ Feedback(7) = 342。
    nav_x, nav_y = 25, 342
    click(hwnd, nav_x, nav_y)
    time.sleep(0.7)
    w, h, data = g.shot()
    # 自证点击生效：x=25 列的选中带应随点击移动（落在点击行 ±18px 内）
    band = []
    y = 0
    while y < h:
        if near(px(data, w, nav_x, y), SEL, 40):
            y0 = y
            while y < h and near(px(data, w, nav_x, y), SEL, 40):
                y += 1
            if y - y0 >= 8:
                band.append((y0 + y - 1) // 2)
        else:
            y += 1
    check("导航 · 点 (25,342) 后选中带移到该行（Feedback 页到位）",
          len(band) == 1 and abs(band[0] - nav_y) <= 18,
          f"band = {band}")

    # ── ② 滚轮把「打开对话框」滚进视野（页面底部）──
    #    特征：Primary 实底块（示例台覆盖 Accent ⇒ **墨绿** #009688）。
    #    取所有块里**最靠下**的（模态小节在页尾）；tab 切换条的选中态钮
    #    （同色）在页顶，滚动后 y 更小，不会混淆。
    btn = None
    cx, cy = w // 2, h // 2
    for i in range(24):
        wheel(hwnd, cx, cy, -WHEEL_DELTA)
        time.sleep(0.12)
        if i % 2 == 1 or i >= 20:
            w, h, data = g.shot()
            blobs = [b for b in find_blobs(data, w, h, PRIMARY) if b[4] >= 1500]
            if blobs:
                low = max(b[3] for b in blobs)
                cand = [b for b in blobs if b[3] >= low - 4]
                btn = min(cand, key=lambda b: b[0])   # 同排取最左 = 「打开对话框」
                print(f"  [滚轮 {i + 1} 格] 墨绿块 {len(blobs)} 个，取最底排最左：{btn[:4]}")
                break
    if btn is None:
        w, h, data = g.shot()
        blobs = find_blobs(data, w, h, PRIMARY)
        check("滚轮 · 「打开对话框」按钮滚进视野", False,
              f"滚了 24 格仍只有 {len(blobs)} 个墨绿块（页面可能没溢出 / 滚轮没到）")
        proc.terminate()
        return 1
    bx0, by0, bx1, by1, _ = btn
    btn_cx, btn_cy = (bx0 + bx1) // 2, (by0 + by1) // 2

    # ── ③ 点开模态前的基线（面板外画布亮度）──
    #    取样区放在右下角远离面板处；面板约 400×250 居中，动不到这块。
    sx0, sy0, sx1, sy1 = w - 160, h - 160, w - 40, h - 40
    luma_before = mean_luma(data, w, sx0, sy0, sx1, sy1)

    # ── ④ 点「打开对话框」⇒ 模态开 ──
    click(hwnd, btn_cx, btn_cy)
    time.sleep(0.8)
    w, h, data = g.shot()

    luma_after = mean_luma(data, w, sx0, sy0, sx1, sy1)
    drop = (luma_before - luma_after) / luma_before * 100.0
    check("模态 · 遮罩生效（面板外画布变暗 ≥15%）", drop >= 15.0,
          f"亮度 {luma_before:.0f} → {luma_after:.0f}（−{drop:.1f}%）")

    panel = find_panel(data, w, h)
    check("模态 · 屏幕中央出现近白大面板（Surface）", panel is not None,
          "中央区域近白像素不足（面板没出来或底色不对）")
    if panel is None:
        proc.terminate()
        return 1
    px0, py0, px1, py1, pcnt = panel
    print(f"  面板 bbox = ({px0},{py0})-({px1},{py1})  近白 {pcnt} px")

    # ── ⑤ 面板右上角应有 X 墨迹 ──
    #    X 是 Flat IconButton（无 hover 圆底 ⇒ 纯墨迹）。命中盒约 32×32 贴在右上角。
    qx0, qy0, qx1, qy1 = px1 - 44, py0 + 6, px1 - 6, py0 + 44
    xx, xy, xn = ink_in(data, w, qx0, qy0, qx1, qy1)
    check("模态 · 面板右上角有 X 墨迹（关闭按钮画出来了）", xn >= 12,
          f"角色块 ({qx0},{qy0})-({qx1},{qy1}) 墨迹 {xn} px")

    # ── ⑥ 点 X ⇒ 模态真的关了 ──
    if xn >= 12:
        click(hwnd, xx, xy)
        time.sleep(0.8)
        w, h, data2 = g.shot()
        luma_closed = mean_luma(data2, w, sx0, sy0, sx1, sy1)
        recover = abs(luma_closed - luma_before) / luma_before * 100.0
        check("关闭 · 点 X 后遮罩消失（画布亮度恢复基线 ±6%）", recover <= 6.0,
              f"亮度 {luma_before:.0f} → {luma_closed:.0f}（差 {recover:.1f}%）")
        # ★ 不能判"中央没有近白"：Feedback 页本身就是一整块白卡片（Card Surface），
        #   关掉模态后它照样占满中央、同位置也照样大多近白（实测 0.90 → 0.83，
        #   占比差判据分不开）⇒ 判据换成"开/关两图在面板 bbox 上的**像素差异率**"：
        #   模态面板是居中的圆角白块，关掉后那里换成页面内容（文字/按钮/边框），
        #   逐像素对不上。
        box = (px0, py0, px1, py1)
        dr = diff_ratio(data, data2, w, box)
        check("关闭 · 点 X 后原面板位置内容变了（模态面板真的撤了）", dr >= 0.06,
              f"bbox 像素差异率 {dr:.3f}")
    else:
        check("关闭 · 点 X 后遮罩消失（画布亮度恢复基线 ±6%）", False, "上文没有 X 可点")
        check("关闭 · 点 X 后原面板位置内容变了（模态面板真的撤了）", False, "上文没有 X 可点")

    # ── ⑦ 反例：dismissible(false) ⇒ 有遮罩有面板、无 X ──
    #    「打开不可关闭的对话框」是 Default 灰钮，在刚才那个墨绿钮右侧同一排。
    #    重新截屏找墨绿钮：灰钮底色 = Hover/Surface 一档，直接用墨绿钮的行范围 + 右侧搜索。
    w, h, data = g.shot()
    blobs = [b for b in find_blobs(data, w, h, PRIMARY) if b[4] >= 1500]
    if not blobs:
        check("反例 · 找到「打开对话框」入口（重开用）", False, "按钮不见了（滚动状态丢了？）")
        proc.terminate()
        return 1
    low = max(b[3] for b in blobs)
    cand = [b for b in blobs if b[3] >= low - 4]
    blue = min(cand, key=lambda b: b[0])
    gx0, gy0, gx1, gy1 = blue[0], blue[1], blue[2], blue[3]
    gray_cx = gx1 + 130      # 「打开不可关闭的对话框」在右侧，估中心（宽约 180）
    gray_cy = (gy0 + gy1) // 2
    click(hwnd, gray_cx, gray_cy)
    time.sleep(0.8)
    w, h, data = g.shot()
    luma_stub = mean_luma(data, w, sx0, sy0, sx1, sy1)
    drop2 = (luma_before - luma_stub) / luma_before * 100.0
    check("反例 · dismissible(false) 也有遮罩（照样拦层外点击）", drop2 >= 15.0,
          f"亮度 {luma_before:.0f} → {luma_stub:.0f}（−{drop2:.1f}%）")
    panel3 = find_panel(data, w, h)
    check("反例 · 有面板（「必须处理」框在屏幕上）", panel3 is not None)
    if panel3 is not None:
        qx0, qy0, qx1, qy1 = panel3[2] - 44, panel3[1] + 6, panel3[2] - 6, panel3[1] + 44
        _, _, xn3 = ink_in(data, w, qx0, qy0, qx1, qy1)
        check("反例 · 面板右上角**没有** X（画个点了没反应的 X 比没有更糟）", xn3 < 12,
              f"角色块墨迹 {xn3} px")

    # 收尾：这个模态三条关闭路全被吞 ⇒ 只能 terminate（见模块 docstring）
    proc.terminate()
    try:
        proc.wait(timeout=3)
    except Exception:   # noqa: BLE001
        proc.kill()

    print("--- probe-modal: %d FAIL ---" % failures)
    return 0 if failures == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
