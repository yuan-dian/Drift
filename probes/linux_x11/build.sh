#!/usr/bin/env bash
# Linux X11 端到端门：tpc/GCC 编出 ELF → 真实窗口 → 消息泵 → 注入关窗事件。
# 用法：PHPX_HOME=/path/to/phpx bash probes/linux_x11/build.sh [workspace]
set -uo pipefail

probe_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$probe_dir/../.." && pwd)"
ws="${1:-/tmp/drift-linux-x11-verify}"
tpc="${TPC:-/home/www/typephp/typephp/bin/tpc.php}"

# 失败在 CI 上是注解（GitHub 会把 ::error:: 挂到 step 顶部，不用翻几十行日志），
# 本地仍是熟悉的 [FAIL] 前缀 —— 同一行文本两种消费方式。
err() {
    echo "[FAIL] $1"
    if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
        echo "::error title=Linux X11 gate::$1"
    fi
}

# 应用 / tail / watchdog 三个后台进程统一收尸：任何提前退出（含 set -u 报错、
# 窗口没出现、CI 取消）都不留孤儿——残留的 ELF 会一直占着 DISPLAY 和 CPU，
# 让失败之后的日志抓取和 runner 回收都变慢。
pid=""; tail_pid=""; watch_pid=""
cleanup() {
    for p in "$pid" "$tail_pid" "$watch_pid"; do
        [ -n "$p" ] && kill "$p" 2>/dev/null
    done
    wait 2>/dev/null
}
trap cleanup EXIT

if [ -z "${PHPX_HOME:-}" ]; then
    err "PHPX_HOME 未设置"; exit 2
fi
if [ ! -f "$tpc" ]; then
    err "tpc 不存在: $tpc"; exit 2
fi
if ! command -v xwininfo >/dev/null 2>&1; then
    err "xwininfo 缺失（apt install x11-utils）"; exit 2
fi

# Skia 产物是 hard dependency：缺了就响亮失败，不能静默退回软光栅 —— 那样
# "Skia 渲染门"会在产物过期/路径写错时全绿，等于没测。
skia_home="${DRIFT_SKIA_HOME:-$repo/cpp/skia-linux}"
if [ ! -f "$skia_home/out/Release-x64/libskia.a" ] \
    || [ ! -f "$skia_home/include/core/SkCanvas.h" ]; then
    err "Linux Skia 产物缺失：$skia_home（设置 DRIFT_SKIA_HOME 指向 build-only artifact 解包目录）"
    exit 2
fi
skia_home="$(cd "$skia_home" && pwd)"

rm -rf "$ws"
mkdir -p "$ws/app" "$ws/native" "$ws/lib" "$ws/cpp"
cp -r "$repo/src" "$ws/src"
cp "$repo/cpp-src/x11/drift_x11.stub.php" "$repo/cpp-src/x11/drift_x11.cc" "$ws/native/"
# Skia 桥与 X11 桥同编：两套 stub 是不同函数名，不会冲突。
cp "$repo/cpp-src/skia/drift_skia.stub.php" "$repo/cpp-src/skia/drift_skia.cc" "$ws/native/"
# 字体跟产物走：桥按 cwd 的 cpp/fonts 找 Noto，CI 与本地口径一致。
cp -r "$repo/cpp/fonts" "$ws/cpp/fonts"
ln -sfn "$skia_home" "$ws/cpp/skia-linux"
cp "$probe_dir/app/main.php" "$probe_dir/send_close.py" "$probe_dir/send_input.py" "$ws/app/"
cp "$probe_dir/project.yml" "$ws/project.yml"

libx11="$(ldconfig -p | awk '/libX11\.so\.6/{print $NF; exit}')"
if [ -z "$libx11" ]; then
    err "libX11.so.6 不在 ldconfig 缓存"; exit 2
fi
ln -sf "$libx11" "$ws/lib/libX11.so"

# Skia 的 libskia.a 引用 Fontconfig。 linker 需要的是 `libfontconfig.so` 开发期软链，
# 而 runner 上常只有 `libfontconfig.so.1` —— 与 libX11 同法现场建一条，本地/CI 同口径。
libfontconfig="$(ldconfig -p | awk '/libfontconfig\.so\.1/{print $NF; exit}')"
if [ -z "$libfontconfig" ]; then
    err "libfontconfig.so.1 不在 ldconfig 缓存"; exit 2
fi
ln -sf "$libfontconfig" "$ws/lib/libfontconfig.so"

cd "$ws" || exit 2
echo "=== 编译（tpc/GCC）==="
php "$tpc" project.yml -O2 -m bin --no-progress > "$ws/build.log" 2>&1
rc=$?
if [ $rc -ne 0 ] || [ ! -x "$ws/linux_x11_e2e" ]; then
    err "编译失败 exit=$rc"; tail -30 "$ws/build.log"; exit 1
fi
echo "[OK] ELF 产出 $(du -h "$ws/linux_x11_e2e" | cut -f1)"

