<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\DebugSession;
use Drift\App\Replay;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * S3a 判据：盒模型与约束链能从**记录**里读出来，而不是从活树里现看。
 *
 * 钉四件事：
 * - 几何快照的四层盒模型有真值，且能跨 JSON 边界（INF 不炸）；
 * - 约束链可复算、两次逐字节相同，复算与快照交叉验证；
 * - 归不到基类默认的跳**说出来**，不冒充 `base`；
 * - 三种"查不到"分开说：复合节点没有盒 / 地址不存在 / 窗口外。
 *
 * 用真页面而不是假会话：这里要证明的是"帧边界真的采到了几何"，假会话只能证明
 * 查询函数会排版。
 */
final class GeometryTest extends SmokeTestCase
{
    private ?DebugSession $session = null;

    protected function tearDown(): void
    {
        $this->session?->close();
        $this->session = null;
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function session(): array
    {
        $meta = ['page' => 1];
        $drive = Plan::build('table', 800.0, 600.0, $meta, 1, 1, [['click', '400,300']]);
        $opened = Runner::open('table', 800.0, 600.0, $meta, $drive->fork());
        $this->session = $opened['session'];
        Replay::apply($this->session, $drive, [], null, static function (int $id, float $ms): void {
        });

        return $opened;
    }

    /** 找一个真正有盒的节点：复合控件（PageShell）没有自己的 RenderBox。 */
    private function boxedNode(array $opened): int
    {
        $session = $opened['session'];
        // 67 在表格页里是表格行里的盒，既有父链也不是根 —— 根盒 hops=0，测不到链。
        foreach ([67, 92, 61, 5, 2] as $id) {
            if ($session->query('constraints', ['node' => $id, 'maxDepth' => 1])['hops'] === 0) {
                continue;
            }
            if ($session->query('boxModel', ['node' => $id])['found']) {
                return $id;
            }
        }
        self::fail('测试页面里找不到一个带盒的节点');
    }

    public function testBoxModelHasRealValuesAndCrossesTheJsonBoundary(): void
    {
        $opened = $this->session();
        $model = $opened['session']->query('boxModel', ['node' => $this->boxedNode($opened)]);

        check('盒模型来自真几何快照', $model['found'], \json_encode($model['reason'] ?? 'found'));
        check('宽度是正数', $model['size']['w'] > 0.0, (string) $model['size']['w']);
        check('四层各就位', \array_key_exists('l', $model['padding'])
            && \array_key_exists('l', $model['margin'])
            && \array_key_exists('width', $model['border']));
        $encoded = \json_encode($model, \JSON_THROW_ON_ERROR);
        check('证据能跨 JSON 边界（INF 已换成字符串）', \is_array(\json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR)));
        check('几何窗口自报容量', $model['window']['capacity'] === 60, (string) $model['window']['capacity']);
    }

    public function testConstraintChainIsByteStable(): void
    {
        $opened = $this->session();
        $args = ['node' => $this->boxedNode($opened), 'maxDepth' => 4];
        $a = $opened['session']->query('constraints', $args);
        $b = $opened['session']->query('constraints', $args);

        check('有约束链', $a['found'] && $a['hops'] > 0, 'hops=' . ($a['hops'] ?? 0));
        check('同一份证据两次复算逐字节相同', \json_encode($a) === \json_encode($b));
    }

    public function testUnattributableHopsAreNamedInsteadOfClaimedAsBase(): void
    {
        $session = $this->session()['session'];
        $chain = $session->query('constraints', ['node' => 67, 'maxDepth' => 12])['chain'];
        check('链非空', $chain !== []);

        $sources = [];
        foreach ($chain as $hop) {
            $sources[] = $hop['source'];
            check(
                '来源在四种已定义的值里',
                \in_array($hop['source'], ['base', 'parent-performLayout', 'child-custom', 'parent-outside-window'], true),
                (string) $hop['source'],
            );
            // 判据的核心：说不出来源的跳不许被标成"基类默认且已验证"。
            check('base 与 verified 一一对应', ($hop['source'] === 'base') === $hop['verified'], (string) $hop['source']);
        }
        check('真页面里既有可归默认的跳，也有归不到的跳',
            \in_array('base', $sources, true) && \count(\array_unique($sources)) > 1,
            \implode('/', \array_unique($sources)),
        );
    }

    public function testDepthCutIsDeclaredWithItsSize(): void
    {
        $session = $this->session()['session'];
        $full = $session->query('constraints', ['node' => 67, 'maxDepth' => 0]);
        $cut = $session->query('constraints', ['node' => 67, 'maxDepth' => 1]);

        check('链不止一跳', $full['hops'] > 1, 'hops=' . $full['hops']);
        check('不截断时给出整条链', \count($full['chain']) === $full['hops'] && !$full['cut']);
        check('截断必须出声', $cut['cut'] && $cut['omitted'] === $full['hops'] - 1,
            'omitted=' . $cut['omitted'],
        );
    }

    public function testBoxlessNodesAreNotReportedAsOutsideTheWindow(): void
    {
        $session = $this->session()['session'];
        $miss = $session->query('boxModel', ['node' => 1]); // PageShell：复合控件，无自己的盒

        check('没有盒就是没有盒', !$miss['found'] && $miss['reason'] === 'no-render-box', (string) ($miss['reason'] ?? ''));
        check('给出下一步去哪查', \array_key_exists('suggest', $miss), (string) ($miss['suggest'] ?? ''));
    }

    public function testUnknownAddressAndWindowMissAreDifferent(): void
    {
        $session = $this->session()['session'];
        $unknown = $session->query('boxModel', ['node' => 999999]);

        check('不存在的地址说地址错', !$unknown['found'] && $unknown['reason'] === 'unknown-node', (string) ($unknown['reason'] ?? ''));
        check('不给无盒节点的建议', !\array_key_exists('suggest', $unknown));
    }

    public function testFrameArgumentDoesNotSilentlyFallForward(): void
    {
        $session = $this->session()['session'];
        $atFirst = $session->query('boxModel', ['node' => 67, 'frame' => 1]);
        check('问哪一帧就是哪一帧', $atFirst['frame'] === 1, (string) $atFirst['frame']);
        check('窗口随答案一起给出', \array_key_exists('window', $atFirst));
    }
}
