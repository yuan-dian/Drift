"""按 **UIA 语义名**定位/点击真机窗口里的控件（2026-10-30）。

    python tools/probe-uia.py --dump                    # 打印当前 showcase 窗口的语义树
    python tools/probe-uia.py --app showcase --title "Drift 示例台" --click "弹一个提示"

★ 为什么有这个脚本（接手真机验证时先看这段）
  之前为了在真机上点一个按钮，靠"截图 → 拿眼睛量像素 → 猜坐标"，一来一回几十分钟、
  几十张截图。这是**原始手段**：成熟 GUI（Qt / Flutter / WPF / Win32）全都导出
  无障碍树，自动化一律**按名字点**（UIA / AT-SPI / AX + WinAppDriver / FlaUI /
  pywinauto 都建在这上面）。
  本仓的应用已经接了语义树（组合根调 `onSemantics()` ⇒ `drift_win_uia_push()`
  ⇒ C 侧 `CProvider` / `FragmentAdapter` 应答 UIA），所以**树本来就在**，缺的只是
  一个消费者。这个脚本就是那个消费者。

★ 判据为什么比截图强
  · 名字是**应用自己声明的**（`Semantics`），改布局不会让它失效；
  · 矩形来自 `get_BoundingRectangle`（provider 自己算的物理像素），不用猜；
  · 找不到就是找不到（响亮失败），不会"点偏了但看起来像点中了"。

★ 依赖：只用 ctypes 手写 COM（本机没有 pywinauto / comtypes，也不想为一个探针装包）。
"""

import argparse
import ctypes
import os
import sys
import time
from ctypes import wintypes

import probe_win as win

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

user32 = ctypes.WinDLL("user32", use_last_error=True)
ole32 = ctypes.WinDLL("ole32", use_last_error=True)
oleaut32 = ctypes.WinDLL("oleaut32", use_last_error=True)


class GUID(ctypes.Structure):
    _fields_ = [("Data1", wintypes.DWORD), ("Data2", wintypes.WORD),
                ("Data3", wintypes.WORD), ("Data4", ctypes.c_ubyte * 8)]


class UiaRect(ctypes.Structure):
    _fields_ = [("left", ctypes.c_double), ("top", ctypes.c_double),
                ("width", ctypes.c_double), ("height", ctypes.c_double)]


CLSID_CUIAutomation = GUID(0xFF48DBA4, 0x60EF, 0x4201,
                           (ctypes.c_ubyte * 8)(0xAA, 0x87, 0x54, 0x10, 0x3E, 0xEF, 0x59, 0x4E))
IID_IUIAutomation = GUID(0x30CBE57D, 0xD9D0, 0x452A,
                         (ctypes.c_ubyte * 8)(0xAB, 0x13, 0x7A, 0xC5, 0xAC, 0x48, 0x25, 0xEE))

CLSCTX_INPROC_SERVER = 1
COINIT_APARTMENTTHREADED = 0x2

# UIA 常量
TreeScope_Children = 0x2
TreeScope_Descendants = 0x4
UIA_InvokePatternId = 10000

# IUIAutomation 的 vtable 索引（IUnknown 占 0..2）
IUIA_ElementFromHandle = 6
IUIA_ElementFromPoint = 7
IUIA_get_ControlViewWalker = 14
IUIA_get_ContentViewWalker = 15

# IUIAutomationElement 的 vtable 索引
# ★ 逐条数过（IUnknown 3 + 文档顺序）：漏一个 `GetCurrentPatternAs` 或
#   `GetCachedPatternAs` 就会整体错位，症状是 BSTR 出参读到垃圾 ⇒ 访问冲突。
ELEM_GetCurrentPropertyValue = 10
ELEM_GetCurrentPattern = 16
ELEM_get_CurrentControlType = 21
ELEM_get_CurrentName = 23
ELEM_get_CurrentHasKeyboardFocus = 26
ELEM_get_CurrentIsKeyboardFocusable = 27
ELEM_get_CurrentIsOffscreen = 38
ELEM_get_CurrentBoundingRectangle = 43

