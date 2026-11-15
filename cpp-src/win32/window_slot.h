/**
 * 窗口槽的公共类型：`WindowSlot` + `UiaNode`。
 * 放头文件是因为 `uia.cc` 与主体都要看见；拆分前它们住在 `drift_win32.cc` 的匿名
 * namespace 里，UIA 只能跟着主体一起编译。
 */
#pragma once

#include <windows.h>
#include <UIAutomationClient.h>   // UIA_CustomControlTypeId（UiaNode 的默认值）
#include <map>
#include <string>
#include <vector>

/** LWA / ULW 互斥的绑定态（状态机理由见 `bindLayered()` 的注释） */
enum { kBindNone = 0, kBindLwa = 1, kBindUlw = 2 };

// UIA provider 在 uia.cc 的具名命名空间里 —— 匿名 namespace 会让它变成**另一种类型**，
// 与这里的指针赋值对不上（拆分时踩过）。
namespace drift::win32::uia {
class CProvider;
}

/** UIA 语义节点（§9 / M5）。`WindowSlot` 用 `vector` 持有它，故必须是完整类型。 */
struct UiaNode {
    std::wstring name;
    // §66 节点身份（PHP `Widget::identity()`）：只按索引比会在滚动 / 排序后误报「选中变了」
    std::wstring key;
    long controlType = UIA_CustomControlTypeId;
    int parent = -1;   // 父节点索引（本槽的 uiaNodes），-1 = 顶层（父是窗口根）
    bool focused = false;
    bool focusable = false;   // 能否被 Tab 到（与 focused 正交）
    // 三态：0 = 该角色没这个概念 ⇒ 不提供对应 Pattern/属性。混成 bool 会让每个
    //   按钮都对客户端声称「我未选中 / 可展开」（§65）。
    int selectState = 0;   // 1 未选 / 2 已选
    int expandState = 0;   // 1 折叠 / 2 展开
    int level = 0;         // 1-based；0 = 不提供 UIA_Level
    double x = 0.0;
    double y = 0.0;
    double w = 0.0;
    double h = 0.0;
    bool isControl = true;
    // 不实现 IsEnabled ⇒ 客户端默认 false，`RangeValuePattern.SetValue` 在客户端侧
    //   就抛 ElementNotEnabledException（到不了 provider，§4.4）
    bool enabled = true;
    // 人读串（PHP `SemanticsNode::$value`）；range 只对 slider / progress 有意义
    std::wstring value;
    // 静态描述（PHP `SemanticsNode`）：空串 ⇒ 不向客户端答该属性
    std::wstring hint;
    std::wstring automationId;
    std::wstring acceleratorKey;
    double rangeMin = 0.0;
    double rangeMax = 0.0;
};

/**
 * 一个窗口的全部壳侧状态。字段顺序就是读取顺序：帧缓冲 → 上屏资源 → 外观 →
 * 几何 → IME → 模态循环 → 指针 → UIA。
 * 多窗口的前提：窗口身份是**参数**（HWND → 槽），不是全局。槽不 erase ——
 * `WindowStateStore::persist()` 跑在退出路径上，还要读最后几何。
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

    // ── 最后已知几何（销毁后仍要可读；"槽不 erase"见主体 `slotOf()` 注释）──
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
    drift::win32::uia::CProvider *uiaProvider = nullptr;
};
