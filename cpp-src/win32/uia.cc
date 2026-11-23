#include "uia.h"
#include "messages.h"

#include <UIAutomationClient.h>
#include <UIAutomationCore.h>
#include <objbase.h>
#include <uiautomationcoreapi.h>

#include <string>
#include <utility>
#include <vector>

#pragma comment(lib, "UIAutomationCore.lib")
#pragma comment(lib, "oleaut32.lib")
#pragma comment(lib, "ole32.lib")
#pragma comment(lib, "uuid.lib")

namespace drift::win32::uia {

// ── UIA 无障碍（§9 / M5，P2 数据流）─────────────────────────────────
// PHP 把扁平的 SemanticsTree 推进 `slot.uiaNodes`，provider 从这份数组应答
// UIA 客户端。`UiaNode` / `WindowSlot` 定义在 `window_slot.h`（两个 TU 共用）。

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
    // 树的行（§65）。★ 换控制类型会连带掐掉 InvokePattern —— 见 `simplePattern()`
    //   里那条"Button **或** TreeItem"的注释。
    if (role == "treeitem") return UIA_TreeItemControlTypeId;
    // 表格行（§66）：DataItem 是 UIA 里"表里的一条记录"的标准 ControlType，
    //   不映射就掉到 Custom ⇒ 客户端认不出这是行。
    if (role == "row") return UIA_DataItemControlTypeId;
    return UIA_CustomControlTypeId;
}

// 事件 diff 的身份：Element key 优先，其次 automationId（给自动化的稳定标识）。
// 两者都空就跳过 —— 只按索引比会在滚动/排序后误报（§66）。
static std::wstring uiaNodeIdentity(const UiaNode &n) {
    return !n.key.empty() ? n.key : n.automationId;
}

// 属性变更事件的 VARIANT：raise 之后立刻 VariantClear，避免 BSTR 泄漏。
static VARIANT uiaVarBstr(const std::wstring &s) {
    VARIANT v;
    VariantInit(&v);
    v.vt = VT_BSTR;
    v.bstrVal = SysAllocString(s.c_str());
    return v;
}
static VARIANT uiaVarBool(bool b) {
    VARIANT v;
    VariantInit(&v);
    v.vt = VT_BOOL;
    v.boolVal = b ? VARIANT_TRUE : VARIANT_FALSE;
    return v;
}
static void uiaRaiseProperty(IRawElementProviderSimple *simple, PROPERTYID id,                             VARIANT before, VARIANT after) {
    UiaRaiseAutomationPropertyChangedEvent(simple, id, before, after);
    VariantClear(&before);
    VariantClear(&after);
}

/**
 * 勾选态（§65）：`ToggleState` 属性与 `get_ToggleState()` 共用这一份判据。
 * 两处各写一份，症状是「读屏念已选中、脚本调 Pattern 说未选中」，而没人会同时验。
 * radio 的 value = 选中时自己的值 / 未选中空串；checkbox / switch 是 checked / unchecked。
 */
static ToggleState toggleStateOf(const UiaNode &node) {
    if (node.controlType == UIA_RadioButtonControlTypeId) {
        return node.value.empty() ? ToggleState_Off : ToggleState_On;
    }
    return (node.value == L"checked") ? ToggleState_On : ToggleState_Off;
}

