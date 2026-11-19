/**
 * X11 剪贴板实现。见 clipboard.h 的取舍与三条硬纪律。
 *
 * ★ 全用同步 POSIX + 有界 poll：xclip 在独立进程里跑 selection 异步协议，
 *   我们这边只有 pipe I/O，不会碰 X connection，故不存在 Qt/Chromium 那种
 *   owner/requestor 同 connection 自死锁。
 */

#include "clipboard.h"

#include <algorithm>
#include <chrono>
#include <cerrno>
#include <string>
#include <vector>

#include <fcntl.h>
#include <poll.h>
#include <signal.h>
#include <sys/wait.h>
#include <unistd.h>

namespace drift {
namespace x11 {
namespace {

// 一次 paste 的预算。与 crcbl-shell 的 2s 同量级：正常 xclip 往返是毫秒级，
// 2s 只用来兜"owner 不回 SelectionNotify"（Chromium 571343363 的永久 hang）。
constexpr int kClipTimeoutMs = 2000;

/**
 * fork/exec xclip，按 input 写 stdin、按需读 stdout。
 *
 * @return 子进程退出码；-1 = spawn/系统错；-2 = 超时（已 kill + 回收）
 *
 * ★ 三条都不可省：
 *   - stdin/stdout 非阻塞 + poll：owner 卡住时不能把 UI 主线程钉死；
 *   - SIGPIPE 临时忽略：xclip 提前退出时写 stdin 会触发，默认动作是杀掉宿主；
 *   - waitpid 无条件回收：Ctrl+V 是高频操作，漏一次回收就多一个僵尸。
 */
int runXclip(char *const argv[], const std::string &input, std::string *output, int timeoutMs)
{
    int inPipe[2] = {-1, -1};
    int outPipe[2] = {-1, -1};
    if (pipe(inPipe) != 0) {
        return -1;
    }
    if (output != nullptr && pipe(outPipe) != 0) {
        close(inPipe[0]);
        close(inPipe[1]);
        return -1;
    }

    const pid_t pid = fork();
    if (pid < 0) {
        close(inPipe[0]); close(inPipe[1]);
        if (output != nullptr) { close(outPipe[0]); close(outPipe[1]); }
        return -1;
    }
    if (pid == 0) {
        // 子进程：把 pipe 接到 stdio，其余 fd 全关（尤其别把父端 fd 带进 exec，
        // 否则 read 模式的 stdout 永远等不到 EOF）。
        dup2(inPipe[0], STDIN_FILENO);
        if (output != nullptr) {
            dup2(outPipe[1], STDOUT_FILENO);
        } else {
            // 写模式不读 stdout：丢 /dev/null。若继承父端读管道，xclip fork 的
            // 常驻 owner 会一直握着写端，我们等 EOF 就会空耗到超时。
            const int devnull = open("/dev/null", O_WRONLY);
            if (devnull >= 0) { dup2(devnull, STDOUT_FILENO); close(devnull); }
        }
        close(inPipe[0]); close(inPipe[1]);
        if (output != nullptr) { close(outPipe[0]); close(outPipe[1]); }
        execvp("xclip", argv);
        _exit(127);   // exec 失败（PATH 里没有 xclip）⇒ 让父进程拿到明确退出码
    }

    close(inPipe[0]);
    if (output != nullptr) {
        close(outPipe[1]);
    }

    int flags = fcntl(inPipe[1], F_GETFL, 0);
    if (flags >= 0) fcntl(inPipe[1], F_SETFL, flags | O_NONBLOCK);
    if (output != nullptr) {
        flags = fcntl(outPipe[0], F_GETFL, 0);
        if (flags >= 0) fcntl(outPipe[0], F_SETFL, flags | O_NONBLOCK);
    }

    // 忽略 SIGPIPE 并记住旧 handler：写完立刻恢复，不影响进程其余部分。
    void (*oldSigpipe)(int) = signal(SIGPIPE, SIG_IGN);

    const auto deadline = std::chrono::steady_clock::now()
                          + std::chrono::milliseconds(timeoutMs);
    size_t written = 0;
    bool inClosed = input.empty();
    if (inClosed) close(inPipe[1]);
    bool outOpen = (output != nullptr);
    bool timedOut = false;
    int status = 0;

    while (true) {
        // 读模式：stdout EOF 即 xclip 交付完毕。写模式：stdin 写完后只需等子进程退出。
        const bool done = (output != nullptr)
                          ? (inClosed && !outOpen)
                          : false;
        if (done) {
            break;
        }
        if (output == nullptr && inClosed) {
            const pid_t r = waitpid(pid, &status, WNOHANG);
            if (r == pid) break;
            if (r < 0 && errno != EINTR) break;
        }

        const int remaining = (int) std::chrono::duration_cast<std::chrono::milliseconds>(
            deadline - std::chrono::steady_clock::now()).count();
        if (remaining <= 0) {
            timedOut = true;
            break;
        }

        struct pollfd fds[2];
        int nfds = 0;
        int inIdx = -1;
        int outIdx = -1;
        if (!inClosed) {
            fds[nfds] = pollfd{inPipe[1], POLLOUT, 0};
            inIdx = nfds++;
        }
        if (outOpen) {
            fds[nfds] = pollfd{outPipe[0], POLLIN, 0};
            outIdx = nfds++;
        }
        // 写模式 nfds 可能为 0（已写完 stdin）⇒ poll 退化成 20ms 睡眠，
        // 每轮回顶部用 WNOHANG 探一次子进程，既不忙等也不会无限阻塞。
        const int pollMs = (output == nullptr) ? std::min(remaining, 20) : remaining;
        const int pr = poll(fds, (nfds_t) nfds, pollMs);
        if (pr < 0) {
            if (errno == EINTR) continue;
            timedOut = true;
            break;
        }
        if (pr == 0) {
            if (output == nullptr) continue;   // 写模式：下一轮再探子进程
            timedOut = true;
            break;
        }

        if (inIdx >= 0 && (fds[inIdx].revents & POLLOUT)) {
            const ssize_t n = write(inPipe[1], input.data() + written, input.size() - written);
            if (n > 0) {
                written += (size_t) n;
                if (written == input.size()) {
                    close(inPipe[1]);
                    inClosed = true;
                }
            } else if (n < 0 && errno != EAGAIN && errno != EINTR) {
                // EPIPE：xclip 已经走了（PATH 缺失 / 提前退出）。停止写，等回收。
                close(inPipe[1]);
                inClosed = true;
            }
        }
        if (outIdx >= 0 && (fds[outIdx].revents & (POLLIN | POLLHUP))) {
            char buf[8192];
            const ssize_t n = read(outPipe[0], buf, sizeof(buf));
            if (n > 0) {
                output->append(buf, (size_t) n);
            } else if (n == 0) {
                close(outPipe[0]);
                outOpen = false;
            } else if (errno != EAGAIN && errno != EINTR) {
                close(outPipe[0]);
                outOpen = false;
            }
        }
    }

    if (timedOut) {
        kill(pid, SIGKILL);
    }
    // 无条件回收：done 分支可能尚未 waitpid，超时分支刚 kill —— 都要 reap。
    while (waitpid(pid, &status, 0) < 0) {
        if (errno == EINTR) continue;
        break;
    }
    if (inPipe[1] >= 0 && !inClosed) close(inPipe[1]);
    if (outPipe[0] >= 0 && outOpen) close(outPipe[0]);
    signal(SIGPIPE, oldSigpipe);

    if (timedOut) return -2;
    if (output != nullptr) {
        return WIFEXITED(status) ? WEXITSTATUS(status) : -1;
    }
    return 0;
}

}   // namespace

std::string clipboardReadText()
{
    // ★ 显式 -t UTF8_STRING：不指定时 xclip 默认按 STRING(Latin-1) 回答，
    //   中文会变问号（WSL Xvfb 实测）。无 owner 时 xclip 立即 exit 1 ⇒ 空串。
    char *const argv[] = {
        const_cast<char *>("xclip"),
        const_cast<char *>("-o"),
        const_cast<char *>("-selection"),
        const_cast<char *>("clipboard"),
        const_cast<char *>("-t"),
        const_cast<char *>("UTF8_STRING"),
        nullptr,
    };
    std::string out;
    if (runXclip(argv, std::string(), &out, kClipTimeoutMs) != 0) {
        return std::string();
    }
    return out;
}

void clipboardWriteText(const std::string &text)
{
    char *const argv[] = {
        const_cast<char *>("xclip"),
        const_cast<char *>("-selection"),
        const_cast<char *>("clipboard"),
        const_cast<char *>("-t"),
        const_cast<char *>("UTF8_STRING"),
        nullptr,
    };
    // 失败静默：端口契约 writeText(): void 没有错误通道；xclip 缺失 / DISPLAY 不对
    // 都会走 exec 失败或退出码非 0，这里不向上抛（与 Win32Clipboard 同口径）。
    runXclip(argv, text, nullptr, kClipTimeoutMs);
}

bool clipboardHasText()
{
    // 先问 TARGETS：没有文本 target 就别读正文。这样"无 owner / 只有图片"是一条
    // 独立判断，不再把"剪贴板里是空串"与"压根没有 owner"混成同一次 readText。
    char *const targetsArgv[] = {
        const_cast<char *>("xclip"),
        const_cast<char *>("-o"),
        const_cast<char *>("-selection"),
        const_cast<char *>("clipboard"),
        const_cast<char *>("-t"),
        const_cast<char *>("TARGETS"),
        nullptr,
    };
    std::string targets;
    if (runXclip(targetsArgv, std::string(), &targets, kClipTimeoutMs) != 0) {
        return false;
    }
    static const char *const kTextTargets[] = {
        "UTF8_STRING", "text/plain;charset=utf-8", "text/plain", "STRING", "TEXT",
    };
    bool textTarget = false;
    for (const char *target : kTextTargets) {
        if (targets.find(target) != std::string::npos) {
            textTarget = true;
            break;
        }
    }
    if (!textTarget) {
        return false;
    }

    // Clipboard 端口只有三方法：hasText 仍按"可读且非空"回答，保留 Fake/Win32 契约。
    return !clipboardReadText().empty();
}

}   // namespace x11
}   // namespace drift
