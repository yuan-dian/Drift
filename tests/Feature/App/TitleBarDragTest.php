<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\TitleBarLabApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\Text;

/**
 * 顶条当标题栏（P1-Stage 1b 无边框窗）：`windowDrag()` 的**让位规则**。
 *
 * ## 失败形态是静默吞掉点击
 *
 * 顶条整条声明 `windowDrag()` 之后，里面任何东西按下去都可能被当成"拖窗" ⇒
 * 按钮**点了没反应**，而且不崩溃、不写日志。所以判据必须两边都钉住：
 * **该拖的真拖**（标题文字 / 顶条空白）与**不该拖的真不拖**（按钮 / 自绘控件 /
 * 顶条外的内容区）。只验"点空白能拖"发现不了按钮被吞。
 *
 * ## 三条让位判据，对应三类"按下归属"
 *
 * | 命中点 | 归属 | 判据 |
 * |---|---|---|
 * | 标题文字 / 顶条空白 | 顶条（拖窗）| `dragStarts` 增长 |
 * | `Activatable`（按钮）| 控件自己（激活）| `dragStarts` **不**动 + 命令照发 |
 * | `pointer()` 声明者 | 控件自己（自绘）| `dragStarts` **不**动 + 回调收到 |
 *
 * ★ 与 `PressClaimTest` 的分工：那边判 `ownsPress` 在**子树内**谁接管，
 *   这边判 `windowDrag` 沿父链的**边界**（顶条子树外一律不生效）。
 * ★ 独立化改动：smoke 里 `dragStarts` 跨用例累加，只能断言"还是 1"；
 *   这里每个用例各开新 app ⇒ 断言 `=== 0` 或 `=== 1`，精确且可证伪。
 */
final class TitleBarDragTest extends SmokeTestCase
{
    private const W = 320.0;

    private const H = 400.0;

