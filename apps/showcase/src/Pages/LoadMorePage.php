<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\State\AsyncCommand;
use Drift\Widget\ScrollController;
use Drift\Widget\State;
use App\Showcase\Pages\States\LoadMorePageState;

/**
 * 加载更多—— **`AsyncCommand::append()` 的消费者**。
 *
 * ## 它和「异步表格」差在哪（一句话）
 *
 * | 页 | 动作 | 第 2 页到达后，界面上的行 |
 * |---|---|---|
 * | 异步表格 | `run($page)` —— **替代** | **换掉**：只剩第 2 页那 20 行 |
 * | 本页 | `append($page)` —— **追加** | **接上**：第 1 页的 20 行还在，共 40 行 |
 *
 * 两者都是"分页取数"，差别只在"已看到的东西该不该留着"。这个差别**不能**靠
 * 一个 flag 表达 —— 它是两种业务（`AsyncCommand` 的类注释有完整论证）。
 *
 * ## 为什么必须有一个页面用它（而不是只留单测）
 *
 * 本仓纪律：**未被消费的分支 = 未实现**。`append()` 的三条判据（合并 / 在途时
 * 旧行还在 / 失败时旧行还在）只有真的跑帧、真的走 `fold()` 才成立；单测能证明
 * `$value` 里有什么，证明不了"界面还在画旧行"。
 * ⇒ 这一页同时是文档里的范文和冒烟/特性测试的被测对象。
 *
 * ## 界面形态
 *
 * 「加载更多」是按钮**也**是滚动触底：滚到底自动取下一页（`ScrollController`
 * 回调里按 `offset >= max - 8` 判），按钮留着给键盘与无障碍——两条路都落到
 * 同一个 `loadMore()`，命令那一侧一行都不用改（"语义在命令、触发在页面"）。
 *
 * ★ 页脚那句"正在加载第 N 页…"来自 `isRefreshing()`：追加中 `fold()` 走的是
 *   **`data` 支**（旧行还在，该画内容），所以"正在忙"必须由页面自己说出来。
 *   同一个理由见 `AsyncTablePage::toolbar()`。
 */
#[Route(id: 'load-more', title: '加载更多 More', icon: IconName::ListBullets)]
final class LoadMorePage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new LoadMorePageState($this->caps);
    }
}
