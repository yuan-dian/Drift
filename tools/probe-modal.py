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
  首屏看不见 ⇒ 在客户区中央真滚 24 格把它滚进来。
★ 为什么杀进程收尾：`dismissible(false)` 的模态三条关闭路全被吞
  （X 不画 / ESC 吞 / 遮罩吞），面板里的「知道了」也没接线
  （§A.3.7：关不关归应用）⇒ 验证完只能 terminate。
★ 启动 / 抢前台 / 真点击 / 真滚轮 / 截图全部走 `tools/probe_win.py`。
  **本文件原来是纯 `PostMessage` 点击**（连光标都不挪）—— 那是已被证伪两次的
  通道：`TME_LEAVE` / `noteScroll()` 认真实光标与真实消息，假消息下应用照常
  推进状态而画面不变 ⇒ 探针报"点了没反应"，产品其实是好的。

★★ 与 smoke 的分工（2026-11-01 重新划线）：
  ①③④ 已在 `tools/smoke.php` 的「模态：点按钮开 → 点 X 关 → …」场景有
  **离线等价断言**（`TestApp::tap()` 找 X、`expectPixel()` 读遮罩合成色
  `#A5A5A5`＝255−90、关后回 `#FFFFFF`）⇒ 这三条不必再开窗。
  ②⑤ 仍只有本探针覆盖（面板尺寸 / `dismissible(false)` 时没有 X）。
  ⇒ 保留本文件作**真机交叉验证**：它跑的是 AOT 后的 exe，验的是"解释器与
    AOT 逐帧一致"，这是 smoke 在结构上做不到的那一半。日常回归不要去跑它。
