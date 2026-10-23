<?php
declare(strict_types=1);

namespace Drift\Widget;

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
 * ## 为什么不是一个 `array<string, string>` 或 `list<string>`
 *
 * ★★ **`label` 与 `value` 必须分开**：列表一旦会被重排 / 过滤 / 分页（选城市、
 *   选用户、选分类 —— Select 的**全部**真实场景），**下标就不是身份**了。
 *   按下标传值的话，"筛掉两个城市之后选中项跳到了隔壁"这种 bug 只能靠应用自己
 *   补映射。`value` 是**稳定的身份**，`label` 是**给人看的字**。
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
}
