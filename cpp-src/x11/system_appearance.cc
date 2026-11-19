#include "system_appearance.h"

#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include <string>

extern "C" {
    typedef struct _XDisplay Display;
    typedef unsigned long Window;
    typedef unsigned long Atom;
    typedef int Bool_;

    Display *XOpenDisplay(const char *name);
    int XCloseDisplay(Display *d);
    Atom XInternAtom(Display *d, const char *name, Bool_ only_if_exists);
    Window XGetSelectionOwner(Display *d, Atom selection);
    int XGetWindowProperty(Display *d, Window w, Atom property, long long_offset, long long_length,
                           Bool_ del, Atom req_type, Atom *actual_type_return, int *actual_format_return,
                           unsigned long *nitems_return, unsigned long *bytes_after_return,
                           unsigned char **prop_return);
    int XFree(void *data);
}

namespace drift {
namespace x11 {
namespace {

uint16_t rd16(const unsigned char *p, bool little)
{
    if (little) {
        return (uint16_t)((uint16_t)p[0] | ((uint16_t)p[1] << 8));
    }
    return (uint16_t)(((uint16_t)p[0] << 8) | (uint16_t)p[1]);
}

uint32_t rd32(const unsigned char *p, bool little)
{
    if (little) {
        return (uint32_t)p[0] | ((uint32_t)p[1] << 8) |
               ((uint32_t)p[2] << 16) | ((uint32_t)p[3] << 24);
    }
    return ((uint32_t)p[0] << 24) | ((uint32_t)p[1] << 16) |
           ((uint32_t)p[2] << 8) | (uint32_t)p[3];
}

size_t pad4(size_t n)
{
    return (n + 3u) & ~static_cast<size_t>(3u);
}

std::string lower(std::string s)
{
    for (char &c : s) {
        c = (char)tolower((unsigned char)c);
    }
    return s;
}

bool contains(const std::string &hay, const char *needle)
{
    return hay.find(needle) != std::string::npos;
}

/**
 * 主题名 → 两个布尔。GTK 的主题变体约定是 `-dark` / `:dark`，高对比度主题
 * 通常叫 `HighContrast` / `HighContrast-dark`；大小写不敏感，因为 XSettings
 * 只是字符串，发行版拼写并不统一。
 */
void applyThemeName(SystemAppearanceSnapshot &s, const std::string &value)
{
    const std::string name = lower(value);
    if (contains(name, "highcontrast") || contains(name, "high_contrast") ||
        contains(name, "high contrast")) {
        s.highContrast = true;
    }
    if (contains(name, "-dark") || contains(name, ":dark") ||
        contains(name, "_dark") || contains(name, "dark") && name.find("highcontrast") == std::string::npos) {
        s.dark = true;
    }
}

void applyColorScheme(SystemAppearanceSnapshot &s, const std::string &value)
{
    const std::string scheme = lower(value);
    if (contains(scheme, "prefer-dark") || contains(scheme, "dark")) {
        s.dark = true;
    } else if (contains(scheme, "prefer-light") || contains(scheme, "light")) {
        s.dark = false;
    }
}

bool parseXSettings(const unsigned char *data, size_t bytes, SystemAppearanceSnapshot &s)
{
    // XSETTINGS 0.5：1 byte order + 3 pad + 4 serial + 4 count。
    if (data == nullptr || bytes < 12) {
        return false;
    }
    const bool little = data[0] == 0;
    const uint32_t count = rd32(data + 8, little);
    size_t pos = 12;

    for (uint32_t i = 0; i < count; ++i) {
        if (pos + 4 > bytes) {
            return false;
        }
        const unsigned char type = data[pos];
        const uint16_t nameLen = rd16(data + pos + 2, little);
        pos += 4;
        if (nameLen == 0 || pos + nameLen + 4 > bytes) {
            return false;
        }
        const std::string name((const char *)(data + pos), (size_t)nameLen);
        pos = pad4(pos + nameLen);
        pos += 4;   // last-change-serial

        switch (type) {
            case 0: {   // integer
                if (pos + 4 > bytes) {
                    return false;
                }
                const uint32_t value = rd32(data + pos, little);
                pos += 4;
                const std::string key = lower(name);
                if (contains(key, "applicationpreferdarktheme")) {
                    s.dark = value != 0;
                }
                break;
            }
            case 1: {   // string
                if (pos + 4 > bytes) {
                    return false;
                }
                const uint32_t valueLen = rd32(data + pos, little);
                pos += 4;
                if (valueLen > bytes - pos) {
                    return false;
                }
                const std::string value((const char *)(data + pos), (size_t)valueLen);
                pos = pad4(pos + valueLen);

                const std::string key = lower(name);
                if (key == "net/themename" || key == "gtk/themename" ||
                    key == "net/iconstheme" || key == "gtk/iconstheme") {
                    applyThemeName(s, value);
                } else if (key == "net/colorscheme" || key == "gtk/colorscheme") {
                    applyColorScheme(s, value);
                } else if (key == "net/highcontrast" || key == "gtk/highcontrast") {
                    s.highContrast = value == "1" || lower(value) == "true";
                }
                break;
            }
            case 2: {   // color：只跳过，Drift 不伪造用户系统色
                if (pos + 8 > bytes) {
                    return false;
                }
                pos += 8;
                break;
            }
            default:
                return false;
        }
    }
    return true;
}

void applyEnvironment(SystemAppearanceSnapshot &s)
{
    if (const char *theme = getenv("GTK_THEME")) {
        applyThemeName(s, theme);
    }
    if (const char *scheme = getenv("GTK_COLOR_SCHEME")) {
        applyColorScheme(s, scheme);
    } else if (const char *scheme = getenv("ADW_COLOR_SCHEME")) {
        applyColorScheme(s, scheme);
    }
    if (const char *qt = getenv("QT_STYLE_OVERRIDE")) {
        applyThemeName(s, qt);
    }
}

SystemAppearanceSnapshot g_published;
bool g_havePublished = false;
std::chrono::steady_clock::time_point g_lastPoll =
    std::chrono::steady_clock::now() - std::chrono::seconds(1);

}   // namespace

SystemAppearanceSnapshot readSystemAppearance()
{
    SystemAppearanceSnapshot s;
    Display *d = XOpenDisplay(nullptr);
    if (d != nullptr) {
        Atom selection = XInternAtom(d, "_XSETTINGS_S0", 1);
        if (selection != 0) {
            const Window owner = XGetSelectionOwner(d, selection);
            if (owner != 0) {
                Atom property = XInternAtom(d, "_XSETTINGS_SETTINGS", 1);
                if (property != 0) {
                    Atom actual = 0;
                    int format = 0;
                    unsigned long items = 0, after = 0;
                    unsigned char *prop = nullptr;
                    if (XGetWindowProperty(d, owner, property, 0, 65536, 0, property,
                                           &actual, &format, &items, &after, &prop) == 0 &&
                        prop != nullptr && format == 8) {
                        parseXSettings(prop, (size_t)items, s);
                    }
                    if (prop != nullptr) {
                        XFree(prop);
                    }
                }
            }
        }
        XCloseDisplay(d);
    }

    // 环境变量是 XSettings 缺席时的兜底（Xvfb / fluxbox / 裸 WM 都没有 settings daemon）。
    applyEnvironment(s);
    return s;
}

bool pollSystemAppearanceChanged()
{
    const auto now = std::chrono::steady_clock::now();
    if (g_havePublished &&
        now - g_lastPoll < std::chrono::milliseconds(250)) {
        return false;
    }
    g_lastPoll = now;

    const SystemAppearanceSnapshot next = readSystemAppearance();
    if (!g_havePublished) {
        g_published = next;
        g_havePublished = true;
        return false;
    }
    const bool changed = next.dark != g_published.dark ||
                         next.highContrast != g_published.highContrast;
    g_published = next;
    return changed;
}

SystemAppearanceSnapshot currentSystemAppearance()
{
    if (!g_havePublished) {
        g_published = readSystemAppearance();
        g_havePublished = true;
    }
    return g_published;
}

}   // namespace x11
}   // namespace drift
