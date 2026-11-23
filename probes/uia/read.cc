// UIA 跨进程客户端（probes/uia，P4 结构变化 + B 焦点变化事件真机闭环）：自起
// users.exe，① 窗口一建立就订阅 StructureChanged（Loading 中才能抓到
// Loading→Ready 的 raise），② 等结构变化事件，③ 发 VK_TAB 切焦点（TextField →
// 刷新 → 新建用户…），④ 等 FocusChanged 事件，⑤ FindAll(Descendants) 验树导航。
// 复跑：bash probes/uia/build.sh && ./probes/uia/uia_read.exe
#include <windows.h>
#include <objbase.h>
#include <UIAutomationCore.h>
#include <UIAutomationClient.h>
#include <uiautomation.h>
#include <cstdio>

#pragma comment(lib, "ole32.lib")
#pragma comment(lib, "oleaut32.lib")
#pragma comment(lib, "uuid.lib")

static const wchar_t *USERS_EXE = L"D:\\TypePHP\\Drift2\\users.exe";
static const wchar_t *USERS_DIR = L"D:\\TypePHP\\Drift2";
static const wchar_t *USERS_TITLE = L"用户管理";

class StructHandler : public IUIAutomationStructureChangedEventHandler {
public:
    StructHandler() : m_ref(1), m_got(false), m_hits(0) {}
    STDMETHOD(QueryInterface)(REFIID riid, void **ppv) override {
        if (ppv == nullptr) return E_INVALIDARG;
        if (riid == __uuidof(IUnknown) || riid == __uuidof(IUIAutomationStructureChangedEventHandler)) {
            *ppv = this;
            AddRef();
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
    STDMETHOD(HandleStructureChangedEvent)(IUIAutomationElement *, StructureChangeType, SAFEARRAY *) override {
        m_got = true;
        m_hits++;
        return S_OK;
    }
    bool got() const { return m_got; }
    int hits() const { return m_hits; }

private:
    ULONG m_ref;
    bool m_got;
    int m_hits;
};

class FocusHandler : public IUIAutomationFocusChangedEventHandler {
public:
    FocusHandler() : m_ref(1), m_hits(0) {}
    STDMETHOD(QueryInterface)(REFIID riid, void **ppv) override {
        if (ppv == nullptr) return E_INVALIDARG;
        if (riid == __uuidof(IUnknown) || riid == __uuidof(IUIAutomationFocusChangedEventHandler)) {
            *ppv = this;
            AddRef();
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
    STDMETHOD(HandleFocusChangedEvent)(IUIAutomationElement *) override {
        m_hits++;
        return S_OK;
    }
    int hits() const { return m_hits; }

private:
    ULONG m_ref;
    int m_hits;
};

int main() {
    CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED);

    STARTUPINFOW si = {};
    si.cb = sizeof(si);
    PROCESS_INFORMATION pi = {};
    if (!CreateProcessW(USERS_EXE, nullptr, nullptr, nullptr, FALSE, 0, nullptr, USERS_DIR, &si, &pi)) {
        printf("[uia-read] CreateProcess users.exe failed (%lu)\n", GetLastError());
        return 2;
    }
    HWND hwnd = nullptr;
    for (int i = 0; i < 100 && hwnd == nullptr; i++) {
        hwnd = FindWindowW(nullptr, USERS_TITLE);
        if (hwnd == nullptr) Sleep(100);
    }
    if (hwnd == nullptr) {
        printf("[uia-read] users window not found\n");
        TerminateProcess(pi.hProcess, 1);
        return 2;
    }

    IUIAutomation *a = nullptr;
    CoCreateInstance(CLSID_CUIAutomation, nullptr, CLSCTX_INPROC_SERVER, IID_IUIAutomation, (void **)&a);
    IUIAutomationElement *root = nullptr;
    if (a == nullptr || !SUCCEEDED(a->ElementFromHandle(hwnd, &root)) || root == nullptr) {
        printf("[uia-read] ElementFromHandle failed\n");
        if (a) a->Release();
        TerminateProcess(pi.hProcess, 1);
        return 2;
    }

    // ① 窗口一建立就订阅（此刻还在 Loading ⇒ 能抓到 Loading→Ready 的结构变化 raise）。
    StructHandler handler;
    HRESULT hrSub = a->AddStructureChangedEventHandler(root, TreeScope_Subtree, nullptr, &handler);
    printf("[uia-read] subscribed hr=0x%08lx\n", hrSub);

    // ①b 焦点变化订阅（新版 SDK 2 参、无 cookie；全局 scope，桌面焦点变化都会来，
    //     本进程只起了 users 一个 GUI ⇒ 命中即 users 的焦点切换）。
    FocusHandler focus;
    HRESULT hrFocus = a->AddFocusChangedEventHandler(nullptr, &focus);
    printf("[uia-read] focus-subscribed hr=0x%08lx\n", hrFocus);

    // ② 等事件：消息循环泵 COM 回调，覆盖 users 的 Loading→Ready（结构变化）。
    DWORD start = GetTickCount();
    int lastCount = -2;
    DWORD lastPoll = 0;
    while (GetTickCount() - start < 3500) {   // 等满覆盖 Loading→Ready，收集多次 raise
        MSG msg;
        while (PeekMessageW(&msg, nullptr, 0, 0, PM_REMOVE)) {
            TranslateMessage(&msg);
            DispatchMessageW(&msg);
        }
        MsgWaitForMultipleObjects(0, nullptr, FALSE, 100, QS_ALLINPUT);
        if (GetTickCount() - lastPoll >= 500) {
            lastPoll = GetTickCount();
            int c = -1;
            IUIAutomationCondition *c2 = nullptr;
            if (SUCCEEDED(a->CreateTrueCondition(&c2)) && c2 != nullptr) {
                IUIAutomationElementArray *ar = nullptr;
                if (SUCCEEDED(root->FindAll(TreeScope_Descendants, c2, &ar)) && ar != nullptr) {
                    int l = 0;
                    if (SUCCEEDED(ar->get_Length(&l))) c = l;
                    ar->Release();
                }
                c2->Release();
            }
            if (c != lastCount) {
                printf("[uia-read]   poll count=%d (t=%lums hits=%d)\n", c, GetTickCount() - start, handler.hits());
                lastCount = c;
            }
        }
    }
    printf("[uia-read] event: structureChanged hits=%d (waited %lums)\n", handler.hits(), GetTickCount() - start);

    // ③ 发 VK_TAB 切焦点（WndProc WM_KEYDOWN → dispatchKey → FocusManager::focusNext）。
    //    users 的 UsersPage 有 TextField + 多个 Button（可聚焦）⇒ Tab 即焦点切换。
    for (int t = 0; t < 6; t++) {
        PostMessageW(hwnd, WM_KEYDOWN, VK_TAB, 0);
        Sleep(300);
        MSG msg;
        while (PeekMessageW(&msg, nullptr, 0, 0, PM_REMOVE)) {
            TranslateMessage(&msg);
            DispatchMessageW(&msg);
        }
        MsgWaitForMultipleObjects(0, nullptr, FALSE, 50, QS_ALLINPUT);
        MSG msg2;
        while (PeekMessageW(&msg2, nullptr, 0, 0, PM_REMOVE)) {
            TranslateMessage(&msg2);
            DispatchMessageW(&msg2);
        }
        MsgWaitForMultipleObjects(0, nullptr, FALSE, 50, QS_ALLINPUT);
    }

    // ④ 等 FocusChanged（Tab 的焦点切换经 push 时 focused 索引变化 ⇒ C 侧 raise）。
    DWORD fStart = GetTickCount();
    while (focus.hits() == 0 && GetTickCount() - fStart < 3000) {
        MSG msg;
        while (PeekMessageW(&msg, nullptr, 0, 0, PM_REMOVE)) {
            TranslateMessage(&msg);
            DispatchMessageW(&msg);
        }
        MsgWaitForMultipleObjects(0, nullptr, FALSE, 100, QS_ALLINPUT);
    }
    printf("[uia-read] event: focusChanged hits=%d\n", focus.hits());

    // ③ 事件之后 FindAll（此刻应已 Ready ⇒ 遍历整棵语义树）。
    int nodeCount = -1, namesGot = 0;
    bool anyRealName = false;
    wchar_t names[16][96];
    IUIAutomationCondition *cond = nullptr;
    if (SUCCEEDED(a->CreateTrueCondition(&cond)) && cond != nullptr) {
        IUIAutomationElementArray *arr = nullptr;
        if (SUCCEEDED(root->FindAll(TreeScope_Descendants, cond, &arr)) && arr != nullptr) {
            int len = 0;
            if (SUCCEEDED(arr->get_Length(&len))) nodeCount = len;
            for (int i = 0; i < nodeCount && namesGot < 16; i++) {
                IUIAutomationElement *e = nullptr;
                if (SUCCEEDED(arr->GetElement(i, &e)) && e != nullptr) {
                    VARIANT nv;
                    VariantInit(&nv);
                    if (SUCCEEDED(e->GetCurrentPropertyValue(UIA_NamePropertyId, &nv)) && nv.vt == VT_BSTR && nv.bstrVal && nv.bstrVal[0] != L'\0') {
                        wcsncpy_s(names[namesGot], 95, nv.bstrVal, _TRUNCATE);
                        if (wcscmp(nv.bstrVal, L"Drift") != 0) anyRealName = true;
                        namesGot++;
                    }
                    VariantClear(&nv);
                    e->Release();
                }
            }
            arr->Release();
        }
        cond->Release();
    }
    printf("[uia-read] tree: descendants=%d namesGot=%d\n", nodeCount, namesGot);

    bool pass = (handler.got() && focus.hits() >= 1 && nodeCount >= 1 && anyRealName);
    if (pass) {
        printf("[uia-read] PASS — 结构变化 + 焦点变化事件都收到 + 树导航遍历整棵语义树\n");
    } else {
        printf("[uia-read] FAIL — event=%d focus=%d tree(desc=%d real=%d)\n",
               handler.got() ? 1 : 0, focus.hits(), nodeCount, anyRealName ? 1 : 0);
    }

    a->RemoveFocusChangedEventHandler(&focus);
    a->RemoveStructureChangedEventHandler(root, &handler);
    root->Release();
    a->Release();
    SendMessageW(hwnd, WM_CLOSE, 0, 0);
    WaitForSingleObject(pi.hProcess, 4000);
    TerminateProcess(pi.hProcess, 0);
    CloseHandle(pi.hThread);
    CloseHandle(pi.hProcess);
    CoUninitialize();
    return pass ? 0 : 1;
}
