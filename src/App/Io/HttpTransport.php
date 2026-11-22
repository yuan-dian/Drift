<?php
declare(strict_types=1);

namespace Drift\App\Io;

use Drift\Async\Future;

/**
 * 异步 HTTP 的**传输后端** —— 替换实现不动门面与应用。
 *
 * 借鉴 .NET `HttpMessageHandler` / reqwest backend feature：门面（`AsyncHttp`）
 * 只认"提交请求返回 Future"，具体怎么把请求跑完由后端决定。当前唯一实现是
 * `CurlMultiTransport`（帧内 curl_multi，零新增依赖，AOT 已验证）。
 *
 * ## 为什么这里不写 Swoole 实现
 *
 * `Co\Http\Client` 不需要全局 hook，解释器与 AOT 主线程协程化都跑通了；但把
 * reactor 关进 `Swoole\Thread` 在 AOT 产物里会重入主入口（线程入口要重新加载
 * .php 脚本，与"源码全编进 exe"结构性冲突，见 `.workbuddy-ai/memory` P9）。
 * ⇒ 在 AOT 分发形态下 Swoole 后端不可用，本接口只保留契约，不埋死代码。
 * 重开条件：编译器支持线程入口 / 用户显式切到解释器分发。
 *
 * @api
 */
interface HttpTransport
{
    /**
     * 提交一次请求，立刻返回一条未结算的 Future。
     *
     * 结算发生在 UI 线程的帧内（后端 pump() → Future::resolve），与 TaskHost
     * 的完成回调同级。取消沿 Future 传播到后端的 remove handle。
     *
     * @param array<string, mixed> $opts 与 `Http::request()` 同款；multipart / 大 body
     *        由门面提前分流，不会到这里
     */
    public function submit(string $method, string $url, array $opts): Future;

    /** 每帧推进一次：exec + 收完成 + 结算。必须在 UI 线程的 settle 之前调 */
    public function pump(): void;

    /** 在途请求数（测试与 devtools 用） */
    public function inFlight(): int;
}
