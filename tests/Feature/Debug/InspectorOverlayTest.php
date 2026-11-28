<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\DevTools\App\DebugSession;
use Drift\DevTools\Inspect\BoxLayers;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Rendering\Color;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * 真机检查器叠加：四层数值必须与几何记录逐值相同，且"没东西可画"要如实上报。
 *
 * | 方法 | 咬住什么 |
 * |---|---|
 * | `testBoxLayersAgreeWithGeometryRecord` | `BoxLayers` 自己算错一层（含 content 原点） |
 * | `testBoxModelNeedsASelection` | "开着但没选中"时假装画了 / 选中了却不画 |
 * | `testHudAgreesWithStatsAndBoxModel` | HUD 的帧号/耗时/重画数另算一遍，或几何对不上 |
 * | `testHudNeedsASelection` | 没选中也给选中摘要 / 没选中仍上屏 |
 * | `testHudDrawsWhenSelected` | HUD 开着但闭包没接上（像素不变） |
 * | `testAllLayersOffDrawsNothing` | 全关时仍在画（叠加层关不掉） |
 *
 * ★ 第一条是**数据判据**：活树四层与 `boxModel()` 记录四层逐值比。两边若各算一套，
 *   迟早给出"叠加层说 padding 12、Web 说 padding 8"这种没人报错的错位。
 * ★ 后两条是**像素判据**：`paint()` 的返回值可以被写错（无条件 true），但屏幕不会。
 */
final class InspectorOverlayTest extends SmokeTestCase
{
    private const W = 300;

    private const H = 200;

    public function testBoxLayersAgreeWithGeometryRecord(): void
    {
        [$app, $session] = $this->open();
        try {
            $node = $this->targetNode($app);
            check('找得到 Column 节点', $node > 0, 'node=' . $node);
            if ($node <= 0) {
                return;
            }

            $model = $session->query('boxModel', ['node' => $node]);
            check('盒模型查得到', ($model['found'] ?? false) === true, \json_encode($model));

            $element = Element::findInRoots($app->treeRoots(), $node, fn(Element $e): bool => $e->debugId === $node);
            $box = $element?->effectiveBox();
            check('活树里找得到这个盒', $box !== null);
            if ($box === null) {
                return;
            }

            $layers = BoxLayers::of($box);
            check('活树的盒有尺寸', $layers !== null);
            if ($layers === null) {
                return;
            }

            check('margin 逐值相同', $layers['margin'] == $model['margin'], \json_encode([$layers['margin'], $model['margin']]));
            check('padding 逐值相同', $layers['padding'] == $model['padding'], \json_encode([$layers['padding'], $model['padding']]));
            check('border 宽度相同', $layers['borderWidth'] == $model['border']['width'], \json_encode([$layers['borderWidth'], $model['border']]));
            check('border box 尺寸相同', [$layers['border']['w'], $layers['border']['h']] == [$model['size']['w'], $model['size']['h']], \json_encode([$layers['border'], $model['size']]));

            // content 原点 = 绝对原点 + padding：本项目的 border 是描边，**不占**布局
            // 空间，所以这里不能再减 borderWidth。减了就是"叠加层的 content 框比
            // 文字实际的起点偏内半个边框"——一个像素级的、只有量过才知道的错。
            [$ax, $ay] = RenderBox::absoluteOrigin($box);
            check(
                'content 原点 = 绝对原点 + padding',
                $layers['content']['x'] == $ax + $layers['padding']['l']
                    && $layers['content']['y'] == $ay + $layers['padding']['t'],
                \json_encode([$layers['content'], $ax, $ay, $layers['padding']]),
            );
        } finally {
            $session->close();
        }
    }

    public function testBoxModelNeedsASelection(): void
    {
        [$app, $session, $probe] = $this->open();
        try {
            $before = $this->pixels($probe);

            $answer = $session->inspect(['box-model']);
            check('回执报出生效的层', ($answer['layers'] ?? []) === ['box-model'], \json_encode($answer));
            check('还没画过时 drawnFrame 是 null', ($answer['drawnFrame'] ?? null) === null, \json_encode($answer));

            $session->pump(16.0);
            check(
                '没选中时不上屏',
                $this->pixels($probe) === $before,
                '变了 —— 没选中也在画（画的谁的盒？）',
            );

            $node = $this->targetNode($app);
            $session->highlight($node);
            $session->pump(48.0);

            check(
                '选中之后盒模型上了屏',
                $this->pixels($probe) !== $before,
                '像素没变 —— 选中了也不画',
            );
        } finally {
            $session->close();
        }
    }

