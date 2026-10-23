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
#include <ShlObj.h>     // SHGetKnownFolderPath —— 用户配置目录（§5.4）
#include <KnownFolders.h>
#include <objbase.h>             // IUnknown —— UIAutomationCore.h 接口的基类
#include <UIAutomationCore.h>    // IRawElementProviderSimple / ProviderOptions
#include <UIAutomationClient.h>  // UIA_*ControlTypeId / UIA_*PropertyId 常量
#include <uiautomationcoreapi.h> // UiaReturnRawElementProvider / UiaRootObjectId
#pragma comment(lib, "UIAutomationCore.lib")  // UiaReturnRawElementProvider
#pragma comment(lib, "oleaut32.lib")          // SysAllocString / VariantInit / BSTR
#pragma comment(lib, "ole32.lib")             // COM（IUnknown 基类）
#pragma comment(lib, "uuid.lib")              // __uuidof(IRawElementProviderSimple / IUnknown)

#include <string>
#include <vector>

using namespace php;

// ★ 拖动重排入口（§5.2）：PHP 侧具名全局函数（src/Platform/Win32/ResizeHook.php）。
//   **必须声明在全局作用域**——放进下面那个匿名 namespace 会被链接成
//   `anonymous namespace::php_drift_resize_hook_pump`，与 AOT 生成的符号对不上
//   （实测 LNK2019）。
extern void php_drift_resize_hook_pump();

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
    kEvWheel,
    kEvDeactivate,
};

struct PendingEvent {
    int kind;
    int a;   // resize: w ／ dpi: dpi ／ timer: id ／ pointer: x ／ wheel: x ／ key: — ／ char: —
    int b;   // resize: h ／ pointer: y ／ wheel: y ／ —
    int c;   // pointer: button（move 恒 0 —— 按住态由应用自己的拖动状态跟踪）／ wheel: delta（WHEEL_DELTA=120 为一格；壳已取负 ⇒ 正=回拉=内容上移，见 WM_MOUSEWHEEL 注释）／ key: modifier 位（1=ctrl 2=alt 4=shift）／ —
    int d;   // pointer: 相位（0=down 1=move 2=up 3=leave，与 Drift\Input\PointerPhase 同值）／ —
    std::string text;   // key: 键名 ／ char: UTF-8 文本
};

// ─────────────────────────────────────────────────────────────── 状态 ──

HWND g_main = NULL;
int g_clientW = 0;
int g_clientH = 0;
UINT g_dpi = 96;

// ── 最小客户区（§5.4，物理像素；0 = 未设）──────────────────────────
// ★ 为什么必须有：窗口能被拖到比内容更小 ⇒ C5/C6 的布局硬校验会弹 Fatal，
//   而"窗口被拖小"是**用户行为**、不是编程错误（用户实测：可用宽 93 vs 条轨 94）。
//   最小尺寸是主流 GUI 的标配（Qt/WPF/Flutter 都有 minimumSize），比让每个
//   容器在极小空间里优雅降级便宜得多。
int g_minClientW = 0;
int g_minClientH = 0;

/** `AdjustWindowRectExForDpi` 的前置声明（定义在文件后部，WndProc 要用） */
void adjustFrameForDpi(RECT *rect, DWORD style, BOOL menu, DWORD exStyle, UINT dpi);

// ── §5.4 退出路径专用：窗口销毁后的"最后已知几何" ──
// 为什么需要：WM_CLOSE 里壳**同步** DestroyWindow ⇒ 等组合根的 while(pump())
// 跳出、WindowStateStore::persist() 来问的时候，窗口句柄已经死了 ⇒
// GetClientRect 对一个死句柄必然失败 ⇒ "记住窗口位置"永远写不出去。
// 刷新两条路：① 每次活窗口查询成功（顺手刷新，零额外成本）；
//           ② WM_DESTROY（决定性的一发 —— 退出前最后一帧的真实几何）。
// 最小化 ⇒ 整份作废（最小化期间的坐标是屏幕外的，照抄会让下次启动"消失"）。
static int  g_lastPosX = 0;
static int  g_lastPosY = 0;
static int  g_lastClientW = 0;
static int  g_lastClientH = 0;
static bool g_lastMaximized = false;
static bool g_lastGeomValid = false;

