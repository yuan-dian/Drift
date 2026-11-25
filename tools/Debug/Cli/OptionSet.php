<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\Cli;

/**
 * 一份选项声明，可被多个出口组合。
 *
 * 驱动词表只在这里出现一次；同名或别名重复声明直接失败——"同一个词两种意思"
 * 是这类工具最难查的分歧，宁可在启动时炸掉，也不让它跑出一份看着正常的报告。
 */
final class OptionSet
{
    /** 会按顺序展开成脚本步骤的选项。 */
    private const array ACTIONS = ['key', 'text', 'click', 'wheel', 'resolve'];

    /** @var array<string, Opt> */
    private array $options = [];

    /** @var array<string, string> 别名 => 正名 */
    private array $aliases = [];

    private function __construct(public readonly string $command)
    {
    }

    /** @param list<Opt> $extras 只放这个出口特有的选项 */
    public static function for(string $command, array $extras = []): self
    {
        $set = new self($command);
        foreach (self::driver() as $opt) {
            $set->add($opt);
        }
        $set->add(Opt::flag('json', '结构化输出'));
        $set->add(Opt::flag('remote', '用宿主进程跑，跨进程读同一份证据'));
        $set->add(Opt::flag('bodies', '采集请求 / 响应正文（脱敏，默认关）'));
        $set->add(Opt::flag('help', '打印本出口的选项清单'));
        foreach ($extras as $opt) {
            $set->add($opt);
        }

        return $set;
    }

    /** 11 个驱动词。宽高统一叫 view-width；短名 width 是别名，排版宽度另叫 text-width。 */
    public static function driver(): array
    {
        return [
            Opt::str('page', null, '页面路由 id（与位置参数同义）'),
            Opt::int('frames', 1, 1, null, '驱动几帧'),
            Opt::int('rows', 0, 0, null, '取数替身参数'),
            Opt::int('hold', 1, 1, null, '动作后再推几帧'),
            Opt::int('at', 0, 1, null, '只重放到第 N 帧（查询侧的选帧，不改脚本）'),
            Opt::float('view-width', 800.0, 1.0, null, '视口宽度', ['width']),
            Opt::float('height', 600.0, 1.0, null, '视口高度'),
            Opt::append('key', '按键，可重复'),
            Opt::append('text', '输入文本，可重复'),
            Opt::append('click', '点击 x,y[,键]，可重复'),
            Opt::append('wheel', '滚轮 x,y,格[,h]，可重复'),
            Opt::flag('resolve', '结算一次异步取数'),
        ];
    }

    public function add(Opt $opt): void
    {
        if (isset($this->options[$opt->name]) || isset($this->aliases[$opt->name])) {
            throw new \LogicException("选项 {$opt->name} 在本出口重复声明");
        }
        foreach ($opt->aliases as $alias) {
            if (isset($this->options[$alias]) || isset($this->aliases[$alias])) {
                throw new \LogicException("别名 --{$alias} 与已有选项冲突");
            }
            $this->aliases[$alias] = $opt->name;
        }
        $this->options[$opt->name] = $opt;
    }

    /** @param list<string> $argv 含脚本名 */
    public function parse(array $argv): Parsed
    {
        $values = [];
        $given = [];
        foreach ($this->options as $name => $opt) {
            $values[$name] = $opt->default;
            $given[$name] = false;
        }

        $steps = [];
        $target = null;
        foreach (\array_slice($argv, 1) as $arg) {
            if (!\str_starts_with($arg, '--')) {
                $target = $arg;
                continue;
            }

            $body = \substr($arg, 2);
            $eq = \strpos($body, '=');
            $name = $eq === false ? $body : \substr($body, 0, $eq);
            $raw = $eq === false ? null : \substr($body, $eq + 1);
            $opt = $this->resolve($name);

            if ($opt->type === Opt::FLAG) {
                if ($raw !== null) {
                    throw new CliError("--{$name} 是开关，不接值；写法：--{$name}");
                }
                $values[$opt->name] = true;
                $given[$opt->name] = true;
                continue;
            }
            if ($raw === null) {
                throw new CliError("--{$name} 需要一个值；写法：--{$name}=…");
            }

            $value = $this->cast($opt, $name, $raw);
            $given[$opt->name] = true;
            if ($opt->type === Opt::APPEND) {
                $values[$opt->name][] = $value;
                if (\in_array($opt->name, self::ACTIONS, true)) {
                    $steps[] = [$opt->name, $value];
                }
            } else {
                $values[$opt->name] = $value;
            }
        }

        $parsed = new Parsed($this->command, $this->options, $values, $given, $steps, $target);
        $this->guardSources($parsed);

        return $parsed;
    }

    private function resolve(string $name): Opt
    {
        $canonical = $this->aliases[$name] ?? $name;
        $opt = $this->options[$canonical] ?? null;
        if ($opt === null) {
            throw new CliError(
                "未知参数：--{$name}；debug-{$this->command} 可用："
                . \implode(' ', \array_map(static fn(string $n): string => "--{$n}", $this->names())) . "\n"
                . "（--help 看每个参数的含义）",
            );
        }

        return $opt;
    }

    /** @return list<string> */
    private function names(): array
    {
        $names = \array_keys($this->options);
        \sort($names);

        return $names;
    }

    /** @return list<string> 规范名 + 别名，给文档闭合校验用 */
    public function words(): array
    {
        return \array_values(\array_unique([...\array_keys($this->options), ...\array_keys($this->aliases)]));
    }

    private function cast(Opt $opt, string $name, string $raw): mixed
    {
        if ($opt->type === Opt::STRING || $opt->type === Opt::APPEND) {
            return $raw;
        }
        if ($opt->type === Opt::INT && \preg_match('/^-?\d+$/', $raw) !== 1) {
            throw new CliError("--{$name} 需要整数，实际是：{$raw}");
        }
        if ($opt->type === Opt::FLOAT && !\is_numeric($raw)) {
            throw new CliError("--{$name} 需要数字，实际是：{$raw}");
        }

        $value = $opt->type === Opt::INT ? (int) $raw : (float) $raw;
        // 越界是归一化，不是错误：--rows=-5 与不给 rows 本来就该是同一页。
        if ($opt->min !== null) {
            $value = \max($opt->min, $value);
        }
        if ($opt->max !== null) {
            $value = \min($opt->max, $value);
        }

        return $value;
    }

    /** `--from` 不许同时给驱动源；`--at` 例外，它选的是包里的帧。 */
    private function guardSources(Parsed $parsed): void
    {
        if (!$parsed->has('from')) {
            return;
        }
        if ($parsed->has('page') || $parsed->target() !== null || $parsed->has('frames') || $parsed->steps() !== []) {
            throw new CliError('--from 与 --page / 目标 / --frames / 驱动参数互斥：包里已经有完整的驱动记录');
        }
    }

    public function help(): string
    {
        $lines = ["用法：debug-{$this->command} [<AppClass|路由id>] [选项]", '', '选项：'];
        foreach ($this->options as $opt) {
            $default = $opt->type === Opt::FLAG ? 'false' : self::show($opt->default);
            $alias = $opt->aliases === [] ? '' : ' (别名：--' . \implode(' --', $opt->aliases) . ')';
            $lines[] = \sprintf('  --%-14s %-7s 默认 %-8s %s%s', $opt->name, $opt->type, $default, $opt->help, $alias);
        }

        return \implode("\n", $lines) . "\n";
    }

    public static function show(mixed $value): string
    {
        if (\is_array($value)) {
            return '[]';
        }
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
