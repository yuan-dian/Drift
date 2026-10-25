<?php
declare(strict_types=1);

namespace App\Tutorial\Stages;

use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\SizedBox;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * ① 最小应用 —— 一个 `Center` 包一个 `Text`，就是全部。
 *
 * ★ 这段对应 `apps/hello`：**整个应用**就是
 *   `Application::buildRoot()` 返回这一个 Widget。本页把它缩小放进卡片里，
 *   好让四段并排看。
 *
 * ★ 为什么包一层 `SizedBox`：`Center` 在**无界高度**里会缩到子的大小，
 *   在 `Column` 里就等于"不居中"。给它一个确定高度，居中才有意义。
 *   （真应用里根 `Center` 拿到的是**紧约束**（整个客户区）⇒ 不需要这层。）
 */
final class HelloStage extends StatelessWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(8.0)->children([
            Text::make('① 最小应用')->heading(),
            Text::make('buildRoot() 返回这一个 Widget，窗口里就画这一个。')->caption(),

            Card::make(
                SizedBox::make(
                    Center::make(Text::make('你好，Drift')->title())
                )->height(96.0)
            ),
        ]);
    }
}
