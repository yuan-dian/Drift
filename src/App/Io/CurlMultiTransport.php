<?php
declare(strict_types=1);

namespace Drift\App\Io;

use Drift\Async\Future;
use Drift\Core\Pipeline;

/**
 * 帧内 curl_multi 传输后端 —— UI 主线程零改动，请求在帧里推进。
 *
 * 每条请求经 `Http::prepare()` 配置成 CurlHandle，add 进一个 long-lived multi 句柄；
 * `pump()` 每帧 `exec + select(0)`，完成的用 `Http::finish()` 结算。
 * 语义（校验 / TLS / 头 / 错误分类）全在 Http，本类只做调度 —— 两条路径零分叉。
 *
 * ★ by-ref：`curl_multi_exec($mh, $running)` 的 `$running` 用**局部变量** ——
 *   属性/数组元素的 by-ref 传递 tpc 未验。每次 exec 自行重算 running。
 * ★ 不调 `curl_close()`：PHP 8 起它已是空操作；AOT 下"从对象属性取出的 CurlHandle
 *   传进 curl_close"会在帧内结算时原生崩溃（进程直接失联，界面永久 loading）。
 *   `curl_multi_remove_handle()` + 对象回收就够了。
 * ★ 结算在 UI 帧内：pump() 由 PumpHost 在 settle 之前调，`Future::resolve()` 与
 *   TaskHost 回调同级；回调里 setState 同帧生效。
 * ★ 取消：Future 的 onCancel remove handle + 关 multipart，迟到结果不再结算。
 *
 * @api
 */
final class CurlMultiTransport implements HttpTransport
{
    /** long-lived multi 句柄（惰性建：构造不碰 curl 扩展） */
    private mixed $multi = null;

    /** @var array<int, array{prepared: PreparedRequest, future: Future}> id → 在途项 */
    private array $inflight = [];

    private int $next = 1;

    public function submit(string $method, string $url, array $opts): Future
    {
        $prepared = Http::prepare($method, $url, $opts);

        if ($this->multi === null) {
            $this->multi = \curl_multi_init();
        }

        $id = $this->next;
        $this->next = $this->next + 1;

        $future = Future::pending(function () use ($id): void {
            $this->drop($id);
        });

        // add 失败不能登记：否则 Future 永不结算、inFlight 恒 > 0 ⇒ 帧时钟永久空转。
        $added = \curl_multi_add_handle($this->multi, $prepared->handle);
        if ($added !== CURLM_OK) {
            $prepared->closeMultipart();
            $future->reject(new \RuntimeException('curl_multi_add_handle 失败（code ' . $added . '）：' . $url));

            return $future;
        }

        $this->inflight[$id] = ['prepared' => $prepared, 'future' => $future];

        // 注册即要帧：只有 submit 那一帧没人再举手 ⇒ 帧时钟睡死，结果永远不结算。
        Pipeline::current()->scheduleNextFrame();

        return $future;
    }

    public function pump(): void
    {
        if ($this->multi === null || $this->inflight === []) {
            return;
        }

        $running = 0;
        do {
            $status = \curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        \curl_multi_select($this->multi, 0);

        while (($info = \curl_multi_info_read($this->multi)) !== false) {
            if (!\is_array($info) || $info['msg'] !== CURLMSG_DONE) {
                continue;
            }
            $this->settleOne($info['handle'], (int) $info['result']);
        }
    }

    public function inFlight(): int
    {
        return \count($this->inflight);
    }

    /**
     * @param mixed $handle 完成的 CurlHandle（用身份比较反查 id，handle 数 < 10）
     * @param int $result curl_multi_info_read 给的传输结果码（0 = 成功）
     */
    private function settleOne(mixed $handle, int $result): void
    {
        $id = 0;
        foreach ($this->inflight as $key => $item) {
            if ($item['prepared']->handle === $handle) {
                $id = $key;
                break;
            }
        }
        if ($id === 0) {
            return; // 已被 cancel() 摘掉的迟到完成
        }

        $prepared = $this->inflight[$id]['prepared'];
        $future = $this->inflight[$id]['future'];

        // 先读完 curl 能回答的，再摘 handle（摘掉后 getinfo 是未定义行为）。
        $raw = \curl_multi_getcontent($handle);
        $errno = $result !== 0 ? $result : (int) \curl_errno($handle);
        $error = (string) \curl_error($handle);
        $status = (int) \curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effective = (string) \curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $elapsedMs = ((float) \curl_getinfo($handle, CURLINFO_TOTAL_TIME)) * 1000.0;

        // 读完再摘：此刻 resolve 还没跑，回调里若再 submit()，inflight 是干净的。
        $this->drop($id);

        try {
            $response = Http::finish($prepared, $raw, $errno, $error, $status, $effective, $elapsedMs);
            $future->resolve($response);
        } catch (\Throwable $e) {
            $future->reject($e);
        }
    }

    /** 从 multi 与在途表摘掉一条，并关 multipart 文件句柄 */
    private function drop(int $id): void
    {
        if (!isset($this->inflight[$id])) {
            return;
        }
        $prepared = $this->inflight[$id]['prepared'];
        \curl_multi_remove_handle($this->multi, $prepared->handle);
        $prepared->closeMultipart();
        unset($this->inflight[$id]);
    }
}
