<?php
declare(strict_types=1);

namespace Drift\DevTools\Host;

use Drift\Debug\Session\InputScript;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;
use Drift\Geometry\Axis;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;

/**
 * 命令行动作 ⇒ 一份**驱动脚本**。
 *
 * ## 为什么先成脚本再跑，而不是边跑边记
 *
 * 边跑边记时，`--key` / `--click` 是"当场派发一条事件"，于是"跑一次"这件事只有
 * 一个执行器能做（本地）。而远端执行器要的是"先给我整份脚本，我来推"——
 * 两条驱动路径就此分叉，`--at` 的边界、动作后的 `--hold`、时间戳的递进规则
 * 都得写两遍。写成脚本后，执行器只负责"照着脚本推"，推的规则只有
 * {@see \Drift\DevTools\App\Replay} 一份。
 *
 * `--save` 因此也变成"把已经定下来的东西写出去"，而不是"把刚才发生过的事抄一遍"。
 *
 * ## 时间戳规则（与手感实测对齐）
 *
 * 每帧 +16ms；动作之后的第一帧**与动作同一个时间戳**（同一次输入在同一帧里被
 * settle 消费，跳一个 16ms 会把"输入"和"消费"拆成两帧），后续帧再 +16。
 * `--hold` 因此是"动作之后再推几帧"，而不是"等几毫秒"。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Plan
{
    public const float FRAME_MS = 16.0;

    /**
     * @param array<string, scalar|null> $meta 环境（`rows` / `page`）
     * @param list<array{0: string, 1: string}> $actions `[种类, 规格]`，按命令行顺序
     */
    public static function build(
        string $label,
        float $width,
        float $height,
        array $meta,
        int $frames,
        int $hold,
        array $actions,
    ): Script {
        $script = new Script($label, $width, $height, $meta);

        $t = 0.0;
        for ($i = 0, $n = \max(1, $frames); $i < $n; ++$i) {
            if ($i > 0) {
                $t += self::FRAME_MS;
            }
            $script->add(Step::frame($t));
        }

        $hold = \max(1, $hold);
        foreach ($actions as [$kind, $spec]) {
            $t += self::FRAME_MS;
            foreach (self::steps($kind, $spec, $t) as $step) {
                $script->add($step);
            }
            for ($i = 0; $i < $hold; ++$i) {
                $t += $i === 0 ? 0.0 : self::FRAME_MS;
                $script->add(Step::frame($t));
            }
        }

        return $script;
    }

    /**
     * 一个命令行动作展开成引擎看到的步骤。
     *
     * "点一下"是命令行语法，引擎看到的是 `down` + `up` 两条 —— 脚本记后者，
     * 换一个驱动（真窗口、测试）录下来的形状因此完全一致。
     *
     * @return list<Step>
     */
    public static function steps(string $kind, string $spec, float $t): array
    {
        return match ($kind) {
            'text' => [InputScript::pack(KeyEvent::character($spec), $t)],
            'key' => [InputScript::pack(KeyEvent::down(self::keyChord($spec)), $t)],
            // 按下 → 抬起一次成型：焦点/选中在前者，激活在后者，中间不推帧
            // （真实用户点击也不可能在两者之间插一帧）。
            'click' => self::click($spec, $t),
            'wheel' => self::wheel($spec, $t),
            // 结算在**帧外**发生：这正是真实异步的形状（后端在两次帧之间回贴），
            // 写入由下一帧的 settle 消费 ⇒ 报告里能看到是"哪一帧"接住的。
            'resolve' => [Step::effect('resolve', [], $t)],
            default => throw new \InvalidArgumentException("未知动作：{$kind}"),
        };
    }

    /** @return list<Step> */
    private static function click(string $spec, float $t): array
    {
        $parts = \explode(',', $spec);
        if (\count($parts) < 2) {
            throw new \InvalidArgumentException("点击坐标要写成 --click=x,y[,键]：{$spec}");
        }
        $x = (float) $parts[0];
        $y = (float) $parts[1];
        $button = isset($parts[2]) ? (int) $parts[2] : 0;

        return [
            InputScript::pack(PointerEvent::down($x, $y, $button), $t),
            InputScript::pack(PointerEvent::up($x, $y, $button), $t),
        ];
    }

    /** `x,y,格[,h]` —— 单位是**格**（`ScrollEvent` 的口径），正 = 往正方向看。 */
    private static function wheel(string $spec, float $t): array
    {
        $parts = \explode(',', $spec);
        if (\count($parts) < 3) {
            throw new \InvalidArgumentException("滚轮要写成 --wheel=x,y,格[,h]：{$spec}");
        }
        $axis = isset($parts[3]) && \str_starts_with(\strtolower($parts[3]), 'h')
            ? Axis::Horizontal
            : Axis::Vertical;

        return [InputScript::pack(ScrollEvent::make((float) $parts[0], (float) $parts[1], (float) $parts[2], $axis), $t)];
    }

    /** `Ctrl+A` / `Shift+Tab` / `Enter` / `a` → 键码；单字符走文本输入。 */
    private static function keyChord(string $spec): KeyChord
    {
        $ctrl = false;
        $alt = false;
        $shift = false;

        while (($pos = \strpos($spec, '+')) !== false) {
            $modifier = \strtolower(\substr($spec, 0, $pos));
            $spec = \substr($spec, $pos + 1);
            match ($modifier) {
                'ctrl' => $ctrl = true,
                'alt' => $alt = true,
                'shift' => $shift = true,
                default => null,
            };
        }

        $named = [
            'tab' => 'Tab', 'enter' => 'Enter', 'esc' => 'Escape', 'escape' => 'Escape',
            'space' => ' ', 'backspace' => 'Backspace', 'delete' => 'Delete', 'del' => 'Delete',
            'left' => 'Left', 'right' => 'Right', 'up' => 'Up', 'down' => 'Down',
            'home' => 'Home', 'end' => 'End', 'pageup' => 'PageUp', 'pagedown' => 'PageDown',
        ];
        $key = $named[\strtolower($spec)] ?? $spec;
        if ($key === '') {
            $key = ' ';
        }
        if (\mb_strlen($key, 'UTF-8') === 1) {
            $key = \mb_strtoupper($key, 'UTF-8'); // 单字符键码统一大写，与 KeyEvent::character 同源
        }

        return KeyChord::make($key, $ctrl, $alt, $shift);
    }
}
