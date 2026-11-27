#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-tree` —— 第 N 帧的结构树。
 *
 * 它回答"第 N 帧时这棵树长什么样"：那时还没出生的节点不出现，那时已经离开的
 * 节点同样不出现，而现在已回收、当时还活着的节点照样画出来并标注。默认帧是
 * 最新一帧，所以不带 `--frame` 时看到的就是当下。
 *
 * 不是"现在的注册表配上某一帧的几何"—— 那是旧口径，它会把第 40 帧之后才出生的
 * 节点列进"第 40 帧的树"，也会把当时还在、之后卸载的节点整个删掉。
 *
 * 选中态不在这里保存：树是导航，真机高亮才是真相，与 Web 共用同一条通道。
 *
 * 用法：
 *   php devtools/bin/debug-tree.php --page=async-table --rows=3 --frames=2
 *   php devtools/bin/debug-tree.php --page=async-table --root=12 --depth=4 --limit=80
 *   php devtools/bin/debug-tree.php ... --frame=42 --json
 *   php devtools/bin/debug-tree.php --from=case.evidence --root=5
 *   php devtools/bin/debug-tree.php ... --remote / --out=case.evidence
 *
 * 参数：
 *   --root=N        只看这个子树（默认 0，看全部根）
 *   --depth=N       展开层数（默认 6，0=只列根）
 *   --limit=N       最多列多少行（默认 200，0=不截断）
 *   --frame=N       回放这一帧（默认最新帧）：结构按那时的存活集合给，几何对齐同一帧
 *
 * 回放的可信度写在输出的第三行（「生命周期 完整 / 不完整：…」）。缺出生帧的旧证据
 * 包、关了几何采集的会话，都不许把"不知道"显示成"没有"。
 */

require __DIR__ . '/../autoload.php';

use Drift\DevTools\Cli\SessionScope;
use Drift\DevTools\View\Tree;

SessionScope::open('tree', $argv)->run(static function (SessionScope $scope): int {
    $args = $scope->args;
    $answer = $scope->query('tree', [
        'root' => $args->int('root'),
        'depth' => $args->int('depth'),
        'limit' => $args->int('limit'),
        'frame' => $args->has('frame') ? $args->int('frame') : null,
    ]);
    $scope->emit($answer, Tree::render(...));

    return 0;
});
