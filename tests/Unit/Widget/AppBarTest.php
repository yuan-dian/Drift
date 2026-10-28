<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\App\FocusManager;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderAppBar;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderObject;
use Drift\Rendering\ColorToken;
use Drift\Theme\ThemeData;
use Drift\Widget\AppBar;
use Drift\Widget\Button;
use Drift\Widget\IconButton;
use Drift\Widget\NavButton;
use Drift\Widget\Spacer;

/**
 * `AppBar`：一行布局、`windowButtons()` 的命令契约、`skipFocus()` 的 Tab 序。
 *
 * ## 三块判据，三种坏法
 *
 * | 块 | 坏法 | 现象 |
 * |---|---|---|
 * | 一行布局 + chrome 底 | 按钮压在标题上 / 底色没画 | 视觉错，但**不报错** |
 * | `windowButtons()` 命令名 | 写错一个字 | 按钮**点了没反应**（没人注册那个命令），不报错、不写日志 |
 * | `skipFocus()` | 整组工具按钮进 Tab 序 | 键盘用户要 Tab 过 5 个图标才到内容 |
 *
 * ★ `windowButtons()` 那条是 `AppBar` 与组合根 `WindowCommands` 之间的**唯一契约**，
 *   所以判据落在**命令名 + 顺序**上，不是"有三个按钮"。
 * ★ `skipFocus()` 必须**成对**验：只验"skipFocus ⇒ 空"会奖励"永远不进 Tab 序"
 *   的实现（那等于把工具按钮整个从键盘可达性里删掉）。
 */
final class AppBarTest extends SmokeTestCase
{
    /** @return list<RenderBox> */
    private static function kidsOf(?RenderBox $box): array
    {
        $kids = [];
        $box?->visitChildren(static function (RenderObject $kid) use (&$kids): void {
            if ($kid instanceof RenderBox) {
                $kids[] = $kid;
            }
        });

        return $kids;
    }

    /**
     * 标题与按钮排成一行（按钮在标题右侧、不重叠）。
     *
     * ★ 只验几何，**不验"有墨"** —— 见 `testChromeBackdropIsPainted`：在"标题 + 按钮"
     *   的形状上数非白像素，标题文字自己就够让判据变绿，底色整段没画也发现不了
     *   （2026-10-28 第 33 批变异 M4 实测）。
     */
    public function testBarPutsTitleAndActionsInOneRow(): void
    {
        $root = AppBar::make()->title('用户管理')->children([
            NavButton::make('列表')->command('nav.users'),
        ])->createElement();
        $root->mount(null, smokeContext());
        $box = $root->effectiveBox();
        $box?->layout(BoxConstraints::tight(new Size(300.0, 60.0)));

        check('AppBar · 渲染盒是 RenderAppBar（RenderFlex 的 chrome 子类）', $box instanceof RenderAppBar);

        $kids = self::kidsOf($box);
        check(
            'AppBar · 标题与按钮排成一行（按钮在标题右侧、不重叠）',
            \count($kids) === 2
                && $kids[1]->offsetX() >= $kids[0]->offsetX() + ($kids[0]->size()?->width ?? 0.0),
            \sprintf('%d 个子盒', \count($kids))
        );
    }

