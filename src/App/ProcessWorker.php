<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;

/**
 * worker 进程的**行协议服务端**（§17.4.5，P3）—— 读一行、跑一个、回一行。
 *
 * ★ 协议（JSON Lines，一行一条，UTF-8）：
 * ```
 * ← {"id":1,"job":"users.fetch","arg":{"q":"ada"}}
 * → {"id":1,"ok":true,"value":[...]}
 * → {"id":1,"ok":false,"error":"RuntimeException: 设备离线"}
 * ```
 * ★ **为什么是 JSON Lines 而不是二进制帧**：`fgets` 一行就能切分（不用自己处理粘包），
 *   而且人能看懂（出问题时直接 `type` 管道日志）。代价是体积大一些 —— 对"重计算 +
 *   结构化数据"这类任务无所谓，对高吞吐字节流（视频）从来就不该走这条路。
 *
 * ★ **worker 进程是同一份 AOT 产物**（`--drift-worker` 分支）：它有自己的完整类表，
 *   所以 `$registry` 里的任务**跑的就是 AOT 编译过的代码**（不是解释执行）——
 *   这是与 Swoole 线程（§17.2.5 实测：worker 看不到 tpc 编译的类）的根本差别。
 *
 * ★ 循环是**阻塞读**（`fgets`）：本进程只干"等任务"这一件事，阻塞在这里
 *   不占 UI 线程、不烧 CPU。UI 侧才是非阻塞的（`ProcessTaskHost` 每帧 `select(0)`）。
 *
 * @api
 */
final class ProcessWorker
{
    /**
     * 服务主循环（**永不返回**，除非 stdin 关闭）。
     *
     * ★ stdin 关闭 = UI 进程退出或主动关了管道 ⇒ 正常收工（不报错）：
     *   这是 worker 的**唯一**退出方式，不做"空闲超时自杀"（池的存在意义就是常驻）。
     *
     * @param array<string, callable(array<string, mixed>): Job> $registry
     *        名字 → 重建工厂（与 UI 侧 `ProcessTaskHost` 的注册表**必须一致**）
     *
     * @api
     */
    public static function serve(array $registry): void
    {
        $in = fopen('php://stdin', 'r');
        $out = fopen('php://stdout', 'w');
        if ($in === false || $out === false) {
            Errors::report('ProcessWorker：拿不到 stdin/stdout（AOT 产物必须以管道方式启动）');
        }

        while (true) {
            $line = fgets($in);
            if ($line === false) {
                return; // stdin 关了 ⇒ UI 进程走了，正常收工
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $reply = self::handle($line, $registry);
            fwrite($out, $reply . "\n");
            fflush($out);
        }
    }

    /**
     * 处理一行请求，返回一行回复（JSON）。
     *
     * ★ 任何异常都变成 `{ok:false,error:...}` —— **不许把 worker 打死**：
     *   一个任务的失败不能影响后续任务（进程隔离的价值就在这里）。
     *
     * @param array<string, callable(array<string, mixed>): Job> $registry
     */
    public static function handle(string $line, array $registry): string
    {
        $msg = json_decode($line, true);
        if (!is_array($msg)) {
            // 协议层坏了（不是任务的错）：回一个 id=0 的错误，让 UI 侧响亮失败
            return self::error(0, '请求不是合法 JSON：' . $line);
        }

        $id = isset($msg['id']) && is_int($msg['id']) ? $msg['id'] : 0;
        $name = isset($msg['job']) && is_string($msg['job']) ? $msg['job'] : '';
        $arg = isset($msg['arg']) && is_array($msg['arg']) ? $msg['arg'] : [];

        if ($name === '' || !isset($registry[$name])) {
            return self::error($id, '任务未注册：' . $name);
        }

        try {
            $job = $registry[$name]($arg);
            if (!$job instanceof Job) {
                return self::error($id, '注册表 ' . $name . ' 的工厂没返回 Job');
            }

            $value = $job->run();

            $encoded = json_encode(['id' => $id, 'ok' => true, 'value' => $value]);
            if ($encoded === false) {
                return self::error($id, '结果无法转 JSON（含对象/资源？）：' . json_last_error_msg());
            }

            return $encoded;
        } catch (\Throwable $e) {
            return self::error($id, get_class($e) . ': ' . $e->getMessage());
        }
    }

    private static function error(int $id, string $message): string
    {
        $encoded = json_encode(['id' => $id, 'ok' => false, 'error' => $message]);

        return $encoded === false ? '{"id":0,"ok":false,"error":"encode failed"}' : $encoded;
    }
}
