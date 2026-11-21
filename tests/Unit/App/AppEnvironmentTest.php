<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\AppEnvironment;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;

/**
 * 窗口级环境门面（notify / delay / modal）的结构与转发判据。
 *
 * 门面是"Store 能用、又摸不到上帝类"的那道窄口：身份须稳定（才能安全递给 Store），
 * 能力须与 Application 同源（否则门面与直调行为分叉），接口面须窄（否则 Store 反向
 * 拿到 frame/attach/input，等于没隔）。三条各钉一条。
 */
final class AppEnvironmentTest extends SmokeTestCase
{
    private function app(): EchoApplication
    {
        $app = new EchoApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 400.0)));

        return $app;
    }

    public function testEnvironmentIdentityIsStable(): void
    {
        $app = $this->app();
        check('门面身份稳定（可安全递给 Store 持有）', $app->environment() === $app->environment());
        $this->flushChecks();
    }

    public function testFacadeIsWindowLevelOnly(): void
    {
        $methods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(AppEnvironment::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        // 只暴露环境能力；宿主/帧内方法一个都不能漏进门面。
        foreach (['frame', 'attach', 'input', 'dispatch', 'showSnackbar', 'after'] as $leak) {
            check("门面不暴露 {$leak}", !\in_array($leak, $methods, true));
        }
        foreach (['notify', 'delay', 'cancelDelay', 'showModal', 'closeModal'] as $cap) {
            check("门面暴露能力 {$cap}", \in_array($cap, $methods, true));
        }
        $this->flushChecks();
    }

    public function testNotifyViaFacadeReachesApplication(): void
    {
        $app = $this->app();
        $app->environment()->notify(Snackbar::make('门面通知'));
        check('门面 notify 到达 Application 的通知槽', $app->hasSnackbar());
        $app->environment()->dismissSnackbar();
        check('门面 dismiss 同源收掉', !$app->hasSnackbar());
        $this->flushChecks();
    }

    public function testModalViaFacadeReachesApplication(): void
    {
        $app = $this->app();
        $app->environment()->showModal(Modal::make());
        check('门面 showModal 到达 Application 的浮层栈', $app->isModalOpen());
        $app->environment()->closeModal();
        check('门面 closeModal 同源关掉', !$app->isModalOpen());
        $this->flushChecks();
    }

    public function testDelayViaFacadeIsCancellable(): void
    {
        $app = $this->app();
        $fired = false;
        $id = $app->environment()->delay(500, static function () use (&$fired): void {
            $fired = true;
        });
        $app->environment()->cancelDelay($id);

        $app->frame(2000.0, BoxConstraints::loose(new Size(320.0, 400.0)));
        check('门面 delay 排的定时器可被门面取消', !$fired);
        $this->flushChecks();
    }
}
