"""真机验收：按下态（`DEBT` §40）—— 指针第二个相位在 AOT 产物里真的画出来了。

单测已经证明「盒会画按下态」「down 事件会推到盒上」，但它证明不了最后一段：
**AOT 产物里，按下动画会把帧时钟重新上膛、像素真的变了。**
盒级动画走的是 `RenderObject::tickAnimations()` ⇒ `Application::wantsFrame()` 的
第四条判据，那条路径只有真机跑得到（同 `probe-motion.py` 的悬停）。

判据设计的三条纪律：
- 只判「**按钮盒内**的像素怎么变」，不判「多了某种颜色」；
- 必须有**对照**：悬停帧先与基线不同，否则「按下帧不同」可能只是页面自己变了；
- 必须判**方向**：`Pressed` 比 `Hover` 深一档，Primary 变体是同一白、更高 alpha
  ⇒ 按下帧盒内更**亮**。只判「变了」会把「配反了」也放过去。

★ 焦点的处理（第一版会踩的坑）：`down` 会 `focusHit()` ⇒ 按钮首次获得焦点，
  焦点环若落在盒内，则「松开后应回到悬停帧」永远不成立。所以先 `click()` 一次
  把焦点给它，此后 hover / down / up 三帧的焦点状态一致，只差悬停与按下。
  「主要」按钮没有 `onPressed`（`ButtonsPage` 里只有「点击我」有）⇒ 这次点击
  不改页面内容。
"""

import sys

from probe_check import check, run
from probe_pixels import color_blocks, diff_count, px
from probe_win import App

# showcase 的 ThemeScope 把 Accent 覆盖成墨绿（LayUI 风）—— Primary 实心按钮就是这个色
PRIMARY = (0x00, 0x96, 0x88)


def inner_luma(data, w, box):
    """按钮盒内（去掉圆角外圈）的平均亮度。"""
    total = 0.0
    n = 0
    for y in range(box[1] + 4, box[3] - 3):
        for x in range(box[0] + 6, box[2] - 5):
            r, g, b = px(data, w, x, y)
            total += (r + g + b) / 3.0
            n += 1

    return total / n if n else 0.0


def main():
    with App("showcase.exe", "Drift 示例台") as app:
        w, h = app.size()
        print(f"客户区 {w}x{h}")

        # ── 导航到「按钮 Button」（按语义名，重排侧栏不影响探针）──
        app.click_nav('按钮 Button', settle=0.8)
        # 页面切换的动画收口。★ §42 之前这里要「浪费一条 move」绕开点击后
        #   hover 不恢复；帧欠账补齐后第一条输入即生效，只剩一次等待。
        app.move(w - 40, h - 40, settle=0.8)
        w, h, before = app.grab()

        blocks = color_blocks(before, w, h, PRIMARY, 250)
        btn_blocks = [b for b in blocks if 60 <= b[2] - b[0] <= 300 and 16 <= b[3] - b[1] <= 60]
        blob = btn_blocks[0] if btn_blocks else None
        check("按下态 · 找得到一个 Primary（墨绿实心）按钮块", blob is not None,
              f"blocks={blocks[:4]}")
        if blob is None:
            return 1

        cx = (blob[0] + blob[2]) // 2
        cy = (blob[1] + blob[3]) // 2
        print(f"  目标按钮 bbox={blob} 中心=({cx},{cy})")

        x0, y0, x1, y1 = blob[0] + 3, blob[1] + 3, blob[2] - 2, blob[3] - 1

        # ── ① 悬停帧：指针移进来，等过渡收口 ──
        #   ★ 不加 `click()` 定焦点：那会额外引一次 down/up，正好踩中"点击后第一条
        #     输入被吃"的坑，把测的变成那条已知异常。焦点由这次 down 自己拿到。
        app.move(cx, cy, settle=0.8)
        w, h, hovered = app.grab()

        # 对照：悬停本身要让盒内变（否则下面的"按下变了"可能只是页面自己在动）
        d_hover = diff_count(before, hovered, w, x0, y0, x1, y1, step=1)
        check("按下态 · 对照：悬停帧与基线在盒内不同（悬停这条路本来是通的）",
              d_hover > 60, f"inner diff = {d_hover} px")

        # ── ② 按住不放 —— 这一帧只有「按下」与「悬停」之差 ──
        app.press(cx, cy, settle=0.7)
        w, h, down = app.grab()

        d_press = diff_count(hovered, down, w, x0, y0, x1, y1, step=1)
        check("按下态 · 按下帧与悬停帧在盒内不同（这是本棒的全部主张）",
              d_press > 60, f"inner diff = {d_press} px")

        l_hover = inner_luma(hovered, w, blob)
        l_down = inner_luma(down, w, blob)
        check("按下态 · 方向对：Primary 按下比悬停更亮（同一白、alpha 40→70）",
              l_down - l_hover > 3.0,
              f"luma 悬停={l_hover:.1f} 按下={l_down:.1f} 差={l_down - l_hover:+.1f}")

        # ── ③ 松开 ⇒ 落回**悬停**档，不是落回原色 ──
        app.release(settle=0.8)
        w, h, up = app.grab()

        d_up = diff_count(hovered, up, w, x0, y0, x1, y1, step=1)
        check("按下态 · 松开后回到悬停档（与悬停帧逐像素相同，差 < 40px）",
              d_up < 40, f"inner diff = {d_up} px")

        # ★ 反例：松开后**不能**等于基线 —— 指针还在按钮上，悬停色应该还在。
        #   只判"回到悬停"会被"两帧都错成原色"骗过，这条把方向钉住。
        d_base = diff_count(before, up, w, x0, y0, x1, y1, step=1)
        check("按下态 · 松开后指针仍在按钮上 ⇒ 不等于基线（悬停色没被一起清掉）",
              d_base > 60, f"inner diff = {d_base} px")

        # ── ④ 移开 ⇒ 悬停与按下都没有残留 ──
        app.move(w - 40, h - 40, settle=0.8)
        w, h, after = app.grab()
        d_after = diff_count(before, after, w, x0, y0, x1, y1, step=1)
        check("按下态 · 移开后盒内回到基线（不留下按住态）",
              d_after < 40, f"inner diff = {d_after} px")

    return 0


if __name__ == "__main__":
    sys.exit(run(main))
