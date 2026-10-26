<?php

/**
 * Drift 的 Skia 桥接 ABI（§7.2 裁决 1 / §7.3 的 M1 后端之二）。
 *
 * ★ 与 `drift_win32.stub.php` 同一条纪律：每个函数都是**空体**，TypePHP 读它拿签名
 *   并生成 C++ 声明头，实现住在 `cpp-src/drift_skia.cc`，函数名加 `php_` 前缀。
 *   **改一边必须同步另一边** —— 签名不一致时是链接期「静默失败」，不是编译错误。
 *
 * ★ **本桥只画，不建窗、不泵消息、不贴屏**。窗口与贴屏仍归 `drift_win32.cc`：
 *   `SkiaCanvas` 画完之后由 `drift_skia_read_pixels()` 取回 RGBA 字节，
 *   再交给**同一条** `drift_win_present()` 上屏。于是：
 *   ① 贴屏路径只有一处（§7.3 "present 是唯一跨边界的一次"）；
 *   ② `WM_PAINT` 重贴缓存的逻辑不用在 Skia 侧重写一遍（漏了就是"窗口被遮住再露出来白屏"）。
 *
 * ★ **画布句柄是不透明 int**（§5.1 纪律 1）：C++ 侧持一个句柄表，PHP 侧只负责传递。
 *   句柄 `0` = 无效（创建失败），所有后续调用对 `0` 一律**静默忽略** ——
 *   画布创建失败是"这一帧没画"，不是"应用该崩"（与光标形状同一档：装饰性失败）。
 *
 * ★ **坐标一律是物理像素**（§6.1）：逻辑→物理的换算只有一处，在 PHP 侧的
 *   `Scale::quantizeRect()`（`RasterCanvas` 同款）。本桥不接收 dpr，也不做量化 ——
 *   多一个换算点就多一个取整口径，两个后端就会出现"矩形和文字差半个像素"这类账。
 */

/**
 * 建一张离屏光栅画布，返回句柄；`0` = 失败。
 *
 * `$backgroundRgba` 是 `Color::toArgb()` 的产物（`0xAARRGGBB`）——
 * **必须给**：Skia 的 bitmap 初值不确定，不清底色的话窗口会是黑的/花的，
 * 而布局与绘制完全正确 ⇒ 现象像"渲染没跑"（`RasterCanvas` 踩过同款）。
 */
function drift_skia_canvas_create(int $width, int $height, int $backgroundRgba): int {}

/** 释放画布。对 `0` 幂等；重复释放同一个句柄是**编程错误**（会命中已释放的槽位检查并忽略） */
function drift_skia_canvas_destroy(int $canvas): void {}

/**
 * 压入裁剪矩形（与当前裁剪求交，**物理像素**）。与 `popClip()` 必须配对。
 *
 * ★ **`save` / `restore` / `translate` 不在本 ABI 里**，是有意的：
 *   变换与量化由 PHP 侧算（§6.1 "逻辑→物理只走 `Scale::quantizeRect()`，唯一量化点"），
 *   本桥收到的**永远是设备坐标**。若让 Skia 自己也持一份变换，两个后端就会出现
 *   "先加偏移再取整"与"先取整再加偏移"的差异 —— 那种半个像素的账最难查。
 *   `pushClip` / `popClip` 是例外：Skia 的裁剪**是 save 状态的一部分**，
 *   所以它们内部各做一次 `save()` / `restore()`（配对，栈深平衡）。
 */
function drift_skia_push_clip(int $canvas, float $x, float $y, float $w, float $h): void {}

function drift_skia_pop_clip(int $canvas): void {}

/**
 * 画一个轴对齐矩形（物理像素）。
 *
 * `$radius` 是圆角半径（`0` ⇒ 直角）；`$stroke` 为真 ⇒ 描边（`$strokeWidth` 生效），
 * 为假 ⇒ 填充。`$rgba` 是 `Color::toArgb()`（`0xAARRGGBB`，alpha 参与 source-over 混合）。
 */
function drift_skia_draw_rect(
    int $canvas,
    float $x,
    float $y,
    float $w,
    float $h,
    float $radius,
    int $rgba,
    bool $stroke,
    float $strokeWidth,
): void {}
/**
 * 椭圆（外接矩形内切）。PHP 侧负责逻辑→设备量化与偏移叠加（§6.1），
 * 桥收到的永远是设备坐标；strokeWidth 不乘 dpr，与 draw_rect 同口径。
 */
function drift_skia_draw_ellipse(
    int $canvas,
    float $x,
    float $y,
    float $w,
    float $h,
    int $rgba,
    bool $stroke,
    float $strokeWidth,
): void {}

/**
 * 线段（两端点，设备坐标）。只支持描边；宽度口径同 draw_rect。
 */
function drift_skia_draw_line(
    int $canvas,
    float $x1,
    float $y1,
    float $x2,
    float $y2,
    int $rgba,
    float $strokeWidth,
): void {}

/**
 * P3 · 把当前渐变源写入画布（线性 / 径向）。坐标已是**设备像素**
 * （PHP 侧按 `toDevice` 同变换把逻辑端点转设备，与 draw 函数收到的坐标一致）。
 * 最多 4 个色标：$c0..$c3 是 `Color::toArgb()`，$s0..$s3 是升序 [0,1] 位置；
 * $n 为实际色标数（>4 截断、<2 按 2 处理）。每笔 draw 之前设、之后 clear_source。
 */
function drift_skia_set_gradient(
    int $canvas,
    int $radial,
    float $x1, float $y1, float $x2, float $y2,
    int $n, int $c0, int $c1, int $c2, int $c3,
    float $s0, float $s1, float $s2, float $s3,
): void {}

