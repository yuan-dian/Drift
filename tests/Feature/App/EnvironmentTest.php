<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\AppScope;
use Drift\App\Application;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\App\EditApplication;
use Drift\Dev\Smoke\App\FakeClipboard;
use Drift\Dev\Smoke\App\FakeDialog;
use PHPUnit\Framework\TestCase;
use Drift\Errors;
use Drift\Widget\Environment;

/**
 * `Environment` 在真实 `Application` 上的接线（2026-11-22）。
 *
 * 只测**判据**，不测转发本身：`notify() → showSnackbar()` 这类一一对应由类型
 * 说话，逐条断言只是把接口再抄一遍。真正会静默出错的是这三件：
 *
 *   ① 缺端口时**什么都不说** —— 用户点了"选文件"没反应，日志里也没线索；
 *   ② 端口有**两个来源**（`Application::clipboard()` 一处、门面一处）——
 *      改了一边另一边不动；
 *   ③ "无边框窗"与"顶条画按钮"分成两个开关 —— 会写出"拖得动、关不掉"。
 */
final class EnvironmentTest extends TestCase
{
    private static function app(?AppScope $scope = null): EchoApplication
    {
        return new EchoApplication([], $scope);
    }

    public function testMissingPortAnswersEmptyAndSaysSoOnce(): void
    {
        Errors::reset();
        $env = self::app()->environment();

        self::assertNull($env->openFile('打开'));
        self::assertSame([], $env->openFiles('打开'));
        self::assertNull($env->saveFile('保存'));
        self::assertFalse($env->confirm('确认', '继续吗'));
        self::assertNull($env->clipboardText());

        // 两次调用只报一条：一遍一遍刷会把 ring 里别的线索挤掉
        $env->openFile('再打开');
        $env->openFiles('再打开');

        $hits = array_filter(
            Errors::recentDiagnostics(),
            static fn(string $line): bool => str_contains($line, '没有对话框实现'),
        );
        self::assertCount(1, $hits, '缺端口必须留一条诊断，且只在第一次留');
    }

    public function testPortsComeFromScopeSoApplicationAndEnvironmentAgree(): void
    {
        $dialog = new FakeDialog();
        $dialog->nextPath = 'D:/tmp/合同.md';
        $env = self::app(AppScope::make()->setDialog($dialog))->environment();

        self::assertSame('D:/tmp/合同.md', $env->openFile('打开一个文件'));
        self::assertSame(1, $dialog->openCalls, '门面必须转发到作用域里那个端口');
    }

    public function testClipboardFacadeUsesTheSameOverrideAsTheEditingRing(): void
    {
        $clip = new FakeClipboard();
        $app = new EditApplication();
        $app->clip = $clip;

        // scope 里没放端口 ⇒ 门面只有走 Application::clipboard() 覆写点才读得到。
        $env = $app->environment();
        $env->setClipboardText('门面写入');

        self::assertSame('门面写入', $clip->text, '写入必须落到应用覆写的端口');
        self::assertSame('门面写入', $env->clipboardText(), '读写同一端口');
    }

    public function testWindowDragSupportAndStartAreTheSameSwitch(): void
    {
        $app = self::app();
        $env = $app->environment();

        // 默认：有原生标题栏 ⇒ 顶条不画自绘按钮，拖动也无事发生
        self::assertFalse($env->supportsWindowDrag());
        $env->startWindowDrag();

        $starts = 0;
        $app->environment()->setWindowDrag(static function () use (&$starts): void {
            $starts++;
        });

        self::assertTrue($env->supportsWindowDrag());
        $env->startWindowDrag();
        self::assertSame(1, $starts);

        // `buildRoot()` 之后才注册也能生效：门面是同一个实例，不是构造期快照
        self::assertInstanceOf(Environment::class, $env);
        self::assertSame($env, $app->environment());
    }

    /** 门面不暴露帧内方法 —— Store 拿它也摸不到 `frame()` / `attach()` */
    public function testEnvironmentDoesNotLeakTheApplication(): void
    {
        $env = self::app()->environment();

        self::assertFalse(method_exists($env, 'frame'));
        self::assertFalse(method_exists($env, 'attach'));
        self::assertFalse(method_exists($env, 'dispatch'));
        self::assertNotInstanceOf(Application::class, $env);
    }
}
