<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use App\Showcase\Pages\ReactivePage;
use Drift\Core\Pipeline;
use Drift\Dev\Bench\FrameTaxApp;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderText;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * 响应式示范页的**判据**：高频值推进不得重建整页。
 *
 * ★ 为什么断言这个：示范页的核心卖点是"LiveText 零 build"。这句话如果只是
 *   页面上摆个计数器给人看，改坏了也没人知道 —— 计数器会跟着一起涨，
 *   而看的人分不清"设计如此"还是"坏了"。这里用 `rebuiltCount()` 独立取证。
 * ★ 判据走公开面（`RenderText::content()` / `Pipeline::rebuiltCount()`），
 *   不读页面私有字段：读私有字段的断言会跟着实现一起漂。
 * ★ 对照组（低频源变必须重建）是为了证明判据**能区分两条路径** ——
 *   没有它，"永远不重建"这种坏实现也能让第一条绿。
 */
final class ReactivePageTest extends SmokeTestCase
{
    /** @return array{0: FrameTaxApp, 1: ReactivePage, 2: BoxConstraints} */
    private function mount(): array
    {
        $page = new ReactivePage();
        $tight = BoxConstraints::tight(new Size(900.0, 640.0));

        $app = new FrameTaxApp(static fn(): Widget => $page->host());
        $surface = new SurfaceProbe();
        $surface->resize(900, 640, 1.0);
        $app->attach($surface, $tight);
        $app->frame(16.0, $tight);

        return [$app, $page, $tight];
    }

    /**
     * 页面里那一个内容**恰好等于**给定串的文本盒。
     *
     * ★ 用全等而不是包含：`ticks = 0` 这种拼好的整串与 LiveText 单独那个 `0`
     *   必须能分开，否则判据会误命中相邻的静态文本。
     */
    private function textBox(object $node, string $content): ?RenderText
    {
        if ($node instanceof Element) {
            $box = $node->effectiveBox();
            if ($box instanceof RenderText && $box->content() === $content) {
                return $box;
            }
        }
        if (!\method_exists($node, 'childCount')) {
            return null;
        }
        for ($i = 0; $i < $node->childCount(); $i++) {
            $found = $this->textBox($node->childAt($i), $content);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 按标签点一个按钮（走控件自己的 `onPressed`，与真实点击同一条回调）。
     *
     * ★ 为什么不用反射设 `running`：那样绕开的正是本次要验的东西 ——
     *   "点「开始」之后帧时钟到底会不会继续问这一页"。
     */
    private function pressButton(object $node, string $label): bool
    {
        if ($node instanceof Element) {
            $widget = $node->widget();
            if ($widget instanceof Button && $widget->label() === $label) {
                $handler = $widget->pressedHandler();
                if ($handler !== null) {
                    $handler();

                    return true;
                }
            }
        }
        if (!\method_exists($node, 'childCount')) {
            return false;
        }
        for ($i = 0; $i < $node->childCount(); $i++) {
            if ($this->pressButton($node->childAt($i), $label)) {
                return true;
            }
        }

        return false;
    }

    public function testStartButtonActuallyStartsTheClock(): void
    {
        [$app, $page, $tight] = $this->mount();

        check('能点到「开始」', $this->pressButton($app->root(), '开始'));

        // 推进两帧：第一次只建立 lastTick 基准，第二次跨过 100ms 才真的写格子。
        $app->frame(120.0, $tight);
        $app->frame(240.0, $tight);

        check(
            '点「开始」后帧时钟继续问这一页（markDirty 登记了活跃表，ticks 真的动了）',
            $this->textBox($app->root(), '1') !== null
        );

        $this->flushChecks();
    }

    public function testHighFrequencyTickRebuildsNothing(): void
    {
        [$app, $page, $tight] = $this->mount();

        check('初始 · 高频源已入盒（LiveText 真的画了）', $this->textBox($app->root(), '0') !== null);

        check('能点到「开始」', $this->pressButton($app->root(), '开始'));

        // ★ 先消化点击本身的那次重建：按钮文案「开始」→「停止」必然要重建一次，
        //   把它算进基线会把 ticks 的功劳/过错混在一起。这一帧也顺带建立 tick 基准。
        $app->frame(64.0, $tight);

        $before = Pipeline::current()->rebuiltCount();
        $app->frame(120.0, $tight);  // 120-64=56 < 100 ⇒ 不推进
        $app->frame(240.0, $tight);  // 240-64=176 ≥ 100 ⇒ ticks 0 → 1
        $rebuilt = Pipeline::current()->rebuiltCount() - $before;

        check(
            '高频值推进 · 整页零重建（LiveText 走叶子路径）',
            $rebuilt === 0,
            "rebuilt=+{$rebuilt}"
        );
        check('高频值推进 · 新值真的进了渲染盒', $this->textBox($app->root(), '1') !== null);
        check('高频值推进 · 旧值已不在盒里', $this->textBox($app->root(), '0') === null);

        $this->flushChecks();
    }

    public function testLowFrequencyWriteStillRebuildsAsControl(): void
    {
        [$app, $page, $tight] = $this->mount();

        $bump = \Closure::bind(function (): void {
            $this->clicks->update(static fn(int $n): int => $n + 1);
        }, $page, ReactivePage::class);

        $before = Pipeline::current()->rebuiltCount();
        $bump();
        $app->frame(32.0, $tight);
        $rebuilt = Pipeline::current()->rebuiltCount() - $before;

        check(
            '对照 · 低频源（页级读点）变确实重建（证明判据能区分两条路径）',
            $rebuilt > 0,
            "rebuilt=+{$rebuilt}"
        );

        $this->flushChecks();
    }
}
