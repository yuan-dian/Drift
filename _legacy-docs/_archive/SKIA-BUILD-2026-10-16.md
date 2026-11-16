# Skia 构建 / 使用 / 体积 —— 实测调研报告

> **调研对象**：`D:\TypePHP\GUI`（实验项目）的 Skia 集成。
> **调研方式**：读配置（`apps/demo/project.yml`）+ 读桥接源码（`cpp-src/gui_gfx.cc`）+ **自建最小 Skia 程序实测三档体积** + `/MT` 真机构建。
> **日期**：2026-10-16。**结论按"今天能不能用"排序**；未实测的一律标 ⏳。
> **产出物**：本文（报告）· `build/base.yml` 的 M4 Skia 追加项（已按本文校正）· `DESIGN.md §8.3 / §16.4 / §24.2 #6 / #12`。

---

## 0 一页结论

| 问题 | 结论 | 判据 |
|------|------|------|
| GUI 怎么拿到 Skia | **预编译包**，不是自己构建 | `cpp/skia/` 里有 `include/` + `modules/` + `src/` + `out/Release-x64/*.lib`（约 **141 MB**），但**仓内没有 `gn` / `ninja` / `args.gn`** ⇒ 没有任何"从源码构建"的痕迹 |
| 这个包是什么 CRT | **`/MT`（静态 CRT）** | `dumpbin -directives` 显示 obj 里是 `FAILIFMISMATCH:RuntimeLibrary=MT_StaticRelease` |
| Drift 要不要重建 Skia | **不要** —— 直接用这个包 | tpc 的 `/MD` 写在 `cxxflags` **之前**（`Msvc.php:104`）⇒ 用户 `cxx-flags` 里的 `/MT` **能覆盖**；且 `php8ts` 在本环境是 **DLL** ⇒ 跨 DLL 边界不共享 CRT。**已实测**：`/MT` 真机构建 `hello.exe` 成功 |
| 这个方式是否最优 | **是**（对当前形态）。替代方案见 §5 | 逐条评估：源码重建（贵、无收益）/ 换包（无源）/ 去 ICU（不可行）/ 去 SVG（仅省 0.52 MB） |
| 包体多大 | 最小程序实测：core **4.03 MB** → +文本栈 **5.89 MB** → +SVG **6.41 MB** | 见 §4；对照无 Skia 的 `users.exe` **2.90 MB** |
| 最大的坑 | ① **ICU 甩不掉** ② **`icudtl.dat` 必须随 exe 分发** ③ **Skottie 实际不可用** ④ 预编译包**不是官方主干快照**（API 有缺） | 见 §3 |

---

## 1 构建流程

### 1.1 形态：预编译包 + 按依赖序直链 21 个 lib

GUI 的 `apps/demo/project.yml` 是**真机构建通过**的权威样例。它的 Skia 部分只有四件事：

1. **include 根**：`-I cpp/skia`（一个就够 —— 包内头按 `include/core/...` 与 `modules/skparagraph/...` 两种前缀写）。
2. **CRT 对齐**：`/MT`（匹配包的静态 CRT）。
3. **链接清单**：`out/Release-x64/` 下 **21 个 `.lib`**，**按依赖顺序**（依赖者在前）。
4. **运行期数据**：`icudtl.dat` + `cpp/fonts/*.ttf`，**按相对路径查找 ⇒ exe 必须以仓库根为 cwd 启动**。

### 1.2 关键项逐条（原文核过）

```yaml
cxx-std: c++20          # ★ 必须 —— SkParagraph 私有头 src/base/SkMathPriv.h 用
                        #   std::countl_zero/countr_zero（<bit>，C++20 起才有），
                        #   被 modules/skunicode/include/SkUnicode.h 间接包含 ⇒ C++17 报 C2039
cxx-flags:
  - /utf-8
  - /wd4267
  - /FS
  - /MT                 # 静态 CRT，匹配包的 CRT（见 §1.3 与 §3.1）
  - /DUSE_SKIA          # 打开桥接里的 Skia 分支（否则退化 GDI 路径）
  - /I"cpp/skia"        # 相对 **cwd（仓库根）** —— cxx-flags 不经 tpc 路径解析
  - /DGR_GL_FUNCTION_TYPE=__stdcall
ld-flags:
  - /STACK:8388608      # AOT 转译后 PHP 函数栈帧远大于 Zend 虚拟栈
  # 21 个 Skia lib（按依赖序，依赖者在前）：
  #   skia · skunicode_icu · icu · skcms · expat · freetype2 · harfbuzz · libpng ·
  #   zlib · libjpeg · libwebp · sksg · skshaper · skresources · skparagraph ·
  #   skunicode_core · skottie · svg · wuffs · bentleyottmann · jsonreader
  # 系统库：
  - d3d12.lib / dxgi.lib / d3dcompiler.lib / windowscodecs.lib   # Skia D3D 后端 + 图片
  - user32 / gdi32 / opengl32 / msimg32 / dwrite / gdiplus / ole32 / shell32 / comdlg32 / imm32
  - advapi32.lib        # ★ ICU 的 Windows 时区探测（icu.wintz.obj 调 RegOpenKeyExW 等）
link-paths:   [../../cpp/skia/out/Release-x64]   # 相对 yml 文件
include-paths: [../../cpp/skia/include, ../../cpp/skia]   # 相对 yml 文件（与 cxx-flags 的 -I 等价）
resource:     # 图标 + 版本信息（tpc 生成 app_resource.rc，用 SDK 的 rc.exe 编译）
manifest:     # comctl32 v6 —— 没有它，原生 MessageBox 是 Win95 外观
```

