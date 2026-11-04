<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\DisplayList;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * 挂一页、跑一帧 —— 把这一帧的**两样产物**交出来：已布局的树、录到的命令流。
 *
 * ★ 存在的理由（2026-11-04）：两个指纹是**同一帧的两个投影**，而前置步骤
 *   （造 App、造表面、attach、settle）一模一样。各写一份 ⇒ 迟早漂移成
 *   "几何挂的是 A 参数、绘制挂的是 B 参数"。`DEBT` §37.5 ② 是同一笔账。
 *
 * ★ `attach()` 自己跑一帧（mount + settle + layout + paint）⇒ `commands()` 就是
 *   "这一页画出来的样子"。不需要再 `beginFrame()` —— 再跑一帧反而多一次
 *   布局，而那会掩盖"这一帧自洽吗"。
 *
 * ★ `frames === 0` 与"画了 0 条命令"是两件事：前者是页面没建起来，后者是
 *   真画了空白。`commands()` 用 `null` 区分，别让 "没画" 冒充 "画得对"。
 *
 * @internal 量具专用，不进公共 API
 */
final class PageFrame
{
    /** 与 `--pages` / `frame-tax-probe` 同口径的视口 */
    public const W = 1200.0;
    public const H = 800.0;

    private function __construct(
        private readonly ?Element $root,
        private readonly ?DisplayList $commands,
    ) {
    }

    public static function of(string $page, float $w = self::W, float $h = self::H): self
    {
        $app = new FrameTaxApp(static fn (): Widget => ShowcasePages::make($page));
        $surface = new SurfaceProbe();
        $surface->resize((int) $w, (int) $h, 1.0);
        $app->attach($surface, BoxConstraints::tight(new Size($w, $h)));

        return new self($app->root(), $surface->frames > 0 ? $surface->list() : null);
    }

    public function root(): ?Element
    {
        return $this->root;
    }

    /** 这一帧录到的命令流（`null` = 一帧都没跑） */
    public function commands(): ?DisplayList
    {
        return $this->commands;
    }
}
