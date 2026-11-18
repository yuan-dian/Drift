#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <unistd.h>
#include <sys/select.h>
#include <sys/time.h>
#include <time.h>
#include <string>
#include <phpx.h>

// 跨平台 IO 线程池（core 是平台无关池体）+ Linux 自管道唤醒钩子。
// ★ io_pool.h 在 ../core/（app 工程是 cpp-src/x11 → cpp-src/core；probe 工程是
//   native/ → core/，两边相对位置一致）。io_wake.h 与本文件同目录。
#include "../core/io_pool.h"
#include "io_wake.h"
#include "image_decode.h"

using php::Array;
using php::Bool;
using php::Float;
using php::Int;
using php::String;

extern "C" {
    typedef struct _XDisplay Display;
    typedef struct _XVisualInfo Visual;
    typedef struct _XImage XImage;
    typedef unsigned long Window;
    typedef unsigned long Atom;
    typedef unsigned long GC;
    typedef unsigned long Time;
    typedef unsigned long KeySym;
    typedef int Bool_;

    struct XErrorEvent {
        int type;
        Display *display;
        unsigned long serial;
        unsigned char error_code;
        unsigned char request_code;
        unsigned char minor_code;
        unsigned long resourceid;
    };
    typedef int (*XErrorHandler)(Display *, XErrorEvent *);
    XErrorHandler XSetErrorHandler(XErrorHandler handler);

    // Xlib XEvent 是 192 字节的 union；布局是稳定 C ABI。
    union XEvent {
        long pad[24];
        struct { int type; unsigned long serial; Bool_ send_event; Display *display; Window window; } any;
        struct { int type; unsigned long serial; Bool_ send_event; Display *display; Window window;
                 int x, y, width, height, border_width; Window above; Bool_ override_redirect; } configure;
        struct { int type; unsigned long serial; Bool_ send_event; Display *display; Window window;
                 Window subwindow; Time time; int x, y, x_root, y_root; unsigned int state, button; Bool_ same_screen; } button;
        struct { int type; unsigned long serial; Bool_ send_event; Display *display; Window window;
                 Window subwindow; Time time; int x, y, x_root, y_root; unsigned int state; char is_hint; Bool_ same_screen; } motion;
        struct { int type; unsigned long serial; Bool_ send_event; Display *display; Window window;
                 Window subwindow; Time time; int x, y, x_root, y_root; unsigned int state, keycode; Bool_ same_screen; } key;
        struct { int type; unsigned long serial; Bool_ send_event; Display *display; Window window;
                 Atom message_type; int format; long data[5]; } client;
    };

    Display *XOpenDisplay(const char *name);
    int XCloseDisplay(Display *d);
    int XConnectionNumber(Display *d);
    Window XDefaultRootWindow(Display *d);
    Visual *XDefaultVisual(Display *d, int screen);
    unsigned int XDefaultDepth(Display *d, int screen);
    Window XCreateSimpleWindow(Display *d, Window parent, int x, int y, unsigned int w, unsigned int h,
                               unsigned int bw, unsigned long border, unsigned long bg);
    int XStoreName(Display *d, Window w, const char *name);
    int XSelectInput(Display *d, Window w, long mask);
    int XMapWindow(Display *d, Window w);
    int XFlush(Display *d);
    int XSync(Display *d, Bool_ discard);
    int XPending(Display *d);
    int XNextEvent(Display *d, XEvent *ev);
    GC XCreateGC(Display *d, Window drawable, unsigned long mask, void *values);
    int XFreeGC(Display *d, GC gc);
    XImage *XCreateImage(Display *d, Visual *visual, unsigned int depth, int format, int offset,
                         char *data, unsigned int w, unsigned int h, int bitmap_pad, int bytes_per_line);
    int XPutImage(Display *d, Window drawable, GC gc, XImage *image, int src_x, int src_y,
                  int dest_x, int dest_y, unsigned int w, unsigned int h);
    int XDestroyWindow(Display *d, Window w);
    int XGetGeometry(Display *d, unsigned long drawable, Window *root, int *x, int *y,
                     unsigned int *w, unsigned int *h, unsigned int *bw, unsigned int *depth);
    Atom XInternAtom(Display *d, const char *name, Bool_ only_if_exists);
    int XSetWMProtocols(Display *d, Window w, Atom *protocols, int count);
    KeySym XKeycodeToKeysym(Display *d, unsigned int keycode, int index);
    unsigned long pthread_self(void);
}