★ **两类路径基准不同**（GUI 的注释专门强调了这一点）：
`cxx-flags` / `ld-flags` 直接拼进命令行 ⇒ 相对 **cwd**（GUI 的约定是**从仓库根调用**）；
`sources` / `include-paths` / `link-paths` / `build-dir` 经 tpc 路径解析 ⇒ 相对 **yml 文件所在目录**。

★ **exe 落 cwd** ⇒ 从仓库根调用时，双击即用（双击把 cwd 设成 exe 所在目录 = 仓库根，正好满足"资源相对路径"与"ld-flags 相对 cwd"两条）。

### 1.3 CRT：这个包为什么是 `/MT`，以及 Drift 为什么能用

- 包是 **`/MT`**（`MT_StaticRelease`），而本机 PHP 运行时 `php8ts` 是 **`/MD`**。
- **`/MT` 与 `/MD` 对象的 `FAILIFMISMATCH:RuntimeLibrary` 记录不能用任何链接器开关桥接** —— `/NODEFAULTLIB` / `/DEFAULTLIB` 只影响库搜索，改不了这条硬断言。混用即 `LNK2038`。
- **但这条墙只在"同一个链接单元里的静态库"上致命**；跨 **DLL 边界**时两边各有各的 CRT 堆，互不干涉。
- `php8ts` 在本环境是 **DLL**（不是 `.lib`）⇒ Drift 的 exe 与 Skia 静态库**同处一个链接单元**，两边都是 `/MT` ⇒ **一致，可用**。
- 而 tpc 在 `typephp/src/Backend/Msvc.php:104` 无条件追加 `' /MD'`，用户 `cxxflags` 在 `:112` **之后**追加 ⇒ **`cxx-flags` 里写 `/MT` 就能覆盖它**。

**实测**：给 `apps/hello/project.yml` 加 `/MT` 后 `php tools/drift-build.php hello` ⇒ **构建成功**（桥接 obj 记录 `MT_StaticRelease`）。

### 1.4 一条必须记住的边界

★ **`/MT` 换不到"免 CRT / 单文件"**。Drift 的 exe 用 `/MT` 构建后**仍然导入 `VCRUNTIME140.dll`**（因为 `php8ts.dll` 是 `/MD`，它自带那半边）。GUI 的 exe 也一样。⇒ **"加 `/MT` 能省掉 VC 运行时依赖"是错的**；加 `/MT` 的唯一理由是**匹配 Skia 包**。

---

## 2 使用方式（代码怎么用）

出处：`cpp-src/gui_gfx.cc`（2301 行）+ `gui_win32.cc`（2108 行）。以下是 Drift 要照搬的骨架。

### 2.1 表面：光栅直画到自有 bitmap

```cpp
SkBitmap bmp;
bmp.allocN32Pixels(w, h);
SkCanvas* c = SkCanvas::MakeRasterDirectN32(w, h, (SkPMColor*)bmp.getPixels(), bmp.rowBytes());
```

### 2.2 字体：目录字体管理器（确定性，不依赖系统）

```cpp
sk_sp<SkFontMgr> mgr = SkFontMgr_New_Custom_Directory("cpp/fonts");
// 配 NotoSansSC-Regular.ttf(10.5 MB) / NotoSansSC-Bold.ttf(10.6 MB)
```

★ 这是**确定性**的关键：不查系统字体 ⇒ 换机器不改排版。代价是每个字重 ~10 MB。

### 2.3 文本：SkParagraph（注意两处 API 细节）

