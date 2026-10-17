/**
 * Drift 的 Win32 壳（§5.1 / §7.3 M1 后端）。
 *
 * 壳只做四件事：**建窗 / 泵消息 / 贴一帧像素 / 定时器**。
 * 界面全部由 PHP 自绘（§18：原生控件与自绘主题无法统一），所以这里没有工具栏、
 * 没有状态栏、没有 RichEdit —— 与 GUI 仓 `gui_win32.cc`（2108 行）和
 * markdown-editor `win32.cc`（2873 行）比，本文件小得多，**这是设计使然，不是欠账**。
 *
 * 与 PHP 的边界见 `cpp-src/drift_win32.stub.php`。三条纪律照抄两个参考实现：
 *   ① 只传原语，句柄是不透明 int；
 *   ② PHP 侧不持有 Win32 常量、不做 wParam 位运算（键码在这里就归一化成键名）；
 *   ③ 零回调、零闭包跨边界 —— WndProc 收下的东西一律进队列，由泵取出。
 *
 * ★ 为什么 M1 不链 Skia：`RasterCanvas` 已经是零 OS 依赖的完整软光栅（§7.2 裁决 1），
 *   贴屏走 `SetDIBitsToDevice` 即可跑通"一个窗口画出 Hello Drift"。§7.3 的分期
 *   本来就写着"M1 = 位块传送"，而 `SurfaceProvider` 这层抽象存在的意义正是
 *   "后端可换" ⇒ Skia 后端接同一个端口，应用侧一行不改。
 */

#define NOMINMAX

#include <phpx.h>
#include <windows.h>
#include <imm.h>
#include <windowsx.h>   // GET_X_LPARAM / GET_Y_LPARAM（在 windowsx.h，不在 windows.h）

#include <string>
#include <vector>

using namespace php;

