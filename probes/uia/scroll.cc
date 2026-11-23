// IScrollProvider 真机判据（probes/uia）：连一个**已在运行**的窗口，用
// `IUIAutomationScrollPattern` 读写滚动状态。
//
// ★ 为什么不用 python 裸 vtable：`GetCurrentPattern` 返回的对象在这个 provider 上
//   的实际槽位与 IDL 对不上（2026-11-23 全槽位扫描：5=H% / 6=V% / 7=HView /
//   8=VView，前面多两个槽位），手写偏移读出来的值全是错位的，判据随之假红/假绿。
//   走 C++ 由**编译器**按 SDK 头文件解析 vtable，不存在错位这回事。
//
// 复跑：bash probes/uia/build.sh && ./probes/uia/uia_scroll.exe "Drift 教程"
#include <windows.h>
#include <objbase.h>
#include <UIAutomation.h>
#include <cmath>
#include <cstdio>
#include <string>
#include <vector>

static IUIAutomationElement *g_found = nullptr;

/** printf 在控制台代码页下会把中文名吞成空白，统一转 UTF-8 再打 */
static std::string u8(const std::wstring &s) {
    if (s.empty()) return std::string();
    int n = WideCharToMultiByte(CP_UTF8, 0, s.c_str(), (int) s.size(), nullptr, 0, nullptr, nullptr);
    if (n <= 0) return std::string();
    std::string out((size_t) n, '\0');
    WideCharToMultiByte(CP_UTF8, 0, s.c_str(), (int) s.size(), &out[0], n, nullptr, nullptr);
    return out;
}

/** 叶子子树快照：只认「无子元素」的节点，用于比较滚动前后的屏幕坐标 */
struct Leaf {
    std::wstring name;
    int top = 0;
    int height = 0;
    bool offscreen = false;
};

static void collectLeaves(IUIAutomationTreeWalker *w, IUIAutomationElement *el,
                          std::vector<Leaf> &out, int depth) {
    if (depth > 8 || el == nullptr) return;
    IUIAutomationElement *child = nullptr;
    if (SUCCEEDED(w->GetFirstChildElement(el, &child)) && child != nullptr) {
        while (child != nullptr) {
            collectLeaves(w, child, out, depth + 1);
            IUIAutomationElement *next = nullptr;
            w->GetNextSiblingElement(child, &next);
            child->Release();
            child = next;
        }
        return;
    }
    Leaf lf;
    BSTR nm = nullptr;
    el->get_CurrentName(&nm);
    if (nm != nullptr) {
        lf.name = nm;
        SysFreeString(nm);
    }
    RECT rc{0, 0, 0, 0};
    el->get_CurrentBoundingRectangle(&rc);
    lf.top = rc.top;
    lf.height = rc.bottom - rc.top;
    BOOL off = FALSE;
    el->get_CurrentIsOffscreen(&off);
    lf.offscreen = off != FALSE;
    out.push_back(lf);
}

static bool findScroll(IUIAutomationTreeWalker *w, IUIAutomationElement *el, int depth) {
    if (depth > 12 || el == nullptr) return false;
    IUIAutomationScrollPattern *pat = nullptr;
    if (SUCCEEDED(el->GetCurrentPattern(UIA_ScrollPatternId, (IUnknown **) &pat)) && pat != nullptr) {
        pat->Release();
        g_found = el;
        el->AddRef();
        return true;
    }
    IUIAutomationElement *child = nullptr;
    if (FAILED(w->GetFirstChildElement(el, &child)) || child == nullptr) return false;
    while (child != nullptr) {
        bool hit = findScroll(w, child, depth + 1);
        if (hit) {
            child->Release();
            return true;
        }
        IUIAutomationElement *next = nullptr;
        w->GetNextSiblingElement(child, &next);
        child->Release();
        child = next;
    }
    return false;
}