echo "=== 运行 + 注入关窗事件 ==="
# wait 必须有界：应用若卡在 waitEvents()，无限等会把 CI 挂到 6h 超时。
# tail -f 把应用输出同步到 job 日志，否则 XSendEvent 之后就是一段空白，没法定位。
gate_timeout="${DRIFT_X11_TIMEOUT:-90}"
: > "$ws/run.log"
timeout --signal=TERM --kill-after=5s "${gate_timeout}s" ./linux_x11_e2e > "$ws/run.log" 2>&1 &
pid=$!
# ★ 要采样的必须是**应用进程**，不是 timeout 包装进程：GNU timeout 会 fork 子进程再
#   exec，`$pid` 是包装进程，它的 utime/stime 恒接近 0 —— 拿它判"死循环"永远判不出来。
#   旧版正是踩了这个坑：watch.log 里的 utime 一直是 0，看起来像"没在烧 CPU"，
#   而真实进程当时在跑什么完全没被观测到。
app_pid_of() {
    cat "/proc/$1/task/$1/children" 2>/dev/null | awk '{print $1; exit}'
}
# 卡住时抓内核态现场：utime/stime 在涨=纯 CPU 死循环，wchan=futex=锁死锁，poll=等事件，
# VmRSS 涨=分配不止。四者合起来能把"卡"分成可归因的几类，不需要猜。
(
    for tick in $(seq 1 200); do
        target="$(app_pid_of "$pid")"
        [ -z "$target" ] && target="$pid"
        stat_line=$(awk '{print "state="$3" utime="$14" stime="$15}' "/proc/$target/stat" 2>/dev/null)
        rss=$(awk '/VmRSS/{print $2" "$3}' "/proc/$target/status" 2>/dev/null)
        wchan=$(cat "/proc/$target/wchan" 2>/dev/null)
        call=$(cut -c1-40 "/proc/$target/syscall" 2>/dev/null)
        echo "[WATCH] pid=$target $stat_line rss=$rss wchan=$wchan syscall=$call"
        kill -0 "$pid" 2>/dev/null || break
        # 到一半还没退出 ⇒ 采一次 C++ 栈。utime 只能说"在烧 CPU"，栈才能说"烧在哪一行"。
        # 需要 DRIFT_X11_GDB=1（本地跑不必付这份开销）且 gdb 可用。
        if [ "${DRIFT_X11_GDB:-0}" = "1" ] && [ "$tick" -eq $((gate_timeout / 10)) ] \
            && command -v gdb >/dev/null 2>&1; then
            {
                echo "===== [GDB] 第 ${tick} 个采样点（约 $((tick * 5))s）pid=$target ====="
                gdb -p "$target" -batch -ex 'thread apply all bt 25'
            } >> "$ws/gdb.log" 2>&1 || true
        fi
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
    err "窗口未出现（createWindow / show / xwininfo 任一环断）"
    # 窗口没出现时应用输出是唯一线索（连接失败 / 立即崩溃 / 卡在初始化）。
    echo "--- run.log ---"; tail -20 "$ws/run.log"
    exit 1
fi
echo "[OK] 窗口出现 $win"

# XSendEvent 直接投递到目标窗口：XTest 走输入焦点，fluxbox 会把焦点抢走，
# 按键全进 WM —— 实测桥一条都收不到（关窗脚本已验证直投这条路可靠）。
python3 "$ws/app/send_input.py" "$win" || echo "[WARN] 输入注入脚本报错"
# 不 sleep：两条 XSendEvent 都进同一个 X server 队列，顺序有保证。
# 这里多等一秒，Xvfb 下几百帧早跑完，应用会先触发兜底自毁，窗口没了再发关窗就是 BadWindow。
python3 "$ws/app/send_close.py" "$win" || echo "[WARN] 事件注入脚本报错"
wait "$pid"
rc=$?
# tail / watchdog 由 trap 收尸；这里先停掉 tail，避免它把后面的判据输出盖在中间。
kill "$tail_pid" 2>/dev/null; wait "$tail_pid" 2>/dev/null

if [ $rc -eq 124 ]; then
    err "应用 ${gate_timeout}s 未退出：事件注入或消息泵卡住（timeout 已强杀）"
    echo "--- 卡住时内核态现场（每 5s 一次，判据看 utime/stime 是否增长）---"
    tail -12 "$ws/watch.log"
    if [ -s "$ws/gdb.log" ]; then
        echo "--- 卡住时的 C++ 栈（决定它烧在哪一行）---"
        tail -60 "$ws/gdb.log"
    fi
fi

fail=0
grep -q '\[BACKEND\] skia' "$ws/run.log" || { err "Skia 后端未启用：桥没链上或 provider 回退到了软光栅"; fail=1; }
grep -q '\[FONT\] ready=1' "$ws/run.log" || { err "Skia 目录字体未就绪 —— 文字不会绘制（几何全对也看不出字）"; fail=1; }
grep -q '找不到目录字体' "$ws/run.log" && { err "Skia 报了字体缺失诊断"; fail=1; }
grep -q '\[EVENT\] close-requested received' "$ws/run.log" || { err "无关闭回调：事件链断"; fail=1; }
grep -q '\[INPUT\] char=a' "$ws/run.log" || { err "文本输入未到框架：char 事件链断（桥没吐 char 或 translate 没接）"; fail=1; }
grep -q '\[INPUT\] scroll delta=-1 axis=vertical' "$ws/run.log" || { err "竖轮前推未到框架：wheel 事件链断或符号反了"; fail=1; }
grep -q '\[INPUT\] scroll delta=1 axis=vertical' "$ws/run.log" || { err "竖轮后推未到框架：wheel 事件链断或符号反了"; fail=1; }
grep -q 'loop exited, frames=' "$ws/run.log" || { err "EventLoop 未收工"; fail=1; }
[ $rc -eq 0 ] || { err "退出码 $rc"; fail=1; }

if [ $fail -ne 0 ]; then
    echo "=== RESULT: FAIL ==="
    exit 1
fi
echo "=== RESULT: PASS（窗口 / 事件链 / 消息泵 / 干净退出）==="
