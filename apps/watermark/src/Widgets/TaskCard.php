<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\WatermarkStore;
use Drift\Layout\FlexAlign;
use Drift\Widget\Bind;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Column;
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
 * ★ 三个输入框**各自一个 `Bind`**（不是包一个大绑定）：绑在一起的话，改时间会让
 *   地址框也重建 —— `TextField` 重建会重置 `TextDraft`（光标回开头），表现就是
 *   "打字时光标乱跳"。绑到单个字段是必须的，不是优化。
 * ★ 本类是 `StatelessWidget`（`build()` 只在被造出来时跑一次）⇒ 直接读
 *   `$store->time()` 拿到的是**浇筑那一刻的快照**。所有会变的值因此必须住在
 *   `Bind` 的 builder 里，那里读什么就订什么。
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
            Bind::make(static fn (BuildContext $ctx): Widget => TextField::make()
                ->value($store->time())
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
            Bind::make(static fn (BuildContext $ctx): Widget => TextField::make()
                ->value($store->address())
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
            Bind::make(static fn (BuildContext $ctx): Widget => Row::make()->children([
                Select::make(['2K' => '2K', '4K' => '4K'])
                    ->value($store->imageSize())
                    ->triggerWidth(140.0)
                    ->onChanged(static function (string $next) use ($store): void {
                        $store->setImageSize($next);
                    }),
                // ★ 外面套一层 `Row` + `Spacer`：`cross(Stretch)` 会把 `Select` 拉满
                //   整行宽（它的 `triggerWidth(140)` 是**首选宽**，紧约束下不算数）。
                //   下拉框拉满宽很难看 —— 原文也是一个固定宽的小选择器。
                Spacer::make(),
            ])),
        ]);
    }

    /**
     * 提交按钮：可用态与**原因**都来自 `submitBlocker()`（同一份判据）。
     *
     * ★ 以前这里读 `isSubmitting()` —— 只有"正在提交"那一档才会灰，于是上传中 /
     *   轮询中按钮是亮的，点下去才被告知"还有一步没做完"。`enabled()` 与
     *   `submitTask()` 的守卫必须同源，否则界面在说一句、逻辑在说另一句。
     */
    private function actions(): Widget
    {
        $store = $this->store;

        return Bind::make(static function (BuildContext $ctx) use ($store): Widget {
            $blocker = $store->submitBlocker();

            // ★ 提交中给一个转圈：只禁用按钮的话，用户看到的是"按钮变灰了"
            //   而不知道是在跑还是点坏了。
            if ($store->isSubmitting()) {
                return Row::make()->spacing(10)->children([
                    Spinner::make()->diameter(12.0),
                    Text::make('提交中…')->caption(),
                    Spacer::make(),
                ]);
            }

            $items = [
                Button::primary('提交任务')
                    ->enabled($blocker === '')
                    ->onPressed(static function () use ($store): void {
                        $store->submitTask();
                    }),
            ];

            // ★ 灰按钮旁必须有一句话：不说原因的话，用户只会觉得按钮坏了
            if ($blocker !== '') {
                $items[] = Text::make($blocker)->caption();
            }

            $items[] = Spacer::make();

            return Row::make()->spacing(10)->children($items);
        });
    }

    private function message(): Widget
    {
        $store = $this->store;

        return Bind::make(static fn (BuildContext $ctx): Widget => MessageLine::make($store->taskMessage()));
    }
}
