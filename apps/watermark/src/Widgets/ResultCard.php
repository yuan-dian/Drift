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
 * ★ **两张切片分开订**：
 *   - 状态区跟 `lastResult`（5 态文案 + 状态点）
 *   - 图片区跟 `localPaths`（下载完成度）
 *   合成一个订阅的话，每下载完一张图就会把状态区也重建一遍（`Text` 重建没事，
 *   但整棵子树重建会让"结果图"闪一下 —— `Image` 重建会重新 `load()`，
 *   命中缓存所以不闪，但没必要）。
 *
 * ★ 结果图走**本地路径**（`localPaths`）：`Image` 不发 HTTP，URL 只是元信息。
 *   一张下载失败不影响别的（见 `DownloadResultsJob` 的"部分失败"）。
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
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): mixed => $s->lastResult())
            ->builder(static function (mixed $result) use ($store): Widget {
                if (!$result instanceof TaskResult) {
                    // 还没提交 / 还没查到：给一句"在等什么"，而不是空白
                    if ($store->isPolling()) {
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

    /** 结果图（本地文件，按服务端给的顺序） */
    private function imagesSection(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): array => [
                'urls' => self::urlsOf($s->lastResult()),
                'locals' => $s->localPaths(),
                'failed' => $s->failedDownloads(),
                'downloading' => $s->isDownloading(),
            ])
            ->builder(static function (array $slice): Widget {
                /** @var list<string> $urls */
                $urls = $slice['urls'];
                /** @var array<string, string> $locals */
                $locals = $slice['locals'];
                /** @var array<string, string> $failed */
                $failed = $slice['failed'];

                if ($urls === []) {
                    return SizedBox::make()->size(0.0, 0.0);
                }

                $items = [];
                foreach ($urls as $url) {
                    $local = $locals[$url] ?? '';
                    if ($local !== '') {
                        $items[] = Column::make()->spacing(8)->children([
                            Center::make(
                                Image::make()
                                    ->asset($local)
                                    ->width(self::PREVIEW_WIDTH)
                                    ->fit(ImageFit::Contain)
                            ),
                            // ★ 原文是"查看 / 下载"链接。这里给**原始 URL 文本**：
                            //   壳没有"用浏览器打开 URL"的端口（`Dialog` 只有文件
                            //   对话框），而把 URL 显示出来用户至少能复制。
                            Text::make($url)->caption(),
                        ]);

                        continue;
                    }

                    if (isset($failed[$url])) {
                        $items[] = Row::make()
                            ->spacing(8)
                            ->padding(EdgeInsets::symmetric(14.0, 10.0))
                            ->background(Palette::errorBg())
                            ->radius(8.0)
                            ->children([
                                Text::make('这张图没能下载：' . $failed[$url])->size(13.0),
                            ]);

                        continue;
                    }

                    // 还在下（或排队）
                    $items[] = Row::make()->spacing(8)->children([
                        Spinner::make()->diameter(12.0),
                        Text::make('正在下载结果图…')->caption(),
                    ]);
                }

                return Column::make()->spacing(16)->children($items);
            });
    }

    /** "停止轮询"（只在真的在轮询时出现） */
    private function pollControl(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): bool => $s->isPolling())
            ->builder(static function (bool $polling) use ($store): Widget {
                if (!$polling) {
                    return SizedBox::make()->size(0.0, 0.0);
                }

                return Row::make()->spacing(10)->children([
                    Button::make('停止轮询')
                        ->variant(ButtonVariant::Danger)
                        ->compact()
                        ->onPressed(static function () use ($store): void {
                            $store->stopPolling();
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
