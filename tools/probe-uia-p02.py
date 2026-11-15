"""P0-2 真机探针（只读通道）：语义属性 / 容器 Pattern / 事件面。

    python tools/probe-uia-p02.py

★ 为什么不复用 `probe-uia-state.py` 的 `click_name()`：那个走真实输入，
  桌面锁定 / 非交互会话里 `require_foreground()` 会 SKIP。本探针全程走
  **UIA COM**：切页用 `Invoke()`（provider 投窗口消息）、改值用 `SetValue()`，
  都不需要前台窗口；判据仍然是「客户端从 UIA 读回来」，不是读 PHP 配置。

★ 只读 ≠ 不操作：`Invoke` / `SetValue` 都是标准 UIA 客户端动作，与读屏一致。
"""

import contextlib
import ctypes
import importlib.util
import io
import os
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "tools"))

import probe_check as PC  # noqa: E402

_spec = importlib.util.spec_from_file_location("probe_uia", os.path.join(ROOT, "tools", "probe-uia.py"))
U = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(U)


def pattern(elem, pattern_id):
    unk = ctypes.c_void_p()
    fn = U._call(elem, U.ELEM_GetCurrentPattern, ctypes.HRESULT,
                 ctypes.c_int, ctypes.POINTER(ctypes.c_void_p))
    fn(elem, ctypes.c_int(pattern_id), ctypes.byref(unk))
    return unk.value


def find_all(uia, w, hwnd, target):
    root = U.element_from_handle(uia, hwnd)
    found = []
    with contextlib.redirect_stdout(io.StringIO()):
        U.dump(root, w, found=found, target=target)
    return found


def find(uia, w, hwnd, target):
    hits = find_all(uia, w, hwnd, target)
    return hits[0][0] if hits else None


def find_visible(uia, w, hwnd, target):
    for elem, r, _t in find_all(uia, w, hwnd, target):
        if (r.right - r.left) > 0 and (r.bottom - r.top) > 0:
            return elem
    return None


EDIT_CTRL = 50004          # UIA_EditControlTypeId


def find_edit(uia, w, hwnd, target):
    # 顶栏也有一个「搜索」Button；只按名字取第一个会拿到按钮，属性全空。
    for elem, _r, _t in find_all(uia, w, hwnd, target):
        if U.ctype_of(elem) == EDIT_CTRL:
            return elem
    return None


def await_elem(uia, w, hwnd, target, timeout=4.0):
    end = time.time() + timeout
    while time.time() < end:
        elem = find(uia, w, hwnd, target)
        if elem is not None:
            return elem
        U.pump(0.1)
    return None


def invoke_nav(uia, w, hwnd, name):
    """用 InvokePattern 切导航页：不抢前台、不送真实点击。"""
    elem = find(uia, w, hwnd, name)
    if elem is None:
        return False
    obj = pattern(elem, U.UIA_InvokePatternId)
    if obj is None:
        return False
    fn = U._call(obj, U.INVOKE_Invoke, ctypes.HRESULT)
    return (fn(obj) & 0xFFFFFFFF) == 0


def go(uia, w, hwnd, nav, marker, picker=None):
    if not invoke_nav(uia, w, hwnd, nav):
        return None
    pick = picker or find
    end = time.time() + 4.0
    while time.time() < end:
        elem = pick(uia, w, hwnd, marker)
        if elem is not None:
            return elem
        U.pump(0.1)
    return None


