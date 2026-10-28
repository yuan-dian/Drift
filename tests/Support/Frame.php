<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\App\Application;
use Drift\Errors;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * 应用级帧驱动：把"挂载探针 + 推帧 + 语义 sink 计数/模式 + Errors 重置"收口成一个对象。
 *
 * 用例只关心"推几帧、每帧时间戳、语义推送怎么数"，不再手写 attach / frame / sink 接线。
 * 帧号在 {@see self::tick()} 内部自增 —— 这是 S1 用 `EditSession` 验证过的约束
 * （"按键后必须推帧"一旦漏写不报错、只表现为读到旧值），这里把它泛化到所有
 * "应用 + 帧"子系统（帧预算 / 挂载登记 / 语义推送 / ensureVisible）。
 */
final class Frame
{
    public readonly Application $app;
    private int $pushes = 0;
    private string $mode = '';
    private float $ts = 0.0;

    public function __construct(Application $app, float $w = 320.0, float $h = 200.0)
    {
        $this->app = $app;
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size($w, $h)));
        $app->setSemanticsSink(function (array $nodes) use ($app): void {
            $this->pushes++;
            $this->mode = $app->semanticsMode();
        });
    }

    public static function open(Application $app, float $w = 320.0, float $h = 200.0): self
    {
        return new self($app, $w, $h);
    }

    /** 推进一帧（帧号内部自增，用例不必手算时间戳）。 */
    public function tick(float $dtMs = 16.0, float $w = 320.0, float $h = 200.0): self
    {
        $this->ts += $dtMs;
        $this->app->frame($this->ts, BoxConstraints::tight(new Size($w, $h)));

        return $this;
    }

    public function pushCount(): int
    {
        return $this->pushes;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** 清掉测试侧推送计数（不影响应用内部节流状态）。 */
    public function resetPushes(): self
    {
        $this->pushes = 0;
        $this->mode = '';

        return $this;
    }

    public function resetErrors(): self
    {
        Errors::reset();

        return $this;
    }

    /** @return list<string> */
    public function diagnostics(): array
    {
        return Errors::recentDiagnostics();
    }
}
