<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 表格的**命中分区**：表头区与行区各归各的子树。
 *
 * 为什么值得单独测：`RenderTable` 把表头与行区叠在同一个坐标系里（行区从 y=表头高
 * 开始），命中测试要沿子盒反查。如果表头盒的高度算错（比如按内容盒而不是边框盒），
 * 表头就会"吞掉"行区顶部若干像素 —— 点击首行没反应，且**不报错**。
 *
 * ★ 判据是 `under($hit, $kid)`：命中的盒必须在该子树下，而不是"命中了一个非 null"。
 *   `$hit !== null` 是恒真断言 —— 点哪都能中一个盒。
 */
final class HitPartitionTest extends SmokeTestCase
{
    public function testHeaderAndBodyPartition(): void
    {
        $root = Fixtures::mount(Fixtures::build(10000));
        Fixtures::stateOf($root)?->scrollBy(48.0);
        Fixtures::pump($root);

        // 滚过之后重新取盒与子盒（`syncBox()` 每次 build 都造新盒）
        $box = Fixtures::boxOf($root);
        $kids = Fixtures::kidsOf($box);

        $hitHeader = $box?->hitTest(100.0, 10.0);    // y=10 落在表头（0..32）
        $hitBody = $box?->hitTest(100.0, 230.0);     // y=230 落在行区（32..240）

        check(
            'Table · 命中分区：表头区的点落在表头子树',
            isset($kids[0]) && Fixtures::under($hitHeader, $kids[0]),
            $hitHeader === null ? 'miss' : \get_debug_type($hitHeader),
        );
        check(
            'Table · 命中分区：行区的点落在行区子树（不是被表头吞掉）',
            isset($kids[1]) && Fixtures::under($hitBody, $kids[1]),
            $hitBody === null ? 'miss' : \get_debug_type($hitBody),
        );
    }
}