namespace {

// ─────────────────────────────────────────────────────────────── 常量 ──

/** 窗口类名。一个进程只有一个主窗口 ⇒ 常量即可，不必按实例区分 */
const wchar_t *const kWindowClass = L"DriftWindowClass";

/**
 * "把同步消息转成队列消息"用的自定义消息。
 *
 * ★ 为什么必须有它：`WM_SIZE` / `WM_DPICHANGED` / `WM_TIMER` 里，前两个是
 *   `DefWindowProc` **同步**调进 WndProc 的，`PeekMessage` 根本取不到；
 *   而事件数组要带 `w`/`h`/`id` 这些**载荷**，光靠"WndProc 往队列里塞"还不够 ——
 *   队列元素是自定义结构体，所以 WndProc 直接 push，再用这条消息把泵**叫醒**。
 */
const UINT kMsgWake = WM_APP + 1;

/** 队列元素种类（壳内部用；跨边界的名字在 `toArray()` 里给） */
enum EventKind {
    kEvResize = 1,
    kEvDpi,
    kEvTimer,
    kEvPointer,
    kEvKey,
    kEvChar,
    kEvTheme,
};

struct PendingEvent {
    int kind;
    int a;   // resize: w ／ dpi: dpi ／ timer: id ／ pointer: x ／ key: — ／ char: —
    int b;   // resize: h ／ pointer: y ／ —
    int c;   // pointer: button ／ key: modifier 位（1=ctrl 2=alt 4=shift）／ —
    std::string text;   // key: 键名 ／ char: UTF-8 文本
};

// ─────────────────────────────────────────────────────────────── 状态 ──

HWND g_main = NULL;
int g_clientW = 0;
int g_clientH = 0;
UINT g_dpi = 96;

// ── IME 定位（§8.4）── 设备像素的客户区坐标；(-1,-1,0) = 无插入符
// 两个消费点：组字窗位置（WM_IME_STARTCOMPOSITION / CFS_POINT）与
// IMR_QUERYCHARPOSITION 应答。内部换算用 g_dpi（与窗口同一 dpr）。
float g_imeX = -1.0f;
float g_imeY = -1.0f;
float g_imeH = 0.0f;

/** 待交付事件队列。WndProc 填，`php_drift_win_pump()` 取 —— 同线程，无需加锁（§5.3） */
std::vector<PendingEvent> g_queue;

/**
 * 上一帧的像素（BGRA，自上而下）。
 *
 * ★ 必须缓存：窗口被遮住又露出来时系统重发 `WM_PAINT`，而那一刻 PHP 正阻塞在
 *   `drift_win_pump()` 里等事件 ⇒ 不重贴缓存就是白屏。GUI 仓的 `guiBlitToScreen`
 *   同样依赖 `g_bitmap` 常驻。
 */
std::vector<unsigned char> g_frame;
int g_frameW = 0;
int g_frameH = 0;

// 当前指针形状。★ **必须缓存**：`WM_SETCURSOR` 每次鼠标移动都会来一次，而那一刻
// PHP 侧可能正阻塞在 `GetMessageW` 里（问不到框架）⇒ 只能重贴**上一次**的决定。
// 见 `php_drift_win_set_cursor()` 与 WndProc 的 `WM_SETCURSOR` 分支。
HCURSOR g_cursor = NULL;

// ★★ `IDC_*` 是 `MAKEINTRESOURCE(...)` 宏：**非 UNICODE 编译下展开成 `LPSTR`**，
//   直接喂 `LoadCursorW` 就是 C2664 / C2440（本文件第二次撞这个坑 —— 第一次是
//   `DefWindowProc` 必须显式写 `W` 后缀，见 WndProc 里的注释）。根因同一个：
//   **Win32 的 `*W` API 不认按 ANSI 展开的宏**。
//   ⇒ 这里用 `MAKEINTRESOURCEW(数值)`：与编译期 `UNICODE` 设置**无关**，数值取自
//     `<winuser.h>` 的原始定义（注释里写名字，好搜；WinUser 改了才需要跟着改）。
LPCWSTR cursorIdForShape(int shape) {
    switch (shape) {
        case 1:  return MAKEINTRESOURCEW(32513);   // IDC_IBEAM
        case 2:  return MAKEINTRESOURCEW(32649);   // IDC_HAND
        case 3:  return MAKEINTRESOURCEW(32646);   // IDC_SIZEALL
        case 4:  return MAKEINTRESOURCEW(32645);   // IDC_SIZENS
        case 5:  return MAKEINTRESOURCEW(32644);   // IDC_SIZEWE
        case 6:  return MAKEINTRESOURCEW(32514);   // IDC_WAIT
        case 7:  return MAKEINTRESOURCEW(32648);   // IDC_NO
        default: return MAKEINTRESOURCEW(32512);   // IDC_ARROW（0 与未知值同路）
    }
}

// ────────────────────────────────────────────────────── 字符串换算 ──

/** UTF-8（PHP 侧）→ UTF-16（Win32 W 系列 API） */
std::wstring toWide(const String &text) {
    size_t length = text.length();
    if (length == 0) {
        return std::wstring();
    }

    int needed = MultiByteToWideChar(CP_UTF8, 0, text.data(), (int)length, NULL, 0);
    if (needed <= 0) {
        return std::wstring();
    }

    std::wstring out;
    out.resize((size_t)needed);
    MultiByteToWideChar(CP_UTF8, 0, text.data(), (int)length, &out[0], needed);
    return out;
}

/** UTF-16 → UTF-8 */
std::string toUtf8(const wchar_t *text, int length) {
    if (text == NULL || length <= 0) {
        return std::string();
    }

    int needed = WideCharToMultiByte(CP_UTF8, 0, text, length, NULL, 0, NULL, NULL);
    if (needed <= 0) {
        return std::string();
    }

    std::string out;
    out.resize((size_t)needed);
    WideCharToMultiByte(CP_UTF8, 0, text, length, &out[0], needed, NULL, NULL);
    return out;
}

// ──────────────────────────────────────────────────── 键码归一化 ──

/**
 * 虚拟键码 → **可读键名**。
 *
 * ★ 归一化必须发生在这里，不是 PHP 侧：`KeyChord` 的 docblock 逐字写着
 *   "键名是可读字符串，**不是**平台虚拟键码 —— 键码是 `Platform` 层的私事"。
 *   把 VK 表搬到 PHP 就是把平台常量漏进上层（§5.1 纪律 1）。
 *
 * ★ 未映射的键返回**空串**，由 `php_drift_win_pump()` 丢弃该事件。
 *   这不是"静默吞掉"：能到达这里的键只有 M1 尚未关心的符号键（`VK_OEM_*` 等），
 *   而它们今天**没有任何消费者** —— 有消费者的键一个不少地在这里。
 *   （GUI 仓的 `win_build_accelerators` 对不认识的键名同样是"跳过"，同一处理。）
 */
std::string keyNameFromVk(WPARAM vk) {
    if (vk >= 'A' && vk <= 'Z') {
        return std::string(1, (char)vk);
    }
    if (vk >= '0' && vk <= '9') {
        return std::string(1, (char)vk);
    }
    if (vk >= VK_F1 && vk <= VK_F24) {
        int n = (int)(vk - VK_F1) + 1;
        return std::string("F") + (n >= 10 ? std::string(1, (char)('0' + n / 10)) : std::string())
             + std::string(1, (char)('0' + n % 10));
    }

    switch (vk) {
        case VK_TAB:     return "Tab";
        case VK_RETURN:  return "Enter";
        case VK_ESCAPE:  return "Escape";
        case VK_BACK:    return "Backspace";
        case VK_DELETE:  return "Delete";
        case VK_LEFT:    return "Left";
        case VK_RIGHT:   return "Right";
        case VK_UP:      return "Up";
        case VK_DOWN:    return "Down";
        case VK_HOME:    return "Home";
        case VK_END:     return "End";
        case VK_PRIOR:   return "PageUp";
        case VK_NEXT:    return "PageDown";
        case VK_SPACE:   return "Space";
        default:         return std::string();
    }
}

/** 当前修饰键物理状态（位：1=Ctrl 2=Alt 4=Shift） */
int modifierBits() {
    int bits = 0;
    // ★ GetKeyState 读的是**本线程消息队列**的键状态 ⇒ 只有窗口在前台时可信。
    //   这不算缺陷：后台窗口收不到 WM_KEYDOWN，本来也不会走到这里。
    if ((GetKeyState(VK_CONTROL) & 0x8000) != 0) bits |= 1;
    if ((GetKeyState(VK_MENU)    & 0x8000) != 0) bits |= 2;
    if ((GetKeyState(VK_SHIFT)   & 0x8000) != 0) bits |= 4;
    return bits;
}

// ──────────────────────────────────────────────────────────── 队列 ──

void enqueue(const PendingEvent &event) {
    g_queue.push_back(event);
    if (g_main != NULL) {
        // 把阻塞在 GetMessageW 上的泵叫醒。载荷在队列里，这条消息只是闹钟。
        PostMessageW(g_main, kMsgWake, 0, 0);
    }
}

// ───────────────────────────────────────────────────────────── 贴屏 ──

/** 把缓存帧贴到给定 DC。`WM_PAINT` 与 `present()` 共用这一条路径。 */
void presentTo(HDC dc) {
    if (g_frame.empty() || g_frameW <= 0 || g_frameH <= 0 || dc == NULL) {
        return;
    }

    BITMAPINFO info = {};
    info.bmiHeader.biSize        = sizeof(BITMAPINFOHEADER);
    info.bmiHeader.biWidth       = g_frameW;
    info.bmiHeader.biHeight      = -g_frameH;   // 负高 = 自上而下，与 RGBA 行序一致
    info.bmiHeader.biPlanes      = 1;
    info.bmiHeader.biBitCount    = 32;
    info.bmiHeader.biCompression = BI_RGB;

    SetDIBitsToDevice(dc, 0, 0, g_frameW, g_frameH, 0, 0, 0, g_frameH,
                      g_frame.data(), &info, DIB_RGB_COLORS);
}

// ─────────────────────────────────────────────────────────── WndProc ──

LRESULT CALLBACK driftWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp) {
    switch (msg) {
        case WM_CLOSE:
            // 不调 DefWindowProc：它会走"关闭确认"那套，而 Drift 的退出语义
            // 只有一条（销毁 ⇒ WM_QUIT ⇒ 泵返回 quit，见 WindowHost::close 的 docblock）。
            DestroyWindow(hwnd);
            return 0;

        case WM_DESTROY:
            PostQuitMessage(0);
            return 0;

        case WM_SIZE: {
            int w = LOWORD(lp);
            int h = HIWORD(lp);
            // 最小化时 lp 是 0；宽高非正一律忽略（恢复时会再来一条 WM_SIZE）
            if (w > 0 && h > 0) {
                g_clientW = w;
                g_clientH = h;
                // 旧帧尺寸作废：留着会在 WM_PAINT 时把旧尺寸的画面贴到新客户区
                g_frame.clear();
                g_frameW = g_frameH = 0;

                PendingEvent event = {};
                event.kind = kEvResize;
                event.a = w;
                event.b = h;
                enqueue(event);
            }
            return 0;
        }

        case WM_SETTINGCHANGE: {
            // §9.2：系统主题变更（深色/浅色切换）。lParam 是被改的设置区名
            // （宽字符指针）—— 时区、语言、显示设置等也会发这条消息，只认
            // "ImmersiveColorSet"（Win10+ 的颜色模式区）。
            if (lp != 0 && wcscmp((LPCWSTR)lp, L"ImmersiveColorSet") == 0) {
                PendingEvent event = {};
                event.kind = kEvTheme;
                enqueue(event);
            }
            return 0;
        }

        case WM_DPICHANGED: {
            g_dpi = HIWORD(wp);
            // 系统给了一个建议外框，照它调整（不照做的话窗口在新 DPI 下尺寸不对）
            RECT *suggested = (RECT *)lp;
            if (suggested != NULL) {
                SetWindowPos(hwnd, NULL,
                             suggested->left, suggested->top,
                             suggested->right - suggested->left,
                             suggested->bottom - suggested->top,
                             SWP_NOZORDER | SWP_NOACTIVATE);
            }

            PendingEvent event = {};
            event.kind = kEvDpi;
            event.a = (int)g_dpi;
            enqueue(event);
            return 0;
        }

        case WM_TIMER: {
            PendingEvent event = {};
            event.kind = kEvTimer;
            event.a = (int)wp;   // id 是 PHP 给的，这里只是原样回传
            enqueue(event);
            return 0;
        }

        case WM_PAINT: {
            PAINTSTRUCT ps;
            HDC dc = BeginPaint(hwnd, &ps);
            presentTo(dc);
            EndPaint(hwnd, &ps);
            return 0;
        }

        case WM_ERASEBKGND:
            // 客户区整块由我们贴 ⇒ 让系统擦背景只会多一次全屏填充并造成闪烁
            return 1;

        case WM_SETCURSOR:
            // ★ 客户区里自己贴并返回 TRUE（= 已处理）。不这么做的话 DefWindowProc
            //   会把光标重置回窗口类光标（本类 hCursor = NULL ⇒ 默认箭头）⇒ 框架
            //   设的形状只显示到下一次鼠标移动，**且不报错**。
            // ★ 非客户区（标题栏 / 边框）`break` 交回 DefWindowProc —— 那里的
            //   拉伸/箭头光标该由系统决定，自绘没有好处。
            if (LOWORD(lp) == HTCLIENT) {
                SetCursor(g_cursor != NULL ? g_cursor : LoadCursorW(NULL, cursorIdForShape(0)));
                return TRUE;
            }
            break;

        case WM_LBUTTONDOWN:
        case WM_MBUTTONDOWN:
        case WM_RBUTTONDOWN: {
            int button = 0;
            if (msg == WM_MBUTTONDOWN) button = 1;
            if (msg == WM_RBUTTONDOWN) button = 2;

            PendingEvent event = {};
            event.kind = kEvPointer;
            event.a = GET_X_LPARAM(lp);
            event.b = GET_Y_LPARAM(lp);
            event.c = button;
            enqueue(event);
            return 0;
        }

        case WM_KEYDOWN: {
            std::string name = keyNameFromVk(wp);
            if (name.empty()) {
                return 0;   // M1 未映射的符号键（见 keyNameFromVk 的注）
            }

            PendingEvent event = {};
            event.kind = kEvKey;
            event.c = modifierBits();
            event.text = name;
            enqueue(event);
            return 0;
        }

        case WM_CHAR: {
            // TranslateMessage 已经把 WM_KEYDOWN 合成成了 WM_CHAR；控制字符
            // （退格、回车…）也走这条路，但它们**同时**是 WM_KEYDOWN ⇒ 会重复。
            // 判据：`< 0x20` 的码位是控制键产生的，由 key 事件负责，这里丢掉。
            if (wp < 0x20) {
                return 0;
            }

            wchar_t ch = (wchar_t)wp;
            PendingEvent event = {};
            event.kind = kEvChar;
            event.text = toUtf8(&ch, 1);
            enqueue(event);
            return 0;
        }

        case WM_IME_STARTCOMPOSITION: {
            // 组字开始：系统将建自己的子窗口（DefWindowProc 做的）。我们只把
            // 它的位置指到光标处 —— CFS_POINT（GUI 基线：组字窗 CFS_POINT、
            // 候选窗不硬塞，"建议位置"的语义系统可调）。
            // ★ ClientToScreen 是必须的：ImmSetCompositionWindow 吃屏幕坐标，
            //   而 g_imeX/Y 是设备像素的客户区坐标。
            HIMC imc = ImmGetContext(hwnd);
            if (imc != NULL && g_imeX >= 0.0f) {
                double imeScale = (double)g_dpi / 96.0;
                POINT pt = {(LONG)((double)g_imeX / imeScale), (LONG)((double)g_imeY / imeScale)};
                ClientToScreen(hwnd, &pt);
                COMPOSITIONFORM form = {};
                form.dwStyle = CFS_POINT;
                form.ptCurrentPos = pt;
                ImmSetCompositionWindow(imc, &form);
            }
            if (imc != NULL) {
                ImmReleaseContext(hwnd, imc);
            }
            return 0;   // ⚠ 不吞消息：DefWindowProc 之后仍要收到它建组字窗
        }

        case WM_IME_COMPOSITION: {
            // 只取 GCS_RESULTSTR（提交串）—— 走既有 kEvChar 路径，TextField 零改动。
            // ⚠ GCS_COMPSTR（组字串）**不读**：那条是自绘路线，GUI 战史结论是
            //   交给系统绘制（litehtml-lab 自绘路线明写"不照抄"）。
            if (lp & GCS_RESULTSTR) {
                HIMC imc = ImmGetContext(hwnd);
                if (imc != NULL) {
                    LONG bytes = ImmGetCompositionStringW(imc, GCS_RESULTSTR, NULL, 0);
                    if (bytes > 0) {
                        int wchars = bytes / (LONG)sizeof(wchar_t);
                        std::vector<wchar_t> buf((size_t)wchars);
                        ImmGetCompositionStringW(imc, GCS_RESULTSTR, buf.data(), bytes);
                        PendingEvent event = {};
                        event.kind = kEvChar;
                        event.text = toUtf8(buf.data(), (int)wchars);
                        enqueue(event);
                    }
                    ImmReleaseContext(hwnd, imc);
                }
            }
            return 0;   // ⚠ 同理：ENDCOMPOSITION 之后 DefWindowProc 还要拆窗
        }

        case WM_IME_REQUEST: {
            // IME 只问一次的字符位置查询（GCS 全在 GUI 战史里踩过）。
            // 一律应答 —— 不答应会让输入法回退到"窗口左上角"乱放。
            if (wp == IMR_QUERYCHARPOSITION) {
                IMECHARPOSITION *pos = (IMECHARPOSITION *)lp;
                if (pos != NULL) {
                    pos->dwSize = sizeof(IMECHARPOSITION);
                    pos->dwCharPos = 0;
                    if (g_imeX < 0.0f || hwnd == NULL) {
                        // 无插入符：报客户区左上角（0,0）高度 1 —— 合法值。
                        pos->pt.x = 0;
                        pos->pt.y = 0;
                        pos->cLineHeight = 1;
                        RECT rc = {};
                        GetClientRect(hwnd, &rc);
                        pos->rcDocument = rc;
                    } else {
                        double imeScale = (double)g_dpi / 96.0;
                        LONG cx = (LONG)((double)g_imeX / imeScale);
                        LONG cy = (LONG)((double)g_imeY / imeScale);
                        POINT pt = {cx, cy};
                        ClientToScreen(hwnd, &pt);   // 同样吃屏幕坐标
                        pos->pt.x = pt.x;
                        pos->pt.y = pt.y;
                        pos->cLineHeight = (LONG)((double)g_imeH / imeScale);
                        RECT rc = {};
                        GetClientRect(hwnd, &rc);
                        pos->rcDocument = rc;
                    }
                    return 1;   // 已处理（不落 DefWindowProc）
                }
            }
            break;
        }

        default:
            break;
    }

    // ★ 必须写 DefWindowProcW（显式 W 后缀）：`DefWindowProc` 宏在非 UNICODE 编译
    //   配置下会展开成 A 版，而本文件全程用 W 系列 API —— 混用会让 WM_CHAR 收到
    //   的是 ANSI 码位（GUI 仓同名坑，注释在 `gui_win32.cc` 第 450 行附近）。
    return DefWindowProcW(hwnd, msg, wp, lp);
}

