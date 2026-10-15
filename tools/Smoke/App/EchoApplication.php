<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Commands\CommandHandler;
use Drift\Dev\Smoke\Commands\CommandWidget;
use Drift\Dev\Smoke\Commands\EchoCommands;
use Drift\Widget\Column;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：一个最小但完整的应用（§4.5 的"只写 buildRoot"）。 */
final class EchoApplication extends Application
{
    public bool $kerned = false;

    /** 打开 ⇒ 根里多一个会接 `widget.echo` 的 State，用来验冒泡顺序 */
    public bool $withCommandWidget = false;

    public function buildRoot(): Widget
    {
        $this->kerned = true;

        $kids = [Text::make('第一行'), Text::make('第二行')];
        if ($this->withCommandWidget) {
            $kids[] = new CommandWidget();
        }

        return Column::make()->children($kids);
    }

    public function title(): string
    {
        return '冒烟应用';
    }

    /** @return iterable<string, CommandHandler> */
    public function commands(): iterable
    {
        return ['echo.hello' => new EchoCommands()];
    }
}
