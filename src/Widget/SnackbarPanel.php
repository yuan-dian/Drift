<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;

/**
 * 底部通知的**形体**（W8-2，2026-10-23）—— 纯工厂，不是 Widget。
 *
 * ```php
 * SnackbarPanel::make($snackbar, function (): void { $this->dismissSnackbar(); })
 * ```
 *
 * ★ **为什么第二个参数是"收掉"回调**：动作按钮点完要**关掉这条通知**，而
 *   `Snackbar` 是纯描述对象（不是 Widget）⇒ 它自己拿不到 `Application`。
 *   把"关闭"作为参数注入，`Application` 就能在组面板时一次性接好线 ——
 *   这是"Snackbar 不做成 Widget"的直接原因（见 `Snackbar` 类注释）。
 *
 * ★ **布局为什么是 Column 不是 Row**：`RenderFlex` 给**非弹性**子的是
 *   `0..maxMain`（不是"分配后的剩余"）⇒ `Row[Text, Spacer, Button]` 在长文案下
 *   会**溢出**（Text 撑到全文宽 + 按钮宽 > 面板宽 ⇒ `Overflow::Report` 直接中止）。
 *   Column 里 Text 拿到 `0..480` 有界宽 ⇒ 自己换行（`TextMetricsProvider::layout`
 *   收 `maxWidth` 就断行），永远不会溢出。
 *
 * ★ 视觉上**没有反色/深色底**（与 Tooltip 一致）：`Text` 没有颜色 API，加它要动
 *   `Text` + `RenderText` + 哨兵（AOT 用"值 + bool"），且与 §15.7「主题 token 与
 *   显式样式只合并一次」冲突。要深色 snackbar 就先把 `Text::color()` 立起来。
 *
 * @api 应用一般**不需要**直接用它（`Application::showSnackbar()` 就够了）
 */
final class SnackbarPanel
{
    private function __construct()
    {
        // 纯静态工厂：不允许实例化
    }

    /**
     * 造一个通知面板。
     *
     * @param \Closure $onDismiss 收掉这条通知（动作按钮点完之后调）
     */
    public static function make(Snackbar $snackbar, \Closure $onDismiss): Widget
    {
        $children = [
            Text::make($snackbar->messageValue())->body()->maxLines(2)->ellipsize(),
        ];

        if ($snackbar->actionLabelValue() !== '') {
            $children[] = Row::make()->spacing(8.0)->children([
                Spacer::make(),
                Button::make($snackbar->actionLabelValue())
                    ->variant(ButtonVariant::Flat)
                    ->onPressed(self::actionCallback($snackbar, $onDismiss)),
            ]);
        }

        return PopupPanel::make(Column::make()->spacing(6.0)->children($children))
            // ★ `minWidth` 是**必须的**，不是审美：见 `Snackbar::MIN_WIDTH` 的注释
            //   （Button 在无界高度下内禀宽失真 ⇒ 面板内容宽算窄 ⇒ RenderFlex 溢出中止）。
            ->minWidth(Snackbar::MIN_WIDTH)
            ->padding(EdgeInsets::symmetric(horizontal: 14.0, vertical: 10.0))
            ->elevation(6.0);
    }

    /**
     * 动作按钮的处理器：先调用户回调，再收掉通知。
     *
     * ★ **顺序其实不影响结果**（诚实记录）：先 `dismiss` 再调 handler 时，
     *   `snackEntry` 已是 `null` ⇒ handler 里 `showSnackbar()` 的那条会**立即显示**；
     *   先 handler 再 `dismiss` 时它入队、`dismiss` 之后照样上位。两条路都得到
     *   "旧的通知走了、新的在显示"。
     *   ⇒ 保留"先 handler"只是因为**读起来符合用户的因果**（我点了动作 → 通知消失），
     *     不是因为反着会错。别把这条注释当成约束去"优化"。
     */
    private static function actionCallback(Snackbar $snackbar, \Closure $onDismiss): \Closure
    {
        return function () use ($snackbar, $onDismiss): void {
            $handler = $snackbar->actionHandlerValue();

            if ($handler !== null) {
                $handler();
            }

            $onDismiss();
        };
    }
}
