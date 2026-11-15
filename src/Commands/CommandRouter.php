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
 * ## 两层：全局表是父，本窗表是子（2026-10-30，M2）
 *
 * 多窗口下"命令表"其实有**两种作用域**，而它们必须同时在：
 *
 * | 表 | 谁注册 | 例子 |
 * |---|---|---|
 * | 父（`AppScope::commands()`） | 组合根 | `app.quit`（关一个窗 ≠ 关进程，只有它知道） |
 * | 子（`Application::$router`） | 本窗 `commands()` | `users.refresh`（handler 持本窗的 Store） |
 *
 * ★ **为什么不能"整表共享"**：窗口级的 handler 持有**本窗的 Store**，若另一窗
 *   能派发到它，改的就是别人家的数据 —— 那不是"多一个入口"，是**错窗口**。
 *   也不能"每窗各注册一份全局命令"：`on()` 的同名检查会当场 Fatal。
 *   ⇒ 父链是唯一同时满足"注册一次 / 全窗可见 / 本窗隔离"的形状。
 * ★ **解析在前，调用在后**：按名字在链上找 handler，**找到谁就是谁**，不靠
 *   `handle()` 返回 `false` 去"协商"。名字是唯一的，所以解析阶段就能定；
 *   把"不是我管"当成继续冒泡会与第 ⑤ 环的 G2 上报打架。
 *
 * @api
 */
final class CommandRouter
{
    /** @var array<string, CommandHandler> */
    private array $handlers = [];

    /** @var array<string, CommandGate> 与 handlers 同一个写者 ⇒ 写不出"门控了不存在的命令" */
    private array $gates = [];

    private function __construct(private ?self $parent = null)
    {
    }

    /**
     * @param null|self $parent 父表（全局命令）；`null` = 单层表（默认，行为与旧版一致）
     *
     * @api
     */
    public static function make(?self $parent = null): self
    {
        return new self($parent);
    }

    /**
     * 注册一个命令（§11.4.3）。
     *
     * @param CommandHandler $handler **具名类的实例**，不是闭包 —— 理由见
     *        `CommandHandler` 的说明（可枚举 / 可单测 / 静态可达）
     * @param null|CommandGate $gate 可选门控；不给 ⇒ 该命令恒可用（兼容既有注册点）
     *
     * @api
     */
    public function on(string $name, CommandHandler $handler, ?CommandGate $gate = null): self
    {
        if (isset($this->handlers[$name])) {
            // 后注册的静默覆盖先注册的 ⇒ 上线后"点了没反应"且查不出为什么。G2。
            Errors::report(sprintf(
                '命令 %s 已被 %s 注册，重复注册会静默覆盖 —— 请合并到一个 handler 或改名',
                $name,
                get_class($this->handlers[$name])
            ));
        } elseif ($this->parent?->has($name) === true) {
            // ★ 覆盖父表**是合法语义**（本窗特化），但必须留痕：调试"为什么这个窗
            //   点了行为不一样"时，唯一线索就是这一行。
            Errors::report(sprintf(
                '命令 %s 应用级已注册（%s），本窗口又注册了一次 —— 本窗将覆盖它',
                $name,
                get_class($this->parent->handlerFor($name) ?? $handler),
            ), Errors::DIAGNOSTIC);
        }

        $this->handlers[$name] = $handler;
        if ($gate !== null) {
            $this->gates[$name] = $gate;
        }

        return $this;
    }

    /** 全链是否允许派发；没挂门控 ⇒ true。由 `Application::dispatch()` 一处调用。 */
    public function enabled(string $name, CommandArg $arg): bool
    {
        return $this->gateInChain($name)?->allows($name, $arg) ?? true;
    }

    /** 沿父链找门控（就近优先，与 handlerInChain 同形） */
    private function gateInChain(string $name): ?CommandGate
    {
        return $this->gates[$name] ?? $this->parent?->gateInChain($name);
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
        $handler = $this->handlerInChain($name);
        if ($handler === null) {
            // 第 ④ 环：链上谁都没接。记环形缓冲（不中止）—— 应用还在跑，
            // 但这个命令名应当尽快修掉。
            Errors::report(sprintf('命令 %s 没有任何 handler 处理（冒泡链与注册表都没接）', $name), Errors::DIAGNOSTIC);

            return false;
        }

        // handler 返回 false = "不是我管" ⇒ 视为没接（调用方继续第 ④ 环）
        return $handler->handle($name, $arg);
    }

    /** 沿父链找 handler（就近优先：本窗注册的覆盖全局的） */
    private function handlerInChain(string $name): ?CommandHandler
    {
        return $this->handlers[$name] ?? $this->parent?->handlerInChain($name);
    }

    /**
     * 已注册的命令名 ——"死命令 lint"（§16.6.1 R5）的静态输入。
     *
     * ★ **只报本层**（不含父表）：调用方问的是"这张表里有什么"，而父表的
     *   名册归父表自己答。要看全链用 `namesInChain()`。
     */
    public function names(): array
    {
        return array_keys($this->handlers);
    }

    /** 全链命令名（本层 + 父链，已去重）—— devtools / 诊断用 */
    public function namesInChain(): array
    {
        return array_values(array_unique(\array_merge(
            $this->names(),
            $this->parent?->namesInChain() ?? [],
        )));
    }

    /** 本层是否注册了这个名字（**不含父表** — 见 `hasInChain()`） */
    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /** 全链是否有人接这个名字（派发前的判据用它） */
    public function hasInChain(string $name): bool
    {
        return isset($this->handlers[$name]) || $this->parent?->hasInChain($name) === true;
    }

    /** 本层的 handler（`null` = 本层没注册；父表的用 `handlerInChain()` 走派发） */
    public function handlerFor(string $name): ?CommandHandler
    {
        return $this->handlers[$name] ?? null;
    }
}
