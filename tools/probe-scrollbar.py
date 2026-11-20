"""真机滚动条验证：**形状 / 位置 / 跟手**三件事，判据落在确实溢出的那一页上。

    python tools/probe-scrollbar.py            # 形状 + 位置（切页要点导航 ⇒ 需要前台）
    python tools/probe-scrollbar.py --wheel    # 追加滚轮跟手量化

判据（四条缺一不可）：
  ① 定位到那条（`probe_pixels.find_scrollbar`：低饱和 + 中亮度 + ≥16 行连续 + 宽 5~7）；
  ② 条身 6px 宽、长度 ≥ `ScrollBarGeometry::MIN_THUMB`(24)；
  ③ 两端收窄（圆角 ⇒ 端行列数 < 中行列数）；
  ④ ★ **条在视口的右缘，不在窗口的右缘** —— 这条是 2026-11-01 两次假红换来的：
     探针当时假设"条贴窗口最右缘"，而真条离窗口右缘三十几像素（`MARGIN` + 页面内边距）。
     把这条写成判据，下次"条跑到窗口边上去"会**直接红**，不用再靠人看图。

`--wheel` 追加跟手量化：滚 1 格 ⇒ 内容变了 **且** thumb 往下走 ≥ 3px。

★ 目标页为什么是 showcase 的「长列表 Big」（导航第 6 项）：
  它的内容确实溢出（`maxScroll` 很大），条一定画。
  ★★ 历史（2026-10-17 → 11-02）：本探针原本验 `users.exe` 的列表页，理由是
     "种子 15 用户溢出 `maxScroll≈64`"。**这个前提早已不成立**（实测滚轮不动、
     右 80px 内零个 Muted 墨点 ⇒ 这页不溢出、按设计不画条）⇒ 探针长期只能 SKIP。
     一个只会 SKIP 的探针等于没有探针，所以换页，而不是留着它假装守卫。
  ★ 2026-11-01 那次"`users` 整屏只有 4 个 Muted 墨点"的结论**也是错的**：
    当时 `px()` 的通道顺序是 BGR 而 `THUMB` 按 RGB 写，`near()` 因此几乎恒假
    （修好后同一页量到 269 个）。**灰度判据掩盖了这个错**，见 `probe_pixels.selftest()`。

★★ 定位逻辑只有一份（`probe_pixels.find_scrollbar`），本文件只负责"在那一列上量形状"。
  定位不到 ⇒ SKIP（没测到），不是 FAIL。
"""

import sys

from probe_check import check, need, run
from probe_pixels import find_scrollbar, near, px
from probe_win import App

THUMB = (110, 119, 129)   # 主题 Muted（light），RGB
THUMB_TOL = 12
MIN_THUMB = 24.0          # ScrollBarGeometry::MIN_THUMB

# 墨迹口径的阈值必须**同时**满足两件事（这是被实测逼出来的区间）：
#   · 淡出后的条（`IDLE_OPACITY 0.35` 叠白底）≈ **207.7** 必须算墨 —— 所以阈值 > 208；
#   · 页面底色 `(239,235,233)` ≈ **235.7** 必须不算墨 —— 所以阈值 < 235。
# 取中间偏下 222：条全亮 119 / 淡出 208 / 底色 236，三档分得开。
INK_LUMA = 222.0


def muted_count(data, w, h):
    """全图有多少个主题 Muted 墨点（= 条该用的颜色）。"""
    n = 0
    for y in range(h):
        for x in range(w):
            if near(px(data, w, x, y), THUMB, THUMB_TOL):
                n += 1

    return n


