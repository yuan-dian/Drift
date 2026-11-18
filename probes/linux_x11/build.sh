#!/usr/bin/env bash
# Linux X11 端到端门：tpc/GCC 编出 ELF → 真实窗口 → 消息泵 → 注入关窗事件。
# 用法：PHPX_HOME=/path/to/phpx bash probes/linux_x11/build.sh [workspace]
set -uo pipefail

probe_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$probe_dir/../.." && pwd)"
ws="${1:-/tmp/drift-linux-x11-verify}"
tpc="${TPC:-/home/www/typephp/typephp/bin/tpc.php}"

if [ -z "${PHPX_HOME:-}" ]; then
    echo "[FAIL] PHPX_HOME 未设置"; exit 2
fi
if [ ! -f "$tpc" ]; then
    echo "[FAIL] tpc 不存在: $tpc"; exit 2
fi
if ! command -v xwininfo >/dev/null 2>&1; then
    echo "[FAIL] xwininfo 缺失（apt install x11-utils）"; exit 2
fi

rm -rf "$ws"
mkdir -p "$ws/app" "$ws/native" "$ws/lib"
cp -r "$repo/src" "$ws/src"
cp "$repo/cpp-src/x11/drift_x11.stub.php" "$repo/cpp-src/x11/drift_x11.cc" "$ws/native/"
cp "$probe_dir/app/main.php" "$probe_dir/send_close.py" "$ws/app/"
cp "$probe_dir/project.yml" "$ws/project.yml"

libx11="$(ldconfig -p | awk '/libX11\.so\.6/{print $NF; exit}')"
if [ -z "$libx11" ]; then
    echo "[FAIL] libX11.so.6 不在 ldconfig 缓存"; exit 2
fi
ln -sf "$libx11" "$ws/lib/libX11.so"

cd "$ws" || exit 2
echo "=== 编译（tpc/GCC）==="
php "$tpc" project.yml -O2 -m bin --no-progress > "$ws/build.log" 2>&1
rc=$?
if [ $rc -ne 0 ] || [ ! -x "$ws/linux_x11_e2e" ]; then
    echo "[FAIL] 编译失败 exit=$rc"; tail -30 "$ws/build.log"; exit 1
fi
echo "[OK] ELF 产出 $(du -h "$ws/linux_x11_e2e" | cut -f1)"

echo "=== 运行 + 注入关窗事件 ==="
# wait 必须有界：应用若卡在 waitEvents()，无限等会把 CI 挂到 6h 超时。
# tail -f 把应用输出同步到 job 日志，否则 XSendEvent 之后就是一段空白，没法定位。
gate_timeout="${DRIFT_X11_TIMEOUT:-90}"
: > "$ws/run.log"
timeout --signal=TERM --kill-after=5s "${gate_timeout}s" ./linux_x11_e2e > "$ws/run.log" 2>&1 &
pid=$!
# 卡住时抓内核态现场：CPU 时间在涨=死循环，wchan=futex=锁死锁，poll=等事件。
(
    while kill -0 "$pid" 2>/dev/null; do
        stat=$(awk '{print "utime="$14" stime="$15}' "/proc/$pid/stat" 2>/dev/null)
        wchan=$(cat "/proc/$pid/wchan" 2>/dev/null)
        call=$(cut -c1-40 "/proc/$pid/syscall" 2>/dev/null)
        echo "[WATCH] $stat wchan=$wchan syscall=$call"
        sleep 5
    done
) > "$ws/watch.log" 2>&1 &
watch_pid=$!
tail -f "$ws/run.log" &
tail_pid=$!
win=""
for i in $(seq 1 200); do
    line="$(xwininfo -root -tree -display "${DISPLAY:-:0}" 2>/dev/null | grep -i '"X11 Drift"' | head -1)"
    if [ -n "$line" ]; then
        win="$(echo "$line" | awk '{print $1}')"
        break
    fi
    sleep 0.02
done
if [ -z "$win" ]; then
    echo "[FAIL] 窗口未出现（createWindow / show / xwininfo 任一环断）"
    kill "$pid" 2>/dev/null; wait "$pid" 2>/dev/null
    kill "$tail_pid" 2>/dev/null; wait "$tail_pid" 2>/dev/null
    kill "$watch_pid" 2>/dev/null; wait "$watch_pid" 2>/dev/null
    exit 1
fi
echo "[OK] 窗口出现 $win"

python3 "$ws/app/send_close.py" "$win" || echo "[WARN] 事件注入脚本报错"
wait "$pid"
rc=$?
kill "$tail_pid" 2>/dev/null; wait "$tail_pid" 2>/dev/null
kill "$watch_pid" 2>/dev/null; wait "$watch_pid" 2>/dev/null

if [ $rc -eq 124 ]; then
    echo "[FAIL] 应用 ${gate_timeout}s 未退出：事件注入或消息泵卡住（timeout 已强杀）"
    echo "--- 卡住时内核态现场（每 5s 一次，判据看 utime/stime 是否增长）---"
    tail -12 "$ws/watch.log"
fi

fail=0
grep -q '\[EVENT\] close-requested received' "$ws/run.log" || { echo "[FAIL] 无关闭回调：事件链断"; fail=1; }
grep -q 'loop exited, frames=' "$ws/run.log" || { echo "[FAIL] EventLoop 未收工"; fail=1; }
[ $rc -eq 0 ] || { echo "[FAIL] 退出码 $rc"; fail=1; }

if [ $fail -ne 0 ]; then
    echo "=== RESULT: FAIL ==="
    exit 1
fi
echo "=== RESULT: PASS（窗口 / 事件链 / 消息泵 / 干净退出）==="
