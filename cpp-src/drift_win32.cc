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

#include <cstring>   // memcpy —— Skia 直贴路径按行拷（§7.3 提速，见文件尾）
#include <phpx.h>
#include <windows.h>
#include <imm.h>
#include <windowsx.h>   // GET_X_LPARAM / GET_Y_LPARAM（在 windowsx.h，不在 windows.h）
#include <commdlg.h>    // OPENFILENAMEW / GetOpenFileNameW（A5-① 文件对话框）
#include <shellapi.h>   // DragAcceptFiles / DragQueryFileW / DragFinish（A5-② 拖文件进窗）
#include <vector>       // std::vector —— WM_DROPFILES 逐个路径的临时缓冲
#include <map>          // std::map —— 窗口身份表（HWND → WindowSlot，M1-a）
#include <set>          // std::set —— 活窗口表 / 关闭守卫表（多窗口的最小形态）
#include <ShlObj.h>     // SHGetKnownFolderPath —— 用户配置目录（§5.4）
#include <KnownFolders.h>
#include <objbase.h>             // IUnknown —— UIAutomationCore.h 接口的基类
#include <UIAutomationCore.h>    // IRawElementProviderSimple / ProviderOptions
#include <UIAutomationClient.h>  // UIA_*ControlTypeId / UIA_*PropertyId 常量
#include <uiautomationcoreapi.h> // UiaReturnRawElementProvider / UiaRootObjectId
#include <dwmapi.h>              // DwmSetWindowAttribute —— 窗口圆角/背景（P1 质感）
#pragma comment(lib, "UIAutomationCore.lib")  // UiaReturnRawElementProvider
#pragma comment(lib, "oleaut32.lib")          // SysAllocString / VariantInit / BSTR
#pragma comment(lib, "ole32.lib")             // COM（IUnknown 基类）
#pragma comment(lib, "uuid.lib")              // __uuidof(IRawElementProviderSimple / IUnknown)
#pragma comment(lib, "dwmapi.lib")             // DwmSetWindowAttribute（P1 圆角/背景）
#pragma comment(lib, "comdlg32.lib")           // GetOpenFileNameW / GetSaveFileNameW（A5-①）
#pragma comment(lib, "shell32.lib")            // DragQueryFileW / DragFinish（A5-②）

// ★ Win11 DWM 常量兜底：旧 SDK 的 `dwmapi.h` 没有这两个成员。值取自
//   `DWMWINDOWATTRIBUTE` / `DWM_WINDOW_CORNER_PREFERENCE` 的真实枚举值 ——
//   与系统 ABI 绑定，**不要**改成"看起来顺眼"的数。
#ifndef DWMWA_WINDOW_CORNER_PREFERENCE
#define DWMWA_WINDOW_CORNER_PREFERENCE 33
#endif
#ifndef DWMWCP_ROUND
#define DWMWCP_ROUND 2
#endif

// ★ `WM_MOUSEHWHEEL` 在 `WINVER >= 0x0600` 才由 winuser.h 给出，而本工程不抬
//   `WINVER`（见 `enablePerMonitorDpi` 的注释）。值取自 winuser.h，与系统 ABI 绑定。
#ifndef WM_MOUSEHWHEEL
#define WM_MOUSEHWHEEL 0x020E
#endif

// ★ **Win10 未公开**的 `user32!SetWindowCompositionAttribute`（无头文件、无 import lib）。
//   结构体定义来自逆向社区、在 Win32 生态里被广泛使用，**字段顺序与系统 ABI 绑定** ——
//   不要重排、不要"改成看起来顺眼的类型"。Win11 起由 `DWMWA_SYSTEMBACKDROP_TYPE`
//   取代，但本机是 Win10 19045，只剩这一条路。详见 `php_drift_win_apply_material()`。
enum DriftAccentState {
    DRIFT_ACCENT_DISABLED = 0,
    DRIFT_ACCENT_ENABLE_GRADIENT = 1,
    DRIFT_ACCENT_ENABLE_TRANSPARENTGRADIENT = 2,
    DRIFT_ACCENT_ENABLE_BLURBEHIND = 3,
    DRIFT_ACCENT_ENABLE_ACRYLICBLURBEHIND = 4,
    DRIFT_ACCENT_ENABLE_HOSTBACKDROP = 5,
};

struct DriftAccentPolicy {
    int  accentState;     /**< `DRIFT_ACCENT_*` */
    int  accentFlags;     /**< 文档未定义；社区实测 2 = 四边都画 */
    int  gradientColor;   /**< ★ `0xAABBGGRR`（**BGRA**，与 `Color::toArgb()` 相反） */
    int  animationId;
};

struct DriftWindowCompositionAttribData {
    int     attrib;       /**< `WCA_ACCENT_POLICY` = 19 */
    void   *data;
    size_t  size;
};

typedef BOOL (WINAPI *DriftSetWindowCompositionAttribute)(HWND, DriftWindowCompositionAttribData *);

#include <string>
#include <vector>

using namespace php;

// ★ 拖动重排入口（§5.2）：PHP 侧具名全局函数（src/Platform/Win32/ResizeHook.php）。
//   **必须声明在全局作用域**——放进下面那个匿名 namespace 会被链接成
//   `anonymous namespace::php_drift_resize_hook_pump`，与 AOT 生成的符号对不上
//   （实测 LNK2019）。
//   ★ **带 hwnd**（2026-10-30，M1-b）：多窗口下"谁在拖"必须传给 PHP，
//     否则两个窗的拖动会去重排同一个表面（不报错、只画错窗）。
extern void php_drift_resize_hook_pump(Int hwnd);

// ★ **这里曾经还有一个 `php_drift_uia_invoke()`**（UIA `Invoke` 直接回调 PHP）。
//   2026-10-25 删掉：跨进程 COM 回调里重入 PHP 会让进程静默消失；改成"投消息 ⇒
//   入队 ⇒ 交一条 `SemanticsInvokeEvent`"之后，壳里**一次 PHP 都不进**，
//   顺带消掉"形参必须是 `php::Int`"与"增量构建不认新增 .php"这两个 AOT 坑。

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
/**
 * UIA `Invoke` 的**转投消息**（§9 / M5）。
 *
 * ★★ 为什么要转投：`Invoke()` 跑在 COM 的 RPC 线程上（跨进程编组），栈上压着
 *   UIA 的状态。在里面重入 PHP 实测会**让进程静默消失**（无异常、无日志，
 *   客户端只看到 `ElementNotAvailable`）。转投到窗口线程后 `Invoke()` 立刻返回。
 *
 * ★★ 转投到窗口线程之后**仍然不直接跑 PHP**，只把"第 idx 个节点被要求激活"
 *   入队（`kEvUiaInvoke`）。理由不是重入安全，而是**泵的契约**：`pump()` 一次
 *   只交一条事件，在派发里跑完 PHP 就没人保证下一帧会跑 —— 真机现象是
 *   "命令执行了、状态变了、画面一动不动"（要等下一条用户输入才刷出来）。
 *   入队则让组合根抽完事件立刻 `FrameClock::tick()`，同一轮循环就落盘。
 */
const UINT kMsgUiaInvoke = WM_APP + 2;

/**
 * UIA `SetValue` 的**转投消息**（§4.4 提交 2）。
 *
 * ★ 与 `kMsgUiaInvoke` 同形，唯一多出来的是**字符串载荷**：lParam 只能传整数，
 *   所以 `ValueAdapter`/`RangeAdapter` 把值 `new std::wstring(...)` 后传指针，
 *   WndProc 取出、转 UTF-8 入队、随即 `delete`（所有权一次性转移）。
 * ★ `Toggle()` **不走这条**：Checkbox 的 activate 就是"换值"，直接复用
 *   `kMsgUiaInvoke`（读屏 Toggle 与鼠标点击同一条路）。
 */
const UINT kMsgUiaSetValue = WM_APP + 3;

/** 队列元素种类（壳内部用；跨边界的名字在 `toArray()` 里给） */
enum EventKind {
    kEvResize = 1,
    kEvDpi,
    kEvTimer,
    kEvPointer,
    kEvKey,
    kEvChar,
    kEvTheme,
    kEvWheel,
    kEvDeactivate,
    kEvUiaInvoke,
    kEvUiaSetValue,
    kEvFilesDropped,
    kEvCloseRequested,
};

struct PendingEvent {
    // ★ 归属窗口（2026-10-30，M1-a）：队列是**线程级一份**（`GetMessageW` 取本线程
    //   任意窗口的消息），但每条事件的**来源**必须记下来 —— 否则两个窗口轮流投递
    //   时 PHP 侧无从判断"这条 resize 是哪个窗的"。`enqueue()` 强制填写。
    HWND hwnd = NULL;
    int kind;
    int a;   // resize: w ／ dpi: dpi ／ timer: id ／ pointer: x ／ wheel: x ／ key: — ／ char: —
    int b;   // resize: h ／ pointer: y ／ wheel: y ／ —
    int c;   // pointer: button（move 恒 0 —— 按住态由应用自己的拖动状态跟踪）／ wheel: delta（WHEEL_DELTA=120 为一格；纵向壳已取负 ⇒ 正=回拉=内容上移，见 WM_MOUSEWHEEL 注释；横向**不取负**，见 WM_MOUSEHWHEEL）／ key: modifier 位（1=ctrl 2=alt 4=shift）／ —
    int d;   // pointer: 相位（0=down 1=move 2=up 3=leave，与 Drift\Input\PointerPhase 同值）／ —
    // ★ pointer 的**修饰键位**（1=ctrl 2=alt 4=shift，与 `key` 同一套编码）。
    //   为什么要它：Ctrl/Shift+点击是表格多选、拖拽修饰（Ctrl 拖 = 复制）的唯一
    //   输入通道；Win32 的鼠标消息 wParam 里只有 MK_CONTROL/MK_SHIFT，Alt 不在其中
    //   ⇒ 三个都走 `GetKeyState`（同一处读、同一套编码，见 `modifierMask()`）。
    int e;   // pointer: modifier 位 ／ wheel: 轴（0=纵向 1=横向）／ —
    std::string text;   // key: 键名 ／ char: UTF-8 文本
};

// ─────────────────────────────────────────────────────────────── 状态 ──

// ──────────────── 窗口身份：一个窗口的全部壳侧状态（M1-a，2026-10-30）──
//
// ★★ 为什么要有这张表：改动之前壳里有 40 多个 per-window 全局（`win.frame` /
//   `win.dpi` / `win.uiaNodes` / `win.layerBind` …），它们共同依赖一个**没有写出来**
//   的前提 —— "进程里只有一个窗口"。建第二个窗口不是"半成品"，是**静默互相
//   破坏**：两窗交替 present 会互相重分配位图（闪烁 + 偶发错帧）、`ScreenToClient`
//   拿第一个窗的坐标系换算第二个窗的指针、`win.dpi` 只剩最后一个。
//   ⇒ 全部收进 `WindowSlot`、用 HWND 取槽：**窗口身份是参数，不是全局**。
//
// ★★ 为什么不留一个 `slot()` 返回"当前槽"的过渡态：那就是用一个隐式全局去修
//   另一个隐式全局 —— 多窗口时"用错窗口"不报错、只画错窗口，是最难定位的
//   错误类型。见 `docs/MULTI-WINDOW-2026-10-30.md` §4.2b 的同一条判断。
//
// ★★ 为什么槽**不 erase**（`WM_DESTROY` 只清资源）：`WindowStateStore::persist()`
//   跑在**退出路径**上 —— 那时窗口已经死透、句柄已经失效，它还要来问
//   `clientSize()` / `position()`。槽一删，"记住窗口位置"就永远写不出去
//   （2026-10-18 真机实测的静默失败）。保留一个空槽 ≈ 200 字节，可忽略。
//   ★ 句柄会被系统复用 ⇒ `createWindow` 里整槽重置（`= WindowSlot{}`），
//     新窗口不会继承上一个窗口的任何状态。

/** LWA / ULW 互斥的绑定态（状态机理由见 `bindLayered()` 的注释） */
enum { kBindNone = 0, kBindLwa = 1, kBindUlw = 2 };

class CProvider;   // UIA provider（定义在下方；槽只持指针）

/**
 * UIA 语义节点（§9 / M5，P2 数据流）。
 *
 * ★ 定义**挪到顶部**（原在 UIA 段）：`WindowSlot` 要持有 `vector<UiaNode>`，
 *   而 `std::vector` 的元素类型必须完整。它是纯数据、无依赖，挪动零风险。
 */
struct UiaNode {
    std::wstring name;
    long controlType = UIA_CustomControlTypeId;
    int parent = -1;   // 父节点索引（本槽的 uiaNodes），-1 = 顶层（父是窗口根）
    bool focused = false;
    double x = 0.0;
    double y = 0.0;
    double w = 0.0;
    double h = 0.0;
    bool isControl = true;
    // UIA `IsEnabled`（§4.4）：不实现它 ⇒ 客户端默认 false ⇒ `RangeValuePattern.SetValue`
    // 在客户端侧就抛 `ElementNotEnabledException`（**根本到不了 provider**）。
    // ★ `Toggle()` 的 .NET 封装不检查 IsEnabled，所以 Checkbox 当时"看起来能用"——
    //   这是"只验证一条 Pattern 会漏掉共用属性"的活证据。
    bool enabled = true;
    // 值型 Pattern（§4.4）：IValueProvider / IToggleProvider / IRangeValueProvider。
    // ★ value 是"人读串"（PHP SemanticsNode::$value）；range 只对 slider/progress 有意义。
    std::wstring value;
    double rangeMin = 0.0;
    double rangeMax = 0.0;
};

/**
 * 一个窗口的全部壳侧状态。
 *
 * ★ 字段的分组顺序就是它们被谁读：**帧缓冲 → 上屏资源 → 外观 → 几何 → IME →
 *   模态循环 → 指针 → UIA**。查一个字段时顺着这个顺序找。
 */
struct WindowSlot {
    // ── 帧缓冲：present 的落点，也是 `WM_PAINT` 重贴的源 ──
    // ★ 必须缓存：窗口被遮住又露出来时系统重发 `WM_PAINT`，而那一刻 PHP 正阻塞
    //   在 `waitEvents()` 里等事件 ⇒ 不重贴缓存就是白屏。
    std::vector<unsigned char> frame;
    int frameW = 0;
    int frameH = 0;

    // ── 逐像素上屏（ULW）资源：懒建、按帧尺寸重建 ──
    // ★ 为什么不用 `CreateCompatibleBitmap`：ULW 要求源是 **32bpp 预乘 BGRA**，
    //   而它给的是设备相关格式（可能 16bpp / 非预乘）⇒ 颜色错、半透明区发白。
    //   `CreateDIBSection` + `BI_RGB` 的字节序与 `frame` **逐字节相同**。
    HDC ulwDc = NULL;
    HBITMAP ulwBitmap = NULL;
    HBITMAP ulwOldBitmap = NULL;
    void *ulwBits = NULL;
    int ulwW = 0;
    int ulwH = 0;
    int layerBind = kBindNone;   // `kBindNone` / `kBindLwa` / `kBindUlw`

    // ── 外观素材 ──
    bool frameless = false;      // `WM_NCCALCSIZE` 让客户区=整窗（样式不变）
    int dragHeight = 0;          // 顶部拖动带高度（物理像素）
    bool pixelAlpha = false;     // 逐像素 alpha 上屏（与 layerBind 一起决定路径）
    BYTE opacity = 255;          // 整窗不透明度（LWA 的 alpha / ULW 的 SourceConstantAlpha）
    // 当前指针形状。★ 必须缓存：`WM_SETCURSOR` 每次鼠标移动都来一次，而那一刻
    //   PHP 可能正阻塞在等事件里（问不到框架）⇒ 只能重贴**上一次**的决定。
    HCURSOR cursor = NULL;

    // ── 几何 / DPI ──
    int clientW = 0;
    int clientH = 0;
    UINT dpi = 96;               // **每显示器** DPI（优先 `GetDpiForWindow`）
    // 最小客户区（物理像素；0 = 未设）。★ 必须有：窗口能被拖到比内容更小 ⇒
    //   布局硬校验弹 Fatal，而"窗口被拖小"是**用户行为**、不是编程错误。
    int minClientW = 0;
    int minClientH = 0;

    // ── 最后已知几何（销毁后仍要可读，见文件头"槽不 erase"的理由）──
    // 刷新两条路：① 每次活窗口查询成功（顺手刷新，零额外成本）；
    //           ② `WM_DESTROY`（决定性的一发 —— 退出前最后一帧的真实几何）。
    // 最小化 ⇒ 整份作废（最小化期间坐标在屏幕外，照抄会让下次启动"消失"）。
    int lastPosX = 0;
    int lastPosY = 0;
    int lastClientW = 0;
    int lastClientH = 0;
    bool lastMaximized = false;
    bool lastGeomValid = false;

    // ── IME 定位（§8.4）── 设备像素的客户区坐标；(-1,-1,0) = 无插入符
    // 两个消费点：组字窗位置（`WM_IME_STARTCOMPOSITION` / CFS_POINT）与
    // `IMR_QUERYCHARPOSITION` 应答。
    float imeX = -1.0f;
    float imeY = -1.0f;
    float imeH = 0.0f;
    // 1×1 假 caret 的可见态（输入法经 `GetCaretPos` 找候选框位置）。
    bool caretVisible = false;

    // ── 模态尺寸循环（§5.2）──
    // ★ **Windows 的插入符是线程级的**：第二个窗口 `CreateCaret` 会销毁第一个
    //   ⇒ 第一个窗的 IME 候选框失去锚点。所以 caret 的建/销必须跟着"当前在
    //   组字的那个窗口"（本槽）走，不能是进程级。
    bool inSizeMove = false;     // `WM_ENTERSIZEMOVE..WM_EXITSIZEMOVE`
    bool inResizeHook = false;   // 防重入：hook 内部再触发 WM_SIZE 不得嵌套进 PHP
    DWORD resizeHookMs = 0;      // 上一次 hook 耗时；0 = 尚未成功跑过

    // ── 指针跟踪（move / up / leave 通路）──
    // ★ 不请求 `TrackMouseEvent(TME_LEAVE)` 就没有 `WM_MOUSELEAVE` ⇒ 指针离开
    //   客户区时框架永远不知道（悬停目标卡在最后一个上、拖动不取消）。
    int ptrX = -1;
    int ptrY = -1;
    bool ptrTracked = false;

    // ── UIA 无障碍（§9 / M5）：每窗一棵语义树 + 一个 provider ──
    std::vector<UiaNode> uiaNodes;
    int lastFocusIdx = -2;       // 上次 push 的焦点节点索引（-2 = 未初始化）
    int lastNodeCount = -1;      // 上次 push 的节点数（结构变化事件用）
    CProvider *uiaProvider = nullptr;
};

/** 窗口身份表。取槽即建（未知句柄 ⇒ 全默认空槽，`lastGeomValid=false`） */
std::map<HWND, WindowSlot> g_slots;

/** 取/建一个窗口的槽。★ 返回引用：`std::map` 节点地址稳定，插入不会让它失效 */
WindowSlot &slotOf(HWND hwnd) {
    return g_slots[hwnd];
}

// ── 跨窗口的两张表（它们**不属于任何单个槽**，所以留在这里）──
// ★ 为什么用 set 而不是"每槽一个 bool"：`WM_DESTROY` 里要问"这是最后一个吗"，
//   而那是个**全局**问题（进程级）。UIA 之前的形状见 MULTI-WINDOW §5.1。
std::set<HWND> g_liveWindows;   // createWindow 成功 ⇒ 加入；WM_DESTROY ⇒ 移除
std::set<HWND> g_closeGuarded;  // 应用声明"关闭前要问我"的窗口

