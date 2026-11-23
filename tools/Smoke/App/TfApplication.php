<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * IME / 输入层夹具应用：两个上下排的文本字段（§8.4 的最小触发形状）。
 *
 * ★ 存在理由：`Application::imeCaretRect()` 需要一棵**挂了焦点的 TextField 树**
 *   才能被测 —— 这是 §8.4 IME 定位链上唯一「框架侧算几何」的一段，做在头侧
 *   夹具里（坐标换算错了，候选框就贴错地方，而那个错误只有真机看得见）。
 *
 * @internal 测试夹具
 */
final class TfApplication extends Application
{
    /** 夹具用：拿到根 Element（坐标链断言要直接走盒，不经 Application） */
    public function rootElement(): ?Element
    {
        return $this->root();
    }

    public function buildRoot(): Widget
    {
        return Column::make()->paddingAll(24.0)->spacing(8.0)->children([
            TextField::make()->value('ab'),
            TextField::make()->value('cd'),
        ]);
    }
}
