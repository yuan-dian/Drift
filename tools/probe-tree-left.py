"""真机验证「焦点通道」：树的 `←` 真的把焦点交给了父行（§64）。

    python tools/probe-tree-left.py           # AOT 产物 showcase.exe
    python tools/probe-tree-left.py --dev     # dev 壳（改 PHP 不用重编译）

★ 判据为什么不看像素、也不看 `HasKeyboardFocus`：
  · 树页那段「当前选中：X」是 `Text`，**不进 UIA 树** ⇒ 读不到；
  · 写这条探针时（§64）`HasKeyboardFocus` 还没实现 ⇒ 恒 false。§65 之后能读了
    （`probe-uia-state.py` ⑥ 就在验它），但这条探针**故意仍用结构判据**：
    它能跑 dev 壳，而 dev 壳的壳侧是冻结的（旧 C++ 读不到新字段）；结构判据
    只依赖 PHP 推的语义树，两种壳都成立 ⇒ 反向变异（改 PHP）才做得成。

  所以判据用**结构**（UIA 语义树里行的存在性），它只随"折叠"变化：

    点 'Button.php'（叶子，父是 'Widget'）
      └ ← 第一次：跳父行，结构不变 ⇒ 'TreeView.php' 还在
      └ ← 第二次：焦点已在 'Widget'（展开的分支）⇒ 折叠它 ⇒ 'TreeView.php' 消失

  **通道失效**时两次 ← 都只是"叶子跳父行"，'Widget' 永远不折叠 ⇒ 子行还在 ⇒ 红。
  这条反向变异在 dev 壳上实跑过（把 `Application::requestFocus()` 空掉 ⇒ 红）。

★ 为什么值得单独一个探针：`instanceof Widget层接口` + 从 `Pipeline` 取出的
  `?object` 上做动态派发，是本仓的新形状 —— 解释器全绿、AOT 真机可能静默不动。
  单元测试抓不到这一层。
"""

import ctypes
import importlib.util
import os
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "tools"))

import probe_win as win  # noqa: E402

# `probe-uia.py` 文件名带横线，import 语句用不了 ⇒ 走 importlib
_spec = importlib.util.spec_from_file_location("probe_uia", os.path.join(ROOT, "tools", "probe-uia.py"))
U = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(U)

VK_LEFT = 0x25


def names(uia, w, hwnd):
    """整棵语义树里的名字（走 `element_from_handle`，与 `dump()` 同一条路）。"""
    root = U.element_from_handle(uia, hwnd)
    out = []

    def walk(e, depth=0):
        n = U.name_of(e)
        if n:
            out.append(n)
        if depth >= 14:
            return
        c = U.child(w, e)
        while c:
            walk(c, depth + 1)
            c = U.sibling(w, c)

    walk(root)

    return out


def find_rect(uia, w, hwnd, target):
    """复用 `dump(found=..., target=...)` —— 那条路已验证过能按名字命中。"""
    import contextlib
    import io

    root = U.element_from_handle(uia, hwnd)
    found = []
    with contextlib.redirect_stdout(io.StringIO()):
        U.dump(root, w, found=found, target=target)
    if not found:
        return None

    r = found[0][1]

    return r.left, r.top, r.right, r.bottom


def click_name(uia, w, hwnd, target, wait=1.2):
    """按 UIA 名字点；不在视口中部就先滚。

    ★ 可见性判据比 `probe-uia.py --click` 更严：那边只判"在客户区内"，
      而导航项会被 `List` 裁掉 —— 实测 `'树 Tree'` 报 y=631 / 视口 640，
      `List` 底只到 618 ⇒ 点在列表外，页面不切。这里要求落在**中部**。
    """
    for _ in range(12):
        r = find_rect(uia, w, hwnd, target)
        if r is None:
            return False
        left, top, right, bottom = r
        cw, ch = win.client_size(hwnd)
        cx_c, cy_c = win.screen_to_client(hwnd, (left + right) // 2, (top + bottom) // 2)
        if ch * 0.12 <= cy_c <= ch * 0.78:
            break
        step = 8 if cy_c > ch * 0.5 else -8
        win.wheel(hwnd, max(4, min(cx_c, cw - 4)), ch // 2, step)
        time.sleep(0.5)
    else:
        return False

    cx, cy = win.screen_to_client(hwnd, (left + right) // 2, (top + bottom) // 2)
    win.click(hwnd, cx, cy, settle=0.0)
    time.sleep(wait)

    return True


def main():
    dev = "--dev" in sys.argv
    fails = []

    if dev:
        entry = os.path.join(ROOT, "apps", "dev", "dev-entry.php").replace("\\", "/")
        proc = win.launch("dev.exe", env_extra={"DRIFT_DEV_ENTRY": entry, "DRIFT_DEV_APP": "showcase"})
        hwnd = win.wait_window("Drift 示例台", proc, 20.0)
    else:
        proc, hwnd = U.launch("showcase", "Drift 示例台", wait=6.0)

    try:
        uia = U.uia_create()
        w = U.walker(uia, control_view=True)

        if not click_name(uia, w, hwnd, "树 Tree"):
            fails.append("点不到导航项 '树 Tree'")
        elif not click_name(uia, w, hwnd, "Button.php"):
            fails.append("点不到树行 'Button.php'")

        if not fails:
            before = set(names(uia, w, hwnd))
            if "TreeView.php" not in before or "drift2" not in before:
                fails.append("前置不成立：树没展开到 Widget 的三个子行")

            win.key(hwnd, VK_LEFT, settle=0.45)
            mid = set(names(uia, w, hwnd))
            if "TreeView.php" not in mid:
                fails.append("第一次 ← 就把 Widget 折叠了 —— 焦点没跳到父行?")

            win.key(hwnd, VK_LEFT, settle=0.45)
            after = set(names(uia, w, hwnd))
            if "TreeView.php" in after:
                fails.append("焦点没被交给父行：第二次 ← 没能折叠 Widget（子行还在）")
            if "drift2" not in after:
                fails.append("树被整棵弄丢了（drift2 不见了）")
    finally:
        proc.terminate()

    if fails:
        print("\n--- FAIL ---")
        for f in fails:
            print(" -", f)

        return 1

    print("\n--- 全过：真键盘 ← 把焦点交给了父行（AOT 派发可用）---")

    return 0


if __name__ == "__main__":
    sys.exit(main())
