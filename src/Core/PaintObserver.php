<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * 绘制事实的观察者。
 *
 * `Layout` 只知道自己画了哪个盒、是重放还是重录、层缓存为什么没命中；它不该知道
 * 调试器。`Pipeline` 做这层转接，采集器实现本接口即可，AOT 可达面不增加反射。
 *
 * ## 为什么契约住 `Core`
 *
 * 转接点 `Pipeline` 在 `Core`，而 `Core` 只允许依赖自己 —— 契约必须与总线同层，
 * 否则总线就得靠 `object` 弱类型偷偷转发（那样类型检查与 `@internal` 一起消失）。
 * 代价是 `Debug` 多一条指向 `Core` 的依赖，已写进 `lint dependency-direction`
 * 的白名单并注明理由。
 *
 * @internal 只由 `Debug\Session\Recorder` 实现
 */
interface PaintObserver
{
    /**
     * @param object $box 刚画过或刚重放过的渲染盒（`spl_object_id` 才是身份）
     * @param bool   $replayed true = 层缓存命中、只重放；false = 真的跑了 paint
     * @param string $reason 重录原因：first / invalidated / resized / capabilities / direct
     */
    public function notePaint(object $box, bool $replayed, string $reason): void;
}
