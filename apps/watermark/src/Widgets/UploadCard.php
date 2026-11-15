<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\WatermarkStore;
use Drift\Geometry\EdgeInsets;
use Drift\Input\FilesDroppedEvent;
use Drift\Input\PointerEvent;
use Drift\Rendering\DashPattern;
use Drift\State\SubjectBuilder;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\Each;
use Drift\Widget\Element;
use Drift\Layout\FlexAlign;
use Drift\Widget\Icon;
use Drift\Widget\IconName;
use Drift\Widget\Progress;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\Spacer;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

use App\Watermark\Store\SelectedImage;
use App\Watermark\Store\StatusMessage;

/**
 * 第 1 张卡片：选图 + 预览 + 上传（原文的"选择并上传图片"）。
 *
 * ★ **每个会变的值都在自己的 `SubjectBuilder` 里读**。本类是 `StatelessWidget`
 *   （`build()` 只在被造出来时跑一次）⇒ 直接读 `$store->xxx()` 拿到的是**浇筑
 *   那一刻的快照**，之后永远不变。这是本仓真机抓过的 bug（`UsersPage` 的
 *   "刷新按钮永久灰"），不是理论风险。
 * ★ 上传区**同时**接"点击"和"拖放"：
 *   - 点击走注入的 `$pickFiles`（组合根里是 `Dialog::openFiles()`）——
 *     Widget 不许依赖 `Drift\Platform`，所以用闭包。
 *   - 拖放走 `onFilesDropped()`（壳的 `WM_DROPFILES`，A5-②）。★ 它是**冒泡**的：
 *     落在子控件上也会找到最近的声明者，所以整块区域都能接住。
 */
final class UploadCard extends StatelessWidget
{
    /**
     * @param \Closure(): void $pickFiles 打开文件对话框（组合根注入）
     */
    public function __construct(
        private readonly WatermarkStore $store,
        private readonly \Closure $pickFiles,
    ) {
        parent::__construct();
    }

    /**
     * @param \Closure(): void $pickFiles
     */
    public static function make(WatermarkStore $store, \Closure $pickFiles): self
    {
        return new self($store, $pickFiles);
    }

    public function build(BuildContext $ctx): Widget
    {
        return CardShell::make('1', '选择并上传图片', Column::make()->spacing(14)->cross(FlexAlign::Stretch)->children([
            $this->dropZone(),
            $this->previews(),
            $this->actions(),
            $this->progress(),
            $this->message(),
            $this->uploadedList(),
        ]));
    }

    /**
     * 上传区（虚线框 + 三行文案）。
     *
     * ★ 虚线是 `CAP_DASH` 的已知近似：`RasterCanvas::drawRect()` 把描边虚线降级成
     *   实线（R2 的既有取舍）⇒ 只跑 Raster 的 golden 看不到差异，画质由
     *   `CanvasCapabilityContractTest` 登记、真机效果看 `probe`。
     */
    private function dropZone(): Widget
    {
        $pick = $this->pickFiles;
        $store = $this->store;

        return Column::make()
            ->spacing(6)
            ->padding(EdgeInsets::symmetric(40.0, 22.0))
            ->background(Palette::listRow())
            ->border(Palette::dropBorder(), 2.0)
            ->borderDash(new DashPattern(6.0, 4.0))
            ->radius(12.0)
            ->children([
                // ★ 图标走 `Icon`（矢量 Path），**不是 emoji**：字模生成链只覆盖
                //   当前源码里出现过的 CJK / 拉丁字符，emoji 没有字模 ⇒ 真机零宽
                //   隐形（不画 tofu、不报错）。`IconName` 的类注释写着同一条理由。
                Center::make(Icon::make(IconName::Image, 32.0)->color(Palette::accent())),
                Center::make(Text::make('点击选择图片，或把图片拖到这里')->size(14.0)),
                Center::make(Text::make('支持 JPG / PNG / WebP 等常见图片格式')->caption()),
            ])
            // ★ 回调**必须声明 2 个参数**（`PointerEvent` + 命中的 `Element`）：
            //   AOT 严格校验实参个数，少声明一个在解释器里没事、真机上是
            //   `Fatal: expects exactly 2`。
            // ★ `ownsPress: true` ⇒ 按下归本控件（跳过"激活"那条路）。这里没有
            //   子按钮，所以不存在"抢了别人的点击"。
            ->pointer(static function (PointerEvent $e, Element $self) use ($pick): void {
                // 抬起才算"点击"（按下就触发的话，用户按住拖走也会开对话框）
                if ($e->isUp()) {
                    $pick();
                }
            }, true)
            ->onFilesDropped(static function (FilesDroppedEvent $e) use ($store): void {
                $store->addFiles($e->paths());
            });
    }

