"""真机验证「语义状态」：读屏与自动化能不能问到焦点/展开/选中/层级（§65）。

    python tools/probe-uia-state.py           # AOT 产物 showcase.exe

★ 这轮补的是 `GetPropertyValue` 的属性出口 + `SelectionItem` / `ExpandCollapse`
  两个 Pattern。**判据必须是"客户端读得到"**，不是"PHP 侧填了"—— 属性没实现时
  客户端读到 `VT_EMPTY`、Pattern 没实现时 UIA 核心自己兜底（真机实测：展开态全
  报 `LeafNode`、选中全报 `False`），两种都不报错、无日志，单元测试全绿。

★ 三态是这轮的核心：`LeafNode`（不可展开）与 `Collapsed`（能展开、现在收着）必须
  分得开。叶子报 `Collapsed` 时读屏会提示按右方向键，而按了没反应。

对照的树页结构（`apps/showcase/src/Pages/TreePage.php` 默认展开 project/src/widget）：

    drift2            L1  分支 展开
      src             L2  分支 展开
        Widget        L3  分支 展开
          TreeView.php  L4  叶子 **选中**
          LazyColumn.php L4 叶子
          Button.php  L4  叶子
      docs            L2  分支 **折叠**
"""

import ctypes
import importlib.util
import os
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "tools"))

import probe_check as PC  # noqa: E402
import probe_win as win  # noqa: E402

_spec = importlib.util.spec_from_file_location("probe_uia", os.path.join(ROOT, "tools", "probe-uia.py"))
U = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(U)

TREE_ITEM_CTRL = 50024
UIA_EXPAND_COLLAPSE_PATTERN = 10005
# `ExpandCollapseState` 枚举（UIAutomationCore.h）
COLLAPSED = 0
EXPANDED = 1
PARTIALLY_EXPANDED = 2
LEAF_NODE = 3


def find(uia, w, hwnd, target):
    """按 UIA 名字找一个元素（`dump(found=...)` 那条路已验证能命中）。"""
    import contextlib
    import io

    root = U.element_from_handle(uia, hwnd)
    found = []
    with contextlib.redirect_stdout(io.StringIO()):
        U.dump(root, w, found=found, target=target)
    if not found:
        return None

    return found[0][0], found[0][1]


