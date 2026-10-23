/**
 * probe-grab —— 从**进程外部**抓一个窗口的客户区像素，算墨量（"非白像素占比"）。
 *
 * 存在的理由：`probe-async` 的判据是"**全程零输入，界面自己变了**"。唯一不能被
 * 应用自述伪造的观测就是客户区像素 —— 应用说自己 Ready 了不算，得看见。
 *
 * ★ 为什么取像素要落到 C++（不是"新引入一门语言"）：本机 PHP 一条路都不通 ——
 *   `gd` / `FFI` / `com_dotnet` 三个扩展 `extension_loaded()` 全 false，
 *   PowerShell `Add-Type` 被安全策略拦。而 C++ 本来就是本项目的 AOT 目标语言。
 *
 * 用法（所有参数都是 `--key=value`，输出都是 `key=value` 一行一条）：
 *
 *   probe-grab --pid=1234 --image=users.exe --wait=8000 --settle=200 \
 *              --samples=30 --interval=100 --y0=220 --y1=640 --close --motion
 *   probe-grab --count-only --image=users.exe
 *   probe-grab --pid=1234 --settle=800 --move=300,200,1100,760 --rect --close
 *   probe-grab --pid=1234 --settle=1500 --rect --no-grab --close
 *   probe-grab --pid=1234 --settle=500 --cursor=250,456 --mdrag=250,456,400,456 \
 *              --png=drag.png                       （框选/拖动类手势）
 *   probe-grab --pid=1234 --settle=500 --deactivate --close
 *                                                    （模拟"用户切走"：WM_ACTIVATE/WA_INACTIVE）
 *   probe-grab --pid=1234 --settle=500 --keys=64,128,5F204F1F --close
 *                                                    （在客户区 64,128 点一下聚焦，再敲"张伟"）
 *                                                    （hex：4 位 = 一个 UTF-16 码元；过 IME 的话不可复现）
 *   probe-grab --pid=1234 --click=64,128 --chars=5F204F1F --close
 *                                                    （确定性字符输入：聚焦 + 逐码元 WM_CHAR）
 *
 * ★★ `--cursor` / `--mdrag` **必须真挪物理光标**（`SetCursorPos`），不能只
 *    `PostMessage` 合成消息：系统不认为指针在窗内 ⇒ 壳侧
 *   `TrackMouseEvent(TME_LEAVE)` 立刻回 `WM_MOUSELEAVE` ⇒ 应用"指针出窗"把
 *   手势收口，现象是"拖到第 2 步就断"、**间歇复现**（2026-10-21 实测：框选只选
 *   到第 1 个字符，成功率 ~50%；补 SetCursorPos 后 ~5/6，残余取决于窗口是否
 *   被遮挡）。⇒ 判据要允许"偶发断"，或把窗口提到前台再跑。
 *
 * 输出：`hwnd=` `client=WxH` `ink_all_pct=` `s0..sN=`（墨量）`c1..cN=`（帧间变化
 *      像素数，仅 `--motion`）`procs=` `closed=`
 *      ★ 窗口控制（2026-10-18 M5 加）：`moved=1` `zoomed=1` `unzoomed=1`
 *      `rect=x,y,w,h`（**外框**、物理像素）
 * 退出码：0 正常 · 2 没等到窗口/参数错 · 3 抓图失败
 *
 * ── 三个真 bug（写这个工具时踩出来的，判据就是被它们教出来的） ──
 *
 * ① **`FindWindowW` 命中 ≠ 窗口可用**：窗口对象先建、`ShowWindow` 与布局在后，
 *    此刻 `GetClientRect` 还是 **0×0** ⇒ 0 尺寸位图 ⇒ `GetDIBits` 返回 0 行。
 *    所以这里不用标题找窗口（中文标题还多一层编码坑），而是按 **pid** 枚举，
 *    并且判据写死为"**窗口在 且 客户区非空**"。
 *
 * ② **客户区非空 ≠ 已经画过第一帧**（同一个坑的邻居，2026-10-18 实测）：窗口刚
 *    `ShowWindow` 时应用还没画，`BitBlt` 抓到的是**那块屏幕上的残留**（实测列表区
 *    7.2% 墨，下一帧就变 0.0%）。⇒ `--settle=ms` 在首次抓图前等一下，让首帧落地。
 *    这一步不能省：它会把"首帧前的垃圾"读成"一开始就有内容"，判据 ② 直接假红。
 *
 * ③ **采样必须在进程内做**：外面循环 spawn 30 次，每次进程创建 + 找窗口 ≈ 10ms，
 *    合计 ~300ms，会把 1.2s 的窗口期采样成 2.4s —— 时间轴一错，"切换发生在哪一刻"
 *    就没意义了。所以 `--samples/--interval` 是本进程内的循环。
 *
 * ★ `biHeight = -height`：位图自顶向下。不这么写 `--y0/--y1` 量的是**底部**那几行，
 *   列表区判据会当场失效（而画面看起来完全正常）。
 *
 * ★ **为什么探针要能搬窗口**（2026-10-18 M5，§5.4）：`WindowStateStore` 的验收是
 *   "关窗时的位置/尺寸，下次启动原样还原"—— 而"位置被还原"这件事**只有进程外部**
 *   能观测（应用自述不算）。所以这里加 `--move/--zoom/--rect`：把窗口搬到已知位置、
 *   优雅关窗（`--close` 走 WM_CLOSE，让 store 的 persist 跑到）、再启动量一次外框，
 *   两次只差 ≤2px 才算还原。少了这三个开关，这条出口判据只能靠人眼。
 */

#define NOMINMAX

#include <windows.h>
#include <tlhelp32.h>
#include <wincodec.h>

#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <vector>
#include <algorithm>   // std::sort（--auto 的同名进程 pid 排序）

#pragma comment(lib, "user32.lib")
#pragma comment(lib, "gdi32.lib")
#pragma comment(lib, "windowscodecs.lib")
#pragma comment(lib, "ole32.lib")   // savePng 的 CoCreateInstance / CoInitializeEx

