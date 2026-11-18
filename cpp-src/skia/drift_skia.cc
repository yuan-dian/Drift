/**
 * Drift 的 Skia 光栅后端（§7.2 裁决 1 的第三个 `Canvas` 实现）。
 *
 * 与 PHP 的边界见 `cpp-src/drift_skia.stub.php`。本文件**只做三件事**：
 *   ① 建/释放离屏 Skia 画布（`SkBitmap` + `SkCanvas::MakeRasterDirectN32`）；
 *   ② 把 PHP 的绘制命令施加到画布上（矩形 / 圆角 / 描边 / 裁剪 / 平移 / 字形）；
 *   ③ 把画布像素取回成 RGBA 字节，交给平台壳的 present（Win32 / X11 共用这一份桥）。
 *
 * ★ **不建窗、不泵消息、不贴屏**：那三件是 `drift_win32.cc` 的职责。贴屏只有一处
 *   （§7.3 "present 是唯一跨边界的一次"），`WM_PAINT` 重贴缓存的逻辑因此不用重写。
 *
 * ★ **几何一律来自 PHP**：本文件不含任何排版逻辑 —— 段落的行基线、笔位都是
 *   `Paragraph` 算好后传进来的（§7.2 "画布只负责施加，不重算"）。若让 Skia 自己
 *   塑形，步进会换成 HarfBuzz 的度量，与 Drift 的度量对不上 ⇒ 断行/光标/命中全错。
 *   ⇒ 所以字形是**逐个**画的（每个都带 PHP 给的位置），不是"传一段文本让 Skia 排"。
 *
 * ★ **本后端不链 SkParagraph / SkUnicode / ICU**（M1 范围）：不需要 `icudtl.dat`，
 *   也不需要那 5 个注册表符号。真字体重排版（`SkiaTextMetricsProvider`）归 M4。
 *
 * ★ 字体用**目录字体**（`cpp/fonts/NotoSansSC-*.ttf`），与 GUI 参考实现同款 ——
 *   确定性来自"不查系统字体"，不是来自"用位图字体"。加载失败**不中止**，
 *   但 `drift_skia_font_ready()` 会如实回答 false，让 PHP 侧记一条 Diagnostic
 *   （否则现象是"矩形全对、字全没了"，看起来像排版 bug）。
 *
 * ★ **查找顺序（2026-10-17）**：① exe 所在目录的 `fonts/` ② exe 目录的
 *   `cpp/fonts/` ③ 相对 cwd 的 `cpp/fonts` ④ `fonts`。前两条让"把 fonts/ 放到
 *   exe 旁边"这种**分发形态**成立 —— 双击 / 快捷方式 / 从任意目录启动都行。
 *   （翻默认后端之前这里只有 ③④，等于暗含"cwd 必须是仓库根"，而那个前置
 *   一旦不成立，症状是"界面全白、布局全对"，最难查。）
 */

#define NOMINMAX

#include <phpx.h>
#ifdef _WIN32
// ★ 必须在 phpx.h **之后**（与 drift_win32.cc 同序）：phpx.h 拉进来的
//   php_network.h 依赖 winsock2 的 `timeval`，而 windows.h 先到会把旧
//   winsock.h 带进来 ⇒ C2027 未定义类型 timeval（实测）。
#include <windows.h>
#endif

#include <cstdint>
#include <cstring>
#include <map>
#include <memory>
#include <string>
#ifndef _WIN32
#include <unistd.h>
#endif

#include "include/core/SkBitmap.h"
#include "include/core/SkBlurTypes.h"
#include "include/core/SkCanvas.h"
#include "include/core/SkColor.h"
#include "include/core/SkFont.h"
#include "include/core/SkFontMgr.h"
#include "include/core/SkImage.h"
#include "include/core/SkImageInfo.h"
#include "include/core/SkMaskFilter.h"
#include "include/core/SkPaint.h"
#include "include/core/SkPath.h"
#include "include/core/SkPathBuilder.h"
#include "include/core/SkPixmap.h"
#include "include/core/SkRRect.h"
#include "include/core/SkRect.h"
#include "include/core/SkTileMode.h"
#include "include/core/SkTypeface.h"
#include "include/effects/SkGradient.h"
#include "include/effects/SkDashPathEffect.h"
#include "include/effects/SkImageFilters.h"
#include "include/ports/SkFontMgr_directory.h"

using namespace php;

