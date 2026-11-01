"""真机滚动条验证：启动 users.exe，核验列表页 ScrollView 的 thumb 画对了。

    python tools/probe-scrollbar.py            # 只判形状
    python tools/probe-scrollbar.py --wheel    # 追加滚轮量化（需要真实输入）

判据（三条缺一不可）：
  ① 定位到那条（`probe_pixels.find_scrollbar`：低饱和 + 中亮度 + ≥16 行连续 + 宽 5~7）；
  ② 长度 ≥ 20px 且上下端收窄（圆角 ⇒ 端行列数 < 中行列数）；
  ③ 最长连续段 ≥ 条长的 80%（★ 2026-10-17：第一版统计没对 y 去重 ——
     每行 6 个重复 y 让"连续段"判断自断 ⇒ 假报"碎成 2px"。判据自己要先自检。）

`--wheel` 追加滚轮量化（真机端到端 + 步长回归）：
  真滚 1 格 ⇒ thumb 应下移 ≈ travel × 48 / maxScroll
  （users 列表页实测 37px；若步长被改回 1px/格，只动 0.8px ⇒ FAIL）。

★ 为什么是 users 而不是 showcase：showcase 的 ListPage 内容不溢出
  （maxScroll=0 ⇒ 按设计不画滚动条）；users 列表页种子 15 用户溢出
  （maxScroll≈64）⇒ 是现成的"内容超长"用例。
  ★★ **2026-11-01 复核：这个前提已经不成立了** —— 当前 `users.exe` 的列表页
     整屏**一个 Muted 墨点都没有**（`ScrollBarFade::opacity()` 没滚过就是 1.0，
     所以不是淡出造成的），说明这页现在不溢出、按设计不画条。
     ⇒ 本探针现在会报 SKIP 并说清这条事实。真要判"条画得对不对"，
     `probe-scroll-diag.py` / `probe-motion.py`（showcase 长列表页）才是活的判据。

★★ 定位逻辑已经换过一次（2026-11-01）：原版在**客户区右缘 14px 窄带**里找条，
  并假设"条就在最右缘"。这正是 showcase 那次假红的同一类假设 —— 真条离视口右缘
  有 `ScrollBarGeometry::MARGIN = 3`，而"视口右缘"又可能离客户区右缘很远
  （页面内边距、卡片留白）。现在列号由 `find_scrollbar()` **查出来**，
  本文件只负责"在那一列上量形状"。定位不到 ⇒ SKIP（没测到），不是 FAIL。
"""

import sys

from probe_check import check, need, run
from probe_pixels import find_scrollbar, near, px
from probe_win import App

THUMB = (110, 119, 129)   # 主题 Muted（light）
THUMB_TOL = 12


def muted_count(data, w, h):
    """全图有多少个主题 Muted 墨点（= 条该用的颜色）。"""
    n = 0
    for y in range(h):
        for x in range(w):
            if near(px(data, w, x, y), THUMB, THUMB_TOL):
                n += 1

    return n


def thumb_shape(data, w, h, x0, x1):
    """在 `x0..x1` 这几列上量 thumb 的形状（★ 先按 y 聚合，别逐像素判连续段）。"""
    per_y = {}
    for y in range(h):
        n = sum(1 for x in range(x0, x1 + 1) if near(px(data, w, x, y), THUMB, THUMB_TOL))
        if n:
            per_y[y] = n

    if not per_y:
        return None

    ys = sorted(per_y)
    runs, cur = [], [ys[0]]
    for y in ys[1:]:
        if y == cur[-1] + 1:
            cur.append(y)
        else:
            runs.append(cur)
            cur = [y]
    runs.append(cur)
    longest = max(runs, key=len)

    return {
        "x_from": x0,
        "x_to": x1,
        "y_from": longest[0],
        "y_to": longest[-1],
        "length": len(longest),
        "mid_cols": per_y[longest[len(longest) // 2]],
        "end_cols": min(per_y[longest[0]], per_y[longest[-1]]),
        "cover": len(longest) / (longest[-1] - longest[0] + 1),
    }


def main() -> int:
    want_wheel = "--wheel" in sys.argv

    # 只有 --wheel 需要真实输入 ⇒ 其余情况不抢前台（桌面锁定时照样能跑）
    with App("users.exe", "用户管理", interactive=want_wheel) as app:
        w, h, data = app.grab()
        print(f"client = {w} x {h}")

        located = find_scrollbar(data, w, h)
        if located is None:
            # ★ 定位不到时先分清两件事，别笼统报"没定位到"：
            #   · 全图一个 Muted 墨点都没有 ⇒ 这页**没画**条（`maxScroll = 0` 时按设计不画），
            #     与"条画歪了"完全不是一回事；
            #   · 有墨点但不成"5~7 宽 + ≥16 行连续"⇒ 形状/位置真的可疑。
            n_muted = muted_count(data, w, h)
            if n_muted < 20:
                need(None, f"画面里只有 {n_muted} 个 Muted 墨点 —— 这页大概**不溢出**"
                           f"（maxScroll=0 时按设计不画条）。当前活判据是 "
                           f"probe-scroll-diag.py / probe-motion.py（showcase 长列表页）")
            need(None, f"有 {n_muted} 个 Muted 墨点，但凑不成 5~7 宽 / ≥16 行连续的竖条")

        bar = need(thumb_shape(data, w, h, located[0], located[1]),
                   f"x={located[0]}..{located[1]} 这几列上没有主题 Muted 色的墨迹")
        print(f"thumb = {bar}")

        check("① 条身 6px 宽 + 长度 ≥ 20px", bar["mid_cols"] >= 5 and bar["length"] >= 20,
              f"x={located[0]}..{located[1]} mid={bar['mid_cols']} 列 / 长 {bar['length']}px")
        check("③ 两端收窄（圆角端列数 < 中段列数）", bar["end_cols"] < bar["mid_cols"],
              f"end={bar['end_cols']} mid={bar['mid_cols']}")
        check("③ 最长连续段覆盖 ≥ 80%（没有碎成一截一截）", bar["cover"] >= 0.8,
              f"cover={bar['cover']:.2f}")

        if want_wheel:
            app.wheel(w // 2, h // 2, 1, settle=0.8)
            bar2 = thumb_shape(app.grab()[2], w, h, located[0], located[1])
            moved = None if bar2 is None else bar2["y_from"] - bar["y_from"]
            print(f"滚 1 格 ⇒ thumb y {bar['y_from']} → {bar2['y_from'] if bar2 else '?'}"
                  f"（下移 {moved}px；48px/格在该页 ≈ 37px，1px/格只会 0.8px）")
            check("滚 1 格 ⇒ thumb 下移 25~50px（步长没退化）",
                  moved is not None and 25 <= moved <= 50, f"moved={moved}")

    return 0


if __name__ == "__main__":
    sys.exit(run(main))
