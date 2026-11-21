<?php
declare(strict_types=1);

namespace App\Watermark;

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
use Drift\Widget\Environment;
use Drift\Widget\ScrollView;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Widget;

/**
 * 组合根（§A.2.6 / §12.2）：**只组合，不写任何具体布局**。
 *
 * ★ 一个 store + 一个门面由构造点注入；订阅点全在控件内部（三张卡片的
 *   `SubjectBuilder` + `StatusBar` 的 `watch`）—— 值**不在这里读**。
 * ★ 选图能力从门面拿（`$env->openFiles()`），不再由应用包一个 `\Closure(): void`
 *   往下递：端口类型（`Drift\Platform\Dialog`）不出 `Drift\App`，控件看到的只是
 *   "选完给我路径列表"。
 * ★ **主题固定 light**：本应用的配色是逐值照抄的品牌色（见 `Palette` 的类注释），
 *   没有深色表。`ThemeScope` 仍然必须有 —— `Button` / `TextField` / `AppBar` 这些
 *   框架控件的默认样式都从主题取，不套就是"控件全裸"。
 */
final class WatermarkRoot extends StatelessWidget
{
    public function __construct(
        private readonly WatermarkStore $store,
        private readonly Environment $env,
    ) {
        parent::__construct();
    }

    public static function make(WatermarkStore $store, Environment $env): self
    {
        return new self($store, $env);
    }

    public function build(BuildContext $ctx): Widget
    {
        return ThemeScope::make(ThemeData::light(), Dock::make()
            ->top(AppBar::make()->title('AI 图片处理工具'))
            // ★ 订阅在 StatusBar 内部（`SubjectBuilder`）：这里只给 store
            ->bottom(StatusBar::make($this->store))
            // ★ 滚动归**页根**（一张 `ScrollView` 包住三张卡片）：
            //   卡片里再套滚动就是嵌套滚动 —— 内层永远"量到全部内容"、外层滚不动。
            // ★ 底色靠 `Card`（`RenderProxy`：约束原样下传 ⇒ `ScrollView` 拿到紧约束
            //   填满中心槽）。换成 `Column->background()` 的话，内容比窗口短时底下会
            //   露一截窗口底色。
            ->center(Card::make(ScrollView::make(
                Column::make()->spacing(20)->children([
                    UploadCard::make($this->store, $this->env),
                    TaskCard::make($this->store),
                    ResultCard::make($this->store),
                ])
            ))->background(Palette::page())->paddingAll(20.0))
            // ★ `Clip` 而不是默认的 `Report`：窗口可以被拖得很窄（`setMinSize` 挡到
            //   420×320 为止），溢出在这里是**正常状态**、不该弹 Fatal。
            ->overflow(Overflow::Clip));
    }
}
