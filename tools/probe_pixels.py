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
    """取一点，返回 **`(r, g, b)`**（与主题色常量 / `Color::fromRgb` / CSS 同一顺序）。

    ★ DIB 的字节序是 **BGRA**，翻序**只在这一个函数里发生**。调用方拿到的是 RGB 元组，
      可以直接跟 `PRIMARY = (0x00, 0x96, 0x88)` 这种常量比。

    ★★ 这条注释是被一次**假红**换来的（2026-11-02）：本轮把 9 个探针收进共享件时，
      `probe-motion.py` 原来自己那份 `px()` 返回 RGB（`data[i+2], data[i+1], data[i]`），
      换成这里返回 BGR 的版本后**通道悄悄对调**，而 `PRIMARY` 仍按 RGB 写 ⇒
      `near()` 拿 `(136,150,0)` 比 `(0,150,136)` 永远不等 ⇒ 第 1 条判据报
      "找不到墨绿按钮块"。同族的 `nonwhite_ratio()` 一直按 RGB 读 ⇒ 同一份字节流
      在同一个文件里有**两种语义**，这才是它没被当场发现的理由。
      灰度判据（亮度 / 低饱和）对翻序**不敏感** ⇒ 滚动条那几条一直是绿的，
      掩盖了整个迁移期。契约自检见 `selftest()`。
    """
    i = (y * width + x) * 4

    return data[i + 2], data[i + 1], data[i]


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


def near(color, ref, tol=26):
    """两个 RGB 是否在容差内（抗锯齿边 / 主题微调不算变）。

    ★ 原来 `probe-motion.py` 与 `probe-modal.py` 各写一份，默认容差一个 26 一个 14。
      同一件事两个默认值，读的人无从判断哪个是"对"的 —— 这里统一成 26，需要更紧的
      调用方显式传 `tol=`。
    """
    return all(abs(color[i] - ref[i]) <= tol for i in range(3))


def nonwhite_ratio(data, width, height):
    """非纯白像素占比（`0.0` = 一张白纸）。

    ★ 判据用它时阈值要按**被验的应用**定：`hello` 是白底几行字（实测 0.2%），
      `users` 那种带列表/顶条的 8.9% —— 拿 5% 当通用门槛会把正常的最小应用判成空白窗。
    """
    total = width * height
    if total <= 0:
        return 0.0
    nonwhite = 0
    for i in range(0, total * 4, 4):
        if (data[i + 2], data[i + 1], data[i]) != (255, 255, 255):
            nonwhite += 1

    return nonwhite / float(total)


def selftest():
    """**契约自检**：这个文件导出的语义必须被证明一次，否则它只是个会绿的函数。

    ★ 存在理由（2026-11-02 假红）：`px()` 的通道顺序**没有任何判据守着**。
      探针里凡是用到颜色的断言都靠它，而灰度断言（亮度 / 低饱和）对翻序不敏感
      ⇒ 顺序错了之后滚动条那几条照样绿，只有"找墨绿按钮"那一条会红，还很容易
      被当成"页面变了"。**量具的契约要自己证，不能靠下游碰巧红。**
    """
    fails = []

    def check(name, ok, detail=""):
        print(("  ok    " if ok else "  FAIL  ") + name + (f"  —— {detail}" if detail else ""))
        if not ok:
            fails.append(name)

    # ── ① 通道顺序：合成一块已知颜色的 BGRA 缓冲 ──
    w, h = 8, 8
    buf = bytearray(w * h * 4)
    # RGB #009688 ⇒ DIB 字节序 (b, g, r, a) = (0x88, 0x96, 0x00, 0xFF)
    i = (3 * w + 5) * 4
    buf[i:i + 4] = bytes((0x88, 0x96, 0x00, 0xFF))
    got = px(bytes(buf), w, 5, 3)
    check("px() 返回 (r,g,b) —— 与主题色常量同一顺序", got == (0x00, 0x96, 0x88), f"got={got}")
    check("px() 没把 R/B 对调（翻序后恰好也是合法颜色，只能靠这条抓）",
          got != (0x88, 0x96, 0x00), f"对调值 {(0x88, 0x96, 0x00)}")
    check("near() 认同一顺序的常量", near(got, (0x00, 0x96, 0x88), 0))
    check("near() 不认对调过的常量（否则上面几条都是恒真）",
          not near(got, (0x88, 0x96, 0x00), 0))

    # ── ② find_scrollbar：正例 / 两个反例 / 最右 1px 边框（历史坑）──
    W, H = 200, 200
    blank = bytearray()
    for _ in range(W * H):
        blank += bytes((0xFF, 0xFF, 0xFF, 0xFF))
    check("find_scrollbar 在白底上返回 None（不硬凑一个条出来）",
          find_scrollbar(bytes(blank), W, H) is None)

    page = bytearray()
    for _ in range(W * H):
        page += bytes((243, 243, 243, 0xFF))   # 页面底色：也是低饱和中亮度
    check("find_scrollbar 不会把**页面底色**当成条（假红原形）",
          find_scrollbar(bytes(page), W, H) is None)

    with_bar = bytearray(blank)
    for y in range(60, 140):
        for x in range(180, 186):             # 6 列 = THICKNESS
            i = (y * W + x) * 4
            with_bar[i:i + 4] = bytes((129, 119, 110, 0xFF))   # RGB(110,119,129)
    for y in range(H):                        # 窗口最右缘 1px 边框（历史坑）
        i = (y * W + (W - 1)) * 4
        with_bar[i:i + 4] = bytes((0xE8, 0xE8, 0xE8, 0xFF))
    check("find_scrollbar 定位到 6 宽那一段（不是最右缘 1px 边框）",
          find_scrollbar(bytes(with_bar), W, H) == (180, 185),
          f"got={find_scrollbar(bytes(with_bar), W, H)}")
    thin = bytearray(blank)
    for y in range(60, 140):
        for x in range(180, 183):             # 3 列：太窄，按约定不是条
            i = (y * W + x) * 4
            thin[i:i + 4] = bytes((129, 119, 110, 0xFF))
    check("find_scrollbar 不认 3 宽的窄带（宽度是约定，不然会把文字笔画当条）",
          find_scrollbar(bytes(thin), W, H) is None,
          f"got={find_scrollbar(bytes(thin), W, H)}")

    # ── ③ 亮度 / 非白占比：灰度口径与通道顺序无关，顺便钉住 ──
    check("column_luma 在白底条上取到 255（灰度口径自证）",
          abs(column_luma(bytes(blank), W, H, 10)[0] - 255.0) < 0.01)
    check("nonwhite_ratio 在全白上是 0.0", nonwhite_ratio(bytes(blank), W, H) == 0.0)
    check("nonwhite_ratio 认得出深色像素（不是恒 0）",
          nonwhite_ratio(bytes(with_bar), W, H) > 0.0)

    if fails:
        print(f"\n✗ 像素契约 {len(fails)} 条不过")
        return 1

    print("\n✓ 像素契约全过（px 顺序 / 定位 / 灰度口径）")
    return 0


if __name__ == "__main__":
    import sys

    sys.exit(selftest() if "--selftest" in sys.argv else 0)
