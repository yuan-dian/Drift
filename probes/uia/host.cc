// P1 UIA 可行性探针（probes/uia，纯 C++，不依赖 tpc / phpx）。
//
// 目的：验证 Win32 自绘窗口能挂自定义 UIA provider，且被 UIA 客户端读到 ——
// 这是 Drift 壳 UIA 桥接可不可行的判据。形态：
//   · 后台线程建窗口 + 消息循环 + provider（ServerSideProvider）；
//   · WndProc 处理 WM_GETOBJECT（lParam == UiaRootObjectId）⇒ UiaReturnRawElementProvider；
//   · 主线程 CoCreateInstance(CUIAutomation) → ElementFromHandle(hwnd) → 读 Name / ControlType；
//   · 断言 Name == "DriftApp" 且 ControlType == Window ⇒ PASS（exit 0）。
//
// ★ 本 SDK（10.0.26100）UIA 接口扁平化：Fragment / FragmentRoot 都直接继承 IUnknown，
//   Simple 吸收了 get_HostRawElementProvider ⇒ 多继承会 IUnknown 歧义。P1 只验"根属性
//   可达"，故 provider 只实现 IRawElementProviderSimple（UiaReturnRawElementProvider 收的
//   正是它）；Fragment/FragmentRoot 的树导航归 P2。
//
// 若 PASS ⇒ §9 无障碍可从"数据层"推到"屏幕阅读器可用"；数据流（PHP SemanticsTree →
// C 侧节点数组）归 P2。复跑：bash probes/uia/build.sh && ./probes/uia/uia_probe.exe
#include <windows.h>
#include <objbase.h>
#include <UIAutomationCore.h>
#include <uiautomationcoreapi.h>
#include <uiautomation.h>
#include <cstdio>

#pragma comment(lib, "ole32.lib")
#pragma comment(lib, "oleaut32.lib")
#pragma comment(lib, "uuid.lib")
// UIAutomationCore.lib 由 build.sh 在 /link 显式给（不写死 SDK 版本路径）。

static const wchar_t *kRootName = L"DriftApp";

// ── 最小 UIA provider：只实现 IRawElementProviderSimple（P1 验根属性可达）。──
class CProvider : public IRawElementProviderSimple {
public:
    CProvider() : m_ref(1) {}

    STDMETHOD(QueryInterface)(REFIID riid, void **ppv) override {
        if (ppv == nullptr) return E_INVALIDARG;
        if (riid == __uuidof(IUnknown) || riid == __uuidof(IRawElementProviderSimple)) {
            *ppv = static_cast<IRawElementProviderSimple *>(this);
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

    STDMETHOD(get_ProviderOptions)(ProviderOptions *pRet) override {
        *pRet = ProviderOptions_ServerSideProvider;
        return S_OK;
    }
    STDMETHOD(GetPatternProvider)(PATTERNID, IUnknown **ppv) override {
        *ppv = nullptr;   // 第一版不提供 Invoke 等 pattern
        return S_OK;
    }
    STDMETHOD(GetPropertyValue)(PROPERTYID id, VARIANT *out) override {
        VariantInit(out);
        if (id == UIA_NamePropertyId) {
            out->vt = VT_BSTR;
            out->bstrVal = SysAllocString(kRootName);
        } else if (id == UIA_ControlTypePropertyId) {
            out->vt = VT_I4;
            out->lVal = UIA_WindowControlTypeId;
        } else if (id == UIA_IsControlElementPropertyId || id == UIA_IsContentElementPropertyId) {
            out->vt = VT_BOOL;
            out->boolVal = VARIANT_TRUE;
        }
        return S_OK;
    }
    STDMETHOD(get_HostRawElementProvider)(IRawElementProviderSimple **ret) override {
        *ret = nullptr;   // 数据全部自供，不委宿主机 provider
        return S_OK;
    }

private:
    ULONG m_ref;
};

static CProvider *g_provider = nullptr;
static HWND g_hwnd = nullptr;
static HANDLE g_ready = nullptr;

static LRESULT CALLBACK wndProc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp) {
    switch (msg) {
        case WM_GETOBJECT:
            if (lp == (LPARAM)UiaRootObjectId) {
                return UiaReturnRawElementProvider(hwnd, wp, lp, g_provider);
            }
            break;
        case WM_DESTROY:
            PostQuitMessage(0);
            return 0;
        default:
            break;
    }
    return DefWindowProcW(hwnd, msg, wp, lp);
}

static DWORD WINAPI winThread(LPVOID) {
    CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED);
    HINSTANCE inst = GetModuleHandleW(nullptr);
    WNDCLASSEXW wc = {};
    wc.cbSize = sizeof(wc);
    wc.lpfnWndProc = wndProc;
    wc.hInstance = inst;
    wc.hCursor = LoadCursorW(nullptr, IDC_ARROW);
    wc.hbrBackground = (HBRUSH)(COLOR_WINDOW + 1);
    wc.lpszClassName = L"UiaProbe";
    RegisterClassExW(&wc);
    g_hwnd = CreateWindowExW(0, L"UiaProbe", L"Drift UIA Probe", WS_OVERLAPPEDWINDOW,
                             CW_USEDEFAULT, CW_USEDEFAULT, 420, 340,
                             nullptr, nullptr, inst, nullptr);
    g_provider = new CProvider();
    ShowWindow(g_hwnd, SW_SHOW);
    UpdateWindow(g_hwnd);
    SetEvent(g_ready);
    MSG msg;
    while (GetMessageW(&msg, nullptr, 0, 0)) {
        TranslateMessage(&msg);
        DispatchMessageW(&msg);
    }
    CoUninitialize();
    return 0;
}

