"""真机验证「语义状态」：读屏与自动化能不能问到焦点/展开/选中/层级（§65 + §66）。

    python tools/probe-uia-state.py           # AOT 产物 showcase.exe

①–⑧ 是 §65 的树行（TreeItem / 层级 / 三态展开 / 选中 / 可聚焦 / 焦点 /
Expand / Collapse）；⑨–⑫ 是 §66 的表格行（DataItem / IsSelected / Select）；
⑬a/⑬b 是 §70 补的事件判据（选中变化会不会主动通知读屏）。

★ ⑨–⑫ 只量「客户端去问时答得对」，把 `UiaRaiseAutomationEvent` 注释掉照样全绿；
  ⑬ 走 UIA 客户端订阅 → 核心分发 → 回调，是唯一独立于实现的路径。

★ 三轮判据都必须是"**客户端读得到**"，不是"PHP 侧填了"—— 属性没实现时
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
DATA_ITEM_CTRL = 50029          # §66 表格行（UIA_DataItemControlTypeId）
UIA_EXPAND_COLLAPSE_PATTERN = 10005
UIA_SELECTION_ITEM_PATTERN = 10010
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


def find_visible(uia, w, hwnd, target):
    """按名字找**有几何**的节点（§66）。

    ★ showcase 有两个表格页（Table / AsyncTable）。Offstage 页保活时同名
      「行 1」可能挂在零面积盒上，`find()` 取第一个就会命中它 ⇒ `Select()`
      合成点击被 `invokeSemanticsNode()` 的 `w<=0` 静默拒掉，看起来像
      "点不动"。`dump(found=...)` 本来就收全部命中，这里挑第一个有面积的。
    """
    import contextlib
    import io

    root = U.element_from_handle(uia, hwnd)
    found = []
    with contextlib.redirect_stdout(io.StringIO()):
        U.dump(root, w, found=found, target=target)
    for elem, r, _t in found:
        if (r.right - r.left) > 0 and (r.bottom - r.top) > 0:
            return elem, r

    return None


def find_edit(uia, w, hwnd, target):
    # 顶栏「搜索」Button 与表单「搜索」Edit 同名；只按名字取第一个会拿到按钮。
    import contextlib
    import io

    root = U.element_from_handle(uia, hwnd)
    found = []
    with contextlib.redirect_stdout(io.StringIO()):
        U.dump(root, w, found=found, target=target)
    for elem, r, _t in found:
        if U.ctype_of(elem) == 50004:      # UIA_EditControlTypeId
            # 与 `find()` 同形状：调用方按 `hit[0]` 取元素
            return elem, r
    return None


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
    """调 Pattern 的方法（3 = ExpandCollapse::Expand / SelectionItem::Select；4 = Collapse）。"""
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

        # ── §66 表格行：能查询、能选中、别的行不跟着假报 ──────────────────
        # ★ 全部走 UIA **客户端侧**：PHP 侧值对、单测全绿，但客户端读不到的事
        #   §65 已经踩过一次（不提供 Pattern ⇒ 核心自己兜底）。
        def row_prop(target, pid):
            hit = find_visible(uia, w, hwnd, target)
            if hit is None:
                return ("missing", None)

            return U.prop_of(hit[0], pid)

        if not PC.check("入口：点得到导航项 '表格 Table'", click_name(uia, w, hwnd, "表格 Table")):
            PC.skip("表格页没切过去，行语义一个都量不到")

        hit = find_visible(uia, w, hwnd, "行 1")
        if not PC.check("入口：UIA 树里有有几何的 '行 1'", hit is not None):
            PC.skip("表格行没渲染出来（或全挂在零面积盒上）")

        ctrl = U.ctype_of(hit[0])
        PC.check("⑨ 表格行是 DataItem（不是 Custom/Button）", ctrl == DATA_ITEM_CTRL, f"controlType={ctrl}")

        # `IsSelected` 只有两态：0 与 1 都报 False。★ 判 `is False` 而不是
        #   `not ...` —— VT_EMPTY 时 `prop_of` 返回 None，那种"根本没答"必须报红。
        sel = row_prop("行 1", U.UIA_SelectionItemIsSelectedPropertyId)[1]
        PC.check("⑩ 未选中行 IsSelected = False（不是 VT_EMPTY）", sel is False, sel)

        obj = pattern(hit[0], UIA_SELECTION_ITEM_PATTERN) if hit else None
        if not PC.check("入口：行拿得到 SelectionItemPattern", obj is not None):
            PC.skip("没有 Pattern，⑪⑫⑬ 量不到")

        # ⑬ 订阅必须在 Select() 之前：属性可查 ≠ 变化会通知，事件是第三种可观察量。
        root = U.element_from_handle(uia, hwnd)
        if not root or not root.value:
            PC.skip("订阅 20012 拿不到窗口根元素")
        event_id = U.UIA_SelectionItem_ElementSelectedEventId
        try:
            sub = U.AutomationEventSubscription(uia, root, event_id)
        except OSError as exc:
            PC.skip(f"AddAutomationEventHandler 失败：{exc}")

        with sub:
            U.pump(0.3)
            quiet = len(sub.events)
            PC.check("⑬a 静默窗口没有 20012 噪声（防『一直有事件』的假绿）",
                     quiet == 0, f"订阅后未操作时事件数={quiet}")
            sub.events.clear()

            call_pattern(obj, 3)      # Select()（vtable slot 3）
            # ★ 轮询而不是固定 sleep：合成点击 → 受控选中集 → setState → 重推语义树
            #   要跨至少一帧；固定 sleep 在负载高时偶发红，且分不清"没实现"和"还没推上来"。
            #   泵消息而不是 sleep：UIA 事件回调经窗口消息投递，不泵收不到。
            got = None
            for _ in range(25):
                got = row_prop("行 1", U.UIA_SelectionItemIsSelectedPropertyId)[1]
                if got is True:
                    break
                U.pump(0.1)
            select_ok = PC.check("⑪ Select() 真的选中行 1（COM → PostMessage → 合成点击 → 受控选中集）",
                                 got is True, got)

            other = row_prop("行 2", U.UIA_SelectionItemIsSelectedPropertyId)[1]
            PC.check("⑫ 另一行仍是 False（不是全表都报选中）", other is False, other)

            # ★ 显式依赖 ⑪：没选中时「没收到事件」与「根本没选中」分不开 ⇒ 只能 SKIP
            if not select_ok:
                PC.skip("⑪ 没选中，⑬ 的事件判据没意义")
            arrived = sub.wait(2.0, lambda ev: any(e[0] == event_id for e in ev))
            names = [e[1] for e in sub.events if e[0] == event_id]
            PC.check("⑬b 选中变化真的 raise 了 ElementSelected（20012，经 UIA 核心分发）",
                     arrived and len(names) > 0,
                     f"事件数={len(names)} sender={names[:2]}")

        # ── P0-2：属性面 / 容器 Pattern / 事件面 ──────────────────────
        # 全部走 UIA 客户端：PHP 侧填了但 provider 没答 ⇒ VT_EMPTY，不报错。
        if not PC.check("入口：点得到导航项 '表单 Form'",
                        click_name(uia, w, hwnd, "表单 Form")):
            PC.skip("表单页没切过去，⑭⑮⑯ 量不到")

        search = find_edit(uia, w, hwnd, "搜索")
        if not PC.check("入口：UIA 树里有 '搜索' 输入框", search is not None):
            PC.skip("没有输入框，⑭⑮⑯ 量不到")

        help_v = U.prop_of(search[0], U.UIA_HelpTextPropertyId)[1]
        PC.check("⑭ HelpText 读到业务声明的提示（不是 VT_EMPTY）",
                 help_v == "输入关键词后按 Enter 提交", help_v)
        aid = U.prop_of(search[0], U.UIA_AutomationIdPropertyId)[1]
        PC.check("⑮ AutomationId 读到稳定标识（不是 VT_EMPTY）",
                 aid == "form.search", aid)

        val_obj = pattern(search[0], U.UIA_ValuePatternId)
        if not PC.check("入口：输入框拿得到 ValuePattern", val_obj is not None):
            PC.skip("没有 ValuePattern，⑯ 触发不了值变化")

        try:
            sub2 = U.AutomationEventSubscription(uia, root, U.UIA_Text_TextChangedEventId)
        except OSError as exc:
            PC.skip(f"订阅 TextChanged 失败：{exc}")

        with sub2:
            U.pump(0.3)
            quiet2 = len(sub2.events)
            PC.check("⑯a 静默窗口没有 TextChanged 噪声（防『一直有事件』假绿）",
                     quiet2 == 0, quiet2)
            sub2.events.clear()
            U.set_value_pattern(val_obj, "P0-2 事件面")

            got = None
            for _ in range(25):
                hit = find_edit(uia, w, hwnd, "搜索")
                got = U.prop_of(hit[0], U.UIA_ValueValuePropertyId)[1] if hit else None
                if got == "P0-2 事件面":
                    break
                U.pump(0.1)
            if not PC.check("⑯b SetValue 真的改到了值（COM → PostMessage → 受控值）",
                            got == "P0-2 事件面", got):
                PC.skip("值没改，⑯c 的『没收到事件』与『根本没变化』分不开")
            arrived = sub2.wait(
                2.0, lambda ev: any(e[0] == U.UIA_Text_TextChangedEventId for e in ev))
            PC.check("⑯c 值变化真的 raise 了 TextChanged（经 UIA 核心分发）",
                     arrived, f"事件数={len(sub2.events)}")

        # 容器 SelectionPattern：从行节点沿 Fragment Parent 上溯，不依赖控件名字
        if not PC.check("入口：点得到导航项 '表格 Table'",
                        click_name(uia, w, hwnd, "表格 Table")):
            PC.skip("表格页没切过去，⑰⑱⑲⑳ 量不到")

        row = find_visible(uia, w, hwnd, "行 1")
        container = U.walker_parent(w, row[0]) if row else None
        if not PC.check("入口：行 1 的 Fragment Parent 拿得到容器", container is not None):
            PC.skip("找不到容器节点，⑰⑱⑲⑳ 量不到")

        sel_obj = pattern(container, U.UIA_SelectionPatternId)
        if not PC.check("⑰ 容器拿得到 SelectionPattern（按子节点状态派生）",
                        sel_obj is not None):
            PC.skip("没有 SelectionPattern，⑱⑲ 量不到")

        hr, multi = U.pattern_bool(sel_obj, 4)
        PC.check("⑱ CanSelectMultiple = False", hr == 0 and multi is False, (hex(hr), multi))
        hr, cnt = U.pattern_selection_count(sel_obj)
        PC.check("⑲ GetCurrentSelection 读到合法元素数（不是垃圾值）",
                 hr == 0 and 0 <= cnt <= 10, (hex(hr), cnt))

        nav = find(uia, w, hwnd, "表单 Form")
        nav_sel = pattern(nav[0], U.UIA_SelectionPatternId) if nav else None
        PC.check("⑳ 导航按钮不该拿到 SelectionPattern（防 predicate 恒真）",
                 nav_sel is None, nav_sel)

        return None
    finally:
        proc.terminate()


if __name__ == "__main__":
    sys.exit(PC.run(main))
