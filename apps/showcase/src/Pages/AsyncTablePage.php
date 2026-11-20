<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\State\AsyncCommand;
use Drift\Widget\Button;
use Drift\Widget\State;
use App\Showcase\Pages\States\AsyncTablePageState;

/**
 * 异步表格—— **事件循环 / 异步能力接入示例**（N3 + N5 + N6）。
 *
 * ## 它演示的真实业务形状（不是玩具）
 *
 * 一个列表页最常见的样子：**进页面就请求数据（慢）→ 转圈 → 失败可重试 → 成功画表**。
 * 这里的三个关键点正是用户要的"不能让 UI 卡住"：
 *   ① 取数在**后台**（`ProcessTaskHost` 的 worker 进程里跑，主进程一帧不停）；
 *   ② 请求的整个生命周期由 `AsyncCommand` 收口（发起 / 去重 / 取消 / 重试）；
 *   ③ 数据到达在**帧内**（`drain` 在 `settle` 之前）⇒ `setState` 同帧生效，不空一帧。
 *
 * ## 页面的异步簿记只有两行
 *
 * ```php
 * $this->rows = $this->asyncCommand(fn(int $page) => $this->caps->fetchRows($page));   // initState：造 + 绑定
 * $this->rows->run($page);                               // 发起
 * $this->rows->value()                                   // build：读四态
 * ```
 *
 * ★ **连 `dispose()` 都不用写**：`State::asyncCommand()` 造的命令由框架在
 *   `unmount()` 时统一停掉（与清理袋同一条兜底）—— 于是"转圈中离开页面 ⇒
 *   worker 白跑满 1.2s"不需要谁记得防。
 *
 * ## 翻页时**表格不消失**
 *
 * 点「下一页」发的是 `loading`，但这个 `loading` **带着上一页的行**
 * （`AsyncValue::carriedFrom()`）⇒ `fold()` 把它路由到 `data` 支 ⇒ 表格照旧画，
 * 只有标题变成「正在加载第 2 页…」、翻页按钮变灰。上一版这里整块换成转圈，
 * 内容消失又出现（用户读到的是"卡了一下"）。
 *
 * ★ 于是"刷新中"这句话必须由**页面**说（`isRefreshing()`）—— 数据还在，界面
 *   看起来就和"没在跑"一样。框架不会替页面决定提示长什么样。
 *
 * 之前（用 `FutureBuilder`）同一件事要写：一个 `$future` 字段、一个"换 Future
 * 触发重订阅"的提交点、一层 `FutureBuilder::make(...)` 包装、一段"同页 / 在途就
 * 不重复提交"的判断。那四样里只有最后一样是业务，其余全是**异步机制的簿记**
 * —— 现在它们在 `AsyncCommand` 里，全仓一份。
 *
 * ## 数据来源完全由组合根决定（页面零感知）
 *
 * 页面只认一个 `\Closure(int): Future`（"取第 N 页"）：
 *   · 真机（`Bootstrap`）⇒ `AppScope::taskHost()->submit(new FetchRowsJob(...))`（真慢任务）；
 *   · 基准 / 整页快照（`Trees::showcase()`）⇒ `Future::resolved([...静态...])`（稳定、不转圈）。
 *   ⇒ 换成"真 HTTP / 本地模拟 / 直接给数据"都不用改本页一行 —— 这是"业务与 UI 隔离"
 *     在**接线层**的形态，也是本页能被无头基准稳定渲染的原因。
 *
 * ## 分页用**禁用态**表达边界，不用回调里 return
 *
 * 第 1 页的「上一页」、转圈时的翻页，都由 `Button::enabled()` 画出来（用户看得见
 * "现在不能点"），而不是在 `onPressed` 里悄悄 `return`（点了没反应、也不知道为什么）。
 * 命令的 `isRunning()` 直接就是那个判据 —— 这是 `AsyncCommand` 的存在理由之一。
 */
#[Route(id: 'async-table', title: '异步表格 Async', icon: IconName::ListBullets)]
final class AsyncTablePage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new AsyncTablePageState($this->caps);
    }
}
