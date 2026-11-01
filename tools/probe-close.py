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

★ `WM_CLOSE` 是本仓**唯一允许**用 `PostMessageW` 的"输入"：关窗本来就是消息语义，
  不依赖真实光标（对比鼠标/滚轮 —— 那两种必须走 `probe_win` 的真实输入）。
  所以本探针 `interactive=False`，在桌面锁定时也能跑。

★ 判据是**四态全对**才 OK：`hwnd != 0`、`IsWindow` 在等待前后如预期、`proc.poll()`
  如预期。只报"发了消息"没有意义 —— 发一条没人处理的消息也是"发了"。
"""

import sys
import time

from probe_check import check, need, run
from probe_win import WM_CLOSE, is_window, launch, user32, wait_window

# 关窗到窗口消失的观察窗（**拒绝关闭**分支用固定值：那时"还活着"是期望结果，
# 等久一点没有信息量）。同意关闭分支改用**轮询 + 上限**（`EXIT_DEADLINE_S`）：
# 固定 sleep 会把"慢一帧"误判成"没关" —— 2026-10-30 实测 tutorial 冷缓存下 1.25s
# 才退（收尾要写回几何 + 卸两个静态钩子），而当时的 1.2s 正好卡在边界，偶发 FAIL。
SETTLE_S = 1.2

# 同意关闭后等进程真正退出的上限。正常是一轮循环 + 一次收尾（实测 0.02~1.3s）。
EXIT_DEADLINE_S = 3.0


def main() -> int:
    if len(sys.argv) < 4:
        print("用法：python tools/probe-close.py <app> <窗口标题> <exit|stay>")
        return 2

    app, title, expect = sys.argv[1], sys.argv[2], sys.argv[3]
    if expect not in ("exit", "stay"):
        print(f"✗ 第三个参数只能是 exit / stay（给的是 {expect!r}）")
        return 2

    proc = launch(f"{app}.exe")
    try:
        hwnd = need(wait_window(title, proc), f"没等到窗口「{title}」")
        print(f"hwnd = {hwnd}")

        check("关之前：窗口在 + 进程在", is_window(hwnd) and proc.poll() is None,
              f"window={is_window(hwnd)} process={proc.poll() is None}")

        posted = user32.PostMessageW(hwnd, WM_CLOSE, 0, 0)
        check("WM_CLOSE 真投进去了（0 = 窗口线程没有消息队列）", bool(posted), f"ret={posted}")

        started = time.time()
        if expect == "exit":
            # 轮询而不是定长 sleep：慢一帧不算失败，真挂住也能在 3s 内报出来
            while time.time() - started < EXIT_DEADLINE_S:
                if not is_window(hwnd) and proc.poll() is not None:
                    break
                time.sleep(0.05)
        else:
            time.sleep(SETTLE_S)
        waited = time.time() - started

        window_alive = is_window(hwnd)
        process_alive = proc.poll() is None
        print(f"关之后（{waited:.2f}s）：window_alive={window_alive} process_alive={process_alive}")

        if expect == "exit":
            check("同意关闭 ⇒ 窗口消失 + 进程自己退出", not window_alive and not process_alive,
                  f"window={window_alive} process={process_alive}")
        else:
            check("拒绝关闭 ⇒ 窗口还在 + 进程还在", window_alive and process_alive,
                  f"window={window_alive} process={process_alive}")

        if process_alive:
            proc.terminate()
    finally:
        if proc.poll() is None:
            proc.terminate()

    return 0


if __name__ == "__main__":
    sys.exit(run(main))
