<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use App\Showcase\Routes\Snackbar\NotifyEffect;
use App\Showcase\Routes\Snackbar\SnackbarEvent;
use App\Showcase\Routes\Snackbar\SnackbarIntent;
use App\Showcase\Routes\Snackbar\SnackbarModel;
use App\Showcase\Routes\Snackbar\SnackbarServices;
use App\Showcase\Routes\SnackbarReducer;
use App\Showcase\ShowcaseCapabilities;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\FakeDialog;
use Drift\Reducer\ReducerUpdate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Snackbar 页的 `update()` 判据：纯函数、只产出值、不改入参。
 *
 * ★ 这里**不挂载 UI、不建窗口**：`update()` 不碰服务，所以"点了删除会产生
 *   带撤销的 effect"这件事可以在纯 PHP 里断言。迁移前这段逻辑藏在 `State` 的
 *   私有方法里，只能靠点按钮 + 抓快照来验证。
 */
final class SnackbarReducerTest extends TestCase
{
    private function route(): SnackbarReducer
    {
        $caps = new ShowcaseCapabilities(
            static function (): void {},
            static function (): void {},
            static function (): void {},
            new FakeDialog(),
            static function (): void {},
            static fn (): Future => Future::pending(),
        );

        return new SnackbarReducer(new SnackbarServices($caps));
    }

    #[Test]
    public function testSimpleNoticeKeepsModelIdentityAndCarriesMessage(): void
    {
        $route = $this->route();
        $model = new SnackbarModel(3);

        $update = $route->update($model, new SnackbarEvent(SnackbarIntent::Saved));

        self::assertSame($model, $update->model, '没有状态变化的动作不该造新 model');
        self::assertCount(1, $update->effects);
        $effect = $update->effects[0];
        self::assertInstanceOf(NotifyEffect::class, $effect);
        self::assertSame('已保存到本地', $effect->message);
        self::assertNull($effect->durationMs, '默认时长交给框架，不在页面里写死 4000');
        self::assertSame('', $effect->actionLabel);
    }

    #[Test]
    public function testUndoableNoticeCarriesActionLabel(): void
    {
        $route = $this->route();

        $update = $route->update(new SnackbarModel(), new SnackbarEvent(SnackbarIntent::Undoable));

        $effect = $update->effects[0];
        self::assertInstanceOf(NotifyEffect::class, $effect);
        self::assertSame('已删除「草稿.docx」', $effect->message);
        self::assertSame('撤销', $effect->actionLabel);
    }

    #[Test]
    public function testBurstPushesThreeInOrder(): void
    {
        $route = $this->route();

        $update = $route->update(new SnackbarModel(), new SnackbarEvent(SnackbarIntent::Burst));

        self::assertCount(3, $update->effects, '推三条就是三个 effect，顺序即入队顺序');
        $messages = [];
        foreach ($update->effects as $effect) {
            self::assertInstanceOf(NotifyEffect::class, $effect);
            $messages[] = $effect->message;
        }
        self::assertSame(['第 1 条：立刻显示', '第 2 条：排队等待', '第 3 条：排在最后'], $messages);
    }

    #[Test]
    public function testPersistentErrorUsesZeroDuration(): void
    {
        $route = $this->route();

        $update = $route->update(new SnackbarModel(), new SnackbarEvent(SnackbarIntent::PersistentError));

        $effect = $update->effects[0];
        self::assertInstanceOf(NotifyEffect::class, $effect);
        self::assertSame(0, $effect->durationMs, 'duration(0) = 不自动消失');
        self::assertSame('知道了', $effect->actionLabel);
    }

    #[Test]
    public function testUndoClickedIncrementsFromGivenModelWithoutMutatingIt(): void
    {
        $route = $this->route();
        $model = new SnackbarModel(2);

        $update = $route->update($model, new SnackbarEvent(SnackbarIntent::UndoClicked));

        self::assertNotSame($model, $update->model, '状态变了就必须是新对象');
        self::assertInstanceOf(SnackbarModel::class, $update->model);
        self::assertSame(3, $update->model->undone);
        self::assertSame(2, $model->undone, '入参 model 不可变');
        self::assertSame([], $update->effects, '计数不需要副作用');
    }

    #[Test]
    public function testUpdateIsPureAcrossRepeatedCalls(): void
    {
        $route = $this->route();
        $model = new SnackbarModel(0);

        $first = $route->update($model, new SnackbarEvent(SnackbarIntent::UndoClicked));
        $second = $route->update($model, new SnackbarEvent(SnackbarIntent::UndoClicked));

        self::assertInstanceOf(ReducerUpdate::class, $first);
        self::assertSame(
            $first->model->undone ?? null,
            $second->model->undone ?? null,
            '同一个入参重复算必须得到同一个结果',
        );
    }
}
