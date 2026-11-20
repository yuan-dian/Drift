"""滚动条淡出诊断 —— **先定位条，再判"淡出跑没跑"**。

★★ 上一版的坑（2026-11-01，白追一轮）：「滚动条一条都没画」这个结论是假的。
   它取 `x = w-40 .. w`（窗口**右缘**）当采样带 —— 那里是**页面底色**，整列恒
   238.9，于是"滚动前后完全一样"必然成立。而条其实在**列表视口**的右缘
   （真机 x=1370..1375），画着、也在淡（119 → 209）。
   教训只有一条：**像素判据必须先在目标内部落点**。采不到目标时说"没测到"，
   不许把它写成"没变"—— 后者会被读成"产品坏了"。

判据三条，依次收紧（前一条不成立，后一条没有意义）：
  ① **内容真的滚了**：两张图的内容区（左侧 3/4）有点差；
  ② **定位到条**：最右一串 5~7 宽、低饱和、中亮度、≥16 行连续的竖列
     （定位逻辑在 `tools/probe_pixels.py`，与 `probe-motion.py` 共用一份）；
  ③ **淡出跑过**：那一列滚后 ≈119（全亮）→ 静止 3s ≈208（IDLE 0.35），差 ≥ 40，
     且静止值 < 240（`ScrollBarFade` 明写"淡出不给 0"）。

退出码：`0` 判完了（结论在输出里）· `2` **没测到**（找不到条 / 内容没滚 /
抢不到前台）· `1` 判据不过。前两者分开，是这个脚本存在的主要理由 ——
现在由 `tools/probe_check.py` 统一保证，本文件不再自己拼。

★ 启动 / 抢前台 / 真滚轮 / 截图全部走 `tools/probe_win.py`。上一版自己裸调
  `SetForegroundWindow` 且**不回读** ⇒ 抢不到前台时报"滚轮没生效（内容区没变）"，
  听起来像产品坏了。现在"输入送不到"在 `probe_win` 里就变成 SKIP。
"""

import os
import struct
import sys
import time
import zlib

from probe_check import check, need, run
from probe_pixels import column_luma, diff_count, find_scrollbar
from probe_win import ROOT, App


def save_png_crop(data, w, h, x0, x1, y0, y1, path):
    """把一块区域存成 PNG（标准库手写，不引第三方依赖）—— 定位失败时留证据。"""
    cw, ch = x1 - x0, y1 - y0
    raw = bytearray()
    for y in range(y0, y1):
        raw.append(0)   # filter type 0
        for x in range(x0, x1):
            i = (y * w + x) * 4
            raw += bytes((data[i], data[i + 1], data[i + 2]))

    def chunk(tag, payload):
        c = struct.pack(">I", len(payload)) + tag + payload

        return c + struct.pack(">I", zlib.crc32(tag + payload) & 0xFFFFFFFF)

    png = b"\x89PNG\r\n\x1a\n"
    png += chunk(b"IHDR", struct.pack(">IIBBBBB", cw, ch, 8, 2, 0, 0, 0))
    png += chunk(b"IDAT", zlib.compress(bytes(raw), 9))
    png += chunk(b"IEND", b"")
    with open(path, "wb") as fh:
        fh.write(png)
    print(f"  存图 {path} ({cw}x{ch})")


def main():
    with App("showcase.exe", "Drift 示例台") as app:
        app.click_nav('长列表 Big', settle=1.2)

        w, h, base = app.grab()
        print(f"客户区 {w}x{h}（已切到长列表页）")

        app.wheel(w // 2, h // 2, 3, settle=0.15)
        app.park()          # 指针移出窗口，别让 hover 掺进来

        time.sleep(0.85)
        w, h, during = app.grab()
        time.sleep(2.2)
        w, h, idle = app.grab()

        # ① 内容真的滚了吗
        moved = diff_count(base, during, w, 0, 60, int(w * 0.75), h - 40)
        check("① 内容真的滚了", moved > 50, f"内容区变化 {moved} 点（步长 2）")

        # ② 定位条（定位不到就是"没测到"，不是"没通过"）
        found = need(find_scrollbar(during, w, h),
                     "没定位到滚动条（5~7 宽 / 低饱和 / 中亮度 / ≥16 行）")
        x0, x1 = found
        x = (x0 + x1) // 2
        print(f"② 条定位: x={x0}..{x1}（宽 {x1 - x0 + 1}），取中心列 x={x}")

        # ③ 淡出跑了吗
        min_a, avg_a = column_luma(during, w, h, x)
        min_b, avg_b = column_luma(idle, w, h, x)
        print(f"   滚后: 最暗 {min_a:.1f} / 均值 {avg_a:.1f}   ← 期望 ≈119（全亮）")
        print(f"   静止: 最暗 {min_b:.1f} / 均值 {avg_b:.1f}   ← 期望 ≈208（IDLE 0.35）")

        save_png_crop(during, w, h, max(0, x0 - 12), min(w, x1 + 12), 0, h,
                      os.path.join(ROOT, "_build", "_bar-during.png"))
        save_png_crop(idle, w, h, max(0, x0 - 12), min(w, x1 + 12), 0, h,
                      os.path.join(ROOT, "_build", "_bar-idle2.png"))

        check("③ 淡出跑过（Δ ≥ 40）", (min_b - min_a) >= 40.0, f"Δ最暗 = {min_b - min_a:.1f}")
        check("③ 淡出不给 0（静止值 < 240）", min_b < 240.0, f"静止最暗 = {min_b:.1f}")

    return 0


if __name__ == "__main__":
    sys.exit(run(main))