"""

import sys
import time

from probe_check import check, run, skip
from probe_pixels import near, px
from probe_win import App

ACCENT = (0x09, 0x69, 0xDA)   # 框架默认 Primary 实底（light）—— 示例台不用它！
PRIMARY = (0x00, 0x96, 0x88)  # 示例台 ThemeScope 覆盖后的 Accent（墨绿，LayUI 风）
SEL = (0x00, 0x96, 0x88)      # 导航选中带（NavButton 选中态，实测 #009688）
INK_DARK = 150                # 「深色墨迹」门槛（X 图标 / 文字）


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


def main():
    with App("showcase.exe", "Drift 示例台") as app:
        w, h, data = app.grab()
        print(f"client = {w} x {h}")

        # ── ① 导航到 Feedback ──
        #    ★ 选中带是 #009688（NavButton 选中态），不是 Primary 蓝 —— 拿 Accent 扫
        #      会一条都找不到（第一版就这样，假红）。
        #    ★ 坐标必须重新量：W1 时 Feedback(7) 中心 313（Layout(0)=69、pitch 35）；
        #      2026-10-25 AppBar 加了右侧动作位后顶条变高 ⇒ 整列下移 21px，
        #      现测 Layout(0)=90、pitch≈36 ⇒ Feedback(7) = 342。
        nav_x, nav_y = 25, 342
        app.click(nav_x, nav_y, settle=0.7)
        w, h, data = app.grab()

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
              len(band) == 1 and abs(band[0] - nav_y) <= 18, f"band = {band}")

        # ── ② 滚轮把「打开对话框」滚进视野（页面底部）──
        #    特征：Primary 实底块（示例台覆盖 Accent ⇒ **墨绿** #009688）。
        #    取所有块里**最靠下**的（模态小节在页尾）；tab 切换条的选中态钮
        #    （同色）在页顶，滚动后 y 更小，不会混淆。
        btn = None
        cx, cy = w // 2, h // 2
        for i in range(24):
            app.wheel(cx, cy, 1, settle=0.12)
            if i % 2 == 1 or i >= 20:
                w, h, data = app.grab()
                blobs = [b for b in find_blobs(data, w, h, PRIMARY) if b[4] >= 1500]
                if blobs:
                    low = max(b[3] for b in blobs)
                    cand = [b for b in blobs if b[3] >= low - 4]
                    btn = min(cand, key=lambda b: b[0])   # 同排取最左 = 「打开对话框」
                    print(f"  [滚轮 {i + 1} 格] 墨绿块 {len(blobs)} 个，取最底排最左：{btn[:4]}")
                    break

        check("滚轮 · 「打开对话框」滚进视野", btn is not None)
        if btn is None:
            w, h, data = app.grab()
            blobs = find_blobs(data, w, h, PRIMARY)
            skip(f"滚了 24 格仍只有 {len(blobs)} 个墨绿块（页面没溢出？）—— 下游无意义")

        bx0, by0, bx1, by1, _ = btn
        btn_cx, btn_cy = (bx0 + bx1) // 2, (by0 + by1) // 2

        # ── ③ 点开模态前的基线（面板外画布亮度）──
        #    取样区放在右下角远离面板处；面板约 400×250 居中，动不到这块。
        sx0, sy0, sx1, sy1 = w - 160, h - 160, w - 40, h - 40
        luma_before = mean_luma(data, w, sx0, sy0, sx1, sy1)

        # ── ④ 点「打开对话框」⇒ 模态开 ──
        app.click(btn_cx, btn_cy, settle=0.8)
        w, h, data = app.grab()

        luma_after = mean_luma(data, w, sx0, sy0, sx1, sy1)
        drop = (luma_before - luma_after) / luma_before * 100.0
        check("模态 · 遮罩生效（面板外画布变暗 ≥15%）", drop >= 15.0,
              f"亮度 {luma_before:.0f} → {luma_after:.0f}（−{drop:.1f}%）")

        panel = find_panel(data, w, h)
        # ★ detail 是**无条件打印**的（成功也打）⇒ 它只能写"量到了什么"，不能写
        #   "为什么可能没量到"。第一版这里写的是失败文案，于是通过时屏幕上是
        #   `ok  模态 · …  —— 中央区域近白像素不足（面板没出来或底色不对）`
        #   —— 一条绿判据自带报警文字，下一棒只能重新查一遍。
        check("模态 · 屏幕中央出现近白大面板（Surface）", panel is not None,
              "bbox=({},{})-({},{}) 近白 {} px".format(*panel) if panel is not None
              else "中央近白像素不足 ⇒ 面板没出来，或底色不是 Surface")
        if panel is None:
            skip("面板没出来 —— 后面「右上角有没有 X」无从谈起")

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
            app.click(xx, xy, settle=0.8)
            w, h, data2 = app.grab()
            luma_closed = mean_luma(data2, w, sx0, sy0, sx1, sy1)
            recover = abs(luma_closed - luma_before) / luma_before * 100.0
            check("关闭 · 点 X 后遮罩消失（画布亮度恢复基线 ±6%）", recover <= 6.0,
                  f"亮度 {luma_before:.0f} → {luma_closed:.0f}（差 {recover:.1f}%）")
            # ★ 不能判"中央没有近白"：Feedback 页本身就是一整块白卡片（Card Surface），
            #   关掉模态后它照样占满中央、同位置也照样大多近白（实测 0.90 → 0.83，
            #   占比差判据分不开）⇒ 判据换成"开/关两图在面板 bbox 上的**像素差异率**"：
            #   模态面板是居中的圆角白块，关掉后那里换成页面内容（文字/按钮/边框），
            #   逐像素对不上。
            dr = diff_ratio(data, data2, w, (px0, py0, px1, py1))
            check("关闭 · 点 X 后原面板位置内容变了（模态面板真的撤了）", dr >= 0.06,
                  f"bbox 像素差异率 {dr:.3f}")
        else:
            check("关闭 · 点 X 后遮罩消失（画布亮度恢复基线 ±6%）", False, "上文没有 X 可点")
            check("关闭 · 点 X 后原面板位置内容变了（模态面板真的撤了）", False, "上文没有 X 可点")

        # ── ⑦ 反例：dismissible(false) ⇒ 有遮罩有面板、无 X ──
        #    「打开不可关闭的对话框」是 Default 灰钮，在刚才那个墨绿钮右侧同一排。
        #    重新截屏找墨绿钮：灰钮底色 = Hover/Surface 一档，直接用墨绿钮的行范围 + 右侧搜索。
        w, h, data = app.grab()
        blobs = [b for b in find_blobs(data, w, h, PRIMARY) if b[4] >= 1500]
        if not blobs:
            check("反例 · 找到「打开对话框」入口（重开用）", False, "按钮不见了（滚动状态丢了？）")
            skip("重开用的入口没了 —— 反例两条无意义")

        low = max(b[3] for b in blobs)
        cand = [b for b in blobs if b[3] >= low - 4]
        blue = min(cand, key=lambda b: b[0])
        gx0, gy0, gx1, gy1 = blue[0], blue[1], blue[2], blue[3]
        gray_cx = gx1 + 130      # 「打开不可关闭的对话框」在右侧，估中心（宽约 180）
        gray_cy = (gy0 + gy1) // 2
        app.click(gray_cx, gray_cy, settle=0.8)
        w, h, data = app.grab()

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

    # 收尾：这个模态三条关闭路全被吞 ⇒ `App.close()` 的 terminate 分支就是它的出口
    return 0


if __name__ == "__main__":
    sys.exit(run(main))