# UIA 属性 id（`GetCurrentPropertyValue` 用；与 provider 侧 `GetPropertyValue` 同一个 id）
UIA_HasKeyboardFocusPropertyId = 30008
UIA_IsKeyboardFocusablePropertyId = 30009
UIA_ValueValuePropertyId = 30045
UIA_ExpandCollapseExpandCollapseStatePropertyId = 30070
UIA_SelectionItemIsSelectedPropertyId = 30079
UIA_ToggleToggleStatePropertyId = 30086
UIA_LevelPropertyId = 30154

# IUIAutomationTreeWalker（`GetParentElement` 在最前，别从 FirstChild 开始数）
WALKER_GetFirstChildElement = 4
WALKER_GetNextSiblingElement = 6

# IUIAutomationInvokePattern
INVOKE_Invoke = 3


def _vt(ptr, index):
    """取 COM 对象的第 index 个虚函数"""
    vtable = ctypes.cast(ptr, ctypes.POINTER(ctypes.POINTER(ctypes.c_void_p)))[0]

    return ctypes.cast(vtable[index], ctypes.c_void_p).value


def _call(ptr, index, restype, *argtypes):
    fn = ctypes.WINFUNCTYPE(restype, ctypes.c_void_p, *argtypes)(_vt(ptr, index))

    return fn


def uia_create():
    ole32.CoInitializeEx(None, COINIT_APARTMENTTHREADED)
    p = ctypes.c_void_p()
    hr = ole32.CoCreateInstance(
        ctypes.byref(CLSID_CUIAutomation), None, CLSCTX_INPROC_SERVER,
        ctypes.byref(IID_IUIAutomation), ctypes.byref(p),
    )
    if hr != 0:
        raise OSError(f"CoCreateInstance(CUIAutomation) hr=0x{hr & 0xFFFFFFFF:08X}")

    return p


def element_from_handle(uia, hwnd):
    elem = ctypes.c_void_p()
    fn = _call(uia, IUIA_ElementFromHandle, ctypes.HRESULT, wintypes.HWND,
               ctypes.POINTER(ctypes.c_void_p))
    fn(uia, hwnd, ctypes.byref(elem))

    return elem


def element_from_point(uia, x, y):
    """**屏幕**坐标 → 该点下的元素（走 provider 的 `ElementProviderFromPoint`）

    ★ 参数是 **`POINT` 结构体**，不是两个 double（2026-11-01 修正）：
      `IUIAutomation::ElementFromPoint(POINT pt, ...)`（UIAutomationClient.h:18869）。
      此前按两个 double 传，x64 下浮点走 xmm、整数寄存器 rdx 是垃圾 ⇒ provider 拿
      垃圾坐标做命中测试 → access violation。不是"STA 需要消息泵"的环境限制，
      是**调用签名写错**（vtable 索引 7 一直是对的）。
    """
    out = ctypes.c_void_p()
    fn = _call(uia, IUIA_ElementFromPoint, ctypes.HRESULT, wintypes.POINT,
               ctypes.POINTER(ctypes.c_void_p))
    hr = fn(uia, wintypes.POINT(int(x), int(y)), ctypes.byref(out))
    if hr != 0 or not out.value:
        return None

    return out


def walker(uia, control_view=True):
    w = ctypes.c_void_p()
    fn = _call(uia, IUIA_get_ControlViewWalker if control_view else IUIA_get_ContentViewWalker,
               ctypes.HRESULT, ctypes.POINTER(ctypes.c_void_p))
    fn(uia, ctypes.byref(w))

    return w


def child(w, elem):
    out = ctypes.c_void_p()
    fn = _call(w, WALKER_GetFirstChildElement, ctypes.HRESULT, ctypes.c_void_p,
               ctypes.POINTER(ctypes.c_void_p))
    fn(w, elem, ctypes.byref(out))

    return out


def sibling(w, elem):
    out = ctypes.c_void_p()
    fn = _call(w, WALKER_GetNextSiblingElement, ctypes.HRESULT, ctypes.c_void_p,
               ctypes.POINTER(ctypes.c_void_p))
    fn(w, elem, ctypes.byref(out))

    return out