```cpp
skia::textlayout::FontCollection fc;
fc.setDefaultFontManager(mgr);
fc.setAssetFontManager(mgr);              // ★ 两个都要设

auto unicode = SkUnicodes::ICU::Make();   // ★ 可能返回 null（见 §3.2）
auto ps = skia::textlayout::ParagraphStyle();
auto builder = skia::textlayout::ParagraphBuilder::make(ps, fc, unicode);
builder->pushStyle(textStyle);
builder->addText(str, len);
builder->pop();                           // ★ 是 pop()，不是 popStyle()
auto para = builder->Build();
para->layout(maxWidth);
para->paint(canvas, x, y);
```

★ **`ParagraphBuilderImpl` 里有 `check(fUnicode)`** ⇒ `unicode` 为 null 时 **abort**（不是降级）。

### 2.4 贴屏：`SetDIBitsToDevice`

`guiBlitToScreen()` 把 `bmp` 的像素整块送到窗口 DC。Drift 的 M1 软光栅走的是同一条路（`RasterCanvas` + `SetDIBitsToDevice`）⇒ **换 Skia 只换"画什么"，不换"怎么贴"**。

### 2.5 其它用到的模块

`SkSVGDOM`（6 处）、`SkGradient` / `SkShaders` / `SkImageFilters` / `SkPathBuilder` / `SkRRect`。

★ **`drift-preview` 的 SVG 输出 ≠ 运行时 SVG 渲染**：前者是 PHP 侧生成的"位图 runs"，与 `SkSVGDOM` 无关，别把两者当一回事。

---

## 3 坑（全部实测）

### 3.1 坑 1 —— CRT 死结（最容易第一天就撞）

- **现象**：吃 `/MT` 的预编译 Skia 进 `/MD` 工程 ⇒ `LNK2038: mismatch detected for 'RuntimeLibrary'`。
- **根因**：`FAILIFMISMATCH` 记录烘进每个 obj，链接器旗标改不了。
- **解法（两条，别混）**：① 让宿主与包同 CRT（Drift 选这条：加 `/MT`）；② 从源码重建 `/MD` Skia（**本包没有源码构建链**，且要装 depot_tools + 数小时）。
- ★ **判据**：`dumpbin -directives <obj>` 看 `RuntimeLibrary` 记录；`dumpbin -dependents <exe>` 看是否仍导入 `VCRUNTIME140.dll`。

### 3.2 坑 2 —— ICU 是**运行期硬依赖**，且甩不掉

- **现象**：不把 `icudtl.dat` 放到 exe 工作目录 ⇒ 启动即 abort。
- **根因链**：`SkUnicodes::ICU::Make()` 找不到数据文件 ⇒ 返回 **null** ⇒ `ParagraphBuilderImpl` 的 `check(fUnicode)` **abort**。
- **为什么甩不掉**：`skshaper.lib` 内部（`SkShaper_harfbuzz.obj` / `SkShaper_skunicode.obj`）**硬引用 `SkUnicodes::ICU::Make()`** ⇒ 用 SkParagraph 就必须链 `skunicode_icu` + `icu`（实测：不链即 `LNK2019: SkUnicodes::ICU::Make`）。
- **数据文件位置**：ICU 按 **cwd** 找（不是 exe 所在目录）⇒ 从别处启动就找不到。
- **⏳ 未解决**：能否把 `icudtl.dat` 内嵌进 exe？`icu.lib` 里**有** `udata_setCommonData`（grep 命中 5 次），但 **ICU 的公开头没有随包分发**（`cpp/skia/include/unicode/` 为空），且 `SkUnicode_icu.h` **只暴露 `Make()`** ⇒ 当前**没有干净路径**，要自建"把文件字节注入 PE 资源"的机制（未实测）。

### 3.3 坑 3 —— Skottie 名义上有 lib，实际不可用

- **现象**：`#include <modules/skottie/...>` ⇒ `C1083: 无法打开包括文件 "modules/skottie/src/SkottieValue.h"`。
- **根因**：`modules/skottie/include/SlotManager.h` 要一个**私有头**（`src/` 下），而包只带了公开 `include/`。
- **解法**：**别用 Skottie**（把 `skottie.lib` 从链接清单删掉 —— 它本来也没人引用，属于空转）。

### 3.4 坑 4 —— 预编译包**不是官方主干快照**，API 有缺

实测缺两项（写代码时按官方文档写会撞）：

| 缺什么 | 症状 |
|--------|------|
| `SkImage::encodeToData()` | `C2039: "encodeToData": 不是 "SkImage" 的成员` |
| `SkCanvas::rotateRadians()` | 同类（较新 API） |

另有**头位置与直觉不同**：`SkEncodedImageFormat` 在 `include/codec/`，**不是** `include/core/`。

