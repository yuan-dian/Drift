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
import subprocess
import sys
import tempfile
import time
from ctypes import wintypes

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
IUIA_get_ControlViewWalker = 14
IUIA_get_ContentViewWalker = 15

# IUIAutomationElement 的 vtable 索引
# ★ 逐条数过（IUnknown 3 + 文档顺序）：漏一个 `GetCurrentPatternAs` 或
#   `GetCachedPatternAs` 就会整体错位，症状是 BSTR 出参读到垃圾 ⇒ 访问冲突。
ELEM_GetCurrentPattern = 16
ELEM_get_CurrentControlType = 21
ELEM_get_CurrentName = 23
ELEM_get_CurrentIsOffscreen = 38
ELEM_get_CurrentBoundingRectangle = 43

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


def wheel_at(hwnd, x, y, notches):
    """在客户区 (x,y) 处滚动 notches 格（正 = 向下）

    ★ **必须先发一条 `WM_MOUSEMOVE`**（2026-10-30 实测）：drift 的滚轮命中读的是
      **缓存的指针位置**，不是 `WM_MOUSEWHEEL` 自带的坐标。不发 move 的话滚轮消息
      全部石沉大海 —— 现象是"滚了 12 次，元素坐标纹丝不动"，很容易误判成
      "UIA 的坐标是死的 / 应用不支持滚动"。
    """
    user32.PostMessageW(hwnd, 0x0200, 0, ((y & 0xFFFF) << 16) | (x & 0xFFFF))
    time.sleep(0.05)

    sx, sy = to_screen(hwnd, x, y)
    delta = -120 if notches > 0 else 120
    for _ in range(abs(notches)):
        user32.PostMessageW(hwnd, 0x020A, (delta << 16) & 0xFFFFFFFF,
                           ((sy & 0xFFFF) << 16) | (sx & 0xFFFF))
        time.sleep(0.03)


def to_screen(hwnd, x, y):
    pt = wintypes.POINT(int(x), int(y))
    user32.ClientToScreen(hwnd, ctypes.byref(pt))

    return pt.x, pt.y


def click_at(hwnd, x, y):
    """真·点击（屏幕坐标）：先移动再按下 —— 顺序要像真鼠标，否则 hover 那轮不存在"""
    sx, sy = int(x), int(y)
    user32.SetCursorPos(sx, sy)
    time.sleep(0.05)
    user32.PostMessageW(hwnd, 0x0200, 0, ((sy & 0xFFFF) << 16) | (sx & 0xFFFF))
    time.sleep(0.05)
    user32.PostMessageW(hwnd, 0x0201, 1, ((sy & 0xFFFF) << 16) | (sx & 0xFFFF))
    time.sleep(0.02)
    user32.PostMessageW(hwnd, 0x0202, 0, ((sy & 0xFFFF) << 16) | (sx & 0xFFFF))


def client_size(hwnd):
    r = wintypes.RECT()
    user32.GetClientRect(hwnd, ctypes.byref(r))

    return r.right, r.bottom


def to_client(hwnd, sx, sy):
    pt = wintypes.POINT(int(sx), int(sy))
    user32.ScreenToClient(hwnd, ctypes.byref(pt))

    return pt.x, pt.y


def launch(app, title, wait=4.0, env_extra=None):
    exe = os.path.join(ROOT, f"{app}.exe")
    ini = os.path.join(tempfile.mkdtemp(prefix="drift-uia-"), "php.ini")
    with open(ini, "w", encoding="ascii") as fh:
        fh.write("opcache.enable=0\n")
    env = dict(os.environ)
    env["PHPRC"] = ini
    env.update(env_extra or {})

    proc = subprocess.Popen([exe], cwd=ROOT, env=env)
    time.sleep(wait)
    hwnd = user32.FindWindowW(None, title)
    if not hwnd:
        proc.terminate()
        raise SystemExit(f"✗ 没找到窗口 {title!r}（进程活={proc.poll() is None}）")

    return proc, hwnd


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--app", default="showcase")
    ap.add_argument("--title", default="Drift 示例台")
    ap.add_argument("--dump", action="store_true", help="打印语义树（不点）")
    ap.add_argument("--click", action="append", default=None,
                    help="按 UIA 名字依次点击（可重复；每一步之间等 0.9s）")
    ap.add_argument("--no-launch", action="store_true", help="不启动，连已在跑的窗口")
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
                cw, ch = client_size(hwnd)
                cy_mid = (r.top + r.bottom) // 2
                if 0 <= cy_mid <= ch:
                    break
                step = 3 if cy_mid > ch else -3
                # 滚轮必须落在**元素所在那一列**（导航列表 / 内容区是两个独立滚动容器）。
                # 打到内容区再滚，导航纹丝不动 —— 现象与"滚轮完全失效"一模一样。
                # ★ y 取**视口中线**而不是边缘：边缘可能落在列表外（标题栏 / 面板留白），
                #   滚轮就滚到了别人身上。手感上"手指放在列表中间往上推"也是这个位置。
                wy = ch // 2
                print(f"  {target!r} 在客户区外（y={cy_mid}，视口 {ch}）⇒ 滚 {step:+d} 格")
                wheel_at(hwnd, max(4, min(r.left + 20, cw - 4)), wy, step)
                time.sleep(0.35)
            else:
                print(f"✗ 滚了 12 次仍没让 {target!r} 进入视口")
                return 1

            elem, r, _t = found[0]
            w_px, h_px = r.right - r.left, r.bottom - r.top

            # ★ 优先走**真实鼠标**（move → down → up 分三条消息）：它与用户的手
            #   完全同一条路（包括 hover 那一轮），Invoke 走的是"读屏激活"分支。
            #   provider 给的矩形是**客户区坐标**（未加屏幕偏移），正好直接可用。
            if w_px > 0 and h_px > 0:
                cx_c = (r.left + r.right) // 2
                cy_c = (r.top + r.bottom) // 2
                print(f"→ 点 {target!r} @ 客户区 ({cx_c},{cy_c})  {w_px}x{h_px}")
                click_at(hwnd, cx_c, cy_c)
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

        return 0
    finally:
        if proc is not None and proc.poll() is None:
            proc.terminate()


if __name__ == "__main__":
    raise SystemExit(main())
