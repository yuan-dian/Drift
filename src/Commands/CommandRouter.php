<?php
declare(strict_types=1);

namespace Drift\Commands;

use Drift\Errors;

/**
 * 应用级命令注册表（§11.4.3 派发链第 ③ 环）。
 *
 * **为什么要注册表而不只靠页面 handler**：跨树复用、一个 handler 管一列命令
 * （`$nav` 同时管 `nav.users` / `nav.signup`）。反过来，只靠注册表又丢了"页面就近
 * 接管"、且页面私有命令必须提到应用级 ⇒ §12.2 的"单页独立预览"对任何带命令的页面
 * 都不成立。⇒ **两者都要**，所以 §11.4.3 是**链**：State → 祖先 State → 本注册表
 * → 都没接 ⇒ Diagnostic。
 *
 * ★ 本类只管**第 ③ 环**。整条链的组装（拿到命中的 Element、逐级向上、最后落到这里）
 *   在 `Drift\App` —— §4.3 禁 `Widget → Commands`，所以链不能住在 Widget 层。
 *
 * @api
 */
final class CommandRouter
{
    /** @var array<string, CommandHandler> */
    private array $handlers = [];

    private function __construct()
    {
    }

    /**
     * @api
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * 注册一个命令（§11.4.3）。
     *
     * @param CommandHandler $handler **具名类的实例**，不是闭包 —— 理由见
     *        `CommandHandler` 的说明（可枚举 / 可单测 / 静态可达）
     *
     * @api
     */
    public function on(string $name, CommandHandler $handler): self
    {
        if (isset($this->handlers[$name])) {
            // 后注册的静默覆盖先注册的 ⇒ 上线后"点了没反应"且查不出为什么。G2。
            Errors::report(sprintf(
                '命令 %s 已被 %s 注册，重复注册会静默覆盖 —— 请合并到一个 handler 或改名',
                $name,
                get_class($this->handlers[$name])
            ));
        }

        $this->handlers[$name] = $handler;

        return $this;
    }

    /**
     * 派发（§11.4.3 第 ③ 环）。
     *
     * @return bool 有没有人接。**没人接 ⇒ Diagnostic 级上报**（不静默：菜单项
     *         亮了但点了什么都没发生，是最难查的一类 bug）
     *
     * @api
     */
    public function dispatch(string $name, CommandArg $arg): bool
    {
        $handler = $this->handlers[$name] ?? null;
        if ($handler === null) {
            // 第 ④ 环：链上谁都没接。记环形缓冲（不中止）—— 应用还在跑，
            // 但这个命令名应当尽快修掉。
            Errors::report(sprintf('命令 %s 没有任何 handler 处理（冒泡链与注册表都没接）', $name), Errors::DIAGNOSTIC);

            return false;
        }

        // handler 返回 false = "不是我管" ⇒ 视为没接（调用方继续第 ④ 环）
        return $handler->handle($name, $arg);
    }

    /** 已注册的命令名 ——"死命令 lint"（§16.6.1 R5）的静态输入 */
    public function names(): array
    {
        return array_keys($this->handlers);
    }

    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    public function handlerFor(string $name): ?CommandHandler
    {
        return $this->handlers[$name] ?? null;
    }
}
