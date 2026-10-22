<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Geometry\BoxConstraints;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：**每帧故意慢一点**的应用（验 `FrameClock` 真的在量帧耗时）。
 *
 * ★ 存在理由：`noteFrameCost()` 的算术可以直接调，但"**它到底有没有被喂**"只有
 *   跑一次真帧才能证明。没有这条，`FrameClock` 里那两行 `hrtime` 摘掉了也不会红
 *   —— 典型的"写了但没被走通的分支"（§24.2 #25）。
 *
 * ★ 睡眠放在 `parent::frame()` **之后**：`FrameClock::tick()` 量的是整段
 *   `frame()` 调用，睡在前后都一样；放在后面不影响帧内的九段顺序。
 *
 * ★ 不继承 `RevealApplication`（它是 `final`，且这里不需要焦点链）：一个 `Text`
 *   就够了 —— 本夹具只关心"一帧花了多久"。
 */
final class SlowFrameApplication extends Application
{
    /** 每帧额外睡多久（微秒）—— 默认刚好超过 16.7ms 预算 */
    public int $sleepUs = 18000;

    public function buildRoot(): Widget
    {
        return Text::make('slow');
    }

    public function frame(float $timestampMs, BoxConstraints $constraints): void
    {
        parent::frame($timestampMs, $constraints);

        if ($this->sleepUs > 0) {
            usleep($this->sleepUs);
        }
    }
}
