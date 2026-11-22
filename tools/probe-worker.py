"""真机验证 AOT worker 的行协议往返 —— 补窗口探针从没覆盖的中段。

    python tools/probe-worker.py                 # 默认 watermark
    python tools/probe-worker.py users           # 指定应用（必须有构建的 exe）

判据（五条缺一不可）：
  ① 进程活着且不建窗（worker 只跑协议循环，不进 UI）
  ② 已注册任务成功回包
  ③ 未注册任务回 error 行，不把 worker 打死
  ④ 错误之后还能再跑成功任务（错误隔离是真进程池的价值）
  ⑤ 关 stdin ⇒ 退出码 0（正常收工，不是被杀）

★ 任务用 `CoreJobs` 自带的 `drift.fetchImage`：它遇到本地路径直接短路返回，
  零网络、零应用改动，且自动注册进每个 worker。所以本探针对所有应用通用。
★ 独立成层而不是塞进 `probe-window.py`：那条是 `interactive=False`，桌面锁定时
  照样能跑；这里要管道，两者前置条件不同，混在一起会让窗口判据跟着 SKIP。

★ 与成熟框架的对应：Playwright / FlaUI 的 IPC 集成测试验的是"消息真的过了
  边界"，不是单侧函数返回值 —— 这里验的正是 stdin 写进、AOT job 跑过、
  stdout 读回这条完整边界。
"""

import ctypes
import json
import os
import queue
import subprocess
import sys
import tempfile
import threading
import time
from ctypes import wintypes

from probe_check import check, run, skip
from probe_win import ROOT, launch, user32

SWITCH = "--drift-worker"
PING_JOB = "drift.fetchImage"  # CoreJobs：url 是本地文件时直接 return，不碰网络
READ_TIMEOUT = 8.0


def _windows_of(pid):
    """这个进程建了哪些顶层窗口（有标题的才算，worker 应当是 0）"""
    proto = ctypes.WINFUNCTYPE(wintypes.BOOL, wintypes.HWND, wintypes.LPARAM)
    user32.EnumWindows.argtypes = [proto, wintypes.LPARAM]
    user32.EnumWindows.restype = wintypes.BOOL
    user32.GetWindowThreadProcessId.argtypes = [wintypes.HWND, ctypes.POINTER(wintypes.DWORD)]
    user32.GetWindowTextLengthW.argtypes = [wintypes.HWND]
    user32.GetWindowTextW.argtypes = [wintypes.HWND, wintypes.LPWSTR, ctypes.c_int]

    found = []

    def visit(hwnd, _):
        owner = wintypes.DWORD()
        user32.GetWindowThreadProcessId(hwnd, ctypes.byref(owner))
        if owner.value == pid:
            size = user32.GetWindowTextLengthW(hwnd)
            buf = ctypes.create_unicode_buffer(size + 1)
            user32.GetWindowTextW(hwnd, buf, size + 1)
            found.append(buf.value)
        return True

    user32.EnumWindows(proto(visit), 0)

    return found


def _pump_stdout(proc, out):
    """线程读管道：Windows 的 select 不支持匿名管道，不能照搬 socket 那套轮询。"""
    try:
        for raw in proc.stdout:
            out.put(raw.decode("utf-8", "replace").strip())
    except Exception:  # noqa: BLE001 —— 收尾时管道会关，属正常
        pass
    finally:
        out.put(None)


def _send(proc, obj):
    proc.stdin.write((json.dumps(obj, ensure_ascii=False) + "\n").encode("utf-8"))
    proc.stdin.flush()


def _reply(out):
    try:
        line = out.get(timeout=READ_TIMEOUT)
    except queue.Empty:
        return None
    if line is None:
        return None
    try:
        msg = json.loads(line)
    except ValueError:
        return None

    return msg if isinstance(msg, dict) else None


def main():
    app = sys.argv[1] if len(sys.argv) > 1 else "watermark"
    exe = os.path.join(ROOT, app + ".exe")
    if not os.path.isfile(exe):
        skip(f"找不到 {exe} —— 先 php tools/drift-build.php {app}")

    fd, local = tempfile.mkstemp(prefix="drift-probe-", suffix=".png")
    os.write(fd, b"probe-payload")
    os.close(fd)

    proc = launch(exe, [SWITCH], pipes=True)
    out = queue.Queue()
    threading.Thread(target=_pump_stdout, args=(proc, out), daemon=True).start()

    try:
        time.sleep(1.0)  # 够窗口建起来（如果它要建）
        windows = _windows_of(proc.pid)
        alive = proc.poll() is None
        if not check("① worker 活着且没建窗", alive and not windows,
                     f"alive={alive} windows={windows!r}"):
            skip("worker 没起来，后四条量不到")

        good = {"id": 1, "job": PING_JOB, "arg": {"url": local, "cacheDir": "", "cacheKey": ""}}
        _send(proc, good)
        first = _reply(out)
        check("② 已注册任务成功回包（值原样传回）",
              first is not None and first.get("id") == 1
              and first.get("ok") is True and first.get("value") == local,
              repr(first))

        _send(proc, {"id": 2, "job": "probe.notRegistered", "arg": {}})
        bad = _reply(out)
        check("③ 未注册任务回 error 行（不静默、不杀进程）",
              bad is not None and bad.get("id") == 2 and bad.get("ok") is False
              and "任务未注册" in str(bad.get("error", "")),
              repr(bad))

        good["id"] = 3
        _send(proc, good)
        again = _reply(out)
        check("④ 错误之后还能再跑成功任务",
              again is not None and again.get("id") == 3 and again.get("ok") is True,
              repr(again))

        proc.stdin.close()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()
            proc.wait(timeout=5)
        check("⑤ 关 stdin 后退出码 0", proc.returncode == 0, f"code={proc.returncode}")
    finally:
        if proc.poll() is None:
            proc.kill()
            proc.wait(timeout=5)
        for stream in (proc.stdin, proc.stdout, proc.stderr):
            try:
                stream.close()
            except Exception:  # noqa: BLE001
                pass
        os.unlink(local)

    return 0


if __name__ == "__main__":
    sys.exit(run(main))
