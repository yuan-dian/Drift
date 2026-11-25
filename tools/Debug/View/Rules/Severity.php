<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View\Rules;

/**
 * 一条发现的严重度。
 *
 * ## 为什么是三级而不是两级
 *
 * 两级（error / info）在成熟工具里试过，最后都加了中间那一级：`error` 要留给
 * "不修就是 bug"（帧超预算、几何算错），`info` 只能表达"说明"（某个阶段的占比），
 * 而"大概率是问题但可能是有意的"（同形兄弟一起重建、边界反复失效）落在中间，
 * 塞进任何一边都会让那一级的信噪比崩掉。
 *
 * 参照 ESLint 的 `error` / `warn` / `off` 与 Lighthouse 的
 * `binary` / `numeric` / `informative`：级别只影响**排序与过滤**，不影响判据 ——
 * 判据一旦随级别变化，同一份证据换个 `--min-severity` 就得到不同结论，
 * 而"可复算"要求的是事实不变、只是少看了几行。
 */
enum Severity: string
{
    /** 不修就是 bug：帧超预算、证据自相矛盾。 */
    case Error = 'error';

    /** 大概率是问题，也可能是有意为之：需要读者判断一次。 */
    case Warning = 'warning';

    /** 说明性：解释"为什么是这样"，不是缺陷。 */
    case Info = 'info';

    /** 数值越大越严重，用于 `--min-severity` 的比较。 */
    public function rank(): int
    {
        return match ($this) {
            self::Error => 3,
            self::Warning => 2,
            self::Info => 1,
        };
    }

    /** 终端里只占一个字，长标签会把结论挤掉。 */
    public function mark(): string
    {
        return match ($this) {
            self::Error => 'E',
            self::Warning => 'W',
            self::Info => 'I',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Error => '错误',
            self::Warning => '警告',
            self::Info => '提示',
        };
    }

    /**
     * 解析命令行给的值。
     *
     * 接受全名与单字母两种写法（`warn` / `w` / `warning` 都是 `Warning`）：
     * 命令行上没人愿意打全 `--min-severity=warning`，但缩写在文档里必须写明，
     * 否则读者要试一次才知道。`off` 单独处理 —— 它不是级别，是"关掉这条规则"。
     */
    public static function parse(string $raw): ?self
    {
        return match (\strtolower(\trim($raw))) {
            'error', 'err', 'e' => self::Error,
            'warning', 'warn', 'w' => self::Warning,
            'info', 'i' => self::Info,
            default => null,
        };
    }
}
