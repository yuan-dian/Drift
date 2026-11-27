<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

/**
 * 终端表格：一条表头 + N 条数据行，按**列规格**排版。
 *
 * ## 为什么是列规格，不是一个"会画表的类"
 *
 * 成熟 GUI 与调试器里这张表都是这个形状：Tracy 用「列定义数组 + 行数组」，Chrome
 * DevTools 的 `DataGrid` 是「列描述 + 单元格渲染器」，`ps` / `top` / lldb 的 `bt`
 * 是固定列宽的纯文本。共同点是**列是显式声明的，行只是数据**。反过来（每张表自己
 * `sprintf` 一遍）的代价本项目尝过：`debug-report.php` 五张表各写一遍表头 + 补空格 +
 * 循环，于是表头与数据列的宽度只能靠人对齐，对错了没人发现。
 *
 * 边界划在这里：**只管一条表头 + N 条数据行**。分组小标题、"还有 N 条没列出"这类
 * 提示由调用方在表与表之间输出 —— 把它们塞进来，这个类就会长成一个配置 DSL，
 * 而真正的排版规则只有二十行。
 *
 * ## 两种列宽
 *
 * - `w > 0`：定宽。超出的单元格按**显示列宽**截断并补 `…`（见 {@see Fit::clip}）。
 *   用于"我知道这一列最多几个字"的数字列与短标签列。
 * - `w = 0`（或不写）：自适应到**该列最宽的那一格**，不截断。用于自由文本列
 *   （调用点、路径、明细）：截掉它们等于把"该改哪一行"删了，而这一列正是读者要的。
 *
 * ## 数字列右对齐
 *
 * `r => true`。`12` 与 `3` 左对齐时个位错开，一列数字没法竖着扫。表头跟着数据走
 * 同一侧 —— 表头左对齐、数据右对齐的话，读者第一眼会认错列。
 *
 * @internal 证据层排版；`\Drift\DevTools\View\Report` 是当前唯一调用方
 */
final class Table
{
    /**
     * @param list<array{t?: string, w?: int, r?: bool}> $cols 列规格：标题 / 列宽（0=自适应）/ 右对齐
     * @param list<list<string>>                         $rows 每行按列顺序给字符串（数字由调用方格式化）
     * @param string                                     $indent 行首缩进
     *
     * @return list<string> 表头（若有标题）+ 数据行；行尾不留空格
     */
    public static function render(array $cols, array $rows, string $indent = '  '): array
    {
        $widths = self::widths($cols, $rows);
        $lines = [];

        $header = self::cells($cols, $widths, \array_map(
            static fn(array $c): string => (string) ($c['t'] ?? ''),
            $cols,
        ));
        if ($header !== '') {
            $lines[] = $indent . $header;
        }

        foreach ($rows as $row) {
            $lines[] = $indent . self::cells($cols, $widths, \array_map(
                static fn($v): string => (string) $v,
                \array_values($row),
            ));
        }

        return $lines;
    }

    /**
     * 解析每列的实际宽度：定宽用声明值，自适应取"标题与所有单元格里最宽的那个"。
     *
     * @param list<array{t?: string, w?: int, r?: bool}> $cols
     * @param list<list<string>>                         $rows
     *
     * @return list<int>
     */
    private static function widths(array $cols, array $rows): array
    {
        $widths = [];
        foreach ($cols as $i => $col) {
            $fixed = (int) ($col['w'] ?? 0);
            if ($fixed > 0) {
                $widths[$i] = $fixed;
                continue;
            }
            $widest = Fit::width((string) ($col['t'] ?? ''));
            foreach ($rows as $row) {
                $widest = \max($widest, Fit::width((string) ($row[$i] ?? '')));
            }
            $widths[$i] = $widest;
        }

        return $widths;
    }

    /**
     * 一行：逐格截断 + 补齐，列间一个空格。
     *
     * 宽度为 0 的列（整列都是空的）直接不占位 —— 否则会留下一串连续空格，读者会以为
     * 那里真的有内容。超出的格子原样追加，**不静默丢掉**：列声明写少了是调用方的错，
     * 让它显形比让它消失好。
     *
     * @param list<array{t?: string, w?: int, r?: bool}> $cols
     * @param list<int>                                  $widths
     * @param list<string>                               $cells
     */
    private static function cells(array $cols, array $widths, array $cells): string
    {
        $parts = [];
        foreach ($cols as $i => $col) {
            $width = $widths[$i];
            $text = $cells[$i] ?? '';
            $fixed = (int) ($col['w'] ?? 0) > 0;
            if ($fixed) {
                $text = Fit::clip($text, $width);
            }
            if ($width <= 0) {
                if ($text !== '') {
                    $parts[] = $text;
                }
                continue;
            }
            $parts[] = ($col['r'] ?? false) === true
                ? Fit::padStart($text, $width)
                : Fit::pad($text, $width);
        }

        foreach (\array_slice($cells, \count($cols)) as $extra) {
            $parts[] = $extra;
        }

        return \rtrim(\implode(' ', $parts));
    }
}
