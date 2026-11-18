<?php
declare(strict_types=1);

use Drift\App\Application;
use Drift\App\EventLoop;
use Drift\Commands\CommandGate;
use Drift\Commands\CommandHandler;
use Drift\Geometry\BoxConstraints;
use Drift\Platform\X11\X11Platform;
use Drift\Text\TextMetricsProvider;
use Drift\Theme\ThemeData;
use Drift\Widget\Center;
use Drift\Widget\Text;
use Drift\Widget\Widget;

final class X11ProbeApplication extends Application
{
    private int $frames = 0;
    private ?X11Platform $platform = null;
    private int $handle = 0;
    private int $target = 600;

    public function configure(X11Platform $platform, int $handle, int $target): void
    {
        $this->platform = $platform;
        $this->handle = $handle;
        $this->target = $target;
    }

    public function frameCountProbe(): int
    {
        return $this->frames;
    }

    public function wantsFrame(): bool
    {
        return true;
    }

    public function closeRequested(): bool
    {
        echo "[EVENT] close-requested received at frame {$this->frames}\n";

        return true;
    }

    // attach() 首个桥调用 drift_thread_id() 之后就是这些钩子；逐个打标定位卡点。
    public function theme(): ThemeData
    {
        echo "[PROBE] theme entered\n";

        return parent::theme();
    }

    public function metrics(): TextMetricsProvider
    {
        echo "[PROBE] metrics entered\n";

        return parent::metrics();
    }

    public function commandGates(): iterable
    {
        echo "[PROBE] commandGates entered\n";

        return parent::commandGates();
    }

    public function commands(): iterable
    {
        echo "[PROBE] commands entered\n";

        return parent::commands();
    }

    public function buildRoot(): Widget
    {
        echo "[PROBE] buildRoot entered\n";

        return Center::make()->child(Text::make('X11 Drift'));
    }

    public function title(): string
    {
        return 'X11 Drift';
    }

    public function frame(float $timestampMs, BoxConstraints $constraints): void
    {
        // attach() 内的同步首帧 t=0；EventLoop 的帧 t>0。打标区分卡在挂载首帧还是循环首帧。
        if ($this->frames === 0) {
            echo "[PROBE] first frame entered (t={$timestampMs})\n";
        }
        parent::frame($timestampMs, $constraints);
        if ($this->frames === 0) {
            echo "[PROBE] first frame finished\n";
        }
        $this->frames++;
        if ($this->frames % 60 === 0) {
            echo "[HEARTBEAT] frame={$this->frames}\n";
        }

        // 兜底自毁：注入失败时也能 10 秒内收工，脚本靠事件输出判 FAIL。
        if ($this->frames === $this->target && $this->platform !== null) {
            echo "-- {$this->frames} frames reached, destroying window --\n";
            $this->platform->window()->destroy($this->handle);
        }
    }
}

function main(): void
{
    echo "=== X11 E2E: real Application on EventLoop ===\n";
    $platform = new X11Platform();
    echo 'configDir=' . $platform->configDir() . "\n";
    echo 'now=' . $platform->now() . "\n";

    $handle = $platform->window()->createWindow('X11 Drift', 320, 240);
    echo "handle={$handle}\n";
    if ($handle <= 0) {
        echo "[FAIL] createWindow\n";
        exit(1);
    }
    $platform->window()->show($handle);
    echo "shown\n";

    $constraints = $platform->screen($handle)->viewport();
    echo "viewport {$constraints->maxWidth}x{$constraints->maxHeight}\n";

    $provider = $platform->surface($handle);
    if ($provider === null) {
        echo "[FAIL] surface provider\n";
        exit(1);
    }
    // 首帧在 attach() 里同步跑，而 resize 事件要等 EventLoop pump 才到 ⇒
    // 不先给尺寸，首帧画布是 0×0、present 被整段跳过。
    $provider->surface()->resize(
        (int) $constraints->maxWidth,
        (int) $constraints->maxHeight,
        1.0,
    );
    echo "surface ready\n";

    $app = new X11ProbeApplication();
    $app->attach($provider->surface(), $constraints);
    $app->configure($platform, $handle, (int) (\getenv('DRIFT_X11_TARGET_FRAMES') ?: 600));
    echo "attached\n";

    EventLoop::run($platform, [$handle => $app]);

    echo 'loop exited, frames=' . $app->frameCountProbe() . "\n";
    echo "=== RESULT: PASS ===\n";
}
