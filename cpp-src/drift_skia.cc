/**
 * Drift 的 Skia 光栅后端（§7.2 裁决 1 的第三个 `Canvas` 实现）。
 *
 * 与 PHP 的边界见 `cpp-src/drift_skia.stub.php`。本文件**只做三件事**：
 *   ① 建/释放离屏 Skia 画布（`SkBitmap` + `SkCanvas::MakeRasterDirectN32`）；
 *   ② 把 PHP 的绘制命令施加到画布上（矩形 / 圆角 / 描边 / 裁剪 / 平移 / 字形）；
 *   ③ 把画布像素取回成 RGBA 字节，交给 `drift_win32.cc` 的 `drift_win_present()` 上屏。
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
// ★ 必须在 phpx.h **之后**（与 drift_win32.cc 同序）：phpx.h 拉进来的
//   php_network.h 依赖 winsock2 的 `timeval`，而 windows.h 先到会把旧
//   winsock.h 带进来 ⇒ C2027 未定义类型 timeval（实测）。
#include <windows.h>

#include <cstdint>
#include <map>
#include <memory>
#include <string>

#include "include/core/SkBitmap.h"
#include "include/core/SkCanvas.h"
#include "include/core/SkColor.h"
#include "include/core/SkFont.h"
#include "include/core/SkFontMgr.h"
#include "include/core/SkPaint.h"
#include "include/core/SkRRect.h"
#include "include/core/SkRect.h"
#include "include/core/SkTypeface.h"
#include "include/effects/SkGradient.h"
#include "include/effects/SkDashPathEffect.h"
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
 * ★ 用 `GetModuleFileNameA`（ANSI）而不是 `W`：`SkFontMgr_New_Custom_Directory`
 *   收的是 `const char*`，Windows 侧按 ANSI 代码页解析 ⇒ 两者编码口径一致。
 *   换成 UTF-8 反而会在非 ASCII 路径上出现"传进去的字节和 Skia 解析的口径不同"。
 */
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

// ══════════════════════════════════════════════════════════ 画布生命周期 ══

Int php_drift_skia_canvas_create(Int width, Int height, Int backgroundRgba) {
    int w = (int)width;
    int h = (int)height;
    if (w <= 0 || h <= 0) {
        return (Int)0;   // 0 尺寸窗口（最小化）不是错误，只是没有可画的东西
    }

    SkiaSlot *slot = nullptr;
    if (g_recycleSlot != nullptr && g_recycleW == w && g_recycleH == h) {
        // ★ 命中复用缓存：bitmap 与 canvas 都活着，只把画布状态复位成"新画布"：
        //   restoreToCount(1) 兜住上一帧可能漏出的 save/clip（正常应已平衡）；
        //   resetMatrix 把 CTM 还原成创建时的单位矩阵（光栅 direct 的初态）。
        slot = g_recycleSlot;
        g_recycleSlot = nullptr;
        slot->canvas->restoreToCount(1);
        slot->canvas->resetMatrix();
    } else {
        slot = new SkiaSlot();
        // isOpaque = true：画布不透明 ⇒ Skia 直接覆盖写，不做 dst alpha 混合；
        // 取回像素时 alpha 固定 255，与 `drift_win_present()` 的约定一致。
        slot->bitmap.allocN32Pixels(w, h, true);
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


/**
 * 建一个渐变 shader 写入画布当前源（线性 / 径向）。坐标已是**设备像素**
 * （PHP 侧按 toDevice 同变换把逻辑端点转设备），与 draw 函数收到的设备坐标一致。
 * 最多 4 个色标；色标表升序、长度与颜色数一致由 PHP 侧 Gradient 构造期保证。
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

/** 建一个虚线 path effect 写入画布当前源（on/off 为逻辑像素，phase 同口径）。 */
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
