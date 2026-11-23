<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\AppScope;
use Drift\App\AppShell;
use Drift\App\Application;
use Drift\App\InputHost;
use Drift\App\OverlayHost;
use Drift\App\PumpHostHost;
use Drift\App\TextEditingHost;
use Drift\Dev\Smoke\App\ThemeApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\ThemeChangedEvent;
use Drift\Widget\FocusHost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 拆分边界：Application 是声明与兼容门面，每窗口运行时住 AppShell。
 *
 * 结构判据先于行为判据：没有它，下一次“顺手在 Application 里加个字段”会把
 * 根树 / 帧循环悄悄搬回门面，而所有功能测试仍然绿。
 */
final class ApplicationShellSplitTest extends TestCase
{
    #[Test]
    public function 运行时只在壳上(): void
    {
        $app = new \ReflectionClass(Application::class);
        $shell = new \ReflectionClass(AppShell::class);

        self::assertTrue($shell->isFinal());
        foreach ([
            TextEditingHost::class,
            InputHost::class,
            OverlayHost::class,
            PumpHostHost::class,
            FocusHost::class,
        ] as $interface) {
            self::assertTrue($shell->implementsInterface($interface), $interface);
            self::assertFalse($app->implementsInterface($interface), $interface);
        }

        foreach (['root', 'frame', 'focus', 'pipeline'] as $property) {
            self::assertFalse($app->hasProperty($property), 'Application 不应再持 ' . $property);
            self::assertTrue($shell->hasProperty($property), 'AppShell 应持 ' . $property);
        }

        // 门面只允许有一个字段（壳本身）。想往门面加缓存字段 = 运行时在回流。
        $fields = [];
        foreach ($app->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === Application::class) {
                $fields[] = $property->getName();
            }
        }
        self::assertSame(['shell'], $fields, 'Application 只该持壳一个字段');

        self::assertTrue($app->hasMethod('frame'));
        self::assertTrue($shell->hasMethod('frame'));
    }

    #[Test]
    public function 作用域只收服务不收运行时(): void
    {
        $scope = new \ReflectionClass(AppScope::class);
        $names = [];
        foreach ($scope->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === AppScope::class) {
                $names[] = $method->getName();
            }
        }

        foreach (['theme', 'metrics', 'asyncHttp', 'accelerators', 'clipboard', 'taskHost', 'io', 'dispatch'] as $expected) {
            self::assertContains($expected, $names);
        }
        foreach (['frame', 'attach', 'dispatchInput', 'root', 'focus', 'showMenu', 'showModal', 'overlays'] as $forbidden) {
            self::assertNotContains($forbidden, $names, 'AppScope 不应暴露 ' . $forbidden);
        }
    }

    #[Test]
    public function 应用覆写仍从壳生效(): void
    {
        $app = new ThemeApplication();
        $surface = new SurfaceProbe();
        $surface->resize(320, 240, 1.0);
        $app->attach($surface, BoxConstraints::tight(new Size(320.0, 240.0)));

        self::assertSame($app->theme(), $app->root()?->context()->theme());

        $before = $app->theme();
        $app->dispatchInput(new ThemeChangedEvent());
        self::assertNotSame($before, $app->theme(), '主题事件必须先走应用钩子');
        self::assertSame($app->theme(), $app->root()?->context()->theme());
    }
}
