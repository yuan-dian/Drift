<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderButton;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * **按下真的把像素改了，而且和悬停不一样**。
 *
 * ★ 为什么需要这个文件：`ColorToken::Pressed` 从 2026-10-25 建表起就**零消费者** ——
 *   主题里写着"按下态底"，但没有任何代码读它。症状是"点下去界面毫无反应"，
 *   而悬停是好的（`ColorToken::Hover` 有消费者）。这条不报错、不崩、不脏任何判据。
 *
 * ★ 判据不能只要"变了"：把 `pressedOverlay` 设成和 `hoverOverlay` 同一个色，
 *   "变了"照样绿 —— 而用户看到的仍然是"按下和指着一样"。所以本文件的骨干是
 *   **三帧对照**：原色 / 悬停 / 按下，三者两两不同；抬起后必须回到**悬停**那一帧
 *   （不是回到原色 —— 指针还在按钮上）。
 *
 * ★ 同理，只要"按下比悬停深"会奖励"按下去直接瞬跳到纯黑"：`testPressIsRamped`
 *   钉住过渡是斜坡，「第二帧落在开区间」。
 */
final class PressPixelTest extends SmokeTestCase
{
    private const W = 240.0;

    private const H = 120.0;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::W, self::H));
    }

    /** @return array{0: Application, 1: SurfaceProbe} */
    private function open(): array
    {
        $app = new class extends Application {
            public function buildRoot(): Widget
            {
                return Column::make()->paddingAll(20.0)->children([
                    Button::make('按下我')->variant(ButtonVariant::Default),
                ]);
            }
        };
        $probe = new SurfaceProbe();
        $probe->resize((int) self::W, (int) self::H, 1.0);
        $app->attach($probe, self::constraints());
        $app->frame(0.0, self::constraints());

        return [$app, $probe];
    }

    /** 跑一帧再拍像素（步长 2px：整幅比，不依赖单点落在哪） */
    private static function shot(Application $app, SurfaceProbe $probe, float $ts): array
    {
        $app->frame($ts, self::constraints());

        $raster = $probe->raster();
        if ($raster === null) {
            return [];
        }

        $pixels = [];
        for ($y = 0; $y < (int) self::H; $y += 2) {
            for ($x = 0; $x < (int) self::W; $x += 2) {
                $c = $raster->pixelAt($x, $y);
                $pixels[] = $c->r . '/' . $c->g . '/' . $c->b;
            }
        }

        return $pixels;
    }

    private static function diffPixels(array $a, array $b): int
    {
        if ($a === [] || \count($a) !== \count($b)) {
            return -1;
        }

        $changed = 0;
        foreach ($a as $i => $v) {
            if ($v !== $b[$i]) {
                $changed++;
            }
        }

        return $changed;
    }

    /** 把过渡跑到收口（40 帧 × 16ms 足够任何斜坡走完） */
    private function settle(Application $app, float $from): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $app->frame($from + $i * 16.0, self::constraints());
        }
    }

    private function buttonBox(Application $app): RenderButton
    {
        $box = $app->root()?->findBoxOf(RenderButton::class);

        return $box instanceof RenderButton ? $box : throw new \LogicException('按钮盒没找到');
    }

    private function buttonElement(Application $app): ?Element
    {
        $root = $app->root();

        return $root === null ? null : findElement(
            $root,
            static fn (Element $e): bool => $e->widget() instanceof Button,
        );
    }

    /**
     * 三帧对照：原色 / 悬停 / 按下，两两不同。
     *
     * ★ 一次拍三帧而不是三个测试：三者必须来自**同一棵树、同一份主题**，
     *   分开建三棵树去比，比出来的差异里混着"主题解析了两次"。
     */
    public function testPressDiffersFromHoverAndBase(): void
    {
        [$app, $probe] = $this->open();
        $base = self::shot($app, $probe, 1000.0);

        $box = $this->buttonBox($app);
        $box->setHovered(true);
        $this->settle($app, 1000.0);
        $hover = self::shot($app, $probe, 2000.0);

        $box->setPressed(true);
        $this->settle($app, 2000.0);
        $pressed = self::shot($app, $probe, 3000.0);

        $hoverVsBase = self::diffPixels($base, $hover);
        $pressVsBase = self::diffPixels($base, $pressed);
        $pressVsHover = self::diffPixels($hover, $pressed);

        check(
            '按下像素 · 悬停相对原色有变化（前提：这条路径本来是好的）',
            $hoverVsBase > 0,
            '变化像素数=' . $hoverVsBase,
        );
        check(
            '按下像素 · 按下相对原色有变化（真机症状：一个都不变）',
            $pressVsBase > 0,
            '变化像素数=' . $pressVsBase,
        );
        check(
            '按下像素 · 按下与悬停**不是同一幅画**（否则"按下去"和"指着"看起来一样）',
            $pressVsHover > 0,
            '变化像素数=' . $pressVsHover . ' ⇒ 两档色相同或按下层没画',
        );

        $this->flushChecks();
    }

    /**
     * 抬起之后回到**悬停**那一帧，不是回到原色。
     *
     * ★ 这是"两条独立斜坡"的核心语义：`pressAnim` 与 `hoverAnim` 各自推进，
     *   合用一个值就表达不了"按下比悬停深、松开退回悬停档"。
     */
    public function testReleaseFallsBackToHover(): void
    {
        [$app, $probe] = $this->open();

        $box = $this->buttonBox($app);
        $box->setHovered(true);
        $this->settle($app, 1000.0);
        $hover = self::shot($app, $probe, 2000.0);

        $box->setPressed(true);
        $this->settle($app, 2000.0);

        $box->setPressed(false);
        $this->settle($app, 3000.0);
        $released = self::shot($app, $probe, 4000.0);

        check(
            '按下像素 · 松开后回到悬停档（逐像素相同 ⇒ 按下层已完全撤掉）',
            self::diffPixels($hover, $released) === 0,
            '与悬停帧的差=' . self::diffPixels($hover, $released) . '；进度='
                . \sprintf('%.3f', $box->pressProgress()),
        );

        $this->flushChecks();
    }

    /**
     * 按下是**斜坡**，不是瞬跳。
     *
     * ★ 与 `testPressDiffersFromHoverAndBase` 成对：那条只要"画面不同"，
     *   这条防"按下去直接跳纯黑也算不同"。
     */
    public function testPressIsRamped(): void
    {
        [$app] = $this->open();
        $box = $this->buttonBox($app);
        $box->setPressed(true);

        $seen = [];
        for ($i = 1; $i <= 10; $i++) {
            $app->frame($i * 16.0, self::constraints());
            $seen[] = $box->pressProgress();
        }

        $seq = \implode(',', \array_map(static fn (float $v): string => \sprintf('%.3f', $v), $seen));

        check(
            '按下像素 · 第二帧进度在开区间 (0,1)（首帧只记时间起点 ⇒ 必为 0）',
            $seen[1] > 0.0 && $seen[1] < 1.0,
            '第二帧=' . $seen[1] . ' 序列=' . $seq,
        );

        $monotonic = true;
        for ($i = 1; $i < \count($seen); $i++) {
            if ($seen[$i] < $seen[$i - 1]) {
                $monotonic = false;

                break;
            }
        }
        check('按下像素 · 进度单调不减', $monotonic, '序列=' . $seq);

        $this->flushChecks();
    }

    /**
     * 换代（`Element::update()` 换新盒）时按下态要跟着搬。
     *
     * ★ `inheritRuntimeState()` 是**手写清单**，新增瞬态字段漏一行就静默归零 ——
     *   而"按下期间正好有兄弟重建"在真机上很常见（按下时按钮自己常常会 setState）。
     */
    public function testPressSurvivesRebuild(): void
    {
        [$app] = $this->open();

        $btnEl = $this->buttonElement($app);
        if ($btnEl === null) {
            check('按下像素 · 按钮 Element 找得到', false);

            return;
        }

        $this->buttonBox($app)->setPressed(true);

        $seen = [];
        for ($i = 1; $i <= 10; $i++) {
            $btnEl->update(Button::make('按下我')->variant(ButtonVariant::Default));
            $app->frame($i * 16.0, self::constraints());
            $seen[] = $this->buttonBox($app)->pressProgress();
        }

        $seq = \implode(',', \array_map(static fn (float $v): string => \sprintf('%.3f', $v), $seen));

        check(
            '按下像素 · 重建后进度从原处续上（不是 0 也不是瞬跳 1.0）',
            $seen[1] > 0.0 && $seen[1] < 1.0,
            '第二帧=' . $seen[1] . ' 序列=' . $seq,
        );

        $this->flushChecks();
    }

    /** 收口之后不再空转（同 `HoverPixelTest::testSettledHoverIsStable` 的理由） */
    public function testSettledPressIsStable(): void
    {
        [$app, $probe] = $this->open();
        $this->buttonBox($app)->setPressed(true);
        $this->settle($app, 1000.0);

        $a = self::shot($app, $probe, 2000.0);
        $this->settle($app, 2000.0);
        $b = self::shot($app, $probe, 3000.0);

        check(
            '按下像素 · 收口后画面稳定（不是每帧都在变）',
            self::diffPixels($a, $b) === 0,
            '变化像素数=' . self::diffPixels($a, $b),
        );

        $this->flushChecks();
    }

    /**
     * **没声明按下色的盒不接受按下态** —— `acceptsPress()` 的默认值。
     *
     * ★ 这条防的是"把 press 合并进 hover"的简化：那样任何接受悬停的盒都会被推
     *   一个它不画的按下态，状态在传、画面不动、还没有报错。
     */
    public function testAcceptsPressIsOptIn(): void
    {
        [$app] = $this->open();

        $box = $this->buttonBox($app);
        check('按下像素 · 按钮声明了按下色 ⇒ 接受按下态', $box->acceptsPress(), 'acceptsPress()=false');

        $plain = $app->root()?->effectiveBox();
        check(
            '按下像素 · 普通盒默认不接受按下态（能力方法默认 false）',
            $plain !== null && !$plain->acceptsPress(),
            'root box acceptsPress()=' . ($plain === null ? 'null' : 'true'),
        );

        $this->flushChecks();
    }

    /**
     * **派发链：真指针事件进来，按下态落在这个按钮的盒上。**
     *
     * ★ 上面六条全部直接调 `setPressed()` —— 那只能证明"盒会画按下态"，
     *   证明不了"按下会走到盒上"。而这段接线（`InputRouter` → `FocusManager::pressTo`）
     *   漏一行就是"逻辑全对、真机点下去没反应"，与 `ColorToken::Pressed` 零消费者
     *   是同一个病。所以这里必须从 `PointerEvent` 进。
     */
    public function testPointerDownReachesButtonBox(): void
    {
        [$app] = $this->open();

        $app->dispatchInput(PointerEvent::down(40.0, 30.0));

        check(
            '按下像素 · 指针按下走到按钮盒（`InputRouter` → `pressTo` 接线）',
            $this->buttonBox($app)->pressed(),
            'pressed()=false ⇒ 按下态没有从事件层传下来',
        );

        $this->flushChecks();
    }

    /**
     * 抬起清按下态，但**悬停态要留着**（指针还在按钮上）。
     *
     * ★ 两个状态若在某处被一起清（比如都挂在"指针离开"上），这条会红 ——
     *   而红的方式正好是"松手后按钮变成没碰过的样子"，很难在真机上注意到。
     */
    public function testPointerUpClearsPressKeepsHover(): void
    {
        [$app] = $this->open();

        $app->dispatchInput(PointerEvent::down(40.0, 30.0));
        $box = $this->buttonBox($app);
        $box->setHovered(true);   // 真机上是 down 之前那次 move 推的；夹具里显式给

        $app->dispatchInput(PointerEvent::up(40.0, 30.0));

        check('按下像素 · 抬起清按下态', !$box->pressed(), 'pressed()=true ⇒ 抬起没收口');
        check(
            '按下像素 · 抬起后悬停态仍在（指针还在按钮上）',
            $box->hovered(),
            'hovered()=false ⇒ 抬起把悬停一起清了',
        );

        $this->flushChecks();
    }

    /** 拖出窗口（leave）⇒ 按下态清（否则回来时按钮还"按着"） */
    public function testPointerLeaveClearsPress(): void
    {
        [$app] = $this->open();

        $app->dispatchInput(PointerEvent::down(40.0, 30.0));
        $box = $this->buttonBox($app);

        $app->dispatchInput(PointerEvent::leave(40.0, 30.0));

        check(
            '按下像素 · 指针离开客户区后按下态清（不是永久按着）',
            !$box->pressed(),
            'pressed()=true ⇒ leave 没收口',
        );

        $this->flushChecks();
    }
}