    private static function open(): TitleBarLabApplication
    {
        $app = new TitleBarLabApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(self::W, self::H)));

        return $app;
    }

    private static function centerOf(?Element $el): array
    {
        $box = $el?->effectiveBox();
        if ($box === null || $box->size() === null) {
            return [0.0, 0.0];
        }
        [$x, $y] = boxOrigin($box);

        return [$x + $box->size()->width / 2.0, $y + $box->size()->height / 2.0];
    }

    private static function textWith(TitleBarLabApplication $app, string $content): ?Element
    {
        return findElement(
            $app->root(),
            static fn(Element $e): bool => $e->widget() instanceof Text
                && $e->widget()->content() === $content
        );
    }

    private static function actionButton(TitleBarLabApplication $app): ?Element
    {
        return findElement($app->root(), static fn(Element $e): bool => $e->widget() instanceof Button);
    }

    /** 顶条里的 `pointer()` 声明者（自绘控件） */
    private static function paintedWidget(TitleBarLabApplication $app): ?Element
    {
        return findElement(
            $app->root(),
            static fn(Element $e): bool => $e->widget()->pointerOwnsPressValue()
        );
    }

    /**
     * 点标题文字 ⇒ 拖窗；但**不捕获指针**、**不改焦点**。
     *
     * ★ 三条各有独立理由：
     *   ① 拖窗本身（标题栏语义 —— 真实窗口上按标题就是拖窗，文字不是例外）；
     *   ② 不捕获：拖窗的鼠标循环归**平台**（系统模态循环），框架再捕获一次 ⇒
     *      拖动结束后 `$captureNode` 停在死节点上，后续点击全废；
     *   ③ 不改焦点：抓标题栏不是"选中"。
     * ★ ②③ 都是"多了就出事"的断言 —— 只验 ① 会奖励"顺手也捕获/也聚焦"的实现。
     */
    public function testTitleTextStartsDragWithoutCaptureOrFocus(): void
    {
        $app = self::open();
        [$x, $y] = self::centerOf(self::textWith($app, '用户管理'));

        $app->dispatchInput(PointerEvent::down($x, $y));
        check(
            'TitleBar · 点标题文字 ⇒ 开始拖窗（沿父链找到顶条的 windowDrag 声明）',
            $app->dragStarts === 1,
            'dragStarts=' . $app->dragStarts
        );
        check(
            'TitleBar · 拖窗**不捕获指针**（拖窗的鼠标循环归平台，框架再捕获会锁死后续事件）',
            $app->captureNode() === null,
            'captured=' . ($app->captureNode() === null ? 'no' : 'yes')
        );
        check(
            'TitleBar · 拖窗**不改焦点**（抓标题栏不是"选中"）',
            $app->focusedElement() === null,
            'focused=' . ($app->focusedElement() === null ? 'none' : 'other')
        );
    }

    /**
     * 一次按下只拖一次：`move` / `up` 不重复触发。
     *
     * ★ 重复触发的后果是每帧重开一次**系统模态移动循环**（窗口跟手就抖，
     *   松手也停不下来），而界面上看不出异常 —— 只能靠计数钉住。
     */
    public function testDragStartsOncePerPress(): void
    {
        $app = self::open();
        [$x, $y] = self::centerOf(self::textWith($app, '用户管理'));

        $app->dispatchInput(PointerEvent::down($x, $y));
        $app->dispatchInput(PointerEvent::move($x + 30.0, $y + 12.0));
        $app->dispatchInput(PointerEvent::up($x + 30.0, $y + 12.0));
        check(
            'TitleBar · 一次按下只拖一次（move / up 不重复触发）',
            $app->dragStarts === 1,
            'dragStarts=' . $app->dragStarts
        );
    }

    /**
     * 点顶条里的**按钮** ⇒ 不拖窗，命令照发（`Activatable` 让位）。
     *
     * ★ 这条是"静默吞点击"的直接守卫：`dragStarts === 0` 与 `actionPresses === 1`
     *   必须同时成立 —— 只验前者会奖励"什么都不做"。
     */
    public function testButtonInTitleBarDoesNotDrag(): void
    {
        $app = self::open();
        [$x, $y] = self::centerOf(self::actionButton($app));

        $app->dispatchInput(PointerEvent::down($x, $y));
        check(
            'TitleBar · 点顶条按钮 ⇒ 不拖窗且命令照发（可激活控件让位）',
            $app->dragStarts === 0 && $app->actionPresses === 1,
            'dragStarts=' . $app->dragStarts . ' actionPresses=' . $app->actionPresses
        );
        $app->dispatchInput(PointerEvent::up($x, $y));
    }

    /**
     * 点顶条里的**自绘控件**（`pointer()` 声明者）⇒ 不拖窗（第三让位判据）。
     *
     * ★ 与上一条不是重复：按钮靠 `Activatable` 让位，自绘控件**没有**激活语义，
     *   只能靠"它声明了 `pointer()`"让位。漏了这一类 ⇒ 自绘的进度条 / 旋钮
     *   在顶条里全部失效。
     */
    public function testPointerDeclarerInTitleBarDoesNotDrag(): void
    {
        $app = self::open();
        [$x, $y] = self::centerOf(self::paintedWidget($app));

        $app->dispatchInput(PointerEvent::down($x, $y));
        check(
            'TitleBar · 点顶条里的 pointer() 声明者 ⇒ 不拖窗（自绘控件让位）',
            $app->dragStarts === 0 && $app->paintedLog === ['down'],
            'dragStarts=' . $app->dragStarts . ' paintedLog=' . \implode(',', $app->paintedLog)
        );
        $app->dispatchInput(PointerEvent::up($x, $y));
    }

    /**
     * 反例：点在顶条**外面**（内容区）⇒ 不拖窗（声明只在顶条子树内生效）。
     *
     * ★ 这条是**边界**判据，不是"再验一遍不拖"：它挡的是 `windowDragOwner()`
     *   用错起点（忽略命中链、从根**全局找**声明者）。
     * ★★ 它的可证伪性有个前提必须写清：`windowDragOwner()` 找到节点之后，
     *   `beginWindowDrag()` 还会**再查一次** `windowDragHandlerValue()`（拿不到就
     *   `return`）—— 两层防御叠着，所以"沿父链一路找"这类变异**打不红它**
     *   （内容区往上到根也没有声明者，本来就返回 `null`）。实测能打红它的只有
     *   "全局找第一个声明者"那一类变异（第 29 批 M8）。
     *   记这一笔是因为：变异不红时先数**防御层数**，别急着改断言。
     */
    public function testOutsideTitleBarDoesNotDrag(): void
    {
        $app = self::open();
        [$x, $y] = self::centerOf(self::textWith($app, '内容'));

        $app->dispatchInput(PointerEvent::down($x, $y));
        check(
            'TitleBar · 反例：点在顶条外 ⇒ 不拖窗（声明只在顶条子树内生效）',
            $app->dragStarts === 0,
            'dragStarts=' . $app->dragStarts
        );
        $app->dispatchInput(PointerEvent::up($x, $y));
    }
}