    /** 预览区：一行可换行的缩略图（原文 `.preview-area { display:flex; flex-wrap:wrap }`） */
    private function previews(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): array => $s->selected())
            ->builder(static function (array $images) use ($store): Widget {
                if ($images === []) {
                    return SizedBox::make()->size(0.0, 0.0);
                }

                $thumbs = [];
                foreach ($images as $i => $image) {
                    $thumbs[] = ImageThumb::make($image, (int) $i, static function (int $index) use ($store): void {
                        $store->removeAt($index);
                    });
                }

                return Wrap::make()->spacing(12.0)->runSpacing(12.0)->children($thumbs);
            });
    }

    /** 按钮行：上传 + 清空 */
    private function actions(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): bool => $s->canUpload())
            ->builder(static function (bool $canUpload) use ($store): Widget {
                return Row::make()->spacing(12)->children([
                    Button::primary('上传')->enabled($canUpload)->onPressed(static function () use ($store): void {
                        $store->upload();
                    }),
                    Button::make('清空选择')
                        ->variant(ButtonVariant::Danger)
                        ->compact()
                        ->onPressed(static function () use ($store): void {
                            $store->clearSelection();
                        }),
                    Spacer::make(),
                ]);
            });
    }

    /** 进度条（只在"正在上传"时出现 —— 平时它是一条 0% 的空槽，看着像坏了） */
    private function progress(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): float => $s->isUploading() ? $s->uploadProgress() : -1.0)
            ->builder(static function (float $fraction): Widget {
                if ($fraction < 0.0) {
                    return SizedBox::make()->size(0.0, 0.0);
                }

                return Progress::make($fraction)->height(8.0);
            });
    }

    private function message(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): StatusMessage => $s->uploadMessage())
            ->builder(static fn(StatusMessage $m): Widget => MessageLine::make($m));
    }

    /** 已上传的对象键列表（原文 `.uploaded-item`：✅ + 等宽路径） */
    private function uploadedList(): Widget
    {
        $store = $this->store;

        return SubjectBuilder::make($store)
            ->select(static fn(WatermarkStore $s): array => $s->uploadedKeys())
            ->builder(static function (array $keys): Widget {
                if ($keys === []) {
                    return SizedBox::make()->size(0.0, 0.0);
                }

                // ★ `Each` 的 `keyFn` 与 `builder` 都只收**一个**参数（item），
                //   没有 index。key 用对象键本身（它天然唯一 —— 同一批里两个
                //   相同的键说明 `objectKey()` 撞了，那是真 bug）。
                return Each::make(static fn(string $key): string => $key)->items(
                    $keys,
                    static fn(string $key): Widget => Row::make()
                        ->spacing(10)
                        ->padding(EdgeInsets::symmetric(14.0, 10.0))
                        ->background(Palette::listRow())
                        ->radius(8.0)
                        ->children([
                            Icon::make(IconName::Check, 14.0)->color(Palette::successText()),
                            Text::make($key)->caption(),
                        ]),
                );
            });
    }
}
