<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\DebugSession;
use Drift\App\Replay;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Debug\View\Brief;
use Drift\Dev\Debug\View\Finding;
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
    private function session(float $width = 800.0, float $height = 600.0): array
    {
        $meta = ['page' => 1];
        $drive = Plan::build('table', $width, $height, $meta, 1, 1, [['click', '400,300']]);
        $opened = Runner::open('table', $width, $height, $meta, $drive->fork());
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
                '来源在五种已定义的值里',
                \in_array($hop['source'], ['base', 'parent-performLayout', 'parent-flex', 'child-custom', 'parent-outside-window'], true),
                (string) $hop['source'],
            );
            // 判据的核心：说不出来源的跳不许被标成"基类默认且已验证"。
            check('base 与 verified 一一对应', ($hop['source'] === 'base') === $hop['verified'], (string) $hop['source']);
        }
        check('真页面里既有可归默认的跳，也有归不到的跳',
            \in_array('base', $sources, true) && \count(\array_unique($sources)) > 1,
            \implode('/', \array_unique($sources)),
        );
        // ★ Flex 归因必须真的被用到：表格页里有 Column + Expanded，若全被判成
        //   `child-custom`，那条归因就等于没写（"看起来说了来源，其实是兜底"）。
        check('Flex 父分配尺寸的跳归到 parent-flex，不落到 child-custom',
            \in_array('parent-flex', $sources, true), \implode('/', \array_unique($sources)));
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

    /**
     * 窗口外的帧号必须报"帧不在窗口"，不能拿窗口末端的旧几何顶替。
     *
     * 早先 `geometryAt()` 把帧号 clamp 到窗口末端：查 `frame=99`（窗口只到 2）会
     * 返回 `frame=1` 的记录并报 `found=true` —— 一个根本没采样过的帧看起来有几何。
     * 更糟的是四个查询里只有 `align()` 自己判了窗口，另外三个照样返回旧帧，
     * 于是"同一份证据、同一帧号"在不同查询里给出互相矛盾的答案。
     */
    public function testOutOfWindowFrameIsReportedInsteadOfClamped(): void
    {
        $opened = $this->session();
        $session = $opened['session'];
        $node = $this->boxedNode($opened);
        check('未来帧的判据拿到一个有盒节点', $node > 0, 'node=' . $node);
        $to = (int) $session->query('boxes')['window']['to'];
        $future = $to + 10;

        $model = $session->query('boxModel', ['node' => $node, 'frame' => $future]);
        check(
            '未来帧的 boxModel 报 frame-out-of-window，不报 found',
            ($model['found'] ?? true) === false && ($model['reason'] ?? '') === 'frame-out-of-window',
            \json_encode($model),
        );

        $chain = $session->query('constraints', ['node' => $node, 'frame' => $future]);
        check(
            '未来帧的 constraints 同样报窗口外',
            ($chain['found'] ?? true) === false && ($chain['reason'] ?? '') === 'frame-out-of-window',
            \json_encode($chain),
        );

        $boxes = $session->query('boxes', ['frame' => $future]);
        check(
            '未来帧的 boxes 不给旧帧整表',
            ($boxes['rows'] ?? ['x']) === [] && ($boxes['reason'] ?? '') === 'frame-out-of-window',
            \json_encode(['rows' => \count($boxes['rows'] ?? []), 'reason' => $boxes['reason'] ?? '']),
        );

        $aligned = $session->query('align', ['node' => $node, 'frame' => $future]);
        check(
            '未来帧的 align 也报窗口外（四个查询同一份语义）',
            ($aligned['geometry']['reason'] ?? '') === 'frame-out-of-window',
            \json_encode($aligned['geometry'] ?? []),
        );

        // ★ 上面四条咬的是查询层的窗口判断（第一道防线）。记录层自己那道也得咬住：
        //   查询层判窗口是"消费者各自守规矩"，而 `geometryAt()` 的 clamp 会让**任何**
        //   直接调它的地方（新查询、面板、将来的消费者）静默拿到旧帧。两道都要有牙，
        //   否则修掉一道另一道就会悄悄退回。
        $raw = $session->recorder()->geometryAt($future, $node);
        check('记录层的 geometryAt 对未来帧返回 null（不许 clamp 到窗口末端）', $raw === null,
            'record=' . ($raw === null ? 'null' : 'frame#' . $raw->frame));
    }

    /**
     * `reset()` 必须把几何与签名一起清。
     *
     * `trim()` 里"签名不清"说的是**窗口滚动**（记录被裁掉了，但这份几何确实记过）。
     * `reset()` 是"重新开始录"，语义不同：不清签名的话，下一帧 `frameId` 从 1
     * 重新开始，签名说"这个几何记过" ⇒ 一条都不写，而查询还能从残留的旧 #1..#60
     * 里读到上上次会话的几何 —— 帧号恰好匹配，`stale` 也不会报。
     */
    public function testResetDropsGeometrySoTheNextFrameIsVisible(): void
    {
        $opened = $this->session();
        $session = $opened['session'];
        $node = $this->boxedNode($opened);
        check('reset 前查得到几何', $session->query('boxModel', ['node' => $node])['found'] === true);

        $session->recorder()->reset();
        $session->pump(16.0);
        $after = $session->query('boxModel', ['node' => $node]);

        check(
            'reset 后新帧的几何可见（签名没把新记录吃掉）',
            ($after['found'] ?? false) === true,
            \json_encode($after),
        );
        check(
            'reset 后不残留旧窗口',
            (int) ($after['window']['from'] ?? -1) === 1,
            \json_encode($after['window'] ?? []),
        );
    }

    public function testFrameArgumentDoesNotSilentlyFallForward(): void
    {
        $session = $this->session()['session'];
        $atFirst = $session->query('boxModel', ['node' => 67, 'frame' => 1]);
        check('问哪一帧就是哪一帧', $atFirst['frame'] === 1, (string) $atFirst['frame']);
        check('窗口随答案一起给出', \array_key_exists('window', $atFirst));
    }

    public function testBoxesIsAWholeFrameReadOfTheRecord(): void
    {
        $session = $this->session()['session'];
        $a = $session->query('boxes', ['frame' => 1]);
        $b = $session->query('boxes', ['frame' => 1]);

        check('整帧几何有真行', \count($a['rows']) > 100, 'rows=' . \count($a['rows']));
        check('问哪一帧就是哪一帧', $a['frame'] === 1, (string) $a['frame']);
        check('两次读取逐字节相同', \json_encode($a) === \json_encode($b));

        $ids = \array_column($a['rows'], 'node');
        $sorted = $ids;
        \sort($sorted, \SORT_NUMERIC);
        check('行按 node id 升序（有确定的读取次序）', $ids === $sorted);
        check('窗口自报容量', ($a['window']['capacity'] ?? 0) === 60, \json_encode($a['window']));
        check('盒事实能过 JSON 边界（无线上的 INF）', \is_array(
            \json_decode((string) \json_encode($a, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
        ));
        check('每行带父指针与类型', isset($a['rows'][0]['parent'], $a['rows'][0]['type']));
    }

    /**
     * 几何事实的第一个真消费者：窄视口下表格把子约束放宽到视口之外，
     * 简报要能自己说出口，而且说的那跳能被 `constraints()` 复核。
     */
    public function testBriefExplainsAConstraintReshapeFromGeometryFacts(): void
    {
        $session = $this->session(360.0, 640.0)['session'];
        $findings = Brief::findings([
            'frames' => $session->query('frames', ['limit' => 0])['frames'],
            'geometry' => $session->query('boxes', ['frame' => 1]),
        ]);

        $widened = null;
        foreach ($findings as $one) {
            if ($one->rule === 'constraint-widened') {
                $widened = $one;
                break;
            }
        }
        check('窄视口的放宽被说出来', $widened instanceof Finding, \json_encode(
            \array_map(static fn(Finding $f): string => $f->rule, $findings),
        ));
        if ($widened === null) {
            return;
        }

        check('发现带 node 地址', \str_contains($widened->address, 'node:'), $widened->address);
        check('复算方式指回约束链查询', \str_contains($widened->recheck, 'constraints('), $widened->recheck);

        $frame = 0;
        $node = 0;
        \sscanf($widened->address, 'frame:%d node:%d', $frame, $node);
        $chain = $session->query('constraints', ['node' => $node, 'frame' => $frame, 'maxDepth' => 0])['chain'];
        $backed = false;
        foreach ($chain as $hop) {
            $offered = $hop['parentInner']['maxW'] ?? null;
            $received = $hop['received']['maxW'] ?? null;
            if (\is_numeric($offered) && \is_numeric($received) && (float) $received > (float) $offered) {
                $backed = true;
                break;
            }
        }
        check('发现能被约束链查询复核', $backed, 'node=' . $node . ' chain=' . \json_encode($chain));
    }
}
