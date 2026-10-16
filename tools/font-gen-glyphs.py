#!/usr/bin/env python3
"""从 Noto Sans SC 预栅格化 16x16 二值位图，生成 Drift 的 CJK / 非 ASCII 补充字模。

用法（两步，第 ① 步的产物在第 ② 步消费）：

    php tools/font-scan-chars.php        # ① 扫全仓字符串字面量 → _build/cjk_used.json
    python tools/font-gen-glyphs.py      # ② 栅格化 → src/Text/CjkGlyphData.php

约定与手写的 `DeterministicFontData::CJK_GLYPHS` 一致：
  每个字形 16 行、每行 16 字符（'#'=墨 / '.'=空），行间 '/' 分隔；
  基线落在自上而下第 ascent=14 行。

★ 为什么必须是"生成物"而不是运行时读 TTF：`DisplayList` 里内嵌字形位图 ⇒ 换机器
  后位图必须逐字节相同（O-08：golden 绑定测试字体）。把字体在固定尺寸下**一次性**
  栅格成固定数据，运行时只读 ⇒ 确定性成立。

★ 字体选择（**必须可再分发**）：用 **Noto Sans SC（SIL OFL 1.1）**。系统里的 SimHei /
  雅黑是专有字体 ⇒ 由它派生的点阵数据**不能入库**。字形表是纯数据、且只作测试夹具，
  但来源必须干净。

★ 为什么先渲染到 `RENDER_PX` 再按**面积覆盖度**降到 16×16，而不是直接在 16px 上阈值化：
  中文笔画密集，16px 直出再二值化会**整根笔画消失**（横/竖细线落在两个像素之间 ⇒
  灰度不到阈值）。高分辨率渲染 + 面积覆盖度 = 把"半个像素的墨"折算成覆盖度 ⇒ 细线
  过 0.5 阈值 ⇒ 保住。这与 `BitmapFontFace::rasterize()` 降采样用的是**同一条原理**
  （那条是运行期、这条是生成期）。
"""
import json, os, sys

# ★ Noto Sans SC（SIL OFL 1.1）—— 可再分发，别换成 SimHei / 雅黑
FONT = 'C:/Windows/Fonts/NotoSansSC-VF.ttf'
RENDER_PX = 64  # 先在这个尺寸上渲染（抗锯齿灰度），再降到 GRID
GRID = 16
ASCENT = 14
COVERAGE = 0.5

from PIL import Image, ImageDraw, ImageFont

here = os.path.dirname(os.path.abspath(__file__))
root = os.path.dirname(here)
used = os.path.join(root, '_build', 'cjk_used.json')

if not os.path.exists(used):
    sys.exit('缺少 %s —— 先跑：php tools/font-scan-chars.php' % used)

chars = json.load(open(used, encoding='utf-8'))
font = ImageFont.truetype(FONT, RENDER_PX)
# ★ `NotoSansSC-VF` 是**变量字体**，默认实例是 **Thin(100)** —— 直接用它渲染出来的
#   横竖笔画只有 ~1.5px（64px 下）⇒ 降到 16×16 后覆盖度够不上阈值 ⇒ 整字判空。
#   必须显式切到 Regular(400)。（2026-10-16 踩过：`三/二/厂/引/「」` 全部"无字形"。）
font.set_variation_by_name('Regular')

STEP = RENDER_PX // GRID  # 每个 16×16 格对应的渲染像素边长
BASELINE = ASCENT * STEP  # 基线在渲染图里的 y（= 第 14 行格的下沿）


def render(ch: str) -> str:
    big = Image.new('L', (RENDER_PX, RENDER_PX), 255)
    ImageDraw.Draw(big).text((0, BASELINE), ch, font=font, fill=0, anchor='ls')
    rows = []
    for gy in range(GRID):
        row = []
        for gx in range(GRID):
            ink = 0
            for sy in range(gy * STEP, (gy + 1) * STEP):
                for sx in range(gx * STEP, (gx + 1) * STEP):
                    ink += 255 - big.getpixel((sx, sy))
            row.append('#' if ink >= 255.0 * STEP * STEP * COVERAGE else '.')
        rows.append(''.join(row))
    return '/'.join(rows)


# 先渲染一遍，剔除字体拿不到字形的码位（全空 = 无墨 ⇒ 画出来也没用，别混进表里）
skipped = []
kept = []
for ch in chars:
    bm = render(ch)
    if set(bm) == {'.', '/'}:
        skipped.append(ch)
    else:
        kept.append((ch, bm))
if skipped:
    print('字体无此字形，已跳过 %d 个：%s' % (len(skipped), ''.join(skipped)))

lines = []
lines.append('<?php')
lines.append('declare(strict_types=1);')
lines.append('')
lines.append('namespace Drift\\Text;')
lines.append('')
lines.append('/**')
lines.append(' * 确定性参考实现的内置点阵字模 —— **CJK / 非 ASCII 补充部分**。')
lines.append(' *')
lines.append(' * ★ 本文件是**生成物**，不要手改：由 `tools/font-gen-glyphs.py` 从 **Noto Sans SC**')
lines.append(' *   （SIL OFL 1.1，可再分发）在 64px 上渲染、再按**面积覆盖度**降到 16×16 二值位图')
lines.append(' *   后写出。运行时只读这份固定数据 ⇒ 与手写的 `DeterministicFontData::CJK_GLYPHS`')
lines.append(' *   一样是**确定性**的。')
lines.append(' *')
lines.append(' * ★ 覆盖范围 = 全仓【字符串字面量】里出现过的所有非 ASCII 字符（cp >= 0xA0，')
lines.append(' *   含汉字、中文标点 `，。（）「」`、箭头 `↑ ⇒` 等），由 `tools/font-scan-chars.php`')
lines.append(' *   扫描。手写那 16 个字模优先（见 `DeterministicFontData::cjk()` 的合并顺序），本表只补缺。')
lines.append(' *')
lines.append(' * ★ 重生成：`php tools/font-scan-chars.php && python tools/font-gen-glyphs.py`')
lines.append(' *   （新增中文字面量后必须重跑，否则新字静默无墨 —— 见 `BitmapFontFace` 的缺字形契约）')
lines.append(' *')
lines.append(' * 编码：每字形 16 行 × 16 字符，`#`=墨 / `.`=空，行间 `/` 分隔；')
lines.append(' *   行数 = face 的 `unitsPerEm`，基线在第 `ascent`(=14) 行。')
lines.append(' *')
lines.append(' * @internal')
lines.append(' */')
lines.append('final class CjkGlyphData')
lines.append('{')
lines.append('    /** @var array<string, string> 字符 => 16 行位图（`/` 分隔） */')
lines.append('    public const GLYPHS = [')
for ch, bm in kept:
    lines.append("        '%s' => '%s'," % (ch, bm))
lines.append('    ];')
lines.append('}')
lines.append('')

out = os.path.join(root, 'src', 'Text', 'CjkGlyphData.php')
with open(out, 'w', encoding='utf-8', newline='\n') as f:
    f.write('\n'.join(lines))
print('wrote %s : %d glyphs, %d bytes' % (out, len(kept), os.path.getsize(out)))