namespace {

// ───────────────────────────────────────────────────────────── 句柄表 ──

/**
 * 一张离屏画布。`bitmap` 必须在 `canvas` 之前构造、之后析构 ——
 * `MakeRasterDirectN32` 是**直接指向** `bitmap` 的像素内存，不持有它。
 */
struct SkiaSlot {
    SkBitmap bitmap;
    std::unique_ptr<SkCanvas> canvas;
    // ★ P3 渐变/虚线：画布级的"当前画笔源"，由 set_gradient/set_dash 写入、
    //   各 draw 函数读取（§7.2 D2 表：渐变/虚线是 paint source 属性，不是新 draw 方法）。
    sk_sp<SkShader> shader;
    sk_sp<SkPathEffect> dash;
};

/** 句柄 → 槽位。句柄从 1 起（0 保留给"无效"），只增不减 ⇒ 不会复用到已释放的槽位 */
std::map<int, SkiaSlot *> g_slots;
int g_nextHandle = 1;

/**
 * 表面复用缓存（`26` §5.4 修法 3）：1 深，按尺寸命中。
 *
 * 稳态下每帧 create/destroy 同一尺寸 ⇒ `allocN32Pixels`（1280x800 时
 * 4 MB）的分配与首次触页全部省掉，只留画布状态复位 + clear 底色。
 * resize 中尺寸连变：每个 miss 帧照常分配，同时把上一个不同尺寸的
 * 缓存释放 —— 不多留内存，也不比现状慢。
 */
SkiaSlot *g_recycleSlot = nullptr;
int g_recycleW = 0;
int g_recycleH = 0;

SkiaSlot *slotFor(Int handle) {
    std::map<int, SkiaSlot *>::iterator it = g_slots.find((int)handle);
    return it == g_slots.end() ? NULL : it->second;
}

SkCanvas *canvasFor(Int handle) {
    SkiaSlot *slot = slotFor(handle);
    return slot == NULL ? NULL : slot->canvas.get();
}

/**
 * P3：把画布当前的 paint source（渐变 shader / 虚线 path effect）施加到一笔上。
 *
 * ★ 必须定义在匿名命名空间**内部**：三个 draw 函数也在这里面，而文件在
 *   `} // namespace` 之后只剩 `php_drift_skia_*` 导出函数（要按 C 名链接）⇒ 放在外面
 *   会变成另一个实体，编译期报 C2129「已声明但未定义」。
 * ★ 虚线只作用于描边（填充挂 path effect 无意义，Skia 下行为未定义）。
 */
static void applyPaintSource(SkiaSlot *slot, SkPaint *paint) {
    if (slot == NULL) {
        return;
    }
    if (slot->shader) {
        paint->setShader(slot->shader);
    }
    if (slot->dash && paint->getStyle() == SkPaint::kStroke_Style) {
        paint->setPathEffect(slot->dash);
    }
}

// ─────────────────────────────────────────────────────────────── 字体 ──

/** 相对 cwd 的兜底（旧约定：exe 放在仓库根、以仓库根启动） */
const char *const kFontDirs[] = { "cpp/fonts", "fonts" };

sk_sp<SkFontMgr> g_fontMgr;
sk_sp<SkTypeface> g_faceRegular;
sk_sp<SkTypeface> g_faceBold;
bool g_fontProbed = false;
bool g_fontReady = false;

/**
 * exe 所在目录（**含尾部路径分隔符**）；取不到时返回空串。
 *
 * ★ Windows 用 `GetModuleFileNameA`（ANSI）而不是 `W`：`SkFontMgr_New_Custom_Directory`
 *   收的是 `const char*`，Windows 侧按 ANSI 代码页解析 ⇒ 两者编码口径一致。
 *   换成 UTF-8 反而会在非 ASCII 路径上出现"传进去的字节和 Skia 解析的口径不同"。
 * ★ Linux 用 `/proc/self/exe`，语义与 Windows 侧一致（定位**当前 ELF**，不是 cwd）。
 *   两条路都只在 `ensureFont()` 里走一次，失败就退回 cwd 相对候选。
 */
#ifdef _WIN32
std::string exeDirA() {
    char buf[MAX_PATH * 4];
    DWORD n = GetModuleFileNameA(NULL, buf, (DWORD)sizeof(buf));
    if (n == 0 || n >= (DWORD)sizeof(buf)) {
        return std::string();
    }
    while (n > 0 && buf[n - 1] != '\\' && buf[n - 1] != '/') {
        n--;
    }
    return std::string(buf, (size_t)n);
}
#else
std::string exeDirA() {
    char buf[4096];
    ssize_t n = readlink("/proc/self/exe", buf, sizeof(buf) - 1);
    if (n <= 0) {
        return std::string();
    }
    buf[n] = '\0';
    while (n > 0 && buf[n - 1] != '/') {
        n--;
    }
    return std::string(buf, (size_t)n);
}
#endif

/**
 * 懒加载目录字体。**只试一次**（失败也记着试过了）——
 * 每帧重试会变成"每次绘制都扫一遍目录"。
 *
 * ★ 候选顺序见文件头：exe 目录优先，cwd 相对路径兜底。`SkFontMgr_New_Custom_Directory`
 *   对不存在的目录**不返回 null**（返回一个扫不到字体的 mgr）⇒ 判据必须落在
 *   `makeFromFile` 的返回值上，不能只看 mgr。
 */
void ensureFont() {
    if (g_fontProbed) {
        return;
    }
    g_fontProbed = true;

    std::string candidates[4];
    int count = 0;

    const std::string exe = exeDirA();
    if (!exe.empty()) {
        candidates[count++] = exe + "fonts";
        candidates[count++] = exe + "cpp/fonts";
    }
    for (int i = 0; i < 2; i++) {
        candidates[count++] = kFontDirs[i];
    }

    for (int i = 0; i < count; i++) {
        const std::string &dir = candidates[i];
        sk_sp<SkFontMgr> mgr = SkFontMgr_New_Custom_Directory(dir.c_str());
        if (!mgr) {
            continue;
        }
        sk_sp<SkTypeface> regular = mgr->makeFromFile((dir + "/NotoSansSC-Regular.ttf").c_str());
        if (!regular) {
            continue;
        }
        // 故意保留 mgr：`makeFromFile` 出来的 typeface 由它管理，整体不析构
        g_fontMgr = mgr;
        g_faceRegular = regular;
        g_faceBold = mgr->makeFromFile((dir + "/NotoSansSC-Bold.ttf").c_str());
        g_fontReady = true;
        return;
    }
}

/** 码点 → UTF-8（`drawString` 收 UTF-8，不是码点） */
size_t encodeUtf8(uint32_t cp, char out[4]) {
    if (cp < 0x80u) {
        out[0] = (char)cp;
        return 1;
    }
    if (cp < 0x800u) {
        out[0] = (char)(0xC0u | (cp >> 6));
        out[1] = (char)(0x80u | (cp & 0x3Fu));
        return 2;
    }
    if (cp < 0x10000u) {
        out[0] = (char)(0xE0u | (cp >> 12));
        out[1] = (char)(0x80u | ((cp >> 6) & 0x3Fu));
        out[2] = (char)(0x80u | (cp & 0x3Fu));
        return 3;
    }
    out[0] = (char)(0xF0u | (cp >> 18));
    out[1] = (char)(0x80u | ((cp >> 12) & 0x3Fu));
    out[2] = (char)(0x80u | ((cp >> 6) & 0x3Fu));
    out[3] = (char)(0x80u | (cp & 0x3Fu));
    return 4;
}

}   // namespace

