<?php
declare(strict_types=1);

namespace Drift\Bench;

use Drift\App\Application;
use Drift\Platform\Win32\Win32Platform;
use Drift\Rendering\SurfaceProfile;
use Drift\Widget\Widget;

/**
 * M4 的应用壳：根就是 showcase 的真实树，与无头 M3 的第一档同源。
 */
final class BenchApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Trees::showcase();
    }

    public function title(): string
    {
        return 'Drift bench M4';
    }
}

/**
 * M4：**真机**每帧分解（`26` §5 的第 1 项）。
 *
 * ── 为什么非要有它 ──────────────────────────────────────────────────────
 * M3 的分母是 `settle + layout + paint`，**不含 present**。而真机上一帧的大头
 * 曾经在"像素搬出搬进"这条链上：
 *
 * ```
 * Skia 画布 →(toRgba) PHP 字符串 4MB →(drift_win_present) 逐像素 swizzle → GDI
 * ```
 *
 * 这一版已经上了**直贴**（`drift_win_present_skia`，C 侧读 Skia 表面贴屏，
 * 像素不进 PHP），所以现在的分段是：
 *
 * ```
 * beginFrame  Skia 表面分配 + 清白
 * present     C 侧 memcpy 进 g_frame + GDI 贴屏   ← 原来这里是 toRgba + swizzle
 * ```
 *
 * ── 量什么 ──────────────────────────────────────────────────────────────
 *   · `端到端`  = `Application::frame()` 全程（settle/pump/layout/paint/present 全含）
 *   · `beginFrame` = Skia 表面分配 + 清白
 *   · `present`    = 直贴（memcpy + GDI）
 *   · `其余`       = 端到端 − 上面两段（≈ settle + layout + paint 的 PHP 部分）
 *
 * ★ **不开消息泵**：这里不需要输入，`presentTo()` 自己 `GetDC` 贴屏，窗口 show 之后
 *   DC 就有效。跑完直接 `exit` —— 基准不是交互程序。
 *
 * ★ 前 `WARMUP` 帧丢弃：首帧要 build 整棵树，且 DWM 的第一帧要建合成表面。
 */
final class M4
{
    private const WIDTH = 1280;

    private const HEIGHT = 800;

    private const FRAMES = 300;

    private const WARMUP = 20;

