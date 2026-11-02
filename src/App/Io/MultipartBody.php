<?php
declare(strict_types=1);

namespace Drift\App\Io;

/**
 * `multipart/form-data` 请求体的**流式**生产者（2026-11-02）。
 *
 * ## 为什么是流式，而不是"拼成一个字符串"
 *
 * 第一版把整个表单拼进一个 `string`，三个问题：
 *
 * 1. **内存峰值 = 文件大小 × 2~3**：`file_get_contents()` 一份，`.=` 扩容再一份。
 *    64 MB 的图能吃到 150 MB 以上 —— 桌面应用里这不可接受。
 * 2. **`.=` 在循环里累加**本身是 lint 挡的写法（§2.4 纪律 2，`str-concat-loop`）。
 * 3. **严格意义的零拷贝**（`sendfile()` / `TransmitFile()`）**用不了**：它只能原样
 *    发一个 fd，而表单上传必须在文件前后拼 HTTP 头和 boundary ⇒ 拼接动作本身
 *    就要求数据经过用户态。所以能做到的上限是**流式**，不是零拷贝。
 *
 * ## 形状
 *
 * `read()` 是一个**状态机**：吐小片段（part 头）→ 吐文件内容（按块读）→ 推进到
 * 下一个 part → 最后吐尾部。curl 通过 `CURLOPT_READFUNCTION` 按需调它，
 * 每次最多要 `CURL_MAX_READ_SIZE`（默认 64 KB）字节。
 *
 * ★ **总长度先算不读**（各 part 头长度 + `filesize()` + 尾部）⇒ `CURLOPT_POSTFIELDSIZE`
 *   拿得到精确值。这有两个好处：进度分母不用猜；请求不会退化成 `Transfer-Encoding:
 *   chunked`（有些老服务端 / 网关不认 chunked 上传）。
 *
 * ★ **读失败要抛，不能返回 `''`**：`''` 在 `CURLOPT_READFUNCTION` 的语义里是
 *   "发完了"。一个被截断的表单，服务端往往报"缺少字段"，而真正的原因（磁盘读不出）
 *   离现场隔着两个系统。
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
            // 文件 part 的头部以 `\r\n\r\n` 结尾，**文件内容之后**还要补一个 `\r\n`；
            // 纯字段 part 的 `\r\n` 已经算在 `head` 里了。
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
            // ① 先把当前 part 的头部吐完
            if ($this->pending !== '') {
                $take = \substr($this->pending, 0, $need);
                $this->pending = \substr($this->pending, \strlen($take));
                $chunks[] = $take;
                $need -= \strlen($take);

                continue;
            }

            // ② 再吐当前文件的内容（按需读，不预读整份）
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

            // ③ 推进到下一个 part；推进不动 ⇒ 全部吐完
            if (!$this->advance()) {
                break;
            }
        }

        return \implode('', $chunks);
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
        // ① 上一个 part 是**文件** part ⇒ 先补分隔用的 CRLF，下次调用再推进。
        //
        // ★ 这 2 个字节不属于文件内容，但属于请求体：`length()` 把它算进了总长，
        //   `read()` 就**必须**吐它。少吐的后果不是"少 2 字节"，而是
        //   "声明长度 > 实际长度" —— curl 会一直等剩下的字节，直到超时；
        //   服务端则挂在那里等一个永远不来的 part 分隔符。
        if (\is_resource($this->handle)) {
            \fclose($this->handle);
            $this->handle = null;
            $this->left = 0;
            $this->pending = "\r\n";

            return true;
        }

        $this->left = 0;

        // ② 推进到下一个 part
        if ($this->index < \count($this->parts)) {
            $part = $this->parts[$this->index];
            $this->index++;
            $this->pending = $part['head'];

            if ($part['path'] !== '') {
                // ★ 用 `rb` 而不是 `r`：**在 PHP 里两者等价**（自 4.3 起 Windows 上也
                //   默认二进制模式，实测 `fopen('r')` 不会把 `\r\n` 翻成 `\n`），
                //   这里写 `b` 是为了把"这是二进制、别做任何翻译"写在代码上 ——
                //   同一个仓库里还有人会拿 C / Python 的直觉读这段。
                $fh = @\fopen($part['path'], 'rb');
                if ($fh === false) {
                    throw new \RuntimeException('multipart：打不开文件 ' . $part['path']);
                }
                $this->handle = $fh;
                $this->left = $part['size'];
            }

            return true;
        }

        // ③ 收尾
        if ($this->tail !== '') {
            $this->pending = $this->tail;
            $this->tail = '';

            return true;
        }

        return false;
    }
}
