<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use App\Showcase\Routes\ShowcaseRoutes;
use App\Showcase\ShowcaseApplication;
use App\Showcase\ShowcaseRoot;
use Drift\Dev\Smoke\App\FakeDialog;
use Drift\Routing\NavEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * showcase 装配契约：**漏注册工厂 ⇒ 红**。
 *
 * 页面依赖改成构造参数后，"写反 / 少写一个闭包"由类型系统当场挡下（构造期 Fatal），
 * 原先"6 个同型闭包 + 反射钉参数顺序"的契约不再需要。
 *
 * 剩下唯一**运行期才暴露**的风险是：某页构造器要参数，但组合根的工厂表漏了它
 * ⇒ 切到那页才 Fatal。本测试把这个缺口变成静态红。
 *
 * 另保留导航身份判据：`NavEntry.id` 是 AutomationId 与路由键的唯一来源。
 */
final class ShowcaseWiringContractTest extends TestCase
{
    #[Test]
    public function 每个需要依赖的页面都在工厂表里(): void
    {
        $factories = (new ShowcaseApplication(new FakeDialog()))->pageFactories();
        $descriptors = ShowcaseRoutes::catalog()->all();

        $pageClasses = [];
        $needArgs = [];
        foreach ($descriptors as $descriptor) {
            $class = $descriptor->pageClass;
            $pageClasses[] = $class;
            if (!\class_exists($class)) {
                self::fail("页面类不存在：{$class}（`#[Route]` 拼错或类名与文件不符）");
            }
            $ctor = (new \ReflectionClass($class))->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                $needArgs[] = $class;
            }
        }

        // 反向：工厂表的键必须是真实页面类（拼错类名会让工厂静默失效）。
        foreach (\array_keys($factories) as $key) {
            self::assertContains($key, $pageClasses, "工厂表的键不是已注册页面：{$key}");
        }

        // 正向：构造器要参数的页面必须被工厂覆盖。
        $missing = \array_values(\array_diff($needArgs, \array_keys($factories)));
        self::assertSame(
            [],
            $missing,
            '这些页构造器要参数却没注册工厂 ⇒ 切到那页 Fatal：' . \implode('、', $missing)
        );
    }

    #[Test]
    public function 导航标识唯一且都在清单里(): void
    {
        $ids = ShowcaseRoutes::catalog()->ids();

        self::assertNotEmpty($ids, 'showcase 必须有导航项');
        self::assertSame(
            $ids,
            \array_values(\array_unique($ids)),
            'NavEntry.id 是 AutomationId 与 RouteTable 键的唯一来源，重复会让探针点错页。'
        );

        foreach ($ids as $id) {
            self::assertTrue(
                ShowcaseRoutes::catalog()->has($id),
                "导航 id {$id} 必须在 ShowcaseRoutes 的清单里登记（页面类缺 #[Route]？）"
            );
        }
    }

    #[Test]
    public function 每个导航标识都声明成自动化标识(): void
    {
        $source = (string) \file_get_contents(
            (string) (new \ReflectionClass(ShowcaseRoot::class))->getFileName()
        );

        self::assertStringContainsString(
            'semanticsMeta(automationId: $entry->id)',
            $source,
            'navButton() 必须把 NavEntry.id 原样声明为 AutomationId —— 探针 click_by_id 按它定位。'
        );
    }

    #[Test]
    public function 导航项稳定标识是只读字段(): void
    {
        $prop = new \ReflectionProperty(NavEntry::class, 'id');
        self::assertTrue($prop->isReadOnly(), 'NavEntry.id 必须 readonly —— 切换期改身份等于没身份');
        self::assertSame('string', (string) $prop->getType());
    }
}
