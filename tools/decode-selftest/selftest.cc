// `decodeImageToRgba()` 的最小自检（不进 AOT、不依赖 phpx）。
//
// 为什么要独立一个程序：`drift_image_decode()` 的缩放分支只有在**有 WIC 的进程**
// 里才走得到，而 `php.exe` 里没有扩展 ⇒ 单测永远覆盖不到。`probes/RESULTS.md`
// 的教训是"没被消费的分支 = 没实现"，所以这条分支要自己开口。
//
// ── 两组判据 ───────────────────────────────────────────────────────────────
//
// A. **色带图**（`fixtures/rgb-bands.png`，期望值手写）：
//   ① 原尺寸：红带是红、绿带是绿、蓝带是蓝、灰带是 128
//   ② 缩到一半：四条带**颜色不变**
//   这一组才是"颜色对不对"的判据。曾经的 1~6 条全是"同一张图两个尺寸互相比"，
//   只比得出"一致 / 不一致"，比不出"是谁"—— 于是 R/B 互换能让它们全绿。
//
// B. **真图**（`--image`，可选，需要一张真结果图）：
//   ③ 原尺寸能解   ④ 目标宽度生效   ⑤ 等比   ⑥ 长度自洽
//   ⑦ 真的变小了   ⑧ 不是全黑       ⑨ 两个尺寸的通道均值一致（防整体偏色）
//   用真图是为了覆盖"16 位 / 非正方形 / 有 alpha"这些色带图没有的形状。
//
// 编译（vcvars64 之后）：
//   cl /nologo /std:c++17 /EHsc /O2 selftest.cc ../../cpp-src/win32/image_decode.cc ^
//      /Fe:selftest.exe /link windowscodecs.lib ole32.lib
//
// 运行：
//   selftest.exe --fixture fixtures/rgb-bands.png
//   selftest.exe --fixture fixtures/rgb-bands.png --image <真图> --maxWidth 1120

#include "../../cpp-src/win32/image_decode.h"

#include <windows.h>

#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>

namespace {

std::wstring toWide(const char *utf8)
{
    const int n = ::MultiByteToWideChar(CP_UTF8, 0, utf8, -1, NULL, 0);
    if (n <= 0) {
        return std::wstring();
    }
    std::wstring out(static_cast<size_t>(n), L'\0');
    ::MultiByteToWideChar(CP_UTF8, 0, utf8, -1, &out[0], n);
    out.pop_back();   // 去掉 MultiByteToWideChar 附带的 L'\0'
    return out;
}

/** 色带图的期望颜色（**手写**，来源是 make-fixture.py 里那块 BANDS 表） */
struct Band
{
    const char *name;
    int r;
    int g;
    int b;
    int tol;   // 允许的偏差：原色 0/255 不该有偏差，中灰留 2（16->8 的取整）
};

const Band kBand[] = {
    {"red",   255,   0,   0, 1},
    {"green",   0, 255,   0, 1},
    {"blue",    0,   0, 255, 1},
    {"grey",  128, 128, 128, 2},
};

/** 取 (x, y) 的 RGB；越界返回 false */
bool pixelAt(const std::string &rgba, unsigned w, unsigned h, unsigned x, unsigned y,
             int &r, int &g, int &b)
{
    if (x >= w || y >= h) {
        return false;
    }
    const unsigned char *p =
        reinterpret_cast<const unsigned char *>(rgba.data()) + (static_cast<size_t>(y) * w + x) * 4;
    r = p[0];
    g = p[1];
    b = p[2];
    return true;
}

}  // namespace