// ────────────────────────────────────────────────────── 事件 → 数组 ──

Array toArray(const PendingEvent &event) {
    Array out;

    switch (event.kind) {
        case kEvResize:
            out.set("type", "resize");
            out.set("w", (Int)event.a);
            out.set("h", (Int)event.b);
            break;

        case kEvDpi:
            out.set("type", "dpi");
            out.set("dpi", (Int)event.a);
            break;

        case kEvTimer:
            out.set("type", "timer");
            out.set("id", (Int)event.a);
            break;

        case kEvPointer:
            out.set("type", "pointer");
            out.set("x", (Int)event.a);
            out.set("y", (Int)event.b);
            out.set("button", (Int)event.c);
            break;

        case kEvKey:
            out.set("type", "key");
            out.set("key", String(event.text));
            out.set("ctrl", (Bool)((event.c & 1) != 0));
            out.set("alt", (Bool)((event.c & 2) != 0));
            out.set("shift", (Bool)((event.c & 4) != 0));
            break;

        case kEvChar:
            out.set("type", "char");
            out.set("text", String(event.text));
            break;

        case kEvTheme:
            out.set("type", "theme");
            break;

        default:
            out.set("type", "quit");
            break;
    }

    return out;
}

Array eventQuit() {
    Array out;
    out.set("type", "quit");
    return out;
}

