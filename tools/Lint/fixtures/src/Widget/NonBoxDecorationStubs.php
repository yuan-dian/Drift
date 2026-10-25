<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * `nonbox-decoration` 的**类型谱系**夹具（一个文件多个类，lint 按 token 扫描不需要 PSR-4 类名对应）。
 *
 * 覆盖判据的三条分支：
 *   · `ScrollView`     —— 不产盒（反例来源，套修饰必须报）；
 *   · `BoxFrame`       —— 继承 `RenderBoxWidget`（产盒，正例）；
 *   · `CardLike`       —— `StatelessWidget` 但**自己覆写**了修饰方法并转发（正例）；
 *   · `StatefulBox`    —— `StatefulWidget` 声明了 `createRenderObjectFromState()`（产盒，正例）。
 *
 * ★ 每个类都标 `@api`：`Drift\` 前缀默认算非公共面，不标的话 `apps/` 的夹具
 *   引用它们会被 `internal-leak` 报 error（那是另一条规则的正例，不是本夹具要测的）。
 */

/** @api */
abstract class Widget
{
    public static function make(): static
    {
        return new static();
    }

    public function paddingAll(float $all): static
    {
        return $this;
    }

    public function shadow(float $elevation): static
    {
        return $this;
    }
}

/** @api */
abstract class RenderBoxWidget extends Widget
{
}

/** @api */
abstract class StatelessWidget extends Widget
{
}

/** @api */
abstract class StatefulWidget extends Widget
{
    public function createRenderObjectFromState(): ?object
    {
        return null;
    }
}

/** @api */
final class ScrollView extends Widget
{
}

/** @api */
final class BoxFrame extends RenderBoxWidget
{
}

/** @api */
final class CardLike extends StatelessWidget
{
    // 覆写 = 自己消费（`Card` 的真实形态：转发给内部代理盒）
    public function paddingAll(float $all): static
    {
        return $this;
    }
}

/** @api */
final class StatefulBox extends StatefulWidget
{
    public function createRenderObjectFromState(): ?object
    {
        return new \stdClass();
    }
}
