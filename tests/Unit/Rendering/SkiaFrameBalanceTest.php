<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Matrix2D;
use Drift\Geometry\Rect;
use Drift\Rendering\Color;
use Drift\Rendering\SkiaCanvas;

/**
 * Skia 单栈镜像的帧末自证。
 *
 * ★ 反例必须走子进程：`Errors::report()` 的 FATAL 落点是 `exit(3)`，进程内抓不到。
 * ★ 正向用例断的是假桥的调用序列，不是"没崩"—— 没崩也可能只是断言没查 `skiaDepth`。
 */
final class SkiaFrameBalanceTest extends SmokeTestCase
{
    private const PUSH = ['save', 'push_clip', 'push_clip_path', 'push_layer'];
    private const POP = ['restore', 'pop_clip', 'pop_layer'];

    private static function canvas(): SkiaCanvas
    {
        skiaBridgeReset();

        return new SkiaCanvas(8, 8, Color::white());
    }

    /** @return list<string> 只留保存栈相关的桥调用名 */
    private static function stackOps(): array
    {
        return array_values(array_filter(
            skiaCallOps(),
            static fn(string $op): bool => in_array($op, self::PUSH, true) || in_array($op, self::POP, true),
        ));
    }

    public function testBalancedFramePasses(): void
    {
        $c = self::canvas();
        $c->save();
        $c->pushClip(new Rect(0.0, 0.0, 4.0, 4.0));
        $c->popClip();
        $c->pushLayer(0.5);
        $c->popLayer();
        $c->restore();
        $c->assertFrameBalanced();

        $ops = self::stackOps();
        check(
            'SkiaCanvas · 完整配对后帧末自证放行（能走到断言即未 FATAL）',
            $ops === ['push_clip', 'pop_clip', 'push_layer', 'pop_layer'],
            'ops=' . implode(',', $ops),
        );

        $this->flushChecks();
    }

    public function testRestoreReturnsUnclosedLayersToBase(): void
    {
        $c = self::canvas();
        $c->save();
        $c->transform(Matrix2D::rotation(30.0));    // 压一层变换锚点
        $c->pushClip(new Rect(0.0, 0.0, 4.0, 4.0)); // 故意漏 popClip
        $c->pushLayer(0.5);                          // 故意漏 popLayer
        $c->restore();                               // 按 save 时的栈深一次性退回
        $c->assertFrameBalanced();

        $ops = self::stackOps();
        $restores = count(array_filter($ops, static fn(string $o): bool => $o === 'restore'));
        check(
            'SkiaCanvas · restore() 一次性退回块内三层未闭合状态（restoreToCount）',
            $restores === 3,
            'restore=' . $restores . ' ops=' . implode(',', $ops),
        );
        check(
            'SkiaCanvas · 变换锚点 / 裁剪 / 层压进同一条 Skia 保存栈',
            $ops === ['save', 'push_clip', 'push_layer', 'restore', 'restore', 'restore'],
            'ops=' . implode(',', $ops),
        );

        $this->flushChecks();
    }

    public function testUnbalancedFramesFailLoudly(): void
    {
        $cases = [
            'skia-unclosed-clip'      => '未配对的 pushClip',
            'skia-unclosed-layer'     => '未配对的 pushLayer',
            'skia-unclosed-clip-path' => '未配对的 pushClipPath',
            'skia-unclosed-save'      => '漏掉的 restore',
            'skia-unclosed-transform' => 'transform 后没配对的 restore',
        ];

        foreach ($cases as $case => $label) {
            $log = sys_get_temp_dir() . '/drift-skia-balance-' . $case . '.log';
            if (is_file($log)) {
                unlink($log);
            }
            [$code, $out] = runFatalCase($case, $log);
            $text = logText($log);

            check(
                'SkiaCanvas · ' . $label . ' ⇒ 帧末自证响亮失败（exit 3）',
                $code === 3,
                'code=' . $code . ' out=' . substr($out, 0, 100),
            );
            check(
                'SkiaCanvas · ' . $label . ' ⇒ 中止报告点明「帧末状态未配平」',
                str_contains($text, '帧末状态未配平'),
                'log=' . substr($text, 0, 120),
            );
        }

        $this->flushChecks();
    }
}