/** `AdjustWindowRectExForDpi` 的前置声明（定义在文件后部，WndProc 要用） */
void adjustFrameForDpi(RECT *rect, DWORD style, BOOL menu, DWORD exStyle, UINT dpi);

/** 窗口还活着 ⇒ 顺手刷新缓存；已死 ⇒ 回填最后已知值（从未记过 ⇒ 空数组） */
static void cacheFinalGeometry(HWND target) {
    if (target == NULL) {
        return;
    }
    WindowSlot &win = slotOf(target);
    if (IsIconic(target) != FALSE) {
        win.lastGeomValid = false;   // 最小化 = 别记，保留上一份
        return;
    }
    RECT client = {0, 0, 0, 0};
    if (!GetClientRect(target, &client)) {
        return;   // 抓不到就保留上一份，不清零
    }
    POINT origin = {client.left, client.top};
    if (!ClientToScreen(target, &origin)) {
        return;
    }
    win.lastPosX = origin.x;
    win.lastPosY = origin.y;
    win.lastClientW = client.right - client.left;
    win.lastClientH = client.bottom - client.top;
    win.lastMaximized = IsZoomed(target) != FALSE;
    win.lastGeomValid = true;
}

/** 死句柄查询时把最后已知几何摊进 out（x/y/w/h 四个键一起给，用哪半取哪半） */
static void fillGeomFromCache(HWND hwnd, Array &out) {
    WindowSlot &win = slotOf(hwnd);
    if (win.lastGeomValid) {
        out.set("x", (Float)(double)win.lastPosX);
        out.set("y", (Float)(double)win.lastPosY);
        out.set("w", (Int)win.lastClientW);
        out.set("h", (Int)win.lastClientH);
    }
}

// ★ IME / 帧缓冲 / 材质 / DPI 的字段都在 `WindowSlot` 里（见文件头）。它们原本是
//   一串进程级全局，共同依赖"只有一个窗口"这个没写出来的前提 —— 那样建第二个窗
//   就是静默互相破坏（两窗交替 present 互相重分配位图 ⇒ 闪烁 + 偶发错帧）。

/**
 * 待交付事件队列。WndProc 填，`php_drift_win_pump()` 取 —— 同线程，无需加锁（§5.3）。
 *
 * ★ **队列是线程级一份，不是每窗一份**：`GetMessageW(NULL, …)` 取的是本线程任意
 *   窗口的消息，一条队列天然覆盖全部窗口。归属靠 `PendingEvent::hwnd` 记下来。
 */
std::vector<PendingEvent> g_queue;

/**
 * 窗口 DPI（**每显示器**，优先 `GetDpiForWindow`）。
 *
 * ★ 前置声明：定义在 1600 行之后（要用 `LoadLibraryW` 动态取符号），而
 *   `WM_NCHITTEST` 的缩放热区在 1100 行就要按 DPI 换算 —— 不声明就是
 *   "未声明的标识符"（构建期就炸，不会静默）。
 */
UINT dpiOfWindow(HWND hwnd);

// ── UIA 无障碍（§9 / M5，P2 数据流）─────────────────────────────────
// PHP 把 SemanticsTree 扁平节点推进来（php_drift_win_uia_push）；provider 从
// 这份数组应答 UIA 客户端。P2 只到"根属性可达"，树导航（Fragment）归 P3。
// ★ `UiaNode` 的定义在文件顶部（`WindowSlot` 要持有它）；语义树本身按窗口分，
//   住 `slot.uiaNodes`。

// Drift 语义角色（SemanticsRole::value，string backed）⇒ UIA ControlTypeId。
// Windows 编号知识集中在这一处（§5.1 纪律 1：PHP 侧不持平台常量）。
static long uiaControlTypeFor(const std::string &role) {
    if (role == "button") return UIA_ButtonControlTypeId;
    if (role == "textfield") return UIA_EditControlTypeId;
    if (role == "scroll") return UIA_ListControlTypeId;
    if (role == "dialog") return UIA_WindowControlTypeId;
    // 值型角色（§4.4）：没有这层映射，客户端认不出"这是复选框/滑块" ⇒ 根本不会
    // 来问 TogglePattern / RangeValuePattern（Pattern 给了也没人问）。
    // ★ `switch` 没有独立的 UIA ControlType，用 CheckBox 承载开/关语义
    //   （两者都需要 TogglePattern；客户端据此能读出 On/Off）。
    if (role == "checkbox") return UIA_CheckBoxControlTypeId;
    if (role == "radio") return UIA_RadioButtonControlTypeId;
    if (role == "switch") return UIA_CheckBoxControlTypeId;
    if (role == "progress") return UIA_ProgressBarControlTypeId;
    if (role == "slider") return UIA_SliderControlTypeId;
    // 集合族（2026-10-29 补齐）：`table` 一直漏在这里（掉到 Custom 控制类型，
    // 客户端认不出"这是表格"），`tree` 随 TreeView 一起接上。
    if (role == "table") return UIA_TableControlTypeId;
    if (role == "tree") return UIA_TreeControlTypeId;
    return UIA_CustomControlTypeId;
}

// ── parent 关系走树 helper（uiaNodes 前序扁平，parent 指父索引，-1 = 窗口根）──
// ★ 树 helper 收 `const std::vector<UiaNode> &` 而不是读全局：它们被 provider
//   （持自己窗口的槽）与推送侧同时调用，参数化是唯一不会"拿错窗的树"的形状。
static int uiaFirstChild(const std::vector<UiaNode> &nodes, int idx) {
    for (size_t k = 0; k < nodes.size(); k++) {
        if (nodes[k].parent == idx) return (int)k;
    }
    return -1;
}
static int uiaNextSibling(const std::vector<UiaNode> &nodes, int idx) {
    if (idx < 0 || idx >= (int)nodes.size()) return -1;
    int p = nodes[idx].parent;
    for (size_t k = (size_t)idx + 1; k < nodes.size(); k++) {
        if (nodes[k].parent == p) return (int)k;
    }
    return -1;
}
static int uiaPrevSibling(const std::vector<UiaNode> &nodes, int idx) {
    if (idx < 0 || idx >= (int)nodes.size()) return -1;
    int p = nodes[idx].parent;
    for (int k = idx - 1; k >= 0; k--) {
        if (nodes[k].parent == p) return k;
    }
    return -1;
}
static int uiaLastChild(const std::vector<UiaNode> &nodes, int idx) {
    int last = -1;
    for (size_t k = 0; k < nodes.size(); k++) {
        if (nodes[k].parent == idx) last = (int)k;
    }
    return last;
}

// ★ 本 SDK（10.0.26100）UIA 接口扁平化：Fragment / FragmentRoot 都直接继承 IUnknown，
//   Simple 吸收 get_HostRawElementProvider，ShowContextMenu 已移除 ⇒ 多接口继承会
//   IUnknown 歧义。故主类只继承 IRawElementProviderFragmentRoot，Simple / Fragment 经
//   内嵌 adapter（QI 分派；adapter 的 IUnknown 委托主对象）。每 provider 持一个
//   语义树索引（-1 = 窗口根），Navigate 按 parent 走树（§9 / M5，P3）。
// ★ **progress 每个窗口一个 provider**（M1-a）：provider 持自己窗口的 HWND，
//   树的读写全走 `slotOf(hwnd).uiaNodes` —— 多窗口时两个 provider 各管各的树，
//   不再有"最后一个建窗的 provider 覆盖前一个"（还会连带漏 COM 引用）。

class SimpleAdapter : public IRawElementProviderSimple {
public:
    explicit SimpleAdapter(CProvider *host) : m_ref(1), m_host(host) {}
    STDMETHOD(QueryInterface)(REFIID riid, void **ppv) override;
    STDMETHOD_(ULONG, AddRef)() override;
    STDMETHOD_(ULONG, Release)() override;
    STDMETHOD(get_ProviderOptions)(ProviderOptions *p) override;
    STDMETHOD(GetPatternProvider)(PATTERNID id, IUnknown **ppv) override;
    STDMETHOD(GetPropertyValue)(PROPERTYID id, VARIANT *out) override;
    STDMETHOD(get_HostRawElementProvider)(IRawElementProviderSimple **r) override;
private:
    ULONG m_ref;
    CProvider *m_host;
};

class FragmentAdapter : public IRawElementProviderFragment {
public:
    explicit FragmentAdapter(CProvider *host) : m_ref(1), m_host(host) {}
    STDMETHOD(QueryInterface)(REFIID riid, void **ppv) override;
    STDMETHOD_(ULONG, AddRef)() override;
    STDMETHOD_(ULONG, Release)() override;
    STDMETHOD(Navigate)(NavigateDirection dir, IRawElementProviderFragment **ret) override;
    STDMETHOD(SetFocus)() override;
    STDMETHOD(GetRuntimeId)(SAFEARRAY **ret) override;
    STDMETHOD(GetEmbeddedFragmentRoots)(SAFEARRAY **ret) override;
    STDMETHOD(get_BoundingRectangle)(UiaRect *rect) override;
    STDMETHOD(get_FragmentRoot)(IRawElementProviderFragmentRoot **ret) override;
private:
    ULONG m_ref;
    CProvider *m_host;
};

// ── COM tear-off 的公共样板（§4.4）─────────────────────────────────
// 每个 UIA Pattern 都是"新造一个实现该接口的小对象、调用方 Release"。四个
// adapter 的 QI/AddRef/Release 逐字相同 ⇒ 收敛到这里，子类只写自己的 Pattern 方法。
// ★ CRTP（`D` = 派生类）是为了 `delete` 走真实类型：基类里 `delete this` 只调基类
//   析构，派生类若有成员就是泄漏；`static_cast<D*>(this)` 才对。
template <class D, class I>
class ComTearOff : public I {
public:
    STDMETHOD(QueryInterface)(REFIID riid, void **ppv) override {
        if (ppv == nullptr) return E_INVALIDARG;
        if (riid == __uuidof(IUnknown) || riid == __uuidof(I)) {
            *ppv = static_cast<I *>(this);
            AddRef();
            return S_OK;
        }
        *ppv = nullptr;
        return E_NOINTERFACE;
    }
    STDMETHOD_(ULONG, AddRef)() override { return ++m_ref; }
    STDMETHOD_(ULONG, Release)() override {
        ULONG r = --m_ref;
        if (r == 0) delete static_cast<D *>(this);
        return r;
    }

protected:
    ULONG m_ref = 1;
};

// ★★ IInvokeProvider 的 tear-off（§9 / M5 无障碍的"可操作"半边）。
//   每次 `GetPatternProvider(UIA_InvokePatternId)` 新造一个，由调用方 Release。
//   它只做一件事：把"第 m_index 个语义节点被 Invoke"转投成一条**窗口消息**，
//   由 WndProc 入队、PHP 侧翻成 `SemanticsInvokeEvent`。
//   ★ **激活语义本身不在这里复制**：PHP 落成"在节点中心派发一次合成 tap"，
//     复用输入路由 → 命中 → `Activatable::activate()` 那条唯一的路。
//     在这里按角色写一份"按钮该调什么"，每加一个控件就要补一支，且"读屏点"与
//     "鼠标点"行为不同是没人测得出来的那类缺陷。
class InvokeAdapter : public ComTearOff<InvokeAdapter, IInvokeProvider> {
public:
    explicit InvokeAdapter(HWND hwnd, int index) : m_hwnd(hwnd), m_index(index) {}
    STDMETHOD(Invoke)() override {
        // ★ 只投消息，**不**在这里进 PHP（理由见 `kMsgUiaInvoke` 的注释）：
        //   这是跨进程 COM 回调，重入 PHP 会让进程静默消失。
        // ★ 投给**自己窗口**（不是"主窗"）：多窗口时读屏激活必须落到被激活的那棵树。
        if (m_hwnd != NULL) {
            PostMessageW(m_hwnd, kMsgUiaInvoke, (WPARAM)m_index, 0);
        }
        return S_OK;
    }

private:
    HWND m_hwnd;
    int m_index;
};

// ── 值型 Pattern 的只读半边（§4.4 提交 1）──────────────────────────
// ★ 写方法（SetValue / Toggle）本提交明确回 `UIA_E_NOTSUPPORTED`，且
//   `get_IsReadOnly` = TRUE —— 客户端据此**不会**去调写方法（不是静默空壳，是
//   UIA 契约里的"这只读"）。写回路径（新事件 + 能力接口）见 §4.4 提交 2。
// ★ 数据全部来自 `slotOf(m_hwnd).uiaNodes[m_index]`，与 Invoke 同源，不另存状态。
class ValueAdapter : public ComTearOff<ValueAdapter, IValueProvider> {
public:
    explicit ValueAdapter(HWND hwnd, int index) : m_hwnd(hwnd), m_index(index) {}
    STDMETHOD(SetValue)(LPCWSTR value) override {
        // 转投窗口线程（与 Invoke 同一理由：COM RPC 线程里不能进 PHP）。
        // 字符串载荷用 `new std::wstring` 过消息 —— lParam 只能传整数；
        // WndProc 取走后 delete（所有权一次转移）。应用层是否接受由
        // `SemanticsValueEditable::setSemanticsValue()` 回答。
        if (m_hwnd != NULL) {
            PostMessageW(m_hwnd, kMsgUiaSetValue, (WPARAM)m_index,
                         (LPARAM)new std::wstring(value != nullptr ? value : L""));
        }
        return S_OK;
    }
    STDMETHOD(get_Value)(BSTR *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        std::wstring v;
        const std::vector<UiaNode> &nodes = slotOf(m_hwnd).uiaNodes;
        if (m_index >= 0 && m_index < (int)nodes.size()) {
            v = nodes[m_index].value;
        }
        *ret = SysAllocString(v.c_str());
        return S_OK;
    }
    STDMETHOD(get_IsReadOnly)(BOOL *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        // 可写：应用侧若没接 `onChanged`，`setSemanticsValue()` 会回 false
        // ⇒ 壳回 `UIA_E_ELEMENTNOTAVAILABLE`（不假装成功）。这里报"可写"是
        // 因为 adapter 不知道控件接没接回调，那是应用层的事实。
        *ret = FALSE;
        return S_OK;
    }

private:
    HWND m_hwnd;
    int m_index;
};

class ToggleAdapter : public ComTearOff<ToggleAdapter, IToggleProvider> {
public:
    explicit ToggleAdapter(HWND hwnd, int index) : m_hwnd(hwnd), m_index(index) {}
    STDMETHOD(Toggle)() override {
        // 复用 Invoke 的转投（§4.4 提交 2）：Checkbox 的 activate 语义就是"换值"，
        // ⇒ "读屏 Toggle"与"鼠标点击"走同一条 `Activatable::activate()`。
        // 零新消息、零新事件。
        if (m_hwnd != NULL) {
            PostMessageW(m_hwnd, kMsgUiaInvoke, (WPARAM)m_index, 0);
        }
        return S_OK;
    }
    STDMETHOD(get_ToggleState)(ToggleState *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        *ret = ToggleState_Off;
        const std::vector<UiaNode> &nodes = slotOf(m_hwnd).uiaNodes;
        if (m_index >= 0 && m_index < (int)nodes.size()) {
            const std::wstring &v = nodes[m_index].value;
            // radio 的 value = 选中时自己的值 / 未选中空串；checkbox、switch 是
            // checked / unchecked（见 `ToggleBase::refreshSemantics()`）。
            if (nodes[m_index].controlType == UIA_RadioButtonControlTypeId) {
                *ret = v.empty() ? ToggleState_Off : ToggleState_On;
            } else {
                *ret = (v == L"checked") ? ToggleState_On : ToggleState_Off;
            }
        }
        return S_OK;
    }

private:
    HWND m_hwnd;
    int m_index;
};

class RangeAdapter : public ComTearOff<RangeAdapter, IRangeValueProvider> {
public:
    explicit RangeAdapter(HWND hwnd, int index) : m_hwnd(hwnd), m_index(index) {}
    STDMETHOD(SetValue)(double value) override {
        // 与 ValueAdapter 同一条转投；double 先格式化成串（`%.17g` 能无损往返），
        // 由 `Slider::setSemanticsValue()` 解析后走 `emit()`（夹区间 + 只在变化时回调）。
        if (m_hwnd != NULL) {
            wchar_t buf[64];
            swprintf_s(buf, 64, L"%.17g", value);
            PostMessageW(m_hwnd, kMsgUiaSetValue, (WPARAM)m_index,
                         (LPARAM)new std::wstring(buf));
        }
        return S_OK;
    }
    STDMETHOD(get_Value)(double *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        *ret = wcstod(valueAt().c_str(), nullptr);
        return S_OK;
    }
    STDMETHOD(get_Minimum)(double *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        *ret = nodeOrZero().rangeMin;
        return S_OK;
    }
    STDMETHOD(get_Maximum)(double *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        *ret = nodeOrZero().rangeMax;
        return S_OK;
    }
    STDMETHOD(get_SmallChange)(double *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        *ret = 1.0;
        return S_OK;
    }
    STDMETHOD(get_LargeChange)(double *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        double mn = nodeOrZero().rangeMin;
        double mx = nodeOrZero().rangeMax;
        *ret = (mx > mn) ? (mx - mn) / 10.0 : 1.0;
        return S_OK;
    }
    STDMETHOD(get_IsReadOnly)(BOOL *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        // Slider 可写（应用接 `onChanged` 即可）；ProgressBar 是纯读数 ⇒ 只读。
        // adapter 分不出"这个控件实现没实现接口"，只能按 ControlType 判。
        const std::vector<UiaNode> &nodes = slotOf(m_hwnd).uiaNodes;
        *ret = (m_index >= 0 && m_index < (int)nodes.size()
                && nodes[m_index].controlType == UIA_ProgressBarControlTypeId) ? TRUE : FALSE;
        return S_OK;
    }

private:
    // value 是"人读串"（Slider 以 `%.0f` 格式化）；越界/空 ⇒ 空串（wcstod 得 0）。
    std::wstring valueAt() const {
        const std::vector<UiaNode> &nodes = slotOf(m_hwnd).uiaNodes;
        if (m_index < 0 || m_index >= (int)nodes.size()) return std::wstring();
        return nodes[m_index].value;
    }
    // 取"有则用之、无则全 0"的节点（Min/Max/大变化共用）。
    const UiaNode &nodeOrZero() const {
        static const UiaNode zero;
        const std::vector<UiaNode> &nodes = slotOf(m_hwnd).uiaNodes;
        if (m_index < 0 || m_index >= (int)nodes.size()) return zero;
        return nodes[m_index];
    }
    HWND m_hwnd;
    int m_index;
};

class CProvider : public IRawElementProviderFragmentRoot {
public:
    /** @param hwnd 这棵树属于哪个窗口（多窗口时每窗一个 provider，各管各的树） */
    explicit CProvider(HWND hwnd, int index)
        : m_ref(1), m_hwnd(hwnd), m_index(index), m_simple(new SimpleAdapter(this)), m_frag(new FragmentAdapter(this)) {}
    ~CProvider() {
        delete m_simple;
        delete m_frag;
    }

    // IUnknown（主对象）
    STDMETHOD(QueryInterface)(REFIID riid, void **ppv) override {
        if (ppv == nullptr) return E_INVALIDARG;
        if (riid == __uuidof(IUnknown) || riid == __uuidof(IRawElementProviderFragmentRoot)) {
            *ppv = static_cast<IRawElementProviderFragmentRoot *>(this);
            AddRef();
            return S_OK;
        }
        if (riid == __uuidof(IRawElementProviderSimple)) {
            *ppv = m_simple;
            m_simple->AddRef();
            return S_OK;
        }
        if (riid == __uuidof(IRawElementProviderFragment)) {
            *ppv = m_frag;
            m_frag->AddRef();
            return S_OK;
        }
        *ppv = nullptr;
        return E_NOINTERFACE;
    }
    STDMETHOD_(ULONG, AddRef)() override { return ++m_ref; }
    STDMETHOD_(ULONG, Release)() override {
        ULONG r = --m_ref;
        if (r == 0) delete this;
        return r;
    }