// ★★ **C2129（只在这里会踩）**：上面匿名命名空间里的 static helper
//    （如 `applyPaintSource()`，其 docblock 有详细说明）**定义必须留在 NS 内**。
//    本行之后只剩 `php_drift_skia_*` 导出函数 —— 它们要按 **C 名**链接
//    （PHP 扩展 ABI 不认 C++ mangling）⇒ 若把 NS 内 forward-declare 的
//    static helper 的定义放到这里，会变成"另一个实体"，编译期报
//    **C2129「已声明但未定义」**。
//    ⇒ 加 helper 时的判据：**它被 NS 内的 draw 函数调用 ⇒ 定义也住 NS 内**。
//    （`docs/reference/aot-constraints.md` §6 有这三条错误码的速查表。）

// ══════════════════════════════════════════════════════════ 画布生命周期 ══

Int php_drift_skia_canvas_create(Int width, Int height, Int backgroundRgba) {
    int w = (int)width;
    int h = (int)height;
    if (w <= 0 || h <= 0) {
        return (Int)0;   // 0 尺寸窗口（最小化）不是错误，只是没有可画的东西
    }

    SkiaSlot *slot = nullptr;
    // ★★ 不透明位由**底色 alpha** 决定（不新增参数）：`a == 255` ⇒ 这扇窗本来就全不
    //   透明，`isOpaque = true` 让位图元数据如实（也少一层 dst alpha 混合的可能）。
    //   底色带 alpha ⇒ `isOpaque = false`。
    // ★ **承重的不是这个 flag，而是 `clear()` 的底色**：`MakeRasterDirectN32` 建的画布
    //   是 `N32Premul`（不是 `N32Opaque`）⇒ 混合与 alpha 写入行为**与 flag 无关**；
    //   底色 `a < 255` 时 `clear()`（= `drawColor(kSrc)`）就把半透明**预乘**写进去了。
    //   翻 flag 是为了**元数据诚实**：位图声明不透明却装着 alpha，将来任何走
    //   `bitmap.isOpaque()` 快路径的读取（`readPixels` 转换、`eraseColor`）都会算错。
    const uint32_t bg = (uint32_t)backgroundRgba;
    const bool opaque = ((bg >> 24) & 0xFFu) == 0xFFu;

    if (g_recycleSlot != nullptr && g_recycleW == w && g_recycleH == h
        && g_recycleSlot->bitmap.isOpaque() == opaque) {
        // ★ 复用条件必须带 `isOpaque` 一致：flag 是分配时定的，复用错就是元数据撒谎。
        // ★ 命中复用缓存：bitmap 与 canvas 都活着，只把画布状态复位成"新画布"：
        //   restoreToCount(1) 兜住上一帧可能漏出的 save/clip（正常应已平衡）；
        //   resetMatrix 把 CTM 还原成创建时的单位矩阵（光栅 direct 的初态）。
        slot = g_recycleSlot;
        g_recycleSlot = nullptr;
        slot->canvas->restoreToCount(1);
        slot->canvas->resetMatrix();
    } else {
        slot = new SkiaSlot();
        slot->bitmap.allocN32Pixels(w, h, opaque);
        if (slot->bitmap.getPixels() == NULL) {
            delete slot;
            return (Int)0;
        }

        slot->canvas = SkCanvas::MakeRasterDirectN32(
            w,
            h,
            (SkPMColor *)slot->bitmap.getPixels(),
            slot->bitmap.rowBytes()
        );
        if (!slot->canvas) {
            delete slot;
            return (Int)0;
        }
    }

    // 必须清底色：bitmap 初值不确定，不清的话窗口会是黑的/花的，而布局与绘制全对
    // ⇒ 现象像"渲染没跑"（`RasterCanvas` 在 PHP 侧踩过同款，见 Win32RasterSurface）。
    // ★★ 底色带 alpha 时，**这一行就是逐像素透明的唯一来源**：`clear()` 等价于
    //   `drawColor(color, kSrc)` ⇒ 直接把半透明（预乘后的）值写进去，不与任何东西
    //   混合。应用没画到的像素就保留这个 alpha，壳走 `UpdateLayeredWindow` 时生效。
    slot->canvas->clear((SkColor)(uint32_t)backgroundRgba);

    int handle = g_nextHandle++;
    g_slots[handle] = slot;
    return (Int)handle;
}

void php_drift_skia_canvas_destroy(Int canvas) {
    std::map<int, SkiaSlot *>::iterator it = g_slots.find((int)canvas);
    if (it == g_slots.end()) {
        return;   // 对 0 与未知句柄幂等
    }
    // ★ 不进 delete，进复用缓存：下一帧同尺寸 create 直接取走（`26` §5.4 修法 3）。
    //   缓存只留 1 个 —— 已有不同尺寸的占用就先释放它。
    if (g_recycleSlot != nullptr) {
        delete g_recycleSlot;
    }
    g_recycleSlot = it->second;
    g_recycleW = it->second->bitmap.width();
    g_recycleH = it->second->bitmap.height();
    g_slots.erase(it);
}

