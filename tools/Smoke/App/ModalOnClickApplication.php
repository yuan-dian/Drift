<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Input\CursorShape;
use Drift\Input\InputEvent;
use Drift\Platform\Dialog;
use Drift\Widget\Spinner;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：根是 `Spinner`（保证帧节奏），**第一条输入就弹一个原生框**。
 *
 * 存在的理由（2026-10-30 真机事故）：`Dialog::alert()` 是**同步原生模态**，
 * 而它几乎总是发生在"派发输入"里（用户点按钮）。帧时钟的模态账本原来只在
 * 派发**之前**读一次 ⇒ 这段时间没被剔除 ⇒ 关掉框之后弹一个
 * "两帧间隔 N ms"的 RECOVERABLE。这条夹具把那个时序逼成可无头复现的形态。
 *
 * ★ 用 `Spinner` 而不是空树：`wantsFrame()` 为 false 时 `nextWaitMs()` 回 `-1`
 *   （无限等），帧时钟**根本不量间隔** —— 那样这个夹具验不到任何东西。
 */
final class ModalOnClickApplication extends Application
{
    private bool $fired = false;

    public function __construct(private readonly Dialog $dialog)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return Spinner::make()->diameter(40.0);
    }

    public function title(): string
    {
        return '点击弹原生框';
    }

    public function dispatchInput(InputEvent $event, ?float $timestampMs = null): ?CursorShape
    {
        if (!$this->fired) {
            $this->fired = true;
            $this->dialog->alert('提示', '模拟用户在原生框里看了一会儿');
        }

        return parent::dispatchInput($event, $timestampMs);
    }
}
