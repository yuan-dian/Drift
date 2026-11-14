<?php
declare(strict_types=1);

namespace Drift\Logging;

use Drift\Errors;

/**
 * ① 层：pattern 字符串 ⇒ 文本（`12` §4.7.1）。
 *
 * ★ **必须在 `compile()` 时解析，不能在 `format()` 里解析**：spdlog 的
 *   `set_pattern()` 正是把模式预编译成内部表示 —— 日志是热路径，
 *   每条都跑一遍 tokenizer 是把成本放错位置。`format()` 只做拼接。
 * ★ **未知占位符 ⇒ Fatal**（不是忽略）：G2「绝不静默」。写错 pattern
 *   就该响，而不是静默输出一个缺字段的行。
 * ★ 只做单字符 8 个占位符，**不做 `%{time yyyyMMdd}`**：带参数的 tokenizer
 *   要 100+ 行，而 Drift 的时间需求只有"时分秒"；需要别的格式写 `LogFormatter`。
 * ★ **每 sink 一个 formatter**（学 spdlog）：文件要时间戳、控制台不要 —— 这是真实差异。
 *
 * ★ 不做 spdlog 的 `add_flag` 注册机制：PHP 里自定义 formatter 就是一个类，
 *   不需要注册表 —— 多一层就多一个失败点。
  * @api
*/
final class PatternFormatter implements LogFormatter
{
    /** 片段类型：字面量 */
    private const int LITERAL = 0;
    /** 片段类型：占位符 */
    private const int PLACEHOLDER = 1;

    /**
     * 已编译片段。
     *
     * @var list<array{0: int, 1: string}> `self::LITERAL` ⇒ 字面量文本；
     *                                     `self::PLACEHOLDER` ⇒ 占位符名（单字符）
     */
    private readonly array $parts;

    private function __construct(array $parts)
    {
        $this->parts = $parts;
    }

    /**
     * 一次解析。失败即中止（不静默产出缺字段的行）。
     */
    public static function compile(string $pattern): self
    {
        $parts = [];
        $literal = [];
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            if ($ch !== '%') {
                $literal[] = $ch;
                continue;
            }
            if ($i + 1 >= $len) {
                Errors::report('日志 pattern 以 % 结尾（' . $pattern . '）');
            }
            $next = $pattern[$i + 1];
            if ($next === '%') {
                $literal[] = '%';
                $i++;
                continue;
            }
            if (str_contains('Ttlcmfs', $next) === false) {
                Errors::report('日志 pattern 未知占位符 %' . $next . '（' . $pattern . '）');
            }
            if ($literal !== []) {
                $parts[] = [self::LITERAL, implode('', $literal)];
                $literal = [];
            }
            $parts[] = [self::PLACEHOLDER, $next];
            $i++;
        }
        if ($literal !== []) {
            $parts[] = [self::LITERAL, implode('', $literal)];
        }

        return new self($parts);
    }

    public function format(LogEntry $entry): string
    {
        $out = [];
        foreach ($this->parts as [$kind, $text]) {
            $out[] = $kind === self::LITERAL
                ? $text
                : self::placeholder($text, $entry);
        }

        return implode('', $out);
    }

    private static function placeholder(string $name, LogEntry $entry): string
    {
        switch ($name) {
            case 'T':
                // 墙钟是 entry 构造时捕获的（见 `LogEntry` 类注释）——
                // 这里只做一次除法，绝不碰 `date()`。
                $sec = intdiv($entry->wallMs, 1000);

                return sprintf(
                    '%02d:%02d:%02d',
                    intdiv($sec, 3600) % 24,
                    intdiv($sec, 60) % 60,
                    $sec % 60
                );
            case 't':
                return (string) intdiv($entry->monotonicNs - Log::startNs(), 1_000_000);
            case 'l':
                return Log::levelName($entry->level);
            case 'c':
                return $entry->category;
            case 'm':
                return $entry->message;
            case 'f':
                return self::fields($entry->fields);
            case 's':
                return (string) $entry->seq;
        }

        return '';
    }

    /** 字段串 `k=v k=v`，按插入序 */
    private static function fields(array $fields): string
    {
        if ($fields === []) {
            return '';
        }
        $parts = [];
        foreach ($fields as $k => $v) {
            $parts[] = $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
        }

        return implode(' ', $parts);
    }
}
