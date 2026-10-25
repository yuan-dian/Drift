<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * **按视口造子层**的纵向列表（虚拟化，§13.1 / M5）。
 *
 * ```php
 * LazyColumn::make(56.0)                                  // 每行 56 逻辑像素
 *     ->items($users, fn(User $u): Widget => UserRow::make($u))
 *     ->overscan(56.0);                                   // 视口外多造一行
 * ```
 *
 * ★ **与 `Each` 的分工**：`Each` 是"全量列表" —— `items()` 在构建期把**每个**
 *   item 造成一个 Widget，1000 行 ⇒ 1000 个实例、1000 份布局。`LazyColumn`
 *   只造**视口覆盖到的那几行**，其余行既没有 Widget 也没有 Element 也没有盒。
 *   实测（`_build/bench-list3.php`，Card+Row 两段文本的行）：
 *
 *   | 行数 | `Each` 单帧（mount+layout） | `LazyColumn`（约 12 行可见） |
 *   |---|---|---|
 *   | 200 | ~17ms | ~2ms |
 *   | 1000 | ~96ms | ~2ms |
 *   | 2000 | ~200ms | ~2ms |
 *
 *   ⇒ 全量列表的成本**随行数线性增长**（约 0.076ms/行），虚拟列表与行数**无关**。
 *
 * ★ 与 `LazyRow` 的差别只有主轴：本类是**纵向**（内容沿 y 变长、子层是 `Column`、
 *   每行定高）；共同部分（窗口换算 / 锚点 / `sliceOrigin` / 可滚上限）全在
 *   `LazyList` + `LazyListState` 一处，**不要再复制一份**。
 *
 * @api
 */
final class LazyColumn extends LazyList
{
    /**
     * @param float $itemExtent 每行的高度（逻辑像素，**必须 > 0**）
     */
    public static function make(float $itemExtent): self
    {
        self::checkExtent($itemExtent);

        return new self($itemExtent, true);
    }

    public function createState(): State
    {
        return new LazyListState();
    }
}