// ── IScrollProvider 的三条换算（单轴语义 → UIA 两轴视图）──────────────────
// 语义侧只带主轴 offset/max/viewport；次轴在这里统一报"不可滚"。
// 百分比 / ViewSize 的公式集中此处：属性回答与事件 diff 必须同源，各写一份会漂移。
static double uiaScrollPercent(double offset, double max) {
    if (max <= 0.0) return -1.0;   // UIA 约定：不可滚 ⇒ -1（NoScroll）
    double p = offset / max * 100.0;
    return p < 0.0 ? 0.0 : (p > 100.0 ? 100.0 : p);
}
// 视口占内容的比例（100 = 全部可见）。content = viewport + maxOffset。
static double uiaScrollViewSize(double viewport, double max) {
    double content = viewport + max;
    if (content <= 0.0) return 100.0;
    double v = viewport / content * 100.0;
    return v < 0.0 ? 0.0 : (v > 100.0 ? 100.0 : v);
}
// Scroll() 的步进：按"一页"折成百分比增量，NoAmount 返回 -1 表示该轴不动。
static double uiaScrollStep(double cur, ScrollAmount amt, double pagePercent) {
    double step;
    switch (amt) {
        case ScrollAmount_LargeDecrement: step = -pagePercent; break;
        case ScrollAmount_SmallDecrement: step = -pagePercent / 3.0; break;
        case ScrollAmount_LargeIncrement: step = pagePercent; break;
        case ScrollAmount_SmallIncrement: step = pagePercent / 3.0; break;
        default: return -1.0;
    }
    double t = cur + step;
    return t < 0.0 ? 0.0 : (t > 100.0 ? 100.0 : t);
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

// 本 SDK（10.0.26100）UIA 接口扁平化：Fragment / FragmentRoot 都直接继承 IUnknown，
//   多接口继承会 IUnknown 歧义 ⇒ 主类只继承 FragmentRoot，Simple / Fragment 走内嵌
//   adapter（QI 分派，IUnknown 委托主对象）。Navigate 按 parent 走树。
// 每窗一个 provider（M1-a）：树读写走各自的 `m_slot`，否则最后一个建窗的会覆盖前一个
//   （还会连带漏 COM 引用）。

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

// `IInvokeProvider` 的 tear-off（§9 / M5）：只把"第 m_index 个节点被 Invoke"转投成
// 一条窗口消息。激活语义不在这里复制 —— PHP 侧落成"节点中心一次合成 tap"，
// 复用输入路由的唯一路径；按角色写分支必然漏，"读屏点"与"鼠标点"不一致也很难测出来。
class InvokeAdapter : public ComTearOff<InvokeAdapter, IInvokeProvider> {
public:
    explicit InvokeAdapter(WindowSlot &slot, HWND hwnd, int index) : m_slot(slot), m_hwnd(hwnd), m_index(index) {}
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
    WindowSlot &m_slot;
    HWND m_hwnd;
    int m_index;
};

/**
 * 选中（§65）：树行 / 列表项的 `ISelectionItemProvider`。
 * 必须有这个 Pattern —— 客户端 `get_CurrentIsSelected` 从 Pattern 取，只答属性时
 * 真机读到的全是 false（2026-11-06 实测）。`Select()` 复用 `kMsgUiaInvoke`（点行 = 选中）。
 */
class SelectionItemAdapter : public ComTearOff<SelectionItemAdapter, ISelectionItemProvider> {
public:
    explicit SelectionItemAdapter(WindowSlot &slot, HWND hwnd, int index) : m_slot(slot), m_hwnd(hwnd), m_index(index) {}
    STDMETHOD(Select)() override {
        if (m_hwnd != NULL) {
            PostMessageW(m_hwnd, kMsgUiaInvoke, (WPARAM)m_index, 0);
        }
        return S_OK;
    }
    STDMETHOD(AddToSelection)() override {
        if (m_hwnd != NULL) {
            PostMessageW(m_hwnd, kMsgUiaInvoke, (WPARAM)m_index, 0);
        }
        return S_OK;
    }
    STDMETHOD(RemoveFromSelection)() override { return UIA_E_NOTSUPPORTED; }
    STDMETHOD(get_IsSelected)(BOOL *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        *ret = FALSE;
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
        if (m_index >= 0 && m_index < (int)nodes.size()) {
            *ret = nodes[m_index].selectState == 2 ? TRUE : FALSE;
        }
        return S_OK;
    }
    STDMETHOD(get_SelectionContainer)(IRawElementProviderSimple **ret) override {
        // 返回父容器（客户端用它问"我属于哪个列表"）。给 nullptr 是合法的
        // —— 客户端会退到 Fragment 的 Parent 去找，不会崩。
        if (ret != nullptr) *ret = nullptr;
        return S_OK;
    }

private:
    WindowSlot &m_slot;
    HWND m_hwnd;
    int m_index;
};

/**
 * 容器选中（P0-2）：从现有子节点的 selectState 派生，不新增语义字段、
 * 不存第二份选中集。GetSelection 的定义在 CProvider 之后（要调 makeFragmentSimple）。
 */
class SelectionAdapter : public ComTearOff<SelectionAdapter, ISelectionProvider> {
public:
    explicit SelectionAdapter(WindowSlot &slot, int index) : m_slot(slot), m_index(index) {}
    STDMETHOD(GetSelection)(SAFEARRAY **ret) override;
    STDMETHOD(get_CanSelectMultiple)(BOOL *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        *ret = FALSE;
        return S_OK;
    }
    STDMETHOD(get_IsSelectionRequired)(BOOL *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        *ret = FALSE;
        return S_OK;
    }

private:
    WindowSlot &m_slot;
    int m_index;
};

/**
 * 展开/折叠（§65）：树分支行的 `IExpandCollapseProvider`。
 * 与上面同一条理由：不给这个 Pattern 时 UIA 核心把 `ExpandCollapseState` 兜底成
 * `LeafNode`（真机三行全是 3），读屏据此认为这棵树没有任何分支。
 */
class ExpandCollapseAdapter : public ComTearOff<ExpandCollapseAdapter, IExpandCollapseProvider> {
public:
    explicit ExpandCollapseAdapter(WindowSlot &slot, HWND hwnd, int index) : m_slot(slot), m_hwnd(hwnd), m_index(index) {}
    STDMETHOD(Expand)() override {
        if (m_hwnd != NULL) {
            PostMessageW(m_hwnd, kMsgUiaExpand, (WPARAM)m_index, (LPARAM)1);
        }
        return S_OK;
    }
    STDMETHOD(Collapse)() override {
        if (m_hwnd != NULL) {
            PostMessageW(m_hwnd, kMsgUiaExpand, (WPARAM)m_index, (LPARAM)0);
        }
        return S_OK;
    }
    STDMETHOD(get_ExpandCollapseState)(ExpandCollapseState *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        *ret = ExpandCollapseState_LeafNode;
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
        if (m_index >= 0 && m_index < (int)nodes.size()) {
            // 三态：0 = 没有展开概念 ⇒ 叶子（UIA 里 LeafNode 就是"不可展开"）。
            int st = nodes[m_index].expandState;
            *ret = st == 2 ? ExpandCollapseState_Expanded
                           : (st == 1 ? ExpandCollapseState_Collapsed
                                      : ExpandCollapseState_LeafNode);
        }
        return S_OK;
    }

private:
    WindowSlot &m_slot;
    HWND m_hwnd;
    int m_index;
};

// ── 值型 Pattern 的只读半边（§4.4 提交 1）──────────────────────────
// ★ 写方法（SetValue / Toggle）本提交明确回 `UIA_E_NOTSUPPORTED`，且
//   `get_IsReadOnly` = TRUE —— 客户端据此**不会**去调写方法（不是静默空壳，是
//   UIA 契约里的"这只读"）。写回路径（新事件 + 能力接口）见 §4.4 提交 2。
// ★ 数据全部来自 `m_slot.uiaNodes[m_index]`，与 Invoke 同源，不另存状态。
class ValueAdapter : public ComTearOff<ValueAdapter, IValueProvider> {
public:
    explicit ValueAdapter(WindowSlot &slot, HWND hwnd, int index) : m_slot(slot), m_hwnd(hwnd), m_index(index) {}
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
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
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
    WindowSlot &m_slot;
    HWND m_hwnd;
    int m_index;
};

class ToggleAdapter : public ComTearOff<ToggleAdapter, IToggleProvider> {
public:
    explicit ToggleAdapter(WindowSlot &slot, HWND hwnd, int index) : m_slot(slot), m_hwnd(hwnd), m_index(index) {}
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
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
        if (m_index >= 0 && m_index < (int)nodes.size()) {
            // 与属性面共用同一份判据（`toggleStateOf()`）—— 两处各写一份会漂移。
            *ret = toggleStateOf(nodes[m_index]);
        }
        return S_OK;
    }

private:
    WindowSlot &m_slot;
    HWND m_hwnd;
    int m_index;
};

