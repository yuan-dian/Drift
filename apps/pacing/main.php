<?php
declare(strict_types=1);

namespace {

    /**
     * 帧节奏量具（AOT）：真窗口上量 `waitEvents()` 超时分支的实际间隔分布。
     *
     * ★ 住在 apps/ 是因为 `drift-build` 只认 `apps/<name>/project.yml`；
     *   它不是能力示例，是量具（与 `apps/bench` 同一条理由）。
     * ★ 必须在**空闲窗口**上量：只统计连续 `Timeout` 的间隔，消息打断会重置基线。
     * ★ GUI 子系统无 stdout ⇒ 结果写 `build/pacing-out.txt`（cwd = 仓库根）。
     */
    function main(): void
    {
        $platform = new \Drift\Platform\Win32\Win32Platform();
        $handle = $platform->window()->createWindow('frame-pacing', 320, 240);

        // 排空建窗消息（WM_CREATE / WM_SIZE / WM_PAINT…），否则前几帧全是 Message
        for ($i = 0; $i < 50; $i++) {
            $platform->waitEvents(1.0);
            $platform->pumpEvents();
        }

        $target = 1000.0 / 60.0;
        $warmup = 20;
        $frames = 300;
        $gaps = [];
        $last = null;
        $next = \hrtime(true) / 1e6 + $target;

        for ($i = 0; $i < $warmup + $frames; $i++) {
            $now = \hrtime(true) / 1e6;
            $remain = $next - $now;
            if ($remain < 0.0) {
                $remain = 0.0;
            }

            $why = $platform->waitEvents($remain);
            $now = \hrtime(true) / 1e6;

            if ($why === \Drift\Platform\WakeReason::Timeout) {
                if ($last !== null && $i >= $warmup) {
                    $gaps[] = $now - $last;
                }
                $last = $now;
            } else {
                $platform->pumpEvents();
                $last = null;   // 被消息打断：不跨事件量间隔
            }

            // 绝对排程；落后超过一帧就重新对齐（不回补一串帧）
            $next += $target;
            if ($now - $next >= $target) {
                $next = $now + $target;
            }
        }

        $platform->window()->destroy($handle);

        // 插入排序（不依赖标准库排序在 AOT 下的形态）
        $count = \count($gaps);
        for ($i = 1; $i < $count; $i++) {
            $v = $gaps[$i];
            $j = $i - 1;
            while ($j >= 0 && $gaps[$j] > $v) {
                $gaps[$j + 1] = $gaps[$j];
                $j--;
            }
            $gaps[$j + 1] = $v;
        }

        $at = static function (float $p) use ($gaps, $count): float {
            if ($count === 0) {
                return 0.0;
            }
            $idx = $p * ($count - 1);
            $lo = (int) \floor($idx);
            $hi = $lo + 1 < $count ? $lo + 1 : $count - 1;
            $frac = $idx - $lo;

            return $gaps[$lo] * (1.0 - $frac) + $gaps[$hi] * $frac;
        };

        $median = $at(0.5);
        $p99 = $at(0.99);
        $max = $count > 0 ? $gaps[$count - 1] : 0.0;
        $over25 = 0;
        foreach ($gaps as $g) {
            if ($g > 25.0) {
                $over25++;
            }
        }

        $delta = $median - $target;
        if ($delta < 0) {
            $delta = -$delta;
        }
        $pass = $delta <= 0.63 && $p99 <= 20.0 && $max <= 33.0;

        $report = 'target=' . \round($target, 3) . 'ms frames=' . $count . "\n"
            . 'median=' . \round($median, 3)
            . ' p95=' . \round($at(0.95), 3)
            . ' p99=' . \round($p99, 3)
            . ' max=' . \round($max, 3)
            . ' over25=' . $over25
            . ' ' . ($pass ? 'PASS' : 'FAIL') . "\n";

        \file_put_contents('build/pacing-out.txt', $report);
    }
}