// ══════════════════════════════════════════════════════════════ 裁剪 ══

/**
 * ★ `pushClip` = `save()` + `clipRect()`，`popClip` = `restore()`。
 *
 *   这不是"多用了一次 save" —— Skia 的裁剪**是 save 状态的一部分**，不先 save
 *   就没法撤销。而 PHP 侧的调用约定是 `pushClip → save → translate → … →
 *   restore → popClip`（`RenderTextField::paint`），两侧配对后栈深正好平衡。
 *
 * ★ 裁剪**不做抗锯齿**（`doAntiAlias = false`）：溢出裁剪要的是"画不出去"，
 *   软边会让"边界上一个像素的半透明残留"出现在溢出检测的截图里。
 */
void php_drift_skia_push_clip(Int canvas, Float x, Float y, Float w, Float h) {
    SkCanvas *c = canvasFor(canvas);
    if (c == NULL) {
        return;
    }
    c->save();
    c->clipRect(SkRect::MakeXYWH((SkScalar)x, (SkScalar)y, (SkScalar)w, (SkScalar)h), false);
}

void php_drift_skia_pop_clip(Int canvas) {
    SkCanvas *c = canvasFor(canvas);
    if (c != NULL) {
        c->restore();
    }
}

// ════════════════════════════════════════════════════════════ 变换（A1c）══
//
// ★ 为什么这里需要 save/restore/concat（推翻"变换全在 PHP 侧"的老裁决）：
//   见 `drift_skia.stub.php` 的对应段落 —— 一句话，**文字的变换只能靠 CTM**，
//   `draw_paragraph` 只收 (x, y)，字号与字形位图在 C++ 侧决定。

void php_drift_skia_save(Int canvas) {
    SkCanvas *c = canvasFor(canvas);
    if (c != NULL) {
        c->save();
    }
}

void php_drift_skia_restore(Int canvas) {
    SkCanvas *c = canvasFor(canvas);
    if (c != NULL) {
        c->restore();
    }
}

void php_drift_skia_translate(Int canvas, Float dx, Float dy) {
    SkCanvas *c = canvasFor(canvas);
    if (c != NULL) {
        c->translate((SkScalar)dx, (SkScalar)dy);
    }
}

// ★ 形参不叫 a/b/c/d：`c` 会与局部的 SkCanvas* 撞名（C2129 那类"重定义"很难看懂）。
// ★ `setAll` 的行优先参数序是 [scaleX skewX transX / skewY scaleY transY / persp]，
//   与本项目的 `x' = a·x + c·y + tx` 对应关系是 skewX=c、skewY=b —— 写反了画出来是
//   "转反了"，而数值上每个分量都"看起来对"。
void php_drift_skia_concat(
    Int canvas,
    Float ma,
    Float mb,
    Float mc,
    Float md,
    Float mtx,
    Float mty
) {
    SkCanvas *cv = canvasFor(canvas);
    if (cv == NULL) {
        return;
    }
    SkMatrix m;
    m.setAll(
        (SkScalar)ma,
        (SkScalar)mc,
        (SkScalar)mtx,
        (SkScalar)mb,
        (SkScalar)md,
        (SkScalar)mty,
        0,
        0,
        SK_Scalar1
    );
    cv->concat(m);
}

// ════════════════════════════════════════════════════════════════ 绘制 ══

void php_drift_skia_draw_rect(
    Int canvas,
    Float x,
    Float y,
    Float w,
    Float h,
    Float radius,
    Int rgba,
    Bool stroke,
    Float strokeWidth
) {
    SkiaSlot *slot = slotFor(canvas);
    SkCanvas *c = slot == NULL ? NULL : slot->canvas.get();
    if (c == NULL || w <= 0.0 || h <= 0.0) {
        return;
    }

    SkPaint paint;
    paint.setAntiAlias(true);
    // `Color::toArgb()` 的 0xAARRGGBB 就是 SkColor 的布局，直接喂
    paint.setColor((SkColor)(uint32_t)rgba);
    if ((bool)stroke) {
        paint.setStyle(SkPaint::kStroke_Style);
        paint.setStrokeWidth((SkScalar)strokeWidth);
    } else {
        paint.setStyle(SkPaint::kFill_Style);
    }
    // ★ P3：渐变/虚线是 paint source，画布级当前源由 set_gradient/set_dash 写入
    applyPaintSource(slot, &paint);

    const SkRect rect = SkRect::MakeXYWH((SkScalar)x, (SkScalar)y, (SkScalar)w, (SkScalar)h);
    if (radius > 0.0) {
        c->drawRRect(SkRRect::MakeRectXY(rect, (SkScalar)radius, (SkScalar)radius), paint);
    } else {
        c->drawRect(rect, paint);
    }
}

void php_drift_skia_draw_ellipse(
    Int canvas,
    Float x,
    Float y,
    Float w,
    Float h,
    Int rgba,
    Bool stroke,
    Float strokeWidth
) {
    SkiaSlot *slot = slotFor(canvas);
    SkCanvas *c = slot == NULL ? NULL : slot->canvas.get();
    if (c == NULL || w <= 0.0 || h <= 0.0) {
        return;
    }

    SkPaint paint;
    paint.setAntiAlias(true);
    // `Color::toArgb()` 的 0xAARRGGBB 就是 SkColor 的布局，直接喂
    paint.setColor((SkColor)(uint32_t)rgba);
    if ((bool)stroke) {
        paint.setStyle(SkPaint::kStroke_Style);
        paint.setStrokeWidth((SkScalar)strokeWidth);
    } else {
        paint.setStyle(SkPaint::kFill_Style);
    }
    applyPaintSource(slot, &paint);

    c->drawOval(SkRect::MakeXYWH((SkScalar)x, (SkScalar)y, (SkScalar)w, (SkScalar)h), paint);
}

