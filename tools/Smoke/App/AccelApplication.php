<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Input\AcceleratorTable;
use Drift\Input\KeyChord;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：带加速键表的应用（§9.1 第 ② 环）。
 *
 * `Ctrl+R` 与 `Ctrl+A` 两条加速键，焦点里有一个内容为 `abc` 的 `TextField`。
 * ★ `Ctrl+A` 两条路径都认领（输入框全选 / 应用 `accel.selectAll`）⇒ 这个夹具
 *   专门用来钉"焦点在哪就归谁"（2026-11-03 顺序裁决）。
 */
final class AccelApplication extends Application
{
    /** @var list<string> 收到过的命令名（顺序即派发顺序） */
    public array $seen = [];

    /** 夹具用：断言要读字段的选区状态（同 `TfApplication` 形态） */
    public function rootElement(): ?Element
    {
        return $this->root;
    }

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Center::make(Text::make('加速键夹具')),
            TextField::make()->value('abc'),
        ]);
    }

    public function accelerators(): AcceleratorTable
    {
        return AcceleratorTable::make()
            ->bind(KeyChord::ctrl('R'), 'accel.fire')
            ->bind(KeyChord::ctrl('A'), 'accel.selectAll')
            // ★ 故意与编辑键撞车：焦点在输入框时它必须收不到（见 `TextFieldUndoKeyTest`）
            ->bind(KeyChord::ctrl('Z'), 'accel.undo');
    }

    public function commands(): iterable
    {
        return [
            'accel.fire' => new AccelHandler($this),
            'accel.selectAll' => new AccelHandler($this),
            'accel.undo' => new AccelHandler($this),
        ];
    }
}

/** @internal 冒烟夹具 */
final class AccelHandler implements CommandHandler
{
    public function __construct(private AccelApplication $app)
    {
    }

    public function handle(string $name, CommandArg $arg): bool
    {
        $this->app->seen[] = $name;

        return true;
    }
}