    /**
     * @return list<string> 报告行
     */
    public static function run(): array
    {
        $lines = [];
        $lines[] = '## M4  真机每帧分解（Skia + GDI）';
        $lines[] = '   窗口 ' . self::WIDTH . 'x' . self::HEIGHT . ' 逻辑 · 帧 ' . self::FRAMES
            . '（预热 ' . self::WARMUP . '）· 不开消息泵 · **直贴路径**';
        $lines[] = '';

        $platform = new Win32Platform();
        $window = $platform->window();
        $handle = $window->createWindow('Drift bench M4', self::WIDTH, self::HEIGHT);
        $window->show($handle);

        $provider = $platform->surface($handle);
        if ($provider === null) {
            $lines[] = '   ✗ 平台没给出渲染表面（窗口句柄无效）';

            return $lines;
        }

        $screen = $platform->screen();
        $scale = $screen->scale();
        $surface = $provider->surface();
        $surface->resize(
            (int) \round((float) self::WIDTH * $scale),
            (int) \round((float) self::HEIGHT * $scale),
            $scale,
        );

        $app = new BenchApplication();
        $constraints = $screen->viewport();
        $app->attach($surface, $constraints);

        SurfaceProfile::$on = true;
        SurfaceProfile::reset();

        $samples = [];
        for ($i = 0; $i < self::FRAMES; $i++) {
            $t0 = \hrtime(true);
            $app->frame((float) $i * 16.6667, $constraints);
            $t1 = \hrtime(true);
            if ($i >= self::WARMUP) {
                $samples[] = $t1 - $t0;
            }
        }

        SurfaceProfile::$on = false;

        $total = Stats::median($samples);
        $per = SurfaceProfile::perCall();

        $begin = (int) ($per['beginFrame'] ?? 0);
        $present = (int) ($per['present'] ?? 0);
        $surfaceSum = $begin + $present;

        // ★ 全分解（`26` §8）：paint 段**含** beginFrame/present ⇒ 扣掉才是"画"自己的。
        //   段键由 Frame.php / Application.php 的 guarded 计时喂（SurfaceProfile 默认关）。
        $paintOwn = \max(0, (int) ($per['paint'] ?? 0) - $surfaceSum);
        $layout = (int) ($per['layout'] ?? 0);
        $settle = (int) ($per['settle'] ?? 0);
        $pumpKeys = ['pumpIo', 'pumpTasks', 'pumpSliced', 'anim', 'placeMenu', 'focus', 'semantics'];
        $pumpSum = 0;
        foreach ($pumpKeys as $k) {
            $pumpSum += (int) ($per[$k] ?? 0);
        }
        $known = $surfaceSum + $paintOwn + $layout + $settle + $pumpSum;
        $unattributed = \max(0, $total - $known);

        $lines[] = \sprintf('   dpr %.2f · 物理 %dx%d · 样本 %d 帧',
            $scale,
            (int) \round((float) self::WIDTH * $scale),
            (int) \round((float) self::HEIGHT * $scale),
            \count($samples));
        $lines[] = '';
        $lines[] = \sprintf('   %-16s %10s ms   %6s%%', '端到端', Stats::ms($total), '100.0');
        $lines[] = \sprintf(
            '   %-16s %10s ms   %6s%%', '  beginFrame', Stats::ms($begin), self::pct($begin, $total)
        );
        $lines[] = \sprintf(
            '   %-16s %10s ms   %6s%%', '  present(直贴)', Stats::ms($present), self::pct($present, $total)
        );
        $lines[] = \sprintf(
            '   %-16s %10s ms   %6s%%', '  paint(记录+光栅)', Stats::ms($paintOwn), self::pct($paintOwn, $total)
        );
        $lines[] = \sprintf(
            '   %-16s %10s ms   %6s%%', '  layout', Stats::ms($layout), self::pct($layout, $total)
        );
        $lines[] = \sprintf(
            '   %-16s %10s ms   %6s%%', '  settle', Stats::ms($settle), self::pct($settle, $total)
        );
        foreach ($pumpKeys as $k) {
            $v = (int) ($per[$k] ?? 0);
            if ($v <= 0) {
                continue;   // 全 0 的泵不占行（量具要短）
            }
            $lines[] = \sprintf('   %-16s %10s ms   %6s%%', '  ' . $k, Stats::ms($v), self::pct($v, $total));
        }
        $lines[] = \sprintf(
            '   %-16s %10s ms   %6s%%', '  未归属(帧头尾)', Stats::ms($unattributed), self::pct($unattributed, $total)
        );
        $lines[] = '';
        $lines[] = \sprintf('   surface 链合计 = %s ms（%.1f%%）',
            Stats::ms($surfaceSum),
            (float) $surfaceSum * 100.0 / (float) \max(1, $total));
        $lines[] = '';
        $lines[] = '   ★ 旧路径这里还有一段 `toRgba`（Skia → PHP 字符串 4MB）+ 一次反向 swizzle，';
        $lines[] = '     直贴后**都不存在了**。改动前后的对比见 `26` §5。';
        $lines[] = '';
        $lines[] = '   判据（`25` §4）：surface 链 > 80% ⇒ 继续压这条链；';
        $lines[] = '                    < 50% ⇒ 才轮到 measure/place 拆分。';
        $lines[] = '';
        $lines[] = '   结论：' . self::verdict($surfaceSum, $total, $paintOwn, $layout, $settle, $pumpSum);

        return $lines;
    }

    private static function pct(int $part, int $total): string
    {
        if ($total <= 0) {
            return 'n/a';
        }

        return \number_format((float) $part * 100.0 / (float) $total, 1, '.', '');
    }

    /**
     * 结论：先按 surface 份额分诊，再点名 PHP 侧最大的那段。
     *
     * ★ PHP 侧的"下一步"不是猜的——`26` §8 起每段都有实测占比，最大那段
     *   就是下一站。GUI 上游的裁决一并写在这里：measure/place 缓存不做。
     */
    private static function verdict(
        int $surfaceSum,
        int $total,
        int $paintOwn,
        int $layout,
        int $settle,
        int $pumpSum
    ): string {
        if ($total <= 0) {
            return 'n/a';
        }

        $r = (float) $surfaceSum * 100.0 / (float) $total;
        if ($r > 80.0) {
            return 'surface 链占 ' . \number_format($r, 1, '.', '')
                . '% ⇒ 瓶颈仍在贴屏侧。下一步：复用 Skia 表面（省 beginFrame 的每帧分配）';
        }

        $php = [['paint(记录+光栅)', $paintOwn], ['layout', $layout], ['settle', $settle], ['各泵+语义', $pumpSum]];
        \usort($php, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
        [$topName, $topVal] = $php[0];
        $next = match ($topName) {
            'paint(记录+光栅)' => '绘制是 PHP 侧大头 ⇒ 下一站：脏矩形 / repaint boundary（GUI 判据：draw 的 86% 是光栅，脏矩形省的就是它）',
            'layout' => 'layout 是大头 ⇒ 先查有没有白排（RenderFlex 第一遍已修）；GUI 裁决：measure/place 缓存层不做',
            'settle' => 'settle（Widget 重建）是大头 ⇒ 查重建粒度（脏边界 / markDirty）',
            default => '泵/语义/焦点是大头 ⇒ 查每帧全量推进是否有必要（懒推进 / 变更集）',
        };

        return 'surface 链占 ' . \number_format($r, 1, '.', '')
            . '% ⇒ PHP 侧大头是 ' . $topName . '（' . self::pct($topVal, $total) . '%）。' . $next;
    }
}
