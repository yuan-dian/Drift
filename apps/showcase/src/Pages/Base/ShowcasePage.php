<?php
declare(strict_types=1);

namespace App\Showcase\Pages\Base;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\Page;

/**
 * 示例台的单类页面：Page 本身就是 State，字段 / `build()` / 事件处理器同处一类。
 *
 * 34 页里 33 页已合并到本类；唯一例外是 `SnackbarPage` —— 它的状态机委托给
 * `ReducerState`，必须经 `createState()` 挂树，故保留薄壳（见该类注释）。
 */
abstract class ShowcasePage extends Page
{
    public function __construct(protected readonly ShowcaseCapabilities $caps)
    {
    }
}
