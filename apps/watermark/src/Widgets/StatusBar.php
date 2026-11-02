<?php
declare(strict_types=1);

namespace App\Watermark\Widgets;

use App\Watermark\Store\StatusMessage;
use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\State\Subject;
use Drift\State\SubscribingWidget;
use Drift\Widget\BuildContext;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 底部状态栏：**专职订阅者** —— 只订阅 `WatermarkStore::statusLine()`。
 *
 * ★ 形状照 `apps/users/src/Widgets/StatusBar.php`（`SubscribingWidget` 是空标记接口，
 *   订阅袋由 `State::watch()` 记、dispose 统一退），差别只有两处：
 *   ① 切片类型是 `StatusMessage` 而不是 `string`（要带 kind 才有那个状态点）；
 *   ② `initState()` 里**先读一次当前值**再订阅 —— `watch()` 不立即回调（见
 *      `State::watch()`），只订阅的话首帧是空的，而 store 的初始值是
 *      `info('准备就绪')`（用户第一眼该看到它）。
 *
 * ★ 源与切片闭包在 `initState()` 里**取一次存字段**，之后回调里只用字段：
 *   `$this->widget()` 的静态类型是 `Widget`（不是 `StatusBar`）⇒ 每次回调都
 *   `$this->widget()->getSelect()` 是在一个"没有这个方法的类型"上调用 ——
 *   AOT 不校验方法存在（见 `MEMORY`：build 成功、真机崩）。取一次就没有这个面。
 */
final class StatusBar extends StatefulWidget implements SubscribingWidget
{
    private ?Subject $source = null;

    /** @var null|callable(Subject): mixed */
    private $select = null;

    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function source(Subject $s): self
    {
        $this->mutate();
        $this->source = $s;

        return $this;
    }

    /** @param callable(Subject): mixed $f */
    public function select(callable $f): self
    {
        $this->mutate();
        $this->select = $f;

        return $this;
    }

    public function createState(): State
    {
        return new StatusBarState();
    }

    public function getSource(): ?Subject
    {
        return $this->source;
    }

    public function getSelect(): ?callable
    {
        return $this->select;
    }
}

final class StatusBarState extends State
{
    private StatusMessage $message;

    private ?Subject $source = null;

    /** @var null|callable(Subject): mixed */
    private $select = null;

    public function initState(): void
    {
        $this->message = StatusMessage::none();

        // ★ `instanceof` 后再调 —— 框架自己的 `DockElement::expand()` 就是这个形状
        //   （AOT 下被编译验证过的窄化写法）。
        $w = $this->widget();
        if (!$w instanceof StatusBar) {
            return;
        }

        $source = $w->getSource();
        $select = $w->getSelect();
        if ($source === null || $select === null) {
            return;   // 没接源 ⇒ 静态空栏（不崩、不订阅）
        }

        $this->source = $source;
        $this->select = $select;

        // ★ 首帧就要有值（`watch()` 不立即回调）。
        $this->message = self::slice($select, $source);

        $this->watch($source, function (): void {
            $source = $this->source;
            $select = $this->select;
            if ($source === null || $select === null) {
                return;
            }

            $next = self::slice($select, $source);
            if ($next->equals($this->message)) {
                return;   // ★ 相等门控：内容没换就不重建这一行
            }

            $this->setState(function () use ($next): void {
                $this->message = $next;
            });
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Row::make()
            ->spacing(8)
            ->padding(EdgeInsets::symmetric(16.0, 9.0))
            ->background(Palette::listRow())
            ->children([
                // ★ 状态点：`SizedBox` 自带 `background()` + `radius()`（`ImageThumb`
                //   里同一个用法）—— 不必为一个 8px 的圆点引入新控件。
                SizedBox::make()
                    ->size(8.0, 8.0)
                    ->background(self::dotColor($this->message->kind))
                    ->radius(4.0),

                Text::make($this->message->text)->caption(),
                Spacer::make(),
            ]);
    }

    /**
     * 从源上切一片（`mixed` → `StatusMessage`）。
     *
     * ★ 切片的静态返回类型是 `mixed`（`Subject` 的订阅回调不带类型）⇒ 这里做**唯一
     *   一次**收窄，三处调用点（首帧 + 每次通知）共用。类型不对时退回空消息而不是
     *   抛：状态栏不该因为一个写错的切片函数把整帧打掉。
     *
     * @param callable(Subject): mixed $select
     */
    private static function slice(callable $select, Subject $source): StatusMessage
    {
        $value = $select($source);

        return $value instanceof StatusMessage ? $value : StatusMessage::none();
    }

    /**
     * `kind` → 状态点颜色。
     *
     * ★ 未知 kind 走"提示"那一档（不画透明、不崩）：多一种 kind 而忘了配色的症状
     *   应该是"颜色不对"，不是"点没了"。
     */
    private static function dotColor(string $kind): Color
    {
        if ($kind === StatusMessage::SUCCESS) {
            return Palette::successText();
        }
        if ($kind === StatusMessage::ERROR) {
            return Palette::errorText();
        }
        if ($kind === StatusMessage::LOADING) {
            return Palette::loadingText();
        }

        return Palette::hint();
    }
}
