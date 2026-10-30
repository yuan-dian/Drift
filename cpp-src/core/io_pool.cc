/**
 * 跨平台 IO 线程池的**池体** —— 见 `core/io_pool.h` 的三条不变量与拆分理由。
 *
 * ★ 本文件**不许** `#include <windows.h>`（也不许用 `Sleep` / `PostMessageW`）：
 *   池体是纯 C++20。平台差异只有两处，都已收进钩子与标准库：
 *     · "睡一会儿" ⇒ `std::this_thread::sleep_for`
 *     · "叫醒 UI"   ⇒ `drift::io::setWakeHook()` 装进来的函数指针
 */

#include "io_pool.h"

#include <atomic>
#include <chrono>
#include <condition_variable>
#include <cstdio>
#include <cstdlib>   // std::atexit —— 退出兜底（见 `start()`）
#include <deque>
#include <mutex>
#include <thread>
#include <vector>

namespace drift {
namespace io {

namespace {

/** 一条待执行的 IO 任务（跨线程只传值，不传任何 PHP 对象） */
struct Job {
    int id;
    std::string handler;
    int intArg;
    std::string strArg;
};

std::mutex g_mu;
std::condition_variable g_cv;
std::deque<Job> g_queue;
std::deque<std::string> g_done;   // 已编码好的 JSON 对象片段（一次 poll 全取走）
std::vector<std::thread> g_threads;
bool g_stop = false;

/** 累计执行条数（跨线程写、探针读 ⇒ atomic；也是"0 丢失"判据的分子） */
std::atomic<long long> g_handled{0};

WakeFn g_wakeFn = nullptr;
void *g_wakeCtx = nullptr;

/** JSON 字符串转义 —— 手写而不是引第三方：只有 6 个字符 + 控制字符。 */
std::string jsonEscape(const std::string &s)
{
    std::string out;
    out.reserve(s.size() + 8);

    for (unsigned char c : s) {
        switch (c) {
            case '"': out += "\\\""; break;
            case '\\': out += "\\\\"; break;
            case '\n': out += "\\n"; break;
            case '\r': out += "\\r"; break;
            case '\t': out += "\\t"; break;
            default:
                if (c < 0x20) {
                    char buf[8];
                    snprintf(buf, sizeof(buf), "\\u%04x", (unsigned)c);
                    out += buf;
                } else {
                    out += (char)c;
                }
        }
    }

    return out;
}

/**
 * worker 线程主体 —— **永远不进 Zend VM**。
 *
 * ★ 退出条件是"stop 且队列空"：stop 之后仍把队列里的活干完（`stop()` 的调用方
 *   是关窗路径，丢活只会让调用方看到一个永不结算的 Future）。
 */
void worker()
{
    for (;;) {
        Job job;

        {
            std::unique_lock<std::mutex> lock(g_mu);
            g_cv.wait(lock, [] { return g_stop || !g_queue.empty(); });

            if (g_queue.empty()) {
                return;   // stop 且没活
            }

            job = g_queue.front();
            g_queue.pop_front();
        }

        bool ok = true;
        std::string valueJson;   // **已是 JSON 词法单元**（数字不带引号、字符串带）
        std::string error;

        if (job.handler == "sleep") {
            if (job.intArg > 0) {
                std::this_thread::sleep_for(std::chrono::milliseconds(job.intArg));
            }
            valueJson = std::to_string(job.intArg);
        } else if (job.handler == "echo") {
            valueJson = "\"" + jsonEscape(job.strArg) + "\"";
        } else {
            ok = false;
            error = "未注册的 IO 处理函数：" + job.handler;
        }

        g_handled.fetch_add(1);

        std::string row = "{\"id\":" + std::to_string(job.id)
            + ",\"ok\":" + (ok ? "true" : "false")
            + ",\"value\":" + (valueJson.empty() ? "null" : valueJson)
            + ",\"error\":\"" + jsonEscape(error) + "\"}";

        {
            std::lock_guard<std::mutex> lock(g_mu);
            g_done.push_back(std::move(row));
        }

        // ★★ 顺序不能反（不变量 ①）：先入队、再叫醒。
        if (g_wakeFn != nullptr) {
            g_wakeFn(g_wakeCtx);
        }
    }
}

}   // namespace

void setWakeHook(WakeFn fn, void *ctx)
{
    g_wakeFn = fn;
    g_wakeCtx = ctx;
}

int start(int threads)
{
    int n = threads;

    if (n < 1) {
        n = 1;
    }
    if (n > 16) {
        n = 16;   // 上限：再多的线程只会抢 CPU，IO 密集用不到
    }

    if (!g_threads.empty()) {
        return (int) g_threads.size();
    }

    // ★★ 退出兜底：`std::thread` 在**仍 joinable** 时析构 ⇒ `std::terminate`
    //   （症状是进程退出码不是 0，看起来像崩溃）。只要调用方忘了 `stop()`，
    //   这就是必然发生的事 ⇒ 注册一次 atexit 把线程收掉。
    //   ★ 顺序成立：本行在运行时执行，晚于 `g_threads` 的静态构造 ⇒ 逆序析构时
    //     本钩子**先**跑，那时 `g_threads` 还是活的。
    static bool exitHookInstalled = false;
    if (!exitHookInstalled) {
        exitHookInstalled = true;
        std::atexit([] { stop(); });
    }

    g_stop = false;

    for (int i = 0; i < n; i++) {
        g_threads.emplace_back(worker);
    }

    return n;
}

bool submit(int id, const std::string &handler, int intArg, const std::string &strArg)
{
    if (g_threads.empty()) {
        return false;
    }

    Job job;
    job.id = id;
    job.handler = handler;
    job.intArg = intArg;
    job.strArg = strArg;

    {
        std::lock_guard<std::mutex> lock(g_mu);
        g_queue.push_back(std::move(job));
    }
    g_cv.notify_one();

    return true;
}

std::string poll()
{
    std::deque<std::string> done;

    {
        std::lock_guard<std::mutex> lock(g_mu);
        if (g_done.empty()) {
            return "[]";
        }
        done.swap(g_done);
    }

    std::string out = "[";
    bool first = true;

    for (const std::string &row : done) {
        if (!first) {
            out += ",";
        }
        first = false;
        out += row;
    }
    out += "]";

    return out;
}

int threads()
{
    return (int) g_threads.size();
}

long long handled()
{
    return g_handled.load();
}

int stop()
{
    {
        std::lock_guard<std::mutex> lock(g_mu);
        g_stop = true;
    }
    g_cv.notify_all();

    for (std::thread &t : g_threads) {
        if (t.joinable()) {
            t.join();
        }
    }
    g_threads.clear();

    return 0;
}

}   // namespace io
}   // namespace drift
