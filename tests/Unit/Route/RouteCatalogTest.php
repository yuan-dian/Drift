<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Route;

use Drift\Route\Route;
use Drift\Route\RouteCatalog;
use Drift\Route\RouteEffect;
use Drift\Route\RouteEvent;
use Drift\Route\RouteModel;
use Drift\Route\RouteUpdate;
use Drift\Route\RouteView;
use Drift\Widget\BuildContext;
use Drift\Widget\IconName;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 启动期 bootstrap 的判据：注解读成描述符、错配在启动期就红、建页按类型接线。
 *
 * 这一层是唯一用反射的地方，所以错法必须在这里拦住，不能漏到用户点导航时。
 */
final class RouteCatalogTest extends TestCase
{
    #[Test]
    public function testDiscoverReadsAnnotationIntoDescriptor(): void
    {
        $catalog = RouteCatalog::discover([SampleRoute::class]);

        self::assertSame(['sample'], $catalog->ids());
        self::assertTrue($catalog->has('sample'));

        $descriptor = $catalog->get('sample');
        self::assertSame('示例页', $descriptor->title);
        self::assertSame(IconName::Bell, $descriptor->icon);
        self::assertSame(SampleRoute::class, $descriptor->viewClass);
        self::assertSame(SampleServices::class, $descriptor->servicesClass);
    }

    #[Test]
    public function testCreateWiresServicesFromContextWithoutPerPageFactory(): void
    {
        $context = new SampleContext('能力束');

        $view = RouteCatalog::discover([SampleRoute::class])->create('sample', $context);

        self::assertInstanceOf(SampleRoute::class, $view);
        self::assertSame($context, $view->services()->context(), '服务投影必须收到应用上下文');
        self::assertInstanceOf(SampleModel::class, $view->init());
    }

    #[Test]
    public function testMissingAnnotationFailsAtStartup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/缺 #\[Route\] 注解/');

        RouteCatalog::discover([NoAnnotationRoute::class]);
    }

    #[Test]
    public function testClassWithoutRouteViewContractFailsAtStartup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/没实现/');

        RouteCatalog::discover([NotAViewRoute::class]);
    }

    #[Test]
    public function testDuplicateIdFailsAtStartup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/路由 id 冲突/');

        RouteCatalog::discover([SampleRoute::class, DuplicateIdRoute::class]);
    }

    #[Test]
    public function testUnknownIdListsRegisteredOnes(): void
    {
        $catalog = RouteCatalog::discover([SampleRoute::class]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/已注册：sample/');

        $catalog->get('nope');
    }

    #[Test]
    public function testIconIsCarriedAsEnumNotString(): void
    {
        $descriptor = RouteCatalog::discover([SampleRoute::class])->get('sample');

        // 枚举进注解的价值：类型在描述符里就定死了，导航层拿不到"拼错的字符串"。
        self::assertSame(IconName::Bell, $descriptor->icon);
    }
}

final readonly class SampleContext
{
    public function __construct(public string $name)
    {
    }
}

final readonly class SampleServices
{
    public function __construct(private SampleContext $context)
    {
    }

    public function context(): SampleContext
    {
        return $this->context;
    }
}

final readonly class SampleModel implements RouteModel
{
    public function __construct(public int $n = 0)
    {
    }
}

#[Route(id: 'sample', title: '示例页', icon: IconName::Bell, services: SampleServices::class)]
final class SampleRoute implements RouteView
{
    public function __construct(private SampleServices $services)
    {
    }

    public function services(): SampleServices
    {
        return $this->services;
    }

    public function init(): RouteModel
    {
        return new SampleModel();
    }

    public function view(BuildContext $ctx, RouteModel $model, \Closure $dispatch): Widget
    {
        return Text::make('sample');
    }

    public function update(RouteModel $model, RouteEvent $event): RouteUpdate
    {
        return new RouteUpdate($model);
    }

    public function apply(RouteEffect $effect, \Closure $dispatch): void
    {
    }
}

final class NoAnnotationRoute implements RouteView
{
    public function init(): RouteModel
    {
        return new SampleModel();
    }

    public function view(BuildContext $ctx, RouteModel $model, \Closure $dispatch): Widget
    {
        return Text::make('x');
    }

    public function update(RouteModel $model, RouteEvent $event): RouteUpdate
    {
        return new RouteUpdate($model);
    }

    public function apply(RouteEffect $effect, \Closure $dispatch): void
    {
    }
}

#[Route(id: 'not-a-view', title: '不是页面', icon: IconName::Bell, services: SampleServices::class)]
final class NotAViewRoute
{
}

#[Route(id: 'sample', title: '撞 id', icon: IconName::Bell, services: SampleServices::class)]
final class DuplicateIdRoute implements RouteView
{
    public function __construct(private SampleServices $services)
    {
    }

    public function init(): RouteModel
    {
        return new SampleModel();
    }

    public function view(BuildContext $ctx, RouteModel $model, \Closure $dispatch): Widget
    {
        return Text::make('dup');
    }

    public function update(RouteModel $model, RouteEvent $event): RouteUpdate
    {
        return new RouteUpdate($model);
    }

    public function apply(RouteEffect $effect, \Closure $dispatch): void
    {
    }
}
