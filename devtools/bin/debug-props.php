#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-props` —— 属性域出口：按节点看 Widget / State 配置。
 *
 * 为什么不给 --frame：属性读的是**当前活树**，给一个历史帧号却返回现在的值
 * 就是伪称对齐。查询面回显 `liveFrame` 明说这是哪一帧读到的。
 *
 * 只在 dev 形态可用：反射拿不到私有字段时查询会报 `reflection-unavailable`，
 * 不会给一份空字段让人以为"这个控件没有配置"。
 *
 * 用法：
 *   php devtools/bin/debug-props.php --page=table --frames=1 --click=400,300 --node=12
 *   php devtools/bin/debug-props.php ... --node=12 --maxDepth=2 --maxFields=40 --json
 *   php devtools/bin/debug-props.php ... --node=12 --remote
 *
 * 参数：
 *   --node=N          必填：节点 id（可由 debug-panel / debug-brief 取到）
 *   --maxDepth=N      对象/数组展开深度（默认 3）
 *   --maxFields=N     每个对象/数组列几项（默认 120）
 *   其余驱动参数同 debug-node / debug-network
 *   --remote          用宿主进程跑（验证跨进程读到同一份证据）
 *   --json            结构化输出
 *
 * @internal 视图规则刚落地，未进 VerifyPlan
 */

require __DIR__ . '/../autoload.php';

use Drift\DevTools\Cli\SessionScope;
use Drift\DevTools\View\Props;

SessionScope::open('props', $argv)->run(static function (SessionScope $scope): int {
    $args = $scope->args;
    $node = $args->int('node');
    if ($node <= 0) {
        \fwrite(\STDERR, "用法：debug-props <AppClass|路由id> --node=N [--page=<路由id>] [--json] [--remote]\n");

        return 2;
    }

    $answer = $scope->query('props', [
        'node' => $node,
        'maxDepth' => $args->int('maxDepth'),
        'maxFields' => $args->int('maxFields'),
    ]);
    $scope->emit($answer, Props::lines(...));

    return 0;
});