    // 造一个"能当 IRawElementProviderFragment"的子 provider（QI 取其 Fragment adapter，
    // 归还 new 的引用 ⇒ 返回指针持 host ref=1，客户端 Release 时 delete，引用平衡）。
    // ★ 子 provider 必须继承**同一个窗口**（`m_hwnd`）：树是同窗的一棵树，
    //   跨窗的 provider 读到的会是别的窗的节点数组。
    IRawElementProviderFragment *makeFragment(int idx) {
        CProvider *p = new CProvider(m_hwnd, idx);
        IRawElementProviderFragment *frag = nullptr;
        p->QueryInterface(__uuidof(IRawElementProviderFragment), (void **)&frag);
        p->Release();
        return frag;
    }

    // 造一个"能当 IRawElementProviderSimple"的 provider（focus changed raise 用：
    // 通知里带的是焦点元素本身）。引用契约同 makeFragment。
    IRawElementProviderSimple *makeFragmentSimple(int idx) {
        CProvider *p = new CProvider(m_hwnd, idx);
        IRawElementProviderSimple *simple = nullptr;
        p->QueryInterface(__uuidof(IRawElementProviderSimple), (void **)&simple);
        p->Release();
        return simple;
    }

    // IRawElementProviderFragmentRoot（本 SDK 只剩这两个纯虚）
    STDMETHOD(ElementProviderFromPoint)(double x, double y, IRawElementProviderFragment **ret) override {
        *ret = nullptr;
        // ★ 客户端给的是**屏幕**坐标 —— 与 `BoundingRectangle` 同一套口径，
        //   所以这里也必须走同一份几何（`nodeScreenRect()`），不能自己再算一遍。
        // ★ 以前这里恒返回窗口根（-1）。后果具体：读屏软件的**鼠标追踪**
        //   （NVDA 的 mouse tracking、讲述人的"鼠标指读"）靠这个入口问
        //   "鼠标下面是什么"，恒返回根 ⇒ 整窗被读成一个整体，读不到局部控件。
        //   客户端也没法靠遍历补 —— spec 就是把这个入口定为命中测试的唯一来源。
        // ★ 取**最深**的命中节点：前序扁平里"最后一个包含该点的"并不等于最深
        //   （前面兄弟的子树可能排在祖先之后），所以按 parent 链算深度比。
        //   同深度取后出现的 —— 后画的在上面，与绘制顺序一致。
        const std::vector<UiaNode> &nodes = slotOf(m_hwnd).uiaNodes;
        int best = -1;
        int bestDepth = -1;
        for (size_t k = 0; k < nodes.size(); k++) {
            RECT r;
            if (!nodeScreenRect((int)k, &r)) {
                continue;
            }
            if (x < (double)r.left || x >= (double)r.right
                || y < (double)r.top || y >= (double)r.bottom) {
                continue;
            }
            int depth = 0;
            int p = nodes[k].parent;
            // ★ 上溯**最多 nodes.size() 步**：`parent` 是 PHP 推来的裸整数
            //   （`node.parent = a.get("parent").toInt()`，没有校验）。成环的话
            //   这里就是死循环 —— 而且跑在读屏软件的消息回调里，等于整个窗口挂死。
            //   本文件别处用 parent 都只走**一步**（`uiaParent`），不会绕圈；
            //   这是唯一一处循环，界必须给。
            while (p >= 0 && p < (int)nodes.size() && depth <= (int)nodes.size()) {
                depth++;
                p = nodes[p].parent;
            }
            if (depth >= bestDepth) {
                bestDepth = depth;
                best = (int)k;
            }
        }
        *ret = makeFragment(best);
        return S_OK;
    }
    STDMETHOD(GetFocus)(IRawElementProviderFragment **ret) override {
        // 返回真实焦点节点（§9 / M5，B）；无焦点 ⇒ 窗口根（-1）。
        const std::vector<UiaNode> &nodes = slotOf(m_hwnd).uiaNodes;
        for (size_t k = 0; k < nodes.size(); k++) {
            if (nodes[k].focused) { *ret = makeFragment((int)k); return S_OK; }
        }
        *ret = makeFragment(-1);
        return S_OK;
    }

    // ── Simple 逻辑（SimpleAdapter 转发；按 m_index 读 Name / ControlType）──
    HRESULT simpleOptions(ProviderOptions *p) {
        *p = ProviderOptions_ServerSideProvider;
        return S_OK;
    }
    HRESULT simplePattern(PATTERNID id, IUnknown **ppv) {
        *ppv = nullptr;
        const std::vector<UiaNode> &nodes = slotOf(m_hwnd).uiaNodes;
        if (m_index < 0 || m_index >= (int)nodes.size()) return S_OK;
        long ctrl = nodes[m_index].controlType;
        // 按 ControlType 分派（§4.4）：控件"是什么"决定"能问什么 Pattern"。
        // ★ 不按 role 判 —— role→ControlType 已在 `uiaControlTypeFor` 收敛，
        //   多一层判据就是两处要同步。
        if (id == UIA_InvokePatternId && ctrl == UIA_ButtonControlTypeId) {
            *ppv = new InvokeAdapter(m_hwnd, m_index);
        } else if (id == UIA_ValuePatternId && ctrl == UIA_EditControlTypeId) {
            *ppv = new ValueAdapter(m_hwnd, m_index);
        } else if (id == UIA_TogglePatternId
                   && (ctrl == UIA_CheckBoxControlTypeId || ctrl == UIA_RadioButtonControlTypeId)) {
            *ppv = new ToggleAdapter(m_hwnd, m_index);
        } else if (id == UIA_RangeValuePatternId
                   && (ctrl == UIA_SliderControlTypeId || ctrl == UIA_ProgressBarControlTypeId)) {
            *ppv = new RangeAdapter(m_hwnd, m_index);
        }
        return S_OK;
    }
    HRESULT simpleProperty(PROPERTYID id, VARIANT *out) {
        VariantInit(out);
        std::wstring name = L"Drift";
        long ctrl = UIA_CustomControlTypeId;
        bool enabled = true;
        const std::vector<UiaNode> &nodes = slotOf(m_hwnd).uiaNodes;
        if (m_index == -1) {
            ctrl = UIA_WindowControlTypeId;
        } else if (m_index >= 0 && m_index < (int)nodes.size()) {
            name = nodes[m_index].name;
            ctrl = nodes[m_index].controlType;
            enabled = nodes[m_index].enabled;
        }
        if (id == UIA_NamePropertyId) {
            out->vt = VT_BSTR;
            out->bstrVal = SysAllocString(name.c_str());
        } else if (id == UIA_ControlTypePropertyId) {
            out->vt = VT_I4;
            out->lVal = ctrl;
        } else if (id == UIA_IsControlElementPropertyId || id == UIA_IsContentElementPropertyId) {
            out->vt = VT_BOOL;
            out->boolVal = VARIANT_TRUE;
        } else if (id == UIA_IsEnabledPropertyId) {
            // ★ 不实现它 ⇒ 客户端默认 false ⇒ `SetValue` 在客户端就抛
            //   `ElementNotEnabledException`（provider 根本收不到调）。
            out->vt = VT_BOOL;
            out->boolVal = enabled ? VARIANT_TRUE : VARIANT_FALSE;
        } else if (id == UIA_IsOffscreenPropertyId) {
            // ★ 不实现它 ⇒ 客户端默认 false ⇒ 读屏软件会对着**窗口外**画焦点框。
            //   具体场景：滚动区里滚出视口的列表项仍在语义树里、仍报布局矩形
            //   （2026-10-30 实测 y 到 1452，而客户区底 712），焦点走到它们时
            //   放大镜/高亮框就飞到窗口外面去了。
            // ★ 判据是"矩形有没有落在客户区里"，几何取 `nodeScreenRect()`
            //   —— 与 `BoundingRectangle` 同一份换算（理由见该函数）。
            //   ★ 不用"宽度为 0"判：零面积容器（分组）只是画不出东西，不代表不在屏上，
            //     两者混在一起会让客户端对分组节点做多余的处理。
            // ★ 窗口根（-1）恒在屏上。
            bool off = false;
            RECT r;
            RECT client;
            if (m_index >= 0 && GetClientRect(m_hwnd, &client)) {
                POINT org;
                org.x = 0;
                org.y = 0;
                ClientToScreen(m_hwnd, &org);
                OffsetRect(&client, org.x, org.y);
                RECT hit;
                off = !nodeScreenRect(m_index, &r) || !IntersectRect(&hit, &r, &client);
            }
            out->vt = VT_BOOL;
            out->boolVal = off ? VARIANT_TRUE : VARIANT_FALSE;
        }
        return S_OK;
    }
    HRESULT simpleHost(IRawElementProviderSimple **r) {
        *r = nullptr;
        return S_OK;
    }

    // ── Fragment 逻辑（FragmentAdapter 转发；按 parent 走树）──
    HRESULT fragNavigate(NavigateDirection dir, IRawElementProviderFragment **ret) {
        *ret = nullptr;
        const std::vector<UiaNode> &nodes = slotOf(m_hwnd).uiaNodes;
        if (m_index == -1) {
            if (dir == NavigateDirection_FirstChild) {
                int c = uiaFirstChild(nodes, -1);
                if (c >= 0) *ret = makeFragment(c);
            }
            return S_OK;
        }
        int target = -1;
        bool valid = false;
        switch (dir) {
            case NavigateDirection_Parent:
                target = m_index < (int)nodes.size() ? nodes[m_index].parent : -1;   // -1 = 窗口根（合法）
                valid = true;
                break;
            case NavigateDirection_FirstChild:
                target = uiaFirstChild(nodes, m_index);
                valid = target >= 0;
                break;
            case NavigateDirection_LastChild:
                target = uiaLastChild(nodes, m_index);
                valid = target >= 0;
                break;
            case NavigateDirection_NextSibling:
                target = uiaNextSibling(nodes, m_index);
                valid = target >= 0;
                break;
            case NavigateDirection_PreviousSibling:
                target = uiaPrevSibling(nodes, m_index);
                valid = target >= 0;
                break;
            default:
                break;
        }
        if (valid) *ret = makeFragment(target);
        return S_OK;
    }
    HRESULT fragSetFocus() { return S_OK; }
    HRESULT fragRuntimeId(SAFEARRAY **ret) {
        int id = m_index;
        *ret = SafeArrayCreateVector(VT_I4, 0, 1);
        LONG idx = 0;
        SafeArrayPutElement(*ret, &idx, &id);
        return S_OK;
    }
    HRESULT fragEmbedded(SAFEARRAY **ret) {
        *ret = nullptr;
        return S_OK;
    }
    /**
     * 节点矩形：**逻辑客户区 → 屏幕物理像素**。返回 false = 算不出来（窗口销毁中）。
     *
     * ★★ 全仓只有这一处做这两步换算。`BoundingRectangle`（问"在哪"）与
     *    `IsOffscreen`（问"在不在"）必须**同源** —— 各写一份就会出现
     *    "矩形说在屏上、offscreen 说不在"这种客户端无法解释的组合。
     *    成熟 provider（WPF `AutomationPeer` / Chromium `ViewAccessibility`）
     *    也是这对属性成对提供。
     *   ★ ① 逻辑 → 物理：× `dpi/96`。取 `dpiOfWindow()` **现查**，不读 `slot.dpi`
     *     —— 后者靠 `WM_DPICHANGED` 落地，跨屏拖动后消息还没派发的那几帧仍是旧值，
     *     而 UIA 客户端恰恰会在任意时刻来问（读屏跟随读的是"现在"）。
     *     `dpiOfWindow()` 优先 `GetDpiForWindow()`，与 `php_drift_win_dpi()` 同源
     *     ⇒ 全仓一套 DPI 口径，不新增第二个真相源。
     *   ★ ② 客户区 → 屏幕：`ClientToScreen`。**不能**自己加 `GetWindowRect()` 的原点
     *     —— 那含标题栏与边框，而节点坐标是客户区内的（差一整圈装饰）。
     *     漏掉 ② 的后果不是"差一点"：读屏软件拿矩形做**放大镜跟随**和 mouse routing
     *     （NVDA 的点读），整窗偏移会让焦点框画到标题栏上；FlaUI / WinAppDriver /
     *     pywinauto 也按屏幕坐标点，一起点偏。
     *   ★ 2026-10-30 之前这里返回客户区坐标，注释写"位置近似（尺寸对、原点未加
     *     客户区偏移）"—— 那是把"没做"写成了"近似"，而 UIA 契约里没有近似。
     */
    bool nodeScreenRect(int index, RECT *out) {
        if (m_hwnd == nullptr) {
            return false;
        }
        const std::vector<UiaNode> &nodes = slotOf(m_hwnd).uiaNodes;
        if (index < 0 || index >= (int)nodes.size()) {
            return false;
        }

        UINT dpi = dpiOfWindow(m_hwnd);
        const double scale = (dpi == 0 ? 96.0 : (double)dpi) / 96.0;

        POINT pt;
        pt.x = (LONG)(nodes[index].x * scale + 0.5);
        pt.y = (LONG)(nodes[index].y * scale + 0.5);
        if (!ClientToScreen(m_hwnd, &pt)) {
            return false;
        }

        out->left = pt.x;
        out->top = pt.y;
        out->right = pt.x + (LONG)(nodes[index].w * scale + 0.5);
        out->bottom = pt.y + (LONG)(nodes[index].h * scale + 0.5);
        return true;
    }

    HRESULT fragBoundingRect(UiaRect *rect) {
        // 默认零矩形：越界 / 窗口已销毁 / 换算失败都落在这里（理由见末段）。
        rect->left = rect->top = rect->width = rect->height = 0.0;

        RECT r;
        if (!nodeScreenRect(m_index, &r)) {
            return S_OK;
        }
        rect->left = (double)r.left;
        rect->top = (double)r.top;
        // 用 right-left 而不是再乘一遍 scale：让 `left + width == right` 严格成立，
        // 客户端拿矩形画焦点框时不会看到 1px 的缝。
        rect->width = (double)(r.right - r.left);
        rect->height = (double)(r.bottom - r.top);
        return S_OK;
    }
    HRESULT fragFragmentRoot(IRawElementProviderFragmentRoot **ret) {
        *ret = new CProvider(m_hwnd, -1);   // CProvider* → FragmentRoot*（upcast，直接基类）
        return S_OK;
    }

private:
    ULONG m_ref;
    HWND m_hwnd;
    int m_index;
    SimpleAdapter *m_simple;
    FragmentAdapter *m_frag;
};

// ── adapter 方法定义（CProvider 之后，故可调其 public simple*/frag*）──
STDMETHODIMP SimpleAdapter::QueryInterface(REFIID riid, void **ppv) {
    if (ppv == nullptr) return E_INVALIDARG;
    if (riid == __uuidof(IUnknown)) { *ppv = m_host; m_host->AddRef(); return S_OK; }
    if (riid == __uuidof(IRawElementProviderSimple)) { *ppv = this; AddRef(); return S_OK; }
    return m_host->QueryInterface(riid, ppv);
}
STDMETHODIMP_(ULONG) SimpleAdapter::AddRef() { return m_host->AddRef(); }
STDMETHODIMP_(ULONG) SimpleAdapter::Release() { return m_host->Release(); }
STDMETHODIMP SimpleAdapter::get_ProviderOptions(ProviderOptions *p) { return m_host->simpleOptions(p); }
STDMETHODIMP SimpleAdapter::GetPatternProvider(PATTERNID id, IUnknown **ppv) { return m_host->simplePattern(id, ppv); }
STDMETHODIMP SimpleAdapter::GetPropertyValue(PROPERTYID id, VARIANT *out) { return m_host->simpleProperty(id, out); }
STDMETHODIMP SimpleAdapter::get_HostRawElementProvider(IRawElementProviderSimple **r) { return m_host->simpleHost(r); }

STDMETHODIMP FragmentAdapter::QueryInterface(REFIID riid, void **ppv) {
    if (ppv == nullptr) return E_INVALIDARG;
    if (riid == __uuidof(IUnknown)) { *ppv = m_host; m_host->AddRef(); return S_OK; }
    if (riid == __uuidof(IRawElementProviderFragment)) { *ppv = this; AddRef(); return S_OK; }
    return m_host->QueryInterface(riid, ppv);
}
STDMETHODIMP_(ULONG) FragmentAdapter::AddRef() { return m_host->AddRef(); }
STDMETHODIMP_(ULONG) FragmentAdapter::Release() { return m_host->Release(); }
STDMETHODIMP FragmentAdapter::Navigate(NavigateDirection dir, IRawElementProviderFragment **ret) { return m_host->fragNavigate(dir, ret); }
STDMETHODIMP FragmentAdapter::SetFocus() { return m_host->fragSetFocus(); }
STDMETHODIMP FragmentAdapter::GetRuntimeId(SAFEARRAY **ret) { return m_host->fragRuntimeId(ret); }
STDMETHODIMP FragmentAdapter::GetEmbeddedFragmentRoots(SAFEARRAY **ret) { return m_host->fragEmbedded(ret); }
STDMETHODIMP FragmentAdapter::get_BoundingRectangle(UiaRect *rect) { return m_host->fragBoundingRect(rect); }
STDMETHODIMP FragmentAdapter::get_FragmentRoot(IRawElementProviderFragmentRoot **ret) { return m_host->fragFragmentRoot(ret); }
// ★ `win.uiaProvider` / `win.cursor` / `g_ptr*` 都已进 `WindowSlot`（见文件头）：
//   它们本来就是 per-window 的 —— provider 挂在 HWND 上、`SetCursor` 作用于 HWND、
//   指针跟踪也是每窗一个 `TrackMouseEvent`。

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

/**
 * 入队一条事件，并叫醒阻塞在 `GetMessageW` 的泵。
 *
 * @param hwnd 事件的**来源窗口** —— 由调用方（WndProc）给，`enqueue` 只负责记进
 *   `event.hwnd`。★ 为什么不让 `enqueue` 自己猜：那就是把"当前窗口"重新变成
 *   全局。多窗口时事件归属错 = 一个窗的 resize 去改另一个窗的表面。
 */
void enqueue(HWND hwnd, PendingEvent event) {
    event.hwnd = hwnd;
    // ★ resize 合并（coalesce，2026-10-19 修"松手后卡顿"）：拖动边缘时系统
    //   高频同步派发 WM_SIZE（ modal resize loop 期间每条都进这里），而应用帧
    //   循环要等 modal loop 退出后才逐条重放 ⇒ N 条中间尺寸各触发一次全树重排
    //   + Skia 重画，大窗口下每帧几十 ms，串行重放就是秒级卡顿。
    //   合并到队列中**最早未消费**的那条（保持顺序位置，只更新尺寸）⇒ 松手后
    //   一帧直达最终尺寸。同尺寸的重复帧对用户零信息（只看最终态）。
    //   ★ 只合并**同一个窗口**的 resize：两个窗口各自的尺寸互不相干。
    if (event.kind == kEvResize) {
        for (auto &queued : g_queue) {
            if (queued.kind == kEvResize && queued.hwnd == hwnd) {
                queued.a = event.a;
                queued.b = event.b;
                if (hwnd != NULL) {
                    PostMessageW(hwnd, kMsgWake, 0, 0);
                }
                return;
            }
        }
    }
    g_queue.push_back(event);
    if (hwnd != NULL) {
        // 把阻塞在 GetMessageW 上的泵叫醒。载荷在队列里，这条消息只是闹钟。
        PostMessageW(hwnd, kMsgWake, 0, 0);
    }
}

