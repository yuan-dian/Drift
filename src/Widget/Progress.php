<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsRole;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderProgress;
use Drift\Rendering\ColorToken;
use Drift\Rendering\ProgressStyle;

/**
 * 进度条 —— `Progress::make(0.42)`，确定性进度读数（[0, 1] 夹取）。
 *
 * ```php
 * Progress::make(0.42)->width(240.0);
 * // 一行里占满剩余宽度：Expanded::make(Progress::make($total > 0 ? $done / $total : 0.0))
 * ```
 *
 * ★ **受控值，不自持状态**（与 `Checkbox` / `TextField` 同纪律）：值由应用给，
 *   控件不接管"任务跑完"这件事。不知道自己该转多久的用 `Spinner`（§11.5）。
 *
 * ★ **value 是 `0..1` 的 float，进渲染盒前夹取并转成千分比整数**（`$permille`）：
 *   见 `RenderProgress` 类头 —— 跨平台确定性优先（§14.1）。
 *   ★ **夹取而不是报错**：`1.0 + 1e-16` 这类浮点尾巴不该让一整页崩掉；负数与
 *     NaN 归零，≥1 归满 —— 报错是"字段填错"的主场，不是进度条的主场。
 *
 * ★ **语义给 `role=progress` + `value=「37%」`**（§9.2 五类之一的"状态"）——
 *   屏幕阅读器 / UIA 要能报"进度 37%"，这是进度条唯一的非装饰价值。
 *
 * ★ **宽度语义**：默认吃满授予宽度（`->width(240.0)` 给固定宽度）——
 *   与 `Divider` 同一条规则，见 `RenderProgress` 类头。
 *
 * @api
 */
final class Progress extends RenderBoxWidget
{
    /** 千分比（0..1000）—— 渲染盒读这个，不是读 0..1 的 float */
    private int $permille = 0;

    private float $height = 8.0;

    /** `<= 0` = 吃满授予宽度 */
    private float $width = 0.0;

    public function __construct(float $value = 0.0)
    {
        // ★ 必须调：`Widget::__construct()` 里初始化 `$mods`（修饰值对象，永远非空）。
        parent::__construct();

        $this->setPermille($value);
        $this->refreshSemantics();
    }

    public static function make(float $value = 0.0): self
    {
        return new self($value);
    }

    /** 进度（`0..1`，超出即夹取） */
    public function value(float $value): static
    {
        $this->mutate();
        $this->setPermille($value);
        $this->refreshSemantics();

        return $this;
    }

    /** 条高（逻辑像素，默认 8） */
    public function height(float $height): static
    {
        $this->mutate();
        $this->height = $height;

        return $this;
    }

    /** 固定宽度；`<= 0`（默认）= 吃满授予宽度 */
    public function width(float $width): static
    {
        $this->mutate();
        $this->width = $width;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderProgress(
            new ProgressStyle(
                $context->color(ColorToken::Accent),
                $context->color(ColorToken::Border),
                $this->height,
            ),
            $this->permille,
            $this->width,
        );
    }

    private function setPermille(float $value): void
    {
        if (!($value > 0.0)) {
            $this->permille = 0; // 负数 / 0 / NaN 一律归零

            return;
        }
        if ($value >= 1.0) {
            $this->permille = RenderProgress::SCALE; // 满

            return;
        }

        $this->permille = (int) round($value * RenderProgress::SCALE);
    }

    /**
     * 语义：`role=progress`，`value` = "37%" 这种读数。
     *
     * ★ name 给 null：进度条没有"名字"，它在语义树里就该是个裸读数；要给
     *   "正在下载"这种语境，调用方把它包进带 name 的父控件（§9.2：语义合并
     *   今天不存在 ⇒ 不预先发明"父子拼名字"的规则）。
     */
    private function refreshSemantics(): void
    {
        $percent = (int) round($this->permille / 10.0);
        $this->semantics(SemanticsRole::Progress, null, $percent . '%');
    }
}
