<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Debug\Session\InputScript;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\SessionDriver;
use Drift\Debug\Session\Step;

/**
 * 把一份 {@see Script} 重新喂给一个**新会话**。
 *
 * ## 为什么是"重新执行"，不是"恢复现场"
 *
 * 快照式回放要能存下某一帧的 `State`（任意闭包、资源、对象图）。PHP 序列化不了，
 * AOT 也没有稳定地址 —— 那条路在本项目是死路（见调试模型的"边界"）。而 Drift 的
 * 布局是纯函数、重建是声明式，**同样的输入必然得到同样的输出**：所以回放是
 * "从第一帧重跑到第 N 帧"，不是"跳回第 N 帧"。
 *
 * 这不是退而求其次：重跑能得到的东西比快照更多 —— 快照只有结果，重跑能给出
 * 到那一帧为止的**全部因果**（谁在第 3 帧写了状态、第 5 帧才被消费）。
 *
 * ## 为什么调用方要给应用实例和副作用
 *
 * 会话不持有"怎么造这一页"（组合根的事），也不猜"这一页怎么取数"（驱动方的事）。
 * 重放方照原样再备一份即可 —— 这份重复是**有意的**：它让"环境不一样"变成编译期
 * 就能看见的显式参数，而不是藏在脚本里的魔法。
 *
 * ## 为什么吃接口而不是 `DebugSession`
 *
 * 同一个循环要能驱动"记录就在手边"和"记录在另一个进程"两种会话。写死具体类的话，
 * 远端要么复制一遍这个循环（`--at`、步号报错、效果参数剥离都会分叉），要么放弃
 * 逐帧控制改成一个粗粒度命令 —— 前者是两份真相，后者让 `--at` 变成远端不支持。
 *
 * ## 停止条件
 *
 * `$untilFrame = N` ⇒ 恰好推进 N 帧，之后不再派发输入（第 N 帧之后的动作属于
 * "还没发生"）。这就是"看第 N 帧前后"的落点：跑到 N-1 查一次，再跑一帧查一次。
 *
 * @api
 */
final class Replay
{
    /**
     * @param array<string, callable(array<string, scalar|null>): mixed> $effects
     * @param int|null $untilFrame 只推进到第 N 帧（`null` = 跑完整份脚本）
     * @param null|callable(int, float): void $onFrame 每帧回调（帧号, 整帧墙钟毫秒）。
     *   报告要按帧列墙钟，而墙钟只能由**驱动方**量 —— 记录里的 `cost` 是另一个口径。
     *
     * @return int 实际推进的帧数
     *
     * @throws \RuntimeException 脚本不完整、含不可重放的输入、或步骤形状坏了
     */
    public static function apply(
        SessionDriver $session,
        Script $script,
        array $effects = [],
        ?int $untilFrame = null,
        ?callable $onFrame = null,
    ): int {
        if ($script->truncated()) {
            // 截断的脚本"能跑一部分"是最坏的情况：报告看起来正常，只是少了后半段。
            throw new \RuntimeException(
                '脚本被截断（超过 ' . Script::MAX_STEPS . ' 步），后半段没记下来 —— 不能当完整脚本重放',
            );
        }
        if ($untilFrame !== null && $untilFrame < 1) {
            throw new \InvalidArgumentException("--at 必须 ≥ 1，实际 {$untilFrame}");
        }

        foreach ($effects as $name => $run) {
            $session->defineEffect($name, $run);
        }

        $frames = 0;
        $index = 0;
        foreach ($script->steps() as $step) {
            ++$index;

            // 达到帧上限后，之后的输入属于"还没发生"：不派发。
            if ($untilFrame !== null && $frames >= $untilFrame) {
                break;
            }

            if ($step->kind === Step::FRAME) {
                $ms = $session->pump((float) $step->t);
                ++$frames;
                if ($onFrame !== null) {
                    $onFrame($session->lastFrameId(), $ms);
                }
                continue;
            }

            try {
                match ($step->kind) {
                    Step::EFFECT => $session->effect(self::effectName($step), self::effectArgs($step)),
                    default => $session->send(InputScript::unpack($step), $step->t),
                };
            } catch (\Throwable $e) {
                // 带步号重抛：脚本是给人改的，报错必须指到那一行。
                throw new \RuntimeException(
                    "重放第 {$index} 步失败（{$step->describe()}）：" . $e->getMessage(),
                    0,
                    $e,
                );
            }
        }

        return $frames;
    }

    private static function effectName(Step $step): string
    {
        $name = $step->data['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            throw new \InvalidArgumentException('效果步骤缺少 name');
        }

        return $name;
    }

    /** @return array<string, scalar|null> */
    private static function effectArgs(Step $step): array
    {
        $args = $step->data;
        unset($args['name']);

        return $args;
    }
}
