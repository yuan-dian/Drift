"""真机判据：C++ IO 线程池 + 唤醒通道（A2 / N5）。

    python tools/probe-io-pool.py            # 跑（产物必须已构建）
    python tools/probe-io-pool.py --build    # 先 `drift-build` 再跑

判据（六条，缺一不可）：

  ① 唤醒延迟   1000/1000 轮都是 `WakeReason::Woken`，且单轮 < 5ms
  ② 不变量 ①   被叫醒时 `drift_io_poll()` 一定**非空**（先入队再叫醒；
               反过来就是"叫醒了却没结果、然后睡回去"，结果永远躺在队列里）
  ③ 0 丢失     poll 行数 == `handled()` == 1000
  ④ 端到端     `ThreadTaskHost`：`sleep` 任务 → 20，`echo` 任务 → 'hi'
  ⑤ 空闲 CPU   < 1%（进程 CPU 时间 / 墙钟；4 个 worker 空转 + 泵阻塞）
  ⑥ 收尾       关池后线程数 == 0，退出码 == 0

★ 为什么 CPU 由**这里**量而不是探针自己量：框架没有"读进程 CPU 时间"的 ABI，
  也不该为一条判据加一个（`GetProcessTimes` 是量具的事，不是框架的事）。

★ 为什么超时看门狗在**这里**：N5 最可能的失败形态不是"算错"，是**挂死** ——
  唤醒没接上时 `waitEvents(-1)` 会永远阻塞。进程内的看门狗需要第二个线程，
  而"线程能不能用"正是被测对象 ⇒ 只有进程外的超时是诚实的。

★ 为什么读 `out.log` 而不是 stdout：探针每行一次 write 落盘，某一步掀掉进程时
  前面的证据仍在盘上；stdout 在管道里可能整段丢掉（`probes/taskhost_thread` 的教训）。
"""

import ctypes
import os
import subprocess
import sys
import time
from ctypes import wintypes

import probe_win as win
from probe_check import check, run

ROOT = win.ROOT
PROBE = os.path.join(ROOT, "probes", "io_pool")
# ★ exe 落在 **仓库根**（tpc 的输出目录是 cwd，`drift-build` 用仓库根当 cwd；
#   `name:` 只决定文件名）—— 与四个 app 同一条规则，不是探针特例。
EXE = os.path.join(ROOT, "io_pool.exe")
LOG = os.path.join(PROBE, "out.log")

#: 探针自己写进 `out.log` 的判据门槛（与 `src/main.php` 的常量保持一致）
WAKE_BUDGET_MS = 5.0
ROUNDS = 1000
IDLE_JOB_MS = 1200
EMPTY_POLL_BUDGET_US = 10.0

#: 全程上限：1000 轮 × (1ms 活 + 往返) + 1.2s 空闲，正常 3–5s
TOTAL_TIMEOUT_S = 120.0
#: 空闲窗口里两次 CPU 采样的间隔
CPU_WINDOW_S = 0.6

kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
kernel32.OpenProcess.restype = wintypes.HANDLE


def cpu_seconds(pid: int):
    """进程累计 CPU 时间（秒）；拿不到返回 None。

    ★ 用 `GetProcessTimes`（内核 + 用户态），**不是**"墙钟 × 核心数" ——
      前者是"这个进程真的烧了多少 CPU"，后者什么也证明不了。
    """
    handle = kernel32.OpenProcess(0x1000, False, pid)  # PROCESS_QUERY_LIMITED_INFORMATION
    if not handle:
        return None

    created = wintypes.FILETIME()
    exited = wintypes.FILETIME()
    kernel = wintypes.FILETIME()
    user = wintypes.FILETIME()

    try:
        ok = kernel32.GetProcessTimes(
            handle,
            ctypes.byref(created),
            ctypes.byref(exited),
            ctypes.byref(kernel),
            ctypes.byref(user),
        )
        if not ok:
            return None

        k = (kernel.dwHighDateTime << 32) | kernel.dwLowDateTime
        u = (user.dwHighDateTime << 32) | user.dwLowDateTime

        return (k + u) / 1e7  # FILETIME 是 100ns 单位
    finally:
        kernel32.CloseHandle(handle)


def read_log() -> list[str]:
    if not os.path.isfile(LOG):
        return []

    with open(LOG, "r", encoding="utf-8", errors="replace") as fh:
        return [line.rstrip("\n") for line in fh]


def kv(lines: list[str]) -> dict[str, str]:
    out: dict[str, str] = {}
    for line in lines:
        if "=" in line and not line.startswith("==="):
            key, _, value = line.partition("=")
            out[key.strip()] = value.strip()
    return out


def build() -> bool:
    """`--build`：调用 `tools/drift-build.php` 重建产物。

    ★ 原来这里用 `sys.executable`（= python.exe）去跑一个 `.php` —— 必然失败，
      只是 `--build` 平时没人用所以没暴露。改成走 PATH 上的 `php`
      （或 `DRIFT_PHP` 覆盖，方便换 PHP 版本）。
    """
    print("[probe] 构建 probes/io_pool ……")
    php = os.environ.get("DRIFT_PHP", "php")
    result = subprocess.run(
        [php, os.path.join(ROOT, "tools", "drift-build.php"), "probes/io_pool"],
        cwd=ROOT,
        capture_output=True,
        text=True,
    )
    sys.stdout.write(result.stdout)
    sys.stderr.write(result.stderr)

    return result.returncode == 0


