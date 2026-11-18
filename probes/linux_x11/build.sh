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
./linux_x11_e2e > "$ws/run.log" 2>&1 &
pid=$!
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
    cat "$ws/run.log"; exit 1
fi
echo "[OK] 窗口出现 $win"

python3 "$ws/app/send_close.py" "$win" || echo "[WARN] 事件注入脚本报错"
wait "$pid"
rc=$?
echo "--- 程序输出 ---"
cat "$ws/run.log"

fail=0
grep -q '\[EVENT\] close-requested received' "$ws/run.log" || { echo "[FAIL] 无关闭回调：事件链断"; fail=1; }
grep -q 'loop exited, frames=' "$ws/run.log" || { echo "[FAIL] EventLoop 未收工"; fail=1; }
[ $rc -eq 0 ] || { echo "[FAIL] 退出码 $rc"; fail=1; }

if [ $fail -ne 0 ]; then
    echo "=== RESULT: FAIL ==="
    exit 1
fi
echo "=== RESULT: PASS（窗口 / 事件链 / 消息泵 / 干净退出）==="