// ────────────────────────────────────────────────────────── DPI 感知（§6） ──

/**
 * 把本进程设成 **Per-Monitor DPI Aware V2** —— §6 的坐标模型要求它。
 *
 * ★ 为什么**不能**用 `SetProcessDPIAware()`：那是 **system-DPI-aware（V1）**。V1 进程
 *   跨屏到另一块不同 DPI 的显示器时，系统把窗口内容**整体位图拉伸**（⇒ 糊），
 *   而且 **`WM_DPICHANGED` 根本不会发**（那条消息只发给 per-monitor 感知的进程）
 *   ⇒ `Screen::scale` 永远停在系统 DPI，§6 的"跨屏 = scale 变更 = 布局事件"落空。
 *   这正是 §19 M1 那句"分数缩放下不糊"要消灭的形态。
 * ★ 为什么 `GetProcAddress` 动态取：`SetProcessDpiAwarenessContext` 与
 *   `DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2` 在 `winuser.h` 里被
 *   `WINVER >= 0x0A00` 包着，而本工程的 `cxx-flags` **不抬** `WINVER`
 *   （抬它会连带改变框架侧所有 Windows 头的可见面）。动态取不碰编译宏。
 * ★ 三级回退，新系统走第一级：V2（Win10 1703+）→ shcore 的 per-monitor（Win8.1+）
 *   → V1（Vista+）。全失败就什么都不做 —— 那是 Vista 之前，本框架不支持。
 * ★ **必须在建窗 / 取 DC 之前调用**：Windows 在第一次建窗或取 DC 时就锁定感知级别，
 *   之后再设一律失败（返回 FALSE）。
 */
