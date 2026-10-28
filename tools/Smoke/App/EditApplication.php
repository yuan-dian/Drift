<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Input\AcceleratorTable;
use Drift\Input\KeyChord;
use Drift\Platform\Clipboard;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Padding;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * 编辑命令 / 指针框选夹具应用（2026-10-21，#50）。
 *
 * 形状与 `TfApplication` 同：`padding(24)` 的 Column 里两个字段（`abc` / `xyz`）
 * —— 几何确定性优先（不赌字模），指针坐标按盒的原点算。
 *
 * ★ 两条**互相拉扯**的绑定，用来验门控的"回落"语义：
 *   `Ctrl+C` / `Ctrl+V` 各绑一条加速键 ⇒ 门控放行时它们必须开火（证明键没有
 *   被吞掉），编辑命令环吃掉时 `seen` 必须不动（证明优先级正确）。
 *
 * @internal 测试夹具
 */
final class EditApplication extends Application
{
    /** @var list<string> 收到过的命令名（顺序即派发顺序） */
    public array $seen = [];

    /** 夹具剪贴板（内存假实现；`null` = 平台没递端口进来） */
    public ?FakeClipboard $clip = null;

    public function buildRoot(): Widget
    {
        return Padding::make(
            Column::make()->children([
                TextField::make()->value('abc'),
                TextField::make()->value('xyz'),
            ])
        )->all(24.0);
    }

    public function clipboard(): ?Clipboard
    {
        return $this->clip;
    }

    public function accelerators(): AcceleratorTable
    {
        return AcceleratorTable::make()
            ->bind(KeyChord::ctrl('C'), 'app.copy')
            ->bind(KeyChord::ctrl('V'), 'app.paste');
    }

    public function commands(): iterable
    {
        return [
            'app.copy' => new EditHandler($this),
            'app.paste' => new EditHandler($this),
        ];
    }

    /** 夹具用：坐标断言要直接走盒（与 `TfApplication` 同一形态） */
    public function rootElement(): ?Element
    {
        return $this->root;
    }
}

/** @internal 冒烟夹具 */
final class EditHandler implements CommandHandler
{
    public function __construct(private EditApplication $app)
    {
    }

    public function handle(string $name, CommandArg $arg): bool
    {
        $this->app->seen[] = $name;

        return true;
    }
}