void php_drift_skia_draw_line(
    Int canvas,
    Float x1,
    Float y1,
    Float x2,
    Float y2,
    Int rgba,
    Float strokeWidth
) {
    SkiaSlot *slot = slotFor(canvas);
    SkCanvas *c = slot == NULL ? NULL : slot->canvas.get();
    if (c == NULL) {
        return;
    }

    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor((SkColor)(uint32_t)rgba);
    // 线段只支持描边（Paint::Fill 在 PHP 侧已响亮失败）；宽度口径与
    // draw_rect 的 strokeWidth 一致：逻辑像素，不乘 dpr。
    paint.setStyle(SkPaint::kStroke_Style);
    paint.setStrokeWidth((SkScalar)strokeWidth);
    applyPaintSource(slot, &paint);

    c->drawLine((SkScalar)x1, (SkScalar)y1, (SkScalar)x2, (SkScalar)y2, paint);
}

// ── P4：Optional / Extended 复合 op（drawPath / drawImage / drawShadow / pushLayer / pushClipPath）──

/**
 * 从 PHP 打包的字节流重建一条 SkPath。
 *
 * ★ 打包格式（PHP 侧 `SkiaCanvas::packPath` 约定）：`verbs` 每命令 1 字节
 *   （MOVE=1 / LINE=2 / QUAD=3 / CLOSE=4），`coords` 每命令 4 个 little-endian
 *   IEEE754 float（x1,y1,x2,y2，16 字节）—— 与 x64 原生 float 同字节序，memcpy 即可。
 *   不引数组参数进 ABI（本桥 ABI 只认标量 + string，见 stub 头）。
 * ★ 本仓 Skia 剥掉了 `SkPath` 的构造方法，路径必须用 `SkPathBuilder` 拼，再
 *   `detach()` 成 `SkPath`（Skia 新版的硬约束，不是我们加的）。
 */
static SkPath buildSkPath(const char *verbs, const char *coords, int count) {
    SkPathBuilder builder;
    for (int i = 0; i < count; i++) {
        unsigned char verb = (unsigned char)verbs[i];
        float f[4];
        memcpy(f, coords + (size_t)i * 16u, 16u);
        switch (verb) {
            case 1: builder.moveTo((SkScalar)f[0], (SkScalar)f[1]); break;
            case 2: builder.lineTo((SkScalar)f[0], (SkScalar)f[1]); break;
            case 3: builder.quadTo((SkScalar)f[0], (SkScalar)f[1], (SkScalar)f[2], (SkScalar)f[3]); break;
            case 4: builder.close(); break;
            default: break;
        }
    }
    return builder.detach();
}

/**
 * P4 · 矢量路径填充 / 描边。坐标已是设备像素（PHP 侧按 toDevice 同变换）；
 * 渐变 / 虚线走画布级 source（与 draw_rect 同纪律）。
 */
Int php_drift_skia_draw_path(
    Int canvas,
    Int count,
    String verbs,
    String coords,
    Int rgba,
    Bool stroke,
    Float strokeWidth
) {
    SkiaSlot *slot = slotFor(canvas);
    SkCanvas *c = slot == NULL ? NULL : slot->canvas.get();
    if (c == NULL || (int)count <= 0) {
        return 0;
    }

    SkPath path = buildSkPath(verbs.data(), coords.data(), (int)count);
    // 诊断：pts<=0 说明 C++ 没从字节流重建出几何（PHP 侧已验证字节流正确），
    // 那问题在 buildSkPath 内部。点数正常却无墨则是 paint / 变换问题。
    const int pts = path.countPoints();

    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor((SkColor)(uint32_t)rgba);
    if ((bool)stroke) {
        paint.setStyle(SkPaint::kStroke_Style);
        paint.setStrokeWidth((SkScalar)strokeWidth);
    } else {
        paint.setStyle(SkPaint::kFill_Style);
    }
    applyPaintSource(slot, &paint);

    c->drawPath(path, paint);
    return pts;
}

/**
 * P4 · 图像。RGBA 字节串（行优先，每像素 4 字节，与 `toRgba()` 同格式）在 C++ 侧
 * 重建为 SkImage 再画。`$hasSrc` 为假 ⇒ 整幅缩放进 `$dst`。
 */
void php_drift_skia_draw_image(
    Int canvas,
    String rgba,
    Int w,
    Int h,
    Float sx, Float sy, Float sw, Float sh,
    Float dx, Float dy, Float dw, Float dh,
    Bool hasSrc
) {
    SkiaSlot *slot = slotFor(canvas);
    SkCanvas *c = slot == NULL ? NULL : slot->canvas.get();
    int iw = (int)w, ih = (int)h;
    if (c == NULL || iw <= 0 || ih <= 0) {
        return;
    }
    size_t need = (size_t)iw * (size_t)ih * 4u;
    if ((size_t)rgba.length() < need) {
        return; // 像素数不足 ⇒ 整幅错位，响亮跳过（与 ImageHandle 构造期校验同判据）
    }

    SkImageInfo info = SkImageInfo::Make(iw, ih, kRGBA_8888_SkColorType, kUnpremul_SkAlphaType);
    SkPixmap pixmap(info, rgba.data(), (size_t)iw * 4u);
    sk_sp<SkImage> img = SkImages::RasterFromPixmapCopy(pixmap);
    if (!img) {
        return;
    }

    SkRect dst = SkRect::MakeXYWH((SkScalar)dx, (SkScalar)dy, (SkScalar)dw, (SkScalar)dh);
    if ((bool)hasSrc) {
        SkRect src = SkRect::MakeXYWH((SkScalar)sx, (SkScalar)sy, (SkScalar)sw, (SkScalar)sh);
        c->drawImageRect(img.get(), src, dst, SkSamplingOptions(), nullptr, SkCanvas::kFast_SrcRectConstraint);
    } else {
        c->drawImageRect(img.get(), dst, SkSamplingOptions(), nullptr);
    }
}

