"""真机验收：悬停浮起（盒外阴影）与滚动条空闲淡出。

两件事都是"状态变化有一条斜坡"，而 smoke 用的是**解释器里的合成盒** —— 它证明
不了 AOT 产物里帧时钟真的会为盒级动画上膛（`Application::wantsFrame()` 的第四
判据来自 `tickSubtree`，那条路径只有真机跑得到）。

判据设计的两条纪律（沿用 `probe-modal.py`）：
- 只判"**某个位置的内容变了**"，不判"这类像素没有了"；
- 阴影必须在**盒外**数像素：盒内数到的是状态色，两者在盒内无法区分。

★ 输入必须是真的：`PostMessage(WM_MOUSEMOVE)` 只让应用"知道"指针在哪，而
  `TrackMouseEvent(TME_LEAVE)` 依据的是**真实光标位置** ⇒ 应用照常把悬停态推完、
  动画也跑满，但画面不变，探针报"一像素不动"而产品是好的（2026-11-01 两次假红）。
  现在这条通道由 `tools/probe_win.py` 独占，本文件拿不到假的那条。
"""

import sys
import time

from probe_check import check, run
from probe_pixels import color_blocks, column_luma, find_scrollbar, px
from probe_win import App

# showcase 的 ThemeScope 把 Accent 覆盖成墨绿（LayUI 风）—— Primary 实心按钮就是这个色
PRIMARY = (0x00, 0x96, 0x88)


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
    with App("showcase.exe", "Drift 示例台") as app:
        w, h = app.size()
        print(f"客户区 {w}x{h}")

        # ── ① 导航到「按钮 Button」──（按语义名，重排侧栏不影响探针）
        app.click_nav('按钮 Button', settle=0.8)
        app.move(w - 40, h - 40, settle=0.3)   # 指针先挪到空白，免得开局就悬在按钮上
        w, h, before = app.grab()

        blocks = color_blocks(before, w, h, PRIMARY, 250)
        # 按钮形态：宽 60..300、高 20..60（两个像素步长）
        btn_blocks = [b for b in blocks if 60 <= b[2] - b[0] <= 300 and 16 <= b[3] - b[1] <= 60]
        blob = btn_blocks[0] if btn_blocks else None
        check("按钮页 · 找得到一个 Primary（墨绿实心）按钮块", blob is not None,
              f"blocks={blocks[:4]}")
        if blob is None:
            return 1

        cx = (blob[0] + blob[2]) // 2
        cy = (blob[1] + blob[3]) // 2
        print(f"  目标按钮 bbox={blob} 中心=({cx},{cy})")

        # ── ② 悬停 ⇒ 过渡跑完后盒外那一圈出现影子 ──
        app.move(cx, cy, settle=0.6)   # 过渡 ~370ms 收口，多等一轮
        w, h, hovered = app.grab()
        ring = ring_diff(before, hovered, w, blob, 2, 10)
        check("悬停浮起 · 收口后盒外 2..10px 那一圈变了（影子落在盒外）", ring > 40,
              f"ring diff = {ring} px")

        # ★ 反例：盒**内**的变化不能替代它 —— 状态色也在盒内，两者必须分开数
        inner = 0
        for y in range(blob[1] + 2, blob[3] - 1):
            for x in range(blob[0] + 2, blob[2] - 1):
                if px(before, w, x, y) != px(hovered, w, x, y):
                    inner += 1
        check("悬停浮起 · 盒内同时变了（状态色也参与了，别把两者混成一个判据）", inner > 100,
              f"inner diff = {inner} px")

        # ── ③ 移开 ⇒ 回到基线（收口后不该留下半个影子）──
        app.move(w - 40, h - 40, settle=0.8)
        w, h, after = app.grab()
        ring_back = ring_diff(before, after, w, blob, 2, 10)
        check("悬停浮起 · 移开后影子收回去（静止态与基线一致，差 < 40px）", ring_back < 40,
              f"ring diff = {ring_back} px")

        # ── ④ 滚动条空闲淡出：滚一下全亮，停手 1.8s 后变淡 ──
        #    ★ 必须换到**真的会溢出**的页（按钮页一屏放得下 ⇒ `maxScroll = 0`
        #      ⇒ 滚动条根本不画，第一版就是在按钮页上找它，0 命中）。
        #      「长列表 Big」才真溢出（按钮页一屏放得下 ⇒ maxScroll = 0，0 命中）。
        app.click_nav('长列表 Big', settle=0.9)
        app.wheel(w // 2, h // 2, 1, settle=0.25)
        w, h, scrolled = app.grab()

        # ★★ 判据**不能**要求"暗列"（`c[0] < 200`）：本主题的滚动条是**浅灰**
        #   （实测 luma≈239），照旧阈值找永远是"0 命中"，看起来像"滚动条没画"
        #   （2026-11-01 就是这样假红了一轮）。软滚动条只要"比背景深一点"就够。
        # ★★ 定位逻辑在 `tools/probe_pixels.py`（与 `probe-scroll-diag.py` 共用一份）。
        #   上一版取"右带里非白像素最多的列" ⇒ 恒定选中**页面底色**（整列 358 行），
        #   而真条在 x=1370..1375（离窗口右缘 39px）⇒ 两条断言假红了整整一轮。
        located = find_scrollbar(scrolled, w, h)
        bar_col = None if located is None else (located[0] + located[1]) // 2
        check("滚动条 · 滚动后在**列表视口右缘**定位到那一列（不是窗口右缘）",
              located is not None,
              "x=" + (f"{located[0]}..{located[1]}" if located is not None else "没定位到"))

        # ★ 判据直接落在**定位到的那一列**上（不再"猜哪列变了"）：条已经由
        #   `find_scrollbar` 定位，剩下的问题只是"它变浅了没有"。
        live_luma = None
        if bar_col is not None:
            live_luma, _ = column_luma(scrolled, w, h, bar_col)

        time.sleep(1.8)   # 无任何输入：这里同时在验"帧时钟会自己上膛"
        w, h, idle = app.grab()
        idle_luma = None
        if bar_col is not None:
            idle_luma, _ = column_luma(idle, w, h, bar_col)
        faded = None if live_luma is None or idle_luma is None else idle_luma - live_luma
        check("滚动条 · 停手 1.8s 后那一列明显变浅（帧时钟在无输入时把动画推完了）",
              faded is not None and faded > 40.0,
              f"col={bar_col} 滚后={None if live_luma is None else round(live_luma, 1)}"
              f" 静止={None if idle_luma is None else round(idle_luma, 1)}")

        # ── ⑤ 再滚一下 ⇒ 回到全亮（淡出不是单程票）──
        app.wheel(w // 2, h // 2, 1, settle=0.5)
        w, h, again = app.grab()
        if bar_col is not None and live_luma is not None:
            vals = [
                sum(px(again, w, bar_col, y)) / 3.0
                for y in range(60, h - 10, 2)
                if sum(px(again, w, bar_col, y)) / 3.0 < 225
            ]
            back = sum(vals) / len(vals) if vals else None
            check("滚动条 · 再滚一下又亮回来（淡出不是单程票）",
                  back is not None and abs(back - live_luma) < 25.0,
                  f"luma={None if back is None else round(back, 1)}（全亮时 {round(live_luma, 1)}）")
        else:
            check("滚动条 · 再滚一下又亮回来（淡出不是单程票）", False, "上文没定位到滚动条列")

    return 0


if __name__ == "__main__":
    sys.exit(run(main))
