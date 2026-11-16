<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 图片获取端口 —— 由组合根装配，控件只认这个接口。
 *
 * ★ 故意不声明 `fetch()` 的返回类型：它是 `Drift\Async\Future`，而 Widget 层
 *   按 §4.3 不得依赖 Async。实现方（App 层）返回它，`State::asyncCommand()` 消费它，
 *   中间这层只负责把"怎么发起"递过去。
 */
interface ImageSource
{
    /** 磁盘缓存命中 ⇒ 同步解码出 handle；没命中返回 `null` */
    public function cached(string $url, int $width): ?ImageHandle;

    /**
     * 下载并在帧内回调 `onReady`。
     *
     * @param callable(ImageHandle): void $onReady
     * @return mixed 一条 Future（交给 State，不在这里声明类型）
     */
    public function fetch(string $url, int $width, callable $onReady): mixed;
}