static Display *g_display = nullptr;
static Atom g_wmDelete = 0;

// KeyPress 一次要吐两条事件（key 给加速键 / char 给文本），而 XNextEvent
// 一次只返回一条 ⇒ 文本暂存到下一次 wait()。与 Win32 的 WM_KEYDOWN→WM_CHAR
// 同序；ctrl/alt 组合不产生文本（与 WM_CHAR 的行为一致）。
static char g_pendingChar[8] = {0};

// IO 唤醒与文本同理：一次 wait 只吐一条事件，select 可能同时被真 X 事件和
// 唤醒管道叫醒 ⇒ 真事件优先，唤醒先记在这里，下一轮 wait 吐（与 Win32 的
// consumeWake「真事件 > 唤醒」同序）。管道字节已 drain，标记是唯一的暂存处。
static bool g_wakePending = false;

// X11 默认错误处理是 abort；死句柄在关窗竞态里是正常路径，必须吞掉。
static int xErrorHandler(Display *d, XErrorEvent *e)
{
    (void)d;
    fprintf(stderr, "[X11] error code=%d request=%d resource=%lu\n",
            (int)e->error_code, (int)e->request_code, e->resourceid);
    return 0;
}

static Display *display()
{
    if (g_display == nullptr) {
        g_display = XOpenDisplay(nullptr);
        if (g_display != nullptr) {
            XSetErrorHandler(xErrorHandler);
            g_wmDelete = XInternAtom(g_display, "WM_DELETE_WINDOW", 0);
        }
    }
    return g_display;
}

/**
 * KeySym → UTF-8 字节（写进 out，返回字节数；0 = 这个键不产生文本）。
 *
 * ★ 不用 XLookupString：它的输出随进程 locale 变，CI/本地 locale 不同就会
 *   让同一份按键得到不同字节。X11 的 KeySym 对 ASCII/Latin-1 有固定数值，
 *   现代输入法上屏的 Unicode 走 0x01000000 + codepoint —— 直接映射是确定的。
 */
static int utf8FromKeySym(KeySym sym, char *out)
{
    unsigned long cp = 0;
    if (sym >= 0x20 && sym <= 0x7e) {
        cp = (unsigned long)sym;
    } else if (sym >= 0xa0 && sym <= 0xff) {
        cp = (unsigned long)sym;
    } else if (sym >= 0x01000000UL && sym <= 0x0100ffffUL) {
        cp = (unsigned long)(sym - 0x01000000UL);
    } else {
        return 0;
    }
    if (cp < 0x80) {
        out[0] = (char)cp;
        return 1;
    }
    if (cp < 0x800) {
        out[0] = (char)(0xc0 | (cp >> 6));
        out[1] = (char)(0x80 | (cp & 0x3f));
        return 2;
    }
    if (cp < 0x10000) {
        out[0] = (char)(0xe0 | (cp >> 12));
        out[1] = (char)(0x80 | ((cp >> 6) & 0x3f));
        out[2] = (char)(0x80 | (cp & 0x3f));
        return 3;
    }
    out[0] = (char)(0xf0 | (cp >> 18));
    out[1] = (char)(0x80 | ((cp >> 12) & 0x3f));
    out[2] = (char)(0x80 | ((cp >> 6) & 0x3f));
    out[3] = (char)(0x80 | (cp & 0x3f));
    return 4;
}

