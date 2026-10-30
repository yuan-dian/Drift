<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderAppBar;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\ColorToken;
use Drift\Rendering\MetricToken;

/**
 * 顶条（§12.1 第 3 条 `top` 槽的常驻内容 / §A.3.1 菜单栏的宿主）。
 *
 * ```php
 * AppBar::make()->title('用户管理')->children([
 *     NavButton::make('列表')->command('nav.users'),
 * ])
 * ```
 *
 * ★ **左右两组，不是一组长条**（2026-10-25）：`children()` 是**导航区**（左），
 *   `actions()` 是**动作区**（右）—— 顶条的形态本来就是"左边指路、右边动手"，
 *   合成一组就得让每个调用点自己记着塞 `Spacer`。`actions()` 非空时内部自动插
 *   一个弹性占位，导航短、动作多时右侧那组仍贴右边（不是被导航推着走）。
 *   ★ 只有一组时（`actions` 为空）**不插占位** —— 少一个无意义的弹性项，
 *   既省一次布局分枝，也让"纯菜单栏形态"（§A.2.6）的子序列逐字不变。
 *
 * ★ **外壳类容器**（W4 / §24.1 #36 的类别判据）：溢出策略由 `RenderAppBar`
 *   **显式声明 `Clip`**（2026-10-19 改，原 `Report`）—— 顶条宽度**随窗口变化**，
 *   用户拖窄窗口时放不下是**用户行为**、不是编程错误；`Report` 会让拖动缩窗
 *   直接弹 Fatal（实测：可用 322 / 自然 327）。裁剪 = 右侧导航项暂时不可见。
 *   ★ 窗口帧另有全局兜底：`LayoutTolerance::$windowDriven`（窗口比内容小一律裁剪）。
 *
 * ★ `->skipFocus()` 由 `Widget` 基类给（§A.3.4：工具按钮组整体不进 Tab 序）——
 *   本类不重造焦点机制。
 *
 * @api
 */
final class AppBar extends RenderBoxWidget
{
    private string $title = '';

    /** @var list<Widget> 导航区（左） */
    private array $kids = [];

    /** @var list<Widget> 动作区（右） */
    private array $actions = [];

    /**
     * 是否在动作区右侧画三个窗口按钮（最小化 / 最大化 / 关闭）。P1-Stage 1b。
     *
     * ★ 默认 `false`：有原生标题栏的窗口**不该**再画一套 —— 会出现两排按钮，
     *   而且上面那排是死的（原生按钮在非客户区，客户区里的那排才是我们的）。
     */
    private bool $windowButtons = false;

    public static function make(): self
    {
        return new self();
    }

    /**
     * 画三个窗口控制按钮（P1-Stage 1b 无边框窗）。
     *
     * ```php
     * // 组合根（无边框 opt-in）
     * AppBar::make()->title('用户管理')->windowButtons(true)
     * ```
     *
     * ★ 三个按钮发的是**标准命令名**（`window.minimize` / `window.maximize` /
     *   `window.close`），由组合根注册 —— 共享代码里不该出现 `WindowHost`
     *   （§5.1：句柄不跨层）。命令名固定是为了"换应用不用改顶条"。
     * ★ 按钮**必须**是真正的 `Activatable`：`InputRouter::windowDragOwner()`
     *   遇到可激活控件就让位，所以点按钮不会被顶条拖动吃掉。
     * ★ 三个按钮都用 `Flat`（无实心底）：与 Windows / macOS 的窗口按钮一致 ——
     *   关闭是**常用但非破坏性**的动作，常驻红底会把注意力从内容上拽走。
     *   `Danger` 变体留给真正的"不可撤销"按钮（删除 / 清空）。
     */
    public function windowButtons(bool $enabled = true): static
    {
        $this->mutate();
        $this->windowButtons = $enabled;

        return $this;
    }

    /** 条首标题（`''` = 不画 —— §A.2.6 的纯菜单栏形态） */
    public function title(string $text): static
    {
        $this->mutate();
        $this->title = $text;

        return $this;
    }

    /** @param list<Widget> $kids 导航区（左） */
    public function children(array $kids): static
    {
        $this->mutate();
        $this->kids = $kids;

        return $this;
    }

