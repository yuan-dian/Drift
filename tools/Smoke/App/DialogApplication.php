<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use App\Showcase\Pages\DialogPage;
use Drift\App\Application;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：「对话框」示例页的宿主应用（A5-①）。
 *
 * ★ 只做一件事 —— 把 `DialogPage` 挂成根，并把 `FakeDialog` 递进去。
 *   它的存在意义是让「点按钮 ⇒ 端口被调一次 ⇒ 结果写回页面」这条链在**解释器**
 *   面就能跑（真机只能证明"对话框弹出来了"，证明不了返回值怎么落到 UI 上）。
 * ★ 端口是**构造参数**而不是 `new FakeDialog()` 写死：用例要能先设 `nextPath`
 *   再点按钮（"取消"与"选了文件"是两条不同的分支）。
 */
final class DialogApplication extends Application
{
    public function __construct(public readonly FakeDialog $dialog)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return DialogPage::make($this->dialog);
    }
}
