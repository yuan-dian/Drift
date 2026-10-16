<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Widget\AppBar;
use Drift\Widget\Button;
use Drift\Widget\Center;
use Drift\Widget\Dock;
use Drift\Widget\NavButton;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/** @internal 记录激活的 handler（具名类 —— O-15 方案 E：handler 必须是类） */
final class NavRecorder implements CommandHandler
{
    /** @var list<string> */
    public array $seen = [];

    public function handle(string $name, CommandArg $arg): bool
    {
        $this->seen[] = $name . '#' . $arg->i . '/' . $arg->s;

        return true;
    }
}

/** @internal 冒烟夹具：外壳 + 导航按钮 + 两条激活路径（§12.2 / §9.1）。 */
final class NavApplication extends Application
{
    /** @var list<string> */
    public array $pressed = [];

    private NavRecorder $recorder;

    public function __construct()
    {
        parent::__construct(); // ★ 不调 ⇒ Application::$router 未初始化（构造链：父先子后）
        $this->recorder = new NavRecorder();
    }

    public function recorder(): NavRecorder
    {
        return $this->recorder;
    }

    public function buildRoot(): Widget
    {
        return Dock::make()
            ->top(AppBar::make()->title('外壳')->children([
                NavButton::make('列表')->command('nav.users')->arg(CommandArg::id(7)),
                Button::make('取消')->onPressed(function (): void {
                    $this->pressed[] = 'cancelled';
                }),
            ]))
            ->center(Center::make()->child(Text::make('内容')));
    }

    public function title(): string
    {
        return '外壳冒烟';
    }

    /** @return iterable<string, CommandHandler> */
    public function commands(): iterable
    {
        return ['nav.users' => $this->recorder];
    }
}