def main():
    proc, hwnd = U.launch("showcase", "Drift 示例台", wait=8.0)
    try:
        uia = U.uia_create()
        w = U.walker(uia, control_view=True)
        root = U.element_from_handle(uia, hwnd)
        if not root or not root.value:
            PC.skip("拿不到窗口根元素")

        # ── 表单页：静态描述 + 值变化事件 ────────────────────────────
        search = go(uia, w, hwnd, "表单 Form", "搜索", find_edit)
        if not PC.check("入口：Invoke 切到表单页且找得到 '搜索' 输入框",
                        search is not None):
            PC.skip("表单页没切过去，属性面与事件面量不到")

        help_v = U.prop_of(search, U.UIA_HelpTextPropertyId)[1]
        PC.check("① HelpText 读到业务声明的提示（不是 VT_EMPTY）",
                 help_v == "输入关键词后按 Enter 提交", help_v)
        aid = U.prop_of(search, U.UIA_AutomationIdPropertyId)[1]
        PC.check("② AutomationId 读到稳定标识（不是 VT_EMPTY）",
                 aid == "form.search", aid)

        val_obj = pattern(search, U.UIA_ValuePatternId)
        if not PC.check("入口：输入框拿得到 ValuePattern", val_obj is not None):
            PC.skip("没有 ValuePattern，③④ 触发不了值变化")

        try:
            sub = U.AutomationEventSubscription(uia, root, U.UIA_Text_TextChangedEventId)
        except OSError as exc:
            PC.skip(f"订阅 TextChanged 失败：{exc}")

        with sub:
            U.pump(0.3)
            quiet = len(sub.events)
            PC.check("③a 静默窗口没有 TextChanged 噪声（防『一直有事件』假绿）",
                     quiet == 0, quiet)
            sub.events.clear()
            U.set_value_pattern(val_obj, "P0-2 事件面")

            got = None
            for _ in range(30):
                hit = find_edit(uia, w, hwnd, "搜索")
                got = U.prop_of(hit, U.UIA_ValueValuePropertyId)[1] if hit else None
                if got == "P0-2 事件面":
                    break
                U.pump(0.1)
            if not PC.check("③b SetValue 真的改到了值（COM → PostMessage → 受控值）",
                            got == "P0-2 事件面", got):
                PC.skip("值没改，③c 的『没收到事件』与『根本没变化』分不开")
            arrived = sub.wait(
                2.5, lambda ev: any(e[0] == U.UIA_Text_TextChangedEventId for e in ev))
            PC.check("③c 值变化真的 raise 了 TextChanged（经 UIA 核心分发）",
                     arrived, f"事件数={len(sub.events)}")

        # ── 表格页：容器 SelectionPattern ────────────────────────────
        row = go(uia, w, hwnd, "表格 Table", "行 1")
        if not PC.check("入口：Invoke 切到表格页且找得到 '行 1'", row is not None):
            PC.skip("表格页没切过去，⑤⑥⑦⑧ 量不到")
        row = find_visible(uia, w, hwnd, "行 1") or row

        container = U.walker_parent(w, row)
        if not PC.check("入口：行 1 的 Fragment Parent 拿得到容器",
                        container is not None):
            PC.skip("找不到容器节点，⑤⑥⑦ 量不到")

        sel_obj = pattern(container, U.UIA_SelectionPatternId)
        if not PC.check("⑤ 容器拿得到 SelectionPattern（按子节点状态派生）",
                        sel_obj is not None):
            PC.skip("没有 SelectionPattern，⑥⑦ 量不到")

        hr, multi = U.pattern_bool(sel_obj, 4)
        PC.check("⑥ CanSelectMultiple = False", hr == 0 and multi is False,
                 (hex(hr), multi))
        hr0, before = U.pattern_selection_count(sel_obj)
        PC.check("⑦a 未选中时 GetCurrentSelection 长度为 0",
                 hr0 == 0 and before == 0, (hex(hr0), before))

        item = pattern(row, 10010)          # SelectionItemPattern
        if not PC.check("入口：行 1 拿得到 SelectionItemPattern", item is not None):
            PC.skip("没有 SelectionItemPattern，⑦c 造不出选中态")
        fn = U._call(item, 3, ctypes.HRESULT)
        fn(item)
        selected = None
        for _ in range(30):
            hit = find_visible(uia, w, hwnd, "行 1")
            selected = U.prop_of(hit, U.UIA_SelectionItemIsSelectedPropertyId)[1] if hit else None
            if selected is True:
                break
            U.pump(0.1)
        if not PC.check("⑦b Select() 真的选中了行 1", selected is True, selected):
            PC.skip("没选中，⑦c 的长度变化量不到")
        row2 = find_visible(uia, w, hwnd, "行 1") or row
        container2 = U.walker_parent(w, row2)
        sel2 = pattern(container2, U.UIA_SelectionPatternId) if container2 else None
        hr1, after = U.pattern_selection_count(sel2) if sel2 else (-1, -1)
        PC.check("⑦c 选中后 GetCurrentSelection 长度为 1（从子节点状态派生）",
                 hr1 == 0 and after == 1, (hex(hr1), after))

        nav = find(uia, w, hwnd, "表单 Form")
        nav_sel = pattern(nav, U.UIA_SelectionPatternId) if nav else None
        PC.check("⑧ 导航按钮不该拿到 SelectionPattern（防 predicate 恒真）",
                 nav_sel is None, nav_sel)

        return None
    finally:
        proc.terminate()


if __name__ == "__main__":
    sys.exit(PC.run(main))
