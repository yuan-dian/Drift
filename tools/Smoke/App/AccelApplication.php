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
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：带加速键表的应用（§9.1 第 ① 环）。
 *
 * `Ctrl+R` 与 `Ctrl+A` 两条加速键；`$withClaimer` 打开时给 TextField 声明
 * 独占 `Ctrl+A`（§9.1 让位规则 ②：编辑命令优先）。
 */
final class AccelApplication extends Application
{
    /** @var list<string> 收到过的命令名（顺序即派发顺序） */
    public array $seen = [];

    public bool $withClaimer = false;

    public function buildRoot(): Widget
    {
        $field = TextField::make();
        if ($this->withClaimer) {
            $field->claimKeys([KeyChord::ctrl('A')]);
        }

        return Column::make()->children([
            Center::make(Text::make('加速键夹具')),
            $field,
        ]);
    }

    public function accelerators(): AcceleratorTable
    {
        return AcceleratorTable::make()
            ->bind(KeyChord::ctrl('R'), 'accel.fire')
            ->bind(KeyChord::ctrl('A'), 'accel.selectAll');
    }

    public function commands(): iterable
    {
        return [
            'accel.fire' => new AccelHandler($this),
            'accel.selectAll' => new AccelHandler($this),
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
