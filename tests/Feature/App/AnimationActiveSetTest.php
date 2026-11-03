<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Core\Pipeline;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\App\SpinnerApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\AnimatedValue;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * **动效活跃表**：帧时钟只访问"在动的那几个"（2026-11-03，`RESEARCH-ALIGN` §2 的 P0）。
 *
 * ## 为什么需要这条测试
 *
 * 改之前 `Application::tickAnimations()` 每帧递归**整棵树**，只为问一句
 * "有没有人在动"。实测 2000 节点 **935µs**（占稳态帧 98%）。改完之后有两份表
 * 要维护（"谁在动" + "谁刚起飞"），而**两份都可能漂**：
 *
 * | 漂法 | 症状 |
 * |---|---|
 * | 漏登记（起飞没举手） | 动画不动、不报错（滚轮滚了、偏移 0） |
 * | 出表太早（同帧重入返回 false） | 动画停在中途，画面冻结 |
 * | 出表太晚（卸载了还留着） | 僵尸 tick，帧时钟永不落闸 |
 *
 * 三条都不报错，所以判据必须是**结构**的，不能靠"跑起来看看"。
 *
 * ## 判据的形状
 *
 * ① 空转帧**一个节点都不访问**（访问次数，不看时间 —— 时间是噪声）
 * ② 一个动画在跑时**只访问一个节点**
 * ③ 中途起步 / 停 / 再起步都能被发现（`markDirty` 那条通道）
 * ④ **对账**：活跃表路径与全树路径逐帧结果必须完全一致
 * ⑤ 收口帧的终值不丢
 *
 * ★ ④ 是核心：前三条都只证"我做的这件事生效了"，只有 ④ 能证"我没漏"。
 *   与 `FrameStageAccountingTest` 第 ① 条同构（名单驱动 + 双向比对）。
 */
final class AnimationActiveSetTest extends SmokeTestCase
{
    private static function bounds(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(200.0, 200.0));
    }

    // ① 空转帧零访问
    public function testIdleFramesVisitNothing(): void
    {
        $app = new EchoApplication();
        $c = self::bounds();
        $app->attach(new SurfaceProbe(), $c);

        // attach 那帧会做一次全树发现（首帧没有表），之后表是空的
        $afterAttach = $app->animationVisits();
        for ($i = 1; $i <= 3; $i++) {
            $app->frame((float) ($i * 16), $c);
        }

        check(
            '动效活跃表 · 空转帧一个节点都不访问（不再 O(树)）',
            $app->animationVisits() === $afterAttach,
            sprintf('attach 后 %d，3 帧后 %d', $afterAttach, $app->animationVisits()),
        );
        check('动效活跃表 · 空转帧活跃表为空', $app->activeAnimationCount() === 0);
    }

    // ② 一个动画 ⇒ 每帧只访问一个节点
    public function testOneAnimationVisitsOneNode(): void
    {
        $app = new SpinnerApplication();
        $c = self::bounds();
        $app->attach(new SurfaceProbe(), $c);

        $before = $app->animationVisits();
        $app->frame(16.0, $c);

        check(
            '动效活跃表 · 一个 Spinner 在跑 ⇒ 一帧只访问一个节点',
            $app->animationVisits() - $before === 1,
            (string) ($app->animationVisits() - $before),
        );
        check('动效活跃表 · 活跃表里只有一个', $app->activeAnimationCount() === 1);
    }

    // ③ 起步 / 停 / 再起步
    public function testStartStopRestart(): void
    {
        $app = new PulseApplication();
        $c = self::bounds();
        $app->attach(new SurfaceProbe(), $c);
        $state = PulseState::$last;

        check('动效活跃表 · 静止时不在表里', $app->activeAnimationCount() === 0);

        for ($i = 1; $i <= 3; $i++) {
            $app->frame((float) ($i * 16), $c);
        }
        check('动效活跃表 · 静止时没被 tick 过', $state->value === 0.0, (string) $state->value);

        // 起步：`setState()` 是"我可能要动"的举手点
        $state->start();
        $app->frame(64.0, $c);
        check('动效活跃表 · 起步后被 tick 了', $state->value > 0.0, (string) $state->value);
        check('动效活跃表 · 起步后进了活跃表', $app->activeAnimationCount() === 1);

        // 停：`tickFrame()` 返回 false ⇒ 出表
        $state->halt();
        $app->frame(80.0, $c);
        check('动效活跃表 · 停下即出表', $app->activeAnimationCount() === 0);

        // 再起步：出表之后必须能重新进来（标记位不能卡在 true）
        $state->start();
        $before = $state->value;
        $app->frame(96.0, $c);
        check('动效活跃表 · 出表后能重新进来', $state->value > $before, (string) $state->value);
    }

    // ④ 对账：活跃表路径 == 全树路径
    public function testActiveTableMatchesFullScan(): void
    {
        $byTable = self::runPulse(false, 24);
        $byScan = self::runPulse(true, 24);

        check(
            '动效活跃表 · 与全树扫描逐帧一致（漏登记会在这里分叉）',
            $byTable === $byScan,
            'table=' . \json_encode($byTable) . ' scan=' . \json_encode($byScan),
        );

        // 反例方向：如果两条路径都"什么都没动"，上面也相等 ⇒ 必须另证它真的动了
        check(
            '动效活跃表 · 对账样本本身在动（否则比对无意义）',
            $byTable[23][0] > 0.0,
            (string) $byTable[23][0],
        );
    }

    // ⑤ 收口帧的终值不丢
    public function testSettlingFrameKeepsFinalValue(): void
    {
        $app = new SettleApplication();
        $c = self::bounds();
        $app->attach(new SurfaceProbe(), $c);
        $state = SettleState::$last;

        $state->to(1.0);

        $frames = 0;
        while ($app->wantsFrame() && $frames < 400) {
            $app->frame((float) (++$frames * 16), $c);
        }

        check(
            '动效活跃表 · 收口帧的终值就是目标值（不是 0.93）',
            $state->anim?->value() === 1.0,
            var_export($state->anim?->value(), true),
        );
        check('动效活跃表 · 收口后活跃表清空', $app->activeAnimationCount() === 0);
    }

    /**
     * 跑同一个"中途起步的动画"，`$forceScan` 决定走哪条路径。
     *
     * @return list<array{0: float, 1: bool}> 每帧的 `[值, wantsFrame]`
     */
    private static function runPulse(bool $forceScan, int $frames): array
    {
        $app = new PulseApplication();
        $c = self::bounds();
        $app->attach(new SurfaceProbe(), $c);
        $state = PulseState::$last;
        $state->start();

        $out = [];
        for ($i = 1; $i <= $frames; $i++) {
            if ($forceScan) {
                // 帧外请求 ⇒ 下一帧走全树发现（与"首帧 / 结构变化"同一条路）
                Pipeline::current()->requestAnimationScan();
            }
            $app->frame((float) ($i * 16), $c);
            $out[] = [$state->value, $app->wantsFrame()];
        }

        return $out;
    }
}