def name_of(elem):
    bstr = ctypes.c_void_p()
    fn = _call(elem, ELEM_get_CurrentName, ctypes.HRESULT, ctypes.POINTER(ctypes.c_void_p))
    fn(elem, ctypes.byref(bstr))
    if not bstr.value:
        return ""
    try:
        return ctypes.wstring_at(bstr.value)
    finally:
        oleaut32.SysFreeString(bstr)


def ctype_of(elem):
    v = ctypes.c_int(0)
    fn = _call(elem, ELEM_get_CurrentControlType, ctypes.HRESULT, ctypes.POINTER(ctypes.c_int))
    fn(elem, ctypes.byref(v))

    return v.value


def rect_of(elem):
    # ★ 客户端侧是 **RECT（4×LONG）**，provider 侧的 `UiaRect`（4×double）是另一回事。
    #   用错类型不会报错 —— 读出来全是 0，很容易误判成"provider 没实现几何"。
    r = wintypes.RECT()
    fn = _call(elem, ELEM_get_CurrentBoundingRectangle, ctypes.HRESULT,
               ctypes.POINTER(wintypes.RECT))
    fn(elem, ctypes.byref(r))

    return r


def offscreen(elem):
    v = wintypes.BOOL(0)
    fn = _call(elem, ELEM_get_CurrentIsOffscreen, ctypes.HRESULT,
               ctypes.POINTER(wintypes.BOOL))
    fn(elem, ctypes.byref(v))

    return bool(v.value)


class VARIANT(ctypes.Structure):
    """x64 VARIANT（24 字节）：8 字节头 + 16 字节 union。
    ★ 别用 16 字节的版本 —— 那是 32 位口径，读出来会踩掉后面 8 字节。"""
    _fields_ = [("vt", ctypes.c_ushort),
                ("wReserved1", ctypes.c_ushort),
                ("wReserved2", ctypes.c_ushort),
                ("wReserved3", ctypes.c_ushort),
                ("llVal", ctypes.c_longlong),
                ("pad", ctypes.c_longlong)]


VT_EMPTY = 0
VT_I4 = 3
VT_BOOL = 11


def prop_of(elem, property_id):
    """读一个 UIA 属性（`GetCurrentPropertyValue`）。

    返回 `(vt, 值)`：
      - `VT_I4`   ⇒ int
      - `VT_BOOL` ⇒ bool
      - `VT_EMPTY` ⇒ `None` —— **"该属性不支持"**，与 `False` 不是一回事
        （provider 侧三态里的 0 就落在这条路上）。
    ★ 探针判据必须能区分 None 与 False：把"没实现"读成"没有"，正是 §65
      要修的那个坑。
    """
    v = VARIANT()
    fn = _call(elem, ELEM_GetCurrentPropertyValue, ctypes.HRESULT,
               ctypes.c_int, ctypes.POINTER(VARIANT))
    fn(elem, ctypes.c_int(property_id), ctypes.byref(v))
    if v.vt == VT_I4:
        return v.vt, v.llVal & 0xFFFFFFFF
    if v.vt == VT_BOOL:
        return v.vt, bool(v.llVal & 0xFFFF)
    if v.vt == VT_EMPTY:
        return v.vt, None

    return v.vt, None


CONTROL_TYPES = {
    50000: "Button", 50001: "Calendar", 50002: "CheckBox", 50003: "ComboBox",
    50004: "Edit", 50005: "Hyperlink", 50006: "Image", 50007: "ListItem",
    50008: "List", 50009: "Menu", 50010: "MenuBar", 50011: "MenuItem",
    50012: "ProgressBar", 50013: "RadioButton", 50014: "ScrollBar",
    50015: "Slider", 50016: "Spinner", 50017: "StatusBar", 50018: "Tab",
    50019: "TabItem", 50020: "Text", 50021: "ToolBar", 50022: "ToolTip",
    50023: "Tree", 50024: "TreeItem", 50025: "Custom", 50026: "Group",
    50027: "Thumb", 50028: "DataGrid", 50029: "DataItem", 50030: "Document",
    50031: "SplitButton", 50032: "Window", 50033: "Pane", 50034: "Header",
    50035: "HeaderItem", 50036: "Table", 50037: "TitleBar", 50038: "Separator",
    50039: "SemanticZoom", 50040: "AppBar",
}