class RangeAdapter : public ComTearOff<RangeAdapter, IRangeValueProvider> {
public:
    explicit RangeAdapter(WindowSlot &slot, HWND hwnd, int index) : m_slot(slot), m_hwnd(hwnd), m_index(index) {}
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
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
        *ret = (m_index >= 0 && m_index < (int)nodes.size()
                && nodes[m_index].controlType == UIA_ProgressBarControlTypeId) ? TRUE : FALSE;
        return S_OK;
    }

private:
    // value 是"人读串"（Slider 以 `%.0f` 格式化）；越界/空 ⇒ 空串（wcstod 得 0）。
    std::wstring valueAt() const {
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
        if (m_index < 0 || m_index >= (int)nodes.size()) return std::wstring();
        return nodes[m_index].value;
    }
    // 取"有则用之、无则全 0"的节点（Min/Max/大变化共用）。
    const UiaNode &nodeOrZero() const {
        static const UiaNode zero;
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
        if (m_index < 0 || m_index >= (int)nodes.size()) return zero;
        return nodes[m_index];
    }
    WindowSlot &m_slot;
    HWND m_hwnd;
    int m_index;
};

/**
 * 滚动容器（`IScrollProvider`）：`ScrollView` / `LazyList` 的读屏滚动。
 *
 * ★ 数据全来自 `m_slot.uiaNodes[m_index]` 的 scroll* 字段（PHP 侧从
 *   `ScrollMetrics` 现读），不另存状态。单轴语义：主轴有值、次轴报不可滚。
 * ★ `Scroll()` 归一成"目标百分比"再转投（`kMsgUiaScroll`），与 `SetScrollPercent`
 *   共用一条写回路径 —— 否则两套语义要在 PHP 侧各接一次，容易只接一半。
 */