void enablePerMonitorDpi() {
    // DPI_AWARENESS_CONTEXT 是不透明句柄；这个负值是 winuser.h 的原始定义
    // （`DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2`）。本工程不抬 WINVER ⇒
    // 拿不到那个宏，只能按数值用（名字写在注释里，好搜）。
    const HANDLE kPerMonitorV2 = (HANDLE)(INT_PTR)-4;

    HMODULE user32 = GetModuleHandleW(L"user32.dll");
    if (user32 != NULL) {
        typedef BOOL (WINAPI *SetAwarenessContextFn)(HANDLE);
        SetAwarenessContextFn setContext =
            (SetAwarenessContextFn)(void *)GetProcAddress(user32, "SetProcessDpiAwarenessContext");
        if (setContext != NULL && setContext(kPerMonitorV2) != FALSE) {
            return;
        }
    }

    // Windows 8.1+：shcore.dll 的 SetProcessDpiAwareness(PROCESS_PER_MONITOR_DPI_AWARE = 2)
    HMODULE shcore = LoadLibraryW(L"shcore.dll");
    if (shcore != NULL) {
        typedef HRESULT (WINAPI *SetAwarenessFn)(int);
        SetAwarenessFn setAwareness =
            (SetAwarenessFn)(void *)GetProcAddress(shcore, "SetProcessDpiAwareness");
        if (setAwareness != NULL && setAwareness(2) == 0) {   // S_OK
            return;
        }
    }

    SetProcessDPIAware();   // 兜底：V1。至少不糊在"系统 DPI"那一档
}

/**
 * 窗口所在**显示器**的 DPI。
 *
 * ★ 优先 `GetDpiForWindow`（Win10 1607+）：它给的是**每显示器** DPI，多屏 / 分数缩放
 *   下才正确。同样动态取（理由同 `enablePerMonitorDpi` 的 `WINVER` 那条）。
 * ★ 退 `GetDeviceCaps(LOGPIXELSX)`：只拿得到**系统** DPI —— 对多屏场景不够。
 *   **这是降级，不是等价**。
 */
