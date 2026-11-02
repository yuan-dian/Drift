<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\StatusMessage;
use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 一条状态消息（原文的 `.status-bar.status-{kind}`）。
 *
 * ★ **没有消息时返回 0×0**（不是 `null`、不是空文本）：`build()` 必须返回 Widget，
 *   而空文本会在 `Column` 里占一行高度 —— 卡片高度就会随"有没有消息"跳。
 * ★ 四种 kind 的配色集中在这里：`Palette` 给色、本类给"哪一格用哪个色"。
 *   三张卡片各写一遍的话，某天加第五种 kind 会漏掉两处（不报错、只是没颜色）。
 */
final class MessageLine extends StatelessWidget
{
    public function __construct(private readonly StatusMessage $message)
    {
        parent::__construct();
    }

    public static function make(StatusMessage $message): self
    {
        return new self($message);
    }

    public function build(BuildContext $ctx): Widget
    {
        if (!$this->message->isVisible()) {
            return SizedBox::make()->size(0.0, 0.0);
        }

        return Row::make()
            ->padding(EdgeInsets::symmetric(12.0, 8.0))
            ->background(self::bgOf($this->message->kind))
            ->radius(8.0)
            ->children([
                // ★ 字色**不在这里定**：`Text` 只有 `TextRole`（Title/Heading/Body/
                //   Caption/Error），没有自定义色 —— 这是 §15.7 的"语义颜色走主题
                //   角色"，不是缺口。原文那四种状态是"浅底 + 同色系深字"，这里
                //   改成"浅底 + 正文色"，对比度反而更高（浅绿底上的深绿字对比度
                //   只有 3.8:1，正文色是 12:1）。
                Text::make($this->message->text)->size(13.0),
            ]);
    }

    /**
     * `kind` → 底色。
     *
     * ★ 方法名**不能叫 `background()`**：`Widget` 从 `WidgetModifiers` 里已经有一个
     *   非静态的 `background(Color)`，在本类声明同名静态方法 ⇒ PHP 当场
     *   `Fatal: Cannot make non static method Widget::background() static`。
     *   这条是软光栅预览抓到的（`php -l` 不管方法冲突）。
     *
     * ★ 未知 kind 走"信息"那一档（不崩、不画透明）：多一种 kind 而忘了配色的
     *   症状应该是"颜色不对"，不是"文字看不见"。
     */
    private static function bgOf(string $kind): Color
    {
        if ($kind === StatusMessage::SUCCESS) {
            return Palette::successBg();
        }
        if ($kind === StatusMessage::ERROR) {
            return Palette::errorBg();
        }
        if ($kind === StatusMessage::LOADING) {
            return Palette::loadingBg();
        }

        return Palette::infoBg();
    }
}
