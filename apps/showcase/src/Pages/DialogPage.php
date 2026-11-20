<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Platform\Dialog;
use Drift\Widget\State;
use Drift\Widget\Widget;
use App\Showcase\Pages\States\DialogPageState;

/**
 * 原生对话框 Dialog—— A5-① 的唯一消费者。
 *
 * ★★ **为什么这一页必须存在**：`Dialog` 端口在框架里躺了一个多月
 *   （`Win32Platform::dialog()` 一直返回 `null`）。端口加方法而没人调用是
 *   "未实现"的一种伪装 —— 这一页是它唯一的调用点，也是 T2 真机取证的落点。
 *
 * ★ **四个方法各给一个按钮**，因为它们的行为**真不一样**：`openFile` /
 *   `saveFile` 返回路径（取消 ⇒ `null`），`alert` 无返回，`confirm` 返回 bool
 *   且默认按钮是"否"。合起来一个"对话框演示"会把三种返回值语义糊成一团。
 *
 * ★ **结果写在页面上而不是 Snackbar 上**：`Snackbar` 会自己消失，而这一页要
 *   证明的是"端口到底递回了什么"（尤其是**取消**这一支）。
 *
 * ★ **按钮回调里直接调**（同步阻塞）是**正确写法**，不是偷懒：原生对话框是模态的，
 *   它没有"完成事件"可挂。代价写在「能力边界」第 ① 条里。
 *
 * ★ `$dialog` **由组合根递进来**（`ShowcaseRoot` → 本页）：`State` 上没有
 *   `application()` 访问器（§4.3 禁 `Widget → App`），平台名只出现在组合根。
 *   ★ 形参**不可空**：`?Dialog` 字段在 AOT 侧退化 `php::Var`（PITFALLS 第 20 条）。
 */
#[Route(id: 'dialog', title: '对话框 Dialog', icon: IconName::Info)]
final class DialogPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new DialogPageState($this->caps);
    }
}
