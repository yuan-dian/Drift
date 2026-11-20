"""真机回归：点击之后「移开 → 移回」必须立刻生效（DEBT §41.4 / §42）。

修法前的症状：一次 click 之后移回按钮，画面停在原色（luma 117.1），静置 3.5s
也不亮，直到再动一下才补上 —— 帧内标脏没有把帧时钟上膛。

判据只认按钮盒内平均亮度（原色 ≈117.1 / 悬停 ≈138.4）。B 是「move 这条路本来
通」的对照，D/F 是防止「一直亮着」骗过主张的反例，静置段不发任何输入。
"""

import sys
import time

from probe_check import check, run
from probe_pixels import color_blocks, px
from probe_win import App

PRIMARY = (0x00, 0x96, 0x88)


def inner_luma(data, w, box):
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
        check("客户区尺寸正常（0x0 = 抓到了上一个进程的僵尸窗口）", w > 200 and h > 200, f"{w}x{h}")
        if w <= 200 or h <= 200:
            return 1

        app.click_nav('buttons', settle=0.8)
        app.move(w - 40, h - 40, settle=0.4)         # 页面切换的动画收口
        w, h, base = app.grab()

        blocks = color_blocks(base, w, h, PRIMARY, 250)
        btn = [b for b in blocks if 60 <= b[2] - b[0] <= 300 and 16 <= b[3] - b[1] <= 60]
        blob = btn[0] if btn else None
        check("找得到目标按钮", blob is not None, f"blocks={blocks[:4]}")
        if blob is None:
            return 1

        cx = (blob[0] + blob[2]) // 2
        cy = (blob[1] + blob[3]) // 2

        def luma():
            ww, _, shot = app.grab()
            return inner_luma(shot, ww, blob)

        app.move(cx, cy, settle=0.8)
        l_hover = luma()
        check("对照 · 悬停起来了（move 这条路本来是通的）", l_hover > 130, f"luma={l_hover:.1f}")

        app.click(cx, cy, settle=0.8)
        app.move(w - 60, h - 60, settle=0.8)
        l_away = luma()
        check("反例 · 移开后退回原色", l_away < 125, f"luma={l_away:.1f}")

        app.move(cx, cy, settle=0.8)
        l_back = luma()
        check(
            "本棒 · 点击后移回按钮当帧就亮（修法前恒为 117.1）",
            l_back > 130,
            f"luma={l_back:.1f}",
        )

        time.sleep(1.0)                              # 不发输入，只看读数稳不稳
        l_settle = luma()
        check("本棒 · 静置 1s 后仍是悬停档（不是靠下一条输入补上的）",
              l_settle > 130, f"luma={l_settle:.1f}")

        app.move(w - 60, h - 60, settle=0.8)
        l_final = luma()
        check("反例 · 再移开仍能退回原色（hover 没卡住）", l_final < 125, f"luma={l_final:.1f}")

    return 0


if __name__ == "__main__":
    sys.exit(run(main))
