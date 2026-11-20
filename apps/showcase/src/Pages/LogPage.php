<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Logging\Log;
use Drift\Widget\State;
use Drift\Widget\Widget;
use App\Showcase\Pages\States\LogPageState;

/**
 * 日志 Log——devtools 面板。
 *
 * ★ **为什么这页要有按钮写日志**：showcase 的业务现在**不写日志**（`Log` 刚建好，
 *   还没接），空面板没意义。按钮让分类 / 级别 / 过滤三件事都有可见的样本 ——
 *   这也是"日志系统能不能用"的最小演示。
 * ★ **过滤在数据层**（`RingSink::recent($category, $minLevel)`）不在这里：
 *   Widget 只负责"选了哪个过滤 + 怎么画"，查询语义属于日志系统。
 * ★ 三个过滤档是**封闭域**（全部 / WARN+ / 单分类）—— 与 `FeedbackPage` 的
 *   `StubState` 同一形状，加一档编译期就叫。
 */
#[Route(id: 'log', title: '日志 Log', icon: IconName::ListBullets)]
final class LogPage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new LogPageState($this->caps);
    }
}
