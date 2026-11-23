<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * "我有版本号"。
 *
 * ★ 只有一件事要说：**我变了没有**。不提 Subject、不提 State、不提 Computed ——
 *   于是业务层与 UI 层可以各自实现，`Computed` 谁都不认识。
 *
 * ★ 版本号是**单调递增**的：写一次 +1。不要求连续、不要求可比较大小之外的语义
 *   （`Computed` 只做"和等不等"的比较）。
 *
 * ★ 它住在 `Core` 而不是 `State`：`Computed` 自己也要实现它（⇒ 可嵌套），
 *   而 `Store`（业务层）不该为了用 `Computed` 去依赖 State 层类型
 *   （`03` §4.4 选项 C —— "业务层零 GUI 依赖"是结构事实）。
 *
 * @api
 */
interface Versioned
{
    public function version(): int;
}