def click_name(uia, w, hwnd, target, wait=1.2):
    """按名字点；不在视口中部就先滚（同 `probe-tree-left.py`，那边记了为什么）。"""
    for _ in range(12):
        hit = find(uia, w, hwnd, target)
        if hit is None:
            return False
        _e, r = hit
        cw, ch = win.client_size(hwnd)
        cx_c, cy_c = win.screen_to_client(hwnd, (r.left + r.right) // 2, (r.top + r.bottom) // 2)
        if ch * 0.12 <= cy_c <= ch * 0.78:
            break
        step = 8 if cy_c > ch * 0.5 else -8
        win.wheel(hwnd, max(4, min(cx_c, cw - 4)), ch // 2, step)
        time.sleep(0.5)
    else:
        return False

    cx, cy = win.screen_to_client(hwnd, (r.left + r.right) // 2, (r.top + r.bottom) // 2)
    win.click(hwnd, cx, cy, settle=0.0)
    time.sleep(wait)

    return True


def pattern(elem, pattern_id):
    """取 Pattern 对象（`GetCurrentPattern`）。拿不到 ⇒ None。"""
    unk = ctypes.c_void_p()
    fn = U._call(elem, U.ELEM_GetCurrentPattern, ctypes.HRESULT,
                 ctypes.c_int, ctypes.POINTER(ctypes.c_void_p))
    fn(elem, ctypes.c_int(pattern_id), ctypes.byref(unk))

    return unk.value


def call_pattern(obj, slot):
    """调 Pattern 的方法（`IExpandCollapseProvider`：3=Expand 4=Collapse）。"""
    fn = U._call(obj, slot, ctypes.HRESULT)
    fn(obj)


def main():
    # `--dev`：跑 dev 壳。★ 只能验 **PHP 侧**逻辑 —— dev 壳的壳侧是冻结的
    #   （旧 `drift_win32`），新字段一律读成 0 / 没 Pattern，①–⑤ 全红。
    #   这条路径留给"改 PHP 之后做反向变异"，C++ 侧只能走 AOT。
    if "--dev" in sys.argv:
        entry = os.path.join(ROOT, "apps", "dev", "dev-entry.php").replace("\\", "/")
        proc = win.launch("dev.exe", env_extra={"DRIFT_DEV_ENTRY": entry, "DRIFT_DEV_APP": "showcase"})
        hwnd = win.wait_window("Drift 示例台", proc, 20.0)
    else:
        proc, hwnd = U.launch("showcase", "Drift 示例台", wait=6.0)

    try:
        uia = U.uia_create()
        w = U.walker(uia, control_view=True)

        def prop(target, pid):
            hit = find(uia, w, hwnd, target)
            if hit is None:
                return ("missing", None)

            return U.prop_of(hit[0], pid)

        if not PC.check("入口：点得到导航项 '树 Tree'", click_name(uia, w, hwnd, "树 Tree")):
            PC.skip("导航页没切过去，树的语义一个都量不到")

        # ① 行的角色：TreeItem 而不是 Button。
        #    退化成 Button ⇒ 读屏把整棵树念成"一堆按钮"，层级/展开全丢。
        hit = find(uia, w, hwnd, "drift2")
        if not PC.check("入口：语义树里有 drift2 这一行", hit is not None):
            PC.skip("树没渲染出来")
        ctrl = U.ctype_of(hit[0])
        PC.check("① 树行是 TreeItem（不是 Button）", ctrl == TREE_ITEM_CTRL, f"controlType={ctrl}")

        # ② 层级：1-based，逐层 +1（读屏靠它念"第 N 层"）
        lv = {n: prop(n, U.UIA_LevelPropertyId)[1] for n in
              ("drift2", "docs", "Widget", "TreeView.php")}
        PC.check("② 层级 1/2/3/4 与树的深度一致",
                 lv == {"drift2": 1, "docs": 2, "Widget": 3, "TreeView.php": 4}, lv)

        # ③ 展开态三态：UIA 侧的落点就是 Expanded / Collapsed / **LeafNode**。
        #    ★ 叶子必须是 LeafNode(3) 而不是 Collapsed(0)：Collapsed 意味着
        #      "能展开、现在收着"，读屏会提示用户按右方向键 —— 按了没反应。
        #    ★ 也**不是** VT_EMPTY：UIA 核心对"不提供 ExpandCollapsePattern"的
        #      节点自己兜底成 LeafNode（第一版判据写 None，被真机打回）。
        ex = {n: prop(n, U.UIA_ExpandCollapseExpandCollapseStatePropertyId)[1]
              for n in ("drift2", "docs", "TreeView.php")}
        PC.check("③ 展开/折叠/叶子 三态分得开（叶子是 LeafNode，不是 Collapsed）",
                 ex["drift2"] == EXPANDED and ex["docs"] == COLLAPSED
                 and ex["TreeView.php"] == LEAF_NODE, ex)

        # ④ 选中：只落在选中那行（UIA 的 IsSelected 只有两态，0 与 1 都报 False
        #    —— 三态在客户端侧只体现为"给不给 SelectionItemPattern"）
        se = {n: prop(n, U.UIA_SelectionItemIsSelectedPropertyId)[1]
              for n in ("TreeView.php", "Button.php")}
        PC.check("④ 选中态只落在选中行（另一个是 False，不是 None）",
                 se["TreeView.php"] is True and se["Button.php"] is False, se)

        # ⑤ 可聚焦：树行能 Tab/方向键进去
        fo = prop("drift2", U.UIA_IsKeyboardFocusablePropertyId)[1]
        PC.check("⑤ 树行 IsKeyboardFocusable = true", fo is True, fo)

        # ⑥ 焦点：点一行之后客户端问得到"焦点在哪"。
        #    ★ 这条是 §64.7 当时绕过去的那个坑 —— 那时 provider 没实现
        #      `HasKeyboardFocus`，只能靠"折叠后子行消失"这种结构判据。
        #    ★ 轮询而不是固定 sleep：同一条判据曾偶发红（负载高一帧跨过 0.8s），
        #      而红的时候看不出是"没实现"还是"还没推上来"。
        if not PC.check("入口：点得到树行 'Button.php'", click_name(uia, w, hwnd, "Button.php", wait=0.4)):
            PC.skip("点不到目标行，⑥–⑧ 量不到")
        got = None
        for _ in range(25):
            got = prop("Button.php", U.UIA_HasKeyboardFocusPropertyId)[1]
            if got is True:
                break
            time.sleep(0.1)
        other = prop("TreeView.php", U.UIA_HasKeyboardFocusPropertyId)[1]
        PC.check("⑥ 点过的那一行 HasKeyboardFocus = true，别处 false",
                 got is True and other is False, {"Button.php": got, "TreeView.php": other})

        # ⑦⑧ 动作方向：`Expand()` / `Collapse()` 真的改界面。
        #   这是新转投链路的唯一证据（COM → PostMessage → 事件队列 → PHP）。
        #   ★ 判据用**结构**（子行出不出现）而不只是属性：属性可能缓存，
        #     结构只随"真的展开了"变化。
        hit = find(uia, w, hwnd, "docs")
        obj = pattern(hit[0], UIA_EXPAND_COLLAPSE_PATTERN) if hit else None
        if not PC.check("入口：docs 拿得到 ExpandCollapsePattern", obj is not None):
            PC.skip("没有 Pattern，⑦⑧ 量不到")

        call_pattern(obj, 3)      # Expand
        st7 = None
        has_child = False
        for _ in range(25):
            st7 = prop("docs", U.UIA_ExpandCollapseExpandCollapseStatePropertyId)[1]
            has_child = find(uia, w, hwnd, "README.md") is not None
            if st7 == EXPANDED and has_child:
                break
            time.sleep(0.1)
        ok7 = PC.check("⑦ Expand() 真的展开了 docs（子行出现）",
                       st7 == EXPANDED and has_child, (st7, f"README.md={has_child}"))

        # ★ ⑧ 必须显式依赖 ⑦ 的成功：docs 本来就折叠着，所以"折叠后 README.md
        #   仍不存在"在 ⑦ 失败时也成立 —— 那条判据会**假绿**（反向变异实测：
        #   ⑦ 红、⑧ 绿）。前置不成立就是"没测到"，不写"没变"。
        if not ok7:
            PC.skip("⑦ 的前置不成立（docs 没展开），⑧ 量不到")

        hit2 = find(uia, w, hwnd, "docs")
        obj2 = pattern(hit2[0], UIA_EXPAND_COLLAPSE_PATTERN) if hit2 else None
        if not PC.check("入口：展开后仍拿得到 docs 的 Pattern", obj2 is not None):
            PC.skip("⑦ 之后拿不到 Pattern，⑧ 量不到")

        call_pattern(obj2, 4)     # Collapse
        st = None
        gone = False
        for _ in range(25):
            st = prop("docs", U.UIA_ExpandCollapseExpandCollapseStatePropertyId)[1]
            gone = find(uia, w, hwnd, "README.md") is None
            if st == COLLAPSED and gone:
                break
            time.sleep(0.1)
        PC.check("⑧ Collapse() 真的折叠了 docs（子行消失）",
                 st == COLLAPSED and gone, (st, f"README.md 消失={gone}"))

        return None
    finally:
        proc.terminate()


if __name__ == "__main__":
    sys.exit(PC.run(main))
