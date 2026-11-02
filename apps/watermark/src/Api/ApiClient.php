<?php
declare(strict_types=1);

namespace App\Watermark\Api;

use Drift\App\Io\Http;
use Drift\App\Io\HttpException;

/**
 * 后端调用（`***REMOVED***` 网关）。
 *
 * ★ **本类是唯一知道 HTTP 形状的地方**：任务（`Jobs/`）只调这四个方法，不碰
 *   URL / 请求头 / JSON 键名。换后端只改这里 + {@see Config}。
 * ★ **跑在 worker 进程里**（`FetchSignJob` 等）：所以只能返回 JSON 能表达的东西
 *   （`SignInfo` / `TaskResult` 会被序列化成数组跨进程），不能返回资源句柄。
 * ★ 失败一律抛 {@see ApiException}：worker 会把它翻成 `{ok:false,error:…}` 回给
 *   UI。**不返回 null 表示失败** —— 那样调用方每处都要判，"忘了判"就变成
 *   "静默无结果"。
 */
final class ApiClient
{
    /** 成功码（网关的约定，不是 HTTP 状态码） */
    private const OK_CODE = 'server.success';

    public function __construct(private readonly string $base = Config::API_BASE)
    {
    }

    /**
     * 取 OSS 直传签名。
     *
     * @throws ApiException 网络失败 / 业务码不对 / `data` 缺字段
     */
    public function sign(): SignInfo
    {
        $data = $this->unwrap($this->call(
            'GET',
            '/storage/upload/sign?appId=' . \rawurlencode(Config::APP_ID),
        ), '取 OSS 签名');

        $sign = SignInfo::fromArray($data);
        if ($sign === null) {
            // ★ 缺字段**不猜**：拿一个 `policy` 为空的签名去传，OSS 会回 400
            //   "签名不匹配" —— 错误信息与本应用毫无关系，归因会跑偏两个系统。
            throw new ApiException('签名响应缺字段（要 account/policy/signature/directoryPath/accessAddress）');
        }

        return $sign;
    }