class ScrollAdapter : public ComTearOff<ScrollAdapter, IScrollProvider> {
public:
    explicit ScrollAdapter(WindowSlot &slot, HWND hwnd, int index) : m_slot(slot), m_hwnd(hwnd), m_index(index) {}
    STDMETHOD(get_HorizontallyScrollable)(BOOL *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        *ret = scrollableOn(node(), 1) ? TRUE : FALSE;
        return S_OK;
    }
    STDMETHOD(get_VerticallyScrollable)(BOOL *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        *ret = scrollableOn(node(), 2) ? TRUE : FALSE;
        return S_OK;
    }
    STDMETHOD(get_HorizontalScrollPercent)(double *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        const UiaNode *n = node();
        // MSDN 口径：不可滚的轴给 `UIA_ScrollPatternNoScroll`(-1) 且 S_OK。
        *ret = scrollableOn(n, 1) ? uiaScrollPercent(n->scrollOffset, n->scrollMax) : -1.0;
        return S_OK;
    }
    STDMETHOD(get_VerticalScrollPercent)(double *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        const UiaNode *n = node();
        *ret = scrollableOn(n, 2) ? uiaScrollPercent(n->scrollOffset, n->scrollMax) : -1.0;
        return S_OK;
    }
    STDMETHOD(get_HorizontalViewSize)(double *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        const UiaNode *n = node();
        *ret = (n != nullptr && n->scrollAxis == 1)
                   ? uiaScrollViewSize(n->scrollViewportW, n->scrollMax)
                   : 100.0;
        return S_OK;
    }
    STDMETHOD(get_VerticalViewSize)(double *ret) override {
        if (ret == nullptr) return E_INVALIDARG;
        const UiaNode *n = node();
        *ret = (n != nullptr && n->scrollAxis == 2)
                   ? uiaScrollViewSize(n->scrollViewportH, n->scrollMax)
                   : 100.0;
        return S_OK;
    }
    STDMETHOD(Scroll)(ScrollAmount horizontal, ScrollAmount vertical) override {
        const UiaNode *n = node();
        if (n == nullptr) return UIA_E_ELEMENTNOTAVAILABLE;
        double h = -1.0, v = -1.0;
        if (n->scrollAxis == 1) {
            double cur = uiaScrollPercent(n->scrollOffset, n->scrollMax);
            if (cur >= 0.0) h = uiaScrollStep(cur, horizontal, uiaScrollViewSize(n->scrollViewportW, n->scrollMax));
        } else if (n->scrollAxis == 2) {
            double cur = uiaScrollPercent(n->scrollOffset, n->scrollMax);
            if (cur >= 0.0) v = uiaScrollStep(cur, vertical, uiaScrollViewSize(n->scrollViewportH, n->scrollMax));
        }
        if (h < 0.0 && v < 0.0) return UIA_E_NOTSUPPORTED;   // 两个方向都滚不动
        post(h, v);
        return S_OK;
    }
    STDMETHOD(SetScrollPercent)(double horizontal, double vertical) override {
        const UiaNode *n = node();
        if (n == nullptr) return UIA_E_ELEMENTNOTAVAILABLE;
        // -1 = 该轴不动。要动的轴必须真可滚，否则不假装成功。
        bool wantH = horizontal >= 0.0;
        bool wantV = vertical >= 0.0;
        if (!wantH && !wantV) return S_OK;   // 无操作是合法请求
        if (wantH && !(n->scrollAxis == 1 && n->scrollMax > 0.0)) return UIA_E_NOTSUPPORTED;
        if (wantV && !(n->scrollAxis == 2 && n->scrollMax > 0.0)) return UIA_E_NOTSUPPORTED;
        post(wantH ? (horizontal > 100.0 ? 100.0 : horizontal) : -1.0,
             wantV ? (vertical > 100.0 ? 100.0 : vertical) : -1.0);
        return S_OK;
    }

private:
    const UiaNode *node() const {
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
        return (m_index >= 0 && m_index < (int)nodes.size()) ? &nodes[m_index] : nullptr;
    }
    /** 该轴可滚？（轴匹配 且 有可滚空间）—— Scrollable / ViewSize / Percent 共用一份判据 */
    static bool scrollableOn(const UiaNode *n, int axis) {
        return n != nullptr && n->scrollAxis == axis && n->scrollMax > 0.0;
    }
    void post(double h, double v) {
        if (m_hwnd != NULL) {
            PostMessageW(m_hwnd, kMsgUiaScroll, (WPARAM)m_index,
                         (LPARAM)new std::pair<double, double>(h, v));
        }
    }
    WindowSlot &m_slot;
    HWND m_hwnd;
    int m_index;
};

