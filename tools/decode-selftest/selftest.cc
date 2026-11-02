// `decodeImageToRgba()` 的最小自检（不进 AOT、不依赖 phpx）。
//
// 为什么要独立一个程序：`drift_image_decode()` 的缩放分支只有在**有 WIC 的进程**
// 里才走得到，而 `php.exe` 里没有扩展 ⇒ 单测永远覆盖不到。`probes/RESULTS.md`
// 的教训是"没被消费的分支 = 没实现"，所以这条分支要自己开口。
//
// 判据全部对着**同一张真图的两个尺寸**，而不是"跑完没崩"：
//   ① 原尺寸能解（w/h 非零）
//   ② 目标宽度生效（返回的 w == 请求值）
//   ③ 等比（h 按原图比例四舍五入，至少 1）
//   ④ rgba 长度 == w*h*4（跨边界的长度契约）
//   ⑤ 真的变小了（不是悄悄退回原尺寸）
//   ⑥ 不是全黑（缩放没把像素流弄丢）
//
// 编译（vcvars64 之后）：
//   cl /nologo /std:c++17 /EHsc /O2 selftest.cc ../../cpp-src/win32/image_decode.cc ^
//      /Fe:selftest.exe /link windowscodecs.lib ole32.lib

#include "../../cpp-src/win32/image_decode.h"

#include <windows.h>

#include <cstdio>
#include <cstdlib>
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

}  // namespace

int main(int argc, char **argv)
{
    if (argc < 2) {
        std::printf("usage: selftest <image> [maxWidth]\n");
        return 2;
    }

    const std::wstring path = toWide(argv[1]);
    const unsigned int target = argc >= 3 ? static_cast<unsigned int>(std::atoi(argv[2])) : 640U;

    int failures = 0;
    char buf[256];

    const auto check = [&failures](const char *what, bool ok, const char *detail) {
        std::printf("%s  %-28s %s\n", ok ? "ok  " : "FAIL", what, detail);
        if (!ok) {
            ++failures;
        }
    };

    // ① 原尺寸
    unsigned int w0 = 0;
    unsigned int h0 = 0;
    std::string rgba0;
    const bool ok0 = drift::win32::decodeImageToRgba(path, 0, w0, h0, rgba0);
    std::snprintf(buf, sizeof buf, "%ux%u %zu bytes", w0, h0, rgba0.size());
    check("decode at original size", ok0 && w0 > 0 && h0 > 0, buf);
    if (!ok0) {
        std::printf("\n不能没解出原图就测缩放（WIC 不可用 / 文件不是图片）\n");
        return 1;
    }

    // ② 目标宽度
    unsigned int w1 = 0;
    unsigned int h1 = 0;
    std::string rgba1;
    const bool ok1 = drift::win32::decodeImageToRgba(path, target, w1, h1, rgba1);
    std::snprintf(buf, sizeof buf, "%ux%u %zu bytes", w1, h1, rgba1.size());
    check("decode at target width", ok1 && w1 == target, buf);

    if (ok1) {
        // ③ 等比
        const unsigned long long want =
            (static_cast<unsigned long long>(h0) * target + w0 / 2ULL) / w0;
        std::snprintf(buf, sizeof buf, "want %llu, got %u", want, h1);
        check("aspect preserved", want == static_cast<unsigned long long>(h1), buf);

        // ④ 长度自洽
        const size_t need = static_cast<size_t>(w1) * static_cast<size_t>(h1) * 4U;
        std::snprintf(buf, sizeof buf, "%zu vs %zu", rgba1.size(), need);
        check("rgba length == w*h*4", rgba1.size() == need, buf);

        // ⑤ 真的小了
        std::snprintf(buf, sizeof buf, "%zu < %zu", rgba1.size(), rgba0.size());
        check("smaller than original", rgba1.size() < rgba0.size(), buf);

        // ⑥ 不是全黑
        bool any = false;
        for (size_t i = 0; i + 3 < rgba1.size(); i += 4) {
            if (rgba1[i] != 0 || rgba1[i + 1] != 0 || rgba1[i + 2] != 0) {
                any = true;
                break;
            }
        }
        check("pixels not all black", any, any ? "" : "all RGB zero");
    }

    std::printf("\n%d failure(s)\n", failures);
    return failures == 0 ? 0 : 1;
}
