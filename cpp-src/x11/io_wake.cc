/**
 * IO 线程池唤醒通道 —— Linux 自管道实现。见 io_wake.h 的拆分理由。
 *
 * ★ 本文件零依赖 core/io_pool、零 X11 头：就是一个非阻塞 pipe + 四个函数。
 *   把它和 drift_x11.cc 平铺进同一个 source 目录即可编译。
 */

#include "io_wake.h"

#include <fcntl.h>
#include <unistd.h>

namespace drift {
namespace x11 {

namespace {

/** 读端[0] / 写端[1]；`-1` = 未启动（与 ioWakeReadFd 的返回值同口径） */
int g_pipe[2] = {-1, -1};

void setNonBlocking(int fd)
{
    int flags = ::fcntl(fd, F_GETFL, 0);
    if (flags >= 0) {
        ::fcntl(fd, F_SETFL, flags | O_NONBLOCK);
    }
}

}   // namespace

bool startIoPipe()
{
    if (g_pipe[0] >= 0) {
        return true;   // 幂等
    }

    int fds[2] = {-1, -1};
    if (::pipe(fds) != 0) {
        return false;
    }
    setNonBlocking(fds[0]);
    setNonBlocking(fds[1]);

    g_pipe[0] = fds[0];
    g_pipe[1] = fds[1];
    return true;
}

int ioWakeReadFd()
{
    return g_pipe[0];
}

void ioWakeNotify()
{
    if (g_pipe[1] < 0) {
        return;
    }
    const char one = 1;
    // 非阻塞写：管道满（已有未消费的唤醒）时 EAGAIN 直接丢弃 —— 唤醒是电平信号，
    // 丢一次不丢事件（结果在 core/io_pool 的完成队列里，由 drift_io_poll 取）。
    (void) ::write(g_pipe[1], &one, 1);
}

bool ioWakeDrain()
{
    char buf[256];
    bool any = false;
    while (g_pipe[0] >= 0 && ::read(g_pipe[0], buf, sizeof(buf)) > 0) {
        any = true;
    }
    return any;
}

void stopIoPipe()
{
    if (g_pipe[0] >= 0) {
        ::close(g_pipe[0]);
        g_pipe[0] = -1;
    }
    if (g_pipe[1] >= 0) {
        ::close(g_pipe[1]);
        g_pipe[1] = -1;
    }
}

}   // namespace x11
}   // namespace drift
