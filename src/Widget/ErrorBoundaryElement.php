<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Layout\RenderErrorBoundary;
use Throwable;

/**
 * `ErrorBoundary` 的 Element（O-12 A′ 的捕获逻辑住这里）。
 *
 * ★ **为什么覆写 `settlePass()` 而不只是 `rebuild()`**：`rebuild()` 只覆盖构造期
 *   （mount/update 路径上的异常）；页面 `build()` 抛的异常发生在帧末
 *   `Frame → root->settle() → 孩子->settlePass() → 孩子->rebuild()` 的**孩子栈**里，
 *   本节点的 `rebuild()` 早已返回 ⇒ 必须把整棵遍历包住。
 *   （`settle()` 自 W2/S3 起是 `final` 的多轮循环 ⇒ 覆写它只能包住第一遍，
 *   见 `settlePass()` 上的注记。）
 *
 * ★ **捕获即换子树**：`updateChildren([ErrorPlaceholder])` 复用同一套 diff ——
 *   旧子树整体卸载、占位整体挂载。在 `settle()` 中途换是安全的：异常已跳出
 *   `visitChildren` 的遍历（PHP 的 foreach 对数组按值遍历，改 `$this->children`
 *   不影响正在进行的遍历，何况遍历已被异常终止）。
 *
 * @api
 */
final class ErrorBoundaryElement extends StatelessElement
{
    /** 已捕获的异常；非 null ⇒ 本边界已"烧断"，子树换成占位且不再重建 */
    private ?Throwable $caught = null;

    #[\Override]
    public function rebuild(): void
    {
        if ($this->caught !== null) {
            $this->swapToPlaceholder();

            return;
        }

        try {
            parent::rebuild();
        } catch (Throwable $e) {
            $this->capture($e);
        }
    }

    /**
     * ★ **改名自 `settle()`**（`03` §5.1，W2/S3）：`Element::settle()` 现在是
     *   `final` 的多轮循环，覆写它 = 只包住第一遍 ⇒ 第 2 遍起的异常绕过边界
     *   直冒 `Frame::run()` ⇒ 错误边界粒度从"到节点"退化成"到整帧"，
     *   **且不报错**。改成覆写单遍的 `settlePass()` 后，每一遍都在边界内。
     */
    #[\Override]
    public function settlePass(): void
    {
        if ($this->caught !== null) {
            return; // 已坏：占位不参与帧末重建，不会二次抛
        }

        try {
            parent::settlePass();
        } catch (Throwable $e) {
            $this->capture($e);
        }
    }

    #[\Override]
    protected function expand(): array
    {
        $widget = $this->widget;

        return $widget instanceof ErrorBoundary ? [$widget->child()] : [];
    }

    #[\Override]
    protected function adoptBoxes(): void
    {
        // ★ `RenderErrorBoundary` 必须 import：instanceof 对**未导入**的类按当前
        //   命名空间解析 ⇒ 恒 false 且**不报错**（PHP 语言行为）—— 那本方法会
        //   静默 return，占位盒永远挂不上（G2 要消灭的正是这种形态）。
        $box = $this->renderObject();
        if (!$box instanceof RenderErrorBoundary) {
            return;
        }

        $childBox = $this->childAt(0)?->effectiveBox();
        if ($childBox !== null) {
            $box->setChild($childBox);
        }
    }

    /**
     * 把"请重建"传给孩子（A46）。
     *
     * ★ 浮层锚点的内容签名变化时，`OverlayLayout` 对**根 Element** 调
     *   `markNeedsBuild()`。边界自己是无态 Element，`parent::` 什么都不做 ⇒
     *   下拉面板看着还开着，筛选内容再也不更新（哑浮层，不报错）。
     */
    #[\Override]
    public function markNeedsBuild(): void
    {
        parent::markNeedsBuild();
        if ($this->caught === null) {
            $this->childAt(0)?->markNeedsBuild();
        }
    }

    /** 本边界是否已捕获过异常（测试 / devtools 断言用） */
    public function hasCaught(): bool
    {
        return $this->caught !== null;
    }

    public function caughtMessage(): ?string
    {
        return $this->caught?->getMessage();
    }

    private function capture(Throwable $e): void
    {
        $this->caught = $e;

        Errors::report(sprintf(
            '错误边界捕获子树异常：%s —— 该子树换成占位，窗口其余部分继续',
            Errors::describe($e),
        ), Errors::DIAGNOSTIC);

        $this->swapToPlaceholder();
    }

    /**
     * 换子树为占位。占位自身若再抛 ⇒ 不二次捕获（让它冒到 `Frame` 走 Fatal
     * 序列 —— 占位只是 Text + Center，真抛了就是框架自己的 bug，必须响）。
     */
    private function swapToPlaceholder(): void
    {
        $message = $this->caught?->getMessage() ?? '未知错误';
        $this->updateChildren([ErrorPlaceholder::make($message)]);
        $this->adoptBoxes();
    }
}
