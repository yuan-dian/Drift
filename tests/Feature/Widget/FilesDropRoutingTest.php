<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\FilesDropRoutingApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\FilesDroppedEvent;
use Drift\Layout\RenderBox;
use Drift\Widget\Element;
use Drift\Widget\Text;

/**
 * 拖放路由的纪律（A5-②，§27）：命中、父链冒泡、最近优先、无接收者、异常兜。
 *
 * ★ 用一棵可控的小树（根 Column + 3 个 Text 叶子），按叶子的**真实盒中心**派发
 *   `FilesDroppedEvent` —— 与 `DialogPageTest::click()` 同源的"取盒中心"手法。
 * ★ 这些是**路由层**判据，`DropPage` 的集成在 `DropPageTest`。两者分开：
 *   路由错了页面会跟着错，但页面测不出"为什么错"。
 */
final class FilesDropRoutingTest extends SmokeTestCase
{
    private const W = 800.0;

    private const H = 600.0;

    /** @return array{0: FilesDropRoutingApplication, 1: BoxConstraints} */
    private static function boot(bool $rootReceives = true, bool $leafReceives = true, bool $leafThrows = false): array
    {
        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));

        $app = new FilesDropRoutingApplication();
        $app->rootReceives = $rootReceives;
        $app->leafReceives = $leafReceives;
        $app->leafThrows = $leafThrows;
        $app->attach($surface, $constraints);
        $app->frame(16.0, $constraints);

        return [$app, $constraints];
    }

    /** 取某个 Text 叶子的盒中心（与 DialogPageTest::click 同源） */
    private static function centerOf(FilesDropRoutingApplication $app, string $content): array
    {
        $el = findElement(
            $app->root(),
            static fn(Element $e): bool => $e->widget() instanceof Text
                && $e->widget()->content() === $content
        );
        $box = $el?->renderObject();
        $size = $box?->size();
        if ($box === null || $size === null) {
            return [0.0, 0.0];
        }
        [$x, $y] = RenderBox::absoluteOrigin($box);

        return [$x + $size->width * 0.5, $y + $size->height * 0.5];
    }

    public function testNearestDeclaredLeafWinsOverRoot(): void
    {
        [$app] = self::boot(true, true);
        [$x, $y] = self::centerOf($app, 'ALPHA');

        $app->dispatchInput(new FilesDroppedEvent('C:/a.md', $x, $y));

        check(
            '路由 · 落在声明过的叶子上 ⇒ 只有叶子收到（最近优先，root 不重复收）',
            $app->calls === ['leaf'],
            'calls=' . json_encode($app->calls)
        );
    }

    public function testUndeclaredLeafBubblesToRoot(): void
    {
        [$app] = self::boot(true, true);
        [$x, $y] = self::centerOf($app, 'BETA');   // BETA 自己没声明

        $app->dispatchInput(new FilesDroppedEvent('C:/b.md', $x, $y));

        check(
            '路由 · 落在没声明的叶子上 ⇒ 沿父链冒泡到根（root 收到）',
            $app->calls === ['root'],
            'calls=' . json_encode($app->calls)
        );
    }

    public function testPayloadIsDeliveredVerbatim(): void
    {
        [$app] = self::boot(true, true);
        [$x, $y] = self::centerOf($app, 'ALPHA');

        $app->dispatchInput(new FilesDroppedEvent("C:/a.md\nD:/文档/b.txt", $x, $y));

        check(
            '路由 · 原始串逐字递到回调（UTF-8 与 \\n 都没被改）',
            $app->payloads === ["C:/a.md\nD:/文档/b.txt"],
            'payloads=' . json_encode($app->payloads, JSON_UNESCAPED_UNICODE)
        );
    }

    public function testNoReceiverIsSilentAndDoesNotCrash(): void
    {
        [$app] = self::boot(false, false);   // 根与叶都不声明
        [$x, $y] = self::centerOf($app, 'GAMMA');

        $app->dispatchInput(new FilesDroppedEvent('C:/c.md', $x, $y));

        check(
            '路由 · 没有任何声明者 ⇒ 静默丢弃、不抛异常（拖到不接收处是合法的）',
            $app->calls === [],
            'calls=' . json_encode($app->calls)
        );
    }

    public function testHandlerExceptionIsIsolatedWithoutExiting(): void
    {
        // RECOVERABLE 在 debug 会中止（`Errors::abort()` 末尾 exit），先关掉才测得着
        // "隔离"（同 SubscriptionIsolationTest 的配方）。try/finally 恢复全局状态。
        $debugWas = Errors::isDebug();
        Errors::setDebug(false);

        try {
            [$app] = self::boot(true, true, true);   // 叶子抛异常
            [$x, $y] = self::centerOf($app, 'ALPHA');

            $threw = false;
            try {
                $app->dispatchInput(new FilesDroppedEvent('C:/d.md', $x, $y));
            } catch (\Throwable $e) {
                $threw = true;
            }

            check('路由 · 回调抛异常 ⇒ 被兜住，不冒到消息泵（进程没被 exit）', !$threw);
            check('路由 · 异常之后回调确实跑到了（calls 记录了 leaf）', $app->calls === ['leaf']);

            // ★ 泵还活着：紧接着第二次拖放照样能投递（不是"炸完就冻住"）
            $app->leafThrows = false;
            [$x2, $y2] = self::centerOf($app, 'ALPHA');
            $app->dispatchInput(new FilesDroppedEvent('C:/e.md', $x2, $y2));
            check(
                '路由 · 异常之后泵没死，第二次拖放照样投递（calls 累加）',
                $app->calls === ['leaf', 'leaf'],
                'calls=' . json_encode($app->calls)
            );
        } finally {
            Errors::setDebug($debugWas);
        }
    }
}
