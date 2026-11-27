<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\App\DebugSession;
use Drift\DevTools\App\Replay;
use Drift\DevTools\Host\Plan;
use Drift\DevTools\Host\Runner;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * web 线框的可读性：底图是主角，容器框只作辅助线，不能盖成一张网。
 *
 * 规则（谁是容器、父链怎么走）归查询层；页面只按标记决定透明度。
 */
final class WebWireframesTest extends SmokeTestCase
{
    private ?DebugSession $session = null;

    protected function tearDown(): void
    {
        $this->session?->close();
        $this->session = null;
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function boxes(): array
    {
        $meta = ['page' => 1];
        $drive = Plan::build('table', 800.0, 600.0, $meta, 1, 1, [['click', '400,300']]);
        $opened = Runner::open('table', 800.0, 600.0, $meta, $drive->fork());
        $this->session = $opened['session'];
        Replay::apply($this->session, $drive, [], null, static function (int $id, float $ms): void {
        });

        return $this->session->query('boxes', []);
    }

    /** 查询层给出容器标记与父链，页面不必自己累加。 */
    public function testBoxesCarryContainerMarkAndAncestorChain(): void
    {
        $rows = (array) ($this->boxes()['rows'] ?? []);
        check('真页面有几何行', $rows !== [], 'rows=' . \count($rows));

        $parents = [];
        foreach ($rows as $row) {
            $parents[(int) ($row['parent'] ?? 0)] = true;
        }

        $containers = 0;
        $leaves = 0;
        foreach ($rows as $row) {
            $node = (int) ($row['node'] ?? 0);
            check('每行带 container 标记', \array_key_exists('container', $row), 'node=' . $node);
            check('每行带 ancestor 链', \array_key_exists('chain', $row), 'node=' . $node);

            $expected = isset($parents[$node]);
            check('容器标记 = 本帧有子盒', ($row['container'] ?? null) === $expected,
                'node=' . $node . ' got=' . \json_encode($row['container'] ?? null) . ' want=' . \json_encode($expected));
            $chain = $row['chain'] ?? null;
            check('链是数组', \is_array($chain), 'node=' . $node);
            if (\is_array($chain) && $chain !== []) {
                check('链首就是父', (int) ($chain[0] ?? 0) === (int) ($row['parent'] ?? 0),
                    'node=' . $node . ' chain=' . \json_encode($chain) . ' parent=' . ($row['parent'] ?? 0));
                check('链不含自己', !\in_array($node, \array_map('intval', $chain), true), 'node=' . $node);
            }

            if ($expected) {
                $containers++;
            } else {
                $leaves++;
            }
        }
        check('确实采到容器', $containers > 0, 'containers=' . $containers);
        check('确实采到叶子', $leaves > 0, 'leaves=' . $leaves);
    }

    /** 画框时容器退到辅助线，选中/悬停的祖先链保持清晰。 */
    private function drawFunction(): string
    {
        $js = (string) \file_get_contents(\dirname(__DIR__, 3) . '/devtools/View/web/app.js');
        $start = \strpos($js, 'function draw()');
        self::assertIsInt($start, '找不到 draw()');
        $end = \strpos($js, "\nfunction placeShot", $start);
        self::assertIsInt($end, '找不到 draw() 结尾');

        return \substr($js, $start, $end - $start);
    }

    public function testContainerBoxesAreDimmedButTheSelectionChainStaysBright(): void
    {
        $draw = $this->drawFunction();

        check('画框读容器标记', \str_contains($draw, 'row.container'), $draw);
        check('容器用更淡的透明度', \str_contains($draw, 'containerAlpha'), $draw);
        check('淡画只在普通框分支', \str_contains($draw, 'isSel') && \str_contains($draw, 'isHover'), $draw);
        // 钉住祖先链豁免的实例，不是注释：否则删掉豁免逻辑注释还在，判据恒绿。
        check('选中链不淡画', \str_contains($draw, 'keepChain.has(row.node)'), $draw);
        check('链集合来自选中与悬停', \str_contains($draw, 'selRow?.chain') && \str_contains($draw, 'hoverRow?.chain'), $draw);
    }
}