static const char *keyName(KeySym sym)
{
    static char buf[2] = {0, 0};
    // 字母归一成大写（与 Win32 keyNameFromVk 一致）；KeyChord::make 也会归一，
    // 但桥层先统一能让两平台的事件数组逐字段相同，便于交叉比对。
    if ((sym >= 'a' && sym <= 'z') || (sym >= 'A' && sym <= 'Z')) {
        buf[0] = (char)(sym >= 'a' ? sym - ('a' - 'A') : sym);
        return buf;
    }
    if (sym >= '0' && sym <= '9') {
        buf[0] = (char)sym;
        return buf;
    }
    switch (sym) {
        case 0x0020: return "Space";
        case 0xff0d: return "Enter";
        case 0xff1b: return "Escape";
        case 0xff08: return "BackSpace";
        case 0xff09: return "Tab";
        case 0xffff: return "Delete";
        case 0xff51: return "ArrowLeft";
        case 0xff52: return "ArrowUp";
        case 0xff53: return "ArrowRight";
        case 0xff54: return "ArrowDown";
        case 0xff50: return "Home";
        case 0xff57: return "End";
        case 0xff55: return "PageUp";
        case 0xff56: return "PageDown";
        // XK_F1..F12 连续：0xffbe + (n-1)。
        case 0xffbe: return "F1";  case 0xffbf: return "F2";
        case 0xffc0: return "F3";  case 0xffc1: return "F4";
        case 0xffc2: return "F5";  case 0xffc3: return "F6";
        case 0xffc4: return "F7";  case 0xffc5: return "F8";
        case 0xffc6: return "F9";  case 0xffc7: return "F10";
        case 0xffc8: return "F11"; case 0xffc9: return "F12";
        default: return "";
    }
}

static Array eventToArray(const XEvent &ev)
{
    Array out;
    out.set("hwnd", (Int)(uintptr_t)ev.any.window);

    switch (ev.any.type) {
        case 19: // ConfigureNotify
            out.set("type", "resize");
            out.set("w", (Int)ev.configure.width);
            out.set("h", (Int)ev.configure.height);
            break;
        case 4: // ButtonPress
            // X11 把滚轮当按钮 4..7。必须在这里拦下：否则滚轮退化成
            // "指针按下 + 抬起"（产生假点击），且没有任何 ScrollEvent。
            if (ev.button.button >= 4 && ev.button.button <= 7) {
                const int btn = (int)ev.button.button;
                const Bool_ shift = (ev.button.state & 1) != 0;
                out.set("type", "wheel");
                out.set("x", (Int)ev.button.x);
                out.set("y", (Int)ev.button.y);
                if (btn == 4 || btn == 5) {
                    // 前推(4)=看上面、后推(5)=看下面。纵轴取负（用户要求的手感，
                    // 与 Win32 桥一致）；Shift+竖轮=横轮，横轴不取负。
                    const int sign = (btn == 4) ? 1 : -1;
                    if (shift) {
                        out.set("axis", String("horizontal"));
                        out.set("delta", (Int)(sign * 120));
                    } else {
                        out.set("delta", (Int)(-sign * 120));
                    }
                } else {
                    // Button6/7 是横轮（左倾/右倾），横向正向不取负。
                    out.set("axis", String("horizontal"));
                    out.set("delta", (Int)(btn == 6 ? -120 : 120));
                }
                break;
            }
            // 普通按钮： fall through 到 pointer
        case 5: // ButtonRelease
            if (ev.button.button >= 4 && ev.button.button <= 7) {
                out.set("type", "noop");   // 滚轮的 release 不产生 pointer up
                break;
            }
            out.set("type", "pointer");
            out.set("x", (Int)ev.button.x);
            out.set("y", (Int)ev.button.y);
            out.set("button", (Int)ev.button.button);
            out.set("phase", (Int)(ev.any.type == 4 ? 0 : 2));
            out.set("ctrl", (Bool)((ev.button.state & 4) != 0));
            out.set("alt", (Bool)((ev.button.state & 8) != 0));
            out.set("shift", (Bool)((ev.button.state & 1) != 0));
            break;
        case 6: // MotionNotify
            out.set("type", "pointer");
            out.set("x", (Int)ev.motion.x);
            out.set("y", (Int)ev.motion.y);
            out.set("button", (Int)0);
            out.set("phase", (Int)1);
            out.set("ctrl", (Bool)((ev.motion.state & 4) != 0));
            out.set("alt", (Bool)((ev.motion.state & 8) != 0));
            out.set("shift", (Bool)((ev.motion.state & 1) != 0));
            break;
        case 2: { // KeyPress
            const KeySym sym = XKeycodeToKeysym(ev.any.display, ev.key.keycode, 0);
            const char *name = keyName(sym);
            const Bool_ ctrl = (ev.key.state & 4) != 0;
            const Bool_ alt = (ev.key.state & 8) != 0;
            const Bool_ shift = (ev.key.state & 1) != 0;
            char text[8] = {0};
            // Ctrl/Alt 组合不产生文本（与 WM_CHAR 一致）：Ctrl+A 只该是加速键，
            // 不该顺手往输入框里插一个 'a'。
            const int len = (ctrl || alt) ? 0 : utf8FromKeySym(sym, text);

            // 有文本 + 有键名（如 'a'）：先吐 key，文本暂存给下一次 wait，
            // 与 Windows 的 WM_KEYDOWN → WM_CHAR 同序。只有文本（标点 / 非 Latin）
            // 没有键名时直接吐 char。
            if (len > 0 && name[0] == '\0') {
                out.set("type", "char");
                out.set("text", String(text));
                break;
            }
            if (len > 0) {
                memcpy(g_pendingChar, text, (size_t) len + 1);
            }
            if (name[0] != '\0') {
                out.set("type", "key");
                out.set("key", String(name));
                out.set("ctrl", ctrl);
                out.set("alt", alt);
                out.set("shift", shift);
            } else {
                out.set("type", "noop");
            }
            break;
        }
        case 33: // ClientMessage
            out.set("type", String((Int)ev.client.data[0] == (Int)g_wmDelete ? "close-requested" : "noop"));
            break;
        default:
            out.set("type", "noop");
            break;
    }
    return out;
}