/**
 * 关闭请求入队（`WM_CLOSE` 且该窗声明了守卫时走这里）。
 *
 * ★ **为什么"关闭"要入队而不是在 WndProc 里同步问 PHP**：转投的理由有两条，
 *   第二条才是决定性的 ——
 *   ① 泵的契约是"一次交一条事件"，在 WndProc 里跑应用代码就没有"下一帧会跑"的保证
 *      （与 `kMsgUiaInvoke` 同一条理由）；
 *   ② 关闭守卫的典型实现要**弹模态确认框**，而 `MessageBoxW` 自己会重入消息循环
 *      ⇒ WndProc 会被再入、`ResizeHookState::$pump` 那条 PHP 回调会在中间被调，
 *      等于在"半个帧"里跑应用代码。入队则让确认框落在**帧边界**。
 * ★ **合并未消费的请求**：用户连点三下 X、或应用自己在帧里又调了一次 `close()`，
 *   都会产生多条 `WM_CLOSE`。不合并就是"确认框弹三次"，而三次问的是同一个问题。
 *   与 `kEvResize` 的 coalesce 同一手法，区别是关闭请求**没有载荷**要合并 ——
 *   队列里已经有一条就什么都不做。
 * ★ 叫醒用**发起请求的那个窗口**：泵阻塞在 `GetMessageW(NULL, …)`（取本线程
 *   任意窗口的消息），投给谁都能叫醒，但投给请求者更贴近事实。
 */
void enqueueCloseRequest(HWND hwnd) {
    for (const auto &queued : g_queue) {
        if (queued.kind == kEvCloseRequested && queued.hwnd == hwnd) {
            return;   // 这个窗口已有一条未消费的关闭请求
        }
    }

    PendingEvent event;
    event.hwnd = hwnd;
    event.kind = kEvCloseRequested;
    event.a = 0;
    event.b = 0;
    event.c = 0;
    event.d = 0;
    event.e = 0;
    g_queue.push_back(event);

    if (hwnd != NULL) {
        PostMessageW(hwnd, kMsgWake, 0, 0);
    }
}

/**
 * 指针事件入队（四相位共用一个壳；顺带记最后已知位置供 `WM_MOUSELEAVE` 用）。
 *
 * ★ `phase` 与 `Drift\Input\PointerPhase` 的 value 同值（0=down 1=move 2=up
 *   3=leave）—— 与 `CursorShape` 同一条纪律：跨边界的枚举值是 ABI。
 */
/**
 * 当前按住的修饰键位（1=ctrl 2=alt 4=shift）—— 与 `key` 事件同一套编码。
 *
 * ★ 为什么不用鼠标消息 wParam 里的 MK_CONTROL / MK_SHIFT：那里**没有 Alt**
 *   （MK_MBUTTON 之类占着那些位），而"Alt+点击"在表格/画布类控件里是真实手势
 *   （列宽拖拽的"仅此列"、画布的平移）。三个都读 `GetKeyState` 才是一致的口径。
 * ★ 读的是**当前**状态而不是消息发生时的状态：两者只在"按住鼠标的同时按/放
 *   修饰键"这一瞬间可能有差别，而这个瞬间本来就没有消费者。
 */
int modifierMask() {
    int mask = 0;
    if ((GetKeyState(VK_CONTROL) & 0x8000) != 0) mask |= 1;
    if ((GetKeyState(VK_MENU) & 0x8000) != 0) mask |= 2;
    if ((GetKeyState(VK_SHIFT) & 0x8000) != 0) mask |= 4;

    return mask;
}

void pushPointer(HWND hwnd, int x, int y, int button, int phase) {
    WindowSlot &win = slotOf(hwnd);
    win.ptrX = x;
    win.ptrY = y;

    PendingEvent event = {};
    event.kind = kEvPointer;
    event.a = x;
    event.b = y;
    event.c = button;
    event.d = phase;
    event.e = modifierMask();
    enqueue(hwnd, event);
}

// ───────────────────────────────────────────────────────────── 贴屏 ──

/** 把缓存帧贴到给定 DC。`WM_PAINT` 与 `present()` 共用这一条路径。
 *
 *  ★ `dstW/dstH` 非 0 = 目标尺寸与帧不同（resize 拖动中：PHP 还没推出新帧，
 *    客户区已变大）⇒ **原尺寸贴 + 余区填帧底色**。
 *  ★ **不拉伸**（2026-10-19 用户裁决）：拉伸会让用户看到"旧内容被放大/重复"，
 *    比一块同色留白难受得多。余区取帧右下角像素当底色 —— 那就是应用自己的
 *    背景色，深色主题也自动跟随。
 *  ★ 带 `hwnd` 是必须的：贴的是**那个窗口**的帧。调用方总是刚好有句柄
 *    （`WM_PAINT` 的 hwnd / `present()` 的 target），白给。 */
void presentTo(HWND hwnd, HDC dc, int dstW = 0, int dstH = 0) {
    WindowSlot &win = slotOf(hwnd);
    if (win.frame.empty() || win.frameW <= 0 || win.frameH <= 0 || dc == NULL) {
        return;
    }
    if (dstW <= 0 || dstH <= 0) {
        dstW = win.frameW;
        dstH = win.frameH;
    }

    BITMAPINFO info = {};
    info.bmiHeader.biSize        = sizeof(BITMAPINFOHEADER);
    info.bmiHeader.biWidth       = win.frameW;
    info.bmiHeader.biHeight      = -win.frameH;   // 负高 = 自上而下，与 RGBA 行序一致
    info.bmiHeader.biPlanes      = 1;
    info.bmiHeader.biBitCount    = 32;
    info.bmiHeader.biCompression = BI_RGB;

    if (dstW == win.frameW && dstH == win.frameH) {
        SetDIBitsToDevice(dc, 0, 0, win.frameW, win.frameH, 0, 0, 0, win.frameH,
                          win.frame.data(), &info, DIB_RGB_COLORS);
    } else {
        // ★ 尺寸不匹配（拖动中 PHP 新帧未到）：**原尺寸贴 + 余区用帧背景色填**
        //   —— **不拉伸**（2026-10-19 用户裁决：拉伸会看到"旧内容放大/重复"，
        //   比留白更难受）。取帧右下角像素当背景色（那就是应用的底色，零配置、
        //   深色主题也自动跟随）。
        const int w = dstW < win.frameW ? dstW : win.frameW;
        const int h = dstH < win.frameH ? dstH : win.frameH;

        // 余区填底色（右下角像素 = BGRA）；先填两块余区（右、下），再贴帧（不重叠）
        const unsigned char *last = win.frame.data() + win.frame.size() - 4;
        HBRUSH brush = CreateSolidBrush(RGB(last[2], last[1], last[0]));
        if (brush != NULL) {
            if (dstW > w) {
                RECT rightR = {w, 0, dstW, dstH};
                FillRect(dc, &rightR, brush);
            }
            if (dstH > h) {
                RECT bottomR = {0, h, w, dstH};
                FillRect(dc, &bottomR, brush);
            }
            DeleteObject(brush);
        }
        SetDIBitsToDevice(dc, 0, 0, w, h, 0, 0, 0, h, win.frame.data(), &info, DIB_RGB_COLORS);
    }
}

// ─────────────────────────────────── 逐像素上屏（ULW，Stage 2b）──

// ★ `kBindNone` / `kBindLwa` / `kBindUlw` 这三个绑定态的说明见 `bindLayered()`；
//   枚举定义在文件顶部（`WindowSlot::layerBind` 的初值要用它）。
// ★ ULW 的 DIB section / 内存 DC 也都是**每窗一份**（`windowSlot.ulw*`）：
//   两窗共用一份会互相按对方尺寸重建位图 ⇒ 闪烁 + 偶发错帧。

/** 释放一个窗口的 ULW 资源（DIB section + 内存 DC；幂等） */
static void releaseUlwSurface(WindowSlot &win) {
    if (win.ulwDc != NULL) {
        if (win.ulwOldBitmap != NULL) {
            SelectObject(win.ulwDc, win.ulwOldBitmap);
            win.ulwOldBitmap = NULL;
        }
        DeleteDC(win.ulwDc);
        win.ulwDc = NULL;
    }
    if (win.ulwBitmap != NULL) {
        DeleteObject(win.ulwBitmap);
        win.ulwBitmap = NULL;
    }
    win.ulwBits = NULL;
    win.ulwW = 0;
    win.ulwH = 0;
}

/**
 * 把窗口的绑定态切到 `want`（`kBindLwa` / `kBindUlw`），并把该模式的属性设上。
 *
 * ★ 只有**模式变了**才摘挂 `WS_EX_LAYERED`；LWA 的 alpha 每次都给一遍（幂等）——
 *   否则第二次 `set_opacity()` 会因为"已经在 LWA 模式"而**静默不生效**。
 * ★ 摘挂会丢掉之前设的 alpha ⇒ 重设一遍，否则"切回来以后整窗突然全不透明"。
 * ★ 切到 ULW 时**不**设任何东西：`SourceConstantAlpha` 每次 present 都带。
 */
static void bindLayered(HWND hwnd, int want) {
    WindowSlot &win = slotOf(hwnd);
    if (win.layerBind != want) {
        LONG_PTR ex = GetWindowLongPtrW(hwnd, GWL_EXSTYLE);
        SetWindowLongPtrW(hwnd, GWL_EXSTYLE, ex & ~WS_EX_LAYERED);
        SetWindowLongPtrW(hwnd, GWL_EXSTYLE, ex | WS_EX_LAYERED);
        SetWindowPos(hwnd, NULL, 0, 0, 0, 0,
                     SWP_NOMOVE | SWP_NOSIZE | SWP_NOZORDER | SWP_NOACTIVATE | SWP_FRAMECHANGED);
        win.layerBind = want;
    }

    if (want == kBindLwa) {
        SetLayeredWindowAttributes(hwnd, 0, win.opacity, LWA_ALPHA);
    }
}

/**
 * 用 `UpdateLayeredWindow` 把 `win.frame`（**预乘 BGRA**）交给合成器。返回是否真的贴了。
 *
 * ★★ 四条不变量，少一条都是"画面不对但**不报错**"：
 *   ① **预乘**：`BLENDFUNCTION.AlphaFormat = AC_SRC_ALPHA` 的语义就是"源已预乘"
 *      ⇒ 再乘一次 alpha 会让半透明区二次压暗（看起来像"底色比设计稿深一档"）。
 *   ② **`pptDst` / `psize` 必须给具体值，不能传 NULL**（本机实测，踩过）：
 *      两个都传 NULL 时 `UpdateLayeredWindow` **返回成功但画面一个像素都不变** ——
 *      现象是"窗口完全不可见、日志干净、`win.frame` 里像素全对"。MSDN 说
 *      "位置/尺寸不变时可以为 NULL"，但那条路在本机 Win10/11 上不生效。
 *      给的值就是**当前窗口矩形** ⇒ 既不动位置也不改尺寸（与 `WM_SIZE` 不打架）。
 *   ③ **帧尺寸必须等于窗口矩形**才贴：不等就是 resize 中途（新帧还没来），或者
 *      窗口还有标题栏（客户区比窗口小一个标题栏 ⇒ 位图会整体上移）。
 *      ULW 没有"余区"可填，位图多大就多大 ⇒ 这里**返回 false**，让调用方回落 GDI。
 *   ④ 失败也返回 false（**绝不留下"分层窗但没内容"的状态** —— 那等于窗口凭空消失）。
 *
 * ★ **前提是无边框**（`set_frameless`）：客户区=整窗，位图才与窗口矩形一一对齐。
 */
static bool presentLayered(HWND hwnd) {
    WindowSlot &win = slotOf(hwnd);
    if (win.frame.empty() || win.frameW <= 0 || win.frameH <= 0) {
        return false;
    }

    RECT wr;
    if (!::GetWindowRect(hwnd, &wr)) {
        return false;
    }
    if ((int)(wr.right - wr.left) != win.frameW || (int)(wr.bottom - wr.top) != win.frameH) {
        return false;   // 见不变量 ③
    }

    bindLayered(hwnd, kBindUlw);

    if (win.ulwBitmap == NULL || win.ulwW != win.frameW || win.ulwH != win.frameH) {
        releaseUlwSurface(win);

        HDC screen = GetDC(NULL);
        win.ulwDc = CreateCompatibleDC(screen);
        if (screen != NULL) {
            ReleaseDC(NULL, screen);
        }
        if (win.ulwDc == NULL) {
            return false;
        }

        BITMAPINFO info = {};
        info.bmiHeader.biSize        = sizeof(BITMAPINFOHEADER);
        info.bmiHeader.biWidth       = win.frameW;
        info.bmiHeader.biHeight      = -win.frameH;   // 负高 = 自上而下，与 win.frame 行序一致
        info.bmiHeader.biPlanes      = 1;
        info.bmiHeader.biBitCount    = 32;
        info.bmiHeader.biCompression = BI_RGB;

        void *bits = NULL;
        win.ulwBitmap = CreateDIBSection(win.ulwDc, &info, DIB_RGB_COLORS, &bits, NULL, 0);
        if (win.ulwBitmap == NULL || bits == NULL) {
            releaseUlwSurface(win);
            return false;
        }
        win.ulwOldBitmap = (HBITMAP)SelectObject(win.ulwDc, win.ulwBitmap);
        win.ulwBits = bits;
        win.ulwW = win.frameW;
        win.ulwH = win.frameH;
    }

    memcpy(win.ulwBits, win.frame.data(), (size_t)win.frameW * (size_t)win.frameH * 4u);

    POINT src = {0, 0};
    POINT dst = {wr.left, wr.top};        // 当前位置 ⇒ 不移动窗口
    SIZE size = {win.frameW, win.frameH};     // 当前尺寸 ⇒ 不改窗口大小

    BLENDFUNCTION blend = {};
    blend.BlendOp             = AC_SRC_OVER;
    blend.SourceConstantAlpha = win.opacity;   // `set_opacity` 在两种模式下语义一致
    blend.AlphaFormat         = AC_SRC_ALPHA;

    return UpdateLayeredWindow(hwnd, NULL, &dst, &size, win.ulwDc, &src, 0, &blend, ULW_ALPHA) != FALSE;
}

/**
 * 这一帧能不能走逐像素上屏：要"声明过 + 无边框"。
 *
 * ★ **无边框是硬前提**（见 `presentLayered()` 的不变量 ③）：有标题栏时位图会与
 *   客户区错开一个标题栏高度，画面上移、底边缺一条 —— 那种"半对"比不透明更糟。
 *   不满足就**回落 GDI**（整窗不透明），应用侧只是"透明没生效"，不是黑屏。
 */
static bool layeredPixelReady(HWND hwnd) {
    WindowSlot &win = slotOf(hwnd);
    return win.pixelAlpha && win.frameless;
}

// ─────────────────────────────────────── 拖动重排入口（§5.2 模态循环）──
// 拖窗口边缘时系统进入模态尺寸循环，PHP 主循环被挂起 ⇒ 拖动中的刷新只能由壳
// **同步**调回 PHP 跑一帧。这是编译期已知的具名函数（不是回调穿越边界）：
// PHP 侧 `src/Platform/Win32/ResizeHook.php`（无 namespace，AOT 符号名约定）。
// 声明在文件顶部**全局作用域**（见那里的注释：匿名 namespace 会改符号名）。

/** 跟手预算（ms）：超过则跳过本次（与 §5.2 的 100ms 因果感知上限同源，更严） */
static const DWORD kResizeHookBudgetMs = 48;

/**
 * 让 PHP 同步重排 + 重绘 + present 一帧（安全封装：防重入 + 预算）。
 *
 * ★ 带 `hwnd`：模态循环是**那个窗口**在拖（多窗口下可能两个窗同时被拖 ⇒
 *   各自的 inSizeMove / 预算互不干扰）。PHP 侧转回来 present 时也用它。
 */
static void pumpResizeHook(HWND hwnd) {
    WindowSlot &win = slotOf(hwnd);
    if (win.inResizeHook || win.resizeHookMs >= kResizeHookBudgetMs) {
        return;   // 正在跑 / 上次太慢：本次跳过，画面由拉伸兜底或松手后收尾
    }
    win.inResizeHook = true;
    const DWORD started = GetTickCount();
    ::php_drift_resize_hook_pump((Int)(INT_PTR)hwnd);
    win.resizeHookMs = GetTickCount() - started;
    win.inResizeHook = false;
}

/** hook 是否真的产出了**匹配当前客户区**的帧（PHP 未注入时 hook 是 no-op） */
static bool resizeHookProducedFrame(HWND hwnd) {
    WindowSlot &win = slotOf(hwnd);
    return win.frameW == win.clientW && win.frameH == win.clientH && win.frameW > 0;
}

// ─────────────────────────────────────────── WndProc ──