namespace {

/** 非白阈值：任一通道 < 245 就算"墨"。与旧 Python 探针同一口径。 */
constexpr unsigned char INK_LEVEL = 245;

struct Options {
    DWORD pid = 0;
    std::wstring image;
    int waitMs = 8000;
    int settleMs = 0;
    int samples = 0;
    int intervalMs = 100;
    int y0 = 0;
    int y1 = 0;
    bool close = false;
    bool countOnly = false;
    bool motion = false;
    // ── 窗口控制（M5，§5.4 的验证开关；见文件头末段）──
    bool move = false;
    int moveX = 0;
    int moveY = 0;
    int moveW = 0;
    int moveH = 0;
    int zoom = 0;             // 0 = 不动；1 = 最大化；2 = 还原
    bool printRect = false;   // 打印外框（物理像素）
    bool noGrab = false;      // 只做控制 + 打印，不碰像素
    bool autoResolve = false; // `--pid` 缺省时按 `--image` 找（取**最老**的那个进程）
    // ── 输入注入 + 存图（2026-10-19 滚轮方向判定用）──
    bool wheel = false;            // --wheel=x,y,delta（客户区坐标 + WHEEL_DELTA 倍数）
    int wheelX = 0;
    int wheelY = 0;
    int wheelDelta = 0;
    bool click = false;            // --click=x,y（客户区坐标；down+up 一次完整点击）
    int clickX = 0;
    int clickY = 0;
    bool setCursor = false;        // --cursor=x,y（客户区坐标；把**物理光标**挪进去）
    int cursorX = 0;
    int cursorY = 0;
    bool mouseDrag = false;        // --mdrag=x1,y1,x2,y2（**客户区**坐标：按下-移动-抬起）
    int mdragX1 = 0, mdragY1 = 0, mdragX2 = 0, mdragY2 = 0;
    bool dragResize = false;       // --drag=x1,y1,x2,y2（屏幕坐标；从左下角拖到新尺寸）
    int dragX1 = 0, dragY1 = 0, dragX2 = 0, dragY2 = 0;
    int dragSteps = 12;            // 拖动分几步发 WM_MOUSEMOVE（模拟真实拖动节奏）
    int grabStep = 0;              // >0 = 拖到第几步时抓一张（验证"拖动中"的画面）
    std::wstring midPngPath;       // 拖动中那张的存图路径
    int clickWaitMs = 600;         // 点完等多久（让应用处理点击）
    int wheelWaitMs = 600;         // 发完滚轮等多久再抓（让帧循环产出新帧）
    bool savePng = false;          // --png=path（WIC 编码，供 Read 直接看）
    std::wstring pngPath;
    bool deactivate = false;       // --deactivate（发 WM_ACTIVATE/WA_INACTIVE，模拟"用户切走"）
    // ── 键盘输入（2026-10-23：S5 搜索框真机验证用）──
    bool typeKeys = false;         // --keys=x,y,<hex UTF-16>
    int keysX = 0;
    int keysY = 0;
    std::wstring keysHex;          // 4 个 hex = 一个 UTF-16 码元（"张伟" = 5F204F1F）
    int keysWaitMs = 700;          // 敲完等多久再抓（让应用处理 onChanged + 重排 + 重画）
    bool printForeground = false;  // --fg（诊断：敲键盘时目标窗口是不是前台）
    // ── 字符输入（`--chars`：PostMessage(WM_CHAR)）──────────────────
    bool typeChars = false;        // --chars=<hex UTF-16>
    std::wstring charsHex;
};

struct Shot {
    int width = 0;
    int height = 0;
    std::vector<unsigned char> pixels;   // BGRA，自顶向下
};

bool parseInt(const std::wstring& text, long long& out)
{
    if (text.empty()) {
        return false;
    }
    wchar_t* end = nullptr;
    const long long value = std::wcstoll(text.c_str(), &end, 10);
    if (end == text.c_str() || *end != L'\0') {
        return false;
    }
    out = value;
    return true;
}

// ─────────────────────────────────────────────────────────── 找窗口 ──

struct FindContext {
    DWORD pid;
    HWND found;
};

BOOL CALLBACK enumWindowsProc(HWND hwnd, LPARAM param)
{
    auto* context = reinterpret_cast<FindContext*>(param);

    DWORD owner = 0;
    ::GetWindowThreadProcessId(hwnd, &owner);
    if (owner != context->pid) {
        return TRUE;
    }
    if (::IsWindowVisible(hwnd) == FALSE) {
        return TRUE;
    }

    // ★ bug ① 的判据：客户区非空才算"窗口可用"。
    RECT client{};
    ::GetClientRect(hwnd, &client);
    if (client.right - client.left <= 0 || client.bottom - client.top <= 0) {
        return TRUE;
    }

    context->found = hwnd;
    return FALSE;
}

struct CountContext {
    DWORD pid;
    int count;
};

/** 放宽版枚举：只按 **pid** 数顶层窗口，不看可见性与客户区（诊断用） */
BOOL CALLBACK countWindowsProc(HWND hwnd, LPARAM param)
{
    auto* context = reinterpret_cast<CountContext*>(param);
    DWORD owner = 0;
    ::GetWindowThreadProcessId(hwnd, &owner);
    if (owner == context->pid) {
        ++context->count;
    }
    return TRUE;
}

int countOwnerWindows(DWORD pid)
{
    CountContext context{pid, 0};
    ::EnumWindows(&countWindowsProc, reinterpret_cast<LPARAM>(&context));
    return context.count;
}

HWND waitWindow(DWORD pid, int waitMs)
{
    for (int waited = 0;; waited += 10) {
        FindContext context{pid, nullptr};
        ::EnumWindows(&enumWindowsProc, reinterpret_cast<LPARAM>(&context));
        if (context.found != nullptr) {
            return context.found;
        }
        if (waited >= waitMs) {
            return nullptr;
        }
        ::Sleep(10);
    }
}

// ───────────────────────────────────────────────────────────── 抓图 ──

bool grab(HWND hwnd, Shot& shot, std::string& error)
{
    RECT client{};
    ::GetClientRect(hwnd, &client);
    const int width = client.right - client.left;
    const int height = client.bottom - client.top;
    if (width <= 0 || height <= 0) {
        error = "client rect is empty";
        return false;
    }

    HDC windowDc = ::GetDC(hwnd);
    if (windowDc == nullptr) {
        error = "GetDC failed";
        return false;
    }
    HDC memoryDc = ::CreateCompatibleDC(windowDc);
    HBITMAP bitmap = ::CreateCompatibleBitmap(windowDc, width, height);
    HGDIOBJ previous = ::SelectObject(memoryDc, bitmap);

    bool ok = ::BitBlt(memoryDc, 0, 0, width, height, windowDc, 0, 0, SRCCOPY) != FALSE;

    BITMAPINFO info{};
    info.bmiHeader.biSize = sizeof(BITMAPINFOHEADER);
    info.bmiHeader.biWidth = width;
    info.bmiHeader.biHeight = -height;   // ★ 负高 = 自顶向下，见文件头
    info.bmiHeader.biPlanes = 1;
    info.bmiHeader.biBitCount = 32;
    info.bmiHeader.biCompression = BI_RGB;

    shot.pixels.assign(static_cast<size_t>(width) * static_cast<size_t>(height) * 4, 0);
    int lines = 0;
    if (ok) {
        lines = ::GetDIBits(memoryDc, bitmap, 0, static_cast<UINT>(height),
                            shot.pixels.data(), &info, DIB_RGB_COLORS);
    }
    if (!ok || lines == 0) {
        char buffer[128];
        std::snprintf(buffer, sizeof(buffer), "GetDIBits returned %d lines (error %lu)",
                      lines, static_cast<unsigned long>(::GetLastError()));
        error = buffer;
        ok = false;
    }

    ::SelectObject(memoryDc, previous);
    ::DeleteObject(bitmap);
    ::DeleteDC(memoryDc);
    ::ReleaseDC(hwnd, windowDc);

    if (ok) {
        shot.width = width;
        shot.height = height;
    }
    return ok;
}

/** 只量 `y0..y1` 这几行的墨量（`y1 <= 0` ⇒ 到底）。
 *
 *  ★ 为什么必须能分区量（2026-10-18 的假绿教训）：**错误态**（"加载失败" + "重试"
 *  两个控件）和 **Ready 态**（15 行列表）在**整窗**口径下都是 ~8.6% —— 顶部导航、
 *  工具条、卡片边框占了大头，列表那点文字淹在里面。整窗阈值 8% 于是对"取数失败"
 *  也成立，判据形同虚设。只量列表区才分得开（错误态 ≈0%，Ready 9.3%）。
 */
double inkPct(const Shot& shot, int y0, int y1)
{
    const int top = y0 < 0 ? 0 : y0;
    const int bottom = (y1 <= 0 || y1 > shot.height) ? shot.height : y1;

    long long total = 0;
    long long ink = 0;
    for (int y = top; y < bottom; ++y) {
        const unsigned char* row = shot.pixels.data()
            + static_cast<size_t>(y) * static_cast<size_t>(shot.width) * 4;
        for (int x = 0; x < shot.width; ++x) {
            const unsigned char* pixel = row + static_cast<size_t>(x) * 4;
            ++total;
            if (pixel[0] < INK_LEVEL || pixel[1] < INK_LEVEL || pixel[2] < INK_LEVEL) {
                ++ink;
            }
        }
    }
    return total == 0 ? 0.0 : 100.0 * static_cast<double>(ink) / static_cast<double>(total);
}

// ───────────────────────────────────────────────────── 帧间变化量 ──

/** 两帧之间**有多少像素变了**（精确不等，不做阈值）。
 *
 *  ★ 为什么用"变了多少个"而不是"变了百分之几"：`Spinner` 只有 24×24，占整窗
 *  0.1% 不到 —— 百分比会被四舍五入成 0.0，"在转"和"不转"看起来一样（假绿）。
 *  绝对计数是二值信号：转 ⇒ 几十到几百；不转 ⇒ **精确 0**（BitBlt 同像素同值）。
 *  尺寸变了（窗口被改大小）返回 -1，不硬比。
 */
long long changedPixels(const Shot& before, const Shot& after)
{
    if (before.width != after.width || before.height != after.height) {
        return -1;
    }

    const size_t count = before.pixels.size() / 4;
    const unsigned char* a = before.pixels.data();
    const unsigned char* b = after.pixels.data();

    long long changed = 0;
    for (size_t i = 0; i < count; ++i) {
        const size_t offset = i * 4;
        if (a[offset] != b[offset] || a[offset + 1] != b[offset + 1] || a[offset + 2] != b[offset + 2]) {
            ++changed;
        }
    }
    return changed;
}

// ───────────────────────────────────────────────────────── 数进程 ──

/**
 * 诊断用：打印"此刻谁是前台窗口"与目标窗口的几何。
 *
 * ★ 为什么需要：`--keys` 走**真实输入**（`SendInput`），而键盘事件只进
 *   **前台窗口**的消息队列。探针从控制台里跑，前台默认是控制台 —— 若点击
 *   没能把目标窗提前台（被别的窗挡住 / 激活被系统合并），敲的字符就全进了
 *   控制台，画面一丝不变（2026-10-23 实证：S5 判据四趟全平，一度被读成
 *   "过滤坏了"，其实是键盘送错了窗口）。
 */
void printForegroundInfo(const char* tag, HWND target)
{
    const HWND fg = ::GetForegroundWindow();
    wchar_t title[256] = {};
    if (fg != nullptr) {
        ::GetWindowTextW(fg, title, 255);
    }
    DWORD fgPid = 0;
    if (fg != nullptr) {
        ::GetWindowThreadProcessId(fg, &fgPid);
    }
    RECT fgRect{};
    if (fg != nullptr) {
        ::GetWindowRect(fg, &fgRect);
    }
    RECT targetRect{};
    if (target != nullptr) {
        ::GetWindowRect(target, &targetRect);
    }
    std::printf(
        "%s fg_hwnd=0x%llx fg_pid=%lu fg_rect=%ld,%ld,%ld,%ld fg_title=%ls "
        "target_hwnd=0x%llx target_rect=%ld,%ld,%ld,%ld is_fg=%d\n",
        tag,
        static_cast<unsigned long long>(reinterpret_cast<uintptr_t>(fg)),
        static_cast<unsigned long>(fgPid),
        fgRect.left, fgRect.top, fgRect.right, fgRect.bottom,
        title,
        static_cast<unsigned long long>(reinterpret_cast<uintptr_t>(target)),
        targetRect.left, targetRect.top, targetRect.right, targetRect.bottom,
        fg == target ? 1 : 0);
    std::fflush(stdout);
}

/**
 * 把目标窗**提前台**（真实输入的前置条件）。
 *
 * ★★ 为什么必须有：`--keys` 走真实输入 —— `SendInput` 的键盘事件只进
 *   **前台窗口**的消息队列，物理点击也只落在"最上面那扇窗"上。探针是从
 *   IDE/控制台里起的，被测窗口天生在后台（2026-10-23 实证：`fg0` 是全屏
 *   IDE、点完 `fg1` 干脆是 0x0）⇒ 点击与字符全送进 IDE，画面一丝不变，
 *   S5 判据四趟全平，一度被读成"过滤坏了"。**不激活 = 输入没送到**。
 * ★ `AttachThreadInput` 是标准解法：后台进程直接 `SetForegroundWindow`
 *   会被系统拒绝，先挂到前台线程的输入队列再设就能成。
 */
void activateWindow(HWND hwnd)
{
    if (hwnd == nullptr) {
        return;
    }
    ::ShowWindow(hwnd, SW_RESTORE);

    DWORD foregroundThread = 0;
    const HWND fg = ::GetForegroundWindow();
    if (fg != nullptr) {
        foregroundThread = ::GetWindowThreadProcessId(fg, nullptr);
    }
    const DWORD thisThread = ::GetCurrentThreadId();

    bool attached = false;
    if (foregroundThread != 0 && foregroundThread != thisThread) {
        attached = ::AttachThreadInput(foregroundThread, thisThread, TRUE) != FALSE;
    }
    ::SetForegroundWindow(hwnd);
    ::BringWindowToTop(hwnd);
    if (attached) {
        ::AttachThreadInput(foregroundThread, thisThread, FALSE);
    }
    ::Sleep(150);   // 前台切换是异步的，等它落地
}

/** 同名进程数（含主进程与 `--drift-worker` 子进程）。失败返回 -1。 */
int countImage(const std::wstring& image)
{
    HANDLE snapshot = ::CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);
    if (snapshot == INVALID_HANDLE_VALUE) {
        return -1;
    }

