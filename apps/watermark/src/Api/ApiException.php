<?php
declare(strict_types=1);

namespace App\Watermark\Api;

/**
 * 后端调用失败（**业务**层）。
 *
 * ★ 为什么不直接让 `HttpException` 冒上去：`HttpException` 的语义是"传输层失败"
 *   （连不上 / 超时 / 非 2xx），而"网关回 `code != server.success`"是**业务**
 *   失败 —— HTTP 状态码可能是 200。两者都叫"失败"，但用户要做的事完全不同
 *   （检查网络 vs 看 message）。分开之后 `catch` 的位置也能表达意图。
 * ★ 消息**直接给用户看**（会显示在状态栏里），所以每条都要能读懂 —— 不带
 *   `Exception` / 堆栈 / 内部类名。
 */
final class ApiException extends \RuntimeException
{
}
