<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderButton;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * **像素脏了 ⇒ 帧时钟必须自己上膛**（`Application::needsFrameNow()` 第八判据）。
 *
 * ★ 这一组测的不是"画得对不对"，是**"帧会不会来"**。原先 `wantsFrame()` 只认
 *   结构（`needsSettle`）、动画（`$animating`）、定时器、焦点环 —— **只改像素的
 *   通道一条都不认**：`setHovered()` / `setBarOpacity()` / `applyCompose()`。
 *   后果是死锁：没脏 ⇒ 不跑帧 ⇒ 不 tick ⇒ 永不脏。
 *
 * ★ 真机症状（`tools/probe-motion.py` 连红 5 条抓到）：
 *   ① 鼠标移上按钮**一个像素都不变**；
 *   ② 滚动条"停手 1.6s 淡出"**从来没跑过**（无输入时帧时钟早已落闸）。
 *
 * ★ 判据必须成对：**上膛**（脏了要醒）与**落闸**（收口后不许空转）。只测前者，
 *   "每帧都上膛"也能绿 —— 那就退回到 N1 之前 22% CPU 的老毛病。
 */
final class PaintArmsFrameTest extends SmokeTestCase
{
    /** 一个只有按钮的最小应用（盒外留白 8px ⇒ 浮起影子落在留白里） */
    private function openButtonApp(): Application
    {
        $app = new class extends Application {
            public function buildRoot(): Widget
            {
                return Column::make()->children([
                    Button::make('悬停')->variant(ButtonVariant::Default),
                ]);
            }
        };
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(200.0, 60.0)));
        $app->frame(0.0, BoxConstraints::tight(new Size(200.0, 60.0)));

        return $app;
    }

    private function boxOf(Application $app): RenderButton
    {
        $box = $app->root()?->findBoxOf(RenderButton::class);

        return $box instanceof RenderButton ? $box : throw new \LogicException('按钮盒没找到');
    }

    public function testHoverArmsTheFrameClock(): void
    {
        $app = $this->openButtonApp();
        $box = $this->boxOf($app);

        check(
            '像素脏 · 稳态时 wantsFrame() = false（前置：此刻帧时钟是落闸的）',
            $app->wantsFrame() === false,
        );

        $box->setHovered(true);

        check(
            '像素脏 · 悬停后 wantsFrame() = true（脏信号必须能叫醒帧时钟）',
            $app->wantsFrame() === true,
            'wantsFrame=false ⇒ 鼠标移上去永远不画',
        );

        $this->flushChecks();
    }

    public function testBarOpacityArmsTheFrameClock(): void
    {
        // 滚动条淡出是**同一条判据的第二个消费者**：它在"无任何输入"时才发生，
        // 那时帧时钟早已落闸 ⇒ 没有这条判据就一次也不会跑。
        $app = new class extends Application {
            public function buildRoot(): Widget
            {
                return ScrollView::make(Column::make()->children([
                    Text::make(str_repeat('行', 200)),
                ]));
            }
        };
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(200.0, 60.0)));
        $app->frame(0.0, BoxConstraints::tight(new Size(200.0, 60.0)));

        $box = $app->root()?->findBoxOf(RenderScrollView::class);
        if (!$box instanceof RenderScrollView) {
            check('像素脏 · 滚动条夹具挂上了', false);

            return;
        }

        check('像素脏 · 滚动条夹具稳态 wantsFrame() = false', $app->wantsFrame() === false);

        $box->setBarOpacity(0.2);

        check(
            '像素脏 · 滚动条不透明度变了 ⇒ wantsFrame() = true（淡出才有帧可推）',
            $app->wantsFrame() === true,
        );

        $this->flushChecks();
    }

    public function testClockFallsBackAfterPaint(): void
    {
        $app = $this->openButtonApp();
        $box = $this->boxOf($app);

        // ★ 只标脏、不动画：`setBarOpacity` 是"画一帧就清"的一次性欠账。
        //   用它才能测"落闸"—— hover 走动画，收口前本来就该一直醒着。
        $box->setHovered(true);
        $tight = BoxConstraints::tight(new Size(200.0, 60.0));

        // 跑到达标（hover 斜坡 ~370ms 收口）
        for ($i = 1; $i <= 40; $i++) {
            $app->frame($i * 16.0, $tight);
        }

        check(
            '像素脏 · 悬停收口后 wantsFrame() 回落 false（不许为了悬停永久空转）',
            $app->wantsFrame() === false,
            'wantsFrame=true ⇒ 静态界面每帧还在醒',
        );

        $this->flushChecks();
    }
}
