<?php
declare(strict_types=1);

namespace Drift\Logging;

use Drift\Errors;

/**
 * 有界环形缓冲 —— devtools / Fatal dump 的默认 sink（`12` §4.4）。
 *
 * ★ **覆盖写，O(1)**：容量固定 2000，写满丢最旧。日志是热路径，
 *   `array_shift()` 会让每条都 O(n) 搬移，这里用 `%` 定位整段覆盖。
 * ★ **存的是 `LogEntry` 不是字符串**：devtools 要按分类/级别过滤、
 *   要按 `%T` 显示墙钟 —— 先格式化成字符串就都做不了了。
 *   这也是 `12` §4.7.5 的"格式自由的兜底"。
 */
final class RingSink implements LogSink
{
    /**
     * 槽位表，空槽为 null，按写入序。
     *
     * ★ **不预分配**（`array_fill` / `array_pad` 都不行）：AOT 下属性 `$buffer`
     *   的元素类型会被定成 `php::Array`，而这两个构造函数的返回被推成
     *   `list<Int>` ⇒ C2440。与 `Errors::$ring` 同形（按需增长的 PHP 数组，
     *   那个 AOT 已编过）—— `recent()` 里再用 `% capacity` 做环绕寻址。
     *
     * @var array<int, LogEntry|null>
     */
    private array $buffer = [];

    private int $head = 0;
    private int $count = 0;

    public function __construct(private readonly int $capacity = 2000)
    {
        if ($capacity < 1) {
            Errors::report('RingSink 容量必须 ≥ 1（' . $capacity . '）');
        }
    }

    public function write(LogEntry $entry): void
    {
        $this->buffer[$this->head] = $entry;
        // ★ AOT 坑（2026-10-24 实测）：`($this->head + 1) % $this->capacity`
        //   这类算术的**结果类型**被推成 `php::Variant`，赋给 `php::Int` 的
        //   属性 ⇒ C2440。绕法是让中间量显式走 `Int`：先 `intdiv()` 得 Int，
        //   再减去倍数 —— 两步都产 Int，赋值就不需要转换了。
        $this->head = self::wrap($this->head + 1, $this->capacity);
        if ($this->count < $this->capacity) {
            $this->count++;
        }
    }

    /**
     * 时间正序的最近 N 条（最旧在前）。
     *
     * @return list<LogEntry>
     */
    public function recent(): array
    {
        $out = [];
        $start = self::wrap($this->head - $this->count, $this->capacity);
        for ($i = 0; $i < $this->count; $i++) {
            $out[] = $this->buffer[self::wrap($start + $i, $this->capacity)];
        }

        return $out;
    }

    /** `$n mod $m`，恒在 `[0, $m)` —— 负数也要绕对（`head - count` 会是负的） */
    private static function wrap(int $n, int $m): int
    {
        // ★ `intdiv` 向**零**取整 ⇒ `intdiv(-1, 3) = 0`，`-1 - 0 = -1` 还是负的。
        //   先加一个够大的倍数把符号抬正，再取模。
        $q = intdiv($n, $m);
        $r = $n - $q * $m;

        return $r < 0 ? $r + $m : $r;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function clear(): void
    {
        $this->buffer = [];
        $this->head = 0;
        $this->count = 0;
    }

    /** 内存环，无缓冲可落 */
    public function flush(): void
    {
    }

    /** 内存环，无资源可释放 */
    public function close(): void
    {
    }
}