UINT dpiOfWindow(HWND hwnd) {
    if (hwnd != NULL) {
        HMODULE user32 = GetModuleHandleW(L"user32.dll");
        if (user32 != NULL) {
            typedef UINT (WINAPI *GetDpiForWindowFn)(HWND);
            GetDpiForWindowFn getDpi =
                (GetDpiForWindowFn)(void *)GetProcAddress(user32, "GetDpiForWindow");
            if (getDpi != NULL) {
                UINT dpi = getDpi(hwnd);
                if (dpi > 0) {
                    return dpi;
                }
            }
        }

        HDC dc = GetDC(hwnd);
        if (dc != NULL) {
            int dpi = GetDeviceCaps(dc, LOGPIXELSX);
            ReleaseDC(hwnd, dc);
            if (dpi > 0) {
                return (UINT)dpi;
            }
        }
    }

    return g_dpi;
}

/**
 * DPI 感知的 `AdjustWindowRectEx`。
 *
 * ★ 老的 `AdjustWindowRectEx` **不是** DPI 感知的：它按**系统** DPI 算标题栏/边框的
 *   厚度 ⇒ 分数缩放下外框偏 ⇒ "请求 640 逻辑宽、拿到 624 客户区"。
 *   `AdjustWindowRectExForDpi`（Win10 1607+）收一个显式 DPI，才是对的。
 *   同样动态取，失败退老 API。
 */
void adjustFrameForDpi(RECT *rect, DWORD style, BOOL menu, DWORD exStyle, UINT dpi) {
    HMODULE user32 = GetModuleHandleW(L"user32.dll");
    if (user32 != NULL) {
        typedef BOOL (WINAPI *AdjustForDpiFn)(LPRECT, DWORD, BOOL, DWORD, UINT);
        AdjustForDpiFn adjust =
            (AdjustForDpiFn)(void *)GetProcAddress(user32, "AdjustWindowRectExForDpi");
        if (adjust != NULL && adjust(rect, style, menu, exStyle, dpi) != FALSE) {
            return;
        }
    }

    AdjustWindowRectEx(rect, style, menu, exStyle);
}

}  // namespace

// ══════════════════════════════════════════════════════ 窗口生命周期 ══

Int php_drift_win_create(String title, Int width, Int height) {
    // ★ **必须在建窗 / 取 DC 之前**：Windows 在第一次建窗或取 DC 时就锁定 DPI 感知
    //   级别（§6 的分数缩放模型要求我们自己按物理像素画，而只有 per-monitor 感知才
    //   拿得到"每显示器 DPI + WM_DPICHANGED"）。详见 enablePerMonitorDpi() 的注释。
    enablePerMonitorDpi();

    // 建窗前拿不到**窗口** DPI ⇒ 先用**系统** DPI 起算；建窗后再用 GetDpiForWindow
    // 纠正（多显示器时窗口可能落在另一块屏上，见函数尾）。
    HDC screen = GetDC(NULL);
    if (screen != NULL) {
        int systemDpi = GetDeviceCaps(screen, LOGPIXELSX);
        ReleaseDC(NULL, screen);
        if (systemDpi > 0) {
            g_dpi = (UINT)systemDpi;
        }
    }
    if (g_dpi == 0) {
        g_dpi = 96;
    }

    // 建窗用的 DPI（建窗后会用 GetDpiForWindow 纠正；两者不同就得挪窗口）
    const UINT preCreateDpi = g_dpi;

    HINSTANCE instance = GetModuleHandleW(NULL);

    WNDCLASSEXW windowClass = {};
    windowClass.cbSize        = sizeof(windowClass);
    windowClass.style         = CS_HREDRAW | CS_VREDRAW;
    windowClass.lpfnWndProc   = driftWndProc;
    windowClass.hInstance     = instance;
    windowClass.hCursor       = LoadCursor(NULL, IDC_ARROW);
    windowClass.hbrBackground = NULL;   // 自绘：不要系统背景刷
    windowClass.lpszClassName = kWindowClass;
    windowClass.hIcon         = LoadIcon(NULL, IDI_APPLICATION);
    windowClass.hIconSm       = LoadIcon(NULL, IDI_APPLICATION);
    RegisterClassExW(&windowClass);

    // 请求的是**客户区**尺寸（逻辑像素）⇒ 先按 DPI 放大，再让系统把外框算进去。
    // 不做这一步，用户拿到的是"窗口 640 宽、能画的只有 624"。
    double scale = (double)g_dpi / 96.0;
    RECT frame = {0, 0, (LONG)((double)(int)width * scale), (LONG)((double)(int)height * scale)};
    adjustFrameForDpi(&frame, WS_OVERLAPPEDWINDOW | WS_CLIPCHILDREN, FALSE, 0, g_dpi);

    std::wstring wideTitle = toWide(title);

    HWND hwnd = CreateWindowExW(
        0,
        kWindowClass,
        wideTitle.c_str(),
        WS_OVERLAPPEDWINDOW | WS_CLIPCHILDREN,
        CW_USEDEFAULT, CW_USEDEFAULT,
        frame.right - frame.left, frame.bottom - frame.top,
        NULL, NULL, instance, NULL);

    if (hwnd == NULL) {
        return 0;   // 调用方（Win32WindowHost）负责报 Fatal，这里不静默
    }

    g_main = hwnd;

    // ★ 假插入符（IME 战史基线）：IME 默认把组字/候选窗贴到"插入符"旁，而
    //   插入符只存在于真实文本框里（系统为每个顶层窗口维护一个 caret 槽）。
    //   CreateCaret 登记一个 1x1 的位图 caret + SetCaretPos 定位 —— **不
    //   ShowCaret**：闪烁相位与自绘光标重叠，且 GUI 战史证明 ShowCaret 的
    //   因果未定。我们只是借这个槽给 IME 一个可查的位置。
    CreateCaret(hwnd, (HBITMAP)1, 1, 1);
    SetCaretPos(0, 0);

    // ★ 建窗后纠正 DPI：`CW_USEDEFAULT` 可能把窗口放在**另一块 DPI** 的显示器上，
    //   而上面按系统 DPI 算的外框/客户区就偏了。`GetDpiForWindow` 给的是**每显示器**
    //   真值 ⇒ 用它重算，并在不同时 `SetWindowPos` 把外框纠正过来。
    UINT actualDpi = dpiOfWindow(hwnd);
    double actualScale = (double)actualDpi / 96.0;
    g_dpi = actualDpi;
    g_clientW = (int)((double)(int)width * actualScale);
    g_clientH = (int)((double)(int)height * actualScale);

    if (actualDpi != preCreateDpi) {
        RECT fixed = {0, 0, (LONG)((double)(int)width * actualScale), (LONG)((double)(int)height * actualScale)};
        adjustFrameForDpi(&fixed, WS_OVERLAPPEDWINDOW | WS_CLIPCHILDREN, FALSE, 0, actualDpi);
        SetWindowPos(hwnd, NULL, 0, 0, fixed.right - fixed.left, fixed.bottom - fixed.top,
                     SWP_NOMOVE | SWP_NOZORDER | SWP_NOACTIVATE);
    }

    return (Int)(INT_PTR)hwnd;
}