int main() {
    CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED);
    g_ready = CreateEventW(nullptr, TRUE, FALSE, nullptr);
    HANDLE th = CreateThread(nullptr, 0, winThread, nullptr, 0, nullptr);
    if (th == nullptr || WaitForSingleObject(g_ready, 5000) != WAIT_OBJECT_0) {
        printf("[uia-probe] window thread failed to start\n");
        return 2;
    }

    IUIAutomation *autoation = nullptr;
    HRESULT hr = CoCreateInstance(CLSID_CUIAutomation, nullptr, CLSCTX_INPROC_SERVER,
                                  IID_IUIAutomation, (void **)&autoation);
    if (FAILED(hr) || autoation == nullptr) {
        printf("[uia-probe] CoCreateInstance(CUIAutomation) failed hr=0x%08lx\n", hr);
        return 2;
    }

    IUIAutomationElement *root = nullptr;
    hr = autoation->ElementFromHandle(g_hwnd, &root);
    if (FAILED(hr) || root == nullptr) {
        printf("[uia-probe] ElementFromHandle failed hr=0x%08lx\n", hr);
        autoation->Release();
        return 1;
    }

    bool pass = false;
    wchar_t nameBuf[128] = L"<none>";
    long ctrl = -1;
    VARIANT v;
    if (SUCCEEDED(root->GetCurrentPropertyValue(UIA_NamePropertyId, &v)) && v.vt == VT_BSTR && v.bstrVal) {
        wcsncpy_s(nameBuf, 127, v.bstrVal, _TRUNCATE);
    }
    VariantClear(&v);
    if (SUCCEEDED(root->GetCurrentPropertyValue(UIA_ControlTypePropertyId, &v)) && v.vt == VT_I4) {
        ctrl = v.lVal;
    }
    VariantClear(&v);

    printf("[uia-probe] Name=%ls  ControlType=%ld (expect Name=DriftApp ControlType=%d=Window)\n",
           nameBuf, ctrl, (int)UIA_WindowControlTypeId);
    if (wcscmp(nameBuf, L"DriftApp") == 0 && ctrl == (long)UIA_WindowControlTypeId) {
        printf("[uia-probe] PASS — 壳可挂 UIA provider，客户端读到了根节点属性\n");
        pass = true;
    } else {
        printf("[uia-probe] FAIL — provider 未被读到或属性不符\n");
    }

    root->Release();
    autoation->Release();
    DestroyWindow(g_hwnd);
    WaitForSingleObject(th, 5000);
    CloseHandle(th);
    CloseHandle(g_ready);
    CoUninitialize();
    return pass ? 0 : 1;
}