def thumb_shape(data, w, h, x0, x1):
    """在 `x0..x1` 这几列上量 thumb 的形状（★ 先按 y 聚合，别逐像素判连续段）。

    ★ 长度按**墨迹**量（`luma < INK_LUMA`），不按"精确命中 Muted"量：条的上下端是
      抗锯齿边（实测 `(123,131,140)`），与 `Muted` 差 13/14 ⇒ 任何 `tol < 15` 的
      精确匹配都会**把两端各吃掉一行**，于是 24px 的条被量成 22px，
      刚好跌破 `MIN_THUMB = 24` 报"条太短"（2026-11-02 差点当产品缺陷）。
      墨迹口径下量到 24，与 `ScrollBarGeometry::MIN_THUMB` 逐位对上。
    """
    per_y = {}
    for y in range(h):
        n = sum(1 for x in range(x0, x1 + 1) if sum(px(data, w, x, y)) / 3.0 < INK_LUMA)
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

    with App("showcase.exe", "Drift 示例台") as app:
        app.click_nav('big-list', settle=1.0)      # 按稳定 id 点导航，不记坐标也不依赖文案
        app.move(app.size()[0] - 40, app.size()[1] - 40, settle=0.3)   # 指针离开列表
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
                need(None, f"画面里只有 {n_muted} 个 Muted 墨点 —— 这页不溢出"
                           f"（maxScroll=0 时按设计不画条）⇒ 换一页再验，别当失败")
            need(None, f"有 {n_muted} 个 Muted 墨点，但凑不成 5~7 宽 / ≥16 行连续的竖条")

        bar = need(thumb_shape(data, w, h, located[0], located[1]),
                   f"x={located[0]}..{located[1]} 这几列上没有主题 Muted 色的墨迹")
        print(f"thumb = {bar}")

        check("① 条身 6px 宽 + 长度 ≥ 24px（MIN_THUMB）",
              bar["mid_cols"] >= 5 and bar["length"] >= MIN_THUMB,
              f"x={located[0]}..{located[1]} mid={bar['mid_cols']} 列 / 长 {bar['length']}px")
        check("② 两端收窄（圆角端列数 < 中段列数）", bar["end_cols"] < bar["mid_cols"],
              f"end={bar['end_cols']} mid={bar['mid_cols']}")
        check("③ 最长连续段覆盖 ≥ 80%（没有碎成一截一截）", bar["cover"] >= 0.8,
              f"cover={bar['cover']:.2f}")

        # ④ 条在**视口**右缘，不在**窗口**右缘：留白 = MARGIN 3 + 页面内边距
        inset = w - 1 - located[1]
        check("④ 条离客户区右缘有留白（在视口右缘，不是窗口右缘）", 6 <= inset <= 120,
              f"条右列 x={located[1]}，客户区宽 {w} ⇒ 留白 {inset}px")

        if want_wheel:
            # ★ 为什么是**一大批**格数而不是 1 格：这一页是 10000 行 × 44px ⇒ 内容高
            #   约 44 万，而 `thumbLength = max(MIN_THUMB, track²/(track+maxScroll))`
            #   被钳在 24 ⇒ 一格 48px 只让 thumb 动
            #   `travel × 48 / maxScroll ≈ 360 × 48 / 439600 ≈ 0.04px`。
            #   **这 0.04px 是比例滚动条的正确行为**，不是"画死的"。
            #   实测 30 格 = 1px，200 格 ≈ 8px —— 只有后者量得出来。
            # ★★ 滚完必须把指针**挪回原处**再截图：`wheel()` 会把光标移到滚轮落点，
            #   行的悬停态随之变化 ⇒ 内容区那几百个像素差异是 hover 造成的假信号
            #   （第一版把它当成"内容滚了"的证据，结论碰巧对但理由是错的）。
            # ★★★ 截图必须赶在 `IDLE_MS = 900` 之前，或干脆接受淡出 —— 墨迹阈值
            #   222 已把淡出后的 208 算作墨（第一版取 200 ⇒ 淡出后量成"没有条"，
            #   于是 ⑥ 把顶栏那 2 行边框当成了 thumb，报出 -221px 的假位移）。
            app.wheel(w // 2, h // 2, 200, settle=0.9)
            app.move(w - 40, h - 40, settle=0.5)
            w2, h2, data2 = app.grab()
            bar2 = thumb_shape(data2, w2, h2, located[0], located[1])
            moved = None if bar2 is None else bar2["y_from"] - bar["y_from"]
            changed = sum(
                1
                for y in range(240, h - 20, 3)
                for x in range(230, w - 80, 3)
                if px(data, w, x, y) != px(data2, w2, x, y)
            )
            print(f"滚 200 格 ⇒ thumb y {bar['y_from']} → "
                  f"{bar2['y_from'] if bar2 else '没量到'}（下移 {moved}px），"
                  f"内容区变了 {changed} 点")
            check("④ 滚 200 格 ⇒ 内容真的动了（否则下面的位移是巧合）", changed > 50,
                  f"变了 {changed} 点")
            check("⑤ 滚 200 格 ⇒ thumb 往下走 ≥ 4px（按比例跟手，不是画死的）",
                  moved is not None and moved >= 4, f"moved={moved}")

            # ⑥ 滚回顶部 ⇒ thumb 回到原位（位移是双向的，不是单向漂移）
            app.wheel(w // 2, h // 2, -260, settle=0.9)
            app.move(w - 40, h - 40, settle=0.5)
            w3, h3, data3 = app.grab()
            bar3 = thumb_shape(data3, w3, h3, located[0], located[1])
            back = None if bar3 is None else bar3["y_from"] - bar["y_from"]
            check("⑥ 滚回顶部 ⇒ thumb 回到原位（±3px）",
                  back is not None and abs(back) <= 3, f"回到 {back}px")

    return 0


if __name__ == "__main__":
    sys.exit(run(main))