/** P3 · 把当前虚线源写入画布（$on/$off/$phase 均为逻辑像素）。 */
function drift_skia_set_dash(int $canvas, float $on, float $off, float $phase): void {}

/** P3 · 清空画布当前源（每笔 draw 之后调用，避免源泄漏到下一笔）。 */
function drift_skia_clear_source(int $canvas): void {}

/**
 * P4 · 矢量路径填充 / 描边（设备像素）。`$count` 命令数；`$verbs` 每命令 1 字节
 * （MOVE=1 / LINE=2 / QUAD=3 / CLOSE=4）；`$coords` 每命令 4 个 little-endian
 * IEEE754 float（x1,y1,x2,y2）共 16 字节 —— 与 x64 原生 float 同字节序。
 * 渐变 / 虚线走画布级 source（与 draw_rect 同纪律）。
 */
function drift_skia_draw_path(
    int $canvas,
    int $count,
    string $verbs,
    string $coords,
    int $rgba,
    bool $stroke,
    float $strokeWidth,
): int {}

/**
 * P4 · 图像。`$rgba` 行优先 RGBA 字节串（每像素 4 字节，与 `read_pixels` 同格式）；
 * `$w`/`$h` 像素尺寸；`$sx,$sy,$sw,$sh` 源子矩形（仅 `$hasSrc` 为真时用）；
 * `$dx,$dy,$dw,$dh` 目标矩形（设备像素）。整幅缩放 / 子区缩放都在 C++ 侧消化。
 */
function drift_skia_draw_image(
    int $canvas,
    string $rgba,
    int $w,
    int $h,
    float $sx, float $sy, float $sw, float $sh,
    float $dx, float $dy, float $dw, float $dh,
    bool $hasSrc,
): void {}

/**
 * P4 · 阴影（投影形状几何 + 偏移 + 模糊 + 浓度）。`$x,$y,$w,$h` 被投影矩形（设备像素）；
 * `$argb` 阴影色（alpha = 浓度）；`$radius` 被投影形状圆角；`$dx,$dy` 偏移；
 * `$blur` 模糊半径（0 ⇒ 硬边）。
 */
function drift_skia_draw_shadow(
    int $canvas,
    float $x, float $y, float $w, float $h,
    int $argb, float $radius, float $dx, float $dy, float $blur,
): void {}

/** P4 · 压入合成层（整体不透明度 0..1，设备像素无关）。与 `pop_layer()` 配对。 */
function drift_skia_push_layer(int $canvas, float $opacity): void {}

/** P4 · 弹出合成层（canvas->restore()）。 */
function drift_skia_pop_layer(int $canvas): void {}

/**
 * 模糊**背景**（毛玻璃底）：`(x,y,w,h)` 设备像素内的"已画内容"被模糊，之后画的
 * 一切保持清晰。一次性操作，无配对（不进栈深度计数）。
 * `$sigma <= 0` / 矩形退化 ⇒ 不做任何事。
 */
function drift_skia_blur_backdrop(
    int $canvas,
    float $sigma,
    float $x, float $y, float $w, float $h,
): void {}

/**
 * P4 · 压入路径裁剪（与当前裁剪求交，单设备像素）。复用裁剪栈，弹栈仍用 `pop_clip()`。
 * `$verbs`/`$coords` 打包格式同 `draw_path`。
 */
function drift_skia_push_clip_path(
    int $canvas,
    int $count,
    string $verbs,
    string $coords,
): void {}

/**
 * 画**一个字形**，位置由 PHP 侧的排版给出（`penX` 是笔位、`baselineY` 是基线）。
 *
 * ★ 为什么是"一个字形一次调用"而不是"整段一次"：**几何必须来自 PHP**。
 *   §7.2 写着"段的几何（baseline / offsetX）是排版给的，画布只负责施加，不重算" ——
 *   若让 Skia 自己塑形，步进就换了一套（Skia 的 HarfBuzz 度量 ≠ Drift 的度量）
 *   ⇒ 断行位置、光标、命中全部对不上。代价是每帧 N 次跨边界（N = 字形数），
 *   这是 M1 的**已知代价**，批量化（一次传打包缓冲）留到有实测需求时再做。
 *
 * ★ `$family` / `$bold`：`family` 当前**未使用**（Drift 的确定性字体没有真实对应物，
 *   一律用目录字体），留着是为了 M4 的 `SkiaTextMetricsProvider` 落地时不用改 ABI。
 *   `$bold` 现在就用（选 Regular / Bold 字面）。
 *
 * ★ 字号是**物理像素**（= 逻辑字号 × dpr）—— 字形光栅在设备分辨率上做（§8.2 坑 2）。
 */
function drift_skia_draw_glyph(
    int $canvas,
    string $family,
    bool $bold,
    float $fontSize,
    float $penX,
    float $baselineY,
    int $codepoint,
    int $rgba,
): void {}

/**
 * 取回本帧像素：**逐像素 RGBA、自上而下、无行填充**（与 `RasterCanvas::toRgba()` 同格式）。
 *
 * ★ 与 `drift_win_present()` 的入参**逐字同格式**，所以取回之后直接转交即可 ——
 *   不需要在 PHP 侧再做一次 RGBA↔BGRA 换算（那是壳的私事，见 `drift_win32.cc`）。
 */
function drift_skia_read_pixels(int $canvas): string {}

/**
 * 目录字体是否已就绪（诊断用）。
 *
 * ★ 字体加载失败**不中止**（与 `Errors::installFatalBox` 同档：它是渲染能力问题，
 *   不是应用状态问题），但必须**可观测** —— 否则现象是"矩形都画对了、字全没了"，
 *   看起来像排版 bug。`SkiaCanvas` 在首次 `drawParagraph` 时问一次并记 Diagnostic。
 */
function drift_skia_font_ready(): bool {}
