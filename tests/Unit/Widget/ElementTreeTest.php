<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Smoke\BuildProbe;
use Drift\Dev\Smoke\Tree\BranchWidget;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\Element;

/**
 * Element 三树：挂载 / diff / 卸载（§3.2 / §12.3，与 `tools/smoke.php` ⑯ 段一致）。
 *
 * 守 Element 身份语义：结构节点建子树、diff 复用同槽、Key 决定身份、同实例挂两处
 * 各一个 Element、unmount 级联、update 换整体描述并封存。
 *
 * ★ 不进帧驱动（直接 mount/update/unmount），纯 Element 机制 ⇒ Unit 层。
 */
final class ElementTreeTest extends SmokeTestCase
{
    public function testMountBuildsSubtreeAndSeals(): void
    {
        $context = smokeContext();
        $treeRoot = (new BranchWidget([new BuildProbe(), new BuildProbe()]))->createElement();
        $treeRoot->mount(null, $context);
        check('Element · 挂载建出整棵子树（结构节点 → 2 个无状态 → 各 1 叶）', countTree($treeRoot) === 5, (string) countTree($treeRoot));
        check('Element · 挂载时封存 Widget', $treeRoot->widget()->isSealed() && $treeRoot->childAt(0) !== null && $treeRoot->childAt(0)->widget()->isSealed());
        check('Element · 渲染环境沿父链继承（不是每层新建一份）', $treeRoot->childAt(0)?->context() === $context);

        $this->flushChecks();
    }

    public function testDiffReusesSameSlot(): void
    {
        $context = smokeContext();
        $treeRoot = (new BranchWidget([new BuildProbe(), new BuildProbe()]))->createElement();
        $treeRoot->mount(null, $context);
        $firstKid = $treeRoot->childAt(0);
        $treeRoot->update(new BranchWidget([new BuildProbe(), new BuildProbe()]));
        check(
            'Element · diff 复用同槽旧子（身份稳定，不重置子树）',
            $treeRoot->childAt(0) === $firstKid && $treeRoot->isMounted()
        );

        $this->flushChecks();
    }

    public function testKeySwapKeepsIdentity(): void
    {
        $context = smokeContext();
        $keyless = (new BranchWidget([(new BuildProbe())->key('a'), (new BuildProbe())->key('b')]))->createElement();
        $keyless->mount(null, $context);
        $slotA = $keyless->childAt(0);
        $slotB = $keyless->childAt(1);
        $keyless->update(new BranchWidget([(new BuildProbe())->key('b'), (new BuildProbe())->key('a')]));
        check(
            'Element · 有 Key ⇒ 位置变了身份不变（换位复用的是同名那个）',
            $keyless->childAt(0) === $slotB && $keyless->childAt(1) === $slotA
        );

        $this->flushChecks();
    }

    public function testKeyChangeDoesNotReuse(): void
    {
        $context = smokeContext();
        $keyless = (new BranchWidget([(new BuildProbe())->key('a'), (new BuildProbe())->key('b')]))->createElement();
        $keyless->mount(null, $context);
        $slotA = $keyless->childAt(0);
        $slotB = $keyless->childAt(1);
        $keyless->update(new BranchWidget([(new BuildProbe())->key('a'), (new BuildProbe())->key('c')]));
        check(
            'Element · Key 变了 ⇒ 不复用（新 Element 进槽）',
            $keyless->childAt(0) === $slotA && $keyless->childAt(1) !== $slotB
        );

        $this->flushChecks();
    }

    public function testSameInstanceMountedTwice(): void
    {
        $context = smokeContext();
        $twice = new BuildProbe();
        $pair = (new BranchWidget([$twice, $twice]))->createElement();
        $pair->mount(null, $context);
        check(
            'Element · 同一实例挂两处 ⇒ 两个 Element（位置各有一个）',
            $pair->childCount() === 2 && $pair->childAt(0) !== $pair->childAt(1)
        );

        $this->flushChecks();
    }

    public function testUnmountCascadesAndClearsChildren(): void
    {
        $context = smokeContext();
        $unmountRoot = (new BranchWidget([new BuildProbe(), new BuildProbe()]))->createElement();
        $unmountRoot->mount(null, $context);
        $kidsBefore = [];
        $unmountRoot->visitChildren(static function (Element $child) use (&$kidsBefore): void {
            $kidsBefore[] = $child;
        });
        $unmountRoot->unmount();
        $allDown = true;
        foreach ($kidsBefore as $kid) {
            if ($kid->isMounted()) {
                $allDown = false;
            }
        }
        check('Element · unmount 级联到全树', !$unmountRoot->isMounted() && $allDown);
        check('Element · unmount 后子列表清空', $unmountRoot->childCount() === 0);

        $this->flushChecks();
    }

    public function testUpdateReplacesDescriptionAndSeals(): void
    {
        $context = smokeContext();
        $rebuilt = (new BranchWidget([new BuildProbe()]))->createElement();
        $rebuilt->mount(null, $context);
        $nextWidget = new BuildProbe();
        $rebuilt->update(new BranchWidget([$nextWidget]));
        check('Element · update 换整体新描述并封存', $rebuilt->childAt(0)?->widget() === $nextWidget && $nextWidget->isSealed());

        $this->flushChecks();
    }
}
