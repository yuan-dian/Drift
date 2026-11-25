<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Frame;
use Drift\Core\BlackBox;
use Drift\Core\Pipeline;
use Drift\Dev\Preview\SvgSurface;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use PHPUnit\Framework\TestCase;

/**
 * @internal 判据夹具：mount 时正常，布防后重建才抛。
 * 若在 mount 里就抛，异常发生在 Frame 之外，钉不到 settle 那条 catch。
 */
final class BlackBoxBoomState extends State
{
    public static bool $armed = false;

    public function build(BuildContext $ctx): Widget
    {
        if (self::$armed) {
            throw new \RuntimeException('boom in build');
        }

        return Text::make('ok');
    }
}

/** @internal 判据夹具 */
final class BlackBoxBoomWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new BlackBoxBoomState();
    }
}

/**
 * S2 的接线判据：谁在什么时候往黑匣子里写。
 *
 * 组件自身的环形与落盘在 Unit 组；这里钉的是两条接缝——Pipeline 帧边界
 * 是否真的写，以及 Frame 的 catch 是否先留现场再走 G2 中止序列。
 */
final class BlackBoxTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = \sys_get_temp_dir() . '/drift-blackbox-feature-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        Errors::reset();
        BlackBoxBoomState::$armed = false;
        Pipeline::enter(new Pipeline());
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
        parent::tearDown();
    }

    public function testPipelineWritesOneScalarRowPerFrame(): void
    {
        $box = new BlackBox(8);
        $pipeline = new Pipeline();
        $pipeline->setBlackBox($box);
        Pipeline::enter($pipeline);

        $pipeline->beginFrame();
        $pipeline->endFrame();
        $pipeline->beginFrame();
        $pipeline->endFrame();

        $snapshot = $box->snapshot();
        self::assertSame(2, $snapshot['total']);
        self::assertSame([1, 2], \array_column($snapshot['frames'], 'frame'));
        foreach ($snapshot['frames'] as $row) {
            self::assertGreaterThanOrEqual(0.0, $row['cost']);
            // 只 begin/end、没有树在跑 ⇒ 整段跳过是事实，不是漏记。
            self::assertTrue($row['skipped']);
        }
    }

    public function testTwoApplicationsKeepSeparateBlackBoxes(): void
    {
        $boxA = new BlackBox(8);
        $boxB = new BlackBox(8);
        $pipelineA = new Pipeline();
        $pipelineB = new Pipeline();
        $pipelineA->setBlackBox($boxA);
        $pipelineB->setBlackBox($boxB);

        Pipeline::enter($pipelineA);
        $pipelineA->beginFrame();
        $pipelineA->endFrame();
        Pipeline::enter($pipelineB);
        $pipelineB->beginFrame();
        $pipelineB->endFrame();
        $pipelineB->beginFrame();
        $pipelineB->endFrame();

        self::assertSame(1, $boxA->snapshot()['total']);
        self::assertSame(2, $boxB->snapshot()['total']);
        self::assertNotSame($boxA, $boxB);
    }

    public function testFrameCatchLeavesTheSiteAndStillFailsLoudly(): void
    {
        $box = new BlackBox(8);
        $pipeline = new Pipeline();
        $pipeline->setBlackBox($box);
        Pipeline::enter($pipeline);

        $surface = new SvgSurface();
        $surface->resize(200, 100, 1.0);
        $element = (new BlackBoxBoomWidget())->createElement();
        $element->mount(null, smokeContext());
        BlackBoxBoomState::$armed = true;
        $element->markNeedsBuild();
        $frame = new Frame($surface, $pipeline);

        Errors::setLogPath($this->dir . '/Errors.log');
        Errors::installFatalBox(static function (string $title, string $message): void {
            throw new \RuntimeException('fatal-box-reached');
        });

        try {
            $frame->run($element, BoxConstraints::tight(new Size(200.0, 100.0)), 0.0);
            self::fail('无边界异常必须走到中止序列，不能被吞掉');
        } catch (\RuntimeException $e) {
            self::assertSame('fatal-box-reached', $e->getMessage());
        } finally {
            Errors::reset();
        }

        $failure = $box->snapshot()['failure'];
        self::assertNotNull($failure);
        self::assertSame('settle', $failure['stage']);
        self::assertStringContainsString('boom in build', $failure['message']);
        self::assertSame(1, $failure['frame']);
    }
}
