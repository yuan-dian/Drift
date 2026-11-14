<?php
declare(strict_types=1);

namespace Drift\Input;

use Drift\Theme\SystemAppearance;

/**
 * 系统外观变更（§9.2）：明暗切换、高对比度开关、用户改系统色。
 *
 * ★ 事件带**一次外观快照**：`Application` 不持 `Screen`（§5.1 纪律 1），
 *   拿不到 HC 状态与系统色；把快照塞进事件，框架层才能零依赖地换主题。
 *   这也推翻了原「载荷为空、消费者自己查」的口径 —— 那时只有明暗一个 bool，
 *   现在 HC 有独立的轴 + 四个系统色，二次查询没有落点。
 *
 * ★ `null` 保留旧形态：只说「变了」，框架退回 `onSystemThemeChanged()` 钩子，
 *   由应用自己换主题。测试与未接外观的平台走这条，零破坏。
 *
 * ★ 与 `DpiChanged` 同通道：环境变了 ⇒ 全树重建（不是 relayout）。
 *
 * @api
 */
final readonly class ThemeChangedEvent implements InputEvent
{
    public function __construct(
        public ?SystemAppearance $appearance = null,
    ) {
    }
}