LRESULT CALLBACK driftWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp) {
    // ★ 每个窗口一条槽：WndProc 里所有 per-window 读写的**唯一入口**。
    //   取在 switch 之外（不是每个 case 各取一次）—— 少一次 `map` 查找，
    //   且"这个函数只管这个窗口"在签名上一眼可见。
    WindowSlot &win = slotOf(hwnd);
    switch (msg) {
        case WM_NCCREATE:
            // ★ 句柄会被系统**复用**：新窗口必须从零开始，否则会继承上一个窗口的
            //   frameless / 最小尺寸 / 材质 / 透明度（不报错、只在特定分配顺序下
            //   出现的那种 bug）。选 `WM_NCCREATE` 是因为它最早 —— 早于
            //   WM_NCCALCSIZE / WM_SIZE，那两条会往槽里写尺寸。
            // ★ `break` 落到函数末尾的 `DefWindowProcW`：这条消息必须交回系统
            //   （返回 FALSE 会让 `CreateWindowExW` 直接失败）。
            win = WindowSlot{};
            break;
        case WM_GETOBJECT:
            // UIA 无障碍（§9 / M5）：UIA 客户端问窗口 provider ⇒ QI 出 Simple 交出。
            if (lp == (LPARAM)UiaRootObjectId && win.uiaProvider != nullptr) {
                IRawElementProviderSimple *simple = nullptr;
                if (SUCCEEDED(win.uiaProvider->QueryInterface(__uuidof(IRawElementProviderSimple), (void **)&simple))) {
                    return UiaReturnRawElementProvider(hwnd, wp, lp, simple);
                }
            }
            break;
        case kMsgUiaInvoke: {
            // UIA `Invoke` 的落地（§9 / M5）：**只入队，不进 PHP**。
            //
            // ★★ 为什么不是"在这里直接跑 PHP"：`Invoke()` 来自 COM 的 RPC 线程，
            //   我们已经把它转投到窗口线程（不能在那儿重入 PHP —— 实测进程静默消失）。
            //   但即便到了窗口线程也还有第二个坑：消息泵的契约是"一次调用交一条
            //   事件"，在派发里跑完 PHP 就没人保证**下一帧会跑** —— 真机现象是
            //   "命令执行了、状态也变了、画面一动不动"（要等下一条用户输入才刷）。
            //   ⇒ 入队 ⇒ `pump()` 交出去 ⇒ 组合根抽完事件立刻 `FrameClock::tick()`
            //     跑帧，激活引起的那次重建**同一轮循环**就落盘。
            PendingEvent event;
            event.hwnd = hwnd;
            event.kind = kEvUiaInvoke;
            event.a = (int) (INT_PTR) wp;
            event.b = 0;
            event.c = 0;
            event.d = 0;
            g_queue.push_back(event);
            // 叫醒阻塞在 `GetMessageW` 的泵（同 WM_SIZE / WM_TIMER 的做法）
            PostMessageW(hwnd, kMsgWake, 0, 0);
            return 0;
        }

        case kMsgUiaSetValue: {
            // UIA `SetValue` 的落地（§4.4 提交 2）：与 Invoke 同一条"入队 + 叫醒"，
            // 唯一多出来的是字符串载荷（lParam 是 `new std::wstring`，这里接管所有权）。
            std::wstring *payload = (std::wstring *) lp;
            PendingEvent event;
            event.hwnd = hwnd;
            event.kind = kEvUiaSetValue;
            event.a = (int) (INT_PTR) wp;
            event.b = 0;
            event.c = 0;
            event.d = 0;
            if (payload != nullptr) {
                event.text = toUtf8(payload->c_str(), (int) payload->size());
                delete payload;
            }
            g_queue.push_back(event);
            PostMessageW(hwnd, kMsgWake, 0, 0);
            return 0;
        }

        case WM_CLOSE:
            // ★ 关闭的**唯一入口**（用户点 X 与应用调 `WindowHost::close()` 同路，
            //   后者是 `PostMessageW(WM_CLOSE)`）。
            // ★ 声明了守卫 ⇒ 不销毁，入队一条"有人要关我"，由帧边界决定
            //   （见 `enqueueCloseRequest`）；否则保持 2026-10-30 之前的行为：
            //   不调 DefWindowProc、直接销毁（Drift 的退出语义只有一条）。
            if (g_closeGuarded.count(hwnd) != 0) {
                enqueueCloseRequest(hwnd);
                return 0;
            }
            DestroyWindow(hwnd);
            return 0;

        case WM_DESTROY:
            // ★ 销毁**之前**抓最后几何（§5.4 的救命一发：persist 会在这个窗口
            //   死透之后才来问它，见 `lastGeom*` 的注释）。
            cacheFinalGeometry(hwnd);
            // ★ 守卫与活窗口表都要摘：句柄会被系统复用，留着就是"下一个窗
            //   莫名继承了上一窗的关闭行为"（不报错、只在特定分配顺序下出现）。
            g_closeGuarded.erase(hwnd);
            g_liveWindows.erase(hwnd);
            // ★ 槽里的**资源**必须在这里释放（槽本身留着 —— 退出路径还要读
            //   `lastGeom*`）：不释放就是每开一次窗口漏一张整屏位图 + 一个
            //   COM provider。`frame` 一并缩容，否则几十个窗口的历史帧会攒起来。
            releaseUlwSurface(win);
            win.layerBind = kBindNone;
            win.frame.clear();
            win.frame.shrink_to_fit();
            win.uiaNodes.clear();
            win.uiaNodes.shrink_to_fit();
            if (win.uiaProvider != nullptr) {
                // Release 而不是 delete：UIA 系统可能仍持一份引用（它是 COM 对象）。
                win.uiaProvider->Release();
                win.uiaProvider = nullptr;
            }
            // ★ 关**最后一个**窗口才退出进程（2026-10-30）：`PostQuitMessage` 是
            //   进程级的，多窗口下"关一个 = 全退"正是 §5 裁决 ① 要否掉的语义。
            //   单窗口时两个分支等价（那个窗就是最后一个）。
            if (g_liveWindows.empty()) {
                PostQuitMessage(0);
            }
            return 0;

        case WM_ENTERSIZEMOVE: {
            // 模态尺寸循环开始（§5.2）：PHP 主循环即被挂起 ⇒ 由下面的 WM_SIZE
            // 分支**同步**产帧（Flutter Windows embedder 同款：在 WM_SIZE 里等到
            // 一张匹配新尺寸的帧；模态循环是串行的 ⇒ 产帧期间尺寸不会变）。
            win.inSizeMove = true;
            return 0;
        }

        case WM_EXITSIZEMOVE: {
            win.inSizeMove = false;
            // 松手：常规循环恢复（积压的 resize 已被合并成一条，见 enqueue）
            return 0;
        }

        case WM_GETMINMAXINFO: {
            // 最小尺寸（§5.4）：客户区 min（物理像素）⇒ 外框 min（路径同建窗换算）。
            // 0 = 未设 ⇒ 交给 DefWindowProc 的系统默认。
            if (win.minClientW > 0 && win.minClientH > 0) {
                MINMAXINFO *info = (MINMAXINFO *)lp;
                if (info != NULL) {
                    RECT frame = {0, 0, win.minClientW, win.minClientH};
                    adjustFrameForDpi(
                        &frame,
                        (DWORD)GetWindowLongPtrW(hwnd, GWL_STYLE),
                        FALSE,
                        (DWORD)GetWindowLongPtrW(hwnd, GWL_EXSTYLE),
                        win.dpi);
                    info->ptMinTrackSize.x = frame.right - frame.left;
                    info->ptMinTrackSize.y = frame.bottom - frame.top;
                }
            }
            return 0;
        }

        case WM_NCCALCSIZE: {
            // ★ 无边框（P1-Stage 1b）：`wp == TRUE` = "窗口尺寸/位置变了，重算
            //   非客户区"。**返回 0 且不改 `rgrc[0]`** ⇒ 系统把"整个窗口矩形"当客户区
            //   （标题栏消失），而窗口样式仍是 `WS_OVERLAPPEDWINDOW` ⇒ 贴边吸附 /
            //   快照布局 / 最大化动画全部由系统照旧。`WS_POPUP` 会丢掉这些原生行为。
            if (wp != TRUE || !win.frameless) {
                break;
            }
            // ★ **最大化必须扣回"不可见缩放边框"**：系统给最大化窗口的矩形比显示器
            //   大一圈（四边各约 8px 的 resize frame），原样返回 ⇒ 内容超出屏幕、
            //   **盖住任务栏**。这是无边框实现最经典的一个坑（真机才看得见）。
            if (IsZoomed(hwnd) != FALSE) {
                NCCALCSIZE_PARAMS *p = (NCCALCSIZE_PARAMS *) lp;
                int fx = GetSystemMetrics(SM_CXSIZEFRAME) + GetSystemMetrics(SM_CXPADDEDBORDER);
                int fy = GetSystemMetrics(SM_CYSIZEFRAME) + GetSystemMetrics(SM_CXPADDEDBORDER);
                p->rgrc[0].left += fx;
                p->rgrc[0].right -= fx;
                p->rgrc[0].top += fy;
                p->rgrc[0].bottom -= fy;
            }
            return 0;
        }

        case WM_NCHITTEST: {
            // 非无边框 ⇒ 交给系统默认（原生标题栏/边框的命中语义）。
            if (!win.frameless) {
                break;
            }
            POINT client = { GET_X_LPARAM(lp), GET_Y_LPARAM(lp) };
            ScreenToClient(hwnd, &client);
            RECT rc;
            GetClientRect(hwnd, &rc);

            // ★ 最大化：不可缩放 ⇒ 只剩"顶部拖动带"，四边/四角一律 `HTCLIENT`。
            //   漏了这条，最大化后拖窗口边缘会让系统"还原 + 缩放"（真机怪异）。
            if (IsZoomed(hwnd) != FALSE) {
                if (win.dragHeight > 0 && client.y < win.dragHeight) {
                    return HTCAPTION;
                }
                return HTCLIENT;
            }

            // 边缘缩放热区按 DPI 缩放（6 逻辑像素）：不缩放的话 200% 下只有 3 逻辑像素宽。
            UINT dpi = dpiOfWindow(hwnd);
            if (dpi == 0) {
                dpi = 96;
            }
            const int edge = (int) MulDiv(6, (int) dpi, 96);
            const bool inLeft   = client.x < edge;
            const bool inRight  = client.x >= rc.right - edge;
            const bool inTop    = client.y < edge;
            const bool inBottom = client.y >= rc.bottom - edge;
            // 四角优先（对角缩放）
            if (inTop && inLeft)     { return HTTOPLEFT; }
            if (inTop && inRight)    { return HTTOPRIGHT; }
            if (inBottom && inLeft)  { return HTBOTTOMLEFT; }
            if (inBottom && inRight) { return HTBOTTOMRIGHT; }
            if (inLeft)              { return HTLEFT; }
            if (inRight)             { return HTRIGHT; }
            if (inBottom)            { return HTBOTTOM; }
            // 顶部拖动带（`setFrameless()` 的第 2 参）⇒ 拖窗不吞按钮点击。
            // ★ 本仓应用走**另一条路**：`AppBar::windowDrag()` 由框架精确判断
            //   "命中的是顶条自己而不是按钮"，所以组合根传 `dragHeight = 0`
            //   （这条原生带留给"不画顶条的应用"当兜底）。
            if (win.dragHeight > 0 && client.y < win.dragHeight) {
                return HTCAPTION;
            }
            return HTCLIENT;
        }

        case WM_SIZE: {
            int w = LOWORD(lp);
            int h = HIWORD(lp);
            // 最小化时 lp 是 0；宽高非正一律忽略（恢复时会再来一条 WM_SIZE）
            if (w > 0 && h > 0) {
                win.clientW = w;
                win.clientH = h;
                // ★ 拖动中**同步产帧**（§5.2，Flutter/Qt 同口径）：模态循环串行
                //   ⇒ 这里产帧期间下一个鼠标消息不会被处理、尺寸不会变 ⇒ 帧必然
                //   匹配当前客户区。产完再决定要不要拉伸兜底（正常情况下不需要）。
                if (win.inSizeMove) {
                    pumpResizeHook(hwnd);
                }

                // ★ 2026-10-19：**不清帧**。旧实现在这里 clear() ⇒ WM_PAINT 时
                //   presentTo 无内容可贴，resize 拖动中新露出的区域全黑。
                //   尺寸不匹配时由 presentTo 走"原尺寸贴 + 余区填底色"（**不拉伸**）。
                if (win.layerBind != kBindUlw
                    && !resizeHookProducedFrame(hwnd)
                    && !win.frame.empty() && win.frameW > 0 && win.frameH > 0
                    && (win.frameW != w || win.frameH != h)) {
                    // ★ 逐像素模式下没有这条兜底：ULW 没有"余区"，位图多大就多大
                    //   （见 `presentLayered()` 的不变量 ③）⇒ 拖动中露出的一块在
                    //   新帧到达前是透明的。这是"不拉伸"裁决在 ULW 下的必然结果。
                    HDC dc = GetDC(hwnd);
                    if (dc != NULL) {
                        presentTo(hwnd, dc, w, h);
                        ReleaseDC(hwnd, dc);
                    }
                }

                PendingEvent event = {};
                event.kind = kEvResize;
                event.a = w;
                event.b = h;
                enqueue(hwnd, event);
            }
            return 0;
        }

        case WM_ACTIVATE: {
            // 窗口失活 = 用户切走了（Alt+Tab / 点别的应用 / 系统弹窗抢前台）。
            //
            // ★ **为什么必须入队**：指针手势的全部收口信号都来自指针自己
            //   （`WM_LBUTTONUP` / `WM_MOUSELEAVE`），而"拖到一半切走"两个都
            //   不会来 —— 指针没动（没有 `WM_MOUSEMOVE`），用户在别处松手
            //   （`WM_LBUTTONUP` 落在别的窗口）⇒ 应用侧的捕获/竞技场/拖动/
            //   框选全部卡在"进行中"，回到窗口后表现为"某个控件永久只收它的
            //   事件"或"拖到一半不动了"。
            //
            // ★ 只发 `WA_INACTIVE`，**不发对称的激活**：今天没有消费者需要在
            //   激活时做任何事（A15：没有消费者不预先发明线上语义）。
            //
            // ★ 用 `WM_ACTIVATE` 而不是 `WM_KILLFOCUS`：前者语义就是"这扇窗
            //   不再是前台"，与"用户切走了"一一对应；后者还会被"焦点在窗口内
            //   部控件之间移动"触发（今天单窗口无此情形，但语义不对）。
            if (LOWORD(wp) == WA_INACTIVE) {
                PendingEvent event = {};
                event.kind = kEvDeactivate;
                enqueue(hwnd, event);
            }
            // 不 `return 0`：这条消息没有"必须吞掉"的理由，交给 DefWindowProc
            // （其它壳分支 `return 0` 是因为它们**处理**了那条消息，这里只是旁听）。
            break;
        }

        case WM_DROPFILES: {
            // A5-② 拖文件进窗（§7 A5 第二件，§27）。老式 shell 拖放：wParam 是 HDROP。
            //
            // ★ 只解**文件路径**：DragQueryFileW 逐个取宽字符路径 → UTF-8 → 用 '\n'
            //   拼进 event.text 一个字符串。为什么不用数组跨 ABI：数组字段跨实例读在
            //   AOT 上是 C2679/php::Var 高发区（§24）；'\n'(U+000A) 在 Win32 文件名里
            //   **非法** ⇒ 分隔无损，且 PHP 侧 explode 可单测（同 A5-① filterText 思路）。
            // ★ 落点用 DragQueryPoint（客户区物理坐标）⇒ PHP 侧除 dpr 转逻辑单位。
            HDROP drop = (HDROP)wp;
            UINT count = DragQueryFileW(drop, 0xFFFFFFFF, NULL, 0);
            POINT point = {0, 0};
            DragQueryPoint(drop, &point);   // 失败（极少）⇒ 保持 (0,0)，不阻断投递

            std::string joined;
            for (UINT i = 0; i < count; ++i) {
                UINT len = DragQueryFileW(drop, i, NULL, 0);   // 不含结尾 NUL 的字符数
                std::vector<wchar_t> buf((size_t)len + 1, L'\0');
                DragQueryFileW(drop, i, buf.data(), len + 1);
                std::string utf8 = toUtf8(buf.data(), (int)len);
                if (i != 0) {
                    joined += "\n";
                }
                joined += utf8;
            }
            DragFinish(drop);   // ★ 必须释放系统为 HDROP 分配的内存，否则每次拖放泄漏

            PendingEvent event = {};
            event.kind = kEvFilesDropped;
            event.a = point.x;
            event.b = point.y;
            event.text = joined;
            enqueue(hwnd, event);
            return 0;
        }

        case WM_SETTINGCHANGE: {
            // §9.2：系统主题变更（深色/浅色切换）。lParam 是被改的设置区名
            // （宽字符指针）—— 时区、语言、显示设置等也会发这条消息，只认
            // "ImmersiveColorSet"（Win10+ 的颜色模式区）。
            if (lp != 0 && wcscmp((LPCWSTR)lp, L"ImmersiveColorSet") == 0) {
                PendingEvent event = {};
                event.kind = kEvTheme;
                enqueue(hwnd, event);
            }
            return 0;
        }

        case WM_DPICHANGED: {
            win.dpi = HIWORD(wp);
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
            event.a = (int)win.dpi;
            enqueue(hwnd, event);
            return 0;
        }

        case WM_TIMER: {
            PendingEvent event = {};
            event.kind = kEvTimer;
            event.a = (int)wp;   // id 是 PHP 给的，这里只是原样回传
            enqueue(hwnd, event);
            return 0;
        }

        case WM_PAINT: {
            PAINTSTRUCT ps;
            HDC dc = BeginPaint(hwnd, &ps);
            // ★ 逐像素模式下**不贴**：内容由 `UpdateLayeredWindow` 交给合成器，
            //   GDI 往客户区画的会被分层表面整个盖住 —— 白费一次 4MB 贴屏，还会在
            //   "刚被遮住又露出来"时闪一下不透明内容。
            //   ★ `BeginPaint`/`EndPaint` 仍然必须配对：不配对 = 重绘区域永不失效 ⇒
            //     `WM_PAINT` 连发，CPU 烧在空转上（而画面看起来"没什么不对"）。
            //   ★ 判据是**绑定态**而不是 `win.pixelAlpha`：ULW 没贴上时会回落 GDI
            //     （见 `presentLayered()` 的返回值），那时 GDI 内容才是屏幕上的真相。
            if (win.layerBind != kBindUlw) {
                // 帧尺寸与客户区不同（resize 后 PHP 新帧未到）⇒ 原尺寸贴 + 余区填底色
                RECT rc;
                presentTo(hwnd, dc, GetClientRect(hwnd, &rc) ? (int)(rc.right - rc.left) : 0,
                          rc.bottom - rc.top);
            }
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
                SetCursor(win.cursor != NULL ? win.cursor : LoadCursorW(NULL, cursorIdForShape(0)));
                return TRUE;
            }
            break;

        case WM_LBUTTONDOWN:
        case WM_MBUTTONDOWN:
        case WM_RBUTTONDOWN: {
            int button = 0;
            if (msg == WM_MBUTTONDOWN) button = 1;
            if (msg == WM_RBUTTONDOWN) button = 2;

            pushPointer(hwnd, GET_X_LPARAM(lp), GET_Y_LPARAM(lp), button, 0);   // Down
            return 0;
        }

        case WM_MOUSEMOVE: {
            // ★ TrackMouseEvent(TME_LEAVE)：不请求跟踪就没有 WM_MOUSELEAVE ⇒
            //   指针离开客户区时框架永远不知道（悬停目标卡在最后一个上、
            //   拖动不取消）。首次移动时请求一次，离开后复位。
            if (!win.ptrTracked) {
                TRACKMOUSEEVENT tme = {};
                tme.cbSize = sizeof(tme);
                tme.dwFlags = TME_LEAVE;
                tme.hwndTrack = hwnd;
                TrackMouseEvent(&tme);
                win.ptrTracked = true;
            }

            // ★ move 的 button 恒 0：wp 里的 MK_* 位（按住态）没有消费者 ——
            //   拖动状态由应用自己的 pending 记录跟踪（§9.1 / A15：不预先
            //   发明线上语义）。
            pushPointer(hwnd, GET_X_LPARAM(lp), GET_Y_LPARAM(lp), 0, 1);   // Move
            return 0;
        }

        case WM_LBUTTONUP:
        case WM_MBUTTONUP:
        case WM_RBUTTONUP: {
            int button = 0;
            if (msg == WM_MBUTTONUP) button = 1;
            if (msg == WM_RBUTTONUP) button = 2;

            pushPointer(hwnd, GET_X_LPARAM(lp), GET_Y_LPARAM(lp), button, 2);   // Up
            return 0;
        }

        case WM_MOUSELEAVE: {
            win.ptrTracked = false;   // 下次进入要重新请求跟踪

            // 坐标是最后已知位置（系统不再给新坐标）—— 消费方只看"离开了"
            pushPointer(hwnd, win.ptrX, win.ptrY, 0, 3);   // Leave
            return 0;
        }

        case WM_MOUSEWHEEL: {
            // ★ 与鼠标按键消息不同：滚轮的 `GET_X_LPARAM`/`GET_Y_LPARAM` 给的是
            //   **屏幕**坐标 ⇒ 必须 ScreenToClient，否则"滚对了距离、位置差一个
            //   窗口原点"（命中测试判在窗外 ⇒ 轮被吞，静默）。
            // ★ 符号（2026-10-19 修正）：原生 delta 正 = 滚轮前推（远离用户）。
            //   框架侧 `ScrollEvent::$delta` 的约定是"正 = 内容上移（往下看）"。
            //   用户环境要的手感是**滚轮跟随视口**：前推 ⇒ 看到上面、回拉 ⇒
            //   看到下面（与触控板的"上划看到下面"同族的直接操作感），故壳在
            //   边界上取负 —— 平台惯例 → 框架语义的翻译只发生在这一处。
            //   （Windows 传统映射是前推=看到下面；本仓按用户明确要求取反。）
            POINT pt = { GET_X_LPARAM(lp), GET_Y_LPARAM(lp) };
            ScreenToClient(hwnd, &pt);   // ★ 用 **本窗**（不是"主窗"）：多窗口下各自坐标系

            // ★ Shift+竖轮 = 横轮（2026-10-27）：没有横向滚轮 / 触控板时，这是
            //   用户唯一能横向滚的入口，也是 Windows 应用的通用手势。
            //   翻译放在壳里而不是 PHP：与上面的取反同一条纪律 ——
            //   "平台惯例 → 框架语义"只发生在这一处，`ScrollEvent` 只认轴、
            //   不认修饰键（否则每个消费方都要自己再判一次 Shift）。
            // ★ 符号：横向前推 = 看右边 = 原生正方向 ⇒ **不取负**。纵向取负是
            //   用户对纵向手感的明确要求（见上），横向没有这条要求。
            const int raw = GET_WHEEL_DELTA_WPARAM(wp);
            const bool horiz = (modifierBits() & 4) != 0;   // 4 = shift

            PendingEvent event = {};
            event.kind = kEvWheel;
            event.a = (int)pt.x;
            event.b = (int)pt.y;
            event.c = horiz ? raw : -raw;
            event.e = horiz ? 1 : 0;
            enqueue(hwnd, event);
            return 0;
        }

        case WM_MOUSEHWHEEL: {
            // 触控板横滑 / 横向滚轮。★ 坐标同样是**屏幕**坐标（理由见上）。
            // ★ 原生 delta 正 = 向右滚 = 看右边，与框架横向正向一致 ⇒ 不取负。
            POINT pt = { GET_X_LPARAM(lp), GET_Y_LPARAM(lp) };
            ScreenToClient(hwnd, &pt);   // ★ 本窗（理由同上）

            PendingEvent event = {};
            event.kind = kEvWheel;
            event.a = (int)pt.x;
            event.b = (int)pt.y;
            event.c = GET_WHEEL_DELTA_WPARAM(wp);
            event.e = 1;
            enqueue(hwnd, event);
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
            enqueue(hwnd, event);
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
            enqueue(hwnd, event);
            return 0;
        }

        case WM_IME_STARTCOMPOSITION: {
            // 组字开始：系统将建自己的子窗口（DefWindowProc 做的）。我们只把
            // 它的位置指到光标处 —— CFS_POINT（GUI 基线：组字窗 CFS_POINT、
            // 候选窗不硬塞，"建议位置"的语义系统可调）。
            // ★ ClientToScreen 是必须的：ImmSetCompositionWindow 吃屏幕坐标，
            //   而 win.imeX/Y 是设备像素的客户区坐标。
            // ★ 2026-10-19 补三件（真机候选框贴屏幕左上角的修复）：
            //   ① 创建 1×1 **可见**系统 caret —— 不少输入法（含部分 TSF 系）
            //      通过 GetCaretPos 定位候选框；caret 不存在时查询失败返回
            //      (0,0)，候选框就落到屏幕左上角。只 SetCaretPos 不建 caret
            //      对这一类输入法无效。
            //   ② ImmSetCandidateWindow（默认候选窗，同点）—— IMM32 输入法
            //      （搜鱼/QQ 类）的**候选框**位置由它决定，不设就默认位置。
            //   ③ 见 WM_IME_ENDCOMPOSITION 的清理。
            if (win.imeX >= 0.0f) {
                double imeScale = (double)win.dpi / 96.0;
                LONG cx = (LONG)((double)win.imeX / imeScale);
                LONG cy = (LONG)((double)win.imeY / imeScale);
                if (!win.caretVisible) {
                    CreateCaret(hwnd, nullptr, 1, (int)(win.imeH / imeScale) > 0 ? (int)(win.imeH / imeScale) : 1);
                    ShowCaret(hwnd);
                    win.caretVisible = true;
                }
                SetCaretPos(cx, cy);

                HIMC imc = ImmGetContext(hwnd);
                if (imc != NULL) {
                    POINT pt = {cx, cy};
                    ClientToScreen(hwnd, &pt);
                    COMPOSITIONFORM form = {};
                    form.dwStyle = CFS_POINT;
                    form.ptCurrentPos = pt;
                    ImmSetCompositionWindow(imc, &form);
                    CANDIDATEFORM cand = {};
                    cand.dwIndex = 0;
                    cand.dwStyle = CFS_POINT;
                    cand.ptCurrentPos = pt;
                    ImmSetCandidateWindow(imc, &cand);
                    ImmReleaseContext(hwnd, imc);
                }
            }
            return 0;   // ⚠ 不吞消息：DefWindowProc 之后仍要收到它建组字窗
        }

        case WM_IME_ENDCOMPOSITION: {
            // 组字结束：收起 caret（否则光标处留一个残留黑点）。
            if (win.caretVisible) {
                HideCaret(hwnd);
                DestroyCaret();
                win.caretVisible = false;
            }
            return 0;
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
                        enqueue(hwnd, event);
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
                    if (win.imeX < 0.0f || hwnd == NULL) {
                        // 无插入符：报客户区左上角（0,0）高度 1 —— 合法值。
                        pos->pt.x = 0;
                        pos->pt.y = 0;
                        pos->cLineHeight = 1;
                        RECT rc = {};
                        GetClientRect(hwnd, &rc);
                        pos->rcDocument = rc;
                    } else {
                        double imeScale = (double)win.dpi / 96.0;
                        LONG cx = (LONG)((double)win.imeX / imeScale);
                        LONG cy = (LONG)((double)win.imeY / imeScale);
                        POINT pt = {cx, cy};
                        ClientToScreen(hwnd, &pt);   // 同样吃屏幕坐标
                        pos->pt.x = pt.x;
                        pos->pt.y = pt.y;
                        pos->cLineHeight = (LONG)((double)win.imeH / imeScale);
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

    // ★ **每条事件都带来源窗口**（2026-10-30，M1-a）：队列是线程级一份，PHP 侧
    //   要靠它把 resize / dpi / 输入派给**对的**那个 `Application`。为 `0`
    //   表示"没有窗口归属"（理论上只有 quit 会这样，而 quit 不走这个函数）。
    //   ★ 统一在 switch 之前写，而不是每个 case 各补一行 —— 漏一个 case 就是
    //     "这条事件永远派不对窗口"，且不会报错。
    if (event.hwnd != NULL) {
        out.set("hwnd", (Int)(INT_PTR)event.hwnd);
    }

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
            out.set("phase", (Int)event.d);
            // 修饰键（1=ctrl 2=alt 4=shift ⇒ 与 `key` 事件同样的三个布尔）
            out.set("ctrl", (Bool)((event.e & 1) != 0));
            out.set("alt", (Bool)((event.e & 2) != 0));
            out.set("shift", (Bool)((event.e & 4) != 0));
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

        case kEvWheel:
            out.set("type", "wheel");
            out.set("x", (Int)event.a);
            out.set("y", (Int)event.b);
            out.set("delta", (Int)event.c);
            // ★ 横向才带这个字段：纵向是缺省（PHP 侧 `?? vertical`）⇒ 老壳 /
            //   老断言不带轴时行为不变。
            if (event.e != 0) {
                out.set("axis", String("horizontal"));
            }
            break;

        case kEvTheme:
            out.set("type", "theme");
            break;

        case kEvDeactivate:
            out.set("type", "deactivate");
            break;

        case kEvUiaInvoke:
            out.set("type", "uia-invoke");
            out.set("idx", (Int)event.a);
            break;

        case kEvUiaSetValue:
            out.set("type", "uia-set-value");
            out.set("idx", (Int)event.a);
            out.set("value", String(event.text));
            break;

        case kEvFilesDropped:
            out.set("type", "files-dropped");
            out.set("x", (Int)event.a);
            out.set("y", (Int)event.b);
            // '\n' 分隔的多路径字符串；PHP 侧 explode 还原数组（见 FilesDroppedEvent）
            out.set("paths", String(event.text));
            break;

        case kEvCloseRequested:
            // 载荷为空：问的是"要不要关"，不是"谁要关"
            out.set("type", "close-requested");
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

    // 问不到窗口 DPI（无 `GetDpiForWindow` 且拿不到 DC）⇒ 退回**这个窗口**
    // 上一次算出来的值。★ 不是"进程里最后算过的那个"：多窗口下那会是别的窗的。
    return slotOf(hwnd).dpi;
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

// 取 PE 里的品牌图标（tpc 由 `resource.icon` 编进 exe，见 build/base.yml）。
//
// ★ 按**名字**取，不按 101：tpc 生成的 .rc 写的是 `MAINICON ICON "..."`，而它
//   **不**给 rc 下 `#define MAINICON 101`（只有 `generateHeader()` 会产出那个
//   define，且没有消费者）⇒ rc.exe 把 MAINICON 当成**字符串 ID**。实测
//   `FindResource(RT_GROUP_ICON, 101)` 返回 0，按 101 加载必然拿不到。
// ★ 数字 101 再试一次：tpc 哪天补上 define，资源就变成数字 ID —— 两级都试，
//   免得那边一改这边**静默**退回系统默认图标（那种错不报错、只是看着不对）。
// ★ 用 LoadImage 而不是 LoadIcon：后者固定给 32x32，高 DPI 下标题栏 / Alt-Tab 会糊。
static HICON loadModuleIcon(HINSTANCE instance, int cx, int cy)
{
    HICON icon = (HICON)LoadImageW(instance, L"MAINICON", IMAGE_ICON, cx, cy, LR_SHARED);
    if (icon == NULL) {
        icon = (HICON)LoadImageW(instance, MAKEINTRESOURCEW(101), IMAGE_ICON, cx, cy, LR_SHARED);
    }

    return icon;
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
    // ★ 这里用**局部变量**而不是槽：此刻还没有窗口，也就没有槽可写。
    UINT preDpi = 96;
    HDC screen = GetDC(NULL);
    if (screen != NULL) {
        int systemDpi = GetDeviceCaps(screen, LOGPIXELSX);
        ReleaseDC(NULL, screen);
        if (systemDpi > 0) {
            preDpi = (UINT)systemDpi;
        }
    }
    if (preDpi == 0) {
        preDpi = 96;
    }

    // 建窗用的 DPI（建窗后会用 GetDpiForWindow 纠正；两者不同就得挪窗口）
    const UINT preCreateDpi = preDpi;

    HINSTANCE instance = GetModuleHandleW(NULL);

    WNDCLASSEXW windowClass = {};
    windowClass.cbSize        = sizeof(windowClass);
    windowClass.style         = CS_HREDRAW | CS_VREDRAW;
    windowClass.lpfnWndProc   = driftWndProc;
    windowClass.hInstance     = instance;
    windowClass.hCursor       = LoadCursor(NULL, IDC_ARROW);
    windowClass.hbrBackground = NULL;   // 自绘：不要系统背景刷
    windowClass.lpszClassName = kWindowClass;

    // 图标：PE 里有品牌图标就用它，取不到回落系统默认 —— 漏配资源的应用不该连
    // 图标都没了。ID 取法的坑见 loadModuleIcon 的注释。
    HICON appIcon = loadModuleIcon(
        instance, GetSystemMetrics(SM_CXICON), GetSystemMetrics(SM_CYICON));
    HICON appIconSm = loadModuleIcon(
        instance, GetSystemMetrics(SM_CXSMICON), GetSystemMetrics(SM_CYSMICON));
    windowClass.hIcon   = appIcon   != NULL ? appIcon   : LoadIcon(NULL, IDI_APPLICATION);
    windowClass.hIconSm = appIconSm != NULL ? appIconSm : LoadIcon(NULL, IDI_APPLICATION);
    RegisterClassExW(&windowClass);

    // 请求的是**客户区**尺寸（逻辑像素）⇒ 先按 DPI 放大，再让系统把外框算进去。
    // 不做这一步，用户拿到的是"窗口 640 宽、能画的只有 624"。
    double scale = (double)preDpi / 96.0;
    RECT frame = {0, 0, (LONG)((double)(int)width * scale), (LONG)((double)(int)height * scale)};
    adjustFrameForDpi(&frame, WS_OVERLAPPEDWINDOW | WS_CLIPCHILDREN, FALSE, 0, preDpi);

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

    // ★ 槽的**重置**在 `WM_NCCREATE`（那里才知道 hwnd，且早于 WM_SIZE 等消息）；
    //   这里只补建窗前的 DPI —— `WM_NCCREATE` 跑在 `CreateWindowExW` 内部，
    //   读不到上面算出来的 `preDpi`。
    WindowSlot &win = slotOf(hwnd);
    win.dpi = preDpi;
    // ★ 活窗口表：`WM_DESTROY` 里移除，空了才 `PostQuitMessage`
    //   （"关最后一个才退"的物质基础，见 WM_DESTROY）。
    g_liveWindows.insert(hwnd);

    // ★ A5-② 拖文件进窗：让本窗接受资源管理器的文件拖放。用老式 WM_DROPFILES
    //   协议（而非 OLE IDropTarget/RegisterDragDrop）—— 今天只需要"一批路径 +
    //   落点"，那套 COM 悬停反馈没有消费者，不预先发明（§27）。
    DragAcceptFiles(hwnd, TRUE);

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
    win.dpi = actualDpi;
    win.clientW = (int)((double)(int)width * actualScale);
    win.clientH = (int)((double)(int)height * actualScale);

    if (actualDpi != preCreateDpi) {
        RECT fixed = {0, 0, (LONG)((double)(int)width * actualScale), (LONG)((double)(int)height * actualScale)};
        adjustFrameForDpi(&fixed, WS_OVERLAPPEDWINDOW | WS_CLIPCHILDREN, FALSE, 0, actualDpi);
        SetWindowPos(hwnd, NULL, 0, 0, fixed.right - fixed.left, fixed.bottom - fixed.top,
                     SWP_NOMOVE | SWP_NOZORDER | SWP_NOACTIVATE);
    }

    // ★ 窗口圆角（P1 / §质感）：Win11 DWM 圆角。旧系统没有这个属性 ⇒ 调用返回
    //   失败，**静默忽略**（窗口保持直角）。这是纯外观增强，失败不该阻断建窗，
    //   所以**不检查返回值**。
    //   ★ 圆角由 DWM 画在**外框**上（`probe-grab` 抓客户区看不到）⇒ 验证要走
    //   整窗屏幕矩形（PowerShell `CopyFromScreen`）。
    int corner = DWMWCP_ROUND;
    DwmSetWindowAttribute(hwnd, DWMWA_WINDOW_CORNER_PREFERENCE, &corner, sizeof(corner));

    // UIA 无障碍（§9 / M5，P2）：建窗即挂 provider（数据由 php_drift_win_uia_push 喂）。
    win.uiaProvider = new CProvider(hwnd, -1);   // 根 provider（m_index=-1 = 窗口根）

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
        // ★ 发 `WM_CLOSE` 而不是 `DestroyWindow`（2026-10-30）：**一条关闭路径**。
        //   改动之前这里有两条 —— 用户点 X 走 `WM_CLOSE`、应用调 `close()` 走
        //   `DestroyWindow`，于是"能不能拦"取决于用户用哪只手关窗（后者永远拦不住）。
        //   合成一条之后，拦截点（`g_closeGuarded`）对两个入口天然同时生效。
        PostMessageW(target, WM_CLOSE, 0, 0);
    }
}

/**
 * **执行**关闭：`DestroyWindow`，不看守卫。
 *
 * ★ 只有一处该调它：框架在应用回答"可以关"之后（`EventLoop` 收到
 *   `CloseRequestedEvent` 且 `Application::onCloseRequested()` 为真）。
 *   应用侧**不该**有入口走到这里 —— 否则守卫又变成"看调用方走哪条路"。
 */
void php_drift_win_destroy(Int hwnd) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target != NULL) {
        DestroyWindow(target);
    }
}

/**
 * 这个句柄还是不是一个活窗口？
 *
 * ★ 谁需要它（2026-10-30，M1-b 真机探针发现的）：**没有声明关闭守卫的窗口**
 *   是壳在 `WM_CLOSE` 里直接销毁的，PHP 侧不知道；而 `PostQuitMessage` 只是往队列
 *   里塞一条 `WM_QUIT` —— 队列里排在它前面的消息（`WM_KILLFOCUS` / `WM_ACTIVATE`）
 *   仍会先被取到。于是"关窗之后、quit 之前"那一轮会对着死句柄跑一帧 ⇒
 *   客户区 0×0 紧约束 ⇒ 布局硬校验 Fatal（真机现象：点 X 之后闪一下错误框，
 *   进程停在错误框上不退）。
 *   ★ 不能拿"客户区 0×0"当判据：**最小化也是 0×0**（那是合法状态，`Win32Screen`
 *     的 `viewport()` 注释写着）。所以必须是显式的存活查询。
 */
Bool php_drift_win_is_alive(Int hwnd) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        return false;
    }

    return IsWindow(target) ? true : false;
}