    PROCESSENTRY32W entry{};
    entry.dwSize = sizeof(entry);

    int count = 0;
    if (::Process32FirstW(snapshot, &entry) != FALSE) {
        do {
            if (_wcsicmp(entry.szExeFile, image.c_str()) == 0) {
                ++count;
            }
        } while (::Process32NextW(snapshot, &entry) != FALSE);
    }
    ::CloseHandle(snapshot);
    return count;
}

/** 按镜像名列出**所有**进程 pid（升序）—— 主进程 + worker 同名，不能只取一个。 */
std::vector<DWORD> resolveAllImages(const std::wstring& image)
{
    HANDLE snapshot = ::CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);
    std::vector<DWORD> pids;
    if (snapshot == INVALID_HANDLE_VALUE) {
        return pids;
    }
    PROCESSENTRY32W entry{};
    entry.dwSize = sizeof(entry);

    if (::Process32FirstW(snapshot, &entry) != FALSE) {
        do {
            if (_wcsicmp(entry.szExeFile, image.c_str()) == 0) {
                pids.push_back(entry.th32ProcessID);
            }
        } while (::Process32NextW(snapshot, &entry) != FALSE);
    }
    ::CloseHandle(snapshot);

    std::sort(pids.begin(), pids.end());
    return pids;
}