    public function testHudAgreesWithStatsAndBoxModel(): void
    {
        [$app, $session] = $this->open();
        try {
            $node = $this->targetNode($app);
            check('找得到 Column 节点', $node > 0, 'node=' . $node);
            if ($node <= 0) {
                return;
            }

            // 几何记录只留"有变化"的帧：pump 之后的最新帧可能没有这个节点的记录。
            // 先取有记录的那一帧，再取 HUD —— 布局没变，两边该逐值相同。
            $model = $session->query('boxModel', ['node' => $node]);
            check('盒模型查得到', ($model['found'] ?? false) === true, \json_encode($model));

            $session->highlight($node);
            $session->inspect(['hud']);
            $session->pump(16.0);

            $hud = $session->inspect(['hud'])['hud'] ?? [];
            check('HUD 认得出选中类型', ($hud['type'] ?? null) === 'Column', \json_encode($hud));

            $stats = $session->query('stats');
            check(
                '耗时与 stats 同源',
                ($hud['costMs'] ?? null) == ($stats['lastCostMs'] ?? null),
                \json_encode([$hud['costMs'] ?? null, $stats['lastCostMs'] ?? null]),
            );
            check(
                '重画数与 stats 同源',
                ($hud['rebuilt'] ?? null) === ($stats['lastRebuilt'] ?? null),
                \json_encode([$hud['rebuilt'] ?? null, $stats['lastRebuilt'] ?? null]),
            );

            // 帧号必须也是**上一完成帧**：HUD 画在帧中间，拿正在录的号会与耗时差一帧。
            $frames = $session->query('frames', ['limit' => 1])['frames'] ?? [];
            $latest = $frames === [] ? 0 : (int) $frames[0]['id'];
            check('帧号取上一完成帧', ($hud['frame'] ?? -1) === $latest, \json_encode([$hud['frame'] ?? null, $latest]));

            check(
                '尺寸与几何记录相同',
                [$hud['width'] ?? null, $hud['height'] ?? null] == [$model['size']['w'], $model['size']['h']],
                \json_encode([$hud['width'] ?? null, $hud['height'] ?? null, $model['size']]),
            );
            check('padding 与几何记录相同', ($hud['padding'] ?? null) == $model['padding'], \json_encode([$hud['padding'] ?? null, $model['padding']]));
            check('border 与几何记录相同', ($hud['border'] ?? null) == $model['border']['width'], \json_encode([$hud['border'] ?? null, $model['border']]));
            check('margin 与几何记录相同', ($hud['margin'] ?? null) == $model['margin'], \json_encode([$hud['margin'] ?? null, $model['margin']]));
        } finally {
            $session->close();
        }
    }

    public function testHudNeedsASelection(): void
    {
        [, $session, $probe] = $this->open();
        try {
            $before = $this->pixels($probe);

            $answer = $session->inspect(['hud']);
            check('回执报出生效的层', ($answer['layers'] ?? []) === ['hud'], \json_encode($answer));
            $hud = $answer['hud'] ?? [];
            check(
                '没选中时没有选中摘要',
                \array_key_exists('type', $hud) && $hud['type'] === null,
                \json_encode($hud),
            );

            $session->pump(16.0);
            check(
                '没选中时不上屏',
                $this->pixels($probe) === $before,
                '变了 —— 没选中也在画 HUD（摘要从哪来的？）',
            );
        } finally {
            $session->close();
        }
    }

    public function testHudDrawsWhenSelected(): void
    {
        [$app, $session, $probe] = $this->open();
        try {
            $baseline = $this->pixels($probe);
            $baselineOps = $probe->list()->count();

            $node = $this->targetNode($app);
            check('找得到 Column 节点', $node > 0, 'node=' . $node);
            if ($node <= 0) {
                return;
            }

            $session->highlight($node);
            $session->inspect(['hud']);
            $session->pump(32.0);

            check('HUD 上了屏', $this->pixels($probe) !== $baseline, '像素没变 —— 开了 hud 也不画');
            check(
                'HUD 真的多画了命令',
                $probe->list()->count() > $baselineOps,
                'ops=' . $probe->list()->count() . ' baseline=' . $baselineOps,
            );
            $drawn = $session->query('state')['inspectorDrawn'] ?? null;
            check('上屏帧记账前进了', $drawn !== null && (int) $drawn > 0, \json_encode($drawn));
        } finally {
            $session->close();
        }
    }

