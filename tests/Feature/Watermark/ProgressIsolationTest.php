<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Store\Phase;
use App\Watermark\Store\WatermarkStore;
use App\Watermark\Widgets\StatusBar;
use App\Watermark\Widgets\TaskCard;
use App\Watermark\Widgets\UploadCard;
use Drift\Dev\Smoke\App\FakeEnvironment;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Cell;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\Progress;
use Drift\Widget\StatefulElement;
use Drift\Widget\Widget;

/**
 * 上传进度 tick 一百次，**别的段一次都不重建**。
 *
 * ## 为什么这条必须存在
 *
 * 状态从 `Subject` + `notifyChanged()` 迁到 `Cell` + `Bind` 的**唯一真收益**就是
 * 这个粒度：一次广播会唤醒全部订阅者，再由各自的切片门控把没变的挡下来（挡下来
 * 也算跑了一遍 select 闭包 + 一次比较）；格子只唤醒读它的那一段。
 *
 * 这个收益在别处**看不出来**：界面一样对、测试一样绿、`probe` 一样 5/5。退化的
 * 症状只有"慢"和"上传时输入框卡顿"，都不报错。所以它需要一条自己的量具。
 *
 * ## 判据三条
 *
 * | 用例 | 钉住的不变式 |
 * |---|---|
 * | ① 进度格变了 ⇒ 进度段标脏 | 订阅是活的（全 0 脏 = 订阅断了，不是"更快"） |
 * | ② 一百次 tick 里，任务卡与状态栏**零**标脏 | 粒度真的收窄到那一段 |
 * | ③ 退出上传档 ⇒ 进度条消失 | 那段响应的是 `phase`，不是"永远画着" |
 *
 * ★ 表格式的"每轮断言一次"会变成 300 条噪音，这里累计违规再报一条：失败消息
 *   里带前三次的实测数，够定位。
 */
final class ProgressIsolationTest extends SmokeTestCase
{
    private const TICKS = 100;

    public function testProgressTicksDoNotRebuildOtherSections(): void
    {
        $store = new WatermarkStore(null, 'http://127.0.0.1:1');
        $ctx = smokeContext();

        $upload = self::mount(UploadCard::make($store, new FakeEnvironment()), $ctx);
        $task = self::mount(TaskCard::make($store), $ctx);
        $bar = self::mount(StatusBar::make($store), $ctx);

        // 首帧收尾：`TextField` 这类控件 mount 时就标一次脏（草稿初始化），
        // 不清掉的话它会被算进后面每一次统计，看着像"任务卡也在重建"。
        self::settle($upload);
        self::settle($task);
        self::settle($bar);

        // 进度条只在「上传中」那一档存在，也只在那一档读进度格 ⇒ 先把 phase 推过去。
        $this->writeCell($store, 'phase', Phase::Uploading);
        check(
            '进度隔离 · 进入上传档后进度段标脏',
            \count(self::dirty($upload)) === 1,
            $this->describe($upload, $task, $bar)
        );
        // 状态栏只订 `statusLine` 一格，任何 phase 切换都不该碰它。
        check(
            '进度隔离 · 状态栏不跟 phase 走',
            \count(self::dirty($bar)) === 0,
            $this->describe($upload, $task, $bar)
        );

        // ★ 任务卡的提交按钮段**会**跟着脏：它读 `isSubmitting()`，那就是读 phase 格。
        //   这是"订阅粒度 = 格子粒度"的已知代价 —— phase 一次业务流只切 6~8 次，
        //   与下面 tick 一百次的量级不同。清掉它，tick 循环才有干净的基线。
        self::rebuildDirty($upload);
        self::rebuildDirty($task);
        check(
            '进度隔离 · 上传档下画出了进度条',
            self::hasProgress($upload),
            '没有 Progress ⇒ 进度段走进了"不是上传中"那条分支'
        );

        $violations = [];
        for ($i = 1; $i <= self::TICKS; $i++) {
            $this->writeCell($store, 'uploadFraction', (float) $i / (float) self::TICKS);

            $inUpload = \count(self::dirty($upload));
            $inTask = \count(self::dirty($task));
            $inBar = \count(self::dirty($bar));

            if ($inUpload !== 1 || $inTask !== 0 || $inBar !== 0) {
                $violations[] = \sprintf('第 %d 次：upload=%d task=%d bar=%d', $i, $inUpload, $inTask, $inBar);
            }

            // 先记录再清：不清的话下一轮读到的是上一轮的残留（本用例第一版就栽在这）。
            self::rebuildDirty($upload);
            self::rebuildDirty($task);
            self::rebuildDirty($bar);
        }

        check(
            '进度隔离 · ' . self::TICKS . ' 次 tick 里只有进度段重建（另两棵树零标脏）',
            $violations === [],
            \implode('; ', \array_slice($violations, 0, 3))
        );
        check(
            '进度隔离 · tick 完进度条还在（订阅没被中途退掉）',
            self::hasProgress($upload),
            '进度条消失了 ⇒ 重建时走了别的分支'
        );

        // 反向自检：phase 离开上传档 ⇒ 那一段必须重建，且进度条消失。
        // 没有这一条，上面的"零重建"无法与"订阅断了"区分。
        $this->writeCell($store, 'phase', Phase::Idle);
        check(
            '进度隔离 · 退出上传档后进度段标脏',
            \count(self::dirty($upload)) === 1,
            $this->describe($upload, $task, $bar)
        );
        self::rebuildDirty($upload);
        check(
            '进度隔离 · 退出上传档后进度条消失',
            !self::hasProgress($upload),
            '还画着 ⇒ 进度段没有跟着 phase 走'
        );

        $upload->unmount();
        $task->unmount();
        $bar->unmount();
        $this->flushChecks();
    }

