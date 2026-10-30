<?php
declare(strict_types=1);

/**
 * @internal lint 夹具：**手写消息循环**的组合根（`app-bootstrap-loop` 的"该响"一侧）。
 *
 * ★ 它写的是 **N2 之后**最容易犯的那个版本：等待用了新接口（`waitEvents()`），
 *   却忘了把等到的消息派发出去（`pumpEvents()`）。后果是"窗口对点击毫无反应"，
 *   同样**不报错** —— 所以只能靠这条静态闸门。
 *
 * ★ 不要"修好"这个文件：它是**反例**。真实组合根在 `apps/<name>/bin/<os>/`，
 *   它们都走 `EventLoop::run()`。
 */

$platform = new \Drift\Platform\Win32\Win32Platform();
$handle = $platform->window()->createWindow('反例', 320, 240);
$app->attach($platform->surface($handle)?->surface(), $platform->screen($handle)->viewport());

while (true) {
    if ($platform->waitEvents(-1.0) === \Drift\Platform\WakeReason::Quit) {
        break;
    }

    // ← 少了 `$platform->pumpEvents();`：事件永远进不了 input() 队列
    $app->frame($platform->now(), $platform->screen($handle)->viewport());
}
