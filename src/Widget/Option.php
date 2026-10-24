<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 一个可选项：**显示名 + 值**（W8-2 Select，2026-10-23）。
 *
 * ```php
 * Select::make([
 *     Option::make('北京市', '110000'),
 *     Option::make('上海市', '310000'),
 *     Option::of('未知'),            // 显示名就是值
 * ])->value($this->city)->onChanged(fn(string $v) => ...);
 * ```
 *
 * ## 为什么不是 `list<string>`（下标当值）
 *
 * ★★ **`label` 与 `value` 必须分开**：列表一旦会被重排 / 过滤 / 分页（选城市、
 *   选用户、选分类 —— Select 的**全部**真实场景），**下标就不是身份**了。
 *   按下标传值的话，"筛掉两个城市之后选中项跳到了隔壁"这种 bug 只能靠应用自己
 *   补映射。`value` 是**稳定的身份**，`label` 是**给人看的字**。
 *
 * ★ **但 `array<string, string>`（键 = 值、值 = 显示名）不违反这条**，恰恰相反：
 *   键就是身份。2026-10-24 起 `normalize()` 收这种短形式，省掉 `Option::make`
 *   的壳；类的存在意义不变，`Option` 仍是**唯一**的选项类型。
 *   （此前本段把"反对下标当值"写成了"反对数组形态"，那是把两件事混了。）
 *
 * ★ 与 `SegmentedControl`（`list<string>` + 下标）**刻意不同**：分段控件的段是
 *   "几个平级的视图"，位置本身就是语义（第 1 段就是第 1 段），没有"重排后还是
 *   同一个东西"的需求。两条纪律各自的理由都写在这两处，别为了统一而统一。
 *
 * ★ **纯值对象，`public readonly` 即终态**（同 `CustomPaint` 的字段纪律）：
 *   没有 `mutate()` / 没有链式设值 —— 它不是 Widget，不需要封存位。
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
     * 把"选项表的两种书写形态"统一成 `list<Option>`（2026-10-24）。
     *
     * ```php
     * Option::normalize([Option::make('北京市', '110000')]);  // 形态 1：显式对象
     * Option::normalize(['110000' => '北京市']);              // 形态 2：映射（键 = 值）
     * ```
     *
     * ★ `Select::make()` / `ComboBox::make()` 的入参都先过这里 ⇒ 应用不必为"从
     *   DB / HTTP 拿来就是 `id => name`"这种最常见的数据形状再手写一遍 `array_map`。
     *
     * ## 两条必须知道的坑（都是 PHP 的，不是本方法的）
     *
     * 1. ★★ **数字键会被 PHP 转成 int**：`['110000' => '北京市']` 的键实际是
     *    `int 110000`（而 `'01'` 仍是 string）⇒ 这里必须 `(string)` 收口。
     *    不收口这一条**根本装不进去**（`Option::$value` 是 `string`，严格类型下
     *    传 int 是 TypeError）—— 也就是说 `['110000' => ...]` 这种最常见的城市码
     *    写法会直接崩，而错误信息指向 `Option::make()`，与应用写的东西对不上。
     *    ★ 不靠调用方的严格类型兜底：AOT 的类型退化表不保证与解释器同形。
     * 2. ★★ **`[0 => 'x']` 与 `['x']` 在 PHP 里是同一个数组** ⇒ "值 0"与"标签
     *    列表"在语言层面**无法区分**。所以 list 形态**只收 `Option`**，裸值一律
     *    **Fatal**（`Errors::report()` 的默认级别）。不猜的理由：猜错的后果是
     *    "选中了错的人"且全程无报错，比启动即崩贵得多。
     *    要"值 = 显示名"写 `Option::of('北京')`，要"键 = 值"写映射 `['北京' => '北京']`。
     *
     * ★ 顺带一条 PHP 语义（不改、只说明）：映射形态下**重复键后者覆盖前者**，
     *   这是 PHP 数组本身的规则，本方法看不到"被覆盖过"这件事。
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

        // 判"连续整数键"（PHP 的 list）。**不用 `array_is_list()`**：本仓没有它的
        // AOT 先例，而这段五行就够 —— 不值得为省一行去赌 tpc 的函数映射表。
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

                // R4 同款纪律（`Each.php`）：绝不给裸值猜一个身份。
                // ★ `Errors::report()` 默认级别是 **FATAL** ⇒ 下面这句不会返回；
                //   `continue` 只是"万一有人把级别调低"时的安全落点（不猜，就不产出）。
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
     * 从"行数组"组装选项表 —— DB / HTTP 最常见的那一种形状（2026-10-24）。
     *
     * ```php
     * // $rows = [['id' => 1, 'name' => '北京市'], ['id' => 2, 'name' => '上海市']]
     * Select::make(Option::fromRows($rows, 'name', 'id'))->value($this->city);
     * ```
     *
     * ★ 对应 WPF 的 `DisplayMemberPath` / `SelectedValuePath` 与 antd 的
     *   `fieldNames`。**区别在失败形态**：那两家字段名拼错是**静默**的（整列空白，
     *   要自己盯着看），这里缺字段直接 Fatal 并报出「哪一行、缺哪个字段、现有哪几列」。
     *
     * ★ 为什么是 `Option::fromRows()` 而不是 `Select::fromRows()`：它产出的是
     *   `list<Option>`，`Select` / `ComboBox` 乃至以后任何吃选项表的控件都能用；
     *   挂到某个控件上，第二个控件就得再抄一遍。
     *
     * @param array<array-key, array<array-key, mixed>> $rows 行数组（每行是关联数组）
     * @param string $labelField 显示名取哪一列
     * @param string $valueField 身份取哪一列（数字会被转成 string，见 `normalize()` 的坑 1）
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
