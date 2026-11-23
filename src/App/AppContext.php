<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\App\Io\AsyncHttp;
use Drift\App\Io\IoPoller;
use Drift\Async\Future;
use Drift\Input\AcceleratorTable;
use Drift\Platform\Clipboard;
use Drift\Text\TextMetricsProvider;
use Drift\Theme\ThemeData;

/**
 * 本窗的服务上下文：主题 / 度量 / 端口 / 异步提交。
 *
 * 只收“应用能用什么”，不收帧循环、输入路由、根树；页面与 Store 需要的能力
 * 已经由 {@see AppEnvironment} 覆盖，本类供 `Application` 的默认实现复用。
 *
 * @internal 由 `AppShell` 持有；应用侧仍走 `Application` 的公开入口。
 */
final readonly class AppContext
{
    public function __construct(
        private AppScope $scope,
        private AppEnvironment $environment,
        private PumpHost $pumps,
    ) {
    }

    public function scope(): AppScope
    {
        return $this->scope;
    }

    public function environment(): AppEnvironment
    {
        return $this->environment;
    }

    public function theme(): ThemeData
    {
        return $this->scope->theme();
    }

    public function metrics(): TextMetricsProvider
    {
        return $this->scope->metrics();
    }

    public function http(): AsyncHttp
    {
        return $this->scope->asyncHttp();
    }

    public function accelerators(): AcceleratorTable
    {
        return $this->scope->accelerators();
    }

    public function clipboard(): ?Clipboard
    {
        return $this->scope->clipboard();
    }

    public function io(): ?IoPoller
    {
        return $this->pumps->io();
    }

    public function taskHost(): ?TaskHost
    {
        return $this->scope->taskHost();
    }

    public function submit(Job $job): Future
    {
        return $this->submitWith($this->scope->dispatch(), $job);
    }

    public function submitWith(TaskDispatch $dispatch, Job $job): Future
    {
        return $dispatch->submit($job);
    }
}
