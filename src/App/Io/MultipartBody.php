<?php
declare(strict_types=1);

namespace Drift\App\Io;

/**
 * `multipart/form-data` 请求体的流式生产者。
 *
 * `read()` 是状态机：吐 part 头 → 按块读文件内容 → 推进到下一 part → 吐尾部；
 * curl 通过 `CURLOPT_READFUNCTION` 按需调它，每次最多 `CURL_MAX_READ_SIZE` 字节。
 * ★ 总长度先算不读（各 part 头 + `filesize()` + 尾部）⇒ `CURLOPT_POSTFIELDSIZE`
 *   拿精确值，避免请求退化成 `Transfer-Encoding: chunked`（老服务端不认）。
 * ★ 读失败要抛，不能返回 `''`：`''` 在 `CURLOPT_READFUNCTION` 里是"发完了"，
 *   截断的表单会让服务端报"缺字段"，真因隔着两个系统。
 *
 * @internal 只被 {@see Http::request()} 用；不进 `@api` 面。
 */
final class MultipartBody
{
    /**
     * 各 part 的描述。`path === ''` 表示纯字段（内容在 `head` 里）。
     *
     * @var list<array{head: string, path: string, size: int}>
     */
    private array $parts;

    /** 收尾的 `--boundary--\r\n`（吐完就置空，防止重复吐） */
    private string $tail;

    /** 请求体总字节数（`CURLOPT_POSTFIELDSIZE` 用） */
    private int $total;

    /** 下一个要吐的 part 下标 */
    private int $index = 0;

    /** 当前 part 还没吐完的头部字节（小，几十到几百字节） */
    private string $pending = '';

    /** @var resource|null 当前 part 的文件句柄（纯字段 part 时为 null） */
    private $handle = null;

    /** 当前文件 part 还剩多少字节没吐 */
    private int $left = 0;

    /**
     * 已吐出总字节数（上传进度的分子）。记在类内而非让调用方累加：
     * `CURLOPT_READFUNCTION` 从 C 回调，调用方累加需引用捕获（未验证支持）。
     */
    private int $sent = 0;

    private bool $closed = false;

    /**
     * @param list<array{head: string, path: string, size: int}> $parts
     * @param string $tail
     */
    public function __construct(array $parts, string $tail)
    {
        $this->parts = $parts;
        $this->tail = $tail;

        $total = 0;
        foreach ($parts as $part) {
            $total += \strlen($part['head']) + $part['size'];
            // 文件 part 的头部以 `\r\n\r\n` 结尾，文件内容之后还要补一个 `\r\n`；
            // 纯字段 part 的 `\r\n` 已算在 `head` 里。
            if ($part['path'] !== '') {
                $total += 2;
            }
        }
        $this->total = $total + \strlen($tail);
    }

    /** 请求体总字节数（精确值，不是估计） */
    public function length(): int
    {
        return $this->total;
    }

    /**
     * 已吐出字节数（上传进度的分子）。
     *
     * 与 `length()` 同口径（含 part 头与分隔符，非"文件字节"）；混用会让
     * 进度条在最后一个文件传完时停在 99%。
     */
    public function bytesSent(): int
    {
        return $this->sent;
    }

    /**
     * 取至多 `$max` 字节。返回 `''` 表示**已经全部吐完**（curl 的结束信号）。
     *
     * @throws \RuntimeException 文件读到一半读不出来（磁盘错误 / 文件被截短）
     */
    public function read(int $max): string
    {
        if ($max <= 0 || $this->closed) {
            return '';
        }

        $chunks = [];
        $need = $max;

        while ($need > 0) {
            if ($this->pending !== '') {
                $take = \substr($this->pending, 0, $need);
                $this->pending = \substr($this->pending, \strlen($take));
                $chunks[] = $take;
                $need -= \strlen($take);

                continue;
            }

            if ($this->left > 0) {
                $want = \min($need, $this->left);
                $chunk = \is_resource($this->handle) ? \fread($this->handle, $want) : false;
                if (!\is_string($chunk) || $chunk === '') {
                    throw new \RuntimeException(
                        'multipart：文件读到一半失败（第 ' . ($this->index - 1) . ' 个 part，还差 ' . $this->left . ' 字节）'
                        . ' —— 文件可能在上传过程中被删/被改小'
                    );
                }
                $this->left -= \strlen($chunk);
                $chunks[] = $chunk;
                $need -= \strlen($chunk);

                continue;
            }

            if (!$this->advance()) {
                break;
            }
        }

        $out = \implode('', $chunks);
        $this->sent += \strlen($out);

        return $out;
    }

    /** 关掉文件句柄（幂等）。curl 提前失败时也要调到。 */
    public function close(): void
    {
        if (\is_resource($this->handle)) {
            \fclose($this->handle);
        }
        $this->handle = null;
        $this->left = 0;
        $this->closed = true;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * 推进到下一个 part（或吐尾部）。
     *
     * @return bool `false` = 没有更多内容了
     *
     * @throws \RuntimeException 文件打不开
     */
    private function advance(): bool
    {
        // 上一个 part 是文件 part ⇒ 先补分隔用的 CRLF，下次调用再真正推进。
        //
        // ★ 这 2 字节属于请求体，必须吐：`length()` 已算进总长，少吐会让
        //   "声明长度 > 实际长度" ⇒ curl 一直等剩余字节直到超时，服务端也卡住。
        if (\is_resource($this->handle)) {
            \fclose($this->handle);
            $this->handle = null;
            $this->left = 0;
            $this->pending = "\r\n";

            return true;
        }

        $this->left = 0;

        if ($this->index < \count($this->parts)) {
            $part = $this->parts[$this->index];
            $this->index++;
            $this->pending = $part['head'];

            if ($part['path'] !== '') {
                $fh = @\fopen($part['path'], 'rb');
                if ($fh === false) {
                    throw new \RuntimeException('multipart：打不开文件 ' . $part['path']);
                }
                $this->handle = $fh;
                $this->left = $part['size'];
            }

            return true;
        }

        if ($this->tail !== '') {
            $this->pending = $this->tail;
            $this->tail = '';

            return true;
        }

        return false;
    }
}