def dump(elem, w, depth=0, found=None, target=None):
    """深度优先打印（或查找）整棵树"""
    n = name_of(elem)
    r = rect_of(elem)
    t = ctype_of(elem)

    if found is not None and target and n == target:
        found.append((elem, r, t))

    if depth <= 6:
        print(f"{'  ' * depth}{CONTROL_TYPES.get(t, t)}  {n!r}  "
              f"[{r.left},{r.top} {r.right - r.left}x{r.bottom - r.top}]"
              f"{'  (offscreen)' if offscreen(elem) else ''}")

    c = child(w, elem)
    while c:
        dump(c, w, depth + 1, found, target)
        c = sibling(w, c)


def collect(elem, w, out=None, depth=0):
    """收集元组 (controlType, name, left, top, right, bottom, offscreen, isLeaf)"""
    if out is None:
        out = []
    r = rect_of(elem)
    c = child(w, elem) if depth <= 12 else None
    # ★ `child()` 返回 `c_void_p(0)` 表示"没有子"，而 **`c_void_p(0) is not None`**
    #   —— 用 `is None` 判会得出"全树都是叶子"。
    out.append((ctype_of(elem), name_of(elem), r.left, r.top,
                r.right, r.bottom, offscreen(elem), not c))
    while c:
        collect(c, w, out, depth + 1)
        c = sibling(w, c)

    return out


def client_rect(hwnd):
    """客户区在**屏幕**上的矩形（Windows 自己算的，与 UIA 无关 ⇒ 可作外部锚）"""
    r = wintypes.RECT()
    user32.GetClientRect(hwnd, ctypes.byref(r))
    w_, h_ = r.right - r.left, r.bottom - r.top
    pt = wintypes.POINT(0, 0)
    user32.ClientToScreen(hwnd, ctypes.byref(pt))

    return pt.x, pt.y, w_, h_


def drift_subtree(root, w):
    """取**我们 provider** 的子树根，避开 UIA 给的系统自带节点。

    ★ 必须避开：`element_from_handle()` 给的根下面是 Windows 自己的标题栏那套
      （TitleBar / 最小化 / 最大化 / 关闭），它们的矩形由系统算，**本来就在
      非客户区**（实测 y≈101，客户区原点 131）—— 混进来会把校验判成"越界"。
      这一条踩过一次：误判成"换算错了"。
    ★ 定位特征：我们的 `FragmentRoot`（`CProvider`，index=-1）**恒零矩形**，
      所以"第一个矩形为空、类型是 Window 的子节点"就是它。
      **若将来让 FragmentRoot 返回客户区矩形，这里会找不到 ⇒ 响亮失败**
      （返回 None，由调用方报错），不会退化成假绿。
    """
    c = child(w, root)
    while c:
        r = rect_of(c)
        if ctype_of(c) in (50032, 50033) and r.right == r.left and r.bottom == r.top:
            return c
        c = sibling(w, c)

    return None


