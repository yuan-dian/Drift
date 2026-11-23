<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Padding;
use Drift\Widget\TextArea;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * 文本选区的**多击**夹具（双击选词 / 三击选段 / Shift+点延伸）。
 *
 * 形状照 `EditApplication`：`padding(24)` 的 Column，几何确定性优先。
 * 与它的差别只有一处：**文本带空格与硬换行**。
 *
 * ★★ 为什么必须是多词多行：单个词（`EditApplication` 的 `abc`）上"选词"与
 *    "选段"得到**同一个区间** ⇒ 两个断言都过，等于什么都没量。这里的
 *    `hello world foo` 与两行的 `alpha beta\ngamma delta` 让"选词 ≠ 选段"、
 *    "第一段 ≠ 第二段"两件事都成立。
 * ★ 坐标一律用**边缘**（`bx + 1` / `bx + 宽度 - 1`）：边缘的落点由夹取决定
 *   （左缘 ⇒ 0，右缘 ⇒ 文本长），不依赖字体度量 ⇒ 换主题字体不会静默失准。
 *
 * @internal 测试夹具
 */
final class WordSelectApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Padding::make(
            Column::make()->children([
                // 单行：三个词
                TextField::make()->value('hello world foo'),
                // 多行：两段（硬换行），每段两个词
                TextArea::make()->value("alpha beta\ngamma delta")->lines(2),
            ])
        )->all(24.0);
    }

    /** 夹具用：坐标与盒断言要直接走树（与 `EditApplication` 同一形态） */
    public function rootElement(): ?Element
    {
        return $this->root();
    }
}