void php_drift_win_show(Int hwnd) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        return;
    }
    ShowWindow(target, SW_SHOW);
    UpdateWindow(target);
}

void php_drift_win_close(Int hwnd) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target != NULL) {
        DestroyWindow(target);
    }
}

void php_drift_win_set_cursor(Int hwnd, Int shape) {
    // ★ `shape` 是 `Drift\Input\CursorShape` 的 value（`src/Input/CursorShape.php`）；
    //   名字→`IDC_*` 的映射只有 `cursorIdForShape()` 一处 —— 两边必须同步改。
    // ★ `LoadCursorW(NULL, …)` 的 IDC_* 是**系统资源**（MAKEINTRESOURCE），
    //   不需要 DestroyCursor，也不随窗口销毁而失效 ⇒ 缓存裸 HCURSOR 是安全的。
    g_cursor = LoadCursorW(NULL, cursorIdForShape((int)shape));

    HWND target = (HWND)(INT_PTR)hwnd;
    if (target != NULL) {
        // 立刻生效（不等下一次 WM_SETCURSOR）—— 否则"移进去要动一下才变"
        SetCursor(g_cursor);
    }
}

void php_drift_win_set_title(Int hwnd, String title) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        return;
    }
    SetWindowTextW(target, toWide(title).c_str());
}

void php_drift_win_ime_caret(Float x, Float y, Float h) {
    // §8.4：IME 定位管线。x/y/h 是**设备像素**的客户区坐标；(-1,-1,0) = 无插入符。
    // 两个消费点：组字窗位置（WM_IME_STARTCOMPOSITION）与 IMR_QUERYCHARPOSITION。
    g_imeX = x;
    g_imeY = y;
    g_imeH = h;

    if (g_main == NULL) {
        return;
    }
    double imeScale = (double)g_dpi / 96.0;
    if (x < 0.0f) {
        // 无插入符：把假 caret 挪到客户区外（收起），IME 查到的位置退左上角
        SetCaretPos(-10, -10);
        return;
    }
    LONG cx = (LONG)((double)x / imeScale);
    LONG cy = (LONG)((double)y / imeScale);
    SetCaretPos(cx, cy);
    // ★ 没有"主动通知 IME 位置变了"这个 API —— 位置在下次
    //   WM_IME_STARTCOMPOSITION（组字开始）与 IMR_QUERYCHARPOSITION
    //   （IME 主动问）时被读取，两个都是拉模型。推只会踩
    //   ImmSetCompositionWindow 的空指针坑（imc 为空时不调）。
}

Array php_drift_win_client_size(Int hwnd) {
    HWND target = (HWND)(INT_PTR)hwnd;
    Array out;
    if (target == NULL) {
        return out;
    }

    RECT rect;
    if (!GetClientRect(target, &rect)) {
        return out;
    }

    out.set("w", (Int)(rect.right - rect.left));
    out.set("h", (Int)(rect.bottom - rect.top));
    return out;
}

Bool php_drift_win_is_maximized(Int hwnd) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        return (Bool)false;
    }
    return (Bool)(IsZoomed(target) != FALSE);
}

// ══════════════════════════════════════════════════════════════ 屏幕 ══

