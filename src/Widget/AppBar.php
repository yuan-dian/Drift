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
 * ★ **外壳类容器**（W4 / §24.1 #36 的类别判据）：会超 ⇒ 溢出策略随
 *   `RenderAppBar` 显式 `Report`，不给默认 `Clip` 的静默。
 *
 * ★ `->skipFocus()` 由 `Widget` 基类给（§A.3.4：工具按钮组整体不进 Tab 序）——
 *   本类不重造焦点机制。
 *
 * @api
 */
final class AppBar extends BoxWidget
{
    private string $title = '';

    /** @var list<Widget> */
    private array $kids = [];

    public static function make(): self
    {
        return new self();
    }

    /** 条首标题（`''` = 不画 —— §A.2.6 的纯菜单栏形态） */
    public function title(string $text): static
    {
        $this->mutate();
        $this->title = $text;

        return $this;
    }

    /** @param list<Widget> $kids */
    public function children(array $kids): static
    {
        $this->mutate();
        $this->kids = $kids;

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

    public function createRenderObject(BuildContext $context): RenderBox
    {
        $theme = $context;
        $pad = $theme->metric(MetricToken::Padding);

        return new RenderAppBar(
            new BoxStyle(
                $theme->color(ColorToken::Chrome),
                $theme->color(ColorToken::Border),
                $theme->metric(MetricToken::BorderWidth),
                0.0,
            ),
            $theme->metric(MetricToken::Gap),
            EdgeInsets::symmetric($pad, $pad * 0.5),
        );
    }

    public function createElement(): Element
    {
        return new AppBarElement($this);
    }
}
