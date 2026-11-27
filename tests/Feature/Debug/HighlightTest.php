<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\App\Replay;
use Drift\DevTools\Session\Digest;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Widget\Element;

/**
 * P3 判据：**高亮（select/highlight 的反向流）**。
 *
 * ## 这一批防的不是"没画出来"，而是三种"看起来画出来了"
 *
 * | 坏法 | 不测就会怎样 |
 * |---|---|
 * | 高亮靠 `setState` 触发重画 | 结构变了（`rebuilt` 涨），"高亮前后因果相同"变成空话 |
 * | 设了值但 `Frame` 整帧跳过 | 屏幕上什么都没有，而查询说"高亮着 #22" |
 * | 每帧无条件画 | 稳态 CPU 空转，而肉眼只看到"高亮是对的" |
 *
 * 三条里前两条互相抵消：只测因果不变会奖励"根本没画"，只测像素会奖励"每次标脏重画"。
 * 所以判据成对：**结构指纹必须逐帧不变 + 像素必须变**。
 *
 * ## 为什么像素要按"环的位置"再判一次
 *
 * "像素变了"只证明**有东西**画上去，证明不了"画的是这个节点"。错位的环、填满整个盒的
 * 错误实现、画在 (0,0) 的常量框，三种都能让整图哈希变红。所以另加一对：
 * 节点边框外 1px 处是 `Warning` 描边、盒心**不是** `Warning`（是描边不是填充）。
 *
 * ## 为什么用 `pick` 拿节点号
 *
 * 它给的是**最深命中盒**（表格里深度 ≥ 7 的 Row 内 Stack），天然覆盖"深层节点也解析得到"
 * —— 那正是上一轮修掉的静默缺陷（原来只查"根 + 直接子节点"两层，深度 ≥ 2 一律查不到
 * 并误报 `node-unmounted`）。用根节点测就永远碰不到那条路。
 */
final class HighlightTest extends SmokeTestCase
{
    private const float W = 800.0;

    private const float H = 600.0;

    /** `ColorToken::Warning` 的浅色值 —— 高亮描边用它，与焦点环的 `Accent` 区分 */
    private const int WARNING = 0xE65100;

    /**
     * 起一个已经跑过两帧的会话，并挑一个**深层**节点。
     *
     * @return array{0: mixed, 1: SurfaceProbe, 2: int}
     */
    private function open(): array
    {
        $script = Plan::build('table', self::W, self::H, ['page' => 1], 2, 1, []);
        $opened = Runner::open('table', self::W, self::H, ['page' => 1], $script->fork());
        $session = $opened['session'];

        try {
            Replay::apply($session, $script);
        } catch (\Throwable $e) {
            $session->close();
            throw $e;
        }

        $picked = $session->query('pick', ['x' => 400.0, 'y' => 300.0]);
        $node = (int) ($picked['hit']['node'] ?? 0);

        check('夹具里点得到节点', $node > 0, 'pick=' . \json_encode($picked['hit'] ?? null, \JSON_UNESCAPED_UNICODE));

        return [$session, $opened['surface'], $node];
    }

    private static function px(?SurfaceProbe $probe): ?string
    {
        $canvas = $probe?->raster();

        return $canvas === null ? null : \hash('sha256', $canvas->toRgba());
    }

    /** @return array<int, string> 帧号 => 结构指纹 */
    private static function causal(mixed $session): array
    {
        return Digest::ofCausal($session->recorder())['frames'];
    }

    private static function warning(): Color
    {
        return Color::fromRgb(0xE6, 0x51, 0x00);
    }

