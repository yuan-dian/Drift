<?php
declare(strict_types=1);

namespace App\Watermark\Api;

/**
 * 后端客户端工厂。
 *
 * ★ 唯一决定"走真实网关还是离线 mock"的地方（见 {@see Config::MOCK}）。
 *   Job / Store 只调 `Api::client()`，不直接 `new ApiClient` —— 切换后端
 *   不用改任何一个业务文件。
 */
final class Api
{
    public static function client(string $base = Config::API_BASE): WatermarkApi
    {
        return Config::$mock ? new MockApiClient() : new ApiClient($base);
    }
}
