<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Theme\SystemTheme;
use Drift\Theme\ThemeData;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 主题切换夹具应用（§9.2 / §A.2.5）：**跟随系统主题**的最小形态。
 *
 * ★ 存在理由：`Application::onSystemThemeChanged()` 钩子与 `applyNewContext()`
 *   的下发链需要一棵"主题真的会变"的树才能被测。应用侧职责就是覆写 `theme()`
 *   并在钩子里换源 —— 框架侧只负责"通知 + 下发渲染环境"。
 *
 * @internal 测试夹具
 */
final class ThemeApplication extends Application
{
    private ThemeData $themeData;

    public function __construct()
    {
        $this->themeData = ThemeData::forSystem(SystemTheme::Light);
    }

    /** 夹具用：直接读当前主题（断言它有没有被换掉） */
    public function currentTheme(): ThemeData
    {
        return $this->themeData;
    }

    public function theme(): ThemeData
    {
        return $this->themeData;
    }

    protected function onSystemThemeChanged(): void
    {
        // 模拟应用重读系统主题（真实实现里从持有的 `Screen` 读）
        $this->themeData = ThemeData::forSystem(SystemTheme::Dark);
    }

    /** 夹具用：拿到根 Element（断言 context 有没有被换掉） */
    public function rootElement(): ?Element
    {
        return $this->root;
    }

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Text::make('hello'),
        ]);
    }
}