    public function testHudMarksPickMode(): void
    {
        [$app, $session] = $this->open();
        try {
            $node = $this->targetNode($app);
            check('找得到 Column 节点', $node > 0, 'node=' . $node);
            if ($node <= 0) {
                return;
            }

            $session->highlight($node);
            $session->inspect(['hud']);
            $before = $session->inspect(['hud'])['hud'] ?? [];
            check('默认不在拾取模式', ($before['picking'] ?? true) === false, \json_encode($before));

            $app->setDebugPickArmed(true);
            $session->pump(16.0);
            $after = $session->inspect(['hud'])['hud'] ?? [];
            check('宿主编排后 HUD 回执报拾取中', ($after['picking'] ?? false) === true, \json_encode($after));

            $app->setDebugPickArmed(false);
            $off = $session->inspect(['hud'])['hud'] ?? [];
            check('关掉后不再报拾取中', ($off['picking'] ?? true) === false, \json_encode($off));
        } finally {
            $session->close();
        }
    }

    public function testAllLayersOffDrawsNothing(): void
    {
        [$app, $session, $probe] = $this->open();
        try {
            $baseline = $this->pixels($probe);
            $baselineOps = $probe->list()->count();

            $node = $this->targetNode($app);
            $session->inspect(['bounds', 'box-model']);
            $session->highlight($node);
            $session->pump(16.0);
            $withOverlay = $this->pixels($probe);
            $overlayOps = $probe->list()->count();

            check('叠加打开了（对照）', $withOverlay !== $baseline, '叠加层压根没画出东西');
            check('叠加层真的多画了命令', $overlayOps > $baselineOps, "ops={$overlayOps} baseline={$baselineOps}");
            check('两层都开着', $session->query('state')['inspector'] === ['bounds', 'box-model'], \json_encode($session->query('state')['inspector'] ?? null));

            $session->inspect([]);
            $session->highlight(0);
            $closed = $session->inspect([]);
            $session->pump(32.0);
            $after = $session->inspect([]);

            check('全关的回执是空层', $closed['layers'] === []);
            check(
                '全关后像素回到基线',
                $this->pixels($probe) === $baseline,
                '没回到基线 —— 有一层关不掉',
            );
            // 全关之后**一帧都不该再记"上屏"**：`paint()` 说"我没画"，录制器就不该
            // 把这一帧记成 inspect 层的成功。这条比像素硬 —— 全透明的叠加在像素上
            // 看不出来，但"报了一次上屏"一定记在账上。
            check(
                '全关后不再报新的上屏帧',
                ($after['drawnFrame'] ?? null) === ($closed['drawnFrame'] ?? null),
                '上屏帧从 ' . \json_encode($closed['drawnFrame'] ?? null) . ' 前进到 ' . \json_encode($after['drawnFrame'] ?? null) . '：全关还在画',
            );
        } finally {
            $session->close();
        }
    }

    /** @return array{0: Application, 1: DebugSession, 2: SurfaceProbe} */
    private function open(): array
    {
        $constraints = BoxConstraints::tight(new Size((float) self::W, (float) self::H));
        $app = new InspectorFixtureApplication();
        $probe = new SurfaceProbe();
        $probe->resize(self::W, self::H, 1.0);
        $app->attach($probe, $constraints);
        $session = DebugSession::open($app, $constraints);

        // 走一帧让录制器给活树发号：`attach()` 的首帧发生在装录制器之前，
        // 那一帧的节点 `debugId` 还是 0（"号"是录制器发的，不是 Element 自带的）。
        $session->pump(0.0);

        return [$app, $session, $probe];
    }

    private function pixels(SurfaceProbe $probe): string
    {
        return \hash('sha256', (string) $probe->raster()?->toRgba());
    }

    private function targetNode(Application $app): int
    {
        $found = 0;
        $walk = function (Element $element) use (&$walk, &$found): void {
            if ($found > 0) {
                return;
            }
            if ($element->widget() instanceof Column) {
                $found = $element->debugId;
                return;
            }
            $element->visitChildren($walk);
        };

        $root = $app->root();
        if ($root !== null) {
            $walk($root);
        }

        return $found;
    }
}

/** @internal 判据夹具：一个 padding 与 border 都可量的盒子 */
final class InspectorFixtureApplication extends Application
{
    public function buildRoot(): Widget
    {
        // 边框放在 `Column` 上而不是按钮上：自绘 chrome 的控件（Button）在
        // `Element::syncBox()` 里会被 `withoutBoxVisual()` 剥掉视觉三字段，
        // 盒的 `boxStyle` 因此是 null——那上面量 border 永远是 0，判据恒绿。
        return Column::make()
            ->border(Color::fromRgb(0x30, 0x30, 0x30), 3.0)
            ->padding(EdgeInsets::all(20.0))
            ->children([
                Button::make('box'),
            ]);
    }
}
