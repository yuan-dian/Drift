<?php
declare(strict_types=1);

namespace App\Watermark;

use App\Watermark\Store\StatusMessage;
use App\Watermark\Store\WatermarkStore;
use App\Watermark\Widgets\Palette;
use App\Watermark\Widgets\ResultCard;
use App\Watermark\Widgets\StatusBar;
use App\Watermark\Widgets\TaskCard;
use App\Watermark\Widgets\UploadCard;
use Drift\Layout\Overflow;
use Drift\Theme\ThemeData;
use Drift\Theme\ThemeScope;
use Drift\Widget\AppBar;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Dock;
use Drift\Widget\ScrollView;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Widget;

/**
 * 组合根（§A.2.6 / §12.2）：**只组合，不写任何具体布局**。
 *
 * ★ 一个 store 由构造点注入；订阅点全在控件内部（三张卡片的 `SubjectBuilder` +
 *   `StatusBar` 的 `watch`）—— 值**不在这里读**。
 * ★ `$pickFiles` 由**应用**注入（`WatermarkApplication::buildRoot()`）：Widget 不许
 *   依赖 `Drift\Platform`（§4.3）⇒ 打开文件对话框这个能力以闭包形式往下递。
 *   与 `UsersRoot::menuOpener()` 同一条路。
 * ★ **主题固定 light**：本应用的配色是逐值照抄的品牌色（见 `Palette` 的类注释），
 *   没有深色表。`ThemeScope` 仍然必须有 —— `Button` / `TextField` / `AppBar` 这些
 *   框架控件的默认样式都从主题取，不套就是"控件全裸"。
 */
final class WatermarkRoot extends StatelessWidget
{
    /**
     * @param \Closure(): void $pickFiles 打开文件对话框（组合根注入）
     */
    public function __construct(
        private readonly WatermarkStore $store,
        private readonly \Closure $pickFiles,
    ) {
        parent::__construct();
    }

    /**
     * @param \Closure(): void $pickFiles
     */
    public static function make(WatermarkStore $store, \Closure $pickFiles): self
    {
        return new self($store, $pickFiles);
    }

    public function build(BuildContext $ctx): Widget
    {
        return ThemeScope::make(ThemeData::light(), Dock::make()
            ->top(AppBar::make()->title('AI 图片处理工具'))
            // ★ 传 store + 切片函数，由 StatusBar 内部 select 订阅
            ->bottom(StatusBar::make()
                ->source($this->store)
                ->select(static fn(WatermarkStore $s): StatusMessage => $s->statusLine()))
            // ★ 滚动归**页根**（一张 `ScrollView` 包住三张卡片）：
            //   卡片里再套滚动就是嵌套滚动 —— 内层永远"量到全部内容"、外层滚不动。
            // ★ 底色靠 `Card`（`RenderProxy`：约束原样下传 ⇒ `ScrollView` 拿到紧约束
            //   填满中心槽）。换成 `Column->background()` 的话，内容比窗口短时底下会
            //   露一截窗口底色。
            ->center(Card::make(ScrollView::make(
                Column::make()->spacing(20)->children([
                    UploadCard::make($this->store, $this->pickFiles),
                    TaskCard::make($this->store),
                    ResultCard::make($this->store),
                ])
            ))->background(Palette::page())->paddingAll(20.0))
            // ★ `Clip` 而不是默认的 `Report`：窗口可以被拖得很窄（`setMinSize` 挡到
            //   420×320 为止），溢出在这里是**正常状态**、不该弹 Fatal。
            ->overflow(Overflow::Clip));
    }
}
