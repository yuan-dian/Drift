<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 发现规则的阈值。值对象，可序列化。
 *
 * ## 为什么不是一组常量
 *
 * 常量只有一份，但**换个项目就错**：慢帧预算在 60Hz 是 16.7ms，在 120Hz 是 8.3ms；
 * 表格页的扇出阈值与表单页不是一个量级。规则不可配，用户就只能改代码 ——
 * 而"要改代码才能用的判据"没人敢用。
 *
 * 第二个理由是复算：阈值必须随证据一起导出（`generated.thresholds`），
 * 否则读者不知道"这条发现是按什么尺度算出来的"，结论就无法被否证。
 *
 * @api
 */
final readonly class Thresholds
{
    public function __construct(
        /** 单帧耗时超过它算慢帧（毫秒）。 */
        public float $slowMs = 16.7,
        /** 单帧重建节点数超过它，且单支占比达到 `fanoutShare`，算扇出异常。 */
        public int $fanoutNodes = 100,
        /** 扇出集中度：单个结构根占该帧重建数的百分比。 */
        public int $fanoutShare = 60,
        /** 同一节点在窗口内的重建次数占比达到它，算重复重建。 */
        public int $repeatPercent = 50,
        /** 同形兄弟折叠组的份数达到它，提示长列表可能没虚拟化。 */
        public int $groupSame = 8,
        /** 约束改写的最小绝对值（px）。 */
        public float $reshapePx = 8.0,
        /** 约束改写相对父内容盒的最小百分比。 */
        public float $reshapePercent = 10.0,
        /** 只看本身够大的盒：1px 图标被"从 760 收到 1"骗出来不是发现。 */
        public float $reshapeMinSize = 100.0,
        /** 同一节点在窗口内被**重画**的帧数占比达到它，算重画风暴（缺重绘边界）。 */
        public int $repaintPercent = 80,
        /** 单个重绘边界在窗口内重录达到它，算层缓存没起作用。 */
        public int $layerMissCount = 3,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * 覆盖给了的键，其余保持不变。未知键忽略——调用方（CLI / 配置）不该因为
     * 多写一个拼错的键就整份失败，但也不能静默生效。
     *
     * @param array<string, scalar|null> $values
     */
    public function with(array $values): self
    {
        $known = \array_intersect_key($values, $this->toArray());
        if ($known === []) {
            return $this;
        }

        return new self(
            (float) ($known['slowMs'] ?? $this->slowMs),
            (int) ($known['fanoutNodes'] ?? $this->fanoutNodes),
            (int) ($known['fanoutShare'] ?? $this->fanoutShare),
            (int) ($known['repeatPercent'] ?? $this->repeatPercent),
            (int) ($known['groupSame'] ?? $this->groupSame),
            (float) ($known['reshapePx'] ?? $this->reshapePx),
            (float) ($known['reshapePercent'] ?? $this->reshapePercent),
            (float) ($known['reshapeMinSize'] ?? $this->reshapeMinSize),
            (int) ($known['repaintPercent'] ?? $this->repaintPercent),
            (int) ($known['layerMissCount'] ?? $this->layerMissCount),
        );
    }

    /** @return array<string, float|int> */
    public function toArray(): array
    {
        return [
            'slowMs' => $this->slowMs,
            'fanoutNodes' => $this->fanoutNodes,
            'fanoutShare' => $this->fanoutShare,
            'repeatPercent' => $this->repeatPercent,
            'groupSame' => $this->groupSame,
            'reshapePx' => $this->reshapePx,
            'reshapePercent' => $this->reshapePercent,
            'reshapeMinSize' => $this->reshapeMinSize,
            'repaintPercent' => $this->repaintPercent,
            'layerMissCount' => $this->layerMissCount,
        ];
    }
}