int wmain(int argc, wchar_t **argv) {
    const wchar_t *title = argc > 1 ? argv[1] : L"Drift 教程";
    CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED);

    IUIAutomation *uia = nullptr;
    HRESULT hr = CoCreateInstance(CLSID_CUIAutomation, nullptr, CLSCTX_INPROC_SERVER,
                                  IID_IUIAutomation, (void **) &uia);
    if (FAILED(hr) || uia == nullptr) {
        printf("CoCreateInstance hr=%08lX\n", (unsigned long) hr);
        return 2;
    }

    HWND hwnd = FindWindowW(nullptr, title);
    if (hwnd == nullptr) {
        printf("window not found: %ls\n", title);
        return 2;
    }

    IUIAutomationElement *root = nullptr;
    uia->ElementFromHandle(hwnd, &root);
    IUIAutomationTreeWalker *w = nullptr;
    uia->get_ControlViewWalker(&w);

    if (!findScroll(w, root, 0)) {
        printf("no ScrollPattern in tree\n");
        return 1;
    }

    BSTR name = nullptr;
    g_found->get_CurrentName(&name);
    CONTROLTYPEID ctrl = 0;
    g_found->get_CurrentControlType(&ctrl);
    printf("element name=%s ctrl=%d\n", u8(name != nullptr ? name : L"").c_str(), (int) ctrl);
    if (name != nullptr) SysFreeString(name);

    IUIAutomationScrollPattern *pat = nullptr;
    hr = g_found->GetCurrentPattern(UIA_ScrollPatternId, (IUnknown **) &pat);
    if (FAILED(hr) || pat == nullptr) {
        printf("GetCurrentPattern hr=%08lX\n", (unsigned long) hr);
        return 1;
    }

    double vpct = -999.0, vview = -999.0, hview = -999.0, hpct = -999.0;
    BOOL vs = FALSE, hs = TRUE;
    HRESULT r1 = pat->get_CurrentVerticalScrollPercent(&vpct);
    HRESULT r2 = pat->get_CurrentVerticalViewSize(&vview);
    HRESULT r3 = pat->get_CurrentVerticallyScrollable(&vs);
    HRESULT r4 = pat->get_CurrentHorizontalViewSize(&hview);
    HRESULT r5 = pat->get_CurrentHorizontallyScrollable(&hs);
    HRESULT r6 = pat->get_CurrentHorizontalScrollPercent(&hpct);
    printf("read V%%=%g(hr=%08lX) VView=%g VScrollable=%d | H%%=%g(hr=%08lX) HView=%g HScrollable=%d\n",
           vpct, (unsigned long) r1, vview, (int) vs,
           hpct, (unsigned long) r6, hview, (int) hs);
    printf("  hr: VView=%08lX VScrollable=%08lX HView=%08lX HScrollable=%08lX\n",
           (unsigned long) r2, (unsigned long) r3, (unsigned long) r4, (unsigned long) r5);

    HRESULT rs = pat->SetScrollPercent(UIA_ScrollPatternNoScroll, 60.0);
    printf("SetScrollPercent(NoScroll, 60) hr=%08lX\n", (unsigned long) rs);
    Sleep(900);
    double after = -999.0;
    HRESULT r7 = pat->get_CurrentVerticalScrollPercent(&after);
    printf("after V%%=%g(hr=%08lX)\n", after, (unsigned long) r7);

    // ── 段 B：写回是否**真的驱动了 UI** ──────────────────────────────
    // 只验证「读回 60」是不够的：provider 完全可以把自己存的值原样吐回来。
    // 真判据是视口内容位移 + 视口外元素是否转为可见。
    std::vector<Leaf> before, afterView;
    pat->SetScrollPercent(UIA_ScrollPatternNoScroll, 0.0);  // 固定起点=顶部
    Sleep(600);
    collectLeaves(w, g_found, before, 0);
    pat->SetScrollPercent(UIA_ScrollPatternNoScroll, 100.0);
    Sleep(900);
    collectLeaves(w, g_found, afterView, 0);

    int moved = 0;
    int becameVisible = 0;
    for (size_t i = 0; i < before.size() && i < afterView.size(); ++i) {
        if (!before[i].name.empty() && before[i].name == afterView[i].name) {
            int d = afterView[i].top - before[i].top;
            if (std::abs(d) >= 4) {
                printf("  moved: %s top %d -> %d\n", u8(before[i].name).c_str(),
                       before[i].top, afterView[i].top);
                ++moved;
            }
            if (before[i].offscreen && !afterView[i].offscreen) ++becameVisible;
        }
    }
    printf("  leaves=%zu/%zu moved=%d becameVisible=%d\n",
           before.size(), afterView.size(), moved, becameVisible);

    bool uiScrolled = moved > 0 || becameVisible > 0;
    bool ok = r1 == S_OK && r2 == S_OK && r3 == S_OK && vs != FALSE
              && vpct >= 0.0 && vpct <= 100.0 && vview > 0.0 && vview < 100.0
              && rs == S_OK && std::fabs(after - 60.0) < 2.0
              && uiScrolled;
    printf("=== IScroll %s (before=%g after=%g uiScrolled=%d) ===\n",
           ok ? "PASS" : "FAIL", vpct, after, uiScrolled ? 1 : 0);
    return ok ? 0 : 1;
}
