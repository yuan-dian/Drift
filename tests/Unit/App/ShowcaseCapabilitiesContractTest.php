<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use App\Showcase\Routes\ShowcaseRoutes;
use App\Showcase\Stores\ButtonsStore;
use App\Showcase\ShowcaseCapabilities;
use App\Showcase\ShowcaseRoot;
use Drift\Async\Future;
use Drift\Platform\Dialog;
use Drift\Routing\NavEntry;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * showcase 两处"接线契约"的机器核对 —— 把位置参数错序与导航身份漂移变成红。
 *
 * ## ① 能力束的构造参数名与顺序
 *
 * `ShowcaseCapabilities` 是 6 个必填位置参数 + 1 个可选共享状态，其中 5 个必填是
 * `\Closure`（解释器对多余实参不报错、AOT 才校验个数）。把 notify 与 delay 写反、
 * 或少写一个闭包，两个构造点全是同形空闭包，一样全绿，真机点下去才 Fatal。
 * ⇒ 用反射直接钉住**构造器签名**（判据与实现同源的反面：这里读的是签名本身）。
 *
 * ## ② 导航 id 与 AutomationId 声明
 *
 * 探针已改为按 AutomationId 定位（`probe_win::click_by_id`）。`NavEntry.id` 是这个
 * id 的唯一来源，必须每页唯一、并在 `navButton()` 里原样声明下去。
 * ⇒ 反射读源码里的 `NavEntry(...)` 字面量 + `semanticsMeta(automationId: ...)` 接线，
 *   任一页漏声明或两页 id 撞车都红 —— 否则探针改一次文案就全断。
 */
final class ShowcaseCapabilitiesContractTest extends TestCase
{
    /** @return list<string> */
    private const CAP_NAMES = [
        'notify', 'delay', 'showModal', 'dialog', 'setCloseConfirm', 'fetchRows', 'buttons',
    ];

    #[Test]
    public function 能力束构造参数名与顺序固定(): void
    {
        $ctor = (new \ReflectionClass(ShowcaseCapabilities::class))->getConstructor();
        self::assertNotNull($ctor, 'ShowcaseCapabilities 必须有构造器');

        $actual = [];
        foreach ($ctor->getParameters() as $param) {
            $actual[] = $param->getName();
        }

        self::assertSame(
            self::CAP_NAMES,
            $actual,
            '能力束前 6 个是必填位置参数，顺序即契约：写反两个闭包解释器不报错、真机才 Fatal。'
            . ' 第 7 个是可选的共享状态；新增能力必须显式改这里（不是自动跟随）。'
        );
    }

    #[Test]
    public function 能力束每个参数的声明类型固定(): void
    {
        $ctor = (new \ReflectionClass(ShowcaseCapabilities::class))->getConstructor();
        self::assertNotNull($ctor);

        $types = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            $types[$param->getName()] = $type === null ? '' : (string) $type;
        }

        self::assertSame(
            [
                'notify' => 'Closure',
                'delay' => 'Closure',
                'showModal' => 'Closure',
                'dialog' => Dialog::class,
                'setCloseConfirm' => 'Closure',
                'fetchRows' => 'Closure',
                'buttons' => '?' . ButtonsStore::class,
            ],
            $types,
            'dialog 是有四种返回语义的端口接口，不闭包化；前五个是单向动作闭包。'
            . ' 第 7 个是可选共享状态：省略则新建，组合根传同一个实例即可跨页保活。'
        );
    }

    #[Test]
    public function 能力束具名方法的签名固定(): void
    {
        $expected = [
            'notify' => [['snackbar' => Snackbar::class], 'void'],
            'delay' => [['milliseconds' => 'int', 'callback' => 'Closure'], 'void'],
            'showModal' => [['modal' => Modal::class], 'void'],
            'dialog' => [[], Dialog::class],
            'setCloseConfirm' => [['enabled' => 'bool'], 'void'],
            'fetchRows' => [['page' => 'int'], Future::class],
            'buttons' => [[], ButtonsStore::class],
        ];

        foreach ($expected as $name => [$params, $return]) {
            self::assertTrue(method_exists(ShowcaseCapabilities::class, $name), "缺少具名方法 {$name}");
            $method = new \ReflectionMethod(ShowcaseCapabilities::class, $name);
            $actual = [];
            foreach ($method->getParameters() as $param) {
                $type = $param->getType();
                $actual[$param->getName()] = $type === null ? '' : (string) $type;
            }
            self::assertSame($params, $actual, "{$name} 的参数签名漂移");
            self::assertSame($return, (string) $method->getReturnType(), "{$name} 的返回类型漂移");
        }
    }

    #[Test]
    public function 每个导航项都有唯一稳定标识(): void
    {
        $ids = self::navIds();

        self::assertNotEmpty($ids, 'showcase 必须有导航项');
        self::assertSame(
            $ids,
            \array_values(\array_unique($ids)),
            'NavEntry.id 是 AutomationId 与 RouteTable 键的唯一来源，重复会让探针点错页且金标看似正常。'
        );
    }

    #[Test]
    public function 每个导航标识都声明成自动化标识(): void
    {
        $source = (string) \file_get_contents((string) (new \ReflectionClass(ShowcaseRoot::class))->getFileName());

        self::assertStringContainsString(
            'semanticsMeta(automationId: $entry->id)',
            $source,
            'navButton() 必须把 NavEntry.id 原样声明为 AutomationId —— 探针 click_by_id 按它定位。'
            . ' 删掉这行后探针全部 NotMeasured（码 2），但 PHP 单测无法发现，故此判据读源码。'
        );

        // 导航 id 的唯一真相源是页面类上的 #[Route] 注解（经 RouteCatalog 反射）。
        // 这里断言它们真的进了路由表 —— 注解写了但没登记到 CLASSES 也一样进不了侧栏。
        $ids = self::navIds();
        foreach ($ids as $id) {
            self::assertTrue(
                ShowcaseRoutes::catalog()->has($id),
                "导航 id {$id} 必须在 ShowcaseRoutes 的清单里登记（页面类缺 #[Route]？）"
            );
        }
    }

    #[Test]
    public function 导航项稳定标识是只读字段(): void
    {
        $prop = new \ReflectionProperty(NavEntry::class, 'id');
        self::assertTrue($prop->isReadOnly(), 'NavEntry.id 必须 readonly —— 切换期改身份等于没身份');
        self::assertSame('string', (string) $prop->getType());
    }

    /** @return list<string> 从路由表读 —— 与导航项的唯一真相源同源 */
    private static function navIds(): array
    {
        return ShowcaseRoutes::catalog()->ids();
    }
}