class CProvider : public IRawElementProviderFragmentRoot {
public:
    /** @param hwnd 这棵树属于哪个窗口（多窗口时每窗一个 provider，各管各的树） */
    explicit CProvider(WindowSlot &slot, HWND hwnd, DriftDpiFn dpiFn, int index)
        : m_ref(1), m_slot(slot), m_hwnd(hwnd), m_dpiFn(dpiFn), m_index(index), m_simple(new SimpleAdapter(this)), m_frag(new FragmentAdapter(this)) {}
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
        CProvider *p = new CProvider(m_slot, m_hwnd, m_dpiFn, idx);
        IRawElementProviderFragment *frag = nullptr;
        p->QueryInterface(__uuidof(IRawElementProviderFragment), (void **)&frag);
        p->Release();
        return frag;
    }

    // 造一个"能当 IRawElementProviderSimple"的 provider（focus changed raise 用：
    // 通知里带的是焦点元素本身）。引用契约同 makeFragment。
    IRawElementProviderSimple *makeFragmentSimple(int idx) {
        CProvider *p = new CProvider(m_slot, m_hwnd, m_dpiFn, idx);
        IRawElementProviderSimple *simple = nullptr;
        p->QueryInterface(__uuidof(IRawElementProviderSimple), (void **)&simple);
        p->Release();
        return simple;
    }

    // IRawElementProviderFragmentRoot（本 SDK 只剩这两个纯虚）
    STDMETHOD(ElementProviderFromPoint)(double x, double y, IRawElementProviderFragment **ret) override {
        *ret = nullptr;
        // 入参是**屏幕**坐标（与 BoundingRectangle 同源）⇒ 必须复用 nodeScreenRect()。
        // 恒返回窗口根会让读屏的鼠标追踪把整窗读成一个整体 —— spec 把这里定为命中
        // 测试的唯一来源，客户端无法靠遍历补。取**最深**命中：前序扁平里"最后包含该点
        // 的"不等于最深，按 parent 链算深度；同深度取后出现的（后画的在上面）。
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
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
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
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
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
        if (m_index < 0 || m_index >= (int)nodes.size()) return S_OK;

        // 一行 = 一种能力。predicate 只读语义数据；新增 Pattern 加一行表项，
        // 不再往 if 链里插分支，也不在 PHP 侧推第二份「能力位」。
        struct Rule {
            PATTERNID id;
            bool (*applies)(const std::vector<UiaNode> &, int);
            IUnknown *(*make)(WindowSlot &, HWND, int);
        };
        static const Rule rules[] = {
            {UIA_InvokePatternId,
             [](const std::vector<UiaNode> &n, int i) {
                 long c = n[i].controlType;
                 // TreeItem 换了角色仍要能 Invoke（§65）
                 return c == UIA_ButtonControlTypeId || c == UIA_TreeItemControlTypeId;
             },
             [](WindowSlot &s, HWND h, int i) -> IUnknown * { return new InvokeAdapter(s, h, i); }},
            {UIA_ValuePatternId,
             [](const std::vector<UiaNode> &n, int i) { return n[i].controlType == UIA_EditControlTypeId; },
             [](WindowSlot &s, HWND h, int i) -> IUnknown * { return new ValueAdapter(s, h, i); }},
            {UIA_TogglePatternId,
             [](const std::vector<UiaNode> &n, int i) {
                 long c = n[i].controlType;
                 return c == UIA_CheckBoxControlTypeId || c == UIA_RadioButtonControlTypeId;
             },
             [](WindowSlot &s, HWND h, int i) -> IUnknown * { return new ToggleAdapter(s, h, i); }},
            {UIA_RangeValuePatternId,
             [](const std::vector<UiaNode> &n, int i) {
                 long c = n[i].controlType;
                 return c == UIA_SliderControlTypeId || c == UIA_ProgressBarControlTypeId;
             },
             [](WindowSlot &s, HWND h, int i) -> IUnknown * { return new RangeAdapter(s, h, i); }},
            {UIA_SelectionItemPatternId,
             [](const std::vector<UiaNode> &n, int i) { return n[i].selectState != 0; },
             [](WindowSlot &s, HWND h, int i) -> IUnknown * { return new SelectionItemAdapter(s, h, i); }},
            {UIA_ExpandCollapsePatternId,
             [](const std::vector<UiaNode> &n, int i) { return n[i].expandState != 0; },
             [](WindowSlot &s, HWND h, int i) -> IUnknown * { return new ExpandCollapseAdapter(s, h, i); }},
            {UIA_SelectionPatternId,
             [](const std::vector<UiaNode> &n, int i) {
                 for (int k = 0; k < (int)n.size(); k++) {
                     if (k != i && n[k].parent == i && n[k].selectState != 0) return true;
                 }
                 return false;
             },
             [](WindowSlot &s, HWND, int i) -> IUnknown * { return new SelectionAdapter(s, i); }},
            {UIA_ScrollPatternId,
             // 0 = 非滚动容器 ⇒ 不给 Pattern；1/2 = 滚动容器（含"装得下"那档）。
             [](const std::vector<UiaNode> &n, int i) { return n[i].scrollAxis != 0; },
             [](WindowSlot &s, HWND h, int i) -> IUnknown * { return new ScrollAdapter(s, h, i); }},
        };
        for (const Rule &r : rules) {
            if (r.id == id && r.applies(nodes, m_index)) {
                *ppv = r.make(m_slot, m_hwnd, m_index);
                break;
            }
        }
        return S_OK;
    }
    HRESULT simpleProperty(PROPERTYID id, VARIANT *out) {
        VariantInit(out);
        std::wstring name = L"Drift";
        long ctrl = UIA_CustomControlTypeId;
        bool enabled = true;
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
        // 本节点（窗口根 -1 / 越界 ⇒ nullptr）：新状态属性一律"没有就不答"，
        //   留 VT_EMPTY 让客户端按"该属性不支持"处理 —— 硬报 false 等于对每个
        //   节点声称"未选中/不可展开"。
        const UiaNode *self = nullptr;
        if (m_index == -1) {
            ctrl = UIA_WindowControlTypeId;
        } else if (m_index >= 0 && m_index < (int)nodes.size()) {
            self = &nodes[m_index];
            name = self->name;
            ctrl = self->controlType;
            enabled = self->enabled;
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
            // ★ 不实现 ⇒ 客户端默认 false ⇒ 读屏对着**窗口外**画焦点框
            //   （滚出视口的行仍在语义树里且仍报矩形，2026-10-30 实测 y=1452 / 客户区底 712）。
            //   判据是"矩形与客户区相交"，几何取 `nodeScreenRect()`，不用"宽度为 0"判
            //   —— 零面积分组只是画不出东西，不代表不在屏上。窗口根（-1）恒在屏上。
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
        } else if (id == UIA_HasKeyboardFocusPropertyId) {
            // §65：数据一直在（`focused` 被 `GetFocus()` 用来做 Fragment 导航），
            //   只是从来没在这里答过 ⇒ 客户端读到 VT_EMPTY 按 false 处理，
            //   读屏不播报焦点、自动化写不出"焦点在我期望的地方"这种判据。
            out->vt = VT_BOOL;
            out->boolVal = (self != nullptr && self->focused) ? VARIANT_TRUE : VARIANT_FALSE;
        } else if (id == UIA_IsKeyboardFocusablePropertyId) {
            out->vt = VT_BOOL;
            out->boolVal = (self != nullptr && self->focusable) ? VARIANT_TRUE : VARIANT_FALSE;
        } else if (id == UIA_ToggleToggleStatePropertyId) {
            // 只对勾选族答（与 `TogglePattern` 同一族）。其它控件留 VT_EMPTY。
            if (self != nullptr
                && (ctrl == UIA_CheckBoxControlTypeId || ctrl == UIA_RadioButtonControlTypeId)) {
                out->vt = VT_I4;
                out->lVal = (long)toggleStateOf(*self);
            }
        } else if (id == UIA_ValueValuePropertyId) {
            if (self != nullptr && ctrl == UIA_EditControlTypeId) {
                out->vt = VT_BSTR;
                out->bstrVal = SysAllocString(self->value.c_str());
            }
        } else if (id == UIA_SelectionItemIsSelectedPropertyId) {
            // 三态：0 = 这个角色没有选中概念 ⇒ 不答（否则每个按钮都说"未选中"）。
            if (self != nullptr && self->selectState != 0) {
                out->vt = VT_BOOL;
                out->boolVal = self->selectState == 2 ? VARIANT_TRUE : VARIANT_FALSE;
            }
        } else if (id == UIA_ExpandCollapseExpandCollapseStatePropertyId) {
            if (self != nullptr && self->expandState != 0) {
                out->vt = VT_I4;
                out->lVal = self->expandState == 2 ? ExpandCollapseState_Expanded
                                                   : ExpandCollapseState_Collapsed;
            }
        } else if (id == UIA_LevelPropertyId) {
            if (self != nullptr && self->level > 0) {
                out->vt = VT_I4;
                out->lVal = self->level;
            }
        } else if (id == UIA_HelpTextPropertyId) {
            if (self != nullptr && !self->hint.empty()) {
                out->vt = VT_BSTR;
                out->bstrVal = SysAllocString(self->hint.c_str());
            }
        } else if (id == UIA_AutomationIdPropertyId) {
            if (self != nullptr && !self->automationId.empty()) {
                out->vt = VT_BSTR;
                out->bstrVal = SysAllocString(self->automationId.c_str());
            }
        } else if (id == UIA_AcceleratorKeyPropertyId) {
            if (self != nullptr && !self->acceleratorKey.empty()) {
                out->vt = VT_BSTR;
                out->bstrVal = SysAllocString(self->acceleratorKey.c_str());
            }
        } else if (id == UIA_ScrollHorizontalScrollPercentPropertyId && self != nullptr && self->scrollAxis == 1) {
            out->vt = VT_R8;
            out->dblVal = uiaScrollPercent(self->scrollOffset, self->scrollMax);
        } else if (id == UIA_ScrollVerticalScrollPercentPropertyId && self != nullptr && self->scrollAxis == 2) {
            out->vt = VT_R8;
            out->dblVal = uiaScrollPercent(self->scrollOffset, self->scrollMax);
        } else if (id == UIA_ScrollHorizontalViewSizePropertyId && self != nullptr && self->scrollAxis == 1) {
            out->vt = VT_R8;
            out->dblVal = uiaScrollViewSize(self->scrollViewportW, self->scrollMax);
        } else if (id == UIA_ScrollVerticalViewSizePropertyId && self != nullptr && self->scrollAxis == 2) {
            out->vt = VT_R8;
            out->dblVal = uiaScrollViewSize(self->scrollViewportH, self->scrollMax);
        } else if (id == UIA_ScrollHorizontallyScrollablePropertyId && self != nullptr && self->scrollAxis == 1) {
            out->vt = VT_BOOL;
            out->boolVal = self->scrollMax > 0.0 ? VARIANT_TRUE : VARIANT_FALSE;
        } else if (id == UIA_ScrollVerticallyScrollablePropertyId && self != nullptr && self->scrollAxis == 2) {
            out->vt = VT_BOOL;
            out->boolVal = self->scrollMax > 0.0 ? VARIANT_TRUE : VARIANT_FALSE;
        } else if (id == UIA_IsScrollPatternAvailablePropertyId) {
            // ★ 必须由 **provider 自己**回答；只靠 `GetPatternProvider` 让 UIA 合成时，
            //   属性面读得到 True，但 ScrollPattern 代理内部按 provider 原值（VT_EMPTY）
            //   判定"不可用" ⇒ 客户端 get_CurrentVerticalScrollPercent 恒 NOTSUPPORTED
            //   （2026-11-23 真机实测：provider 侧日志证明 getter 返回 S_OK + 正确值，
            //   客户端仍拿到 UIA_E_NOTSUPPORTED）。
            out->vt = VT_BOOL;
            out->boolVal = (self != nullptr && self->scrollAxis != 0) ? VARIANT_TRUE : VARIANT_FALSE;
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
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
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
     * 节点矩形：逻辑客户区 → 屏幕物理像素。false = 算不出来（窗口销毁中）。
     * `BoundingRectangle` 与 `IsOffscreen` 必须同源，否则客户端读到"矩形在屏上、
     * offscreen 说不在"。DPI 现查而非读 `slot.dpi`（后者靠 `WM_DPICHANGED` 落地，
     * 跨屏拖动那几帧仍是旧值）。客户区→屏幕必须 `ClientToScreen`，加 `GetWindowRect()`
     * 原点会把边框算进去（2026-10-30 前这里给的是客户区坐标，放大镜跟随全偏）。
     */
    bool nodeScreenRect(int index, RECT *out) {
        if (m_hwnd == nullptr) {
            return false;
        }
        const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
        if (index < 0 || index >= (int)nodes.size()) {
            return false;
        }

        UINT dpi = m_dpiFn(m_hwnd);
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
        *ret = new CProvider(m_slot, m_hwnd, m_dpiFn, -1);   // CProvider* → FragmentRoot*（upcast，直接基类）
        return S_OK;
    }

private:
    ULONG m_ref;
    WindowSlot &m_slot;
    HWND m_hwnd;
    DriftDpiFn m_dpiFn;
    int m_index;
    SimpleAdapter *m_simple;
    FragmentAdapter *m_frag;
};

// ── adapter 方法定义（CProvider 之后，故可调其 public simple*/frag*）──
STDMETHODIMP SelectionAdapter::GetSelection(SAFEARRAY **ret) {
    if (ret == nullptr) return E_INVALIDARG;
    *ret = nullptr;
    const std::vector<UiaNode> &nodes = m_slot.uiaNodes;
    std::vector<int> picked;
    for (int k = 0; k < (int)nodes.size(); k++) {
        if (k != m_index && nodes[k].parent == m_index && nodes[k].selectState == 2) {
            picked.push_back(k);
        }
    }
    SAFEARRAY *sa = SafeArrayCreateVector(VT_UNKNOWN, 0, (ULONG)picked.size());
    if (sa == nullptr) return E_OUTOFMEMORY;
    for (size_t i = 0; i < picked.size(); i++) {
        LONG at = (LONG)i;
        IRawElementProviderSimple *simple = m_slot.uiaProvider != nullptr
            ? m_slot.uiaProvider->makeFragmentSimple(picked[i]) : nullptr;
        SafeArrayPutElement(sa, &at, simple);   // SAFEARRAY 自己 AddRef
        if (simple != nullptr) simple->Release();
    }
    *ret = sa;
    return S_OK;
}

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
// ★ provider 是每窗状态：住在 `WindowSlot::uiaProvider`，provider 自己持 `m_slot`。


}   // namespace drift::win32::uia