def verify_geometry(root, w, hwnd):
    """校验 UIA 矩形是**屏幕物理像素**，不是客户区局部坐标。

    ★ 判据只能来自契约本身：UIA 客户端拿到矩形后**直接**置光标 / 画焦点框 /
      做放大镜跟随，所以它必须是屏幕坐标。四条断言：
        ① **完全**落在客户区外的节点必须被 `IsOffscreen` 承认 —— 滚动区里滚出视口的
           项仍在语义树里、仍报布局矩形（实测 y 到 1452、客户区底 712），客户端靠
           offscreen 跳过它们；
        ② 最小 left / top ≥ 客户区屏幕原点 − 2 —— ★ **这条才是否定性的、能证伪的**：
           若 provider 给的是客户区局部坐标，最小原点会远小于客户区原点
           （2026-10-30 实测 [20,74] vs [108,131]），一测就红；
        ③ 有面积的节点占多数 —— 否则"全零矩形"也能满足 ①②，是假绿；
        ④ 与客户区**有交集**的节点不得报 offscreen —— ① 的对称面，两条合起来才
           覆盖全部节点、没有缝；同时防"一律 true"把 ① 糊过去（那时 ① 恒真，
           等于没验）。
    ＊ ① 只判"完全在外"：**部分可见**（实测有一条只露出 3px）报 offscreen=false
      是正确语义 —— WPF 那边同样是"沾到一点就算可见"。第一版把这种也判成越界，
      错的是判据不是实现。
    ＊ ② 不能省：① 在"只漏了加偏移、尺寸还对"时仍然全绿（整棵子树嵌套在客户区里）。
    """
    sub = drift_subtree(root, w)
    if sub is None:
        print("✗ 没找到 drift 的 provider 子树（FragmentRoot 不再是零矩形？）")
        return 1

    cx, cy, cw, ch = client_rect(hwnd)
    nodes = collect(sub, w)
    sized = [n for n in nodes if n[4] > n[2] and n[5] > n[3]]

    print(f"客户区（屏幕）：[{cx},{cy} {cw}x{ch}]  节点 {len(nodes)} 个"
          f"（有面积 {len(sized)}）")

    fails = []
    outside = []
    inside_called_off = []
    for t, n, x0, y0, x1, y1, off, _leaf in sized:
        fully_out = (x1 <= cx or y1 <= cy or x0 >= cx + cw or y0 >= cy + ch)
        overlap = (x0 < cx + cw and x1 > cx and y0 < cy + ch and y1 > cy)
        if fully_out and not off:
            outside.append(f"{n or CONTROL_TYPES.get(t, t)}[{x0},{y0} {x1}x{y1}]")
        if overlap and off:
            inside_called_off.append(f"{n or CONTROL_TYPES.get(t, t)}[{x0},{y0}]")

    if outside:
        print(f"  ✗ ①完全在客户区外却报 offscreen=false：{outside[:5]}（共 {len(outside)}）")
        fails.append("完全在外的节点未被 IsOffscreen 承认")
    else:
        print("  ✓ ①完全在外的节点都被 IsOffscreen 承认")

    if sized:
        min_left = min(n[2] for n in sized)
        min_top = min(n[3] for n in sized)
        ok2 = min_left >= cx - 2 and min_top >= cy - 2
        print(f"  {'✓' if ok2 else '✗'} ②最小原点 [{min_left},{min_top}] "
              f"{'≥' if ok2 else '<'} 客户区原点 [{cx},{cy}]")
        if not ok2:
            fails.append(f"最小原点 {min_left},{min_top} 在客户区原点左侧 ⇒ 仍在用局部坐标")
    else:
        fails.append("零个有面积节点")
        print("  ✗ ②无有面积节点，无法判定")

    ok3 = len(sized) * 2 > len(nodes)
    print(f"  {'✓' if ok3 else '✗'} ③有面积占比 {len(sized)}/{len(nodes)}")
    if not ok3:
        fails.append("有面积节点不过半")

    if inside_called_off:
        print(f"  ✗ ④与客户区有交集却报 offscreen=true：{inside_called_off[:5]}"
              f"（共 {len(inside_called_off)}）")
        fails.append("可见节点被误报 offscreen")
    else:
        print("  ✓ ④可见节点都没被误报 offscreen")

    if fails:
        print("结论：✗ UIA 几何契约不成立（坐标不是屏幕坐标，或 offscreen 与可见性不一致）")
        return 1

    print("结论：✓ UIA 几何契约成立（矩形为屏幕坐标，offscreen 与可见性一致）")
    return 0


