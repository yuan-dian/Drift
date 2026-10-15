<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Rendering\Color;
use Drift\Text\TextMetricsProvider;
use Drift\Theme\ColorToken;
use Drift\Theme\MetricToken;
use Drift\Theme\ThemeData;

/**
 * `build()` 的入参：**本次构建所处的渲染环境**（§3.4）。
 *
 * ★ 三条边界 —— 都不是风格偏好，每条都对应一个判据：
 *   ① **只读** —— 无 setter。改环境走 Widget / 主题树。
 *   ② **无业务数据** —— 不得出现 `Subject` 子类型（否则 `dead-subscription`
 *      的判据会被绕过：读值与订阅都藏在 `$ctx` 后面）。业务数据在组合点
 *      直接注入构造器，不经这里。
 *   ③ **无向上查找** —— 无 `of()` / `find()` / `maybeOf()`（§18 已否决
 *      Flutter 的 provider soup）。
 *
 * @api
 */
final readonly class BuildContext
{
    /**
     * @param float $width  本容器收到的约束宽（逻辑单位，§6.1）
     * @param float $height 本容器收到的约束高
     * @param ThemeData $theme 沿 Element 父链**解析后**的结果（不是查找入口）
     * @param TextMetricsProvider $metrics **布局确定性的注入点**（§14.2）——
     *        Widget 层问度量、不问平台，所以测试能喂固定度量、golden 能复现。
     */
    public function __construct(
        public float $width,
        public float $height,
        private ThemeData $theme,
        private TextMetricsProvider $metrics,
    ) {
    }

    public function theme(): ThemeData
    {
        return $this->theme;
    }

    public function metrics(): TextMetricsProvider
    {
        return $this->metrics;
    }

    public function color(ColorToken $token): Color
    {
        return $this->theme->color($token);
    }

    public function metric(MetricToken $token): float
    {
        return $this->theme->metric($token);
    }
}