// ═══════════════════════════════════════════════════════════ 对外入口 ══

using namespace drift::win32::uia;

long driftUiaControlTypeFor(const std::string &role) {
    return uiaControlTypeFor(role);
}

void driftUiaAttach(WindowSlot &slot, HWND hwnd, DriftDpiFn dpiFn) {
    // 根 provider：m_index = -1 = 窗口根
    slot.uiaProvider = new CProvider(slot, hwnd, dpiFn, -1);
}

void driftUiaDetach(WindowSlot &slot) {
    slot.uiaNodes.clear();
    slot.uiaNodes.shrink_to_fit();
    if (slot.uiaProvider != nullptr) {
        // Release 而不是 delete：UIA 系统可能仍持一份引用（它是 COM 对象）
        slot.uiaProvider->Release();
        slot.uiaProvider = nullptr;
    }
}

bool driftUiaHandleGetObject(WindowSlot &slot, HWND hwnd, WPARAM wp, LPARAM lp, LRESULT *result) {
    if (lp != (LPARAM)UiaRootObjectId || slot.uiaProvider == nullptr) {
        return false;
    }
    IRawElementProviderSimple *simple = nullptr;
    if (FAILED(slot.uiaProvider->QueryInterface(__uuidof(IRawElementProviderSimple), (void **)&simple))) {
        return false;
    }
    *result = UiaReturnRawElementProvider(hwnd, wp, lp, simple);

    return true;
}

