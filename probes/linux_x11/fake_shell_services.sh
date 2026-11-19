#!/usr/bin/env bash
# probes/linux_x11/fake_shell_services.sh —— X11 壳服务的确定性假助手
#
# 只用于端到端门：真机上用 zenity / yad 弹原生 GUI，CI 不能点按钮。
# 这里按同样的 argv 契约返回固定路径 / 固定退出码，验的是 Drift 一侧的接线、
# 解析、模态计时与进程生命周期，不冒充 native 对话框的外观。

set -u

# ★ 先全扫描 argv，再分派。真 yad 的 --file-selection 在 --multiple 之前；
#   边遍历边响应会在看到 --multiple 之前就返回单文件，把多选链断成假红。
want_multi=0
want_notification=0
want_question=0
want_info=0
want_file=0
for arg in "$@"; do
    case "$arg" in
        --notification)  want_notification=1 ;;
        --multiple)      want_multi=1 ;;
        --question|--yesno) want_question=1 ;;
        --info|--msgbox) want_info=1 ;;
        --file-selection) want_file=1 ;;
    esac
done

if [ "$want_notification" -eq 1 ]; then
    : "${DRIFT_X11_TRAY_PID_FILE:?fake tray needs DRIFT_X11_TRAY_PID_FILE}"
    printf '%s\n' "$$" > "$DRIFT_X11_TRAY_PID_FILE"
    # yad --notification 是前台进程；保持活着，等 remove() 发 SIGTERM。
    while true; do sleep 1; done
fi

if [ "$want_file" -eq 1 ]; then
    if [ "$want_multi" -eq 1 ]; then
        printf '%s\n%s\n' '/tmp/drift-dialog-a.txt' '/tmp/drift-dialog-b.txt'
    else
        printf '%s\n' '/tmp/drift-dialog-a.txt'
    fi
    exit 0
fi

if [ "$want_question" -eq 1 ] || [ "$want_info" -eq 1 ]; then
    exit 0
fi

# 未识别的调用要响亮失败，不能静默返回 0 把假绿伪装成通过。
printf 'fake shell service: unsupported args: %s\n' "$*" >&2
exit 64
