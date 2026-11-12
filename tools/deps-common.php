<?php
declare(strict_types=1);

/**
 * `fetch-deps` 与 `release-deps` 共用的 GitHub 访问层：推 repo、取 token、调 API、
 * 下载与上传文件。
 *
 * 刻意不 require `tools/autoload.php` —— 这两个脚本的定位是「装个 PHP 就能跑」，
 * 取一个二进制依赖不该顺带拉起 TypePHP 的类图。
 */

const DEPS_UA = 'drift-deps/1';
const DEPS_API = 'https://api.github.com';
const DEPS_UPLOADS = 'https://uploads.github.com';

/**
 * API 根，可用 `DRIFT_DEPS_API` 覆盖 —— GitHub Enterprise 的地址与公开站不同，
 * 本地也可以用它指一台 mock 服务把整条链路跑一遍。
 */
function depsApiRoot(): string
{
    $env = getenv('DRIFT_DEPS_API');
    return is_string($env) && trim($env) !== '' ? rtrim(trim($env), '/') : DEPS_API;
}

/** 上传根，与 `depsApiRoot()` 同理；未覆盖时按 API 根推（GHE 两者同域）。 */
function depsUploadsRoot(): string
{
    $env = getenv('DRIFT_DEPS_UPLOADS');
    return is_string($env) && trim($env) !== '' ? rtrim(trim($env), '/') : DEPS_UPLOADS;
}

/**
 * 仓库自带的信任根。本机 php.ini 的 `curl.cainfo` 指向一个不存在的路径
 * （`C:\tools\php\ssl\cacert.pem`），表现是所有 https 请求直接失败、HTTP 码 0，
 * 看起来像网络不通。assets/cacert.pem 本来就是给运行期 curl 用的，直接复用。
 */
function depsCaBundle(): ?string
{
    static $resolved = false;
    static $path = null;
    if (!$resolved) {
        $resolved = true;
        $candidate = dirname(__DIR__) . '/assets/cacert.pem';
        $path = is_file($candidate) ? $candidate : null;
    }
    return $path;
}

/** curl 失败时补一句人话：CA 不匹配和网络不通在日志里长得一模一样。 */
function depsCurlHint(string $err): void
{
    if (stripos($err, 'trust anchor') !== false || stripos($err, 'certificate') !== false) {
        $ca = depsCaBundle();
        fwrite(STDERR, $ca === null
            ? "  …… TLS 信任根有问题，且仓库里没有 assets/cacert.pem 可兜底\n"
            : "  …… 用了仓库自带的 assets/cacert.pem，仍然失败，检查系统证书\n");
    }
}

/**
 * 从 origin 推 owner/repo：仓库托管到 GitHub 之后，这一步不该再让人手填。
 * 支持 https 与 scp 风格的 ssh 地址。
 */
function depsRepoFromOrigin(): ?string
{
    $out = [];
    $code = 0;
    exec('git config --get remote.origin.url 2>&1', $out, $code);
    if ($code !== 0 || $out === []) {
        return null;
    }
    if (preg_match('#github\.com[:/]+([^/]+)/([^/]+?)(?:\.git)?/?$#', trim($out[0]), $m)) {
        return $m[1] . '/' . $m[2];
    }
    return null;
}

/** 当前分支指向的 commit —— 发版时用它当 tag 的落点，比默认分支的 HEAD 可控。 */
function depsHeadSha(): ?string
{
    $out = [];
    $code = 0;
    exec('git rev-parse HEAD 2>&1', $out, $code);
    if ($code !== 0 || $out === []) {
        return null;
    }
    $sha = trim($out[0]);
    return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
}

/**
 * token 来源：参数 > GITHUB_TOKEN > GH_TOKEN > `~/.git-credentials`。
 *
 * Windows 的 credential.helper=manager 把凭据放进系统凭据库，读不出来 ——
 * 那种机器上只能靠环境变量，所以报错信息里要点名这一条。
 */
function depsToken(?string $cli): ?string
{
    if ($cli !== null && trim($cli) !== '') {
        return trim($cli);
    }
    foreach (['GITHUB_TOKEN', 'GH_TOKEN'] as $name) {
        $v = getenv($name);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }

    $home = getenv('HOME');
    if (!is_string($home) || $home === '') {
        $home = getenv('USERPROFILE');
    }
    if (!is_string($home) || $home === '') {
        return null;
    }
    $file = rtrim($home, '/\\') . '/.git-credentials';
    if (!is_file($file)) {
        return null;
    }
    foreach (file($file) ?: [] as $line) {
        if (preg_match('#^https://[^:@/]+:([^@]+)@github\.com#', trim($line), $m)) {
            return urldecode($m[1]);
        }
    }
    return null;
}

