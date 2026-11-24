<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

use Drift\Geometry\Axis;
use Drift\Input\InputEvent;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\PointerPhase;
use Drift\Input\ScrollEvent;

/**
 * 输入事件 ⇄ 脚本步骤。
 *
 * ## 为什么在**派发入口**记，不在这条命令里记
 *
 * `drift debug --click=x,y` 展开成 `down` + `up` 两条事件，"点一下"是命令行语法，
 * 不是引擎看到的东西。脚本记引擎看到的那两条：换一个驱动（真窗口、测试、将来的
 * 录制器）记下来的脚本，形状完全一致，重放路径也只有一条。
 *
 * ## 记**标注前**的事件
 *
 * `Application::dispatchInput()` 会给指针事件标 `clickCount`（`TapTracker` 按时间
 * 算"这是第几次点击"）。如果在标注之后记，重放时同一个 `t` 会被**再标一次**
 * ⇒ 点击次数翻倍，"双击"变"三击"。所以记录点在标注之前，`clickCount` 记的是
 * 引擎收到的原值，重放时由同一条链重新算 —— 算出来的必须一样，这正是判据。
 *
 * ## 认不出的事件明确标记
 *
 * 语义树事件、文件拖放等目前没有往返编码。它们被记成 {@see Step::OPAQUE}：
 * 重放时**报错**并指名类名，而不是跳过。跳过会得到"看起来跑完了"的脚本，
 * 而少掉的那一段恰恰可能就是要复现的那一段。
 *
 * @api
 */
final class InputScript
{
    public static function pack(InputEvent $event, ?float $t): Step
    {
        if ($event instanceof KeyEvent) {
            return new Step(Step::KEY, $t, [
                'chord' => $event->chord->key,
                'ctrl' => $event->chord->ctrl,
                'alt' => $event->chord->alt,
                'shift' => $event->chord->shift,
                'text' => $event->text,
            ]);
        }

        if ($event instanceof PointerEvent) {
            return new Step(Step::POINTER, $t, [
                'phase' => $event->phase->name,
                'x' => $event->x,
                'y' => $event->y,
                'button' => $event->button,
                'ctrl' => $event->ctrl,
                'alt' => $event->alt,
                'shift' => $event->shift,
                'clickCount' => $event->clickCount,
            ]);
        }

        if ($event instanceof ScrollEvent) {
            return new Step(Step::SCROLL, $t, [
                'x' => $event->x,
                'y' => $event->y,
                'delta' => $event->delta,
                'axis' => $event->axis === Axis::Horizontal ? 'h' : 'v',
            ]);
        }

        return Step::opaque($event::class, $t);
    }

    /**
     * @throws \InvalidArgumentException 步骤不是可重放的输入，或字段缺失
     */
    public static function unpack(Step $step): InputEvent
    {
        return match ($step->kind) {
            Step::KEY => self::key($step),
            Step::POINTER => self::pointer($step),
            Step::SCROLL => self::scroll($step),
            Step::OPAQUE => throw new \InvalidArgumentException(
                '这条输入没有往返编码，无法重放：' . (string) ($step->data['class'] ?? '未知类'),
            ),
            default => throw new \InvalidArgumentException("步骤 {$step->kind} 不是输入事件"),
        };
    }

    private static function key(Step $step): KeyEvent
    {
        $text = self::str($step, 'text');
        if ($text !== '') {
            return KeyEvent::character($text);
        }

        return KeyEvent::down(KeyChord::make(
            self::str($step, 'chord'),
            self::bool($step, 'ctrl'),
            self::bool($step, 'alt'),
            self::bool($step, 'shift'),
        ));
    }

    private static function pointer(Step $step): PointerEvent
    {
        $name = self::str($step, 'phase');
        $phase = null;
        foreach (PointerPhase::cases() as $case) {
            if ($case->name === $name) {
                $phase = $case;
                break;
            }
        }
        if ($phase === null) {
            throw new \InvalidArgumentException("指针相位不认识：{$name}");
        }

        $x = self::num($step, 'x');
        $y = self::num($step, 'y');
        $button = (int) self::num($step, 'button');
        $ctrl = self::bool($step, 'ctrl');
        $alt = self::bool($step, 'alt');
        $shift = self::bool($step, 'shift');

        $event = match ($phase) {
            PointerPhase::Down => PointerEvent::down($x, $y, $button, $ctrl, $alt, $shift),
            PointerPhase::Move => PointerEvent::move($x, $y, $button, $ctrl, $alt, $shift),
            PointerPhase::Up => PointerEvent::up($x, $y, $button, $ctrl, $alt, $shift),
            PointerPhase::Leave => PointerEvent::leave($x, $y),
        };

        return $event->withClickCount((int) self::num($step, 'clickCount'));
    }

    private static function scroll(Step $step): ScrollEvent
    {
        return ScrollEvent::make(
            self::num($step, 'x'),
            self::num($step, 'y'),
            self::num($step, 'delta'),
            self::str($step, 'axis') === 'h' ? Axis::Horizontal : Axis::Vertical,
        );
    }

    private static function num(Step $step, string $key): float
    {
        $value = $step->data[$key] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            throw new \InvalidArgumentException("步骤 {$step->kind} 缺少数字字段 {$key}");
        }

        return (float) $value;
    }

    private static function str(Step $step, string $key): string
    {
        $value = $step->data[$key] ?? null;
        if (!\is_string($value)) {
            throw new \InvalidArgumentException("步骤 {$step->kind} 缺少字符串字段 {$key}");
        }

        return $value;
    }

    private static function bool(Step $step, string $key): bool
    {
        return (bool) ($step->data[$key] ?? false);
    }
}