/** 窗口还活着 ⇒ 顺手刷新缓存；已死 ⇒ 回填最后已知值（从未记过 ⇒ 空数组） */
static void cacheFinalGeometry(HWND target) {
    if (target == NULL) {
        return;
    }
    if (IsIconic(target) != FALSE) {
        g_lastGeomValid = false;   // 最小化 = 别记，保留上一份
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
    g_lastPosX = origin.x;
    g_lastPosY = origin.y;
    g_lastClientW = client.right - client.left;
    g_lastClientH = client.bottom - client.top;
    g_lastMaximized = IsZoomed(target) != FALSE;
    g_lastGeomValid = true;
}

/** 死句柄查询时把最后已知几何摊进 out（x/y/w/h 四个键一起给，用哪半取哪半） */
static void fillGeomFromCache(Array &out) {
    if (g_lastGeomValid) {
        out.set("x", (Float)(double)g_lastPosX);
        out.set("y", (Float)(double)g_lastPosY);
        out.set("w", (Int)g_lastClientW);
        out.set("h", (Int)g_lastClientH);
    }
}

// ── IME 定位（§8.4）── 设备像素的客户区坐标；(-1,-1,0) = 无插入符
// 两个消费点：组字窗位置（WM_IME_STARTCOMPOSITION / CFS_POINT）与
// IMR_QUERYCHARPOSITION 应答。内部换算用 g_dpi（与窗口同一 dpr）。
float g_imeX = -1.0f;
float g_imeY = -1.0f;
float g_imeH = 0.0f;
// 1×1 假 caret 的可见态（IME 定位：输入法经 GetCaretPos 找候选框位置）。
// 建/销各只有一处（WM_IME_STARTCOMPOSITION / WM_IME_ENDCOMPOSITION）。
bool g_caretVisible = false;

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

// ── UIA 无障碍（§9 / M5，P2 数据流）─────────────────────────────────
// PHP 把 SemanticsTree 扁平节点推进来（php_drift_win_uia_push）；provider 从
// 这份数组应答 UIA 客户端。P2 只到"根属性可达"，树导航（Fragment）归 P3。
struct UiaNode {
    std::wstring name;
    long controlType = UIA_CustomControlTypeId;
    int parent = -1;   // 父节点索引（g_uiaNodes），-1 = 顶层（父是窗口根）
    bool focused = false;
    double x = 0.0;
    double y = 0.0;
    double w = 0.0;
    double h = 0.0;
    bool isControl = true;
};
static std::vector<UiaNode> g_uiaNodes;
static int g_lastFocusIdx = -2;   // 上次 push 时的焦点节点索引（-2 = 未初始化）

// Drift 语义角色（SemanticsRole::value，string backed）⇒ UIA ControlTypeId。
// Windows 编号知识集中在这一处（§5.1 纪律 1：PHP 侧不持平台常量）。
static long uiaControlTypeFor(const std::string &role) {
    if (role == "button") return UIA_ButtonControlTypeId;
    if (role == "textfield") return UIA_EditControlTypeId;
    if (role == "scroll") return UIA_ListControlTypeId;
    if (role == "dialog") return UIA_WindowControlTypeId;
    return UIA_CustomControlTypeId;
}

// ── parent 关系走树 helper（g_uiaNodes 前序扁平，parent 指父索引，-1 = 窗口根）──
static int uiaFirstChild(int idx) {
    for (size_t k = 0; k < g_uiaNodes.size(); k++) {
        if (g_uiaNodes[k].parent == idx) return (int)k;
    }
    return -1;
}
static int uiaNextSibling(int idx) {
    if (idx < 0 || idx >= (int)g_uiaNodes.size()) return -1;
    int p = g_uiaNodes[idx].parent;
    for (size_t k = (size_t)idx + 1; k < g_uiaNodes.size(); k++) {
        if (g_uiaNodes[k].parent == p) return (int)k;
    }
    return -1;
}
static int uiaPrevSibling(int idx) {
    if (idx < 0 || idx >= (int)g_uiaNodes.size()) return -1;
    int p = g_uiaNodes[idx].parent;
    for (int k = idx - 1; k >= 0; k--) {
        if (g_uiaNodes[k].parent == p) return k;
    }
    return -1;
}
static int uiaLastChild(int idx) {
    int last = -1;
    for (size_t k = 0; k < g_uiaNodes.size(); k++) {
        if (g_uiaNodes[k].parent == idx) last = (int)k;
    }
    return last;
}

// ★ 本 SDK（10.0.26100）UIA 接口扁平化：Fragment / FragmentRoot 都直接继承 IUnknown，
//   Simple 吸收 get_HostRawElementProvider，ShowContextMenu 已移除 ⇒ 多接口继承会
//   IUnknown 歧义。故主类只继承 IRawElementProviderFragmentRoot，Simple / Fragment 经
//   内嵌 adapter（QI 分派；adapter 的 IUnknown 委托主对象）。每 provider 持一个
//   g_uiaNodes 索引（-1 = 窗口根），Navigate 按 parent 走树（§9 / M5，P3）。
class CProvider;

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

class CProvider : public IRawElementProviderFragmentRoot {
public:
    explicit CProvider(int index)
        : m_ref(1), m_index(index), m_simple(new SimpleAdapter(this)), m_frag(new FragmentAdapter(this)) {}
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
    static IRawElementProviderFragment *makeFragment(int idx) {
        CProvider *p = new CProvider(idx);
        IRawElementProviderFragment *frag = nullptr;
        p->QueryInterface(__uuidof(IRawElementProviderFragment), (void **)&frag);
        p->Release();
        return frag;
    }

    // 造一个"能当 IRawElementProviderSimple"的 provider（focus changed raise 用：
    // 通知里带的是焦点元素本身）。引用契约同 makeFragment。
    static IRawElementProviderSimple *makeFragmentSimple(int idx) {
        CProvider *p = new CProvider(idx);
        IRawElementProviderSimple *simple = nullptr;
        p->QueryInterface(__uuidof(IRawElementProviderSimple), (void **)&simple);
        p->Release();
        return simple;
    }

    // IRawElementProviderFragmentRoot（本 SDK 只剩这两个纯虚）
    STDMETHOD(ElementProviderFromPoint)(double, double, IRawElementProviderFragment **ret) override {
        *ret = makeFragment(-1);
        return S_OK;
    }
    STDMETHOD(GetFocus)(IRawElementProviderFragment **ret) override {
        // 返回真实焦点节点（§9 / M5，B）；无焦点 ⇒ 窗口根（-1）。
        for (size_t k = 0; k < g_uiaNodes.size(); k++) {
            if (g_uiaNodes[k].focused) { *ret = makeFragment((int)k); return S_OK; }
        }
        *ret = makeFragment(-1);
        return S_OK;
    }

    // ── Simple 逻辑（SimpleAdapter 转发；按 m_index 读 Name / ControlType）──
    HRESULT simpleOptions(ProviderOptions *p) {
        *p = ProviderOptions_ServerSideProvider;
        return S_OK;
    }
    HRESULT simplePattern(PATTERNID, IUnknown **ppv) {
        *ppv = nullptr;
        return S_OK;
    }
    HRESULT simpleProperty(PROPERTYID id, VARIANT *out) {
        VariantInit(out);
        std::wstring name = L"Drift";
        long ctrl = UIA_CustomControlTypeId;
        if (m_index == -1) {
            ctrl = UIA_WindowControlTypeId;
        } else if (m_index >= 0 && m_index < (int)g_uiaNodes.size()) {
            name = g_uiaNodes[m_index].name;
            ctrl = g_uiaNodes[m_index].controlType;
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
        if (m_index == -1) {
            if (dir == NavigateDirection_FirstChild) {
                int c = uiaFirstChild(-1);
                if (c >= 0) *ret = makeFragment(c);
            }
            return S_OK;
        }
        int target = -1;
        bool valid = false;
        switch (dir) {
            case NavigateDirection_Parent:
                target = g_uiaNodes[m_index].parent;   // -1 = 窗口根（合法）
                valid = true;
                break;
            case NavigateDirection_FirstChild:
                target = uiaFirstChild(m_index);
                valid = target >= 0;
                break;
            case NavigateDirection_LastChild:
                target = uiaLastChild(m_index);
                valid = target >= 0;
                break;
            case NavigateDirection_NextSibling:
                target = uiaNextSibling(m_index);
                valid = target >= 0;
                break;
            case NavigateDirection_PreviousSibling:
                target = uiaPrevSibling(m_index);
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
    HRESULT fragBoundingRect(UiaRect *rect) {
        if (m_index >= 0 && m_index < (int)g_uiaNodes.size()) {
            // 几何是逻辑/客户区，UIA 要屏幕物理 —— 位置近似（尺寸对、原点未加客户区偏移）。
            rect->left = g_uiaNodes[m_index].x;
            rect->top = g_uiaNodes[m_index].y;
            rect->width = g_uiaNodes[m_index].w;
            rect->height = g_uiaNodes[m_index].h;
        } else {
            rect->left = rect->top = rect->width = rect->height = 0.0;
        }
        return S_OK;
    }
    HRESULT fragFragmentRoot(IRawElementProviderFragmentRoot **ret) {
        *ret = new CProvider(-1);   // CProvider* → FragmentRoot*（upcast，直接基类）
        return S_OK;
    }

private:
    ULONG m_ref;
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
static CProvider *g_uiaProvider = nullptr;

// 当前指针形状。★ **必须缓存**：`WM_SETCURSOR` 每次鼠标移动都会来一次，而那一刻
// PHP 侧可能正阻塞在 `GetMessageW` 里（问不到框架）⇒ 只能重贴**上一次**的决定。
// 见 `php_drift_win_set_cursor()` 与 WndProc 的 `WM_SETCURSOR` 分支。
HCURSOR g_cursor = NULL;

// ── 指针跟踪（2026-10-17 move/up/leave 通路）──
// 最后已知指针位置（客户区，设备像素）—— `WM_MOUSELEAVE` 不再带坐标，
// 用它兜底（消费方只看"离开了"，不用坐标，见 PointerEvent::leave 的 docblock）。
int g_ptrX = -1;
int g_ptrY = -1;

// `TrackMouseEvent(TME_LEAVE)` 是否已请求。★ 不请求跟踪就没有 `WM_MOUSELEAVE`
// ⇒ 指针离开客户区时框架永远不知道（悬停目标卡在最后一个上、拖动不取消）——
// hover 的标准 Win32 模式：首次移动时请求一次，离开后复位、下次重新请求。
bool g_ptrTracked = false;

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
    // ★ resize 合并（coalesce，2026-10-19 修"松手后卡顿"）：拖动边缘时系统
    //   高频同步派发 WM_SIZE（ modal resize loop 期间每条都进这里），而应用帧
    //   循环要等 modal loop 退出后才逐条重放 ⇒ N 条中间尺寸各触发一次全树重排
    //   + Skia 重画，大窗口下每帧几十 ms，串行重放就是秒级卡顿。
    //   合并到队列中**最早未消费**的那条（保持顺序位置，只更新尺寸）⇒ 松手后
    //   一帧直达最终尺寸。同尺寸的重复帧对用户零信息（只看最终态）。
    if (event.kind == kEvResize) {
        for (auto &queued : g_queue) {
            if (queued.kind == kEvResize) {
                queued.a = event.a;
                queued.b = event.b;
                if (g_main != NULL) {
                    PostMessageW(g_main, kMsgWake, 0, 0);
                }
                return;
            }
        }
    }
    g_queue.push_back(event);
    if (g_main != NULL) {
        // 把阻塞在 GetMessageW 上的泵叫醒。载荷在队列里，这条消息只是闹钟。
        PostMessageW(g_main, kMsgWake, 0, 0);
    }
}

/**
 * 指针事件入队（四相位共用一个壳；顺带记最后已知位置供 `WM_MOUSELEAVE` 用）。
 *
 * ★ `phase` 与 `Drift\Input\PointerPhase` 的 value 同值（0=down 1=move 2=up
 *   3=leave）—— 与 `CursorShape` 同一条纪律：跨边界的枚举值是 ABI。
 */
void pushPointer(int x, int y, int button, int phase) {
    g_ptrX = x;
    g_ptrY = y;

    PendingEvent event = {};
    event.kind = kEvPointer;
    event.a = x;
    event.b = y;
    event.c = button;
    event.d = phase;
    enqueue(event);
}

// ───────────────────────────────────────────────────────────── 贴屏 ──

/** 把缓存帧贴到给定 DC。`WM_PAINT` 与 `present()` 共用这一条路径。
 *
 *  ★ `dstW/dstH` 非 0 = 目标尺寸与帧不同（resize 拖动中：PHP 还没推出新帧，
 *    客户区已变大）⇒ **原尺寸贴 + 余区填帧底色**。
 *  ★ **不拉伸**（2026-10-19 用户裁决）：拉伸会让用户看到"旧内容被放大/重复"，
 *    比一块同色留白难受得多。余区取帧右下角像素当底色 —— 那就是应用自己的
 *    背景色，深色主题也自动跟随。 */
void presentTo(HDC dc, int dstW = 0, int dstH = 0) {
    if (g_frame.empty() || g_frameW <= 0 || g_frameH <= 0 || dc == NULL) {
        return;
    }
    if (dstW <= 0 || dstH <= 0) {
        dstW = g_frameW;
        dstH = g_frameH;
    }

    BITMAPINFO info = {};
    info.bmiHeader.biSize        = sizeof(BITMAPINFOHEADER);
    info.bmiHeader.biWidth       = g_frameW;
    info.bmiHeader.biHeight      = -g_frameH;   // 负高 = 自上而下，与 RGBA 行序一致
    info.bmiHeader.biPlanes      = 1;
    info.bmiHeader.biBitCount    = 32;
    info.bmiHeader.biCompression = BI_RGB;

    if (dstW == g_frameW && dstH == g_frameH) {
        SetDIBitsToDevice(dc, 0, 0, g_frameW, g_frameH, 0, 0, 0, g_frameH,
                          g_frame.data(), &info, DIB_RGB_COLORS);
    } else {
        // ★ 尺寸不匹配（拖动中 PHP 新帧未到）：**原尺寸贴 + 余区用帧背景色填**
        //   —— **不拉伸**（2026-10-19 用户裁决：拉伸会看到"旧内容放大/重复"，
        //   比留白更难受）。取帧右下角像素当背景色（那就是应用的底色，零配置、
        //   深色主题也自动跟随）。
        const int w = dstW < g_frameW ? dstW : g_frameW;
        const int h = dstH < g_frameH ? dstH : g_frameH;

        // 余区填底色（右下角像素 = BGRA）；先填两块余区（右、下），再贴帧（不重叠）
        const unsigned char *last = g_frame.data() + g_frame.size() - 4;
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
        SetDIBitsToDevice(dc, 0, 0, w, h, 0, 0, 0, h, g_frame.data(), &info, DIB_RGB_COLORS);
    }
}

// ─────────────────────────────────────── 拖动重排入口（§5.2 模态循环）──
// 拖窗口边缘时系统进入模态尺寸循环，PHP 主循环被挂起 ⇒ 拖动中的刷新只能由壳
// **同步**调回 PHP 跑一帧。这是编译期已知的具名函数（不是回调穿越边界）：
// PHP 侧 `src/Platform/Win32/ResizeHook.php`（无 namespace，AOT 符号名约定）。
// 声明在文件顶部**全局作用域**（见那里的注释：匿名 namespace 会改符号名）。

/** 模态拖动会话（WM_ENTERSIZEMOVE..WM_EXITSIZEMOVE） */
static bool g_inSizeMove = false;
/** 防重入：hook 内部若再次触发 WM_SIZE/WM_TIMER 不得嵌套再进 PHP */
static bool g_inResizeHook = false;
/** 上一次 hook 的耗时（ms）；0 = 尚未成功跑过（此时退回旧帧拉伸兜底） */
static DWORD g_resizeHookMs = 0;
/** 跟手预算（ms）：超过则跳过本次（与 §5.2 的 100ms 因果感知上限同源，更严） */
static const DWORD kResizeHookBudgetMs = 48;

/** 让 PHP 同步重排 + 重绘 + present 一帧（安全封装：防重入 + 预算） */
static void pumpResizeHook() {
    if (g_inResizeHook || g_resizeHookMs >= kResizeHookBudgetMs) {
        return;   // 正在跑 / 上次太慢：本次跳过，画面由拉伸兜底或松手后收尾
    }
    g_inResizeHook = true;
    const DWORD started = GetTickCount();
    ::php_drift_resize_hook_pump();
    g_resizeHookMs = GetTickCount() - started;
    g_inResizeHook = false;
}

/** hook 是否真的产出了**匹配当前客户区**的帧（PHP 未注入时 hook 是 no-op） */
static bool resizeHookProducedFrame() {
    return g_frameW == g_clientW && g_frameH == g_clientH && g_frameW > 0;
}

// ─────────────────────────────────────────── WndProc ──

LRESULT CALLBACK driftWndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp) {
    switch (msg) {
        case WM_GETOBJECT:
            // UIA 无障碍（§9 / M5）：UIA 客户端问窗口 provider ⇒ QI 出 Simple 交出。
            if (lp == (LPARAM)UiaRootObjectId && g_uiaProvider != nullptr) {
                IRawElementProviderSimple *simple = nullptr;
                if (SUCCEEDED(g_uiaProvider->QueryInterface(__uuidof(IRawElementProviderSimple), (void **)&simple))) {
                    return UiaReturnRawElementProvider(hwnd, wp, lp, simple);
                }
            }
            break;
        case WM_CLOSE:
            // 不调 DefWindowProc：它会走"关闭确认"那套，而 Drift 的退出语义
            // 只有一条（销毁 ⇒ WM_QUIT ⇒ 泵返回 quit，见 WindowHost::close 的 docblock）。
            DestroyWindow(hwnd);
            return 0;

        case WM_DESTROY:
            // ★ 销毁**之前**抓最后几何（§5.4 的救命一发：persist 会在这个窗口
            //   死透之后才来问它，见 g_lastGeom* 的注释）。
            cacheFinalGeometry(hwnd);
            PostQuitMessage(0);
            return 0;

        case WM_ENTERSIZEMOVE: {
            // 模态尺寸循环开始（§5.2）：PHP 主循环即被挂起 ⇒ 由下面的 WM_SIZE
            // 分支**同步**产帧（Flutter Windows embedder 同款：在 WM_SIZE 里等到
            // 一张匹配新尺寸的帧；模态循环是串行的 ⇒ 产帧期间尺寸不会变）。
            g_inSizeMove = true;
            return 0;
        }

        case WM_EXITSIZEMOVE: {
            g_inSizeMove = false;
            // 松手：常规循环恢复（积压的 resize 已被合并成一条，见 enqueue）
            return 0;
        }

        case WM_GETMINMAXINFO: {
            // 最小尺寸（§5.4）：客户区 min（物理像素）⇒ 外框 min（路径同建窗换算）。
            // 0 = 未设 ⇒ 交给 DefWindowProc 的系统默认。
            if (g_minClientW > 0 && g_minClientH > 0) {
                MINMAXINFO *info = (MINMAXINFO *)lp;
                if (info != NULL) {
                    RECT frame = {0, 0, g_minClientW, g_minClientH};
                    adjustFrameForDpi(
                        &frame,
                        (DWORD)GetWindowLongPtrW(hwnd, GWL_STYLE),
                        FALSE,
                        (DWORD)GetWindowLongPtrW(hwnd, GWL_EXSTYLE),
                        g_dpi);
                    info->ptMinTrackSize.x = frame.right - frame.left;
                    info->ptMinTrackSize.y = frame.bottom - frame.top;
                }
            }
            return 0;
        }

        case WM_SIZE: {
            int w = LOWORD(lp);
            int h = HIWORD(lp);
            // 最小化时 lp 是 0；宽高非正一律忽略（恢复时会再来一条 WM_SIZE）
            if (w > 0 && h > 0) {
                g_clientW = w;
                g_clientH = h;
                // ★ 拖动中**同步产帧**（§5.2，Flutter/Qt 同口径）：模态循环串行
                //   ⇒ 这里产帧期间下一个鼠标消息不会被处理、尺寸不会变 ⇒ 帧必然
                //   匹配当前客户区。产完再决定要不要拉伸兜底（正常情况下不需要）。
                if (g_inSizeMove) {
                    pumpResizeHook();
                }

                // ★ 2026-10-19：**不清帧**。旧实现在这里 clear() ⇒ WM_PAINT 时
                //   presentTo 无内容可贴，resize 拖动中新露出的区域全黑。
                //   尺寸不匹配时由 presentTo 走"原尺寸贴 + 余区填底色"（**不拉伸**）。
                if (!resizeHookProducedFrame()
                    && !g_frame.empty() && g_frameW > 0 && g_frameH > 0
                    && (g_frameW != w || g_frameH != h)) {
                    HDC dc = GetDC(hwnd);
                    if (dc != NULL) {
                        presentTo(dc, w, h);
                        ReleaseDC(hwnd, dc);
                    }
                }

                PendingEvent event = {};
                event.kind = kEvResize;
                event.a = w;
                event.b = h;
                enqueue(event);
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
                enqueue(event);
            }
            // 不 `return 0`：这条消息没有"必须吞掉"的理由，交给 DefWindowProc
            // （其它壳分支 `return 0` 是因为它们**处理**了那条消息，这里只是旁听）。
            break;
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
            // 帧尺寸与客户区不同（resize 后 PHP 新帧未到）⇒ 拉伸贴，不留黑边
            RECT rc;
            presentTo(dc, GetClientRect(hwnd, &rc) ? (int)(rc.right - rc.left) : 0,
                      rc.bottom - rc.top);
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

            pushPointer(GET_X_LPARAM(lp), GET_Y_LPARAM(lp), button, 0);   // Down
            return 0;
        }

        case WM_MOUSEMOVE: {
            // ★ TrackMouseEvent(TME_LEAVE)：不请求跟踪就没有 WM_MOUSELEAVE ⇒
            //   指针离开客户区时框架永远不知道（悬停目标卡在最后一个上、
            //   拖动不取消）。首次移动时请求一次，离开后复位。
            if (!g_ptrTracked) {
                TRACKMOUSEEVENT tme = {};
                tme.cbSize = sizeof(tme);
                tme.dwFlags = TME_LEAVE;
                tme.hwndTrack = hwnd;
                TrackMouseEvent(&tme);
                g_ptrTracked = true;
            }

            // ★ move 的 button 恒 0：wp 里的 MK_* 位（按住态）没有消费者 ——
            //   拖动状态由应用自己的 pending 记录跟踪（§9.1 / A15：不预先
            //   发明线上语义）。
            pushPointer(GET_X_LPARAM(lp), GET_Y_LPARAM(lp), 0, 1);   // Move
            return 0;
        }

        case WM_LBUTTONUP:
        case WM_MBUTTONUP:
        case WM_RBUTTONUP: {
            int button = 0;
            if (msg == WM_MBUTTONUP) button = 1;
            if (msg == WM_RBUTTONUP) button = 2;

            pushPointer(GET_X_LPARAM(lp), GET_Y_LPARAM(lp), button, 2);   // Up
            return 0;
        }

        case WM_MOUSELEAVE: {
            g_ptrTracked = false;   // 下次进入要重新请求跟踪

            // 坐标是最后已知位置（系统不再给新坐标）—— 消费方只看"离开了"
            pushPointer(g_ptrX, g_ptrY, 0, 3);   // Leave
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
            ScreenToClient(g_main, &pt);

            PendingEvent event = {};
            event.kind = kEvWheel;
            event.a = (int)pt.x;
            event.b = (int)pt.y;
            event.c = -GET_WHEEL_DELTA_WPARAM(wp);
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
            // ★ 2026-10-19 补三件（真机候选框贴屏幕左上角的修复）：
            //   ① 创建 1×1 **可见**系统 caret —— 不少输入法（含部分 TSF 系）
            //      通过 GetCaretPos 定位候选框；caret 不存在时查询失败返回
            //      (0,0)，候选框就落到屏幕左上角。只 SetCaretPos 不建 caret
            //      对这一类输入法无效。
            //   ② ImmSetCandidateWindow（默认候选窗，同点）—— IMM32 输入法
            //      （搜鱼/QQ 类）的**候选框**位置由它决定，不设就默认位置。
            //   ③ 见 WM_IME_ENDCOMPOSITION 的清理。
            if (g_imeX >= 0.0f) {
                double imeScale = (double)g_dpi / 96.0;
                LONG cx = (LONG)((double)g_imeX / imeScale);
                LONG cy = (LONG)((double)g_imeY / imeScale);
                if (!g_caretVisible) {
                    CreateCaret(hwnd, nullptr, 1, (int)(g_imeH / imeScale) > 0 ? (int)(g_imeH / imeScale) : 1);
                    ShowCaret(hwnd);
                    g_caretVisible = true;
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
            if (g_caretVisible) {
                HideCaret(hwnd);
                DestroyCaret();
                g_caretVisible = false;
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
            out.set("phase", (Int)event.d);
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
            break;

        case kEvTheme:
            out.set("type", "theme");
            break;

        case kEvDeactivate:
            out.set("type", "deactivate");
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

    // UIA 无障碍（§9 / M5，P2）：建窗即挂 provider（数据由 php_drift_win_uia_push 喂）。
    g_uiaProvider = new CProvider(-1);   // 根 provider（m_index=-1 = 窗口根）

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

void php_drift_win_set_min_size(Int hwnd, Int width, Int height) {
    // §5.4：客户区最小尺寸（**物理**像素）。WM_GETMINMAXINFO 里换算成外框。
    // 只是"下一次拖动时的限制" —— 当前窗口若已经更小，不强制放大（那会让
    // 用户在窄屏幕上开不出窗口）。
    g_minClientW = width > 0 ? (int)width : 0;
    g_minClientH = height > 0 ? (int)height : 0;
    (void)hwnd;   // 单窗口进程：状态在进程级（同 g_clientW）
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
        // 无插入符：收起 caret 并挪到客户区外（IME 查到的位置退左上角）
        if (g_caretVisible) {
            HideCaret(g_main);
            DestroyCaret();
            g_caretVisible = false;
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
        fillGeomFromCache(out);
        return out;
    }

    out.set("w", (Int)(rect.right - rect.left));
    out.set("h", (Int)(rect.bottom - rect.top));
    cacheFinalGeometry(target);
    return out;
}

Bool php_drift_win_is_maximized(Int hwnd) {
    HWND target = (HWND)(INT_PTR)hwnd;
    if (target == NULL) {
        // 窗口已死（退出路径）：退化到最后已知状态（§5.4）
        return (Bool)(g_lastGeomValid && g_lastMaximized);
    }
    bool zoomed = IsZoomed(target) != FALSE;
    g_lastMaximized = zoomed;   // 活窗口：顺手刷新，缓存有效性仍归 cacheFinalGeometry 管
    return (Bool)zoomed;
}

Array php_drift_win_window_position(Int hwnd) {
    // §5.4 持久化的读向侧。**物理**像素；空数组 = "别记"（最小化，或从未记过）。
    // 为什么不记最小化时的坐标：Windows 在最小化期间把窗口摆在屏幕外
    // （惯例是 (-32000,-32000)），照抄回来下次启动窗口就"消失"了。
    // ★ 窗口已销毁（退出路径）⇒ 回填 WM_DESTROY 抓的最后已知位置，不是空数组 ——
    //   否则 persist() 第一关就跳过，"记住窗口位置"永远写不出去（真机实测过）。
    HWND target = (HWND)(INT_PTR)hwnd;
    Array out;
    if (target != NULL && IsIconic(target) != FALSE) {
        g_lastGeomValid = false;
        return out;
    }

    RECT client = {0, 0, 0, 0};
    if (target == NULL || !GetClientRect(target, &client)) {
        fillGeomFromCache(out);
        return out;
    }
    POINT origin = {client.left, client.top};
    if (!ClientToScreen(target, &origin)) {
        fillGeomFromCache(out);
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
    if (g_minClientW > 0 && g_minClientH > 0) {
        if ((double)w * dpr < (double)g_minClientW) {
            w = (Float)((double)g_minClientW / dpr);
        }
        if ((double)h * dpr < (double)g_minClientH) {
            h = (Float)((double)g_minClientH / dpr);
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
    if (g_frameW != w || g_frameH != h) {
        g_frame.assign(need, 0);
        g_frameW = w;
        g_frameH = h;
    }

    // ★★ 零 swizzle：BGRA → BGRA。只拷紧凑宽度，忽略 Skia 的行尾填充。
    const size_t rowPixels = (size_t)w * 4u;
    for (int y = 0; y < h; y++) {
        memcpy(g_frame.data() + (size_t)y * rowPixels, src + (size_t)y * rowBytes, rowPixels);
    }

    HDC dc = GetDC(target);
    if (dc != NULL) {
        presentTo(dc);
        ReleaseDC(target, dc);
    }
}

// ═══════════════════════════════════════ UIA 无障碍（§9 / M5，P2 数据流）══
// PHP 侧 SemanticsBuilder::build(root)->toArrays()['nodes'] 推进来；provider 从
// 这份扁平数组应答 UIA 客户端。空数组 = 无障碍关闭（清空 C 侧树）。
void php_drift_win_uia_push(Array nodes) {
    static int g_lastNodeCount = -1;
    g_uiaNodes.clear();
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
        node.name = toWide(String(name));
        node.controlType = uiaControlTypeFor(role);
        node.parent = (int)a.get("parent").toInt();
        node.x = a.get("x").toFloat();
        node.y = a.get("y").toFloat();
        node.w = a.get("w").toFloat();
        node.h = a.get("h").toFloat();
        node.isControl = true;
        node.focused = a.get("focused").toBool();
        g_uiaNodes.push_back(node);
    }

    // 结构变化事件（§9 / M5，P4）：节点数变了 ⇒ 通知 UIA 客户端重新查询树
    // （Loading→Ready、列表增删）。count 触发（同 count 的内容变不 raise，够用）。
    int cnt = (int)g_uiaNodes.size();
    if (cnt != g_lastNodeCount) {
        g_lastNodeCount = cnt;
        if (g_uiaProvider != nullptr) {
            IRawElementProviderSimple *simple = nullptr;
            if (SUCCEEDED(g_uiaProvider->QueryInterface(__uuidof(IRawElementProviderSimple), (void **)&simple)) && simple != nullptr) {
                UiaRaiseStructureChangedEvent(simple, StructureChangeType_ChildrenInvalidated, nullptr, 0);
                simple->Release();
            }
        }
    }

    // 焦点变化事件（§9 / M5，B）：焦点节点索引变了 ⇒ raise。provider 取焦点节点本身
    // （客户端回调里一般会 GetFocusedElement()，这里只负责通知"焦点动过了"）。
    int focusIdx = -1;
    for (size_t k = 0; k < g_uiaNodes.size(); k++) {
        if (g_uiaNodes[k].focused) { focusIdx = (int)k; break; }
    }
    if (focusIdx != g_lastFocusIdx) {
        g_lastFocusIdx = focusIdx;
        if (focusIdx >= 0 && g_uiaProvider != nullptr) {
            IRawElementProviderSimple *fsimple = CProvider::makeFragmentSimple(focusIdx);
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