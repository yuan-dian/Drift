#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <unistd.h>
#include <sys/select.h>
#include <sys/time.h>
#include <time.h>
#include <phpx.h>

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

static const char *keyName(KeySym sym)
{
    static char buf[2] = {0, 0};
    if ((sym >= 'a' && sym <= 'z') || (sym >= '0' && sym <= '9')) {
        buf[0] = (char)sym;
        return buf;
    }
    switch (sym) {
        case 0xff0d: return "Enter";
        case 0xff1b: return "Escape";
        case 0xff08: return "BackSpace";
        case 0xff09: return "Tab";
        case 0xffff: return "Delete";
        case 0xff51: return "ArrowLeft";
        case 0xff52: return "ArrowUp";
        case 0xff53: return "ArrowRight";
        case 0xff54: return "ArrowDown";
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
        case 4: case 5: // ButtonPress / ButtonRelease
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
            const char *name = keyName(XKeycodeToKeysym(ev.any.display, ev.key.keycode, 0));
            out.set("type", "key");
            out.set("key", String(name));
            out.set("ctrl", (Bool)((ev.key.state & 4) != 0));
            out.set("alt", (Bool)((ev.key.state & 8) != 0));
            out.set("shift", (Bool)((ev.key.state & 1) != 0));
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
    Display *d = display();
    if (d == nullptr) {
        Array out;
        out.set("type", "quit");
        return out;
    }
    while (XPending(d) == 0) {
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
        int fd = XConnectionNumber(d);
        FD_SET(fd, &fds);
        if (select(fd + 1, &fds, nullptr, nullptr, ptv) <= 0) {
            Array out;
            out.set("type", "timeout");
            return out;
        }
    }
    XEvent ev;
    XNextEvent(d, &ev);
    return eventToArray(ev);
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
    for (int i = 0; i < w * h; i++) {
        ((uint32_t*)buf)[i] = 0xff000000u | ((uint32_t)src[i*4+2] << 16)
                              | ((uint32_t)src[i*4+1] << 8) | src[i*4];
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

// 核心桥的空实现：IO 池在无线程池的 headless/X11 最小壳里不可用。
Int php_drift_thread_id() { return (Int)pthread_self(); }
Int php_drift_io_start(Int threads) { (void)threads; return 1; }
Bool php_drift_io_submit(Int id, String handler, Int intArg, String strArg)
{
    (void)id; (void)handler; (void)intArg; (void)strArg;
    return false;
}
String php_drift_io_poll() { return String(""); }
Int php_drift_io_threads() { return 0; }
Int php_drift_io_handled() { return 0; }
Int php_drift_io_stop() { return 0; }
