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
    /**
     * @param null|\Closure(bool): void $onCloseConfirm 「关闭前确认」开关的回传口
     *   （为 `null` ⇒ 什么都不做）。★ 它不是端口、是**接线探针**：
     *   页面 → 应用这条方向只有它一处，用例要能证明"闭包真的被调了"。
     */
    public function __construct(
        public readonly FakeDialog $dialog,
        private ?\Closure $onCloseConfirm = null,
    ) {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return DialogPage::make(ShowcaseCaps::make($this->dialog, null, null, function (bool $enabled): void {
            // ★ 先取到局部再判空：AOT 上 `$this->可空字段?->m()` 会读成 null（MEMORY 第 21 条）。
            $sink = $this->onCloseConfirm;
            if ($sink !== null) {
                $sink($enabled);
            }
        }));
    }
}
