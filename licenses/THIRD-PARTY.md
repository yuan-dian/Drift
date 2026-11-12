# 第三方组件与许可

`dist/drift-deps-win-x64.zip` 除本仓代码外还分发两类内容：Skia 预编译件（含它链接的
九个静态库）与 Noto Sans SC 字体。本目录收录全部许可全文，本文件是索引与来源记录。

## 分发形态

| 包内位置 | 内容 |
|---|---|
| `cpp/licenses/*.txt` | 下表 11 份全文，发布时整目录随附 |
| `cpp/licenses/THIRD-PARTY.md` | 本文件随包副本 |
| `cpp/skia/LICENSE` | Skia BSD-3 的就近副本 —— 解包第一眼可见，防止只拿 `cpp/` 的人漏掉 |
| `cpp/fonts/OFL.txt` | Noto 的 OFL 1.1 就近副本，OFL 条件 2 要求随字体保留 |
| `cpp/fonts/FONT-NOTICE.txt` | 字体版权声明与 Reserved Font Name 说明 |

包内路径都挂在 `cpp/` 下，因为这个包是**解压到仓库根**的：顶层放东西会污染工作区，
还会盖掉仓库里更新的 `licenses/`。仓库根 `licenses/` 是事实源，包内 `cpp/licenses/`
是它的快照，由 `tools/pack-deps.php` 注入，不单独维护。

## 全文清单

| 组件 | 相关二进制 | 许可 | 文件 | 上游 |
|---|---|---|---|---|
| Skia | `skia` `skcms` `skparagraph` `skshaper` `skottie` `sksg` `svg` `skunicode_*` `bentleyottmann` `jsonreader` `skresources` | BSD-3-Clause | [`BSD-3-Clause-Skia.txt`](BSD-3-Clause-Skia.txt) | https://github.com/google/skia |
| Noto Sans SC Regular / Bold | `cpp/fonts/*.ttf` | SIL OFL 1.1 | [`OFL-NotoSansSC.txt`](OFL-NotoSansSC.txt) | https://github.com/google/fonts |
| Expat | `expat.lib` | MIT | [`MIT-Expat.txt`](MIT-Expat.txt) | https://github.com/libexpat/libexpat |
| FreeType | `freetype2.lib` | FTL（双许可之一，见下） | [`FTL-Freetype.txt`](FTL-Freetype.txt) | https://gitlab.freedesktop.org/freetype/freetype |
| HarfBuzz | `harfbuzz.lib` | MIT（上游称 "Old MIT"） | [`MIT-HarfBuzz.txt`](MIT-HarfBuzz.txt) | https://github.com/harfbuzz/harfbuzz |
| ICU | `icu.lib` | Unicode License V3 | [`UnicodeV3-ICU.txt`](UnicodeV3-ICU.txt) | https://github.com/unicode-org/icu |
| libjpeg-turbo | `libjpeg` `libjpeg12` `libjpeg16` | IJG **与** BSD-3 双许可 | [`IJG-BSD3-libjpeg-turbo.txt`](IJG-BSD3-libjpeg-turbo.txt) | https://github.com/libjpeg-turbo/libjpeg-turbo |
| libpng | `libpng.lib` | PNG Reference Library License v2 | [`libpng-License-v2.txt`](libpng-License-v2.txt) | https://github.com/pnggroup/libpng |
| libwebp | `libwebp` `libwebp_sse41` | BSD-3-Clause | [`BSD-3-Clause-libwebp.txt`](BSD-3-Clause-libwebp.txt) | https://github.com/webmproject/libwebp |
| Wuffs | `wuffs.lib` | MIT **或** Apache-2.0 双许可 | [`MIT-Apache2-Wuffs.txt`](MIT-Apache2-Wuffs.txt) | https://github.com/google/wuffs |
| zlib | `zlib.lib` | zlib License | [`Zlib-zlib.txt`](Zlib-zlib.txt) | https://github.com/madler/zlib |

三处「双许可」的选取：FreeType 用 FTL 而非 GPLv2（后者会传染到整个二进制）；
libjpeg-turbo 的 IJG 与 BSD-3 需同时满足（TurboJPEG 部分两者都适用）；
Wuffs 取 MIT 即可满足，但文件里两份全文都在，随附更稳妥。四份双许可文件都是
上游原样文本，未做删减。

## 来源与校验

全文取自上游仓库主干（经 jsDelivr 镜像取 raw 文件，2026-11-12），逐字照抄，未重排、
未删节。字节数可作为复核锚点，`sha256sum licenses/*.txt` 可复算：

```
 1507  BSD-3-Clause-Skia.txt        1496  BSD-3-Clause-libwebp.txt
 6743  FTL-Freetype.txt             6077  IJG-BSD3-libjpeg-turbo.txt
11372  MIT-Apache2-Wuffs.txt        1144  MIT-Expat.txt
 1971  MIT-HarfBuzz.txt             4388  OFL-NotoSansSC.txt
27718  UnicodeV3-ICU.txt            1002  Zlib-zlib.txt
 5345  libpng-License-v2.txt
```

## 诚实边界

- 取的是**上游当前主干**，不是 M148 构建那一刻的快照。这些许可条款本身多年不变，
  差异只在版权年份区间与措辞微调，新版方向上是「更全」；若日后要出正式 SBOM，
  应按 Skia `DEPS` 锁定版本重取并记录版本号。
- 九个静态库以**二进制**形式随 Skia 分发，本仓不含其源码。因此本仓承担的是
  「随附版权声明与免责声明」这类二进制重分发义务，不承担源码提供义务 ——
  前提是其中没有 GPL/LGPL 类组件。当前清单里没有：FreeType 选的是 FTL。
  若日后把某个库换成 GPL 版本，这条结论立刻失效。
- 组件表与 `cpp/skia/out/Release-x64/*.lib` 实际文件一一对应。新增静态库时，
  本文件与 `licenses/` 必须同步补录，`tools/pack-deps.php` 会把整目录打进包里。