⇒ **纪律**：用这个包时，**先查包里的头**，别按 Skia 官网文档写。

### 3.5 坑 5 —— 只有头、没有实现的后端

| 后端 | 头 | 实现 | 结论 |
|------|----|------|------|
| `SkUnicodes::ICU` | ✅ | ✅ `skunicode_icu.lib`(17.8 MB) | 可用（SkParagraph 唯一能用的后端） |
| `SkUnicodes::Bidi` | ✅ `SkUnicode_bidi.h` | ❌ **全 21 个 lib 都 grep 不到 `SkUnicodes::Bidi::Make`** | **不可用**（实测 `LNK2019`） |
| `SkUnicodes::ICU4X` / `Libgrapheme` | ✅ | ❌ | 不可用 |

⇒ **bidi 没有后端**。若将来要做 RTL，得换包或源码重建。

### 3.6 坑 6 —— 缺 `advapi32.lib` 的 LNK2019 指向 ICU

- **现象**：链 `skunicode_icu.lib` 后报 **5 个** `LNK2019`：`__imp_RegOpenKeyExW` / `RegQueryInfoKeyW` / `RegCloseKey` / `RegEnumKeyExW` / `RegQueryValueExW`。
- **根因**：`icu.wintz.obj` 的 `uprv_detectWindowsTimeZone()` 读注册表。
- **解法**：加 `advapi32.lib`。★ 报错指向 ICU 的 obj，**看不出是"缺系统库"** ⇒ 容易绕圈。

### 3.7 坑 7 —— `c++20` 是硬门槛

`SkParagraph` 的私有头用 `std::countl_zero`（`<bit>`，C++20 起）⇒ `c++17` 报 `C2039`。**链接清单对了但编译不过**时先查这条。

---

## 4 包体大小（实测）

### 4.1 三档阶梯

量法：写**最小可运行程序**（真的调用目标模块），`cl /O2 /MT` + 全清单链接，量 **exe 字节数**。
★ **单位**：本报告体积一律 **MiB**（1 MiB = 1048576 B）。按 10^6 计则三档为 4.23 / 6.18 / 6.72 MB。

| 档 | 内容 | exe |
|----|------|-----|
| **1 core** | Skia 核心光栅 + FreeType + skcms + CRT（`SkCanvas` / `SkBitmap` / `SkFont` / `SkTextBlob` / `SkPathBuilder`，**不含** SkParagraph/SkShaper/ICU） | **4.03 MB** |
| **2 +文本栈** | + SkParagraph + SkShaper + HarfBuzz + **SkUnicode/ICU** | **5.89 MB**（+1.86） |
| **3 +SVG** | + SVG + SkSG + expat | **6.41 MB**（+0.52） |

**对照**：

| 程序 | 体积 |
|------|------|
| Drift `users.exe`（**无 Skia**，软光栅） | **2.90 MB** |
| GUI `typephp_gui_demo.exe`（框架 + Skia 全开） | **12.76 MiB**（≈13.4 MB 十进制） |

### 4.2 两条读法

1. **`.lib` 大小 ≠ 进 exe 的贡献** —— 链接器会 dead-strip 没被引用的代码。`skia.lib` 单个 35 MB，但档 1 的 exe 只有 4 MB ⇒ **只有"真走那条链"的程序才量得准**。
2. **GUI 的 12.76 MiB 主要不是 Skia** —— Skia 部分只 ~5.9 MB，差额约 **7 MB 是框架自身的 PHP→C++ 代码 + 更多特性**（GUI 有 Selftest / TreeDump / 更多页面）。

### 4.3 ICU 的边际成本

**+1.86 MB**（4.03 → 5.89，含 HarfBuzz + SkShaper + SkParagraph + ICU 整条链），且**不可省**（§3.2）。⇒ **"自实现一个最小 ICU 断行器"省不到 1 MB，不值得。**

### 4.4 对 Drift 的预测

| 形态 | 预测体积 |
|------|----------|
| 现状（软光栅，无 Skia） | 2.90 MB（实测） |
| + Skia + 文本栈（SkParagraph/ICU） | **≈ 8.8 MB** |
| + SVG/SkSG | **≈ 9.3 MB** |

外加**必须分发**的 `icudtl.dat`（778 KB）+ 字体（若用 Noto Sans SC 两个字重约 21 MB；Drift 的确定性测试字体是自有点阵，**不适用**）。

---

## 5 这个方式是否最优 / 有没有更优选择

