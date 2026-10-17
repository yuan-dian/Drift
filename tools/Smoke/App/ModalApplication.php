<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Commands\CommandArg;
use Drift\Input\AcceleratorTable;
use Drift\Input\KeyChord;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Modal;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：模态（§A.3.7）的宿主应用。
 *
 * 根树给两个可点按钮（验证"遮罩后面的东西点不到"）；ESC 绑了一条加速键
 * （验证 `dismissible=false` 时 ESC 被**吞掉**，不落到第 ① 环）。
 */
final class ModalApplication extends Application
{
    /** @var list<string> 收到过的命令名 */
    public array $seen = [];

    /** 下次 `openConfirm()` 用的 `dismissible`（夹具按用例改） */
    public bool $dismissible = true;

    /** `buildRoot()` 里建的那个"背景"按钮的 Element（点它 = 穿透，必须不发生） */
    public ?Widget $backgroundButton = null;

    public function buildRoot(): Widget
    {
        $this->backgroundButton = Button::make('背景按钮')->command('bg.hit');

        return Column::make()->children([
            Button::make('打开模态')->command('modal.open'),
            $this->backgroundButton,
        ]);
    }

    public function commands(): iterable
    {
        $handler = new RecordingHandler(function (string $name, CommandArg $arg): void {
            $this->seen[] = $name;
        });

        return [
            'modal.open' => $handler,
            'modal.confirm' => $handler,
            'bg.hit' => $handler,
            'esc.pressed' => $handler,
        ];
    }

    /** ESC 加速键（第 ① 环）—— 模态吞键时它不该被调到 */
    public function accelerators(): AcceleratorTable
    {
        return AcceleratorTable::make()->bind(KeyChord::escape(), 'esc.pressed');
    }

    /** 夹具入口：按样板（§A.3.7）开一个确认框 */
    public function openConfirm(): void
    {
        $this->showModal(
            Modal::make()
                ->title('确认删除')
                ->child(
                    Column::make()->spacing(8.0)->children([
                        Text::make('删除后不可恢复，确定吗？')->body(),
                        Button::make('确定')->command('modal.confirm'),
                    ]),
                )
                ->dismissible($this->dismissible),
        );
    }
}
