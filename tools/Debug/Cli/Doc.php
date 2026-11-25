<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\Cli;

/**
 * 调试手册的机器视图：枚举事实由 Registry / schema 生成，语义散文留在文档里。
 *
 * 校验只回答两件事：块是不是这份声明生成的，散文有没有写出 schema 不认识的参数。
 * 不把 help 文本或手册反过来当声明源——那样漂移只会换个地方发生。
 */
final class Doc
{
    public const string START = '<!-- drift-cli-reference:start -->';
    public const string END = '<!-- drift-cli-reference:end -->';

    private const string PROSE_START = '## 手工工具';
    private const string PROSE_END = '## 环境与依赖脚本';

    /** 生成块正文（不含标记行）。 */
    public static function block(): string
    {
        $lines = [
            '下表由 `php tools/gen-cli-doc.php` 从出口注册表与参数 schema 生成，勿手改。',
            '',
            '### 调试出口',
            '',
            '| 动词 | 脚本 | 类别 | 摘要 | 特有参数 |',
            '|---|---|---|---|---|',
        ];

        $extras = Commands::extras();
        foreach (Registry::all() as $verb => $entry) {
            $words = \array_map(static fn(Opt $o): string => '`--' . $o->name . '`', $extras[$verb] ?? []);
            $lines[] = \sprintf(
                '| `%s` | `%s` | %s | %s | %s |',
                $verb,
                $entry->script,
                $entry->driver ? 'driver' : 'protocol',
                self::cell($entry->about),
                $words === [] ? '—' : \implode(' ', $words),
            );
        }

        $lines[] = '';
        $lines[] = '### 共用驱动词';
        $lines[] = '';
        $lines[] = '| 参数 | 类型 | 默认 | 说明 |';
        $lines[] = '|---|---|---|---|';
        foreach (OptionSet::driver() as $opt) {
            $aliases = \array_map(static fn(string $a): string => '`--' . $a . '`', $opt->aliases);
            $lines[] = \sprintf(
                '| `--%s`%s | %s | `%s` | %s |',
                $opt->name,
                $aliases === [] ? '' : '（别名 ' . \implode(' / ', $aliases) . '）',
                $opt->type,
                OptionSet::show($opt->default),
                self::cell($opt->help),
            );
        }

        $lines[] = '';
        $lines[] = '> 每个出口的完整参数以 `php bin/drift debug <动词> --help` 为准；两级 help 与这张表同源。';

        return \implode("\n", $lines);
    }

    /** @return list<string> 手册与声明之间的分歧，空数组表示闭合 */
    public static function problems(string $markdown): array
    {
        $problems = [];
        if (\substr_count($markdown, self::START) !== 1 || \substr_count($markdown, self::END) !== 1) {
            return ['生成块标记缺失或不唯一'];
        }

        $start = \strpos($markdown, self::START);
        $end = \strpos($markdown, self::END);
        if ($start === false || $end === false || $end <= $start) {
            return ['生成块标记顺序错误'];
        }
        $inner = \substr($markdown, $start + \strlen(self::START), $end - $start - \strlen(self::START));
        if ($inner !== "\n" . self::block() . "\n") {
            $problems[] = '生成块与 Registry / schema 不一致（跑 php tools/gen-cli-doc.php）';
        }

        $prose = self::prose($markdown);
        if ($prose === null) {
            $problems[] = '找不到「手工工具 / 环境与依赖脚本」散文段';

            return $problems;
        }
        $from = \strpos($markdown, self::PROSE_START);
        if ($start < $from || $end > \strpos($markdown, self::PROSE_END)) {
            $problems[] = '生成块不在「手工工具」段内';
        }

        $body = self::withoutBlock($prose);
        foreach (Registry::all() as $verb => $entry) {
            $mentioned = \preg_match('/\b' . \preg_quote($verb, '/') . '\b/u', $body) === 1
                || \str_contains($body, $entry->script);
            if (!$mentioned) {
                $problems[] = "手册没提到出口 {$verb}";
            }
        }

        $known = [];
        foreach (\array_keys(Registry::all()) as $verb) {
            if (!Registry::get($verb)->driver) {
                continue;
            }
            foreach (Commands::set($verb)->words() as $word) {
                $known[$word] = true;
            }
        }
        \preg_match_all('/--([A-Za-z][A-Za-z0-9-]*)/u', self::debugLines($body), $m);
        foreach (\array_unique($m[1]) as $word) {
            if (!isset($known[$word])) {
                $problems[] = "手册写了 schema 不认识的参数 --{$word}";
            }
        }

        return $problems;
    }

    /** 写回工具只负责替换 / 插入，不再实现第二份生成逻辑。 */
    public static function upsert(string $markdown): string
    {
        $rendered = self::START . "\n" . self::block() . "\n" . self::END;
        $start = \strpos($markdown, self::START);
        $end = \strpos($markdown, self::END);
        if ($start !== false && $end !== false && $end > $start) {
            return \substr($markdown, 0, $start) . $rendered . \substr($markdown, $end + \strlen(self::END));
        }

        $at = \strpos($markdown, self::PROSE_END);
        if ($at === false) {
            throw new \RuntimeException('找不到插入位置：' . self::PROSE_END);
        }

        return \rtrim(\substr($markdown, 0, $at)) . "\n\n" . $rendered . "\n\n" . \substr($markdown, $at);
    }

    /** 只把调试出口的行纳入参数闭合；同一张表里还有 gen-route-manifest 这类别的命令。 */
    private static function debugLines(string $body): string
    {
        $kept = [];
        foreach (\explode("\n", $body) as $line) {
            $isRow = \str_starts_with(\ltrim($line), '|');
            $isDebug = \preg_match('/^\|\s*`(?:composer\s+debug|composer\s+panel|php\s+tools\/debug-host\.php)/u', \ltrim($line)) === 1;
            if (!$isRow || $isDebug) {
                $kept[] = $line;
            }
        }

        return \implode("\n", $kept);
    }

    /** 散文段：生成块之外、两张手工表所在的范围。 */
    public static function prose(string $markdown): ?string
    {
        $from = \strpos($markdown, self::PROSE_START);
        $to = \strpos($markdown, self::PROSE_END);
        if ($from === false || $to === false || $to <= $from) {
            return null;
        }

        return \substr($markdown, $from, $to - $from);
    }

    public static function withoutBlock(string $text): string
    {
        $start = \strpos($text, self::START);
        $end = \strpos($text, self::END);
        if ($start === false || $end === false || $end <= $start) {
            return $text;
        }

        return \substr($text, 0, $start) . \substr($text, $end + \strlen(self::END));
    }

    private static function cell(string $text): string
    {
        return \str_replace('|', '\\|', $text);
    }
}
