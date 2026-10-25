<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Commands\CommandArg;

/**
 * 一次"激活"的上下文 —— 控件被激活时，框架能告诉它什么。
 *
 * ★ **为什么要有这个对象**：`Activatable::activate()` 收一个参数而不是零个 ——
 *   因为四类控件的"激活"要的东西不一样：
 *
 *   | 控件 | 激活要什么 |
 *   |---|---|
 *   | `Button` | 派发命令（要走 `CommandRouter` 冒泡链）|
 *   | `Menu` | 自己的 `Element`（`onOpen` 回调的第二个参数）|
 *   | `ToggleBase` / `Segment` | 什么都不要，换值即可 |
 *
 *   若接口写零参，`Button` 就够不到命令派发、`Menu` 就够不到 element ⇒ 只能
 *   各自在 `InputRouter` 里留一条 `instanceof`（正是本接口要消灭的东西）。
 *   ⇒ 把"框架能给的东西"打包成一个对象，由控件**按需取用**。
 *
 * ★ **为什么 `$dispatcher` 是闭包而不是 host 接口**：命令派发要落到
 *   `CommandRouter`，而那是 `App` 层的知识（§4.3 依赖方向：`Widget` 不能依赖
 *   `App`）。闭包由 `InputRouter` 构造时注入 ⇒ `Widget` 层只见到一个
 *   `\Closure(string, CommandArg): void`，不见到 `App` 的任何类型。
 *   与 `overlayPanel(\Closure $factory)` 同一条路数（§4.3 裁决）。
 *
 * @api
 */
final class ActivationContext
{
    /**
     * @param ?Element                $element    被激活的控件元素；`null` = 直接调用（无框架上下文）
     * @param ?\Closure               $dispatcher `\Closure(string, CommandArg): void`，命令派发出口
     */
    public function __construct(
        private readonly ?Element $element = null,
        private readonly ?\Closure $dispatcher = null,
    ) {
    }

    /**
     * 无框架上下文 —— 控件自己（或测试）直接调 `activate()` 时用。
     *
     * ★ 存在理由：`ToggleBase::activate()` / `Segment::activate()` 换值不需要
     *   任何外部信息，冒烟夹具直接调它们是合法的（不是"绕过框架"，是"这个动作
     *   本来就不依赖框架"）。
     */
    public static function none(): self
    {
        return new self();
    }

    /** 被激活的控件元素；直接调用时为 `null` */
    public function element(): ?Element
    {
        return $this->element;
    }

    /**
     * 派发一条命令（走全链：冒泡 → 注册表）。
     *
     * ★ 没有 dispatcher 时**静默不做事**（与"命令没人接"同语义）—— 不报错：
     *   直接调用 `activate()` 的场合本来就没有命令系统，那不是缺陷。
     */
    public function dispatch(string $command, CommandArg $arg): void
    {
        $dispatcher = $this->dispatcher;

        if ($dispatcher !== null) {
            $dispatcher($command, $arg);
        }
    }
}
