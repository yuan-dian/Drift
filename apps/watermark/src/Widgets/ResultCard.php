<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Api\TaskResult;
use App\Watermark\Store\WatermarkStore;
use Drift\Geometry\EdgeInsets;
use Drift\Layout\ImageFit;
use Drift\State\SubjectBuilder;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\Each;
use Drift\Layout\FlexAlign;
use Drift\Widget\Image;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\Spacer;
use Drift\Widget\Spinner;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 第 3 张卡片：轮询状态 + 结果信息 + 结果图（原文的"处理结果"）。
 *
 * ★ 结果图直接把服务端 URL 交给 `Image::asset()` —— 下载 / 缓存 / 三态由控件自治，
 *   本卡片不再维护一份批量下载状态。一张失败不影响别的。
 */
final class ResultCard extends StatelessWidget
{
    /** 结果图预览的最大宽度（原文 `max-width:100%`；窗口宽 900 减去卡片内边距） */
    private const PREVIEW_WIDTH = 560.0;

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
        return CardShell::make('3', '处理结果', Column::make()->spacing(14)->cross(FlexAlign::Stretch)->children([
            $this->statusSection(),
            $this->infoSection(),
            $this->imagesSection(),
            $this->pollControl(),
        ]));
    }

    /** 轮询状态（5 态：已创建 / 排队中 / 处理中 / 完成 / 失败） */
    private function statusSection(): Widget
    {
        return SubjectBuilder::make($this->store)
            // ★ `isPolling()` 必须进 select，不能在 builder 里现读：
            //   select 只盯 `lastResult()`，而"刚开始轮询"时它是 `null → null`（没变化）
            //   ⇒ builder 不重建 ⇒ 状态区停在"等待任务提交后自动查询结果…"，
            //   而下面 `pollControl()` 已经冒出"停止轮询"按钮 —— 两句话自相矛盾。
            ->select(static fn(WatermarkStore $s): array => [
                'result' => $s->lastResult(),
                'polling' => $s->isPolling(),
            ])
            ->builder(static function (array $slice): Widget {
                $result = $slice['result'];
                $polling = $slice['polling'] === true;

                if (!$result instanceof TaskResult) {
                    // 还没提交 / 还没查到：给一句"在等什么"，而不是空白
                    if ($polling) {
                        return Row::make()->spacing(10)->children([
                            Spinner::make()->diameter(14.0),
                            Text::make('正在查询结果…')->caption(),
                        ]);
                    }

                    return Text::make('等待任务提交后自动查询结果…')->caption();
                }

                [$dot, $bg] = self::statusColors($result);

                $lines = [
                    Text::make($result->statusLabel())->size(14.0),
                ];
                if ($result->taskId !== '') {
                    $lines[] = Text::make('任务 ID：' . $result->taskId)->caption();
                }
                $reason = $result->failureReason();
                if ($reason !== '' && !$result->isSuccess()) {
                    $lines[] = Text::make($reason)->caption();
                }

                return Row::make()
                    ->spacing(12)
                    ->padding(EdgeInsets::symmetric(18.0, 14.0))
                    ->background($bg)
                    ->radius(10.0)
                    ->children([
                        SizedBox::make()
                            ->size(12.0, 12.0)
                            ->background($dot)
                            ->radius(6.0),
                        Column::make()->spacing(4)->children($lines),
                    ]);
            });
    }

    /** 结果元信息（模板 / 尺寸 / 耗时 / 创建 / 完成） */
    private function infoSection(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): mixed => $s->lastResult())
            ->builder(static function (mixed $result): Widget {
                if (!$result instanceof TaskResult || !$result->isSuccess()) {
                    return SizedBox::make()->size(0.0, 0.0);
                }

                return Column::make()
                    ->spacing(6)
                    ->padding(EdgeInsets::all(18.0))
                    ->background(Palette::listRow())
                    ->radius(10.0)
                    ->children([
                        Text::make('模板：' . self::orDash($result->templateName))->size(13.0),
                        Text::make('尺寸：' . self::orDash($result->imageSize))->size(13.0),
                        Text::make('处理耗时：' . self::orDash($result->processingTime) . ' 秒')->size(13.0),
                        Text::make('创建时间：' . self::orDash($result->createTime))->size(13.0),
                        Text::make('完成时间：' . self::orDash($result->updateTime))->size(13.0),
                    ]);
            });
    }

    /** 结果图（URL 直接交给 Image，按服务端给的顺序） */
    private function imagesSection(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): array => self::urlsOf($s->lastResult()))
            ->builder(static fn(array $urls): Widget => self::imagesFor($urls));
    }

    /**
     * 每张图自治：加载 / 缓存 / 失败降级都在 `Image` 里，这里只管排版。
     * `@internal` 供判据直接调，不建 Element 树。
     *
     * @param list<string> $urls
     */
    public static function imagesFor(array $urls): Widget
    {
        if ($urls === []) {
            return SizedBox::make()->size(0.0, 0.0);
        }

        $items = [];
        foreach ($urls as $url) {
            $items[] = Column::make()->spacing(8)->children([
                Center::make(
                    Image::make()
                        ->asset($url)
                        ->width(self::PREVIEW_WIDTH)
                        ->fit(ImageFit::Contain)
                ),
                // 壳没有"用浏览器打开 URL"的端口，把 URL 显示出来至少能复制。
                Text::make($url)->caption(),
            ]);
        }

        return Column::make()->spacing(16)->children($items);
    }

    /**
     * 「停止」（只在提交中 / 轮询中出现；结果图下载由各 Image 自治，没有整批停止）。
     *
     * ★ 判据与文案都来自 Store（`canStop()` / `stopLabel()`）；数组切片用 `===`
     *   判等（同序同值即相等）⇒ 内容没变不重建。
     */
    private function pollControl(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): array => [
                'stop' => $s->canStop(),
                'label' => $s->stopLabel(),
            ])
            ->builder(static function (array $state) use ($store): Widget {
                if (($state['stop'] ?? false) !== true) {
                    return SizedBox::make()->size(0.0, 0.0);
                }

                $label = \is_string($state['label'] ?? null) ? $state['label'] : '停止';

                return Row::make()->spacing(10)->children([
                    Button::make($label)
                        ->variant(ButtonVariant::Danger)
                        ->compact()
                        ->onPressed(static function () use ($store): void {
                            $store->stop();
                        }),
                    Spacer::make(),
                ]);
            });
    }

    /**
     * 状态色：`[点, 底]`。
     *
     * ★ 用 `if` 链而不是 `match`：tpc 对 `match` 的窄化没验证过（本仓已有两处
     *   注释写着这个理由）。五个分支的 `if` 链零风险。
     *
     * @return array{\Drift\Rendering\Color, \Drift\Rendering\Color}
     */
    private static function statusColors(TaskResult $result): array
    {
        if ($result->isSuccess()) {
            return [Palette::successText(), Palette::successBg()];
        }
        if ($result->isPending()) {
            return [Palette::accent(), Palette::infoBg()];
        }
        if ($result->status === TaskResult::STATUS_FAILED) {
            return [Palette::errorText(), Palette::errorBg()];
        }

        // 未知状态（含 -1）：用中性色 + 文案里带原始状态码
        return [Palette::hint(), Palette::listRow()];
    }

    /** @return list<string> */
    private static function urlsOf(mixed $result): array
    {
        if (!$result instanceof TaskResult) {
            return [];
        }

        return $result->downloadUrls;
    }

    private static function orDash(string $value): string
    {
        return $value === '' ? '-' : $value;
    }
}