def main() -> int:
    if "--build" in sys.argv:
        if not build():
            print("FAIL  构建失败")
            return 1

    if not os.path.isfile(EXE):
        print(f"FAIL  找不到 {EXE} —— 先跑 `php tools/drift-build.php probes/io_pool`")
        return 1

    if os.path.isfile(LOG):
        os.remove(LOG)

    # ★ 启动环境（`PHPRC` ⇒ `opcache.enable=0`）由 `probe_win.launch` 统一给：
    #   TypePHP 产物会拉起 opcache，而它要按当前 ASLR 布局校验 opcode handlers，
    #   校验不过就拒绝启动（"Opcode handlers are unusable due to ASLR"，
    #   每次开机重新随机 ⇒ 间歇性）。这段原来 9 个探针各写一份。
    proc = win.launch(EXE, cwd=PROBE)

    # ── 等 idle-begin（或提前结束）────────────────────────────────────────
    started = time.time()
    idle_cpu = None
    saw_idle = False

    while time.time() - started < TOTAL_TIMEOUT_S:
        lines = read_log()
        if not saw_idle and any(line == "idle-begin" for line in lines):
            saw_idle = True

            c0 = cpu_seconds(proc.pid)
            t0 = time.time()
            time.sleep(CPU_WINDOW_S)
            c1 = cpu_seconds(proc.pid)
            t1 = time.time()

            if c0 is not None and c1 is not None and (t1 - t0) > 0:
                idle_cpu = (c1 - c0) / (t1 - t0) * 100.0

        if proc.poll() is not None:
            break

        time.sleep(0.02)

    # ── 收尾：超时 = 挂死（这正是 N5 要抓的形态）──────────────────────────
    hung = False
    if proc.poll() is None:
        hung = True
        proc.kill()
        proc.wait()

    exit_code = proc.returncode
    lines = read_log()
    data = kv(lines)

    print()
    for line in lines:
        print("  | " + line)
    print()


    # ① 唤醒延迟
    not_woken = int(data.get("wake.not_woken", "-1"))
    max_dt = float(data.get("wake.max_dt_ms", "999999"))
    check(
        "① 唤醒延迟：1000 轮全是 Woken 且单轮 < 5ms",
        not_woken == 0 and max_dt < WAKE_BUDGET_MS,
        f"not_woken={not_woken} max_dt={max_dt}ms",
    )

    # ② 不变量 ①
    empty_poll = int(data.get("wake.empty_poll", "-1"))
    check(
        "② 不变量①：被叫醒时 poll() 一定非空（先入队再叫醒）",
        empty_poll == 0,
        f"empty_poll={empty_poll}",
    )

    # ③ 0 丢失
    rows = int(data.get("wake.rows", "-1"))
    handled = int(data.get("handled", "-1"))
    check(
        "③ 0 丢失：poll 行数 == handled == 1000",
        rows == ROUNDS and handled == ROUNDS,
        f"rows={rows} handled={handled}",
    )

    # ④ 端到端
    e2e_sleep = data.get("e2e.sleep", "MISSING")
    e2e_echo = data.get("e2e.echo", "MISSING")
    e2e_errors = data.get("e2e.errors", "MISSING")
    check(
        "④ 端到端：ThreadTaskHost 结算 sleep→20 / echo→'hi'",
        e2e_sleep == "20" and e2e_echo == "hi" and e2e_errors == "none",
        f"sleep={e2e_sleep} echo={e2e_echo} errors={e2e_errors}",
    )

    # ⑤ 空闲 CPU
    check(
        "⑤ 空闲 CPU < 1%（4 个 worker 空转 + 泵阻塞）",
        idle_cpu is not None and idle_cpu < 1.0,
        "未采到样本" if idle_cpu is None else f"{idle_cpu:.3f}%",
    )

    # ⑥ 收尾
    threads_after = int(data.get("threads_after_stop", "-1"))
    check(
        "⑥ 收尾：关池后线程数 0 且退出码 0",
        threads_after == 0 and exit_code == 0 and not hung,
        f"threads={threads_after} exit={exit_code}" + (" 挂死(超时被杀)" if hung else ""),
    )

    # ⑦ 空队列 poll 单次耗时
    empty_pre = data.get("empty_poll.pre", "MISSING")
    empty_us = float(data.get("empty_poll_us", "999999"))
    check(
        "⑦ 空队列 poll 单次 < 10µs（前置：队列确实是空的）",
        empty_pre == "empty" and empty_us < EMPTY_POLL_BUDGET_US,
        f"pre={empty_pre} {empty_us}µs",
    )

    verdict = data.get("VERDICT", "MISSING")
    check("探针自判（VERDICT=PASS）", verdict == "PASS", f"VERDICT={verdict}")

    # 挂死是**单独一类**结论（进程根本没跑完，判据读到的数是残缺的）⇒ 记一条 FAIL，
    # 让 `run()` 的"失败优先"把它带到退出码 1。
    check("进程自己跑完（没被超时看门狗杀掉）", not hung,
          f"exit={exit_code} 空闲 CPU {idle_cpu:.3f}%" if idle_cpu is not None else f"exit={exit_code}")

    return 0


if __name__ == "__main__":
    sys.exit(run(main))
