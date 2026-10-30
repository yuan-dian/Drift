"""真机判据：**关闭语义**（2026-10-30，可拦截关闭）。

    python tools/probe-close.py showcase "Drift 示例台" exit     # 允许关闭 ⇒ 窗口没了、进程退出
    python tools/probe-close.py showcase "Drift 示例台" stay     # 拒绝关闭 ⇒ 窗口还在、进程还在

★ `stay` 要先让应用**拒绝**：示例台默认开关是关的（放行），所以必须设
  `DRIFT_CLOSE_CONFIRM=1`（`Bootstrap.php` 读它 ⇒ 启动即 `setConfirmClose(true)`）：

      DRIFT_CLOSE_CONFIRM=1 python tools/probe-close.py showcase "Drift 示例台" stay

★ 为什么必须有这个探针：`WindowHost::close()` 从"同步 `DestroyWindow`"改成了
  "`PostMessageW(WM_CLOSE)` ⇒ 入队 ⇒ 帧边界问应用 ⇒ 框架 `destroy()`"，
  **整条链的每一段都只在真机上跑得到**（解释器里全是假桥）。三个后果各只有真机看得见：
  ① 消息真的被投进去了（不是往一个不存在的消息循环里投）；
  ② 应用拒绝之后窗口**还在**（不是被系统或 `DefWindowProc` 顺手关掉）；
  ③ 同意之后进程**自己退出**（`WM_DESTROY` → 活窗口表空 → `PostQuitMessage`）。
  任何一段断了，现象都是"点了 X 没反应"或"关了但进程吊着"，**都不报错**。

★ 与 `probe-window.py` 的分工：那个验"建起来 / 画对 / DPI"，这个只验"关"。
  两者的启动环境（`PHPRC` 指向 `opcache.enable=0` 的临时 ini）必须一致 ——
  否则 exe 在 `main()` 之前就挂（`Opcode handlers are unusable due to ASLR`）。

★ 判据是**四态全对**才 OK：`hwnd != 0`、`IsWindow` 在等待前后如预期、`proc.poll()`
  如预期。只报"发了消息"没有意义 —— 发一条没人处理的消息也是"发了"。
"""

import ctypes
import os
import subprocess
import sys
import tempfile
import time
from ctypes import wintypes

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

user32 = ctypes.WinDLL("user32", use_last_error=True)
user32.IsWindow.argtypes = [wintypes.HWND]
user32.IsWindow.restype = wintypes.BOOL

WM_CLOSE = 0x0010

# 关窗到窗口消失的观察窗。异步路径是"下一轮循环"（≤ 一个帧间隔 + 一次消息往返），
# 1.2s 足够宽；再短会把"慢"误判成"没关"，再长则拒绝分支的断言失去意义。
SETTLE_S = 1.2


def main() -> int:
    if len(sys.argv) < 4:
        print("用法：python tools/probe-close.py <app> <窗口标题> <exit|stay>")
        return 2

    app, title, expect = sys.argv[1], sys.argv[2], sys.argv[3]
    if expect not in ("exit", "stay"):
        print(f"✗ 第三个参数只能是 exit / stay（给的是 {expect!r}）")
        return 2

    exe = os.path.join(ROOT, f"{app}.exe")
    if not os.path.isfile(exe):
        print(f"✗ 找不到 {exe} —— 先跑 `php tools/drift-build.php {app}`")
        return 2

    ini_dir = tempfile.mkdtemp(prefix="drift-close-")
    ini = os.path.join(ini_dir, "php.ini")
    with open(ini, "w", encoding="ascii") as fh:
        fh.write("opcache.enable=0\n")

    env = dict(os.environ)
    env["PHPRC"] = ini

    proc = subprocess.Popen([exe], cwd=ROOT, env=env)
    time.sleep(2.5)

    hwnd = user32.FindWindowW(None, title)
    print(f"hwnd = {hwnd}  (0 = 没找到窗口；标题应为 {title!r})")
    if hwnd == 0:
        proc.terminate()
        print("FAIL")
        return 1

    print(f"关之前：window_alive={bool(user32.IsWindow(hwnd))} process_alive={proc.poll() is None}")

    posted = user32.PostMessageW(hwnd, WM_CLOSE, 0, 0)
    print(f"PostMessageW(WM_CLOSE) = {posted}  (0 = 投递失败，通常是窗口线程没有消息队列)")
    time.sleep(SETTLE_S)

    window_alive = bool(user32.IsWindow(hwnd))
    process_alive = proc.poll() is None
    print(f"关之后（{SETTLE_S}s）：window_alive={window_alive} process_alive={process_alive}")

    if expect == "exit":
        ok = bool(posted) and not window_alive and not process_alive
        why = "期望：窗口消失 + 进程自己退出"
    else:
        ok = bool(posted) and window_alive and process_alive
        why = "期望：窗口还在 + 进程还在（应用拒绝了关闭）"

    if process_alive:
        proc.terminate()

    print(why)
    print("OK" if ok else "FAIL")
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