/**
 * P4 · 阴影（投影形状的几何 + 偏移 + 模糊 + 浓度）。
 *
 * ★ 实现：在被投影位置画一个带模糊 mask filter 的圆角矩形，填充阴影色 ——
 *   Skia 没有独立的"画阴影"原语，模糊的实心形状就是软阴影。blur=0 ⇒ 关掉
 *   mask filter（硬边阴影，等价于偏移的不透明矩形）。
 * ★ 坐标已是设备像素（PHP 侧按 toDevice 同变换把 rect 转设备）。
 */
void php_drift_skia_draw_shadow(
    Int canvas,
    Float x, Float y, Float w, Float h,
    Int argb, Float radius, Float dx, Float dy, Float blur
) {
    SkiaSlot *slot = slotFor(canvas);
    SkCanvas *c = slot == NULL ? NULL : slot->canvas.get();
    if (c == NULL || w <= 0.0 || h <= 0.0) {
        return;
    }

    SkRect r = SkRect::MakeXYWH((SkScalar)(x + dx), (SkScalar)(y + dy), (SkScalar)w, (SkScalar)h);
    SkPathBuilder builder;
    if (radius > 0.0) {
        builder.addRRect(SkRRect::MakeRectXY(r, (SkScalar)radius, (SkScalar)radius));
    } else {
        builder.addRect(r);
    }
    SkPath path = builder.detach();

    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor((SkColor)(uint32_t)argb);
    if (blur > 0.0) {
        paint.setMaskFilter(SkMaskFilter::MakeBlur(kNormal_SkBlurStyle, (SkScalar)(blur * 0.5f)));
    }

    c->drawPath(path, paint);
}

/**
 * P4 · 压入合成层（整体不透明度 0..1）。Skia 的 `saveLayerAlpha` 把层内累积结果
 *   按 alpha 合成到下层；与 `popLayer()`（canvas->restore()）配对。
 * ★ 层是绘制栈的第三条语义（save/restore 管变换、pushClip/popClip 管裁剪之外），
 *   不污染那两条栈（W8 的 saveDepth/clipDepth 不覆盖层深，由调用方配对）。
 */
void php_drift_skia_push_layer(Int canvas, Float opacity) {
    SkCanvas *c = canvasFor(canvas);
    if (c == NULL) {
        return;
    }
    U8CPU alpha = (U8CPU)((SkScalar)opacity * 255.0f);
    if (alpha > 255) {
        alpha = 255;
    }
    c->saveLayerAlpha(nullptr, alpha);
}

void php_drift_skia_pop_layer(Int canvas) {
    SkCanvas *c = canvasFor(canvas);
    if (c != NULL) {
        c->restore();
    }
}

/**
 * 模糊**背景**（毛玻璃底），范围 `(x,y,w,h)` 设备像素；之后画的都是清晰的。
 *
 * ## 配方：`saveLayer(kInitWithPrevious)` + 立刻 `restore()`
 *
 * `kInitWithPrevious_SaveLayerFlag` 让新层的**初始内容 = 当前画布内容**（即背景），
 * 而 `paint` 上的图像滤镜在层合成回画布时作用于层内容 ⇒ 画布上得到"模糊后的
 * 背景"。**层里除了背景什么都没画过**，所以"子内容不会被一起模糊"这条由结构
 * 保证，不依赖滤镜到底是"入层时"还是"出层时"生效 —— 后者正是 Skia 语义里
 * 最容易记反的一处，不去赌它。
 *
 * ## 边缘为什么用 `kClamp` 而不是放大层的 bounds
 *
 * 模糊在矩形边缘要取样"矩形外"的像素。默认的 `kDecal` 把界外当透明 ⇒ 结果是
 * 一圈**往透明褪的暗边**（sigma 越大越明显）。
 *
 * 先写的第一版靠"把层 bounds 放大 3σ"绕开它，**那是错的**：`saveLayer` 的种子
 * 内容来自当前画布，而当前裁剪（调用方为了收口压的那层）会把种子一起裁到可见
 * 矩形 ⇒ 放大出来的那一圈是空的，褪色暗边原样还在。想在层里"去掉裁剪"是做不到的
 * （Skia 的 `clipRect` 只做求交，没有"临时取消"）。
 *
 * 正解是换平铺模式：`kClamp` 让界外取样**钳到边缘像素**（Flutter 的
 * `ImageFilter.blur` 默认也是 clamp）⇒ 层 bounds 就等于可见矩形，既没有褪色
 * 暗边、又天然满足"矩形外一个像素都不动"，还省掉一次 save/clip。
 *
 * ★ `sigma <= 0` / 矩形退化 ⇒ 直接返回：模糊半径 0 就是不模糊（同
 *   `drawShadow` 的 `blur == 0` 走硬边捷径），不是错误。
 */
