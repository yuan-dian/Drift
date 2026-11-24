<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 脚本的往返与边界。
 *
 * ## 钉什么
 *
 * - JSON 往返**逐值相同**（中文、浮点、空时间戳都不许漂）；
 * - 非标量进不来（对象 / 数组在构造处就抛，不等序列化时才发现）；
 * - 摘要对"改了一个字节"敏感 —— 否则回放判据会在两种实现下都绿；
 * - 触顶后 `truncated` 为真且停止增长：宁可明确说不完整，不给"看起来能跑"。
 */
final class ScriptTest extends SmokeTestCase
{
    public function testJsonRoundTripKeepsEveryValue(): void
    {
        $script = new Script('table', 800.0, 600.0, ['rows' => 6, 'label' => '表']);
        $script->add(Step::frame(0.0));
        $script->add(new Step(Step::KEY, 16.0, [
            'chord' => 'A', 'ctrl' => true, 'alt' => false, 'shift' => false, 'text' => '井',
        ]));
        $script->add(new Step(Step::POINTER, 16.0, [
            'phase' => 'Down', 'x' => 400.0, 'y' => 300.0, 'button' => 0,
            'ctrl' => false, 'alt' => false, 'shift' => true, 'clickCount' => 2,
        ]));
        $script->add(new Step(Step::SCROLL, 32.0, ['x' => 1.5, 'y' => 2.5, 'delta' => -3.0, 'axis' => 'h']));
        $script->add(Step::effect('resolve', ['page' => 2], 48.0));
        // 没有时间戳的输入是合法输入（测试与解释器就不传），必须原样往返。
        $script->add(new Step(Step::KEY, null, [
            'chord' => 'Tab', 'ctrl' => false, 'alt' => false, 'shift' => false, 'text' => '',
        ]));

        $back = Script::fromJson($script->json());

        check('目标一致', $back->target === 'table', $back->target);
        check('视口一致', $back->width === 800.0 && $back->height === 600.0, $back->width . 'x' . $back->height);
        check('环境一致', $back->metaValue('rows') === 6, \json_encode($back->meta()));
        check('步数一致', $back->count() === 6, (string) $back->count());
        check('往返逐值相同', $back->toArray() === $script->toArray(), 'sha=' . \substr($back->digest(), 0, 12));
        check('摘要也一致', $back->digest() === $script->digest(), $back->digest());
        check(
            '空时间戳往返成 null',
            $back->steps()[5]->t === null,
            \json_encode($back->steps()[5]->t),
        );
        check(
            '中文没有被转义成 UTF-8 字节对',
            \str_contains($script->json(), '井'),
            \substr($script->json(), 0, 80),
        );
    }

    public function testNonScalarPayloadIsRefusedAtConstruction(): void
    {
        $refused = false;
        try {
            // 一个对象混进来：脚本就不再是"能存成 JSON、能跨进程传"的东西了。
            new Step(Step::KEY, 0.0, ['chord' => 'A', 'text' => new \stdClass()]);
        } catch (\InvalidArgumentException $e) {
            $refused = \str_contains($e->getMessage(), '只能是标量');
        }

        check('对象在构造处被拒绝', $refused, '抛出的异常没有指出"标量"这条规则');

        $refusedArray = false;
        try {
            new Step(Step::EFFECT, 0.0, ['name' => 'x', 'nested' => ['a' => 1]]);
        } catch (\InvalidArgumentException $e) {
            // 嵌套数组同样拒绝：允许它，第二个口子就会以"就这一次"出现。
            $refusedArray = \str_contains($e->getMessage(), '只能是标量');
        }

        check('嵌套数组也被拒绝', $refusedArray);
    }

    public function testUnknownKindAndVersionAreRefused(): void
    {
        $badKind = false;
        try {
            new Step('jump', 0.0);
        } catch (\InvalidArgumentException $e) {
            $badKind = \str_contains($e->getMessage(), '未知的步骤种类');
        }
        check('未知种类被拒绝', $badKind);

        $badVersion = false;
        try {
            Script::fromArray(['version' => 99, 'target' => 'x', 'viewport' => [1, 1], 'steps' => []]);
        } catch (\InvalidArgumentException $e) {
            $badVersion = \str_contains($e->getMessage(), '版本不认识');
        }
        check('未知版本被拒绝（不猜着读）', $badVersion);
    }

    public function testDigestChangesWhenASingleValueChanges(): void
    {
        $a = new Script('table', 800.0, 600.0);
        $a->add(Step::frame(0.0));
        $a->add(new Step(Step::POINTER, 16.0, ['phase' => 'Down', 'x' => 400.0, 'y' => 300.0]));

        // 只挪 1 像素：摘要必须变。否则"同一脚本"这句话就没有判据。
        $b = new Script('table', 800.0, 600.0);
        $b->add(Step::frame(0.0));
        $b->add(new Step(Step::POINTER, 16.0, ['phase' => 'Down', 'x' => 401.0, 'y' => 300.0]));

        check('改一个坐标摘要就变', $a->digest() !== $b->digest(), '摘要对改动不敏感');

        // 同内容新建一份：摘要必须相同（摘要只取决于内容，不取决于对象身份）。
        $c = new Script('table', 800.0, 600.0);
        $c->add(Step::frame(0.0));
        $c->add(new Step(Step::POINTER, 16.0, ['phase' => 'Down', 'x' => 400.0, 'y' => 300.0]));
        check('同内容摘要相同', $a->digest() === $c->digest(), '摘要掺进了对象身份');
    }

    public function testTruncationStopsGrowingAndIsNotHidden(): void
    {
        $script = new Script('table', 800.0, 600.0);
        for ($i = 0; $i < Script::MAX_STEPS + 5; ++$i) {
            $script->add(Step::frame((float) $i));
        }

        check('步数停在容量上限', $script->count() === Script::MAX_STEPS, (string) $script->count());
        check('截断被标出来', $script->truncated(), 'truncated=false：重放方会以为脚本完整');

        $back = Script::fromJson($script->json());
        check('截断标记能往返', $back->truncated(), '存档后丢了截断标记');
    }

    public function testForkKeepsHeaderButNotSteps(): void
    {
        $script = new Script('table', 800.0, 600.0, ['rows' => 3]);
        $script->add(Step::frame(0.0));

        $fork = $script->fork();

        check('分叉同目标', $fork->target === 'table', $fork->target);
        check('分叉同视口', $fork->width === 800.0 && $fork->height === 600.0);
        check('分叉同环境', $fork->metaValue('rows') === 3);
        check('分叉不带步骤', $fork->count() === 0, (string) $fork->count());
        check('原脚本没被改动', $script->count() === 1, (string) $script->count());
    }
}
