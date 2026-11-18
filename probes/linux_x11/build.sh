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
mkdir -p "$ws/app" "$ws/native" "$ws/core" "$ws/lib" "$ws/cpp"
cp -r "$repo/src" "$ws/src"
cp "$repo/cpp-src/x11/drift_x11.stub.php" "$repo/cpp-src/x11/drift_x11.cc" "$ws/native/"
# IO 池：core 是平台无关池体（单独 core/ 目录，drift_x11.cc 用 ../core/io_pool.h 引它），
# io_wake 是 Linux 自管道唤醒钩子（与 drift_x11.cc 同在 native/，同目录 include）。
# ★ 不复制这两个文件 ⇒ drift_io_* 会链接到空实现（或 undefined reference），
#   ThreadTaskHost 静默不可用 —— 这正是本轮要闭合的 P0 缺口。
cp "$repo/cpp-src/core/io_pool.cc" "$repo/cpp-src/core/io_pool.h" "$ws/core/"
cp "$repo/cpp-src/x11/io_wake.cc" "$repo/cpp-src/x11/io_wake.h" "$ws/native/"
# 图片解码（Skia codec）：解码实现单独 TU，drift_x11.cc 只做 ABI 包装。
cp "$repo/cpp-src/x11/image_decode.cc" "$repo/cpp-src/x11/image_decode.h" "$ws/native/"
# 剪贴板（xclip 子进程）：实现单独 TU，drift_x11.cc 只做 ABI 包装。
# ★ 不复制 ⇒ drift_x11_clipboard_* 链接到未定义符号，AOT 直接编不过（不是静默假绿）。
cp "$repo/cpp-src/x11/clipboard.cc" "$repo/cpp-src/x11/clipboard.h" "$ws/native/"
# Skia 桥与 X11 桥同编：两套 stub 是不同函数名，不会冲突。
cp "$repo/cpp-src/skia/drift_skia.stub.php" "$repo/cpp-src/skia/drift_skia.cc" "$ws/native/"
# 字体跟产物走：桥按 cwd 的 cpp/fonts 找 Noto，CI 与本地口径一致。
cp -r "$repo/cpp/fonts" "$ws/cpp/fonts"
ln -sfn "$skia_home" "$ws/cpp/skia-linux"
cp "$probe_dir/app/main.php" "$probe_dir/send_close.py" "$probe_dir/send_input.py" "$probe_dir/send_focus.py" "$ws/app/"
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

# 剪贴板跨进程判据的前半段：应用启动前，用**外部** xclip 进程写入 CLIPBOARD 并
# 成为 owner。应用内 probeClipboard() 的 J1 必须读回这段值 —— 证明读走的是
# X11 selection（别的进程持有），不是本进程缓存（假实现读不到外部值）。
# xclip 二进制：CI apt 装到 PATH；本地免 root 解包时用 XCLIP_BIN 指过去。
xclip_bin="${XCLIP_BIN:-xclip}"
if ! command -v "$xclip_bin" >/dev/null 2>&1; then
    err "xclip 缺失（apt install xclip；本地可设 XCLIP_BIN=/path/to/xclip）"; exit 2
fi
# ★ 本地免 root 解包的 xclip 不在默认 PATH 上，而 AOT 应用内部是 execvp("xclip")
#   （只查 PATH，没有绝对路径可用）。把 XCLIP_BIN 的目录导出，确保**应用进程**
#   也找得到 xclip —— 否则外部写成功、应用读永远空串，还会误判成"假实现读不到"。
#   CI 里 xclip 在 /usr/bin，dirname 是 /usr/bin，导出是无害的幂等操作。
if [ -n "${XCLIP_BIN:-}" ]; then
    PATH="$(dirname "$xclip_bin"):$PATH"
    export PATH