/**
 * 进程里**任意一个还活着的**窗口句柄（没有 ⇒ 0）。
 *
 * ★ 谁需要它：`Win32TimerHost` —— `SetTimer` 必须挂在一个活窗口上，而"等消息的
 *   闹钟"是**进程级**资源（一次 `GetMessageW` 服务所有窗口）。改动前它挂在
 *   "最后建的窗"上：主窗关掉、第二窗还在时就成了死句柄 ⇒ `start()` 失败
 *   ⇒ `waitEvents()` 退化成无限阻塞 ⇒ 剩下的那个窗口**动画停摆**（不报错）。
 * ★ 返回值只用来**起定时器**，不要拿它当"主窗"做别的事 —— 集合顺序是任意的。
 */
Int php_drift_win_live_window() {
    if (g_liveWindows.empty()) {
        return (Int)0;
    }

    return (Int)(INT_PTR)(*g_liveWindows.begin());
}

/**
 * 声明这个窗口的关闭要不要**先问应用**（2026-10-30）。
 *
 * ★ `armed = false`（默认）⇒ `WM_CLOSE` 直接销毁，与 2026-10-30 之前逐字节相同。
 *   所以"没有未保存内容的应用"零改动、零代价。
 * ★ 与 `apply_material` 同一条纪律：这是**窗口级状态**，按 HWND 记。
 */
void php_drift_win_set_close_guard(Int hwnd, Bool armed) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        return;
    }
    if (armed) {
        g_closeGuarded.insert(target);
    } else {
        g_closeGuarded.erase(target);
    }
}

/**
 * 窗口背后材质的 accent 策略本体（P1-Stage 3 落地，从老的 `set_backdrop` 原样搬入；
 * 真机实测与"为什么是未公开 API"见 stub 的 `drift_win_apply_material` docblock）。
 * `kind`：`0` 关 / `1` 轻模糊 / `2` 磨砂（= `Backdrop::value`）。
 */
static void applyBackdrop(HWND target, int kind) {
    int state;
    int tint;
    switch (kind) {
        case 0:  state = DRIFT_ACCENT_DISABLED;             tint = 0;          break;
        case 1:  state = DRIFT_ACCENT_ENABLE_BLURBEHIND;    tint = 0;          break;
        case 2:  state = DRIFT_ACCENT_ENABLE_ACRYLICBLURBEHIND; tint = (int)0x99000000u; break;
        default:
            // 未知编号：ABI 由 `Backdrop::value` 定义，不该出现。静默忽略而不是
            // 报错 —— 壳不承担校验职责，越界值只影响外观。
            return;
    }

    static DriftSetWindowCompositionAttribute setAttr = NULL;
    static bool looked = false;
    if (!looked) {
        looked = true;
        HMODULE user32 = GetModuleHandleW(L"user32.dll");
        if (user32 != NULL) {
            setAttr = (DriftSetWindowCompositionAttribute)(void *)
                GetProcAddress(user32, "SetWindowCompositionAttribute");
        }
    }
    if (setAttr == NULL) {
        return;
    }

    DriftAccentPolicy policy;
    policy.accentState = state;
    policy.accentFlags = 2;      // 文档未定义；社区实测 2 = 四边都画
    policy.gradientColor = tint; // 已是 0xAABBGGRR 口径，不再换算
    policy.animationId = 0;

    DriftWindowCompositionAttribData data;
    data.attrib = 19;            // WCA_ACCENT_POLICY
    data.data = &policy;
    data.size = sizeof(policy);

    setAttr(target, &data);
}

