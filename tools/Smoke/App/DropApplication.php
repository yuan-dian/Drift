<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use App\Showcase\Pages\DropPage;
use Drift\App\Application;
use Drift\Widget\Widget;
/**
 * @internal 冒烟夹具：「拖文件」示例页的宿主应用（A5-②，§27）。
 *
 * ★ 只做一件事 —— 把 `DropPage` 挂成根。能力束用共享空实现（这类交互页不取数、不弹窗），
 *   与 `DialogApplication` 传入替身端口的差别只在注入内容。
 * ★ 存在意义：让「派发 FilesDroppedEvent ⇒ 命中/冒泡 ⇒ 页面状态流转」这条链在
 *   解释器面就能跑 —— 真机只能证明"能拖进来"，证明不了"落点命中与状态更新"。
 */
final class DropApplication extends Application
{
    public function buildRoot(): Widget
    {
        return (new DropPage())->host();
    }
}
