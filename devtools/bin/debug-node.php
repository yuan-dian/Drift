#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-node` —— 跨域对齐的出口：给一个节点和一个帧，并排看因果 / 几何 / 网络。
 *
 * 为什么是显式 node + frame 而不是"帮我找"：三个域的缺省帧语义不同，让命令
 * 替你选帧就会重演"看起来对齐、实际各取各的"。`--frame` 必填正是这条纪律的
 * 可执行形式——`align()` 查询也不接受缺省帧。
 *
 * 只读查询面（`align`），本地与 `--remote` 同一条路；投影层给一条可复算的
 * 发现，地址指回 `why(node, frame)`，不冒充 bug。
 *
 * 用法：
 *   php devtools/bin/debug-node.php --page=table --frames=1 --click=400,300 --node=1 --frame=2
 *   php devtools/bin/debug-node.php ... --node=1 --frame=2 --json
 *   php devtools/bin/debug-node.php ... --node=1 --frame=2 --remote
 *
 * 参数：
 *   --node=N / --frame=N  必填：节点 id 与显式帧号
 *   其余驱动参数同 debug-brief / debug-network
 *
 * @internal 未进 VerifyPlan：S4 视图规则还在定
 */

require __DIR__ . '/../autoload.php';

use Drift\DevTools\Cli\SessionScope;
use Drift\DevTools\View\Align;

SessionScope::open('node', $argv)->run(static function (SessionScope $scope): int {
    $args = $scope->args;
    $node = $args->int('node');
    $frame = $args->int('frame');
    if ($node <= 0 || $frame <= 0) {
        \fwrite(\STDERR, "用法：debug-node <AppClass|路由id> --page=<路由id> --node=N --frame=N [--json] [--remote]\n");

        return 2;
    }

    $answer = $scope->query('align', ['node' => $node, 'frame' => $frame]);
    $scope->emit($answer, Align::lines(...));

    return 0;
});
