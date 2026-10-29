<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use App\Showcase\Pages\DropPage;
use Drift\App\Application;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：「拖文件」示例页的宿主应用（A5-②，§27）。
 *
 * ★ 只做一件事 —— 把 `DropPage` 挂成根。`DropPage` 构造无必填参数，所以这个
 *   夹具不递任何端口（与 `DialogApplication` 的差别）。
 * ★ 存在意义：让「派发 FilesDroppedEvent ⇒ 命中/冒泡 ⇒ 页面状态流转」这条链在
 *   解释器面就能跑 —— 真机只能证明"能拖进来"，证明不了"落点命中与状态更新"。
 */
final class DropApplication extends Application
{
    public function buildRoot(): Widget
    {
        return new DropPage();
    }
}