void php_drift_skia_blur_backdrop(
    Int canvas,
    Float sigma,
    Float x,
    Float y,
    Float w,
    Float h
) {
    SkCanvas *c = canvasFor(canvas);
    const SkScalar s = (SkScalar)sigma;
    const SkScalar rw = (SkScalar)w;
    const SkScalar rh = (SkScalar)h;
    if (c == NULL || s <= 0.0f || rw <= 0.0f || rh <= 0.0f) {
        return;
    }

    const SkRect visible = SkRect::MakeXYWH((SkScalar)x, (SkScalar)y, rw, rh);

    SkPaint paint;
    paint.setImageFilter(SkImageFilters::Blur(s, s, SkTileMode::kClamp, nullptr));

    // 层 bounds = 可见矩形 ⇒ 合成范围也被它夹住，矩形外一个像素都不动。
    c->saveLayer(
        SkCanvas::SaveLayerRec(&visible, &paint, SkCanvas::kInitWithPrevious_SaveLayerFlag)
    );
    c->restore();
}

/**
 * P4 · 压入路径裁剪（与当前裁剪求交，设备像素）。复用 pushClip 的裁剪栈
 *   （不另开第二条栈），弹栈仍用 popClip()（canvas->restore()）。
 */
void php_drift_skia_push_clip_path(
    Int canvas,
    Int count,
    String verbs,
    String coords
) {
    SkCanvas *c = canvasFor(canvas);
    if (c == NULL || (int)count <= 0) {
        return;
    }
    SkPath path = buildSkPath(verbs.data(), coords.data(), (int)count);
    c->save();
    c->clipPath(path, true);
}

/**
 * 建一个渐变 shader 写入画布当前源（线性 / 径向）。坐标已是**设备像素**
 * （PHP 侧按 toDevice 同变换把逻辑端点转设备），与 draw 函数收到的设备坐标一致。
 * 最多 4 个色标；色标表升序、长度与颜色数一致由 PHP 侧 Gradient 构造期保证。
 *
 * ★★ **C2653（2026-10-17 实测，只在本文件这类"手写 Skia 调用"处会踩）**：
 *    旧 API 写 `SkGradientShader::MakeLinearGradient(...)` ⇒ MSVC 报
 *    **C2653「`SkGradientShader`: 不是类或命名空间」** —— 新 Skia 把它从
 *    "一个类 + 静态工厂"改成"`SkShaders` 命名空间下的自由函数"。
 *    径向同理：`SkShaders::RadialGradient`。
 *    ⇒ 判据：**照旧版 Skia 教程/文档写桥，编译期就炸**；而 PHP 侧 smoke 有假桥
 *      （`if (!function_exists)` 兜底）⇒ **T1 全绿，只有 T2 真机构建看得见**。
 *      这也是"改 Skia C++ 桥必须过 T2"的第一条理由。
 * ★ 色标数组必须 `SkColor4f` 且位置**严格递增**（重复位置会被 Skia 整表丢掉）；
 *    PHP 侧 `Gradient` 会把不足 4 槽的补齐成 4 槽 —— 补齐值若重复就是静默丢表。
 */
void php_drift_skia_set_gradient(
    Int handle,
    Int radial,
    Float x1, Float y1, Float x2, Float y2,
    Int n, Int c0, Int c1, Int c2, Int c3,
    Float s0, Float s1, Float s2, Float s3
) {
    SkiaSlot *slot = slotFor(handle);
    if (slot == NULL) {
        return;
    }
    int count = (int)n;
    if (count > 4) {
        count = 4;
    }
    if (count < 2) {
        count = 2;
    }
    SkColor packed[4] = {
        (SkColor)(uint32_t)c0, (SkColor)(uint32_t)c1,
        (SkColor)(uint32_t)c2, (SkColor)(uint32_t)c3,
    };
    float packedStops[4] = { (float)s0, (float)s1, (float)s2, (float)s3 };

    // ★ 只喂前 count 个：位置必须**严格递增**，而 PHP 侧为凑齐 4 槽会把末位重复补齐
    //   （2 色标 ⇒ [0,1,1,1]），重复值交给 Skia 会被当成非法输入丢掉整个位置表。
    SkColor4f colors[4];
    float stops[4];
    for (int i = 0; i < count; i++) {
        colors[i] = SkColor4f::FromColor(packed[i]);
        stops[i] = packedStops[i];
    }

    SkGradient::Colors gradientColors(
        SkSpan<const SkColor4f>(colors, count),
        SkSpan<const float>(stops, count),
        SkTileMode::kClamp
    );
    SkGradient grad(gradientColors, SkGradient::Interpolation{});

    if ((bool)radial) {
        SkPoint center = SkPoint::Make((SkScalar)x1, (SkScalar)y1);
        slot->shader = SkShaders::RadialGradient(center, (float)x2, grad);
    } else {
        SkPoint pts[2] = {
            SkPoint::Make((SkScalar)x1, (SkScalar)y1),
            SkPoint::Make((SkScalar)x2, (SkScalar)y2),
        };
        slot->shader = SkShaders::LinearGradient(pts, grad);
    }
}

/**
 * 建一个虚线 path effect 写入画布当前源（on/off 为逻辑像素，phase 同口径）。
 *
 * ★★ **C2660（2026-10-17 实测）**：旧 API 是**三参**
 *    `SkDashPathEffect::Make(intervals, count, phase)`；新 Skia 改成**两参**
 *    `Make(SkSpan<const SkScalar>, phase)`。照旧写法 ⇒ MSVC **C2660
 *    「函数不接受 3 个参数」**。
 *    ⇒ 判据同 C2653：**T1 全绿（假桥兜底），只有 T2 看得见**。
 * ★ `SkSpan` 要显式带 `const SkScalar` 模板实参 —— 从 C 数组推断会失败。
 * ★ 虚线只作用于描边：`applyPaintSource()` 里判了 `kStroke_Style`，
 *   填充挂 path effect 在 Skia 下行为未定义（不报错，画出来是错的）。
 */
