<?php
declare(strict_types=1);

namespace App\Showcase\Routes\Snackbar;

use Drift\Reducer\ReducerEffect;

/**
 * 「弹一条通知」这个副作用的**值**。
 *
 * 它只描述要弹什么，不持有 `notify` 闭包 —— 所以 `update()` 可以是纯函数：
 * 算出 effect、交给壳执行。单测断言"点了删除会产出带撤销的 effect"时，
 * 连能力束都不用造。
 *
 * @api
 */
final readonly class NotifyEffect implements ReducerEffect
{
    /**
     * @param string $message 通知正文
     * @param null|int $durationMs null = 用框架默认时长（4s）；0 = 永不自动消失
     * @param string $actionLabel '' = 无动作按钮
     */
    public function __construct(
        public string $message,
        public ?int $durationMs = null,
        public string $actionLabel = '',
    ) {
    }
}