    /**
     * 动作区（右）—— 通常是纯图标按钮。
     *
     * ```php
     * AppBar::make()->title('用户管理')
     *     ->children([NavButton::make('列表')->command('nav.users')])
     *     ->actions([
     *         IconButton::of(IconName::Search, '搜索')->variant(ButtonVariant::Flat),
     *     ]);
     * ```
     */
    public function actions(array $kids): static
    {
        $this->mutate();
        $this->actions = $kids;

        return $this;
    }

    /** @internal `AppBarElement` 读它 */
    public function titleText(): string
    {
        return $this->title;
    }

    /** @internal */
    public function kidList(): array
    {
        return $this->kids;
    }

    /** @internal */
    public function actionList(): array
    {
        return $this->actions;
    }

    /**
     * 展开后的完整子序列：标题 + 导航 + （有动作时）弹性占位 + 动作。
     *
     * ★ 标题 Text 在这里现造 —— 与 `StatelessWidget::build()` 同一条纪律
     *   （重建即换新描述，diff 按位置复用 Element），不引入缓存字段。
     * ★ 占位只在 `actions` 非空时出现：纯导航条的子序列逐字不变（见类注释）。
     *
     * @internal
     */
    public function barKids(): array
    {
        $out = [];

        if ($this->title !== '') {
            $out[] = Text::make($this->title)->title();
        }
        foreach ($this->kids as $kid) {
            $out[] = $kid;
        }

        if ($this->actions !== [] || $this->windowButtons) {
            $out[] = Spacer::make();
            foreach ($this->actions as $kid) {
                $out[] = $kid;
            }
        }

        if ($this->windowButtons) {
            // ★ 三个按钮是**普通命令按钮**，不是"框架魔法"：组合根注册
            //   `window.*` 三个 handler 即可，换应用不用改这里（P1-Stage 1b）。
            $out[] = IconButton::of(IconName::Minus, '最小化')
                ->variant(ButtonVariant::Flat)->command('window.minimize');
            $out[] = IconButton::of(IconName::Square, '最大化')
                ->variant(ButtonVariant::Flat)->command('window.maximize');
            $out[] = IconButton::of(IconName::Close, '关闭')
                ->variant(ButtonVariant::Flat)->command('window.close');
        }

        return $out;
    }

    /**
     * 用户设的 `->background()` / `->border()` / `->radius()` **逐字段覆盖**主题盒。
     *
     * ★ S1（2026-10-28）之前这三项在 `AppBar` 上是**死字段**：`BoxWidget` 把值存进
     *   `$box`，而本方法直接 `new BoxStyle(主题…)` —— 存了但没人读。现在样式只有
     *   `$mods` 一份存储，合并写在这里，与 `Button` / `PopupPanel` 同一口径。
     */
    public function createRenderObject(BuildContext $context): RenderBox
    {
        $theme = $context;
        $pad = $theme->metric(MetricToken::Padding);

        $themeBox = new BoxStyle(
            $theme->color(ColorToken::Chrome),
            $theme->color(ColorToken::Border),
            $theme->metric(MetricToken::BorderWidth),
            0.0,
        );

        $userBox = $this->mods()->boxStyle();
        if ($userBox === null) {
            $style = $themeBox;
        } else {
            $hasBorder = $userBox->hasBorder();
            $style = new BoxStyle(
                $userBox->background ?? $themeBox->background,
                $hasBorder ? $userBox->borderColor : $themeBox->borderColor,
                $hasBorder ? $userBox->borderWidth : $themeBox->borderWidth,
                $userBox->radius > 0.0 ? $userBox->radius : $themeBox->radius,
                $userBox->backgroundGradient,
            );
        }

        return new RenderAppBar(
            $style,
            $theme->metric(MetricToken::Gap),
            EdgeInsets::symmetric($pad, $pad * 0.5),
        );
    }

    /** @internal 见 `RenderBoxWidget::paintsBoxChrome()`：`RenderAppBar` 画底与 1px 底边 */
    public function paintsBoxChrome(): bool
    {
        return true;
    }

    #[\Override]
    public function createElement(): Element
    {
        return new AppBarElement($this);
    }
}