def verify_hit_test(uia, root, w, hwnd, samples=5):
    """点元素中心 ⇒ `ElementFromPoint` 必须返回**那个**元素，而不是窗口根。

    ★ 判据来自 spec：`ElementProviderFromPoint` 是命中测试的唯一来源，读屏的
      鼠标追踪（NVDA mouse tracking / 讲述人鼠标指读）全走它。
      恒返回根（2026-10-30 之前）⇒ "点谁都是 Drift"，整窗被读成一个整体。
    ★ 只取**叶子**节点当锚：取容器的话，返回它的某个后代也是对的，
      名字不等就成了假红。

    ★ 2026-11-01 已真机打通：此前一调就 access violation，一度误判成"hit test 需要
      有消息泵的 STA 线程"。根因是 ctypes 把参数传成两个 double，而签名是 `POINT`
      （见 `element_from_point`）。修参数后实测 5/5 命中正确。
    """
    cx, cy, cw, ch = client_rect(hwnd)
    nodes = collect(root, w)
    pool = [n for n in nodes
            if n[7] and n[1] and n[4] > n[2] and n[5] > n[3] and not n[6]
            and n[2] >= cx and n[3] >= cy and n[4] <= cx + cw and n[5] <= cy + ch]
    if len(pool) < samples:
        print(f"✗ 可取样叶子节点太少（{len(pool)}）")
        return 1

    bad = []
    for t, n, x0, y0, x1, y1, _off, _leaf in pool[:samples]:
        px = (x0 + x1) // 2
        py = (y0 + y1) // 2
        try:
            hit = element_from_point(uia, px, py)
        except OSError as exc:
            # 签名已修正，仍崩说明是回归（别退化成"跳过=通过"的假绿）
            print(f"✗ ElementFromPoint 崩了（{exc}）——签名/索引回归？")
            return 1
        if not hit:
            bad.append(f"{n}: 返回 null")
            continue
        got = name_of(hit)
        ok = (got == n)
        print(f"  {'✓' if ok else '✗'} ({px},{py}) → {got!r}（期望 {n!r}）")
        if not ok:
            bad.append(f"{n} → {got!r}")

    if bad:
        print(f"结论：✗ 命中测试不对（{bad[:3]}）—— provider 大概在返回窗口根")
        return 1

    print("结论：✓ 命中测试正确（点叶子中心返回该叶子）")
    return 0


