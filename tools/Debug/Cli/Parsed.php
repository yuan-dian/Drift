<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\Cli;

use Drift\Dev\Debug\Plan;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Script;

/** 解析结果。出口只读这里，不再回头看 argv。 */
final class Parsed
{
    /**
     * @param array<string, Opt> $options
     * @param array<string, mixed> $values
     * @param array<string, bool> $given
     * @param list<array{0: string, 1: string}> $steps
     */
    public function __construct(
        private readonly string $command,
        private readonly array $options,
        private readonly array $values,
        private readonly array $given,
        private readonly array $steps,
        private readonly ?string $target,
    ) {
    }

    /** 参数是否**显式给过**（与"取到了默认值"分开：--from 的互斥靠它）。 */
    public function has(string $name): bool
    {
        return $this->given[$name] ?? false;
    }

    public function bool(string $name): bool
    {
        return (bool) ($this->values[$name] ?? false);
    }

    public function int(string $name): int
    {
        return (int) ($this->values[$name] ?? 0);
    }

    public function float(string $name): float
    {
        return (float) ($this->values[$name] ?? 0.0);
    }

    public function str(string $name): ?string
    {
        $value = $this->values[$name] ?? null;

        return $value === null ? null : (string) $value;
    }

    /** @return list<string> */
    public function list(string $name): array
    {
        $value = $this->values[$name] ?? [];

        return \is_array($value) ? \array_values(\array_map('strval', $value)) : [];
    }

    /**
     * 可逗号分隔的列表：`--rule=a,b` 与 `--rule=a --rule=b` 同义。
     *
     * 两种写法都要支持的理由不同：逗号便于从文档里复制一行，重复开关便于
     * 脚本拼接（拼的时候不必知道前一个值有没有逗号）。解析只做一次、
     * 只在 `Parsed` 里做 —— 出口各写一遍的话，总有一个只认其中一种写法，
     * 而读者看到的是"这条规则没触发"。
     *
     * @return list<string>
     */
    public function strings(string $name): array
    {
        $out = [];
        foreach ($this->list($name) as $one) {
            foreach (\explode(',', $one) as $piece) {
                $piece = \trim($piece);
                if ($piece !== '') {
                    $out[] = $piece;
                }
            }
        }

        return \array_values(\array_unique($out));
    }

    public function at(): ?int
    {
        return $this->has('at') ? $this->int('at') : null;
    }

    public function target(): ?string
    {
        return $this->target;
    }

    public function label(): ?string
    {
        $from = $this->str('from');

        return $from !== null ? \basename($from) : ($this->str('page') ?? $this->target);
    }

    /** @return list<array{0: string, 1: string}> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return array<string, int> */
    public function meta(): array
    {
        $rows = $this->int('rows');
        $meta = $rows > 0 ? ['rows' => $rows] : [];
        if ($this->has('page')) {
            $meta['page'] = 1;
        }

        return $meta;
    }

    /** CLI 的采集预算唯一组装点：正文默认不抓。 */
    public function recordOptions(): RecordOptions
    {
        // 三个可选域都是"默认关、显式要"：报文是隐私与体积，绘制是每帧多记一个节点集合，
        // 异步是后端 tap 的记账成本。网络骨架默认开，不在 CLI 暴露开关。预算不进脚本，不改指纹。
        return RecordOptions::of(
            bodies: $this->bool('bodies'),
            paint: $this->bool('paint'),
            async: $this->bool('async'),
        );
    }

    /**
     * 唯一的"驱动参数 → 脚本"路径。十个出口曾各写一遍这段组装，
     * 其中任一段漏了 meta 都不会报错，只会让指纹悄悄分叉。
     */
    public function script(): Script
    {
        $label = $this->label();
        if ($label === null) {
            throw new CliError("debug-{$this->command} 要一个页面：给位置参数或 --page=…");
        }

        return Plan::build(
            $label,
            $this->float('view-width'),
            $this->float('height'),
            $this->meta(),
            $this->int('frames'),
            $this->int('hold'),
            $this->steps(),
        );
    }
}
