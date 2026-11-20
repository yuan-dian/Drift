<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 悬停提示 Tooltip。
 *
 * 形态对照 WinUI `ToolTip` / macOS 的 `NSView.toolTip`：**指针停留 500ms** 才出现，
 * 离开即消失，已显示时移到相邻控件立即换内容。
 *
 * 四段演示：
 *
 *   ① 基础：一排图标按钮，各带一句提示；
 *   ② 长文案：超过 320 会换行（面板有宽度上限，不会横穿窗口）；
 *   ③ 相邻切换：两个按钮紧挨着 —— 从一个移到另一个，**不再等 500ms**；
 *   ④ 没有提示的对照：悬停它什么都不该发生。
 *
 * ★ 应用侧只有一行 `->tooltip('…')`：浮层的生命周期、落位、时序全在框架
 *   （`Application::onHoverHit()` / `showTooltip()`）。这正是"声明式"相对
 *   "自己写 hover 回调 + 自己 push 浮层"的收益。
 * ★ 页末如实记录能力边界。
 */
final class TooltipPage extends StatefulWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new TooltipState();
    }
}

final class TooltipState extends State
{
    /** 点击计数（证明"提示层不吃点击"——提示盖住的按钮照样能点） */
    private int $clicks = 0;

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('悬停提示 Tooltip')->title(),
            Text::make('指针停留 500ms 才出现，离开即消失。应用侧只有一行 ->tooltip(…)。')
                ->caption(),

            $this->sectionBar('基础 · 停留 500ms'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                $this->icon('新建', '新建一个文档 (Ctrl+N)'),
                $this->icon('打开', '从磁盘打开 (Ctrl+O)'),
                $this->icon('保存', '保存当前文档 (Ctrl+S)'),
                $this->icon('导出', '导出为 PDF'),
            ]))->paddingAll(12.0),

            $this->sectionBar('长文案 · 面板限宽 320，超出换行'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                $this->icon('说明', '这是一段刻意写长的提示文案，用来验证面板有宽度上限：'
                    . '没有上限的话它会一路横穿整个窗口，右边被裁掉。'),
            ]))->paddingAll(12.0),

            $this->sectionBar('相邻切换 · 已显示时移到隔壁，不再等 500ms'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                $this->icon('上', '上一页 (PageUp)'),
                $this->icon('下', '下一页 (PageDown)'),
            ]))->paddingAll(12.0),

            $this->sectionBar('对照 · 没有 tooltip()'),
            Card::make(Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('没有提示')->onPressed(fn() => $this->bump()),
                Button::make('有提示')->tooltip('这个有')->onPressed(fn() => $this->bump()),
            ]))->paddingAll(12.0),
            Text::make('点击计数：' . $this->clicks . '（提示出现时点它盖住的按钮，照样算数 —— 提示不吃点击）')
                ->caption(),

            $this->sectionBar('能力边界（如实记录）'),
            Text::make('① 富内容提示（图片 / 多行带标题）：今天只有纯文本 —— 要富内容得开另一个'
                . ' `tooltipWidget()` 声明，等有消费者再立。')->caption(),
            Text::make('② 跟随指针的提示（Win32 的 tracking tooltip）：没有 —— 提示贴在锚点外侧，'
                . '锚点动它才动。')->caption(),
            Text::make('③ 键盘焦点也弹提示：没有 —— 无障碍的正解是语义树的 HelpText（UIA 侧），'
                . '那是 W8-6 的活，不是靠视觉提示兜。')->caption(),
        ]);
    }

    /** 一个带提示的按钮 */
    private function icon(string $label, string $tip): Widget
    {
        return Button::make($label)->tooltip($tip)->onPressed(fn() => $this->bump());
    }

    private function bump(): void
    {
        $this->setState(function (): void {
            $this->clicks++;
        });
    }

    private function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