    /**
     * chrome 底：**无子项**的 `AppBar` 也有墨，且那块墨是主题的 `Chrome` 色。
     *
     * ★ 为什么必须用无子项的夹具：`inkCount()` 数的是"非白像素"，标题文字自己就有墨
     *   ⇒ 在"标题 + 按钮"的真实形状上验"有墨"，把 `paintOwnChrome()` 的背景分支整段
     *   关掉也照样绿。去掉子项之后，墨的**唯一来源**就是 chrome 底。
     * ★ 再钉颜色：只验"有墨"会奖励"画成任意非白色"的实现（深灰、反色都能过）。
     */
    public function testChromeBackdropIsPainted(): void
    {
        $root = AppBar::make()->createElement();
        $root->mount(null, smokeContext());
        $box = $root->effectiveBox();
        $box?->layout(BoxConstraints::tight(new Size(300.0, 60.0)));

        check('AppBar · 无子项也建出 RenderAppBar', $box instanceof RenderAppBar);
        if (!$box instanceof RenderAppBar) {
            return;
        }

        $canvas = spinCanvas($box, 300, 60);
        $chrome = ThemeData::light()->color(ColorToken::Chrome);

        $covered = 0;
        for ($y = 0; $y < 60; $y++) {
            for ($x = 0; $x < 300; $x++) {
                if ($canvas->pixelAt($x, $y)->equals($chrome)) {
                    $covered++;
                }
            }
        }

        check(
            'AppBar · chrome 底铺满整盒（Chrome 色 ≥ 90%，剩下的是描边）',
            $covered >= 18000 * 0.90,
            $covered . ' / 18000'
        );
        check(
            'AppBar · chrome 底色 = 主题 Chrome（不是任意非白色）',
            $canvas->pixelAt(150, 30)->equals($chrome),
            'rgb=' . describeColor($canvas->pixelAt(150, 30)) . '，期望 ' . describeColor($chrome)
        );
    }

    /**
     * `windowButtons()` 的三个命令名 + 贴右布局。
     *
     * ★ 顺序固定（最小化 / 最大化 / 关闭 从左到右）—— 写反了用户会点到关闭。
     * ★ 必须是**最后三个**、前面紧跟 `Spacer`：漏了 `Spacer` 按钮就贴在标题右边
     *   而不是贴窗口右边。
     * ★ 比 smoke 多验了 `$wbKids[3]`（最大化）：原来只验了 2 和 4，实现把中间那个
     *   换成别的控件类型时 `count === 5` 仍成立、判据照样绿。
     */
    public function testWindowButtonsContract(): void
    {
        $kids = AppBar::make()->title('条')->windowButtons()->barKids();

        $names = [];
        foreach ($kids as $kid) {
            if ($kid instanceof Button) {
                $names[] = $kid->commandName();
            }
        }
        check(
            'AppBar · windowButtons ⇒ 三个窗口按钮（标准命令名，顺序固定）',
            $names === ['window.minimize', 'window.maximize', 'window.close'],
            'names=' . \implode(',', \array_map(static fn(?string $n): string => (string) $n, $names))
        );
        check(
            'AppBar · windowButtons ⇒ 按钮贴右边（前面紧跟弹性占位，且是最后三个子项）',
            \count($kids) === 5
                && $kids[1] instanceof Spacer
                && $kids[2] instanceof IconButton
                && $kids[3] instanceof IconButton
                && $kids[4] instanceof IconButton,
            'kids=' . \count($kids)
        );
        check(
            'AppBar · 不声明 windowButtons ⇒ 一个窗口按钮都不画（有原生标题栏的窗口不该有两排）',
            \array_filter(
                AppBar::make()->title('条')->barKids(),
                static fn($k): bool => $k instanceof Button && \str_starts_with((string) $k->commandName(), 'window.'),
            ) === [],
            '意外画了窗口按钮'
        );
    }

    /**
     * `skipFocus()`：整组工具按钮**不进** Tab 序；不声明时**照常进**。
     *
     * ★ 两条成对：只验前者会奖励"工具按钮永远不可聚焦"（键盘可达性被整个删掉）。
     */
    public function testSkipFocusKeepsActionsOutOfTabOrder(): void
    {
        $skipRoot = AppBar::make()->children([
            NavButton::make('a')->command('x'),
            NavButton::make('b')->command('y'),
        ])->skipFocus()->createElement();
        $skipRoot->mount(null, smokeContext());
        $skipFm = new FocusManager();
        $skipFm->collect([$skipRoot]);
        check('AppBar · skipFocus ⇒ 整组按钮不进 Tab 序', $skipFm->order() === []);

        $keepRoot = AppBar::make()->children([
            NavButton::make('a')->command('x'),
            NavButton::make('b')->command('y'),
        ])->createElement();
        $keepRoot->mount(null, smokeContext());
        $keepFm = new FocusManager();
        $keepFm->collect([$keepRoot]);
        check('AppBar · 不声明 skipFocus ⇒ 按钮照常进 Tab 序（2 个）', \count($keepFm->order()) === 2);
    }
}