    /**
     * 高亮**不改结构**，但**必须改像素**；两个方向缺一不可。
     *
     * ★ 两个断言在同一个方法里是有意的：拆开之后，"结构不变"会被"压根没画"满足，
     *   "像素变了"会被"靠重建顺带重画"满足 —— 各自都绿，合起来是错的。
     */
    public function testHighlightKeepsStructureButChangesPixels(): void
    {
        [$session, $probe, $node] = $this->open();

        try {
            $before = self::px($probe);
            $causalBefore = self::causal($session);
            $lastT = (float) $session->query('frames', ['limit' => 1])['frames'][0]['t'];

            $session->highlight($node);
            check(
                '高亮请求之后还没画：query 报 node 但 onScreen 为假',
                ($session->query('highlight')['node'] ?? 0) === $node
                    && ($session->query('highlight')['onScreen'] ?? true) === false,
                \json_encode($session->query('highlight'), \JSON_UNESCAPED_UNICODE)
            );

            $session->pump($lastT + 16.0);

            $after = self::px($probe);
            $causalAfter = self::causal($session);
            $answer = $session->query('highlight');

            // ① 结构指纹逐帧不变（新推的那一帧没有可比对象，故只比共有帧）
            $same = 0;
            $diff = 0;
            foreach ($causalBefore as $id => $hash) {
                if (!isset($causalAfter[$id])) {
                    continue;
                }
                $hash === $causalAfter[$id] ? ++$same : ++$diff;
            }
            check(
                '高亮不改结构：逐帧因果指纹全同（不靠 setState 触发重画）',
                $diff === 0 && $same === \count($causalBefore) && $same > 0,
                "相同 {$same} / 不同 {$diff}"
            );
            check(
                '高亮确实多推了一帧（指纹比较不是空集）',
                \count($causalAfter) === \count($causalBefore) + 1,
                \count($causalBefore) . ' → ' . \count($causalAfter)
            );

            // ★ 只比共有帧不够：靠 `markNeedsBuild()` 触发的那次重建发生在**新加的那一帧**，
            //   共有帧全都干净。所以高亮那一帧本身必须零重建 —— 否则"结构不变"只是
            //   把变化挪到了没被比较的帧里。
            $last = $session->query('frames', ['limit' => 1])['frames'][0] ?? [];
            check(
                '高亮那一帧零重建（不是靠标脏顺带重画）',
                (int) ($last['rebuilt'] ?? -1) === 0 && (int) ($last['causes'] ?? -1) === 0,
                \json_encode(['rebuilt' => $last['rebuilt'] ?? null, 'causes' => $last['causes'] ?? null])
            );

            // ② 像素必变
            check(
                '高亮改了像素（叠加层真的上了屏）',
                $before !== null && $after !== null && $before !== $after,
                (string) $before . ' → ' . (string) $after
            );

            // ③ 记账与像素同向
            check(
                '上屏记账与像素同向：onScreen 为真且上屏帧就在这一帧',
                ($answer['onScreen'] ?? false) === true
                    && (int) ($answer['drawnFrame'] ?? 0) === $session->recorder()->frameId(),
                \json_encode($answer, \JSON_UNESCAPED_UNICODE)
            );
        } finally {
            $session->close();
        }
    }

    /** 环画在**这个节点**的边框上，且是描边不是填充。 */
    public function testOutlineFollowsTheNodeNotThePage(): void
    {
        [$session, $probe, $node] = $this->open();

        try {
            $element = Element::findInRoots($session->app()->treeRoots(), $node);
            $box = $element?->effectiveBox();
            check('挑中的节点有盒', $box !== null && $box->hasSize(), $node . ' ' . ($box === null ? 'null' : $box::class));
            if ($box === null || !$box->hasSize()) {
                return;
            }

            [$ox, $oy] = RenderBox::absoluteOrigin($box);
            $size = $box->size();
            $left = (int) \round($ox) - 1;              // 描边外扩 1px
            $midY = (int) \round($oy + ($size->height / 2.0));
            $centerX = (int) \round($ox + ($size->width / 2.0));

            $lastT = (float) $session->query('frames', ['limit' => 1])['frames'][0]['t'];
            $session->highlight($node);
            $session->pump($lastT + 16.0);

            $raster = $probe->raster();
            check('读得到像素', $raster instanceof RasterCanvas);
            if (!$raster instanceof RasterCanvas) {
                return;
            }

            $edge = $raster->pixelAt(\max(0, $left), $midY);
            $center = $raster->pixelAt($centerX, $midY);

            check(
                '环画在节点左边框外 1px：是 Warning 描边',
                $edge->equals(self::warning()),
                'rgb=' . self::describe($edge) . " at ({$left},{$midY}) origin=({$ox},{$oy})"
            );
            check(
                '环是描边不是填充：盒心不是 Warning',
                !$center->equals(self::warning()),
                'rgb=' . self::describe($center) . " at ({$centerX},{$midY})"
            );
        } finally {
            $session->close();
        }
    }