void driftUiaApply(WindowSlot &slot, const std::vector<UiaNode> &nodes) {
    // 选中变化要在覆盖前抓快照：结构事件只看节点数，而选中不改节点数
    std::vector<std::wstring> oldKeys, oldIdent, oldName, oldValue;
    std::vector<int> oldSelect;
    std::vector<char> oldEnabled, oldFocusable;
    std::vector<double> oldScroll;   // 主轴偏移（IScroll 位置事件用）
    size_t n = slot.uiaNodes.size();
    oldKeys.reserve(n);
    oldIdent.reserve(n);
    oldSelect.reserve(n);
    oldName.reserve(n);
    oldValue.reserve(n);
    oldEnabled.reserve(n);
    oldFocusable.reserve(n);
    oldScroll.reserve(n);
    for (const UiaNode &o : slot.uiaNodes) {
        oldKeys.push_back(o.key);
        oldIdent.push_back(uiaNodeIdentity(o));
        oldSelect.push_back(o.selectState);
        oldName.push_back(o.name);
        oldValue.push_back(o.value);
        oldEnabled.push_back(o.enabled ? 1 : 0);
        oldFocusable.push_back(o.focusable ? 1 : 0);
        oldScroll.push_back(o.scrollOffset);
    }

    slot.uiaNodes = nodes;

    const int cnt = (int)slot.uiaNodes.size();
    if (cnt != slot.lastNodeCount) {
        slot.lastNodeCount = cnt;
        if (slot.uiaProvider != nullptr) {
            IRawElementProviderSimple *simple = nullptr;
            if (SUCCEEDED(slot.uiaProvider->QueryInterface(__uuidof(IRawElementProviderSimple), (void **)&simple)) && simple != nullptr) {
                UiaRaiseStructureChangedEvent(simple, StructureChangeType_ChildrenInvalidated, nullptr, 0);
                simple->Release();
            }
        }
    }

    int focusIdx = -1;
    for (size_t k = 0; k < slot.uiaNodes.size(); k++) {
        if (slot.uiaNodes[k].focused) { focusIdx = (int)k; break; }
    }
    if (focusIdx != slot.lastFocusIdx) {
        slot.lastFocusIdx = focusIdx;
        if (focusIdx >= 0 && slot.uiaProvider != nullptr) {
            IRawElementProviderSimple *fsimple = slot.uiaProvider->makeFragmentSimple(focusIdx);
            if (fsimple != nullptr) {
                UiaRaiseAutomationEvent(fsimple, UIA_AutomationFocusChangedEventId);
                fsimple->Release();
            }
        }
    }

    // 选中事件：按 key 认节点、只在同一份节点上 raise（结构变了交给上面那条）
    if (slot.uiaProvider != nullptr && oldKeys.size() == slot.uiaNodes.size()) {
        for (size_t k = 0; k < slot.uiaNodes.size(); k++) {
            if (slot.uiaNodes[k].key.empty()) continue;
            if (oldKeys[k] != slot.uiaNodes[k].key) continue;
            if (oldSelect[k] == 2 || slot.uiaNodes[k].selectState != 2) continue;
            IRawElementProviderSimple *ssimple = slot.uiaProvider->makeFragmentSimple((int)k);
            if (ssimple != nullptr) {
                UiaRaiseAutomationEvent(ssimple, UIA_SelectionItem_ElementSelectedEventId);
                ssimple->Release();
            }
        }
    }

    // 属性变更：读屏客户端订阅的是**事件**，不是快照。按 key 认节点，
    // 结构变化已经由 ChildrenInvalidated 覆盖，这里不重复比较。
    if (slot.uiaProvider != nullptr && oldKeys.size() == slot.uiaNodes.size()) {
        for (size_t k = 0; k < slot.uiaNodes.size(); k++) {
            const UiaNode &now = slot.uiaNodes[k];
            std::wstring id = uiaNodeIdentity(now);
            if (id.empty() || oldIdent[k] != id) continue;
            bool nameChanged = oldName[k] != now.name;
            bool valueChanged = oldValue[k] != now.value;
            bool enabledChanged = (oldEnabled[k] != 0) != now.enabled;
            bool focusableChanged = (oldFocusable[k] != 0) != now.focusable;
            // 滚动位置：只对滚动容器发，且按主轴挑属性 ID（读屏据事件更新"已滚到 x%"）。
            bool scrollChanged = now.scrollAxis != 0 && oldScroll[k] != now.scrollOffset;
            if (!nameChanged && !valueChanged && !enabledChanged && !focusableChanged && !scrollChanged) continue;

            IRawElementProviderSimple *simple = slot.uiaProvider->makeFragmentSimple((int)k);
            if (simple == nullptr) continue;
            if (nameChanged) {
                uiaRaiseProperty(simple, UIA_NamePropertyId, uiaVarBstr(oldName[k]), uiaVarBstr(now.name));
            }
            if (enabledChanged) {
                uiaRaiseProperty(simple, UIA_IsEnabledPropertyId,
                                 uiaVarBool(oldEnabled[k] != 0), uiaVarBool(now.enabled));
            }
            if (focusableChanged) {
                uiaRaiseProperty(simple, UIA_IsKeyboardFocusablePropertyId,
                                 uiaVarBool(oldFocusable[k] != 0), uiaVarBool(now.focusable));
            }
            if (valueChanged) {
                uiaRaiseProperty(simple, UIA_ValueValuePropertyId, uiaVarBstr(oldValue[k]), uiaVarBstr(now.value));
                // 文本控件必须同时发 TextChanged：官方要求两个事件都支持。
                if (now.controlType == UIA_EditControlTypeId) {
                    UiaRaiseAutomationEvent(simple, UIA_Text_TextChangedEventId);
                }
            }
            if (scrollChanged) {
                // 变化的"前后值"是百分比（与属性面同公式），不是像素偏移 —— 客户端比对的是同一量。
                VARIANT before, after;
                before.vt = VT_R8;
                before.dblVal = uiaScrollPercent(oldScroll[k], now.scrollMax);
                after.vt = VT_R8;
                after.dblVal = uiaScrollPercent(now.scrollOffset, now.scrollMax);
                // 滚动位置变化 = ScrollPercent 属性的 PropertyChanged（UIA 没有独立的"位置事件"）。
                PROPERTYID scrollProp = now.scrollAxis == 1 ? UIA_ScrollHorizontalScrollPercentPropertyId
                                                            : UIA_ScrollVerticalScrollPercentPropertyId;
                uiaRaiseProperty(simple, scrollProp, before, after);
            }
            simple->Release();
        }
    }
}
