<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\State\Cell;
use Drift\State\SubscribingWidget;

/**
 * 夹具：`dead-subscription` 的已知错误（§16.6.3 #4 / A16）。
 *
 * 两个维度各一条 Bad / Good：
 * - 声明位置：类体属性 vs 构造器提升参数（`__construct(private Cell $x)`）
 * - 标记：没实现 `SubscribingWidget`（死订阅）vs 实现了（不该响）
 */
final class ReadsCellUnmarked extends StatelessWidget
{
    private Cell $hovered;

    public function build(): Widget
    {
        return Text::make((string) $this->hovered->value());
    }
}

final class ReadsCellMarked extends StatelessWidget implements SubscribingWidget
{
    private Cell $hovered;

    public function build(): Widget
    {
        return Text::make((string) $this->hovered->value());
    }
}

final class ReadsCellPromotedUnmarked extends StatelessWidget
{
    public function __construct(private readonly Cell $hovered)
    {
        parent::__construct();
    }

    public function build(): Widget
    {
        return Text::make((string) $this->hovered->value());
    }
}

final class ReadsCellPromotedMarked extends StatelessWidget implements SubscribingWidget
{
    public function __construct(private readonly Cell $hovered)
    {
        parent::__construct();
    }

    public function build(): Widget
    {
        return Text::make((string) $this->hovered->value());
    }
}

/**
 * 读发生在事件回调里 ⇒ 不在 build 期，**不该响**。
 *
 * 钉住 `closureEnd()` 的闭包边界：跳过逻辑一旦失效，这里会多报一条。
 */
final class ReadsCellInCallback extends StatelessWidget
{
    private Cell $hovered;

    public function build(): Widget
    {
        return Button::make('重置')->onPressed(fn() => $this->hovered->value());
    }
}