| 方向 | 判断 | 依据 |
|------|------|------|
| **直接用预编译包（不重建）** | ✅ **可行且最优** | §1.3 实测 `/MT` 可用；源码重建要 depot_tools + 数小时 + 数 GB，**换不到任何东西**（Drift 不需要 `/MD`） |
| 换一个"官方 `/MD` 预编译包" | ❌ **无源** | Skia 官方不发布 Windows 预编译包（只有 `libskia.a` 的 macOS/iOS 形态） |
| 从源码重建 `/MD` Skia | ❌ 无收益 | 只有"宿主必须是 `/MD` 且不能改"时才需要（那是 DLL 形态）；Drift 是 bin 形态，能改 |
| `/MT` 换"单文件 / 免 CRT" | ❌ **换不到** | §1.4：exe 仍导入 `VCRUNTIME140.dll`（`php8ts.dll` 是 `/MD`） |
| 去掉 ICU（改用 `Bidi` 后端） | ❌ **不可行** | §3.5：包里**没有** `Bidi` 实现；且 `skshaper.lib` 硬引 `ICU::Make` |
| 自实现最小 ICU / 断行 | ❌ 不值得 | §4.3：省不到 1 MB |
| 把 `icudtl.dat` 内嵌进 exe | ⚠️ **方向对、路径不通** | §3.2：`udata_setCommonData` 在，但头未随包 ⇒ 要自建注入机制（未实测，登记 §24.2 #6） |
| **只上文本 + 矢量，不上 SVG** | ✅ 成立 | 只值 **+0.52 MB**，但 Drift 当前**没有 SVG 需求** ⇒ 先不加，需要时再加 |
| 删 `skottie` / 未用的 lib | ✅ 无害 | §3.3：Skottie 本来不可用；其余是空转（dead-strip） |
| 把 Skia 换成"平台原生文本栈"（DirectWrite） | ❌ **与采用 Skia 的理由冲突** | 换平台文本栈 ⇒ 排版随机器变，"处处同一张 PNG"的确定性目标落空 —— 而那通常正是采用 Skia 的理由 |

### 5.1 一句话

> **GUI 的做法（预编译 `/MT` 包 + 按依赖序直链 + 确定性目录字体）是当前形态下最优解。**
> 真正的代价不在"怎么构建"，而在 **ICU 甩不掉** 与 **`icudtl.dat` 要随 exe 走**这两件事上 —— 而这两件**换任何构建方式都躲不开**。

---

## 6 对 Drift 的落地建议（M4 `SkiaCanvas`）

1. **不重建 Skia** —— 直接引用上游包的 `include/` 与 `out/Release-x64/*.lib`。包的**分发方式**另定（约 141 MB，**不宜入库**）⇒ §24.2 #12。
2. **`build/base.yml` 的追加项按本文校正**（已改）：`c++20` / `/MT` / `/DUSE_SKIA` / `/I"cpp/skia"` / `GR_GL_FUNCTION_TYPE` / 20 个 lib（**去掉 `skottie`**）/ `advapi32.lib`。
3. **链接清单可从 21 裁到约 12**（只用文本 + 矢量时）：`skia · skunicode_icu · icu · skcms · freetype2 · harfbuzz · skshaper · skparagraph · skunicode_core · expat · zlib · libpng`（其余按需加）。
4. **运行期**：`icudtl.dat` 先按"与 exe 同目录"分发（**不阻塞**）；"内嵌"登记为待验。
5. **代码照 `gui_gfx.cc` 的三个函数搬**：`ensureFont()`（字体管理器）/ `buildParagraph()`（段落，注意 `pop()` 与 `check(fUnicode)`）/ `php_gfx_create_surface`（表面）。
6. **先做体积预算再决定要不要 SVG** —— 文本档 ≈ 8.8 MB 已可接受，SVG 档 +0.52 MB 随时可加。

---

## 附：本次实测的可复现产物

均在 `_build/skia-size/`（**被 gitignore，不入库**）：

| 文件 | 说明 |
|------|------|
| `main_core.cc` | 档 1（核心光栅 + FreeType，不含排版栈） |
| `main.cc` | 档 2（+ SkParagraph / SkUnicode-ICU / HarfBuzz） |
| `main_full.cc` | 档 3（+ SVG / SkSG / expat；已去掉 Skottie 与图片编码） |
| `main_bidi.cc` | `Bidi` 后端试验（**链接失败 = 该后端不在包里**） |
| `build.sh` | 编译 + 链接 + 运行 + 量体积的可复现脚本（含 `advapi32.lib` 那一行） |
| `skia-core.exe` / `skia-size.exe` / `skia-full.exe` | 三档产物（4.03 / 5.89 / 6.41 MB） |

> ★ 这些是**一次性探针**，与 `probes/` 同理不入库；结论已写进本文与 `DESIGN.md`。
