<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use App\Showcase\Pages\TablePage;
use App\Showcase\ShowcaseApplication;
use Drift\Dev\Bench\FrameTaxApp;
use Drift\Dev\Smoke\App\FakeEnvironment;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\TableState;
use Drift\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;

/**
 * showcase 表格页的 `Ctrl+C` → 剪贴板接线判据。
 *
 * ★ 验证的是**真实消费者**：`Table::onCopy()` 钩子 + `Environment::setClipboardText()`
 *   两个零消费者语义 token，接起来后由真实键盘事件驱动（不是直接调回调）。
 * ★ `TableState` 算 TSV（列 `copyText()` 优先、否则 `field()`），本页只决定去向；
 *   所以这里断言 TSV 的形状（tab 分格、含 `field()` 的中文值），证明链路走通。
 */
final class ShowcaseTableClipboardTest extends SmokeTestCase
{
    private float $clock = 20.0;

    private function mount(?FakeEnvironment $env): FrameTaxApp
    {
        $page = new TablePage($env);
        $tight = Fixtures::tight();
        $app = new FrameTaxApp(static fn(): Widget => $page->host());
        $surface = new SurfaceProbe();
        $surface->resize(900, 640, 1.0);
        $app->attach($surface, $tight);
        $app->frame(16.0, $tight);

        return $app;
    }

    private function tick(FrameTaxApp $app): void
    {
        $this->clock += 1.0;
        $app->frame($this->clock, Fixtures::tight());
    }

    #[Test]
    public function 注入门面时CtrlC把选区写进剪贴板(): void
    {
        $env = new FakeEnvironment();
        $app = $this->mount($env);

        // 焦点进表格（TablePage 有标题 / 说明 / 两个工具行在前 ⇒ 要 focusNext 数次
        // 才轮到 TableState），Ctrl+A 全选，Ctrl+C 复制。
        for ($i = 0; $i < 12; $i++) {
            $app->focus()?->focusNext();
            $this->tick($app);
            if ($app->focus()?->focusedState() instanceof TableState) {
                break;
            }
        }
        self::assertInstanceOf(TableState::class, $app->focus()?->focusedState(), '焦点没落到表格');
        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('A')), 1000.0);
        $this->tick($app);
        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('C')), 1001.0);
        $this->tick($app);

        $copied = $env->clipboard;
        check(
            'showcase · 表格 Ctrl+C ⇒ 选区 TSV 写进 Environment 剪贴板',
            $copied !== '' && \str_contains($copied, "\t") && \str_contains($copied, "\n"),
            'clipboard=' . \json_encode(\substr($copied, 0, 80)),
        );
        check(
            'showcase · TSV 含 field() 列的真实值（用户 1）',
            \str_contains($copied, '用户 1'),
            'clipboard=' . \json_encode(\substr($copied, 0, 80)),
        );
        check(
            'showcase · 复制经门面而非直连平台（hasText 同步为真）',
            $env->hasClipboardText(),
        );

        $this->flushChecks();
    }

    #[Test]
    public function 组合根工厂注入门面而默认构造不注入(): void
    {
        $factories = (new ShowcaseApplication())->pageFactories();
        $make = $factories[TablePage::class] ?? null;

        check(
            'showcase 工厂表注册了 TablePage（真机走注入路径）',
            $make !== null,
        );
        if ($make === null) {
            $this->flushChecks();
            return;
        }

        $wired = $make();
        self::assertInstanceOf(TablePage::class, $wired, 'TablePage 工厂应直接返回页面实例');
        $ref = new \ReflectionProperty(TablePage::class, 'clipboard');
        $injected = $ref->getValue($wired);
        check(
            '工厂造出的 TablePage 拿到了 Environment 门面',
            $injected instanceof \Drift\Widget\Environment,
            '实际=' . \get_debug_type($injected),
        );
        $bare = new TablePage();
        check(
            '默认构造（golden / 无头）不注入 ⇒ onCopy 不挂载、像素不变',
            $ref->getValue($bare) === null,
        );

        $this->flushChecks();
    }
}