/** 一个"自己决定何时动"的控件：`tickFrame()` 只在 `$running` 时返回 true */
final class PulseApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Pulse::make();
    }

    public function title(): string
    {
        return '脉冲';
    }
}

final class Pulse extends StatefulWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new PulseState();
    }
}

/** @internal 见 `PulseApplication` */
final class PulseState extends State
{
    public static ?PulseState $last = null;

    public bool $running = false;

    public float $value = 0.0;

    public function initState(): void
    {
        self::$last = $this;

        parent::initState();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Text::make('pulse');
    }

    public function tickFrame(float $timestampMs): bool
    {
        if (!$this->running) {
            return false;
        }
        ++$this->value;

        return true;
    }

    public function start(): void
    {
        $this->setState(function (): void {
            $this->running = true;
        });
    }

    public function halt(): void
    {
        $this->setState(function (): void {
            $this->running = false;
        });
    }
}

/** 用 `AnimatedValue` 驱动的收口帧夹具 */
final class SettleApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Settle::make();
    }

    public function title(): string
    {
        return '收口';
    }
}

final class Settle extends StatefulWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new SettleState();
    }
}

/** @internal 见 `SettleApplication` */
final class SettleState extends State
{
    public static ?SettleState $last = null;

    public ?AnimatedValue $anim = null;

    public function initState(): void
    {
        self::$last = $this;

        parent::initState();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Text::make('settle');
    }

    public function to(float $target): void
    {
        $this->anim = new AnimatedValue(0.0);
        $this->anim->toward($target);
        // `toward()` 自己会请求"全树发现"，但帧时钟还得有人叫醒 —— 这与
        // `State::requestFrame()` 的分工一致（前者管"发现"，后者管"上膛"）。
        $this->requestFrame();
    }

    public function tickFrame(float $timestampMs): bool
    {
        return $this->anim?->tick($timestampMs) ?? false;
    }
}