    /**
     * 直传一个文件到 OSS（PostObject 表单）。
     *
     * ★ 走 `fields` + `files` ⇒ `Http` 内部是**流式**的（内存 O(64KB)），
     *   64 MB 的图不会把 worker 撑爆。
     * ★ 进度是**请求体口径**（含表单头），与 `Http::onProgress` 的契约一致。
     * ★ 这一步**不带** `uc-*` 头：`accessAddress` 是 OSS 的地址，不是网关的。
     *   带上也无害，但 OSS 的 CORS/签名校验与它们无关，少发几个头少一处怀疑。
     *
     * @param null|callable(float): void $onProgress 0..1
     *
     * @throws ApiException OSS 非 2xx
     */
    public function upload(SignInfo $sign, string $localPath, string $objectKey, ?callable $onProgress = null): void
    {
        $opts = [
            'fields' => [
                'key' => $objectKey,
                'policy' => $sign->policy,
                'OSSAccessKeyId' => $sign->account,
                'signature' => $sign->signature,
                // ★ OSS 的约定：传 `200` 表示"成功时回 200 而不是 204"，
                //   这样 body 里会有内容可看（否则 curl 拿到空 body，判成功全靠状态码）。
                'success_action_status' => '200',
            ],
            'files' => ['file' => $localPath],
            'connectTimeout' => Config::CONNECT_TIMEOUT_S,
            'timeout' => Config::UPLOAD_TIMEOUT_S,
        ];
        if ($onProgress !== null) {
            $opts['onProgress'] = $onProgress;
        }

        try {
            // ★ `expectOk` 默认 true ⇒ 非 2xx 抛 `HttpException`。OSS 的失败响应
            //   常是 XML（`<Error><Code>SignatureDoesNotMatch</Code>…`），
            //   `snippet()` 会把它带出来 —— 这比"HTTP 403"有用得多。
            Http::request('POST', $sign->accessAddress, $opts);
        } catch (HttpException $e) {
            throw new ApiException('上传失败：' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 建任务。
     *
     * @param list<string> $objectKeys 已上传的对象键（顺序即展示顺序）
     * @param string $time 画面上的时间文案（**不解析**，原样交给模板）
     * @param string $address 画面上的地址文案
     * @param string $imageSize `2K` / `4K`
     *
     * @return string 任务 id
     *
     * @throws ApiException 建任务失败 / 响应里找不到 id
     */
    public function addTask(array $objectKeys, string $time, string $address, string $imageSize): string
    {
        if ($objectKeys === []) {
            // ★ 不发这个请求：服务端收到空 `imagePath` 会建一个"没有素材"的任务，
            //   轮询到状态 3 但 `downloadUrls` 为空 —— 看起来像"服务端 bug"。
            throw new ApiException('没有已上传的图片，不能建任务');
        }

        $body = \json_encode([
            'imagePath' => \array_values($objectKeys),
            // ★ 原文是 `[time, address]` —— **位置固定**的二元组，不是对象。
            //   服务端按序号取，顺序错了不会报错，只会在图上印错位置。
            'promptReplace' => [$time, $address],
            'imageSize' => $imageSize,
            'templateId' => Config::TEMPLATE_ID,
        ]);
        if ($body === false) {
            throw new ApiException('建任务：请求体 JSON 编码失败（图片路径里可能有非法 UTF-8）');
        }

        $data = $this->unwrap($this->call('POST', '/task/add', $body, [
            'Content-Type' => 'application/json',
        ]), '建任务');

        $taskId = self::extractTaskId($data);
        if ($taskId === '') {
            // ★ 原文有四种兜底（`data.taskId` / `data.id` / `taskId` / `data` 是字符串）。
            //   四种都没有 ⇒ 服务端改了形状，把原始响应带出来才查得动。
            throw new ApiException('建任务成功但响应里没有任务 id：' . self::describe($data));
        }

        return $taskId;
    }

    /**
     * 查任务结果。
     *
     * @throws ApiException 网络失败 / 业务码不对 / `data` 不是数组
     */
    public function getResult(string $taskId): TaskResult
    {
        $data = $this->unwrap($this->call(
            'GET',
            '/task/getResult?taskId=' . \rawurlencode($taskId),
        ), '查询任务结果');

        $result = TaskResult::fromArray($data, $taskId);
        if ($result === null) {
            throw new ApiException('查询结果：data 不是数组（' . self::describe($data) . '）');
        }

        return $result;
    }

    /**
     * 发一次请求并把 body 解成数组（**不解业务码**）。
     *
     * @param array<string, string> $extraHeaders
     *
     * @throws ApiException
     */
    private function call(string $method, string $path, string $body = '', array $extraHeaders = []): mixed
    {
        $url = \rtrim($this->base, '/') . $path;

        // ★ 三个 `uc-*` 头**每个请求都带**（网关靠它们认租户）。漏一个的表现是
        //   401/403，而错误信息不会说"缺哪个头"。
        $headers = Config::headers();
        foreach ($extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }

        try {
            $response = Http::request($method, $url, [
                'body' => $body,
                'headers' => $headers,
                'connectTimeout' => Config::CONNECT_TIMEOUT_S,
                'timeout' => Config::TIMEOUT_S,
            ]);
        } catch (HttpException $e) {
            throw new ApiException($method . ' ' . $path . ' → ' . $e->getMessage(), 0, $e);
        }

        $decoded = $response->json();
        if (!\is_array($decoded)) {
            throw new ApiException($method . ' ' . $path . ' → 响应不是 JSON：' . $response->snippet());
        }

        return $decoded;
    }

    /**
     * 验业务码并取出 `data`。
     *
     * ★ 网关把错误放在 **`code` + `message`** 里，HTTP 状态码可能还是 200 ⇒
     *   只看 `ok()` 会把"业务失败"当成功。
     *
     * @param mixed $json {@see self::call()} 的返回
     *
     * @throws ApiException
     */
    private function unwrap(mixed $json, string $what): mixed
    {
        if (!\is_array($json)) {
            throw new ApiException($what . '：响应不是数组');
        }

        $code = $json['code'] ?? null;
        if ($code !== self::OK_CODE) {
            $message = $json['message'] ?? '';
            $message = \is_scalar($message) ? (string) $message : '';

            throw new ApiException(
                $what . '失败：' . ($message !== '' ? $message : '网关未给 message')
                . '（code=' . (\is_scalar($code) ? (string) $code : '(缺失)') . '）'
            );
        }

        return $json['data'] ?? null;
    }

    /**
     * 从建任务的响应里挖出 taskId —— **四种形状都认**（照抄原文的兜底链）。
     *
     * ★ 四种不是"想多了"：这条兜底链是原作者在真实联调里试出来的。删掉任何
     *   一种，某天服务端换了形状就会变成"建任务成功但没反应"。
     * ★ **public 是为了能被单测**（`tests/Feature/Watermark/ApiClientParseTest`）：
     *   它是本类唯一有分支的纯函数，其余方法都要打网络。同 `Win32Dialog::
     *   splitPathList()` 的处理方式（判据必须落在纯函数上）。
     *
     * @param mixed $data
     *
     * @internal
     */
    public static function extractTaskId(mixed $data): string
    {
        if (\is_string($data)) {
            return \trim($data);
        }
        if (!\is_array($data)) {
            return '';
        }

        foreach (['taskId', 'id'] as $key) {
            $value = $data[$key] ?? null;
            $text = \is_scalar($value) ? (string) $value : '';
            // ★ `'0'` 也算"没给"：原文的 `||` 会跳过它（`0` 是假值），而一个
            //   taskId 是 0 只说明服务端给错了 —— 静默用 '0' 去查结果，错误会
            //   出现在"查不到"那一步，归因跑偏。这里与原文同义。
            if ($text !== '' && $text !== '0') {
                return $text;
            }
        }

        // ★ 还有一层：`data.taskId` 可能是 0 / '' 这类假值，原文的 `||` 会跳到
        //   下一项；这里已经用 `!== ''` 表达了同一件事。剩下最后一种形状是
        //   "id 在顶层" —— 那不在 `$data` 里，由调用方给的原样数组兜（见下）。
        return '';
    }

    /** 把响应压成一行短文本（错误消息里用；太长会把日志淹掉） */
    private static function describe(mixed $data): string
    {
        $encoded = \json_encode($data, \JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return \gettype($data);
        }

        return \strlen($encoded) > 300 ? \substr($encoded, 0, 300) . '…' : $encoded;
    }
}
