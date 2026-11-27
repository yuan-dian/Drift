#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-tree-diff` —— 两帧之间的结构事件。
 *
 * 它回答"从 A 帧之后到 B 帧（含）发生了什么"：谁出生、谁离开、谁在中间重建。
 * 区间是左开右闭，所以 `diff(2,4)` 与 `diff(4,6)` 拼起来正好是 `diff(2,6)`。
 *
 * 事实取自生命周期两端（`f` / `g`）与帧记录的重建集合，不是"两次 tree() 相减"：
 * 后者拿到的只是两份被 depth / limit 截断的投影，相减会把截断当成"没变化"。
 *
 * 没有逐帧的父 / 类型快照，所以移位与改型不报——报了就是拿现在冒充当时。
 * 对比的完整性与缺口写在第二行；空结果配上"不完整"不等于"没发生"。
 *
 * 用法：
 *   php devtools/bin/debug-tree-diff.php --page=async-table --base=2 --frame=6
 *   php devtools/bin/debug-tree-diff.php --page=async-table --base=2 --limit=0
 *   php devtools/bin/debug-tree-diff.php --from=case.evidence --base=10 --frame=20 --json
 *
 * 参数：
 *   --base=N       起点帧（不含；默认 0 = 从录制开始）
 *   --frame=N      终点帧（含；默认最新帧）
 *   --limit=N      最多列多少条事件（默认 200，0=不截断）
 */

require __DIR__ . '/../autoload.php';

use Drift\DevTools\Cli\SessionScope;
use Drift\DevTools\View\TreeDiff;

SessionScope::open('tree-diff', $argv)->run(static function (SessionScope $scope): int {
    $args = $scope->args;
    $answer = $scope->query('treeDiff', [
        'base' => $args->int('base'),
        'frame' => $args->has('frame') ? $args->int('frame') : null,
        'limit' => $args->int('limit'),
    ]);
    $scope->emit($answer, TreeDiff::render(...));

    return 0;
});
