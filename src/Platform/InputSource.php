<?php
declare(strict_types=1);

namespace Drift\Platform;

use Drift\Input\InputEvent;

/**
 * 原始事件泵（§9.1 路由链的输入侧）。
 *
 * ★ **平台只负责"归一化"，不负责"路由"**：Win32 的 `MSG` 在这里被翻成 Drift 事件
 *   （PHP 侧因此不持有任何 Win32 常量、不做 `wParam` 位运算 —— GUI 仓 `win32.stub.php`
 *   开头那三条纪律），而"加速键 → IME → 焦点链"与"命中测试 → GestureArena"的
 *   **派发**归 `EventLoop`（`Drift\App`，M4）。
 *
 * ★ **入队由 `Platform::pumpEvents()` 驱动**：`waitEvents()` 阻塞等到一条消息，
 *   `pumpEvents()` 归一化后 `push()` 进本队列。于是"等待""入队""消费"三步分开 ——
 *   等待阻塞在平台上，入队与跑帧成对（`EventLoop`），消费由框架在自己方便的时机
 *   抽干（一帧一次）。
 *
 * ★ 依赖说明：本文件让 `Platform` 层引用了 `Input` 的 `InputEvent`。§9.1 的路由链图
 *   把平台输出逐字写成 `PointerEvent / KeyEvent`，而 §4.3 的 `Platform` 行没登记
 *   这条边 ⇒ 按"写全已有裁决"补一条**按文件**的协议例外（与 `Input` 行已有的
 *   `Text\TextInputClient` 例外同一机制）。
 *
 * @api platform
 */
interface InputSource
{
    /**
     * 取一条已归一化的事件；队列空 ⇒ `null`（**非阻塞**）。
     *
     * ★ 非阻塞是有意的：一帧要抽干队列，而"这一帧没事件"是**正常状态**，
     *   不是错误 ⇒ 用 `null` 而不是抛异常，也不许调用方空转等待。
     */
    public function poll(): ?InputEvent;

    /**
     * 入队一条事件（由平台实现自己调用；`@internal` 语义，但接口上无法标注）。
     *
     * ★ 暴露在接口上而不是藏在实现里，是为了让**测试与自检**能合成事件序列
     *   （GUI 仓的合成事件是自检覆盖交互路径的唯一手段 —— 真实鼠标点不出来）。
     */
    public function push(InputEvent $event): void;
}
