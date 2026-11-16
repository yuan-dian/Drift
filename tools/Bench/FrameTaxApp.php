<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

use Drift\App\Application;
use Drift\Widget\Widget;

/**
 * 帧税量具用的最小应用：根由闭包给，标题固定。
 *
 * ★ 它是**具名类**而不是 `frame-tax-probe.php` 里原来的匿名类，理由是 AOT：
 *   `probes/frame_tax_aot` 要在 `-m bin` 下编译同一段测量代码，而匿名类在 tpc 下
 *   **没有先例**（`docs/reference/aot-constraints.md` 零记录）。把编排收进具名类，
 *   "解释器数字"与"AOT 数字"才测的是同一个东西。
 *
 * ★ 合成树档与真实页面档走**同一条挂载路径**（同一份 `attach` / `frame` / 计时），
 *   差别只在闭包返回的根是谁 —— 否则两档的数字不可比。
 *
 * @internal 量具专用，不进公共 API
 */
final class FrameTaxApp extends Application
{
    /**
     * @param \Closure(): Widget $rootFactory 建根的函数（每次调用给一棵新树）
     */
    public function __construct(private readonly \Closure $rootFactory)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return ($this->rootFactory)();
    }

    public function title(): string
    {
        return 'tax';
    }
}