fi
printf '%s' 'external-剪贴板-X11' | "$xclip_bin" -selection clipboard -t UTF8_STRING
echo "[OK] 外部剪贴板已写入"

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
# 焦点切换注入：FocusOut 必须排在关窗之前 —— close 一到，应用收工，后续事件全丢。
# 不 sleep：所有 XSendEvent 都进同一个 X server 队列，顺序有保证。
python3 "$ws/app/send_focus.py" "$win" || echo "[WARN] 焦点注入脚本报错"
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
grep -q '\[IO\] threads=[1-9]' "$ws/run.log" || { err "IO 池未起线程：core/io_pool 或自管道唤醒未编进工程"; fail=1; }
grep -q '\[IO\] wake woken=8/8' "$ws/run.log" || { err "IO 唤醒失败：8 次 submit 没有被 waitEvents 叫醒（自管道断）"; fail=1; }
grep -q '\[IO\] wake woken=8/8 rows=8' "$ws/run.log" || { err "IO 完成队列丢结果：poll 没拿满 8 条"; fail=1; }
grep -q '\[IO\] e2e sleep=20 echo=hi errors=none' "$ws/run.log" || { err "ThreadTaskHost 端到端失败：两条 Future 没按值结算"; fail=1; }
grep -q '\[IO\] threads_after_stop=0' "$ws/run.log" || { err "IO 池没关干净：shutdown 后仍有 worker（会崩溃/泄漏）"; fail=1; }
grep -q '\[IO\] RESULT: PASS' "$ws/run.log" || { err "IO 池判据整体未通过"; fail=1; }
grep -q '\[DEC\] png 8x8 bytes=256' "$ws/run.log" || { err "PNG 解码失败：Skia codec 没解出 8x8 RGBA"; fail=1; }
grep -q '\[DEC\] webp 8x8 bytes=256' "$ws/run.log" || { err "WebP 解码失败：Skia codec 缺 webp 支持"; fail=1; }
grep -q '\[DEC\] jpg 8x8 bytes=256' "$ws/run.log" || { err "JPEG 解码失败：Skia codec 缺 jpeg 支持（本 P0 的核心价值）"; fail=1; }
grep -q '\[DEC\] png red(R=255,B=0) blue(R=0,B=255)' "$ws/run.log" || { err "PNG 通道序错：R/B 互换 —— 与 Windows 踩过的同一个坑"; fail=1; }
grep -q '\[DEC\] scaled maxWidth=4 => 4x4 bytes=64' "$ws/run.log" || { err "maxWidth 缩放失败：解码期没等比缩小"; fail=1; }
grep -q '\[DEC\] missing=empty' "$ws/run.log" || { err "解码失败路径没返回空数组 —— 会抛异常打断消息循环"; fail=1; }
grep -q '\[DEC\] RESULT: PASS' "$ws/run.log" || { err "图片解码判据整体未通过"; fail=1; }
grep -q '\[WM\] min 400x300' "$ws/run.log" || { err "最小尺寸失效：XSetWMNormalHints 没写进 XSizeHints"; fail=1; }
grep -q '\[WM\] place 400x300 @ ' "$ws/run.log" || { err "几何还原失效：XMoveResizeWindow 没改窗口尺寸"; fail=1; }
grep -q '\[WM\] max_v=1 max_h=1 isMaximized=1' "$ws/run.log" || { err "EWMH 最大化失效：ClientMessage 没让 WM 置 _NET_WM_STATE"; fail=1; }
grep -q '\[WM\] iconic=1' "$ws/run.log" || { err "最小化失效：XIconifyWindow 没让 WM 置 WM_STATE=Iconic"; fail=1; }
grep -q '\[WM\] RESULT: PASS' "$ws/run.log" || { err "窗口管理判据整体未通过"; fail=1; }
grep -q '\[CLIP\] external read=external-剪贴板-X11' "$ws/run.log" || { err "跨进程剪贴板读失败：读不到外部 xclip owner 的值（可能走成本进程缓存假实现，或 -t target 不对）"; fail=1; }
grep -q '\[CLIP\] roundtrip got=drift-剪贴板-中文-ok' "$ws/run.log" || { err "剪贴板中文往返失败：UTF8_STRING target 没生效（不显式指定会被当 Latin-1，中文变问号）"; fail=1; }
grep -q '\[CLIP\] hasText=1' "$ws/run.log" || { err "hasText 读不到内容：剪贴板三件套的可用性门控断"; fail=1; }
grep -q '\[CLIP\] overwrite got=second-copy' "$ws/run.log" || { err "剪贴板覆盖写失败：第二次 writeText 没替换内容"; fail=1; }
grep -q '\[CLIP\] RESULT: PASS' "$ws/run.log" || { err "剪贴板判据整体未通过"; fail=1; }
grep -q '\[BACKEND\] skia' "$ws/run.log" || { err "Skia 后端未启用：桥没链上或 provider 回退到了软光栅"; fail=1; }
grep -q '\[FONT\] ready=1' "$ws/run.log" || { err "Skia 目录字体未就绪 —— 文字不会绘制（几何全对也看不出字）"; fail=1; }
grep -q '找不到目录字体' "$ws/run.log" && { err "Skia 报了字体缺失诊断"; fail=1; }
grep -q '\[EVENT\] deactivate received' "$ws/run.log" || { err "窗口失活未到框架：FocusOut→deactivate 链断（桥没订阅 FocusChangeMask / detail 过滤过严 / translate 没接）"; fail=1; }
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
