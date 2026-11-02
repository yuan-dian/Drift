// 把一张图用**本仓的解码函数**解出来，打印均值与抽样点。
//
// 用途：给"颜色对不对"提供可比数字。同一张 PNG 分别用
//   (a) 纯 Python 按文件字节解（tools/png-ref-dump.py）—— 基准
//   (b) 这里的 WIC 路径（cpp-src/win32/image_decode.cc）—— 待测
// 两组数字一致 ⇒ 解码没改颜色；不一致 ⇒ 差在 WIC 这层。
//
// 编译与运行见 tools/decode-selftest/README 里 selftest 的同一套命令。

#include "../../cpp-src/win32/image_decode.h"

#include <cstdio>
#include <cstdlib>
#include <string>

static std::wstring widen(const char *s)
{
    std::wstring w;
    while (*s) {
        w.push_back(static_cast<wchar_t>(*s++));   // 只用于 ASCII 路径
    }
    return w;
}

int main(int argc, char **argv)
{
    if (argc < 2) {
        std::printf("usage: dump.exe <file> [maxWidth] [x,y ...]\n");
        return 2;
    }

    const unsigned int maxWidth =
        (argc > 2) ? static_cast<unsigned int>(std::atoi(argv[2])) : 0;

    unsigned int w = 0;
    unsigned int h = 0;
    std::string rgba;

    if (!drift::win32::decodeImageToRgba(widen(argv[1]), maxWidth, w, h, rgba)) {
        std::printf("decode FAILED\n");
        return 1;
    }

    std::printf("%ux%u  bytes=%zu\n", w, h, rgba.size());

    double sr = 0;
    double sg = 0;
    double sb = 0;
    double n = 0;
    for (unsigned int y = 0; y < h; ++y) {
        const unsigned char *row = reinterpret_cast<const unsigned char *>(rgba.data()) + static_cast<size_t>(y) * w * 4;
        for (unsigned int x = 0; x < w; ++x) {
            sr += row[x * 4 + 0];
            sg += row[x * 4 + 1];
            sb += row[x * 4 + 2];
            n += 1;
        }
    }
    std::printf("mean R=%.2f G=%.2f B=%.2f\n", sr / n, sg / n, sb / n);

    for (int i = 3; i < argc; ++i) {
        unsigned int x = 0;
        unsigned int y = 0;
        if (std::sscanf(argv[i], "%u,%u", &x, &y) != 2 || x >= w || y >= h) {
            continue;
        }
        const unsigned char *p =
            reinterpret_cast<const unsigned char *>(rgba.data()) + (static_cast<size_t>(y) * w + x) * 4;
        std::printf("  %u,%u  #%02X%02X%02X  (%3u %3u %3u)\n", x, y, p[0], p[1], p[2], p[0], p[1], p[2]);
    }

    return 0;
}