/** 把 Shot（BGRA 自顶向下）编码成 PNG（WIC）。失败返回 false。 */
bool savePng(const Shot& shot, const std::wstring& path)
{
    // STA 初始化失败（RPC_E_CHANGED_MODE）也继续：WIC 工厂两种模式都能建。
    CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED);

    IWICImagingFactory* factory = nullptr;
    HRESULT hr = CoCreateInstance(CLSID_WICImagingFactory, nullptr, CLSCTX_INPROC_SERVER,
                                  IID_PPV_ARGS(&factory));
    if (FAILED(hr) || factory == nullptr) {
        return false;
    }

    IWICStream* stream = nullptr;
    IWICBitmapEncoder* encoder = nullptr;
    IWICBitmapFrameEncode* frame = nullptr;
    IPropertyBag2* props = nullptr;
    bool ok = false;

    do {
        if (FAILED(factory->CreateStream(&stream))) break;
        hr = stream->InitializeFromFilename(path.c_str(), GENERIC_WRITE);
        if (FAILED(hr)) break;
        if (FAILED(factory->CreateEncoder(GUID_ContainerFormatPng, nullptr, &encoder))) break;
        if (FAILED(encoder->Initialize(stream, WICBitmapEncoderNoCache))) break;
        if (FAILED(encoder->CreateNewFrame(&frame, &props))) break;
        if (FAILED(frame->Initialize(props))) break;
        if (FAILED(frame->SetSize(static_cast<UINT>(shot.width), static_cast<UINT>(shot.height)))) break;
        // BGRA（内存布局）⇒ GUID_WICPixelFormat32bppBGRA，与像素序一致，无需转换
        WICPixelFormatGUID srcFmt = GUID_WICPixelFormat32bppBGRA;
        hr = frame->SetPixelFormat(&srcFmt);
        if (FAILED(hr)) break;
        if (FAILED(frame->WritePixels(static_cast<UINT>(shot.height),
                                      static_cast<UINT>(shot.width) * 4,
                                      static_cast<UINT>(shot.pixels.size()),
                                      const_cast<BYTE*>(shot.pixels.data())))) break;
        if (FAILED(frame->Commit())) break;
        if (FAILED(encoder->Commit())) break;
        ok = true;
    } while (false);

    if (props != nullptr) props->Release();
    if (frame != nullptr) frame->Release();
    if (encoder != nullptr) encoder->Release();
    if (stream != nullptr) stream->Release();
    factory->Release();
    return ok;
}

}   // namespace