Int php_drift_x11_create(String title, Int width, Int height)
{
    Display *d = display();
    if (d == nullptr) return 0;
    Window root = XDefaultRootWindow(d);
    Window win = XCreateSimpleWindow(d, root, 80, 80, (unsigned int)(int)width, (unsigned int)(int)height,
                                     0, 0, 0xffffff);
    if (win == 0) return 0;
    XStoreName(d, win, title.data());
    // 事件掩码按 X11/X.h 的标准位：KeyPress(0)|ButtonPress(2)|ButtonRelease(3)|
    // PointerMotion(6)|Exposure(15)|StructureNotify(17)。
    XSelectInput(d, win, (1L << 0) | (1L << 2) | (1L << 3) | (1L << 6) | (1L << 15) | (1L << 17));
    XMapWindow(d, win);
    if (g_wmDelete != 0) XSetWMProtocols(d, win, &g_wmDelete, 1);
    XFlush(d);
    return (Int)(uintptr_t)win;
}

void php_drift_x11_show(Int hwnd)
{
    Display *d = display();
    if (d != nullptr && hwnd > 0) {
        XMapWindow(d, (Window)(uintptr_t)hwnd);
        XFlush(d);
    }
}

void php_drift_x11_destroy(Int hwnd)
{
    Display *d = display();
    if (d != nullptr && hwnd > 0) {
        XDestroyWindow(d, (Window)(uintptr_t)hwnd);
        XSync(d, 0);
    }
}

Bool php_drift_x11_is_alive(Int hwnd)
{
    Display *d = display();
    if (d == nullptr || hwnd <= 0) return false;
    Window root;
    int x, y;
    unsigned int w, h, bw, dep;
    return XGetGeometry(d, (Window)(uintptr_t)hwnd, &root, &x, &y, &w, &h, &bw, &dep) != 0;
}

Array php_drift_x11_client_size(Int hwnd)
{
    Array out;
    Display *d = display();
    if (d == nullptr || hwnd <= 0) return out;
    Window root;
    int x, y;
    unsigned int w, h, bw, dep;
    if (XGetGeometry(d, (Window)(uintptr_t)hwnd, &root, &x, &y, &w, &h, &bw, &dep) == 0) return out;
    out.set("x", (Int)x);
    out.set("y", (Int)y);
    out.set("w", (Int)w);
    out.set("h", (Int)h);
    return out;
}

void php_drift_x11_set_title(Int hwnd, String title)
{
    Display *d = display();
    if (d != nullptr && hwnd > 0) {
        XStoreName(d, (Window)(uintptr_t)hwnd, title.data());
        XFlush(d);
    }
}

