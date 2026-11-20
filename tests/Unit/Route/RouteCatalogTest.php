<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Route;

use Drift\Route\Route;
use Drift\Route\RouteCatalog;
use Drift\Widget\BuildContext;
use Drift\Widget\IconName;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 启动期 bootstrap 的判据：注解读成导航描述符、错配在启动期就红。
 *
 * 这一层是唯一用反射的地方，所以错法必须在这里拦住，不能漏到用户点导航时。
 * ★ 路由层只产元数据（id/title/icon/pageClass），不碰状态管理：
 *   `create()`/`services` 已移除，页面内部用什么方式管状态是它自己的选择。
 */
final class RouteCatalogTest extends TestCase
{
    #[Test]
    public function testDiscoverReadsAnnotationIntoDescriptor(): void
    {
        $catalog = RouteCatalog::discover([SamplePage::class]);

        self::assertSame(['sample'], $catalog->ids());
        self::assertTrue($catalog->has('sample'));

        $descriptor = $catalog->get('sample');
        self::assertSame('sample', $descriptor->id);
        self::assertSame('示例页', $descriptor->title);
        self::assertSame(IconName::Bell, $descriptor->icon);
        self::assertSame(SamplePage::class, $descriptor->pageClass);
    }

    #[Test]
    public function testMissingAnnotationFailsAtStartup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/缺 #\[Route\] 注解/');

        RouteCatalog::discover([NoAnnotationPage::class]);
    }

    #[Test]
    public function testClassThatIsNotWidgetFailsAtStartup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/不是.*Widget/');

        RouteCatalog::discover([NotAWidget::class]);
    }

    #[Test]
    public function testDuplicateIdFailsAtStartup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/路由 id 冲突/');

        RouteCatalog::discover([SamplePage::class, DuplicateIdPage::class]);
    }

    #[Test]
    public function testUnknownIdListsRegisteredOnes(): void
    {
        $catalog = RouteCatalog::discover([SamplePage::class]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/已注册：sample/');

        $catalog->get('nope');
    }

    #[Test]
    public function testIconIsCarriedAsEnumNotString(): void
    {
        $descriptor = RouteCatalog::discover([SamplePage::class])->get('sample');

        // 枚举进注解的价值：类型在描述符里就定死了，导航层拿不到"拼错的字符串"。
        self::assertSame(IconName::Bell, $descriptor->icon);
    }

    #[Test]
    public function testPageClassIsConstructibleFromContext(): void
    {
        // 路由层不负责造页，但描述符给出的 pageClass 必须能用同一个上下文构造
        // —— 这正是 ShowcaseNav 交给 PageRegistry 的建页方式。
        $context = new \stdClass();
        $descriptor = RouteCatalog::discover([SamplePage::class])->get('sample');
        $class = $descriptor->pageClass;

        self::assertInstanceOf(Widget::class, new $class($context));
    }
}

#[Route(id: 'sample', title: '示例页', icon: IconName::Bell)]
final class SamplePage extends StatelessWidget
{
    public function __construct(public object $context)
    {
        parent::__construct();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Text::make('sample');
    }
}

final class NoAnnotationPage extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return Text::make('x');
    }
}

#[Route(id: 'not-a-widget', title: '不是页面', icon: IconName::Bell)]
final class NotAWidget
{
}

#[Route(id: 'sample', title: '撞 id', icon: IconName::Bell)]
final class DuplicateIdPage extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return Text::make('dup');
    }
}