/**
 * 一次应用窗口外观材质（P1 收敛裁决，2026-10-26）。取代 `set_opacity` /
 * `set_pixel_alpha` / `set_backdrop` 三个入口（已删）—— 三个旋钮互斥/耦合，
 * 分开调就得靠"调用顺序对了才对"；一次拿到完整快照，调和是按值的确定性逻辑。
 * 参数语义与耦合规则见 stub 的 `drift_win_apply_material` docblock。
 */
void php_drift_win_apply_material(Int hwnd, Bool pixelAlpha, Float opacity, Int backdropKind) {
    WindowSlot &win = slotOf((HWND)(INT_PTR)hwnd);
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        return;
    }

    // 逐像素模式：只置位标志，**不动窗口样式**（老 set_pixel_alpha 的坑）——
    // 真正挂 WS_EX_LAYERED 推迟到第一次 present，避免"挂着 LAYERED 而谁都没调"
    // 的不可见空窗。清零同样不动样式：切回 GDI 只需 present 走 SetDIBitsToDevice。
    win.pixelAlpha = (pixelAlpha != 0);

    int a255 = (int)(opacity * 255.0f + 0.5f);
    if (a255 < 0) {
        a255 = 0;
    } else if (a255 > 255) {
        a255 = 255;
    }
    win.opacity = (BYTE)a255;

    applyBackdrop(target, (int)backdropKind);

    // 整窗 alpha 的懒挂：只在"要半透明且不是逐像素模式"时挂 LWA —— 其余窗口
    // 保持纯 GDI 上屏，不承担分层窗的合成怪癖。逐像素模式下不能调
    // SetLayeredWindowAttributes（会把绑定态切回 LWA，ULW 的位图当场失效），
    // 整窗 alpha 由 present 的 BLENDFUNCTION.SourceConstantAlpha 带上，语义一致。
    if (!win.pixelAlpha && a255 < 255) {
        bindLayered(target, kBindLwa);
    }
}

/** RtlGetVersion：不被兼容清单骗的 OS 版本读取（material_caps 用） */
typedef struct DriftOsVersionInfoW {
    ULONG dwOSVersionInfoSize;
    ULONG dwMajorVersion;
    ULONG dwMinorVersion;
    ULONG dwBuildNumber;
    ULONG dwPlatformId;
    WCHAR szCSDVersion[128];
} DriftOsVersionInfoW;
typedef LONG (WINAPI *DriftRtlGetVersionFn)(DriftOsVersionInfoW *);

/**
 * 当前平台对窗口材质各旋钮的实际支持（位掩码；位值 = ABI，见 stub docblock）。
 *
 * ★ 结果缓存：OS 版本 / API 入口在进程生命周期内不变，探一次就够。
 * ★ 圆角按 OS 版本判（build 22000+），**不试 API**：`DwmSetWindowAttribute` 在
 *   Win10 上返回 S_OK 但静默不裁角（2026-10-26 真机证伪过"分层窗照样裁角"）
 *   —— 返回值骗人，版本号不会。
 */
Int php_drift_win_material_caps() {
    static int cached = -1;
    if (cached >= 0) {
        return (Int)cached;
    }

    int caps = 0x01 | 0x02;   // LWA（Win2000+）/ ULW（XP+）：实际恒可用

    HMODULE user32 = GetModuleHandleW(L"user32.dll");
    if (user32 != NULL && GetProcAddress(user32, "SetWindowCompositionAttribute") != NULL) {
        caps |= 0x04 | 0x08;   // 轻模糊 / 磨砂：accent 策略入口探得到
    }

    HMODULE ntdll = GetModuleHandleW(L"ntdll.dll");
    if (ntdll != NULL) {
        DriftRtlGetVersionFn rtl =
            (DriftRtlGetVersionFn)(void *)GetProcAddress(ntdll, "RtlGetVersion");
        if (rtl != NULL) {
            DriftOsVersionInfoW info = {};
            info.dwOSVersionInfoSize = sizeof(info);
            if (rtl(&info) == 0 && (int)info.dwBuildNumber >= 22000) {
                caps |= 0x10;   // 系统圆角（Win11+）
            }
        }
    }

    cached = caps;
    return (Int)caps;
}

/**
 * 无边框模式（P1-Stage 1b / §质感）。`dragHeight` = 顶部拖动带高度（**物理**像素）。
 *
 * ★ opt-in：调用即置位 `win.frameless`。走 `WM_NCCALCSIZE` 让客户区=整窗（见
 *   WndProc），**样式不变** ⇒ 原生贴边吸附 / 快照布局 / 最大化动画不丢。
 * ★ 置位后必须 `SWP_FRAMECHANGED` 让系统重算非客户区（否则要等下一次尺寸变化）。
 */
void php_drift_win_set_frameless(Int hwnd, Int dragHeight) {
    WindowSlot &win = slotOf((HWND)(INT_PTR)hwnd);
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        return;
    }
    win.frameless = true;
    int dh = (int)dragHeight;
    win.dragHeight = dh < 0 ? 0 : dh;

    SetWindowPos(target, NULL, 0, 0, 0, 0,
                 SWP_NOMOVE | SWP_NOSIZE | SWP_NOZORDER | SWP_NOACTIVATE | SWP_FRAMECHANGED);

    int corner = DWMWCP_ROUND;
    DwmSetWindowAttribute(target, DWMWA_WINDOW_CORNER_PREFERENCE, &corner, sizeof(corner));
}

/**
 * 由**应用内容**发起窗口拖动（无边框窗没有原生标题栏可抓）。
 *
 * ★ 消费者是 `Drift\Widget\Widget::windowDrag()`：框架在**命中的是声明者自己**
 *   （不是它的子按钮/输入框）时调一次。走 `WM_NCHITTEST` 返 `HTCAPTION` 做不到
 *   这种精确度 —— 壳看不见控件树，顶条上的按钮会连点击一起被拖走。
 * ★ 实现是 Windows 惯例组合：`ReleaseCapture()` + `WM_NCLBUTTONDOWN/HTCAPTION`
 *   ⇒ 交给系统走**原生标题栏拖动**（贴边吸附随之生效）。
 * ★ **阻塞**到用户松手（模态移动循环）—— 与原生标题栏一致，调用方不必等。
 */
void php_drift_win_begin_drag(Int hwnd) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        return;
    }
    POINT pt;
    GetCursorPos(&pt);
    ReleaseCapture();
    SendMessageW(target, WM_NCLBUTTONDOWN, HTCAPTION, MAKELPARAM(pt.x, pt.y));
}

void php_drift_win_minimize(Int hwnd) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target != NULL) {
        ShowWindow(target, SW_MINIMIZE);
    }
}

void php_drift_win_maximize_toggle(Int hwnd) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target != NULL) {
        ShowWindow(target, IsZoomed(target) ? SW_RESTORE : SW_MAXIMIZE);
    }
}

void php_drift_win_set_cursor(Int hwnd, Int shape) {
    WindowSlot &win = slotOf((HWND)(INT_PTR)hwnd);
    // ★ `shape` 是 `Drift\Input\CursorShape` 的 value（`src/Input/CursorShape.php`）；
    //   名字→`IDC_*` 的映射只有 `cursorIdForShape()` 一处 —— 两边必须同步改。
    // ★ `LoadCursorW(NULL, …)` 的 IDC_* 是**系统资源**（MAKEINTRESOURCE），
    //   不需要 DestroyCursor，也不随窗口销毁而失效 ⇒ 缓存裸 HCURSOR 是安全的。
    win.cursor = LoadCursorW(NULL, cursorIdForShape((int)shape));

    HWND target = (HWND)(INT_PTR)hwnd;
    if (target != NULL) {
        // 立刻生效（不等下一次 WM_SETCURSOR）—— 否则"移进去要动一下才变"
        SetCursor(win.cursor);
    }
}

void php_drift_win_set_title(Int hwnd, String title) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        return;
    }
    SetWindowTextW(target, toWide(title).c_str());
}

void php_drift_win_set_min_size(Int hwnd, Int width, Int height) {
    WindowSlot &win = slotOf((HWND)(INT_PTR)hwnd);
    // §5.4：客户区最小尺寸（**物理**像素）。WM_GETMINMAXINFO 里换算成外框。
    // 只是"下一次拖动时的限制" —— 当前窗口若已经更小，不强制放大（那会让
    // 用户在窄屏幕上开不出窗口）。
    win.minClientW = width > 0 ? (int)width : 0;
    win.minClientH = height > 0 ? (int)height : 0;
    (void)hwnd;   // 单窗口进程：状态在进程级（同 win.clientW）
}

void php_drift_win_ime_caret(Int hwnd, Float x, Float y, Float h) {
    // §8.4：IME 定位管线。x/y/h 是**设备像素**的客户区坐标；(-1,-1,0) = 无插入符。
    // 两个消费点：组字窗位置（WM_IME_STARTCOMPOSITION）与 IMR_QUERYCHARPOSITION。
    //
    // ★ **必须带 hwnd**（2026-10-30，M1-a）：Windows 的插入符是**线程级**的，
    //   `SetCaretPos` 作用于"当前线程的插入符" —— 而"该贴哪个窗口"只有调用方知道
    //   （→ 每帧报位置的是那个窗口的 `Application`）。用"最后建的窗"在多窗口下
    //   就是"窗口 A 打字的候选框贴在 B 上"。
    HWND target = (HWND)(INT_PTR)hwnd;
    WindowSlot &win = slotOf(target);
    win.imeX = x;
    win.imeY = y;
    win.imeH = h;

    if (target == NULL) {
        return;
    }
    double imeScale = (double)win.dpi / 96.0;
    if (x < 0.0f) {
        // 无插入符：收起 caret 并挪到客户区外（IME 查到的位置退左上角）
        if (win.caretVisible) {
            HideCaret(target);
            DestroyCaret();
            win.caretVisible = false;
        }
        SetCaretPos(-10, -10);
        return;
    }
    LONG cx = (LONG)((double)x / imeScale);
    LONG cy = (LONG)((double)y / imeScale);
    // ★★ 无条件 SetCaretPos（2026-10-19 回归教训）：微软拼音 / 搜狗等输入法经
    //    `GetCaretPos` 读位置，**不依赖 caret 是否可见**。曾把这一行关进
    //    "仅 caret 可见"，组字前与组字中的查询全部读到 (-10,-10) 哨兵 ⇒
    //    候选框贴屏幕左上角（两个输入法同时复现，均上周正常）。caret 的
    //    建/销独立管理（WM_IME_STARTCOMPOSITION / ENDCOMPOSITION），与这里
    //    "每帧报位置"是两件事，别再合并。
    SetCaretPos(cx, cy);
    // ★ 没有"主动通知 IME 位置变了"这个 API —— 位置在下次
    //   WM_IME_STARTCOMPOSITION（组字开始）与 IMR_QUERYCHARPOSITION
    //   （IME 主动问）时被读取，两个都是拉模型。推只会踩
    //   ImmSetCompositionWindow 的空指针坑（imc 为空时不调）。
}

Array php_drift_win_client_size(Int hwnd) {
    HWND target = (HWND)(INT_PTR)hwnd;
    Array out;
    // 窗口已死（退出路径）：给最后已知尺寸（§5.4，见 g_lastGeom* 注释）
    RECT rect;
    if (target == NULL || !GetClientRect(target, &rect)) {
        fillGeomFromCache(target, out);
        return out;
    }

    // ★★ **最小化 ⇒ 报 0×0**（2026-10-26，无边框窗撞出来的真机 bug）。
    //   最小化后窗口是经典的 160×28 小方块。**有标题栏时**客户区高度被标题栏
    //   扣成 0（≈144×0），`Application::frame()` 的零视口保护正好兜住；
    //   **无边框窗**客户区 = 整窗 = 160×28 ⇒ 保护不触发 ⇒ 框架按 160×28 布局，
    //   顶条只能给标题文字 136×16（自然高 21.6）⇒ C6 Fatal。
    //   真机现象：点自绘的最小化按钮 ⇒ 窗口确实最小化了，随后弹
    //   "Drift FATAL / RenderText 画出的尺寸超出父容器给的可用空间：72.0×21.6，
    //   最多只能用 136.0×16.0"。
    //   ⇒ 这里**诚实地**报 0：最小化的窗口没有"能画的客户区"。这与
    //     `position()` 在最小化时返 null 是同一条语义（都是"此刻不可信"）。
    if (IsIconic(target) != FALSE) {
        out.set("w", (Int)0);
        out.set("h", (Int)0);
        return out;
    }

    out.set("w", (Int)(rect.right - rect.left));
    out.set("h", (Int)(rect.bottom - rect.top));
    cacheFinalGeometry(target);
    return out;
}

Bool php_drift_win_is_maximized(Int hwnd) {
    WindowSlot &win = slotOf((HWND)(INT_PTR)hwnd);
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        // 窗口已死（退出路径）：退化到最后已知状态（§5.4）
        return (Bool)(win.lastGeomValid && win.lastMaximized);
    }
    bool zoomed = IsZoomed(target) != FALSE;
    win.lastMaximized = zoomed;   // 活窗口：顺手刷新，缓存有效性仍归 cacheFinalGeometry 管
    return (Bool)zoomed;
}

Array php_drift_win_window_position(Int hwnd) {
    WindowSlot &win = slotOf((HWND)(INT_PTR)hwnd);
    // §5.4 持久化的读向侧。**物理**像素；空数组 = "别记"（最小化，或从未记过）。
    // 为什么不记最小化时的坐标：Windows 在最小化期间把窗口摆在屏幕外
    // （惯例是 (-32000,-32000)），照抄回来下次启动窗口就"消失"了。
    // ★ 窗口已销毁（退出路径）⇒ 回填 WM_DESTROY 抓的最后已知位置，不是空数组 ——
    //   否则 persist() 第一关就跳过，"记住窗口位置"永远写不出去（真机实测过）。
    HWND target = (HWND)(INT_PTR)hwnd;
    Array out;
    if (target != NULL && IsIconic(target) != FALSE) {
        win.lastGeomValid = false;
        return out;
    }

    RECT client = {0, 0, 0, 0};
    if (target == NULL || !GetClientRect(target, &client)) {
        fillGeomFromCache(target, out);
        return out;
    }
    POINT origin = {client.left, client.top};
    if (!ClientToScreen(target, &origin)) {
        fillGeomFromCache(target, out);
        return out;
    }

    cacheFinalGeometry(target);   // 活窗口：顺手刷新
    out.set("x", (Float)(double)origin.x);
    out.set("y", (Float)(double)origin.y);
    return out;
}

/**
 * 把窗口矩形夹回"看得见"的范围（§5.4 的显示器夹取）。
 *
 * 屏幕几何是平台知识，所以它住在壳里而不是 PHP 侧（`Drift\App` 不该有
 * "哪块显示器在工作区左边"这种概念）。两条规则：
 *   ① 与最近显示器的工作区**完全不相交** ⇒ 平移到工作区原点；
 *   ② **部分越界** ⇒ 整体平移回来，**不缩尺寸**（用户存的大小就是大小）。
 */
static void clampRectToNearestMonitor(RECT &rect) {
    HMONITOR monitor = MonitorFromRect(&rect, MONITOR_DEFAULTTONEAREST);
    if (monitor == NULL) {
        return;   // 拿不到显示器信息 ⇒ 原样返回，交给 SetWindowPos
    }
    MONITORINFO info = {};
    info.cbSize = sizeof(MONITORINFO);
    if (!GetMonitorInfoW(monitor, &info)) {
        return;
    }
    const RECT &work = info.rcWork;

    const bool disjoint = rect.right <= work.left || rect.left >= work.right
        || rect.bottom <= work.top || rect.top >= work.bottom;
    if (disjoint) {
        long w = rect.right - rect.left;
        long h = rect.bottom - rect.top;
        rect.left = work.left;
        rect.top = work.top;
        rect.right = rect.left + w;
        rect.bottom = rect.top + h;
        return;
    }

    if (rect.left < work.left) {
        long d = work.left - rect.left;
        rect.left += d;
        rect.right += d;
    }
    if (rect.top < work.top) {
        long d = work.top - rect.top;
        rect.top += d;
        rect.bottom += d;
    }
    if (rect.right > work.right) {
        long d = rect.right - work.right;
        rect.left -= d;
        rect.right -= d;
    }
    if (rect.bottom > work.bottom) {
        long d = rect.bottom - work.bottom;
        rect.top -= d;
        rect.bottom -= d;
    }
}

void php_drift_win_place_window(Int hwnd, Float x, Float y, Float w, Float h, Bool maximized) {
    WindowSlot &win = slotOf((HWND)(INT_PTR)hwnd);
    // §5.4 一次性还原：逻辑客户区几何 ⇒ 物理外框 ⇒ 夹取 ⇒ SetWindowPlacement。
    // 为什么用 `WINDOWPLACEMENT` 而不是 `SetWindowPos`：最大化与"还原后回到哪"
    // 在 Windows 里是同一份状态，拆成两个 API 就会丢掉"从最大化还原回原位"。
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        return;
    }
    // 非正尺寸含 NaN 比较（`!(nan > 0)` 为真）⇒ 一律当垃圾输入，不动窗口
    if (!(w > 0.0) || !(h > 0.0)) {
        return;
    }

    UINT dpi = dpiOfWindow(target);
    double dpr = dpi > 0 ? (double)dpi / 96.0 : 1.0;

    // ★ 最小尺寸 clamp（§5.4）：还原的历史几何可能是"窗口太小"的脏数据 ——
    //   那种状态会踩布局硬校验（C5/C6）弹 Fatal。拖动由 WM_GETMINMAXINFO 挡，
    //   程序设尺寸（SetWindowPlacement/SetWindowPos 不受 ptMinTrackSize 限制）
    //   在这里挡。min 是**物理客户区**，w/h 是逻辑 ⇒ 先换算再比。
    if (win.minClientW > 0 && win.minClientH > 0) {
        if ((double)w * dpr < (double)win.minClientW) {
            w = (Float)((double)win.minClientW / dpr);
        }
        if ((double)h * dpr < (double)win.minClientH) {
            h = (Float)((double)win.minClientH / dpr);
        }
    }

    // ① 客户区（逻辑）→ 物理，再把外框厚度加回去（DPI 感知）
    RECT frame = {0, 0, (LONG)((double)w * dpr), (LONG)((double)h * dpr)};
    adjustFrameForDpi(&frame, WS_OVERLAPPEDWINDOW, FALSE, 0, dpi);

    // ② 平移：外框矩形的 -left/-top 偏移就是"标题栏比客户区高多少"
    RECT want = {
        (LONG)((double)x * dpr) + frame.left,
        (LONG)((double)y * dpr) + frame.top,
        (LONG)((double)x * dpr) + frame.right,
        (LONG)((double)y * dpr) + frame.bottom,
    };

    // ③ 夹到看得见的范围（拔显示器 / 分辨率变小都不会让窗口消失）
    clampRectToNearestMonitor(want);

    WINDOWPLACEMENT placement = {};
    placement.length = sizeof(WINDOWPLACEMENT);
    placement.rcNormalPosition = want;
    placement.showCmd = (maximized != FALSE) ? SW_SHOWMAXIMIZED : SW_SHOWNORMAL;
    placement.flags = 0;   // 同步应用：调用方紧接下来就要读回 clientSize()
    SetWindowPlacement(target, &placement);
}

