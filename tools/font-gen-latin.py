#!/usr/bin/env python3
"""从 Noto Sans SC 生成拉丁（0x20–0x7E）字模：**按真实 advance 定宽**的高分辨率网格。

用法：
    python tools/font-gen-latin.py            # 写 src/Text/LatinGlyphData.php
    python tools/font-gen-latin.py --preview  # 只打印几个字的 ASCII art（人眼校验）

★ 为什么这样就能修好字距（§24.2 #21）：
  `BitmapFontFace` 的步进是 **位图宽 / unitsPerEm**，所以"位图宽 == 真实 advance"
  时步进天然正确 —— **不需要 advance 表，也不需要改一行代码**。
  旧的手写拉丁字模是**固定 5 列**（`i` 与 `m` 同宽）⇒ 步进恒 5/8 = 0.625em，
  而真值是 `i` 0.275em / `m` 0.926em。

★ 为什么网格是 32（而不是 CJK 的 16）：拉丁的窄字（`i` 0.275em）在 16 网格下只有
  4.4 列，量化误差 ±0.03em 就超过字宽差异的 10%。32 网格下 `i` = 9 列，误差 ±0.016em。
  14px 上屏时 32→14 是 2.3× 降采样，走 `rasterize()` 的**面积覆盖度**路径 ⇒ 有灰度。

★ 高度 = unitsPerEm = 32（位图即 em 盒的纵向），基线在第 `ASCENT`(=28) 行
  ⇒ ascent/descent = 28/32 / 4/32 = 0.875 / 0.125，与 CJK 面**逐字段相同**。

★ 字体必须可再分发：Noto Sans SC（SIL OFL 1.1）。别换 SimHei / 雅黑。
"""

import os
import sys

from PIL import Image, ImageDraw, ImageFont

# ★ Noto Sans SC（SIL OFL 1.1）—— 可再分发
FONT = "C:/Windows/Fonts/NotoSansSC-VF.ttf"
GRID = 32        # = unitsPerEm（每字 32 行）
ASCENT = 28      # 基线在第 28 行 ⇒ ascent/descent = 0.875 / 0.125
COVERAGE = 0.5   # 面积覆盖度阈值（32px 渲染后二值化）

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)


def make_font() -> ImageFont.FreeTypeFont:
    font = ImageFont.truetype(FONT, GRID)
    # ★ 变量字体的默认实例是 Thin(100)，必须显式切 Regular(400)
    font.set_variation_by_name("Regular")
    return font


def render(font, ch: str) -> tuple[int, str]:
    """→ (位图宽 = 真实 advance 的取整像素, '行/行/…')"""
    advance = font.getlength(ch)
    width = max(1, int(round(advance)))

    big = Image.new("L", (width, GRID), 255)
    ImageDraw.Draw(big).text((0, ASCENT), ch, font=font, fill=0, anchor="ls")

    rows = []
    for gy in range(GRID):
        row = []
        for gx in range(width):
            ink = 255 - big.getpixel((gx, gy))
            row.append("#" if ink >= 255 * COVERAGE else ".")
        rows.append("".join(row))
    return width, "/".join(rows)


def preview() -> int:
    font = make_font()
    for ch in "aimMWjg .,-":
        width, bm = render(font, ch)
        print(f"=== {ch!r}  advance={font.getlength(ch):.2f}px  位图宽={width} ===")
        for row in bm.split("/"):
            print("  " + row)
    return 0


def main() -> int:
    if "--preview" in sys.argv:
        return preview()

    font = make_font()
    entries = []
    for code in range(0x20, 0x7F):
        ch = chr(code)
        width, bm = render(font, ch)
        entries.append((ch, width, bm))

    lines = [
        "<?php",
        "declare(strict_types=1);",
        "",
        "namespace Drift\\Text;",
        "",
        "/**",
        " * 确定性参考实现的内置点阵字模 —— **拉丁部分（0x20–0x7E）**。",
        " *",
        " * ★ 本文件是**生成物**，不要手改：由 `tools/font-gen-latin.py` 从 **Noto Sans SC**",
        " *   （SIL OFL 1.1，可再分发）在 32px 上渲染、按 50% 覆盖度二值化后写出。",
        " *   重生成：`python tools/font-gen-latin.py`",
        " *",
        " * ★★ **位图宽 == 该字的真实 advance**（不是固定 5 列）—— 这是字距正确的全部秘密：",
        " *   `BitmapFontFace::glyphAdvance()` 返回 `位图宽 / unitsPerEm`，所以只要位图宽",
        " *   取自真字体，步进就天然正确，**不需要任何 advance 表**（§24.2 #21）。",
        " *   旧的手写表是固定 5 列 ⇒ `i` 与 `m` 同宽（真值 0.275em vs 0.926em）。",
        " *",
        " * ★ 高度 = unitsPerEm = 32，基线在第 28 行 ⇒ ascent/descent = 0.875/0.125，",
        " *   与 CJK 面（16 网格、14/2）**逐字段相同** ⇒ 两个面可以混排。",
        " *",
        " * ★ 覆盖范围 = 可打印 ASCII（空格 0x20 到 `~` 0x7E）。控制字符不在此表",
        " *   （`glyphAdvance` 对它们另有处理）。",
        " *",
        " * @internal",
        " */",
        "final class LatinGlyphData",
        "{",
        "    /** 每字形 32 行 × N 列（N = 该字的 advance），`#`=墨 / `.`=空，行间 `/` 分隔 */",
        "    public const GLYPHS = [",
    ]

    for ch, width, bm in entries:
        escaped = ch.replace("\\", "\\\\").replace("'", "\\'")
        lines.append(f"        '{escaped}' => '{bm}',")

    lines += [
        "    ];",
        "}",
        "",
    ]

    out = os.path.join(ROOT, "src", "Text", "LatinGlyphData.php")
    with open(out, "w", encoding="utf-8", newline="\n") as fh:
        fh.write("\n".join(lines))
    print(f"wrote {out} : {len(entries)} glyphs, {os.path.getsize(out)} bytes")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