    /** 清除之后像素**回到原样**，且稳态不再重画（否则每帧无条件画）。 */
    public function testClearRestoresPixelsAndSteadyStateStopsPainting(): void
    {
        [$session, $probe, $node] = $this->open();

        try {
            $original = self::px($probe);
            $lastT = (float) $session->query('frames', ['limit' => 1])['frames'][0]['t'];

            $session->highlight($node);
            $session->pump($lastT + 16.0);

            // 稳态：高亮**没变**的第二帧不该再画一次
            $session->pump($lastT + 32.0);
            $steady = self::px($probe);
            $frames = $session->query('frames', ['limit' => 1])['frames'];
            check(
                '稳态不重画：高亮没变的下一帧被整段跳过',
                ($frames[0]['paintSkipped'] ?? false) === true,
                \json_encode($frames[0] ?? null)
            );
            check(
                '稳态像素不变（跳过不是"画了但画得一样"）',
                $steady === self::px($probe),
                (string) $steady
            );

            $session->highlight(0);
            $session->pump($lastT + 48.0);

            check(
                '清除之后像素回到高亮前的样子',
                self::px($probe) === $original,
                (string) $original . ' → ' . (string) self::px($probe)
            );
            check(
                '清除之后 query 报"没有高亮"',
                ($session->query('highlight')['node'] ?? -1) === 0
                    && ($session->query('highlight')['onScreen'] ?? true) === false,
                \json_encode($session->query('highlight'), \JSON_UNESCAPED_UNICODE)
            );
        } finally {
            $session->close();
        }
    }

    /**
     * 失效信号：设了高亮但**没人推帧**时，框架自己知道要跑一帧。
     *
     * 与焦点环的"第七判据"同源：叠加层不经 `setState`，没有这条判据，高亮要等
     * 下一次鼠标移动才出现 —— 而"下一次输入"可能永远不会来。
     */
    public function testHighlightRequestsAFrameOnItsOwn(): void
    {
        [$session, , $node] = $this->open();

        try {
            $app = $session->app();
            $lastT = (float) $session->query('frames', ['limit' => 1])['frames'][0]['t'];

            // 先让稳态成立：推一帧把上一帧的账走完
            $session->pump($lastT + 16.0);

            $session->highlight($node);
            check(
                '高亮请求了帧：wantsFrame 为真（不靠下一次输入）',
                $app->wantsFrame() === true,
                'wantsFrame=n'
            );

            $session->pump($lastT + 32.0);
            check(
                '画完就回落（判据有界，不是恒请求）',
                $app->wantsFrame() === false,
                'wantsFrame=y'
            );

            // ★ 零视口帧（窗口最小化）：这一帧一个像素都画不了，但"欠着一笔高亮"
            //   不能丢。丢了的表现是"恢复窗口之后要等下一次输入才看见环" —— 而
            //   最小化再恢复时几乎不会有输入。这条判据 bite 的是 `continueFrame()`
            //   里那句 `debugHighlight !== paintedHighlight`：正常路径上它在画完之后
            //   恒假，只有"画了但没画成"才起作用，所以必须专门造一次画不成。
            $session->highlight(0);
            $session->pump($lastT + 48.0);
            $session->highlight($node);
            $app->frame($lastT + 64.0, BoxConstraints::tight(new Size(0.0, 0.0)));
            check(
                '画不成的帧（零视口）之后仍然欠着这笔画',
                $app->wantsFrame() === true,
                'wantsFrame=n'
            );

            $session->pump($lastT + 80.0);
            check(
                '恢复视口之后高亮真的上了屏（欠的账被还上）',
                ($session->query('highlight')['onScreen'] ?? false) === true,
                \json_encode($session->query('highlight'), \JSON_UNESCAPED_UNICODE)
            );
        } finally {
            $session->close();
        }
    }

