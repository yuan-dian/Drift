<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Input\CursorShape;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * 「按下归我」与「键盘归我」两条缝的夹具（W8-2，2026-10-23）。
 *
 * 四个区域，各验一件事：
 * | 区域 | 声明 | 判据 |
 * |---|---|---|
 * | 接管 | `pointer($h, ownsPress: true)` | down 时回调收到 `down`，**命令不发** |
 * | 旁观 | `pointer($h)`（默认 false）| down 时**命令照发**，回调只在捕获期跑（零回归）|
 * | 祖先 | `Column->pointer($h, ownsPress: true)` 包一个子按钮 | 点子按钮 ⇒ 按下归**祖先** |
 * | 键盘 | `keyboard($h)` | 焦点在它上面时方向键归它；返回 false 则让位 |
 *
 * ★ **为什么要有"旁观"这一栏**：`pointer()` 在 W8-2 之前就有消费者
 *   （`OverlayLab` 的锚点按钮），语义扩成"含按下"会静默改掉它的行为。
 *   `ownsPress` 默认 false 就是为了让那一栏**逐字节不变** —— 判据必须钉住它。
 * ★ 坐标一律 `effectiveBox()` + `absoluteOrigin()` 现取（不赌字模高度）。
 *
 * @internal 测试夹具
 */
final class PressLabApplication extends Application
{
    /** 接管栏收到的相位序列（`down` / `move` / `up` / `leave`） */
    public array $ownLog = [];

    /** 旁观栏收到的相位序列 */
    public array $watchLog = [];

    /** 祖先栏收到的相位序列 */
    public array $ancestorLog = [];

    /** 「接管」按钮被激活的次数 —— ownsPress 下**必须恒为 0** */
    public int $ownPresses = 0;

    /** 「旁观」按钮被激活的次数 —— 默认声明下**必须随 down 增长** */
    public int $watchPresses = 0;

    /** 「祖先」子按钮被激活的次数 */
    public int $childPresses = 0;

    /** 键盘栏收到的键名序列 */
    public array $keyLog = [];

    /** 键盘栏回调的返回值（夹具可切，验"返回 false 就让位"） */
    public bool $keyHandled = true;

    /** 「键盘」按钮被激活的次数（方向键不该触发它） */
    public int $keyPresses = 0;

    /**
     * 当前焦点 Element（`$focus` 是 protected ⇒ 夹具开一个只读口子）。
     *
     * ★ 判据要验"接管按下之后焦点**仍落上去**"—— 那是键盘可达的前提，
     *   而 `ownsPress` 跳过的只是 `activateFocusedWidget()`，不是 `focusHit()`。
     */
    public function focusedElement(): ?Element
    {
        return $this->focus?->focused();
    }

    public function buildRoot(): Widget
    {        return Column::make()->children([
            Button::make('接管')
                ->onPressed(function (): void {
                    $this->ownPresses++;
                })
                ->pointer(function (PointerEvent $event, Element $self): ?CursorShape {
                    $this->ownLog[] = self::phaseName($event);

                    return null;
                }, ownsPress: true),

            Button::make('旁观')
                ->onPressed(function (): void {
                    $this->watchPresses++;
                })
                ->pointer(function (PointerEvent $event, Element $self): ?CursorShape {
                    $this->watchLog[] = self::phaseName($event);

                    return null;
                }),

            // 祖先接管：点子按钮 ⇒ 按下归 Column（子没声明，祖先声明了）
            Column::make()
                ->pointer(function (PointerEvent $event, Element $self): ?CursorShape {
                    $this->ancestorLog[] = self::phaseName($event);

                    return null;
                }, ownsPress: true)
                ->children([
                    Button::make('子按钮')
                        ->onPressed(function (): void {
                            $this->childPresses++;
                        }),
                ]),

            Button::make('键盘')
                ->onPressed(function (): void {
                    $this->keyPresses++;
                })
                ->keyboard(function (KeyEvent $event, Element $self): bool {
                    $this->keyLog[] = $event->chord->key;

                    return $this->keyHandled;
                }),
        ]);
    }

    private static function phaseName(PointerEvent $event): string
    {
        if ($event->isDown()) {
            return 'down';
        }

        if ($event->isMove()) {
            return 'move';
        }

        if ($event->isUp()) {
            return 'up';
        }

        return 'leave';
    }
}