void php_drift_skia_set_dash(Int handle, Float on, Float off, Float phase) {
    SkiaSlot *slot = slotFor(handle);
    if (slot == NULL) {
        return;
    }
    SkScalar intervals[2] = { (SkScalar)on, (SkScalar)off };
    slot->dash = SkDashPathEffect::Make(SkSpan<const SkScalar>(intervals, 2), (SkScalar)phase);
}

/** 清空画布当前源（每笔绘制后调用，避免源泄漏到下一笔）。 */
void php_drift_skia_clear_source(Int handle) {
    SkiaSlot *slot = slotFor(handle);
    if (slot == NULL) {
        return;
    }
    slot->shader = nullptr;
    slot->dash = nullptr;
}

void php_drift_skia_draw_glyph(
    Int canvas,
    String family,
    Bool bold,
    Float fontSize,
    Float penX,
    Float baselineY,
    Int codepoint,
    Int rgba
) {
    SkCanvas *c = canvasFor(canvas);
    if (c == NULL || fontSize <= 0.0) {
        return;
    }

    // `family` 当前未使用（见 stub 的说明）；先问一次，未就绪就**不画** ——
    // 静默画方框比不画更难查。
    ensureFont();
    if (!g_fontReady) {
        return;
    }

    SkFont font((bool)bold && g_faceBold ? g_faceBold : g_faceRegular, (SkScalar)fontSize);
    font.setSubpixel(true);
    font.setEdging(SkFont::Edging::kAntiAlias);

    SkPaint paint;
    paint.setAntiAlias(true);
    paint.setColor((SkColor)(uint32_t)rgba);

    // ★★ 必须用**收长度**的重载：`drawString(const char str[])` 内部走
    //   `strlen(str)` ⇒ `char utf8[4]` 没有终止符时会**读到栈上的未初始化字节**
    //   （画不出东西，且不报错 —— 真机上表现为"窗口全白，其它一切正常"）。
    char utf8[4];
    const size_t length = encodeUtf8((uint32_t)(Int)codepoint, utf8);
    c->drawSimpleText(
        utf8,
        length,
        SkTextEncoding::kUTF8,
        (SkScalar)penX,
        (SkScalar)baselineY,
        font,
        paint
    );
}

// ══════════════════════════════════════════════════════════════ 取回像素 ══

String php_drift_skia_read_pixels(Int canvas) {
    SkiaSlot *slot = slotFor(canvas);
    if (slot == NULL) {
        return String();
    }

    const int w = slot->bitmap.width();
    const int h = slot->bitmap.height();
    if (w <= 0 || h <= 0) {
        return String();
    }

    const size_t rowPixels = (size_t)w * 4u;
    std::string out(rowPixels * (size_t)h, '\0');

    const unsigned char *src = (const unsigned char *)slot->bitmap.getPixels();
    const size_t rowBytes = slot->bitmap.rowBytes();

    for (int y = 0; y < h; y++) {
        const unsigned char *row = src + (size_t)y * rowBytes;
        unsigned char *dst = (unsigned char *)out.data() + (size_t)y * rowPixels;
        for (int x = 0; x < w; x++) {
            const size_t i = (size_t)x * 4u;
            // N32 在小端上是 BGRA ⇒ 交换 R/B 得到 RGBA。画布不透明 ⇒ alpha 固定 255
            // （与 `drift_win_present()` 的入参格式逐字一致，PHP 侧不必再换算）。
            dst[i + 0] = row[i + 2];
            dst[i + 1] = row[i + 1];
            dst[i + 2] = row[i + 0];
            dst[i + 3] = 0xFF;
        }
    }

    return String(out);
}

Bool php_drift_skia_font_ready() {
    ensureFont();
    return (Bool)g_fontReady;
}

// ─────────────────────────────────────────── 零拷贝取像素（喂贴屏）──
//
// ★ **不是 PHP ABI 函数**（不进 stub，PHP 侧拿不到）：它只服务
//   `drift_win32.cc` 的 `php_drift_win_present_skia()`。
//   用 `extern "C"` 固定符号名 ⇒ 不为它开公共面，也不会有人从 PHP 绕开 present 路径。
//
// ★ 为什么要有它：旧路径把像素 `read_pixels()` 取回成 PHP 字符串（BGRA→RGBA
//   swizzle + 4MB 跨界），再交给 `php_drift_win_present()` 做一次**反向**的
//   RGBA→BGRA swizzle。而 Skia 的 N32 在小端上本来就是 BGRA、32bpp DIB 也要 BGRA
//   ⇒ 两次交换纯属白做，实测合计占端到端 50%+（`26` §5）。
//
// ★ 返回的指针在**下一次绘制或 destroy 之前**有效。调用方必须在同一帧内用完 ——
//   直贴路径立刻拷进 `g_frame`（WM_PAINT 重贴缓存还要用），符合这个约束。
extern "C" bool drift_skia_surface_pixels(
    int canvas,
    const unsigned char **data,
    int *w,
    int *h,
    size_t *rowBytes)
{
    if (data == NULL || w == NULL || h == NULL || rowBytes == NULL) {
        return false;
    }

    SkiaSlot *slot = slotFor(canvas);
    if (slot == NULL) {
        return false;
    }

    const int bw = slot->bitmap.width();
    const int bh = slot->bitmap.height();
    if (bw <= 0 || bh <= 0) {
        return false;
    }

    const unsigned char *pixels = (const unsigned char *)slot->bitmap.getPixels();
    if (pixels == NULL) {
        return false;
    }

    *data = pixels;
    *w = bw;
    *h = bh;
    *rowBytes = slot->bitmap.rowBytes();
    return true;
}
