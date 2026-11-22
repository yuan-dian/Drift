<?php
declare(strict_types=1);

namespace Drift\App\Io;

/**
 * 收 curl 响应头：见状态行就清空，留下最后一段（重定向会重来一段）。
 *
 * 从 `Http::request()` 内联闭包抽成对象，是为了让 prepare() 与 finish()
 * 之间能通过值对象交接头，而不用按引用传递数组（AOT 下按引用传数组有风险）。
 *
 * @internal
 */
final class HeaderCollector
{
    /** @var array<string, string> 头名已小写；同名多值按 ", " 合并 */
    private array $seen = [];

    /**
     * CURLOPT_HEADERFUNCTION 的回调体，返回消费掉的字节数。
     *
     * @internal
     */
    public function ingest(string $raw): int
    {
        $line = \rtrim($raw, "\r\n");
        if ($line === '') {
            return \strlen($raw);
        }
        if (\str_starts_with($line, 'HTTP/')) {
            // 重定向会重来一段头：见到新状态行就清空，finish 读到的总是最后一段。
            $this->seen = [];

            return \strlen($raw);
        }

        $colon = \strpos($line, ':');
        if ($colon === false) {
            return \strlen($raw);
        }
        $name = \strtolower(\trim(\substr($line, 0, $colon)));
        $value = \trim(\substr($line, $colon + 1));
        $this->seen[$name] = isset($this->seen[$name]) ? $this->seen[$name] . ', ' . $value : $value;

        return \strlen($raw);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->seen;
    }
}
