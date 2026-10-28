<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Smoke\App\TableCellEditApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\Rect;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextField;
use Drift\Widget\Element;
use Drift\Widget\InlineEditorBox;
use Drift\Widget\TableState;
use Drift\Widget\Text;

/**
 * 一次编辑会话的驱动：建夹具 + "按键后必推帧" + 树查询。
 *
 * ★ 为什么按键与推帧要绑在一起：不推帧读到的是**上一帧**的状态（编辑态、编辑盒
 *   都要等帧末才反映到树上）。漏了不报错，只表现为"断言到了旧值"—— 这是这一族
 *   测试最容易假绿的地方，而且从用例代码上看不出来。
 * ★ 帧号自增：手写的 17.0 / 18.0 本身不是判据，抄漏一行就全盘错位。
 * ★ 用值对象而不是四元组：`[$app, $root, $state, $focus] = ...` 的顺序错了不报错，
 *   只会在解构出一堆 `null` 之后才崩 —— 而这正是要防的那类失败。
 */
final class EditSession
{
    private function __construct(
        public readonly TableCellEditApplication $app,
        public readonly Element $root,
        public readonly ?TableState $state,
        private float $clock,
    ) {
    }

    /**
     * 新会话：焦点已 Tab 进表、首帧已推。
     *
     * @param list<array{id: int, name: string}>|null $rows 换一份行数据（缺省用夹具
     *        的三行单字）；★ 必须在 `attach()` **之前**换 —— 之后的换法要走
     *        `store->setRows()`，那会顺带触发一次重建，首帧口径就说不清了。
     */
    public static function open(?array $rows = null): self
    {
        $app = new TableCellEditApplication();
        if ($rows !== null) {
            $app->store->setRows($rows);
        }
        $app->attach(new SurfaceProbe(), Fixtures::tight());
        $app->focus()?->focusNext();
        $app->frame(16.0, Fixtures::tight());

        return new self($app, $app->root(), Fixtures::stateOf($app->root()), 16.0);
    }

    /** 推一帧（时间必须递增：同一时间戳重复推会被当成同一帧跳过 settle） */
    public function tick(): void
    {
        $this->clock += 1.0;
        $this->app->frame($this->clock, Fixtures::tight());
    }

    /** 按键 + 推帧；返回"键有没有被吃掉" */
    public function press(KeyEvent $event): bool
    {
        $ate = $this->app->focus()?->dispatchKey($event) ?? false;
        $this->tick();

        return $ate;
    }

    /** 按下一个键 + 推帧；返回"键有没有被吃掉" */
    public function chord(KeyChord $chord): bool
    {
        return $this->press(KeyEvent::down($chord));
    }

    /** 键入一个字符 + 推帧 */
    public function type(string $char): void
    {
        $this->press(KeyEvent::character($char));
    }

    /**
     * 一次点击（按下 + 抬起）+ 推帧。
     *
     * ★ `$at` 是**按下**的时间 —— 连击判据只看按下（抬起只用来收口拖动）。
     * ★ 连击是**跳着给时间**造出来的：`TapTracker` 的窗口 500ms，两次 `$at` 相差
     *   超过它就成了新的一次单击。想造双击就挨着给（1000/1100），想造"慢双击"
     *   就隔开给（1000/1600）—— 两者的区别只有这一个数字，别写成注释里的假设。
     */
    public function tap(float $x, float $y, float $at): void
    {
        $this->app->dispatchInput(PointerEvent::down($x, $y), $at);
        $this->app->dispatchInput(PointerEvent::up($x, $y), $at + 20.0);
        // 点完要推帧才看得到布局结果（`caretRect()` / `selectionRect()` 取的是
        // 盒的**当前**位置，而盒在帧末才换）
        $this->tick();
    }

    /** 编辑盒（`null` = 当前没有编辑格在画） */
    public function editBox(): ?RenderTextField
    {
        $box = $this->root->findBox(
            static fn(RenderBox $candidate): bool => $candidate instanceof RenderTextField,
        );

        return $box instanceof RenderTextField ? $box : null;
    }

    /** 编辑盒的左缘根坐标（`null` = 没在画编辑格） */
    public function editBoxX(): ?float
    {
        $box = $this->editBox();

        return $box === null ? null : RenderBox::absoluteOrigin($box)[0];
    }

    /** 编辑 Widget（拿草稿与插入符；`null` = 没在画编辑格） */
    public function editWidget(): ?InlineEditorBox
    {
        $element = findElement(
            $this->root,
            static fn(Element $e): bool => $e->widget() instanceof InlineEditorBox,
        );
        $widget = $element?->widget();

        return $widget instanceof InlineEditorBox ? $widget : null;
    }

    /** 树上有没有这串文字（判"格子画的是新值"用） */
    public function hasText(string $content): bool
    {
        return findElement(
            $this->root,
            static fn(Element $e): bool => $e->widget() instanceof Text
                && $e->widget()->content() === $content,
        ) !== null;
    }

    public function isEditing(): bool
    {
        return $this->state?->isEditing() ?? false;
    }

    /** @return array{row: int, col: int}|null */
    public function focusedCell(): ?array
    {
        return $this->state?->focusedCell();
    }

    /** @return array{row: int, col: int}|null */
    public function editingCell(): ?array
    {
        return $this->state?->editingCell();
    }

    public function caret(): ?Rect
    {
        return $this->state?->caretRect();
    }
}
