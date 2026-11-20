<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use Drift\Widget\Card;
use Drift\Widget\State;
use Drift\Widget\Widget;
use App\Showcase\Pages\States\DropPageState;

/**
 * 拖文件进窗 Drop—— A5-② 的唯一消费者（落地记录 §27）。
 *
 * ★★ **为什么这一页必须存在**：`WM_DROPFILES` 通道是新开的，没有调用方就没人能
 *   回答"拖进来之后 UI 说什么""命中怎么算""没接收者会怎样"。这一页是它唯一的
 *   落点，也是 T2 真机取证的落点（§16：未被消费的分支 = 未实现）。
 *
 * ★ **声明挂在根 Widget 上**：`Card` 上 `->onFilesDropped(...)`，命中点沿父链
 *   冒泡总能找到它 ⇒ 拖到本页任意位置都算，不需要一个大号"拖放区"来接。
 *   视觉上仍给一块提示区，暗示"这里是目标"。
 *
 * ★ **状态存字符串而不是数组**（`$lastPaths` 是 `'\n'` 分隔原始串）：数组字段跨
 *   实例读在 AOT 上是 C2679/`php::Var` 高发区（§24）；显示时才 explode。
 *
 * ★ 构造无必填参数 ⇒ 能进 golden 整页基线（不像 `DialogPage` 要递端口）。
 */
#[Route(id: 'drop', title: '拖文件 Drop', icon: IconName::Square)]
final class DropPage extends StatefulShowcasePage
{
    public function createState(): State
    {
        return new DropPageState($this->caps);
    }
}