String php_drift_win_config_dir() {
    // §5.4 用户配置目录：`%APPDATA%\Drift`。只给路径，**不创建目录**。
    // 空返回 = 平台答不出来 ⇒ PHP 侧静默跳过持久化（`WindowStateStore`）。
    PWSTR raw = NULL;
    if (FAILED(SHGetKnownFolderPath(FOLDERID_RoamingAppData, KF_FLAG_DEFAULT, NULL, &raw))) {
        return String();
    }

    std::wstring dir = std::wstring(raw) + L"\\Drift";
    CoTaskMemFree(raw);
    return toUtf8(dir.c_str(), (int)dir.size());
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

/** 当前线程 id —— 供 PHP 侧做 UI 线程身份/断言（§17.4.2.1）。 */
Int php_drift_thread_id() {
    return (Int)GetCurrentThreadId();
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
    WindowSlot &win = slotOf((HWND)(INT_PTR)hwnd);
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

    if (win.frameW != w || win.frameH != h) {
        win.frame.assign(need, 0);
        win.frameW = w;
        win.frameH = h;
    }

    const unsigned char *src = (const unsigned char *)rgba.data();
    if (layeredPixelReady(target)) {
        // ★★ 预乘 BGRA，**只换 R↔B**：`RasterCanvas` 的像素缓冲本来就是**预乘**的
        //   （混合公式 `dst = src·a + dst·(1−a)`，见 `toRgba()` 的长注释）⇒
        //   这里再乘一次 alpha 会把半透明区二次压暗。alpha 原样带过去。
        for (size_t i = 0; i < need; i += 4) {
            win.frame[i + 0] = src[i + 2];
            win.frame[i + 1] = src[i + 1];
            win.frame[i + 2] = src[i + 0];
            win.frame[i + 3] = src[i + 3];
        }

        if (presentLayered(target)) {
            return;
        }
        // 回落：ULW 没贴上（尺寸不匹配 / API 失败）⇒ 下面的 GDI 路径接手。
        // ★ 必须补满 alpha：GDI 的 32bpp `BI_RGB` 忽略 alpha，但留着半透明值会让
        //   远程桌面 / 截图路径按 alpha 混合，出现"半透明窗口"。
    }

    for (size_t i = 0; i < need; i += 4) {
        // RGBA（画布）⇒ BGRA（32bpp DIB）。alpha 置满（理由见上）。
        win.frame[i + 0] = src[i + 2];
        win.frame[i + 1] = src[i + 1];
        win.frame[i + 2] = src[i + 0];
        win.frame[i + 3] = 0xFF;
    }

    HDC dc = GetDC(target);
    if (dc != NULL) {
        presentTo(target, dc);
        ReleaseDC(target, dc);
    }
}

// ═══════════════════════════════════════════════ Skia 直贴（§7.3 提速）══
// ★★ 旧路径每帧把同一批像素做两次**方向相反**的逐像素交换：
//     `drift_skia_read_pixels()`   BGRA → RGBA（为了喂 `php_drift_win_present`）
//     `php_drift_win_present()`    RGBA → BGRA（为了喂 32bpp DIB）
//   中间还要过一次 PHP 字符串形态的 4MB 跨界。实测这两段合计占端到端 **50%+**
//   （`docs/design-v7/26-bench-结果.md` §5：toRgba 30% + present 20.5%）。
//
//   ★ **交换本来就是多余的**：Skia 的 N32 在小端上就是 **BGRA**，而 32bpp
//     `BI_RGB` DIB 要的也是 BGRA ⇒ 按行 `memcpy` 就够，两次 swizzle 全省。
//
// ★ 像素指针由 `drift_skia.cc` 零拷取出。它**不是** PHP ABI 函数（不进 stub，
//   PHP 侧拿不到）⇒ 用 `extern "C"` 固定符号名，不为它开公共面。
extern "C" bool drift_skia_surface_pixels(
    int canvas,
    const unsigned char **data,
    int *w,
    int *h,
    size_t *rowBytes);

void php_drift_win_present_skia(Int hwnd, Int canvas) {
    WindowSlot &win = slotOf((HWND)(INT_PTR)hwnd);
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        return;
    }

    const unsigned char *src = NULL;
    int w = 0;
    int h = 0;
    size_t rowBytes = 0;
    if (!drift_skia_surface_pixels((int)canvas, &src, &w, &h, &rowBytes)) {
        return;   // 画布无效/已销毁 ⇒ 这一帧没贴。与 `php_drift_win_present` 的丢弃同档
    }

    const size_t need = (size_t)w * (size_t)h * 4u;
    if (win.frameW != w || win.frameH != h) {
        win.frame.assign(need, 0);
        win.frameW = w;
        win.frameH = h;
    }

    // ★★ 零 swizzle：BGRA → BGRA。只拷紧凑宽度，忽略 Skia 的行尾填充。
    const size_t rowPixels = (size_t)w * 4u;
    for (int y = 0; y < h; y++) {
        memcpy(win.frame.data() + (size_t)y * rowPixels, src + (size_t)y * rowBytes, rowPixels);
    }

    if (layeredPixelReady(target)) {
        // Skia 的 N32 就是**预乘** BGRA（`N32Premul`）⇒ 上面那次 memcpy 已经把
        // ULW 要的字节原样搬过来了，**不需要任何换算**。
        if (presentLayered(target)) {
            return;
        }
        // 回落 GDI：alpha 必须补满（见 `php_drift_win_present` 的同款注释）
        for (size_t i = 3; i < need; i += 4) {
            win.frame[i] = 0xFF;
        }
    }

    HDC dc = GetDC(target);
    if (dc != NULL) {
        presentTo(target, dc);
        ReleaseDC(target, dc);
    }
}

// ═══════════════════════════════════════ UIA 无障碍（§9 / M5，P2 数据流）══
// PHP 侧 SemanticsBuilder::build(root)->toArrays()['nodes'] 推进来；provider 从
// 这份扁平数组应答 UIA 客户端。空数组 = 无障碍关闭（清空 C 侧树）。
//
// ★ **带 hwnd**（2026-10-30，M1-a）：语义树是**每窗一棵** —— 挂的是各窗的
//   UIA provider、坐标是各窗的客户区。推错的树 = 读屏器把 A 窗的控件当 B 窗的。
void php_drift_win_uia_push(Int hwnd, Array nodes) {
    WindowSlot &win = slotOf((HWND)(INT_PTR)hwnd);
    win.uiaNodes.clear();
    size_t n = nodes.count();
    for (size_t i = 0; i < n; i++) {
        Variant v = nodes.get((zend_ulong)i);
        if (!v.isArray()) {
            continue;
        }
        Array a = v;
        UiaNode node;
        std::string name(a.get("name").toCString());
        std::string role(a.get("role").toCString());
        std::string value(a.get("value").toCString());
        node.name = toWide(String(name));
        node.controlType = uiaControlTypeFor(role);
        // 值型 Pattern 的数据源（§4.4）。缺键 ⇒ 空串 / 0（老调用方不推也安全）。
        node.value = toWide(String(value));
        node.rangeMin = a.get("rangeMin").toFloat();
        node.rangeMax = a.get("rangeMax").toFloat();
        node.enabled = a.get("enabled").toBool();
        node.parent = (int)a.get("parent").toInt();
        node.x = a.get("x").toFloat();
        node.y = a.get("y").toFloat();
        node.w = a.get("w").toFloat();
        node.h = a.get("h").toFloat();
        node.isControl = true;
        node.focused = a.get("focused").toBool();
        win.uiaNodes.push_back(node);
    }

    // 结构变化事件（§9 / M5，P4）：节点数变了 ⇒ 通知 UIA 客户端重新查询树
    // （Loading→Ready、列表增删）。count 触发（同 count 的内容变不 raise，够用）。
    int cnt = (int)win.uiaNodes.size();
    if (cnt != win.lastNodeCount) {
        win.lastNodeCount = cnt;
        if (win.uiaProvider != nullptr) {
            IRawElementProviderSimple *simple = nullptr;
            if (SUCCEEDED(win.uiaProvider->QueryInterface(__uuidof(IRawElementProviderSimple), (void **)&simple)) && simple != nullptr) {
                UiaRaiseStructureChangedEvent(simple, StructureChangeType_ChildrenInvalidated, nullptr, 0);
                simple->Release();
            }
        }
    }

    // 焦点变化事件（§9 / M5，B）：焦点节点索引变了 ⇒ raise。provider 取焦点节点本身
    // （客户端回调里一般会 GetFocusedElement()，这里只负责通知"焦点动过了"）。
    int focusIdx = -1;
    for (size_t k = 0; k < win.uiaNodes.size(); k++) {
        if (win.uiaNodes[k].focused) { focusIdx = (int)k; break; }
    }
    if (focusIdx != win.lastFocusIdx) {
        win.lastFocusIdx = focusIdx;
        if (focusIdx >= 0 && win.uiaProvider != nullptr) {
            IRawElementProviderSimple *fsimple = win.uiaProvider->makeFragmentSimple(focusIdx);
            if (fsimple != nullptr) {
                UiaRaiseAutomationEvent(fsimple, UIA_AutomationFocusChangedEventId);
                fsimple->Release();
            }
        }
    }
}
// ═══════════════════════════════ 剪贴板（§5.1 壳服务 / M3 文本编辑三件套）══
// 只做纯文本（CF_UNICODETEXT）。壳**不知道**"复制/粘贴"的语义 —— 它只回答三个
// 问题：有没有文本、读出来是什么、写进去。选择态与 Ctrl+C/V/X 的翻译住 PHP 侧
// （§9.1 路由链第 ③ 环，`TextFieldState` + `Application`）。
//
// ★ 位置纪律：本段必须在 `php_drift_win_uia_push` 的收尾 `}` **之后**（全局
//   作用域）。插进任何函数体内部 ⇒ C2601「本地函数定义是非法的」，而 PHP 侧
//   smoke 用 `function_exists` 假实现，**一点都看不出来**（2026-10-21 实测）。

/**
 * 打开剪贴板（带重试）。
 *
 * ★ 为什么必须重试：`OpenClipboard` 在**别的进程**正握着剪贴板时返回 FALSE
 *   （ERROR_ACCESS_DENIED）—— 这是 Windows 的既定行为、不是错误路径。不重试的
 *   后果是"用户刚在别的程序里复制过，这边的 Ctrl+V 就随机失效"，且**不报错**
 *   （G2 反模式：把平台时序噪声当永久失败）。
 */
static bool openClipboardWithRetry(int attempts) {
    for (int i = 0; i < attempts; i++) {
        if (OpenClipboard(NULL)) {
            return true;
        }
        Sleep(2);
    }
    return false;
}

/** 读纯文本；空 / 非文本 / 打不开 ⇒ 空串（端口契约：空串不是错误） */
String php_drift_win_clipboard_read_text() {
    if (!openClipboardWithRetry(8)) {
        return String("");
    }

    std::string out;
    if (IsClipboardFormatAvailable(CF_UNICODETEXT)) {
        HANDLE handle = GetClipboardData(CF_UNICODETEXT);
        if (handle != NULL) {
            const wchar_t *text = (const wchar_t *)GlobalLock(handle);
            if (text != NULL) {
                // CF_UNICODETEXT 保证 NUL 结尾 ⇒ 可以按 wcslen 取长
                out = toUtf8(text, (int)wcslen(text));
                GlobalUnlock(handle);
            }
        }
    }

    CloseClipboard();
    return String(out.c_str());
}

/**
 * 写纯文本（覆盖剪贴板现有内容）。
 *
 * ★ 失败是**静默**的：端口契约是 `writeText(): void`（调用方没有错误通道），
 *   而"写失败"在 Windows 上只剩"剪贴板被别的进程占着"一种，已由重试消掉。
 *   真要吃这个错，先把端口改成返回 `bool` —— 今天没有消费者要它（A15）。
 */
void php_drift_win_clipboard_write_text(String text) {
    if (!openClipboardWithRetry(8)) {
        return;
    }

    std::wstring wide = toWide(text);
    SIZE_T bytes = (SIZE_T)(wide.size() + 1) * sizeof(wchar_t);

    HANDLE handle = GlobalAlloc(GMEM_MOVEABLE, bytes);
    if (handle == NULL) {
        CloseClipboard();
        return;
    }

    memcpy(GlobalLock(handle), wide.c_str(), bytes);
    GlobalUnlock(handle);

    if (SetClipboardData(CF_UNICODETEXT, handle) == NULL) {
        // 系统没接手 ⇒ 块还是我们的，必须自己放（否则每失败一次漏一块全局内存）
        GlobalFree(handle);
    }

    CloseClipboard();
}

/** 剪贴板上有没有纯文本 —— 让"粘贴"能先判可用性再改状态 */
Bool php_drift_win_clipboard_has_text() {
    if (!openClipboardWithRetry(8)) {
        return Bool(false);
    }

    bool has = IsClipboardFormatAvailable(CF_UNICODETEXT) != FALSE;
    CloseClipboard();
    return Bool(has);
}

// ═══════════════════════════════ 原生对话框（§5.1 壳服务 / A5-①）══
// `Drift\Platform\Dialog` 的 Win32 实现。四件事：选已有文件 / 选保存位置 /
// 单键提示 / 是-否确认。
//
// ★★ **四个函数全是模态**（`GetOpenFileNameW` / `MessageBoxW`）：模态期间系统跑
//   自己的内层消息循环，PHP 侧**一帧都跑不到**。这是原生对话框的固有语义，
//   不是缺陷；但调用点不能在动画/滚动的回调里。
// ★ 位置纪律同剪贴板那段：必须在全局作用域（任何函数体的收尾 `}` 之后）。
//   插进函数体内部 ⇒ C2601，而 PHP 侧 smoke 一点都看不出来（2026-10-21 实测）。
// ★ 这里**没有中文字符串字面量**：本文件不声明 `/utf-8`，MSVC 按系统码页读源码，
//   中文字面量会变成乱码。所有给用户看的文案都从 PHP 传来（UTF-8 → `toWide`）。

/**
 * `显示名\t通配符`（行间 `\n`）→ `OPENFILENAMEW::lpstrFilter` 要的
 * **双 NUL 结尾**宽字符表：`名\0通配符\0名\0通配符\0\0`。
 *
 * ★ 为什么不在 PHP 侧直接拼双 NUL：NUL 穿过 ABI 之后在调试器里全是一样的点，
 *   出了错看不出来；而 `\t` / `\n` 是**可读、可单测**的中间形态
 *   （`Win32Dialog::filterText()` 有断言），一次转换留在这里。
 */
static std::vector<wchar_t> buildFilterWide(const String &filters) {
    std::vector<wchar_t> out;
    std::string raw(filters.data(), filters.length());

    size_t start = 0;
    while (start < raw.size()) {
        size_t end = raw.find('\n', start);
        if (end == std::string::npos) {
            end = raw.size();
        }

        std::string line = raw.substr(start, end - start);
        if (!line.empty() && line[line.size() - 1] == '\r') {
            line.erase(line.size() - 1);   // 容忍 CRLF：调用方用 heredoc 拼也不会坏
        }

        if (!line.empty()) {
            size_t tab = line.find('\t');
            std::string label = tab == std::string::npos ? line : line.substr(0, tab);
            std::string pattern = tab == std::string::npos ? std::string() : line.substr(tab + 1);

            std::wstring wideLabel = toWide(String(label));
            std::wstring widePattern = toWide(String(pattern));
            out.insert(out.end(), wideLabel.begin(), wideLabel.end());
            out.push_back(L'\0');
            out.insert(out.end(), widePattern.begin(), widePattern.end());
            out.push_back(L'\0');
        }

        if (end == raw.size()) {
            break;
        }
        start = end + 1;
    }

    if (out.empty()) {
        return out;   // 空表 ⇒ 调用方给 NULL（PHP 侧保证至少一行，这里是防御）
    }

    out.push_back(L'\0');   // 双 NUL 的第二个
    return out;
}

/** 打开 / 保存 的公共部分：取消与真失败在这里分开处理，成功返回 UTF-8 路径 */
static std::string runFileDialog(Int owner, const String &title, const String &filters,
                                 const String &defaultName, bool save) {
    std::vector<wchar_t> filterWide = buildFilterWide(filters);

    // ★ `nMaxFile` 是**字符数**不是字节数；32K 宽字符 ≈ 14K 个汉字路径，够。
    std::vector<wchar_t> fileBuf(32768, 0);
    std::wstring seed = toWide(defaultName);
    if (!seed.empty() && seed.size() < fileBuf.size()) {
        std::copy(seed.begin(), seed.end(), fileBuf.begin());
    }

    std::wstring titleWide = toWide(title);

    OPENFILENAMEW ofn;
    ZeroMemory(&ofn, sizeof(ofn));
    ofn.lStructSize = sizeof(ofn);
    ofn.hwndOwner = (HWND)(INT_PTR)owner;
    ofn.lpstrFilter = filterWide.empty() ? NULL : filterWide.data();
    ofn.lpstrFile = fileBuf.data();
    ofn.nMaxFile = (DWORD)fileBuf.size();
    ofn.lpstrTitle = titleWide.empty() ? NULL : titleWide.c_str();
    // ★ `OFN_PATHMUSTEXIST`：路径打错时对话框自己拦住，不把无效路径递回上层。
    // ★ `OFN_NOCHANGEDIR`：**不许改进程的工作目录** —— 应用侧有相对路径
    //   （`getcwd()` 那条纪律），读文件之后 cwd 变了会让后面每次读写都错位。
    ofn.Flags = OFN_EXPLORER | OFN_PATHMUSTEXIST | OFN_NOCHANGEDIR;
    ofn.Flags |= save ? OFN_OVERWRITEPROMPT : OFN_FILEMUSTEXIST;

    BOOL ok = save ? GetSaveFileNameW(&ofn) : GetOpenFileNameW(&ofn);
    if (!ok) {
        DWORD code = CommDlgExtendedError();
        if (code != 0) {
            // 端口（`Dialog::openFile(): ?string`）只有"取消 / 成功"两态 ⇒
            // 真失败**不能**装作用户点了取消：壳自己喊出来（§1.2 响亮失败）。
            wchar_t message[128];
            swprintf_s(message, L"file dialog failed: 0x%08lX", (unsigned long)code);
            MessageBoxW((HWND)(INT_PTR)owner, message, L"Drift",
                        MB_OK | MB_ICONERROR | MB_TASKMODAL | MB_SETFOREGROUND);
        }
        return std::string();
    }

    return toUtf8(fileBuf.data(), (int)wcslen(fileBuf.data()));
}

String php_drift_win_open_file(Int owner, String title, String filters, String defaultName) {
    std::string path = runFileDialog(owner, title, filters, defaultName, false);
    return String(path.c_str());
}

String php_drift_win_save_file(Int owner, String title, String filters, String defaultName) {
    std::string path = runFileDialog(owner, title, filters, defaultName, true);
    return String(path.c_str());
}

void php_drift_win_alert(Int owner, String title, String body) {
    std::wstring wideTitle = toWide(title);
    std::wstring wideBody = toWide(body);
    MessageBoxW((HWND)(INT_PTR)owner, wideBody.c_str(), wideTitle.c_str(),
                MB_OK | MB_ICONINFORMATION | MB_TASKMODAL | MB_SETFOREGROUND);
}

Bool php_drift_win_confirm(Int owner, String title, String body) {
    std::wstring wideTitle = toWide(title);
    std::wstring wideBody = toWide(body);
    // ★ `MB_DEFBUTTON2`：默认焦点在"否"。破坏性动作的默认不能是"执行"
    //   （`Dialog::confirm()` 的契约：关掉窗口 ⇒ false）。
    int result = MessageBoxW((HWND)(INT_PTR)owner, wideBody.c_str(), wideTitle.c_str(),
                             MB_YESNO | MB_ICONQUESTION | MB_DEFBUTTON2
                                 | MB_TASKMODAL | MB_SETFOREGROUND);
    return Bool(result == IDYES);
}