Int php_drift_win_dpi(Int hwnd) {
    // ★ 用 `dpiOfWindow()`：优先 `GetDpiForWindow`（**每显示器** DPI —— 多屏 / 分数
    //   缩放下才正确），退 `GetDeviceCaps`（只有系统 DPI，是**降级**不是等价）。
    //
    // ★ 这里曾写着"`GetDpiForWindow` 要 `_WIN32_WINNT >= 0x0A00`，为一个查询不值得抬宏
    //   ⇒ 用系统 DPI 也无差别，因为跨屏时系统会发 `WM_DPICHANGED`" —— **那句是错的**：
    //   `WM_DPICHANGED` **只发给 per-monitor 感知的进程**，而当时进程只是 system-aware
    //   ⇒ 跨屏既拿不到新 DPI、也被系统位图拉伸（糊）。2026-10-16 修（§24.1 #43）。
    //   正解不是"抬 WINVER"，是 `GetProcAddress` 动态取 —— 不碰编译宏。
    return (Int)dpiOfWindow((HWND)(INT_PTR)hwnd);
}

Bool php_drift_win_system_dark() {
    // §9.2：系统深色模式的唯一权威是 AppsUseLightTheme（0 = 深色）。
    // 读不到（键不存在 / 权限不足）按**浅色**降级 —— 这是 §5.4 第 2 条
    // "恢复失败静默降级到默认值"的同类场合，不是静默吞错：默认值就是浅色。
    HKEY key = NULL;
    if (RegOpenKeyExW(HKEY_CURRENT_USER,
                      L"Software\\Microsoft\\Windows\\CurrentVersion\\Themes\\Personalize",
                      0, KEY_QUERY_VALUE, &key) != ERROR_SUCCESS) {
        return (Bool)false;
    }

    DWORD value = 1;
    DWORD size = sizeof(value);
    DWORD type = 0;
    LONG status = RegQueryValueExW(key, L"AppsUseLightTheme", NULL, &type,
                                   (LPBYTE)&value, &size);
    RegCloseKey(key);

    if (status != ERROR_SUCCESS || type != REG_DWORD) {
        return (Bool)false;
    }

    return (Bool)(value == 0);
}

// ══════════════════════════════════════════════════════════════ 消息泵 ══

Array php_drift_win_pump() {
    for (;;) {
        MSG msg;
        BOOL got = GetMessageW(&msg, NULL, 0, 0);

        // 0 = WM_QUIT；-1 = 出错。两者都当作"循环结束"：出错时继续等只会空转。
        if (got == 0 || got == -1) {
            return eventQuit();
        }

        TranslateMessage(&msg);   // WM_KEYDOWN ⇒ WM_CHAR（也是 IME 的入口，M3 才接）
        DispatchMessageW(&msg);   // WndProc 把关心的事件塞进 g_queue

        if (!g_queue.empty()) {
            PendingEvent event = g_queue.front();
            g_queue.erase(g_queue.begin());
            return toArray(event);
        }
        // 没有关心的事件 ⇒ 继续等下一条。壳不把"不关心的消息"回传给 PHP
        // （否则泵会变成每帧几百次跨边界调用）。
    }
}

Float php_drift_win_now_ms() {
    return (Float)(double)GetTickCount64();
}

// ══════════════════════════════════════════════════════════════ 定时器 ══

Bool php_drift_win_set_timer(Int hwnd, Int id, Int ms) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL || (int)ms <= 0) {
        return (Bool)false;
    }
    UINT_PTR result = SetTimer(target, (UINT_PTR)(int)id, (UINT)(int)ms, NULL);
    return (Bool)(result != 0);
}

void php_drift_win_kill_timer(Int hwnd, Int id) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target != NULL) {
        KillTimer(target, (UINT_PTR)(int)id);
    }
}

// ═══════════════════════════════════════════════════════ 失败路径 / 呈现 ══

void php_drift_win_message_box(String title, String body) {
    std::wstring wideTitle = toWide(title);
    std::wstring wideBody = toWide(body);
    // MB_TASKMODAL + MB_SETFOREGROUND：中止序列不能被"框跑到别的窗口后面"卡住
    MessageBoxW(NULL, wideBody.c_str(), wideTitle.c_str(),
                MB_OK | MB_ICONERROR | MB_TASKMODAL | MB_SETFOREGROUND);
}

void php_drift_win_present(Int hwnd, String rgba, Int width, Int height) {
    HWND target = (HWND)(INT_PTR)hwnd;
    int w = (int)width;
    int h = (int)height;
    if (target == NULL || w <= 0 || h <= 0) {
        return;
    }

    size_t need = (size_t)w * (size_t)h * 4u;
    if (rgba.length() < need) {
        // 尺寸不符 ⇒ 整帧丢弃，不画半张：画一半会让"画面缺一块"看起来像布局 bug
        return;
    }

    if (g_frameW != w || g_frameH != h) {
        g_frame.assign(need, 0);
        g_frameW = w;
        g_frameH = h;
    }

    const unsigned char *src = (const unsigned char *)rgba.data();
    for (size_t i = 0; i < need; i += 4) {
        // RGBA（画布）⇒ BGRA（32bpp DIB）。alpha 置满：32bpp BI_RGB 忽略它，
        // 但留着半透明值会让某些远程桌面/截图路径按 alpha 混合，出现半透明窗口。
        g_frame[i + 0] = src[i + 2];
        g_frame[i + 1] = src[i + 1];
        g_frame[i + 2] = src[i + 0];
        g_frame[i + 3] = 0xFF;
    }

    HDC dc = GetDC(target);
    if (dc != NULL) {
        presentTo(dc);
        ReleaseDC(target, dc);
    }
}
