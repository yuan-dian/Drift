<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\StatusMessage;
use App\Watermark\Store\WatermarkStore;
use Drift\State\SubjectBuilder;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Layout\FlexAlign;
use Drift\Widget\Row;
use Drift\Widget\Select;
use Drift\Widget\Spacer;
use Drift\Widget\Spinner;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * 第 2 张卡片：时间 / 地址 / 尺寸 + 提交（原文的"提交处理任务"）。
 *
 * ★ 三个输入框**各自一个 `SubjectBuilder`**（不是包一个大订阅）：大订阅的话，
 *   改时间会让地址框也重建 —— `TextField` 重建会重置 `TextDraft`（光标回开头），
 *   表现就是"打字时光标乱跳"。切片到单个字段是必须的，不是优化。
 */
final class TaskCard extends StatelessWidget
{
    public function __construct(private readonly WatermarkStore $store)
    {
        parent::__construct();
    }

    public static function make(WatermarkStore $store): self
    {
        return new self($store);
    }

    public function build(BuildContext $ctx): Widget
    {
        return CardShell::make('2', '提交处理任务', Column::make()->spacing(14)->cross(FlexAlign::Stretch)->children([
            $this->timeField(),
            $this->addressField(),
            $this->sizeField(),
            $this->actions(),
            $this->message(),
        ]));
    }

    private function timeField(): Widget
    {
        $store = $this->store;

        return Column::make()->spacing(6)->cross(FlexAlign::Stretch)->children([
            Text::make('时间')->size(13.0),
            SubjectBuilder::make($store)
                ->select(static fn(WatermarkStore $s): string => $s->time())
                ->builder(static fn(string $value): Widget => TextField::make()
                    ->value($value)
                    ->placeholder('例如：2024-01-01 12:00:00')
                    ->onChanged(static function (string $next) use ($store): void {
                        $store->setTime($next);
                    })),
        ]);
    }

    private function addressField(): Widget
    {
        $store = $this->store;

        return Column::make()->spacing(6)->cross(FlexAlign::Stretch)->children([
            Text::make('地址')->size(13.0),
            SubjectBuilder::make($store)
                ->select(static fn(WatermarkStore $s): string => $s->address())
                ->builder(static fn(string $value): Widget => TextField::make()
                    ->value($value)
                    ->placeholder('例如：北京市朝阳区')
                    ->onChanged(static function (string $next) use ($store): void {
                        $store->setAddress($next);
                    })),
        ]);
    }

    private function sizeField(): Widget
    {
        $store = $this->store;

        return Column::make()->spacing(6)->cross(FlexAlign::Stretch)->children([
            Text::make('图片尺寸')->size(13.0),
            SubjectBuilder::make($store)
                ->select(static fn(WatermarkStore $s): string => $s->imageSize())
                // ★ 外面套一层 `Row` + `Spacer`：`cross(Stretch)` 会把 `Select` 拉满
                //   整行宽（它的 `triggerWidth(140)` 是**首选宽**，紧约束下不算数）。
                //   下拉框拉满宽很难看 —— 原文也是一个固定宽的小选择器。
                ->builder(static fn(string $value): Widget => Row::make()->children([
                    Select::make(['2K' => '2K', '4K' => '4K'])
                        ->value($value)
                        ->triggerWidth(140.0)
                        ->onChanged(static function (string $next) use ($store): void {
                            $store->setImageSize($next);
                        }),
                    Spacer::make(),
                ])),
        ]);
    }

    /** 提交按钮：可用态在订阅里读（见 `UsersPage` 那个"永久灰"的教训） */
    private function actions(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): bool => $s->isSubmitting())
            ->builder(static function (bool $running) use ($store): Widget {
                $items = [
                    Button::primary('提交任务')
                        ->enabled(!$running)
                        ->onPressed(static function () use ($store): void {
                            $store->submitTask();
                        }),
                    Spacer::make(),
                ];

                // ★ 提交中给一个转圈：只禁用按钮的话，用户看到的是"按钮变灰了"
                //   而不知道是在跑还是点坏了。
                if ($running) {
                    $items = [
                        Spinner::make()->diameter(12.0),
                        Text::make('提交中…')->caption(),
                        Spacer::make(),
                    ];
                }

                return Row::make()->spacing(10)->children($items);
            });
    }

    private function message(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): StatusMessage => $s->taskMessage())
            ->builder(static fn(StatusMessage $m): Widget => MessageLine::make($m));
    }
}