Array php_drift_x11_wait(Float timeoutMs)
{
    // 上一次 KeyPress 暂存的文本先吐：这样 wait() 的调用方看到的顺序与
    // Windows 一致（key → char），且不会让文本卡在下一个真实事件之后。
    if (g_pendingChar[0] != '\0') {
        Array out;
        out.set("type", "char");
        out.set("text", String(g_pendingChar));
        g_pendingChar[0] = '\0';
        return out;
    }

    Display *d = display();
    int wakeFd = drift::x11::ioWakeReadFd();
    if (d == nullptr && wakeFd < 0) {
        Array out;
        out.set("type", "quit");
        return out;
    }

    for (;;) {
        // ★ 真事件优先（与 Win32 的「真事件 > 唤醒」同序）：先看 X 队列。
        //   XPending 存在的窗口事件（含注入的键鼠 / 关窗）比 IO 完成更紧急。
        if (d != nullptr && XPending(d) > 0) {
            XEvent ev;
            XNextEvent(d, &ev);
            return eventToArray(ev);
        }

        // X 队列空了才轮到 IO 唤醒：先 drain 管道（一次消费），再吐 type=wake。
        // g_wakePending 兜住「select 同时报 X 与管道、但上面先返回真事件」那次，
        // 避免那条唤醒被吞掉后结果躺在完成队列里没人取（core 的不变量 ①）。
        if (drift::x11::ioWakeDrain()) {
            g_wakePending = true;
        }
        if (g_wakePending) {
            g_wakePending = false;
            Array out;
            out.set("type", "wake");
            return out;
        }

        if (d == nullptr) {
            // 有唤醒管道但没 X display（headless IO 场景）：直接睡在管道上。
            struct timeval tv;
            struct timeval *ptv = nullptr;
            double ms = (double)timeoutMs;
            if (ms >= 0.0) {
                tv.tv_sec = (long)(ms / 1000.0);
                tv.tv_usec = (long)((ms - (double)tv.tv_sec * 1000.0) * 1000.0);
                ptv = &tv;
            }
            fd_set fds;
            FD_ZERO(&fds);
            FD_SET(wakeFd, &fds);
            if (select(wakeFd + 1, &fds, nullptr, nullptr, ptv) <= 0) {
                Array out;
                out.set("type", "timeout");
                return out;
            }
            continue;   // 回循环顶 drain 管道
        }

        // 真正阻塞：select 同时盯 X connection 与唤醒管道。
        struct timeval tv;
        struct timeval *ptv = nullptr;
        double ms = (double)timeoutMs;
        if (ms >= 0.0) {
            tv.tv_sec = (long)(ms / 1000.0);
            tv.tv_usec = (long)((ms - (double)tv.tv_sec * 1000.0) * 1000.0);
            ptv = &tv;
        }
        fd_set fds;
        FD_ZERO(&fds);
        int xfd = XConnectionNumber(d);
        FD_SET(xfd, &fds);
        int maxfd = xfd;
        if (wakeFd >= 0) {
            FD_SET(wakeFd, &fds);
            if (wakeFd > maxfd) maxfd = wakeFd;
        }
        if (select(maxfd + 1, &fds, nullptr, nullptr, ptv) <= 0) {
            Array out;
            out.set("type", "timeout");
            return out;
        }
        // 回循环顶：XPending 优先，其次 drain 管道。
    }
}