def launch(app, title, wait=4.0, env_extra=None):
    """起产物 + 等窗。**只做转发** —— 真实实现（含 `PHPRC`、进程早退的死因、
    用户窗口状态隔离）在 `tools/probe_win.py`，9 个探针共用那一份。

    这里保留 `(proc, hwnd)` 的形状，是因为本文件有 `--no-launch` 分支（`proc=None`）。
    """
    proc = win.launch(f"{app}.exe", env_extra=env_extra)
    try:
        hwnd = win.wait_window(title, proc, wait)
    except win.NotMeasured as exc:
        proc.terminate()
        raise SystemExit(f"✗ {exc}")

    return proc, hwnd


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--app", default="showcase")
    ap.add_argument("--title", default="Drift 示例台")
    ap.add_argument("--dump", action="store_true", help="打印语义树（不点）")
    ap.add_argument("--verify-geometry", action="store_true",
                    help="校验 UIA 矩形是屏幕坐标而非客户区局部坐标")
    ap.add_argument("--verify-hit-test", action="store_true",
                    help="校验 ElementFromPoint 能命中具体元素（而不是恒返回窗口根）")
    ap.add_argument("--click", action="append", default=None,
                    help="按 UIA 名字依次点击（可重复；每一步之间等 0.9s）")
    ap.add_argument("--no-launch", action="store_true", help="不启动，连已在跑的窗口")
    ap.add_argument("--dump-after", action="store_true",
                    help="点击完成后再打印一次树（验「点了真的生效」）")
    args = ap.parse_args()

    if args.no_launch:
        proc = None
        hwnd = user32.FindWindowW(None, args.title)
        if not hwnd:
            raise SystemExit("✗ 没有正在跑的窗口")
    else:
        proc, hwnd = launch(args.app, args.title)

    try:
        uia = uia_create()
        root = element_from_handle(uia, hwnd)
        w = walker(uia, control_view=True)

        if args.dump:
            print(f"== UIA 树：{args.title} ==")
            dump(root, w)
            return 0

        if args.verify_geometry:
            return verify_geometry(root, w, hwnd)

        if args.verify_hit_test:
            return verify_hit_test(uia, root, w, hwnd)

        for target in args.click:
            elem, r = None, None
            # ★ 目标可能在滚动区里**看不见**（UIA 树给的是布局坐标，含滚出视口的部分）
            #   ⇒ 先按名字找到它，若在客户区之外就滚，滚完重读树（名字不变，坐标会变）。
            #   这就是"按名字点"相对"按坐标点"的全部价值：坐标是**查出来的**，不是记下来的。
            for attempt in range(12):
                root = element_from_handle(uia, hwnd)
                found = []
                dump(root, w, found=found, target=target)
                if not found:
                    print(f"✗ 树里没有名字为 {target!r} 的元素（先 --dump 看真名）")
                    return 1
                elem, r, _t = found[0]
                cw, ch = win.client_size(hwnd)
                # UIA 给的是**屏幕**坐标，可见性要按客户区判断（换算一次）
                cx_c, cy_c = win.screen_to_client(hwnd, (r.left + r.right) // 2, (r.top + r.bottom) // 2)
                # ★ 判据是**中部**（12%–78%）而不是"在客户区内"：
                #   这条判据用的是**客户区**，而元素真正被裁它的是**自己那个滚动容器**。
                #   实测（2026-11-06）：导航项 '树 Tree' 报 y=631 / 视口 640 ⇒ 判"可见"，
                #   但导航 `List` 的底只到 618 ⇒ 点在列表外，页面不切。
                #   现象与"点了没反应"一模一样，且判据本身看不出来。
                #   中部判据是**经验规避**：根因（拿不到元素所在的滚动视口）还在，
                #   要真修得让 UIA 侧报出容器的可见区。
                if ch * 0.12 <= cy_c <= ch * 0.78:
                    break
                step = 3 if cy_c > ch * 0.5 else -3
                # 滚轮必须落在**元素所在那一列**（导航列表 / 内容区是两个独立滚动容器）。
                # 打到内容区再滚，导航纹丝不动 —— 现象与"滚轮完全失效"一模一样。
                # ★ y 取**视口中线**而不是边缘：边缘可能落在列表外（标题栏 / 面板留白），
                #   滚轮就滚到了别人身上。手感上"手指放在列表中间往上推"也是这个位置。
                print(f"  {target!r} 在客户区外（y={cy_c}，视口 {ch}）⇒ 滚 {step:+d} 格")
                win.wheel(hwnd, max(4, min(cx_c, cw - 4)), ch // 2, step)
                time.sleep(0.35)
            else:
                print(f"✗ 滚了 12 次仍没让 {target!r} 进入视口")
                return 1

            elem, r, _t = found[0]
            w_px, h_px = r.right - r.left, r.bottom - r.top

            # ★ 优先走**真实鼠标**（move → down → up 分三条消息）：它与用户的手
            #   完全同一条路（包括 hover 那一轮），Invoke 走的是"读屏激活"分支。
            #   provider 给的矩形是**屏幕**坐标（2026-10-30 起修正，此前是客户区
            #   局部坐标）⇒ 换回客户区再喂 `probe_win.click`。
            #   ★ 这两端必须是同一个语义：矩形若是客户区坐标、这里又换一次，就会
            #     双重偏移 108,131，点全落到窗口左上方的空白处。
            if w_px > 0 and h_px > 0:
                cx_s = (r.left + r.right) // 2
                cy_s = (r.top + r.bottom) // 2
                cx, cy = win.screen_to_client(hwnd, cx_s, cy_s)
                print(f"→ 点 {target!r} @ 客户区 ({cx},{cy})  {w_px}x{h_px}")
                win.click(hwnd, cx, cy)
            else:
                pat = ctypes.c_void_p()
                fn = _call(elem, ELEM_GetCurrentPattern, ctypes.HRESULT,
                           ctypes.c_int, ctypes.POINTER(ctypes.c_void_p))
                hr = fn(elem, UIA_InvokePatternId, ctypes.byref(pat))
                if hr == 0 and pat.value:
                    try:
                        _call(pat, INVOKE_Invoke, ctypes.HRESULT)(pat)
                        print(f"✓ Invoke {target!r}（零面积，走读屏激活）")
                    finally:
                        _call(pat, 2, ctypes.c_ulong)(pat)
                else:
                    print(f"✗ {target!r} 既无面积也无 Invoke 模式")
                    return 1

            time.sleep(0.9)

        if args.dump_after:
            # ★ 点完再看一次树：这是"点了**生效**"的唯一证据。只看"没报错"
            #   等于把"点了空气"也算通过。
            root = element_from_handle(uia, hwnd)
            print(f"== 点击后 UIA 树：{args.title} ==")
            dump(root, w)

        return 0
    finally:
        if proc is not None and proc.poll() is None:
            proc.terminate()


if __name__ == "__main__":
    raise SystemExit(main())
