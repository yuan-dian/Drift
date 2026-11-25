<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 属性视图：把 `props(node)` 的返回压成可读文本。
 *
 * ## 它不采集、不反射、不判断
 *
 * 输入只有查询面的返回；编码、截断、能力降级都在查询层做完。视图若自己
 * 再"美化"一个值，两个消费者看到同一对象就会得出不同结论。
 *
 * ## 两条纪律
 *
 * 1. **纯函数**：同样的 answer 必须给出逐字节相同的行；不读时钟、不读环境。
 * 2. **自报裁剪**：`omitted` / `cut` / `cycles` 必须写出来。读者把"我没看到"
 *    当成"不存在"，而一列文本看不出这一点。
 *
 * ★ 属性是 dev 即时内省，不是证据（A55）：没有快照窗口、不写包。头部明说
 *   这是"第 N 帧现读"的值，避免被当成可回放的历史事实。
 *
 * @internal 内省视图；`tools/debug-props.php` 是它唯一的调用方
 */
final class Props
{
    /**
     * 每个对象/列表最多展开几项明细。
     *
     * ★ 查询面已经把每层裁到 120 项，但那是为了"证据够用"；投影面要给的是
     *   "人能读完"。10000 行的表格数据按 120 项展开就是 480 行，简报和终端
     *   都会被它撑爆。两层预算口径不同，不能共用同一个数。
     */
    private const int DETAIL_LIMIT = 12;

    /** @param array<string, mixed> $answer `props()` 的返回 */
    public static function lines(array $answer): array
    {
        $node = (int) ($answer['node'] ?? 0);
        if (($answer['found'] ?? false) !== true) {
            return [
                \sprintf(
                    '节点 #%d 的属性查不到：%s',
                    $node,
                    (string) ($answer['reason'] ?? 'unknown'),
                ),
            ];
        }

        $lines = [
            \sprintf(
                '节点 #%d  %s（%s）%s · 第 %d 帧现读（即时内省，不进证据包）',
                $node,
                (string) ($answer['widget'] ?? '?'),
                (string) ($answer['element'] ?? '?'),
                ($answer['state'] ?? null) === null ? '' : ' / State=' . $answer['state'],
                (int) ($answer['liveFrame'] ?? 0),
            ),
        ];

        $fields = $answer['fields'] ?? [];
        if ($fields === []) {
            $lines[] = '  （没有可枚举的字段；这不等于控件没有配置）';
        }
        foreach ($fields as $field) {
            $lines[] = \sprintf(
                '  %-24s %s',
                (string) ($field['owner'] ?? '?') . '::' . (string) ($field['name'] ?? '?'),
                self::value($field['value'] ?? [], 0),
            );
            // 上面的定宽会把嵌套内容压成一行；对象/列表的明细另起缩进行。
            $detail = self::details($field['value'] ?? [], 1);
            foreach ($detail as $line) {
                $lines[] = '      ' . $line;
            }
        }

        $lines[] = \sprintf(
            '字段 %d 项，省略 %d；深度/预算截断 %d 处；循环引用 %d 处；读取 %.3fms',
            \count($fields),
            (int) ($answer['omitted'] ?? 0),
            (int) ($answer['cut'] ?? 0),
            (int) ($answer['cycles'] ?? 0),
            (float) ($answer['costMs'] ?? 0.0),
        );
        $lines[] = ($answer['redact'] ?? true) === true
            ? \sprintf('凭证脱敏 %d 处', (int) ($answer['redacted'] ?? 0))
            : '未脱敏（含明文）';

        return $lines;
    }

    /** @param array<string, mixed> $value */
    private static function value(array $value, int $depth): string
    {
        $kind = (string) ($value['kind'] ?? 'unknown');
        $raw = $value['value'] ?? null;

        return match ($kind) {
            'scalar' => \is_bool($raw) ? ($raw ? 'true' : 'false') : ($raw === null ? 'null' : (string) $raw),
            'string' => '"' . (string) $raw . '"' . (($value['truncated'] ?? false) === true
                ? ' …（原长 ' . (int) ($value['length'] ?? 0) . ' 字节）' : ''),
            'list', 'map' => \sprintf('%s（%d 项，列 %d）', $kind, (int) ($value['count'] ?? 0), \count($value['items'] ?? [])),
            'obj' => (string) ($value['class'] ?? '?') . '（' . \count($value['props'] ?? []) . ' 字段）',
            'closure' => 'Closure @ ' . (string) ($value['at'] ?? '?'),
            'resource' => 'resource(' . (string) ($value['type'] ?? '?') . ')',
            'ref' => '↩ 对象 #' . (int) ($value['to'] ?? 0),
            'cut' => '… ' . (string) ($value['class'] ?? '?') . '（预算截断）',
            'unreadable' => '?（读不到）',
            default => $kind,
        };
    }

    /**
     * @param array<string, mixed> $value
     * @return list<string>
     */
    private static function details(array $value, int $depth): array
    {
        if ($depth > 3) {
            return [];
        }
        $kind = (string) ($value['kind'] ?? '');
        if ($kind === 'obj') {
            $out = [];
            $properties = $value['props'] ?? [];
            $shown = \array_slice($properties, 0, self::DETAIL_LIMIT);
            foreach ($shown as $property) {
                $out[] = (string) ($property['name'] ?? '?') . ' = ' . self::value($property['value'] ?? [], $depth);
                foreach (self::details($property['value'] ?? [], $depth + 1) as $line) {
                    $out[] = '  ' . $line;
                }
            }
            $hidden = \count($properties) - \count($shown) + (int) ($value['omitted'] ?? 0);
            if ($hidden > 0) {
                $out[] = "… 明细另省略 {$hidden} 字段（完整值可用 --json 取）";
            }

            return $out;
        }
        if ($kind === 'list' || $kind === 'map') {
            $out = [];
            $items = $value['items'] ?? [];
            $shown = \array_slice($items, 0, self::DETAIL_LIMIT);
            foreach ($shown as $item) {
                $out[] = '[' . (string) ($item['key'] ?? '') . '] = ' . self::value($item['value'] ?? [], $depth);
                foreach (self::details($item['value'] ?? [], $depth + 1) as $line) {
                    $out[] = '  ' . $line;
                }
            }
            $hidden = \count($items) - \count($shown) + (int) ($value['omitted'] ?? 0);
            if ($hidden > 0) {
                $out[] = "… 明细另省略 {$hidden} 项（完整值可用 --json 取）";
            }

            return $out;
        }

        return [];
    }
}
