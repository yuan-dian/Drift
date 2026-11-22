<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Api\TaskResult;
use Drift\Geometry\EdgeInsets;
use Drift\Rendering\StatusTone;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/** 结果卡的状态区：业务状态 → StatusTone → 当前主题色。 */
final class ResultStatusRow extends StatelessWidget
{
    public function __construct(private readonly TaskResult $result)
    {
        parent::__construct();
    }

    public function build(BuildContext $ctx): Widget
    {
        $tone = self::tone($this->result);
        $lines = [Text::make($this->result->statusLabel())->size(14.0)];
        if ($this->result->taskId !== '') {
            $lines[] = Text::make('任务 ID：' . $this->result->taskId)->caption();
        }
        $reason = $this->result->failureReason();
        if ($reason !== '' && !$this->result->isSuccess()) {
            $lines[] = Text::make($reason)->caption();
        }

        return Row::make()
            ->spacing(12)
            ->padding(EdgeInsets::symmetric(18.0, 14.0))
            ->background($ctx->color($tone->surfaceToken()))
            ->radius(10.0)
            ->children([
                SizedBox::make()
                    ->size(12.0, 12.0)
                    ->background($ctx->color($tone->foregroundToken()))
                    ->radius(6.0),
                Column::make()->spacing(4)->children($lines),
            ]);
    }

    /** 未知状态（含 -1）落 Idle：颜色不对可以查，静默崩掉不行。 */
    private static function tone(TaskResult $result): StatusTone
    {
        if ($result->isSuccess()) {
            return StatusTone::Success;
        }
        if ($result->isPending()) {
            return StatusTone::Info;
        }
        if ($result->status === TaskResult::STATUS_FAILED) {
            return StatusTone::Error;
        }

        return StatusTone::Idle;
    }
}