void php_drift_x11_present(Int hwnd, String rgba, Int width, Int height)
{
    Display *d = display();
    int w = (int)width, h = (int)height;
    if (d == nullptr || hwnd <= 0 || w <= 0 || h <= 0) return;

    Window win = (Window)(uintptr_t)hwnd;
    GC gc = XCreateGC(d, win, 0, nullptr);
    char *buf = (char*)malloc((size_t)w * h * 4);
    const unsigned char *src = (const unsigned char*)rgba.data();
    // ★ XImage 的 32bpp 像素是 native 32-bit **0xAARRGGBB**（masks: R=0xff0000 /
    //   G=0xff00 / B=0xff），而 toRgba() 给的是逐字节 RGBA ⇒ R 必须落到 bits 16-23。
    //   旧写法把 src[i*4+2]（B）放到那一位，等于和 Win32 的 BGRA 打包混为一谈 ——
    //   现象是整套配色红蓝互换（青绿 #009688 显示成橄榄 #889600），几何全对但颜色错。
    //   Win32 的 SetDIBitsToDevice 吃 BGRA 字节，两边口径本来就不同，不能照抄。
    for (int i = 0; i < w * h; i++) {
        ((uint32_t*)buf)[i] = 0xff000000u | ((uint32_t)src[i*4] << 16)
                              | ((uint32_t)src[i*4+1] << 8) | src[i*4+2];
    }
    XImage *img = XCreateImage(d, XDefaultVisual(d, 0), XDefaultDepth(d, 0), 2, 0,
                               buf, (unsigned int)w, (unsigned int)h, 32, w * 4);
    if (img != nullptr) {
        XPutImage(d, win, gc, img, 0, 0, 0, 0, (unsigned int)w, (unsigned int)h);
        XFlush(d);
        free(img);
    }
    free(buf);
    XFreeGC(d, gc);
}

Float php_drift_x11_now_ms()
{
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (Float)((double)ts.tv_sec * 1000.0 + (double)ts.tv_nsec / 1e6);
}

String php_drift_x11_config_dir()
{
    const char *base = getenv("XDG_CONFIG_HOME");
    if (base == nullptr || *base == '\0') {
        const char *home = getenv("HOME");
        if (home == nullptr) return String("");
        char path[1024];
        snprintf(path, sizeof(path), "%s/.config/Drift", home);
        return String(path);
    }
    char path[1024];
    snprintf(path, sizeof(path), "%s/Drift", base);
    return String(path);
}

// ── 核心桥的 X11 实现：池体复用 core/io_pool.cc，唤醒走自管道 ──
// 与 win32/drift_win32.cc 的 ABI 包装对称，唯一差别是唤醒钩子：
//   Win32 = startIoWake()（消息专用窗 + PostMessageW）
//   X11   = startIoPipe()（非阻塞管道 + select 读端）
// 顺序同样不能反：先建唤醒通道，再起 worker（worker 一跑就可能叫醒）。
Int php_drift_thread_id() { return (Int)pthread_self(); }

Int php_drift_io_start(Int threads)
{
    if (!drift::x11::startIoPipe()) {
        return (Int)0;
    }
    // ★ 钩子必须在 core::start 之前装：worker 起来后第一条完成就可能叫醒 UI。
    drift::io::setWakeHook([](void *) { drift::x11::ioWakeNotify(); }, nullptr);
    return (Int)drift::io::start((int)threads);
}

Bool php_drift_io_submit(Int id, String handler, Int intArg, String strArg)
{
    return (Bool)drift::io::submit(
        (int)id,
        std::string(handler.data(), handler.length()),
        (int)intArg,
        std::string(strArg.data(), strArg.length())
    );
}

String php_drift_io_poll() { return String(drift::io::poll()); }
Int php_drift_io_threads() { return (Int)drift::io::threads(); }
Int php_drift_io_handled() { return (Int)drift::io::handled(); }

Int php_drift_io_stop()
{
    drift::io::stop();              // 先 join 全部 worker
    drift::io::setWakeHook(nullptr, nullptr);
    drift::x11::stopIoPipe();       // 再关管道（顺序反了 = worker 叫醒死管道）
    return (Int)0;
}

// ── 图片解码（Skia codec，与 win32/WIC 同一 ABI 契约）──
// 空数组 = 失败（格式不支持 / 文件损坏是预期内结果，跨边界抛异常要 phpx 翻译层）。
// 正数 maxWidth 收敛到 unsigned；负数/0 都当"原尺寸"。
Array php_drift_image_decode(String path, Int maxWidth)
{
    Array out;
    unsigned int width = 0;
    unsigned int height = 0;
    std::string rgba;
    const unsigned int limit = maxWidth > 0 ? (unsigned int)maxWidth : 0U;

    if (!drift::x11::decodeImageToRgba(
            std::string(path.data(), path.length()), limit, width, height, rgba)) {
        return out;
    }

    out.set("w", (Int)width);
    out.set("h", (Int)height);
    out.set("rgba", String(rgba));
    return out;
}
