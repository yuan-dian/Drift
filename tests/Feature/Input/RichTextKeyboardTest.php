<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\App\FocusManager;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderRichText;
use Drift\Widget\Column;
use Drift\Widget\RichText;
use Drift\Widget\Span;

/**
 * 富文本的**键盘可达**：带 `onSpanTap` 的 `RichText` 要能 Tab 到、Enter 触发第一个链接。
 *
 * ## 为什么这条在 Feature 层
 *
 * 判据要过 `FocusManager`（收集焦点链 ⇒ 聚焦 ⇒ 派发键），是应用级路由，
 * 不是"量一个盒"。排版产物与链接命中在 `Unit/Text/RichTextTest` 里
 * （只要一次 `layout()`）。
 *
 * ## 三条是一条链，不能拆
 *
 * ① `onSpanTap` ⇒ 声明可聚焦（进 Tab 序）—— 没这一步，键根本到不了它；
 * ② `firstTag()` 找得到落点 —— 没这一步，Enter 不知道该触发谁；
 * ③ Enter 真的把 tag 交出去 —— 没这一步，前两条全绿而功能是死的。
 *
 * ★ ① 的失败形态最隐蔽：`RichText` 看起来像纯展示控件，忘了声明可聚焦 ⇒
 *   **整段链接键盘不可达**，鼠标能点、Tab 跳不过去，而且不报错。
 * ★ ③ 断言 `consumed === true` **且** `taps === ['retry']`：只验前者会奖励
 *   "吃掉了键但什么也没做"的实现（用户按 Enter 毫无反应，且键不再往下传）。
 */
final class RichTextKeyboardTest extends SmokeTestCase
{
    public function testOnSpanTapMakesRichTextKeyboardReachable(): void
    {
        /** @var list<string> $taps */
        $taps = [];

        $root = Column::make()->children([
            RichText::make([
                Span::make('加载失败，'),
                Span::make('重试')->tag('retry')->underline(),
            ])->onSpanTap(function (string $tag) use (&$taps): void {
                $taps[] = $tag;
            }),
        ])->createElement();
        $root->mount(null, smokeContext());
        $root->effectiveBox()?->layout(BoxConstraints::loose(new Size(400.0, 60.0)));

        $fm = new FocusManager();
        $fm->collect([$root]);
        check(
            'RichText · onSpanTap ⇒ 声明可聚焦（进 Tab 序）',
            \count($fm->order()) === 1,
            'order=' . \count($fm->order())
        );

        $el = $fm->order()[0] ?? null;
        $box = $el?->effectiveBox();
        check(
            'RichText · firstTag 找到第一个链接（Enter 的落点）',
            $box instanceof RenderRichText && $box->firstTag() === 'retry',
            'tag=' . ($box instanceof RenderRichText ? $box->firstTag() : 'n/a')
        );

        if ($el !== null) {
            $fm->focus($el);
        }

        $consumed = $fm->dispatchKey(KeyEvent::down(KeyChord::enter()));
        check(
            'RichText · Enter 触发第一个链接（keyboard 缝，键被消费）',
            $consumed && $taps === ['retry'],
            \sprintf('consumed=%s taps=%s', $consumed ? 'y' : 'n', \implode(',', $taps))
        );
    }
}
