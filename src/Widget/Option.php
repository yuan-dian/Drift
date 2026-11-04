<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 一个可选项：**显示名（`label`） + 值（`value`）**。
 *
 * `value` 是稳定的身份，`onChanged()` 回调收到的就是它；`label` 只是给人看的字。
 * 列表一旦会被重排 / 过滤 / 分页，下标就不是身份了，所以两者必须分开。
 *
 * 纯值对象，`public readonly` 即终态：没有 `mutate()`，也没有链式设值。
 *
 * @api
 */
final class Option
{
    public function __construct(
        /** 给人看的显示名（下拉里那一行字、触发按钮上显示的字） */
        public readonly string $label,
        /** 稳定的身份：`onChanged()` 回调收到的就是它 */
        public readonly string $value,
    ) {
    }

    public static function make(string $label, string $value): self
    {
        return new self($label, $value);
    }

    /** 显示名与值相同（`Option::of('北京')` = `make('北京', '北京')`） */
    public static function of(string $value): self
    {
        return new self($value, $value);
    }

    /**
     * 把"选项表的两种书写形态"统一成 `list<Option>`。
     *
     * ```php
     * Option::normalize([Option::make('北京市', '110000')]);  // 形态 1：显式对象
     * Option::normalize(['110000' => '北京市']);              // 形态 2：映射（键 = 值）
     * ```
     *
     * `Select::make()` / `ComboBox::make()` 的入参都先过这里，应用拿到
     * `id => name` 这种形状不必再手写 `array_map`。
     *
     * 两条必须知道的坑（都是 PHP 的，不是本方法的）：
     *
     * 1. **数字键会被 PHP 转成 int**：`['110000' => '北京市']` 的键实际是
     *    `int 110000`，这里必须 `(string)` 收口，否则 `Option::$value` 严格类型下
     *    传 int 是 TypeError（报错指向 `Option::make()`，对不上应用写的）。
     * 2. **`[0 => 'x']` 与 `['x']` 在 PHP 里是同一个数组** ⇒ 值 0 与标签列表
     *    在语言层面无法区分。所以 list 形态**只收 `Option`**，裸值一律 Fatal。
     *    ★ 不猜的理由：猜错 ⇒ "选中了错的人"且全程无报错，比启动即崩贵得多。
     *
     * 映射形态下重复键后者覆盖前者，这是 PHP 数组本身的规则。
     *
     * @param list<Option>|array<array-key, string> $raw
     * @return list<Option>
     * @api
     */
    public static function normalize(array $raw): array
    {
        if ($raw === []) {
            return [];
        }

        // 判断是否为 PHP 的 list（连续整数键）。
        $isList = true;
        $expect = 0;
        foreach ($raw as $key => $unused) {
            if ($key !== $expect) {
                $isList = false;
                break;
            }
            $expect++;
        }

        $out = [];

        if ($isList) {
            foreach ($raw as $index => $value) {
                if ($value instanceof self) {
                    $out[] = $value;
                    continue;
                }

                // `Errors::report()` 默认级别是 FATAL ⇒ 下面这句不会返回；
                // `continue` 只是级别被调低时的安全落点（不猜，就不产出）。
                Errors::report(sprintf(
                    'Option::normalize() 的 list 形态只收 Option，第 %d 个元素是裸值。'
                        . "\n" . '要"值 = 显示名"写 `Option::of(\'北京\')`；'
                        . "\n" . '要"键 = 值、值 = 显示名"写成映射 `[\'110000\' => \'北京市\']`。',
                    $index,
                ));
            }

            return $out;
        }

        foreach ($raw as $key => $value) {
            $identity = (string) $key; // ★ 数字键已被 PHP 转成 int，这里转回 string

            if ($identity === '') {
                Errors::report(
                    'Option::normalize() 收到空字符串键：`value(\'\')` 是"未选"的哨兵，'
                        . "\n" . '不能同时当一个真选项（否则"没选"与"选了空"分不开）。',
                );
                continue;
            }

            if (!\is_string($value)) {
                Errors::report(sprintf(
                    'Option::normalize() 的映射形态要求"值 = 显示名"是字符串，键 %s 那一条不是。',
                    var_export($key, true),
                ));
                continue;
            }

            $out[] = self::make($value, $identity);
        }

        return $out;
    }

    /**
     * 从"行数组"组装选项表（DB / HTTP 最常见的形状）。
     *
     * ```php
     * // $rows = [['id' => 1, 'name' => '北京市'], ['id' => 2, 'name' => '上海市']]
     * Select::make(Option::fromRows($rows, 'name', 'id'))->value($this->city);
     * ```
     *
     * 字段名拼错直接 Fatal，并报告哪一行、缺哪个字段、现有哪几列。
     *
     * @param array<array-key, array<array-key, mixed>> $rows 行数组（每行是关联数组）
     * @param string $labelField 显示名取哪一列
     * @param string $valueField 身份取哪一列（数字会被转成 string，见 `normalize()`）
     * @return list<Option>
     * @api
     */
    public static function fromRows(array $rows, string $labelField, string $valueField): array
    {
        $out = [];

        foreach ($rows as $index => $row) {
            if (!\is_array($row)) {
                Errors::report(sprintf(
                    'Option::fromRows() 的第 %s 行不是数组 ⇒ 期望 `list<array<string, mixed>>`'
                        . "\n" . '（DB 的 fetchAll / HTTP 的 JSON 行形状）。',
                    var_export($index, true),
                ));
                continue;
            }

            if (!isset($row[$labelField])) {
                Errors::report(sprintf(
                    'Option::fromRows() 的第 %s 行没有显示名列 %s（现有列：%s）。'
                        . "\n" . '★ 字段名拼错在这里是 Fatal，不是"整列空白"（WPF / antd 那两家是静默的）。',
                    var_export($index, true),
                    var_export($labelField, true),
                    implode(', ', array_keys($row)),
                ));
                continue;
            }

            if (!isset($row[$valueField])) {
                Errors::report(sprintf(
                    'Option::fromRows() 的第 %s 行没有身份列 %s（现有列：%s）。',
                    var_export($index, true),
                    var_export($valueField, true),
                    implode(', ', array_keys($row)),
                ));
                continue;
            }

            $label = $row[$labelField];
            $value = $row[$valueField];

            if (!\is_scalar($label) || !\is_scalar($value)) {
                Errors::report(sprintf(
                    'Option::fromRows() 的第 %s 行的 %s / %s 不是标量（label 是 %s、value 是 %s）'
                        . "\n" . '⇒ 选项的显示名与身份都必须是标量。',
                    var_export($index, true),
                    var_export($labelField, true),
                    var_export($valueField, true),
                    \is_array($label) ? 'array' : (\is_object($label) ? 'object' : 'null'),
                    \is_array($value) ? 'array' : (\is_object($value) ? 'object' : 'null'),
                ));
                continue;
            }

            $out[] = self::make((string) $label, (string) $value);
        }

        return $out;
    }
}
