<?php
declare(strict_types=1);

namespace App\Watermark\Api;

/**
 * 后端契约（**唯一一处**）—— 从 `shuiyin.html` 的 JS 里摸出来的，写死在这儿。
 *
 * ★ 为什么不是配置文件 / 环境变量：这三个值（地址 / 应用号 / 模板号）是**业务
 *   身份**，不是部署参数。`§16.7 nano-hygiene` 禁 `getenv()`（AOT 不保证环境变量），
 *   而"换一个后端"这件事今天没有第二个调用方 —— 真需要时加构造参数，不加环境变量。
 * ★ 测试要打别的地址时走 `ApiClient` 的构造参数（默认值取这里）。
 */
final class Config
{
    /** 网关根（**不带尾斜杠**） */
    public const API_BASE = 'http://***REMOVED***/***REMOVED***';

    /** 应用号：同时出现在 `?appId=` 与 `uc-appCode` 头里 */
    public const APP_ID = '***REMOVED***';

    /** 模板号（决定"水印"长什么样） */
    public const TEMPLATE_ID = '***REMOVED***';

    /**
     * 统一请求头 —— 三个都是网关的租户标识。
     *
     * ★★ **`uc-userName` 是中文**（原文里是 `'11'`，但注释写明了这条限制）：
     *   原 HTML 用 XHR 不用 `fetch` 的**唯一**原因就是 `fetch` 的 Headers 不允许
     *   非 ISO-8859-1 字符。本仓走 curl，没有这条限制 —— 所以这里可以放中文。
     *   真要放中文时**别改回 fetch 时代的写法**（那是给浏览器 API 让路）。
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            'uc-userId' => '1',
            'uc-userName' => '11',
            'uc-appCode' => self::APP_ID,
        ];
    }

    /** OSS 签名的有效期余量（毫秒）：剩余不足这个数就当"快过期"，重新签一次 */
    public const SIGN_REFRESH_MARGIN_MS = 60_000;

    /** 轮询间隔（毫秒）—— 原文是 `setInterval(..., 10000)` */
    public const POLL_INTERVAL_MS = 10_000;

    /** 连接超时（秒）：网关在国内，5 秒连不上就该报错 */
    public const CONNECT_TIMEOUT_S = 5.0;

    /** 普通请求总超时（秒） */
    public const TIMEOUT_S = 15.0;

    /** 上传总超时（秒）：大图 + 慢上行，比普通请求宽裕得多 */
    public const UPLOAD_TIMEOUT_S = 300.0;
}