    /**
     * 注解**进脚本**：不是旁路。
     *
     * 若高亮只存在录制器/查询层，`Script::digest()` 不含它 —— 于是"同一份脚本跑两遍"
     * 会让"一次高亮了一次没高亮"的两次运行拿到同一个驱动指纹，差异被指纹掩盖。
     */
    public function testHighlightIsAScriptStepThatSurvivesJsonRoundTrip(): void
    {
        [$session, , $node] = $this->open();

        try {
            $drive = $session->script();
            $plain = $drive->digest();
            $lastT = (float) $session->query('frames', ['limit' => 1])['frames'][0]['t'];

            $session->highlight($node);

            $steps = $drive->steps();
            $highlight = null;
            foreach ($steps as $step) {
                if ($step->kind === Step::HIGHLIGHT) {
                    $highlight = $step;
                }
            }
            check(
                '高亮在脚本里是一步（不是旁路注解）',
                $highlight instanceof Step && (int) $highlight->data['node'] === $node,
                \json_encode($highlight?->toArray())
            );
            check(
                '注解改变了驱动指纹（否则两次运行分不出高亮过没）',
                $drive->digest() !== $plain,
                $plain . ' → ' . $drive->digest()
            );

            // 存盘再读：脚本要能被打开、被手改、被贴进 issue
            $back = Script::fromJson($drive->json());
            check(
                '脚本存盘再读摘要不变',
                $back->digest() === $drive->digest(),
                $back->digest()
            );
            $reloaded = null;
            foreach ($back->steps() as $step) {
                if ($step->kind === Step::HIGHLIGHT) {
                    $reloaded = $step;
                }
            }
            check(
                '读回来的高亮步骤节点号与时刻都没丢',
                $reloaded instanceof Step
                    && (int) $reloaded->data['node'] === $node
                    && $reloaded->t !== null,
                \json_encode($reloaded?->toArray())
            );

            // 重放它：脚本能被重新执行，且仍然高亮同一个节点
            $session->pump($lastT + 16.0);
            check(
                '重放之后仍报同一个高亮节点',
                ($session->query('highlight')['node'] ?? 0) === $node,
                \json_encode($session->query('highlight'), \JSON_UNESCAPED_UNICODE)
            );
        } finally {
            $session->close();
        }
    }

    /**
     * 号写错就**拒绝**，而且不留痕。
     *
     * 记下一个"高亮了不存在的节点"，重放时会同样解析不到 —— 那时失败发生在重放里，
     * 看起来像"重放坏了"，而真正的错误是当初那个号。
     */
    public function testUnknownNodeIsRejectedWithoutRecordingAStep(): void
    {
        [$session, $probe, $node] = $this->open();

        try {
            $before = self::px($probe);
            $steps = \count($session->script()->steps());

            $error = null;
            try {
                $session->highlight(999999);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }

            check('写错的节点号被拒', $error !== null && \str_contains($error, '999999'), (string) $error);
            check(
                '被拒之后脚本没有多出一步（不留"高亮了不存在的节点"）',
                \count($session->script()->steps()) === $steps,
                $steps . ' → ' . \count($session->script()->steps())
            );
            check(
                '被拒之后高亮状态没被污染',
                ($session->query('highlight')['node'] ?? -1) === 0,
                \json_encode($session->query('highlight'), \JSON_UNESCAPED_UNICODE)
            );

            // 负数同样是错的：0 是"清除"，不是一个"更小的节点号"
            $negative = null;
            try {
                /** @phpstan-ignore-next-line 故意传非法值 */
                $session->highlight(-1);
            } catch (\InvalidArgumentException $e) {
                $negative = $e->getMessage();
            }
            check('负数节点号被拒', $negative !== null, (string) $negative);
            check('被拒之后像素没变', self::px($probe) === $before, 'px');
        } finally {
            $session->close();
        }
    }

    private static function describe(Color $color): string
    {
        return \sprintf('#%02X%02X%02X', $color->r, $color->g, $color->b);
    }
}