int wmain(int argc, wchar_t** argv)
{
    Options options;

    for (int i = 1; i < argc; ++i) {
        std::wstring argument = argv[i];
        if (argument.rfind(L"--", 0) != 0) {
            continue;
        }
        const size_t equals = argument.find(L'=');
        const std::wstring key = argument.substr(
            2, equals == std::wstring::npos ? std::wstring::npos : equals - 2);
        const std::wstring value = equals == std::wstring::npos ? L"1" : argument.substr(equals + 1);

        long long number = 0;
        if (key == L"pid" && parseInt(value, number)) {
            options.pid = static_cast<DWORD>(number);
        } else if (key == L"image") {
            options.image = value;
        } else if (key == L"wait" && parseInt(value, number)) {
            options.waitMs = static_cast<int>(number);
        } else if (key == L"settle" && parseInt(value, number)) {
            options.settleMs = static_cast<int>(number);
        } else if (key == L"samples" && parseInt(value, number)) {
            options.samples = static_cast<int>(number);
        } else if (key == L"interval" && parseInt(value, number)) {
            options.intervalMs = static_cast<int>(number);
        } else if (key == L"y0" && parseInt(value, number)) {
            options.y0 = static_cast<int>(number);
        } else if (key == L"y1" && parseInt(value, number)) {
            options.y1 = static_cast<int>(number);
        } else if (key == L"close") {
            options.close = value != L"0";
        } else if (key == L"count-only") {
            options.countOnly = value != L"0";
        } else if (key == L"motion") {
            options.motion = value != L"0";
        } else if (key == L"move") {
            // x,y,w,h —— 外框、物理像素（进程是 DPI aware 的）
            int parts[4] = {0, 0, 0, 0};
            int index = 0;
            size_t start = 0;
            for (size_t i = 0; i <= value.size(); ++i) {
                if (i == value.size() || value[i] == L',') {
                    long long parsed = 0;
                    if (!parseInt(value.substr(start, i - start), parsed)) {
                        std::fprintf(stderr, "err=--move wants x,y,w,h\n");
                        return 2;
                    }
                    if (index >= 4) {
                        std::fprintf(stderr, "err=--move wants exactly 4 numbers\n");
                        return 2;
                    }
                    parts[index++] = static_cast<int>(parsed);
                    start = i + 1;
                }
            }
            if (index != 4) {
                std::fprintf(stderr, "err=--move wants exactly 4 numbers\n");
                return 2;
            }
            options.move = true;
            options.moveX = parts[0];
            options.moveY = parts[1];
            options.moveW = parts[2];
            options.moveH = parts[3];
        } else if (key == L"zoom" && parseInt(value, number)) {
            options.zoom = value == L"0" ? 2 : static_cast<int>(number);
        } else if (key == L"rect") {
            options.printRect = value != L"0";
        } else if (key == L"no-grab") {
            options.noGrab = value != L"0";
        } else if (key == L"auto") {
            options.autoResolve = value != L"0";
        } else if (key == L"wheel") {
            int parts[3] = {0, 0, 0};
            int index = 0;
            size_t start = 0;
            for (size_t i = 0; i <= value.size(); ++i) {
                if (i == value.size() || value[i] == L',') {
                    long long parsed = 0;
                    if (!parseInt(value.substr(start, i - start), parsed)) {
                        std::fprintf(stderr, "err=--wheel wants x,y,delta\n");
                        return 2;
                    }
                    if (index >= 3) {
                        std::fprintf(stderr, "err=--wheel wants exactly 3 numbers\n");
                        return 2;
                    }
                    parts[index++] = static_cast<int>(parsed);
                    start = i + 1;
                }
            }
            if (index != 3) {
                std::fprintf(stderr, "err=--wheel wants exactly 3 numbers\n");
                return 2;
            }
            options.wheel = true;
            options.wheelX = parts[0];
            options.wheelY = parts[1];
            options.wheelDelta = parts[2];
        } else if (key == L"wheel-wait" && parseInt(value, number)) {
            options.wheelWaitMs = static_cast<int>(number);
        } else if (key == L"click") {
            // x,y —— 客户区坐标；down+up 一次完整点击（导航切换用）
            int parts[2] = {0, 0};
            int index = 0;
            size_t start = 0;
            for (size_t i = 0; i <= value.size(); ++i) {
                if (i == value.size() || value[i] == L',') {
                    long long parsed = 0;
                    if (!parseInt(value.substr(start, i - start), parsed)) {
                        std::fprintf(stderr, "err=--click wants x,y\n");
                        return 2;
                    }
                    if (index >= 2) {
                        std::fprintf(stderr, "err=--click wants exactly 2 numbers\n");
                        return 2;
                    }
                    parts[index++] = static_cast<int>(parsed);
                    start = i + 1;
                }
            }
            if (index != 2) {
                std::fprintf(stderr, "err=--click wants exactly 2 numbers\n");
                return 2;
            }
            options.click = true;
            options.clickX = parts[0];
            options.clickY = parts[1];
        } else if (key == L"click-wait" && parseInt(value, number)) {
            options.clickWaitMs = static_cast<int>(number);
        } else if (key == L"cursor") {
            // x,y —— 客户区坐标。**必须用 SetCursorPos 真挪物理光标**：合成
            // WM_MOUSEMOVE 不会让系统认为指针在窗口里，`TrackMouseEvent(TME_LEAVE)`
            // 会立刻回一条 WM_MOUSELEAVE ⇒ 应用侧"指针出窗"收口，框选/拖动半途
            // 被打断（2026-10-21 实证：拖到第 2 步 textSelect 就被清空，间歇复现）。
            int parts[2] = {0, 0};
            int index = 0;
            size_t start = 0;
            for (size_t i = 0; i <= value.size(); ++i) {
                if (i == value.size() || value[i] == L',') {
                    long long parsed = 0;
                    if (!parseInt(value.substr(start, i - start), parsed)) {
                        std::fprintf(stderr, "err=--cursor wants x,y\n");
                        return 2;
                    }
                    if (index >= 2) {
                        std::fprintf(stderr, "err=--cursor wants exactly 2 numbers\n");
                        return 2;
                    }
                    parts[index++] = static_cast<int>(parsed);
                    start = i + 1;
                }
            }
            if (index != 2) {
                std::fprintf(stderr, "err=--cursor wants exactly 2 numbers\n");
                return 2;
            }
            options.setCursor = true;
            options.cursorX = parts[0];
            options.cursorY = parts[1];
        } else if (key == L"mdrag") {
            // x1,y1,x2,y2 —— **客户区**坐标（滚动条拖动等客户区内拖拽用）
            int parts[4] = {0, 0, 0, 0};
            int index = 0;
            size_t start = 0;
            for (size_t i = 0; i <= value.size(); ++i) {
                if (i == value.size() || value[i] == L',') {
                    long long parsed = 0;
                    if (!parseInt(value.substr(start, i - start), parsed)) {
                        std::fprintf(stderr, "err=--mdrag wants x1,y1,x2,y2\n");
                        return 2;
                    }
                    if (index >= 4) {
                        std::fprintf(stderr, "err=--mdrag wants exactly 4 numbers\n");
                        return 2;
                    }
                    parts[index++] = static_cast<int>(parsed);
                    start = i + 1;
                }
            }
            if (index != 4) {
                std::fprintf(stderr, "err=--mdrag wants exactly 4 numbers\n");
                return 2;
            }
            options.mouseDrag = true;
            options.mdragX1 = parts[0];
            options.mdragY1 = parts[1];
            options.mdragX2 = parts[2];
            options.mdragY2 = parts[3];
        } else if (key == L"drag") {
            // dx,dy —— 从窗口右下角起算的**放大量**（正=拖大、负=拖小）。
            // 走 WM_NCLBUTTONDOWN(HTBOTTOMRIGHT) + 若干 WM_MOUSEMOVE + WM_LBUTTONUP，
            // 与真实拖动同路径（系统 modal resize loop），区别于 --move 的单次 SetWindowPos。
            int parts[2] = {0, 0};
            int index = 0;
            size_t start = 0;
            for (size_t i = 0; i <= value.size(); ++i) {
                if (i == value.size() || value[i] == L',') {
                    long long parsed = 0;
                    if (!parseInt(value.substr(start, i - start), parsed)) {
                        std::fprintf(stderr, "err=--drag wants dx,dy\n");
                        return 2;
                    }
                    if (index >= 2) {
                        std::fprintf(stderr, "err=--drag wants exactly 2 numbers\n");
                        return 2;
                    }
                    parts[index++] = static_cast<int>(parsed);
                    start = i + 1;
                }
            }
            if (index != 2) {
                std::fprintf(stderr, "err=--drag wants exactly 2 numbers\n");
                return 2;
            }
            options.dragResize = true;
            options.dragX2 = parts[0];
            options.dragY2 = parts[1];
        } else if (key == L"drag-steps" && parseInt(value, number)) {
            options.dragSteps = static_cast<int>(number);
        } else if (key == L"grab-step" && parseInt(value, number)) {
            options.grabStep = static_cast<int>(number);
        } else if (key == L"mid-png") {
            options.midPngPath = value;
        } else if (key == L"deactivate") {
            options.deactivate = true;
        } else if (key == L"keys") {
            // x,y,<hex UTF-16> —— 三段。hex 而不是原文：bash 传的非 ASCII 参数经
            // `CommandLineToArgvW` 按 ANSI 码页转换会丢字 ⇒ 一律 hex 才可复现。
            std::vector<std::wstring> parts;
            size_t start = 0;
            while (true) {
                const size_t comma = value.find(L',', start);
                if (comma == std::wstring::npos) {
                    parts.push_back(value.substr(start));
                    break;
                }
                parts.push_back(value.substr(start, comma - start));
                start = comma + 1;
            }
            // ★★ x/y 必须**各自解析进自己的变量**：旧写法把 `parseInt` 写在
            //   `if` 条件里，第二次调用把 `number` 覆盖成 y 值，紧接着
            //   `keysX = number` 拿到的是 **y**（2026-10-23 实证：传 64,128
            //   实际点 128,128 —— 落在"刷新"按钮上，整轮 S5 判据全假绿）。
            long long keysX = 0;
            long long keysY = 0;
            if (parts.size() >= 3 && parseInt(parts[0], keysX) && parseInt(parts[1], keysY)) {
                options.typeKeys = true;
                options.keysX = static_cast<int>(keysX);
                options.keysY = static_cast<int>(keysY);
                options.keysHex = parts[2];
            } else {
                std::fprintf(stderr, "err=--keys wants x,y,<hex>\n");
                return 2;
            }
        } else if (key == L"keys-wait" && parseInt(value, number)) {
            options.keysWaitMs = static_cast<int>(number);
        } else if (key == L"fg") {
            options.printForeground = true;
        } else if (key == L"chars") {
            // <hex UTF-16> —— 逐码元 PostMessage(WM_CHAR)（确定性字符输入）。
            // ★ 与 `--keys` 的分工：`--keys` 走 `SendInput` 真实输入，**要求目标窗
            //   在前台**（键盘事件只进前台窗口的队列）；本机探针从 IDE 里跑，被测
            //   窗常在后台，真实输入会整批送进 IDE（2026-10-23 实证：S5 四趟全平）。
            //   `--chars` 直接发到目标窗的消息队列 —— 壳侧 `WM_CHAR` 处理与真实
            //   敲键**同一条路径**（都入 `kEvChar`，焦点路由在 PHP 侧），
            //   `SendInput(KEYEVENTF_UNICODE)` 产生的也正是 WM_CHAR。
            //   ⇒ 语义等价、且不依赖窗口层级。焦点仍由 `--click` 给。
            options.typeChars = true;
            options.charsHex = value;
        } else if (key == L"png") {
            options.savePng = true;
            options.pngPath = value;
        }
    }

    // 抓的是**物理**像素；不声明 DPI 感知的话 GetClientRect 会被虚拟化成逻辑像素。
    ::SetProcessDPIAware();

    if (options.countOnly) {
        if (options.image.empty()) {
            std::fprintf(stderr, "err=--count-only needs --image\n");
            return 2;
        }
        const int count = countImage(options.image);
        std::printf("procs=%d\n", count);
        return count < 0 ? 3 : 0;
    }

    HWND hwnd = nullptr;
    if (options.pid == 0) {
        if (options.autoResolve && !options.image.empty()) {
            // ★ **逐个试**：同名进程里 worker 也在（`--drift-worker` 分支不建窗口），
            //   而"哪个 pid 有窗口"不由 pid 大小决定（pid 会回收）⇒ 只能试。
            const std::vector<DWORD> pids = resolveAllImages(options.image);
            if (pids.empty()) {
                std::fprintf(stderr, "err=no process named %ls is running\n", options.image.c_str());
                return 2;
            }
            const int per = options.waitMs > 0 ? options.waitMs / static_cast<int>(pids.size()) : 400;
            for (const DWORD candidate : pids) {
                hwnd = waitWindow(candidate, per < 100 ? 100 : per);
                if (hwnd != nullptr) {
                    options.pid = candidate;
                    std::printf("resolved_pid=%lu\n", static_cast<unsigned long>(options.pid));
                    break;
                }
            }
        }
        if (hwnd == nullptr) {
            std::fprintf(stderr, "err=--pid is required (or --auto with --image)\n");
            return 2;
        }
    }

    if (hwnd == nullptr) {
        hwnd = waitWindow(options.pid, options.waitMs);
    }
    if (hwnd == nullptr) {
        // ★ 诊断：是"进程没有窗口"还是"窗口不满足判据"—— 两种原因的修法完全不同。
        const int owned = countOwnerWindows(options.pid);
        std::fprintf(stderr,
                     "err=no usable window for pid %lu within %dms (must be visible with a non-empty "
                     "client area); the process owns %d top-level window(s) in total\n",
                     static_cast<unsigned long>(options.pid), options.waitMs, owned);
        return 2;
    }
    std::printf("hwnd=0x%llx\n", static_cast<unsigned long long>(reinterpret_cast<uintptr_t>(hwnd)));
    if (options.printForeground) {
        printForegroundInfo("fg0", hwnd);
    }

    // ★ bug ②：窗口在 ≠ 已画过第一帧。首帧落地前抓到的是屏幕残留。
    if (options.settleMs > 0) {
        ::Sleep(static_cast<DWORD>(options.settleMs));
    }

    // ── 输入注入（拖动 resize / 点击 / 滚轮）────────────────────
    if (options.dragResize) {
        // ★ PostMessage（不是 SendMessage）：WM_NCLBUTTONDOWN 交 DefWindowProc 后会
        //   进系统 modal resize loop 并**阻塞发送线程**，SendMessage 会把探针卡死。
        //   PostMessage + 后续 MOVE/UP 都由 modal loop 在同一线程消费。
        // 起点 (0,0) = 自动取窗口右下角（免去先量几何再算）
        RECT frame{};
        ::GetWindowRect(hwnd, &frame);
        const int sx = frame.right - 2;   // 起点永远是当前右下角（dx/dy 模式）
        const int sy = frame.bottom - 2;
        ::PostMessageW(hwnd, WM_NCLBUTTONDOWN, HTBOTTOMRIGHT,
                       MAKELPARAM(static_cast<WORD>(sx), static_cast<WORD>(sy)));
        ::Sleep(60);
        for (int s = 1; s <= options.dragSteps; ++s) {
            const int mx = sx + options.dragX2 * s / options.dragSteps;
            const int my = sy + options.dragY2 * s / options.dragSteps;
            ::PostMessageW(hwnd, WM_MOUSEMOVE, 0,
                           MAKELPARAM(static_cast<WORD>(mx), static_cast<WORD>(my)));
            ::Sleep(30);
            if (options.grabStep > 0 && s == options.grabStep) {
                // 拖动中抓一张：验证"拖动期间"是实时新帧还是旧帧拉伸
                Shot mid{};
                std::string midError;
                if (grab(hwnd, mid, midError)) {
                    std::printf("mid_client=%dx%d mid_ink=%.1f\n", mid.width, mid.height, inkPct(mid, 0, 0));
                    if (!options.midPngPath.empty() && savePng(mid, options.midPngPath)) {
                        std::printf("mid_png=%ls\n", options.midPngPath.c_str());
                    }
                }
            }
        }
        ::PostMessageW(hwnd, WM_LBUTTONUP, 0,
                       MAKELPARAM(static_cast<WORD>(sx + options.dragX2), static_cast<WORD>(sy + options.dragY2)));
        std::printf("drag=br(%d,%d)+(%d,%d)\n", sx, sy, options.dragX2, options.dragY2);
        ::Sleep(static_cast<DWORD>(options.wheelWaitMs));
    }
    if (options.setCursor) {
        POINT target{options.cursorX, options.cursorY};
        ::ClientToScreen(hwnd, &target);
        ::SetCursorPos(target.x, target.y);
        std::printf("cursor=%d,%d\n", options.cursorX, options.cursorY);
        ::Sleep(80);   // 让 TrackMouseEvent 先看到"指针在窗内"
    }
    if (options.click) {
        // ★★ WM_LBUTTONDOWN 的 lParam 是**客户区**坐标，**不能** ClientToScreen
        //   （那是 WM_MOUSEWHEEL 才需要的换算，见下）。旧实现从 wheel 分支抄了
        //   一行 ClientToScreen ⇒ 点击整体偏移一个窗口原点：客户区 (100,318) 实际
        //   落在 (100, 318+窗体 top) —— 点导航项永远点不中想要的（2026-10-20 实证）。
        //   与 mdrag 分支同口径：直接发客户区坐标。
        LPARAM lpPt = MAKELPARAM(static_cast<WORD>(options.clickX), static_cast<WORD>(options.clickY));
        ::PostMessageW(hwnd, WM_LBUTTONDOWN, MK_LBUTTON, lpPt);
        ::PostMessageW(hwnd, WM_LBUTTONUP, 0, lpPt);
        std::printf("click=%d,%d\n", options.clickX, options.clickY);
        ::Sleep(static_cast<DWORD>(options.clickWaitMs));
    }
    if (options.mouseDrag) {
        // 客户区按下 → 分步移动 → 抬起（WM_* 的 lParam 是**客户区**坐标，无需换算）
        // ★★ 每一步都**真挪物理光标**（SetCursorPos），不能只 PostMessage：
        //   系统认为指针不在窗内 ⇒ `TrackMouseEvent(TME_LEAVE)` 立刻回
        //   WM_MOUSELEAVE ⇒ 应用侧"指针出窗"把拖动/框选收口，现象是"拖到第 2
        //   步就断"，且**间歇复现**（取决于窗口是否真的在光标下、有没有被遮）。
        //   合成消息保留：它保证即使 SetCursorPos 被系统合并，应用也收到每一步。
        auto cursorTo = [hwnd](int cx, int cy) {
            POINT p{cx, cy};
            ::ClientToScreen(hwnd, &p);
            ::SetCursorPos(p.x, p.y);
        };
        cursorTo(options.mdragX1, options.mdragY1);
        ::PostMessageW(hwnd, WM_LBUTTONDOWN, MK_LBUTTON,
                       MAKELPARAM(static_cast<WORD>(options.mdragX1), static_cast<WORD>(options.mdragY1)));
        ::Sleep(60);
        for (int s = 1; s <= 12; ++s) {
            const int mx = options.mdragX1 + (options.mdragX2 - options.mdragX1) * s / 12;
            const int my = options.mdragY1 + (options.mdragY2 - options.mdragY1) * s / 12;
            cursorTo(mx, my);
            ::PostMessageW(hwnd, WM_MOUSEMOVE, MK_LBUTTON,
                           MAKELPARAM(static_cast<WORD>(mx), static_cast<WORD>(my)));
            ::Sleep(30);
        }
        ::PostMessageW(hwnd, WM_LBUTTONUP, 0,
                       MAKELPARAM(static_cast<WORD>(options.mdragX2), static_cast<WORD>(options.mdragY2)));
        std::printf("mdrag=%d,%d,%d,%d\n", options.mdragX1, options.mdragY1, options.mdragX2, options.mdragY2);
        ::Sleep(static_cast<DWORD>(options.wheelWaitMs));
    }
    if (options.wheel) {
        POINT pt{options.wheelX, options.wheelY};
        ::ClientToScreen(hwnd, &pt);
        WPARAM wheelWp = MAKEWPARAM(0, static_cast<WORD>(options.wheelDelta));
        LPARAM wheelLp = MAKELPARAM(static_cast<WORD>(pt.x), static_cast<WORD>(pt.y));
        ::PostMessageW(hwnd, WM_MOUSEWHEEL, wheelWp, wheelLp);
        std::printf("wheel=%d,%d,%d\n", options.wheelX, options.wheelY, options.wheelDelta);
        ::Sleep(static_cast<DWORD>(options.wheelWaitMs));
    }

    // ── 窗口失活（`WM_ACTIVATE` + `WA_INACTIVE`，模拟"用户切走"）──────
    // ★ 这条**可以**用 PostMessage：`WM_ACTIVATE` 不依赖"指针在哪"（与
    //   `WM_MOUSEMOVE` 不同，见文件头的 ★★），壳侧只看 `LOWORD(wParam)`。
    if (options.deactivate) {
        ::PostMessageW(hwnd, WM_ACTIVATE, MAKEWPARAM(WA_INACTIVE, 0), 0);
        std::printf("deactivated=1\n");
        ::Sleep(static_cast<DWORD>(options.wheelWaitMs));
    }

    // ── 键盘输入（S5 搜索框真机验证用）────────────────────────────
    // ★ 走**真实输入**（`SetCursorPos` + `mouse_event` + `SendInput`）：最贴近
    //   真实用户（含"系统路由到焦点控件"这一跳）。★ 代价：要求目标窗在**前台**
    //   （键盘事件只进前台窗口的队列）—— 见 `activateWindow`；本机探针从 IDE
    //   里跑、被测窗常在后台时改用 `--chars`（语义等价、不依赖窗口层级）。
    // ★ `KEYEVENTF_UNICODE` 直接送码元 ⇒ **不过输入法**，可复现（中文也能敲）。
    if (options.typeKeys) {
        // ★★ 先把目标窗提前台：否则物理点击落在压着它的窗（IDE）上、
        //   字符也进 IDE 的消息队列 —— 输入根本没送到（见 `activateWindow`）。
        activateWindow(hwnd);

        // hex → UTF-16 码元：4 个 hex = 1 个码元（"张伟" = 5F204F1F）
        std::wstring text;
        for (size_t i = 0; i + 4 <= options.keysHex.size(); i += 4) {
            const std::wstring unit = options.keysHex.substr(i, 4);
            text.push_back(static_cast<wchar_t>(wcstol(unit.c_str(), nullptr, 16)));
        }

        POINT at{options.keysX, options.keysY};
        ::ClientToScreen(hwnd, &at);
        ::SetCursorPos(at.x, at.y);
        ::Sleep(80);    // 让 TrackMouseEvent 先看到"指针在窗内"
        ::mouse_event(MOUSEEVENTF_LEFTDOWN, 0, 0, 0, 0);
        ::Sleep(30);
        ::mouse_event(MOUSEEVENTF_LEFTUP, 0, 0, 0, 0);
        ::Sleep(150);   // 等控件拿到焦点
        if (options.printForeground) {
            printForegroundInfo("fg1", hwnd);
        }

        for (const wchar_t ch : text) {
            INPUT in[2] = {};
            in[0].type = INPUT_KEYBOARD;
            in[0].ki.wScan = ch;
            in[0].ki.dwFlags = KEYEVENTF_UNICODE;
            in[1] = in[0];
            in[1].ki.dwFlags = KEYEVENTF_UNICODE | KEYEVENTF_KEYUP;
            ::SendInput(2, in, sizeof(INPUT));
            ::Sleep(40);
        }

        std::printf("keys=%d,%d,units=%zu\n", options.keysX, options.keysY, text.size());
        ::Sleep(static_cast<DWORD>(options.keysWaitMs));
    }

    // ── 字符输入（PostMessage(WM_CHAR)，见参数解析处的分工说明）─────
    if (options.typeChars) {
        std::wstring text;
        for (size_t i = 0; i + 4 <= options.charsHex.size(); i += 4) {
            const std::wstring unit = options.charsHex.substr(i, 4);
            text.push_back(static_cast<wchar_t>(wcstol(unit.c_str(), nullptr, 16)));
        }
        for (const wchar_t ch : text) {
            ::PostMessageW(hwnd, WM_CHAR, static_cast<WPARAM>(ch), 0);
            ::Sleep(40);
        }
        std::printf("chars=units=%zu\n", text.size());
        ::Sleep(static_cast<DWORD>(options.keysWaitMs));
    }

    // ── 窗口控制（M5，§5.4 验证用）────────────────────────────
    // 顺序固定：最大化先、搬位置后 —— 反过来"先把最大化窗口搬走"会被 Windows 忽略。
    if (options.zoom == 1) {
        ::ShowWindow(hwnd, SW_MAXIMIZE);
        std::printf("zoomed=1\n");
    } else if (options.zoom == 2) {
        ::ShowWindow(hwnd, SW_RESTORE);
        std::printf("unzoomed=1\n");
    }
    if (options.move) {
        // SWP_NOZORDER(4)：只搬尺寸，不换 Z 序（换 Z 序会让窗口弹到最前，干扰观测）
        ::SetWindowPos(hwnd, nullptr, options.moveX, options.moveY, options.moveW, options.moveH,
                       SWP_NOZORDER);
        std::printf("moved=1\n");
        // 搬完立刻量外框：SetWindowPos 是同步的，此刻 GetWindowRect 就是新值
    }
    if (options.printRect) {
        RECT frame{};
        ::GetWindowRect(hwnd, &frame);
        std::printf("rect=%ld,%ld,%ld,%ld\n", frame.left, frame.top,
                    frame.right - frame.left, frame.bottom - frame.top);
        std::fflush(stdout);
    }
    if (options.noGrab) {
        if (!options.image.empty()) {
            std::printf("procs=%d\n", countImage(options.image));
        }
        if (options.close) {
            ::PostMessageW(hwnd, WM_CLOSE, 0, 0);
            std::printf("closed=1\n");
        }
        std::fflush(stdout);
        return 0;
    }

    Shot shot{};
    std::string error;
    if (!grab(hwnd, shot, error)) {
        std::fprintf(stderr, "err=%s\n", error.c_str());
        return 3;
    }

    std::printf("client=%dx%d\n", shot.width, shot.height);
    std::printf("ink_all_pct=%.1f\n", inkPct(shot, 0, 0));

    if (options.savePng) {
        if (savePng(shot, options.pngPath)) {
            std::printf("png=%ls\n", options.pngPath.c_str());
        } else {
            std::fprintf(stderr, "err=png encode failed\n");
        }
    }

    if (options.samples <= 0) {
        std::printf("s0=%.1f\n", inkPct(shot, options.y0, options.y1));
        std::fflush(stdout);
    } else {
        // ★ bug ③：采样循环在本进程内，别在外面 spawn 30 次（见文件头）。
        Shot previous;
        if (options.motion) {
            previous = shot;
        }

        for (int i = 0; i < options.samples; ++i) {
            long long changed = -1;
            if (i > 0) {
                ::Sleep(static_cast<DWORD>(options.intervalMs));
                if (!grab(hwnd, shot, error)) {
                    std::fprintf(stderr, "err=%s\n", error.c_str());
                    return 3;
                }
                if (options.motion) {
                    changed = changedPixels(previous, shot);
                }
            }
            std::printf("s%d=%.1f\n", i, inkPct(shot, options.y0, options.y1));
            if (options.motion && i > 0) {
                std::printf("c%d=%lld\n", i, changed);
            }
            std::fflush(stdout);
            if (options.motion) {
                previous = shot;
            }
        }
    }

    if (!options.image.empty()) {
        std::printf("procs=%d\n", countImage(options.image));
    }

    if (options.close) {
        // ★ 必须走 WM_CLOSE（优雅关窗），不能 taskkill —— 后者会把 worker 一起打死，
        //   "关窗后 worker 自己收工"这条判据就成了假绿。
        ::PostMessageW(hwnd, WM_CLOSE, 0, 0);
        std::printf("closed=1\n");
    }

    std::fflush(stdout);
    return 0;
}
