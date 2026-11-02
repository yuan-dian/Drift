<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 后台任务单元（§17.2 / §24.1 #35）—— **是类，不是闭包**。
 *
 * ★ **为什么必须是类**（2026-10-14 裁决，§23.2 O-03 方案 A）：与
 *   `CommandHandler` 必须是类**同一条设计语言** ——
 *   **凡是要跨边界的东西都是类**（可枚举、可单测、可静态可达性分析）；
 *   而且它**绕开了"闭包跨线程未验"这个开放项**（只传对象 + 调方法）。
 *
 * ★ **参数由构造器注入**（§O-03 方案 A）：`Job` 自己不带状态机，
 *   依赖在 `new` 的时候就位 ⇒ `run()` 无参、可重放、可单测。
 *
 * ★ 与 `TaskHost` 的分工：`Job` 说"**做什么**"，`TaskHost` 说"**在哪跑、结果怎么回来**"。
 *   换后端（§17.2.3）只换 `TaskHost`，`Job` 一字不改。
 *
 * @api
 */
interface Job
{
    /**
     * 执行任务本体，返回值经 `TaskHost::submit()` 的 `$onDone` 回贴。
     *
     * ★ 返回类型故意是 `mixed`：任务五花八门（取一页 / 删一行 / 算一个和），
     *   统一成一种 DTO 只会逼每个任务都套一层没有信息量的壳。类型由**调用点的
     *   闭包签名**收窄（`function (Page $p): void { … }`）—— 与
     *   `Draggable::payload()` 是同一手法。
     *
     * ★ **`run()` 里不许碰 Widget / Element / State**（§11.2 的分工）：后台只碰业务
     *   数据；要改 UI 就在 `$onDone` 里改（那一段在 UI 线程上执行）。
     *
     * @api
     */
    public function run(): mixed;
}