int main(int argc, char **argv)
{
    const char *fixture = NULL;
    const char *image = NULL;
    unsigned int target = 640U;
    bool haveTarget = false;

    for (int i = 1; i < argc; ++i) {
        if (std::strcmp(argv[i], "--fixture") == 0 && i + 1 < argc) {
            fixture = argv[++i];
        } else if (std::strcmp(argv[i], "--image") == 0 && i + 1 < argc) {
            image = argv[++i];
        } else if (std::strcmp(argv[i], "--maxWidth") == 0 && i + 1 < argc) {
            target = static_cast<unsigned int>(std::atoi(argv[++i]));
            haveTarget = true;
        } else if (argv[i][0] != '-' && image == NULL) {
            image = argv[i];      // 旧用法：selftest <image> [maxWidth]
        } else if (argv[i][0] != '-' && !haveTarget) {
            target = static_cast<unsigned int>(std::atoi(argv[i]));
            haveTarget = true;
        }
    }

    if (fixture == NULL && image == NULL) {
        std::printf("usage: selftest --fixture <rgb-bands.png> [--image <file>] [--maxWidth N]\n");
        return 2;
    }

    int failures = 0;
    int total = 0;
    char buf[256];

    const auto check = [&failures, &total](const char *what, bool ok, const char *detail) {
        ++total;
        std::printf("%s  %-30s %s\n", ok ? "ok  " : "FAIL", what, detail);
        if (!ok) {
            ++failures;
        }
    };

    // ── A. 色带：颜色必须对（期望值手写，不跟任何解码器比）───────────────────
    if (fixture != NULL) {
        unsigned int fw = 0;
        unsigned int fh = 0;
        std::string frgba;
        const bool ok = drift::win32::decodeImageToRgba(toWide(fixture), 0, fw, fh, frgba);
        std::snprintf(buf, sizeof buf, "%ux%u %zu bytes", fw, fh, frgba.size());
        check("fixture decodes", ok && fw == 64 && fh == 64, buf);

        if (ok) {
            // ① 原尺寸：每条带 16 行，取带中央
            for (int i = 0; i < 4; ++i) {
                int r = -1;
                int g = -1;
                int b = -1;
                const unsigned y = static_cast<unsigned>(i * 16 + 8);
                const bool got = pixelAt(frgba, fw, fh, 32, y, r, g, b);
                const bool same = got && std::abs(r - kBand[i].r) <= kBand[i].tol
                    && std::abs(g - kBand[i].g) <= kBand[i].tol
                    && std::abs(b - kBand[i].b) <= kBand[i].tol;
                std::snprintf(buf, sizeof buf, "(%d,%d) want %d,%d,%d got %d,%d,%d", 32,
                              static_cast<int>(y), kBand[i].r, kBand[i].g, kBand[i].b, r, g, b);
                std::snprintf(buf + std::strlen(buf), 32, " %s", kBand[i].name);
                check("color at original size", same, buf);
            }

            // ② 缩到 32 宽：每条带仍有 8 行，取带中央（不让插值混到邻带）
            unsigned int sw = 0;
            unsigned int sh = 0;
            std::string srgba;
            const bool okS = drift::win32::decodeImageToRgba(toWide(fixture), 32, sw, sh, srgba);
            std::snprintf(buf, sizeof buf, "%ux%u", sw, sh);
            check("fixture scales to 32", okS && sw == 32 && sh == 32, buf);

            if (okS) {
                for (int i = 0; i < 4; ++i) {
                    int r = -1;
                    int g = -1;
                    int b = -1;
                    const unsigned y = static_cast<unsigned>(i * 8 + 4);
                    const bool got = pixelAt(srgba, sw, sh, 16, y, r, g, b);
                    const bool same = got && std::abs(r - kBand[i].r) <= kBand[i].tol
                        && std::abs(g - kBand[i].g) <= kBand[i].tol
                        && std::abs(b - kBand[i].b) <= kBand[i].tol;
                    std::snprintf(buf, sizeof buf, "(%d,%d) want %d,%d,%d got %d,%d,%d", 16,
                                  static_cast<int>(y), kBand[i].r, kBand[i].g, kBand[i].b, r, g, b);
                    std::snprintf(buf + std::strlen(buf), 32, " %s", kBand[i].name);
                    check("color after scaling", same, buf);
                }
            }
        }
    }

    // ── B. 真图：形状与均值 ──────────────────────────────────────────────────
    if (image != NULL) {
        const std::wstring path = toWide(image);

        unsigned int w0 = 0;
        unsigned int h0 = 0;
        std::string rgba0;
        const bool ok0 = drift::win32::decodeImageToRgba(path, 0, w0, h0, rgba0);
        std::snprintf(buf, sizeof buf, "%ux%u %zu bytes", w0, h0, rgba0.size());
        check("decode at original size", ok0 && w0 > 0 && h0 > 0, buf);

        if (!ok0) {
            std::printf("\n不能没解出原图就测缩放（WIC 不可用 / 文件不是图片）\n");
            std::printf("\n%d of %d check(s) failed\n", failures, total);
            return 1;
        }

        unsigned int w1 = 0;
        unsigned int h1 = 0;
        std::string rgba1;
        const bool ok1 = drift::win32::decodeImageToRgba(path, target, w1, h1, rgba1);
        std::snprintf(buf, sizeof buf, "%ux%u %zu bytes", w1, h1, rgba1.size());
        check("decode at target width", ok1 && w1 == target, buf);

        if (ok1) {
            const unsigned long long want = (static_cast<unsigned long long>(h0) * target + w0 / 2ULL) / w0;
            std::snprintf(buf, sizeof buf, "want %llu, got %u", want, h1);
            check("aspect preserved", want == static_cast<unsigned long long>(h1), buf);

            const size_t need = static_cast<size_t>(w1) * static_cast<size_t>(h1) * 4U;
            std::snprintf(buf, sizeof buf, "%zu vs %zu", rgba1.size(), need);
            check("rgba length == w*h*4", rgba1.size() == need, buf);

            std::snprintf(buf, sizeof buf, "%zu < %zu", rgba1.size(), rgba0.size());
            check("smaller than original", rgba1.size() < rgba0.size(), buf);

            bool any = false;
            for (size_t i = 0; i + 3 < rgba1.size(); i += 4) {
                if (rgba1[i] != 0 || rgba1[i + 1] != 0 || rgba1[i + 2] != 0) {
                    any = true;
                    break;
                }
            }
            check("pixels not all black", any, any ? "" : "all RGB zero");

            // ⑨ 通道均值：缩放会平均掉细节，但**不会**改变整体偏色。
            //    插值误差是零点几个色阶，R/B 互换是几十个色阶 —— 两者差三个数量级。
            double m0[3] = {0, 0, 0};
            double m1[3] = {0, 0, 0};
            for (size_t i = 0; i + 3 < rgba0.size(); i += 4) {
                for (int c = 0; c < 3; ++c) {
                    m0[c] += static_cast<unsigned char>(rgba0[i + c]);
                }
            }
            for (size_t i = 0; i + 3 < rgba1.size(); i += 4) {
                for (int c = 0; c < 3; ++c) {
                    m1[c] += static_cast<unsigned char>(rgba1[i + c]);
                }
            }
            for (int c = 0; c < 3; ++c) {
                m0[c] /= static_cast<double>(rgba0.size() / 4);
                m1[c] /= static_cast<double>(rgba1.size() / 4);
            }
            const double d = std::abs(m0[0] - m1[0]) + std::abs(m0[1] - m1[1]) + std::abs(m0[2] - m1[2]);
            std::snprintf(buf, sizeof buf, "R %.1f->%.1f  G %.1f->%.1f  B %.1f->%.1f",
                          m0[0], m1[0], m0[1], m1[1], m0[2], m1[2]);
            check("channel means match", d < 3.0, buf);
        }
    }

    std::printf("\n%d of %d check(s) failed\n", failures, total);
    return failures == 0 ? 0 : 1;
}
