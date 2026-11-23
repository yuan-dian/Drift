<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * 语义写值夹具（§4.4 提交 2）：一个**接住** `onChanged` 的 TextField。
 *
 * ★ 存在理由：`Application::setSemanticsValue()` 的判据要落在**回写值**上
 *   （`onChanged` 真收到什么），不是"方法返回 true" —— 后者在"顺着父链找到了
 *   控件但没人接值"时照样绿，等于没测。
 * ★ 与 `TfApplication` 的分工：那个是 IME 几何夹具（TextField 不接回调），
 *   正好当"受控但没人接 ⇒ setSemanticsValue 回 false"的反例。
 *
 * @internal 测试夹具
 */
final class SemValueApplication extends Application
{
    /** 最近一次 `onChanged` 收到的值（null = 还没收到） */
    public ?string $got = null;

    /** 夹具用：拿到根 Element（语义树要直接建在它上面） */
    public function rootElement(): ?Element
    {
        return $this->root();
    }

    public function buildRoot(): Widget
    {
        return Column::make()->paddingAll(24.0)->children([
            TextField::make()
                ->value('abc')
                ->label('搜索')
                ->onChanged(function (string $v): void {
                    $this->got = $v;
                }),
        ]);
    }
}