/**
 * 发一个 API 请求。$payload 非空时按 JSON POST。
 *
 * @return array{0:int,1:?string} 状态码与响应体
 */
function depsRequest(string $method, string $url, ?string $token, ?array $payload = null): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: ' . DEPS_UA,
    ];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }

    if (!function_exists('curl_init')) {
        fwrite(STDERR, "需要 curl 扩展（GitHub API 无法用 stream 退化处理写操作）\n");
        return [0, null];
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => DEPS_UA,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    $ca = depsCaBundle();
    if ($ca !== null) {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        fwrite(STDERR, "请求失败：{$err}\n{$url}\n");
        depsCurlHint($err);
        return [0, null];
    }
    return [$code, (string) $resp];
}

/** @return array<string,mixed>|null */
function depsApiJson(string $url, ?string $token): ?array
{
    [$code, $body] = depsRequest('GET', $url, $token);
    if ($code === 200 && $body !== null) {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
    $why = match ($code) {
        401 => 'token 无效或已过期',
        403 => 'token 权限不足（需要 repo 读权限），或触发了速率限制',
        404 => 'release 不存在，或 token 看不到这个仓库',
        default => "HTTP {$code}",
    };
    fwrite(STDERR, "  …… GitHub API 失败：{$why}\n");
    return null;
}

/**
 * 下载到 $dest。$resume 为真时从已有字节续传。
 *
 * 带 Authorization 时跨域 302 会被 curl 自动剥掉 —— 这是要的：
 * 资产最终落在 objects.githubusercontent.com 的签名 URL 上，多带一个头的反而会被拒。
 *
 * @param list<string> $headers
 * @return int HTTP 状态码；0 表示失败（原因已打印，$quiet 时留给调用方说）
 */
function depsDownload(string $url, string $dest, array $headers = [], bool $quiet = false, bool $resume = false): int
{
    $offset = $resume && is_file($dest) ? (int) filesize($dest) : 0;
    $fp = $offset > 0 ? fopen($dest, 'r+b') : fopen($dest, 'wb');
    if ($fp === false) {
        fwrite(STDERR, "无法写入 {$dest}\n");
        return 0;
    }
    if ($offset > 0) {
        fseek($fp, $offset);
    }

    $ca = depsCaBundle();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => DEPS_UA,
        ];
        if ($headers !== []) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        if ($offset > 0) {
            // 本机 PHP 8.5 的 curl 常量表里没有 _LARGE 变体；包远小于 2 GB，int 版够用
            $opts[defined('CURLOPT_RESUME_FROM_LARGE') ? CURLOPT_RESUME_FROM_LARGE : CURLOPT_RESUME_FROM] = $offset;
        }
        if ($ca !== null) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($ok === false || $code >= 400) {
            if (!$quiet) {
                fwrite(STDERR, "下载失败（HTTP {$code}）：{$err}\n{$url}\n");
                depsCurlHint($err);
            }
            return 0;
        }
        return $code;
    }

    $ctx = stream_context_create([
        'http' => [
            'header' => implode("\r\n", array_merge($headers, ['User-Agent: ' . DEPS_UA])),
            'follow_location' => 1,
            'timeout' => 1800,
        ],
        'ssl' => $ca === null ? [] : ['cafile' => $ca],
    ]);
    $in = @fopen($url, 'rb', false, $ctx);
    if ($in === false) {
        fclose($fp);
        if (!$quiet) {
            fwrite(STDERR, "下载失败：{$url}\n");
        }
        return 0;
    }
    stream_copy_to_stream($in, $fp);
    fclose($in);
    fclose($fp);
    return 200;
}

/**
 * 上传本地文件为 release 资产。用 INFILE 流式发，50 MB 的包不进内存。
 *
 * @return array{0:int,1:?string}
 */
function depsUpload(string $url, string $path, string $mime, ?string $token): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        fwrite(STDERR, "无法读取 {$path}\n");
        return [0, null];
    }
    $size = (int) filesize($path);

    $headers = [
        'Content-Type: ' . $mime,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: ' . DEPS_UA,
        // 大包默认会先发 100-continue 再等回执；某些代理在这步卡到超时
        'Expect:',
    ];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_UPLOAD => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_INFILE => $fp,
        CURLOPT_INFILESIZE => $size,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 1800,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => DEPS_UA,
    ];
    $ca = depsCaBundle();
    if ($ca !== null) {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if ($resp === false) {
        fwrite(STDERR, "上传失败：{$err}\n");
        depsCurlHint($err);
        return [0, null];
    }
    return [$code, (string) $resp];
}