    private static function mount(Widget $widget, BuildContext $ctx): Element
    {
        $element = $widget->createElement();
        $element->mount(null, $ctx);

        return $element;
    }

    /**
     * 树里标了脏的 `StatefulElement`。
     *
     * ★ 只认 `StatefulElement`：`needsBuild()` 在 `State` 上，无状态 element 没有
     *   这件事。`Bind` 的 element 是 Stateful 的，进度段就在其中。
     *
     * @return list<StatefulElement>
     */
    private static function dirty(Element $root): array
    {
        $out = [];
        $walk = null;
        $walk = static function (Element $e) use (&$walk, &$out): void {
            if ($e instanceof StatefulElement && $e->state()?->needsBuild() === true) {
                $out[] = $e;
            }
            $e->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /**
     * 重建标脏的 element。
     *
     * ★ 不递归重建"脏的子"：本用例里标脏的都是平行的 `Bind`，没有父子同时脏的
     *   情况；真出现了，父先重建会让子的旧实例脱离树，再 rebuild 它没有意义。
     */
    private static function rebuildDirty(Element $root): void
    {
        foreach (self::dirty($root) as $element) {
            $element->rebuild();
        }
    }

    /**
     * 把一棵树里的脏 element 重建成"静态"状态（最多五轮，防死循环）。
     *
     * ★ 只用于首帧收尾：mount 时若干控件（`TextField` 的草稿、`Spinner` 的相位）
     *   会标一次脏，那是正常初始化，不是本用例要量的东西。
     */
    private static function settle(Element $root): void
    {
        for ($i = 0; $i < 5 && self::dirty($root) !== []; $i++) {
            self::rebuildDirty($root);
        }
    }

    private static function hasProgress(Element $root): bool
    {
        return $root->findElement(static fn (Element $e): bool => $e->widget() instanceof Progress) !== null;
    }

    /** 私有格子 + 反射：真正的写点在异步流水线里，为一条 UI 判据搭假后台不值当。 */
    private function writeCell(WatermarkStore $store, string $field, mixed $value): void
    {
        $cell = (new \ReflectionProperty(WatermarkStore::class, $field))->getValue($store);
        self::assertInstanceOf(Cell::class, $cell);

        $cell->set($value);
    }

    private function describe(Element $upload, Element $task, Element $bar): string
    {
        return \sprintf(
            'upload=%d task=%d bar=%d',
            \count(self::dirty($upload)),
            \count(self::dirty($task)),
            \count(self::dirty($bar)),
        );
    }
}
