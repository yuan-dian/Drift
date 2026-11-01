"""像素探针的**共享定位件** —— 只放"怎么在一幅客户区截图里找到某样东西"。

★★ 存在的理由（2026-11-01，连续两轮假红）：

  1. 悬停那一轮：`probe-motion.py` 用 `PostMessage` 伪造鼠标 ⇒ 应用"知道指针在
     哪"但 `TME_LEAVE` 不认，探针报"一像素不变"，产品其实是好的。
  2. 滚动条那一轮：两个探针都在**窗口右缘**（`x ∈ [w-40, w)`）找条 —— 那里是
     **页面底色**，整列恒 238.9 ⇒ "滚动前后完全一样"必然成立。而条在**列表视口**
     的右缘（真机 x=1370..1375，离窗口右缘 39px），画着、也在淡。

  两次的共性是同一条：**判据没有先证明"我落在了目标内部"**。所以这里只做一件事：
  把一个"目标长什么样"的描述变成**可定位**的函数，定位不到就返回 `None` ——
  调用方必须把它当"没测到"（SKIP），不许当"没通过"（FAIL）。

★ 为什么单独一个文件：`probe-motion.py` 与 `probe-scroll-diag.py` 各抄过一份
  "右带里数非白像素"，两份都因为同一个原因错。定位逻辑只该有一份。
"""


def px(data, width, x, y):
    """BGRA（`BitBlt` + `GetDIBits` 的 32bpp 顺序）取一点。"""
    i = (y * width + x) * 4

    return data[i], data[i + 1], data[i + 2]


def find_scrollbar(data, width, height, top=40, bottom=20, min_rows=16,
                   low_saturation=26, luma_lo=100.0, luma_hi=235.0):
    """在客户区截图里定位**纵向**滚动条，返回 `(x0, x1)`（含两端）或 `None`。

    判据（四条同时成立才算"这是条"）：
      · 低饱和：`max(r,g,b) - min(r,g,b) < low_saturation`（条是中性灰，不是彩色内容）；
      · 中亮度：`luma_lo < luma < luma_hi`（排除纯白底与深色文字）；
      · 纵向连续：该列满足上面两条的**行数** ≥ `min_rows`（最短 thumb 24 逻辑像素）；
      · 横向成一串：连续 **5~7** 列（`ScrollBarGeometry::THICKNESS = 6`）。

    ★★ 必须**收齐所有连续段再挑**，不能"从右往左碰到第一段就停"：窗口最右缘
       有一条 1px 边框，它也满足前三条，会把真正的条整段挡住（第一版就这么没了）。
    ★ 从右往左取**最右**一段 5~7 宽的：同一屏可能有多个滚动容器（导航栏在左、
       内容区在右），右缘那个才是"当前页面"的那个。
    """
    hits = []
    for x in range(width - 1, width // 2, -1):
        rows = 0
        for y in range(top, height - bottom):
            r, g, b = px(data, width, x, y)
            luma = (r + g + b) / 3.0
            if max(r, g, b) - min(r, g, b) < low_saturation and luma_lo < luma < luma_hi:
                rows += 1
        hits.append((x, rows))

    runs = []
    current = []
    for x, rows in hits:
        if rows >= min_rows:
            current.append(x)
            continue
        if current:
            runs.append((current[-1], current[0]))
            current = []
    if current:
        runs.append((current[-1], current[0]))

    for x0, x1 in runs:   # hits 是右→左，runs 也按"从右到左"排列
        if 5 <= x1 - x0 + 1 <= 7:
            return x0, x1

    return None


def column_luma(data, width, height, x, top=40, bottom=20):
    """一列的 `(最暗, 均值)` —— 条"多亮"就用最暗那个数（AA 边不会把结论带偏）。"""
    values = []
    for y in range(top, height - bottom):
        r, g, b = px(data, width, x, y)
        values.append((r + g + b) / 3.0)

    return min(values), sum(values) / len(values)


def diff_count(a, b, width, x0, y0, x1, y1, step=2):
    """两块图的点差（步长 `step`）。"""
    n = 0
    for y in range(y0, y1, step):
        for x in range(x0, x1, step):
            if px(a, width, x, y) != px(b, width, x, y):
                n += 1

    return n
