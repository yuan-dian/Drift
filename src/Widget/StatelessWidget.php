<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 无局部 UI 态的控件：输出**只由入参决定**（§3.2 / §11.5）。
 *
 * ★ 若它需要"记住"点什么（悬停项、展开状态、编辑中的草稿），那是 `StatefulWidget`
 *   的事 —— 在这里塞字段 ⇒ 值变了也不会重建（`StatelessWidget` 没有重进入口）。
 *
 * ★ **基类 `Widget` 上刻意没有 `build()`**（§15.6.1）：同名零参方法会与这里的
 *   `build(BuildContext)` 构成不兼容覆盖 ⇒ PHP Fatal。封存那件事叫 `seal()`。
 *
 * @api
 */
abstract class StatelessWidget extends Widget
{
    /**
     * 描述本节点在给定渲染环境下的样子。
     *
     * 必须是**纯的**：给定同样的（入参 + `$ctx`）就得给出同样的输出。
     * 业务数据从构造器注入，不从 `$ctx` 取（§3.4 边界 ②）。
     */
    abstract public function build(BuildContext $ctx): Widget;
}
