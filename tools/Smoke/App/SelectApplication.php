<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\App\OverlayEntry;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Option;
use Drift\Widget\Select;
use Drift\Widget\Widget;

/**
 * Select 夹具（W8-2）。
 *
 * 主树 = 一个 `Select`（三个选项）+ 一个 host 按钮（占位 + 验"没声明浮层的按钮
 * 仍走 `onPressed`"）+ 一个 **30 项的长列表 `Select`**（验 `PopupPanel::maxHeight()`
 * 的"超高才滚、够短就贴着内容"）。
 *
 * ★ 判据全部走**真实激活链**（`dispatchInput(PointerEvent::down(...))` ⇒
 *   `activateFocusedWidget()`），不直调私有方法 —— 否则测的是"我以为的路径"。
 * ★ 坐标一律 `effectiveBox()` + `absoluteOrigin()` 现取（不赌字模、不赌固定布局）。
 *
 * @internal 测试夹具
 */
final class SelectApplication extends Application
{
    /** @var list<string> `onChanged` 收到的值（顺序 = 触发顺序） */
    public array $changed = [];

    /** @var list<string> host 按钮的回调记录 */
    public array $hostPressed = [];

    /** 受控值（改它 + 重建根才能看到触发框换字；面板高亮读的是**打开那一刻**的值） */
    public string $value = '310000';

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Select::make([
                Option::make('北京市', '110000'),
                Option::make('上海市', '310000'),
                Option::make('广州市', '440100'),
            ])
                ->value($this->value)
                ->placeholder('请选择城市')
                ->onChanged(function (string $value): void {
                    $this->changed[] = $value;
                }),
            Button::make('host')->onPressed(function (): void {
                $this->hostPressed[] = 'host';
            }),
            // ★ 第三个孩子：30 项 ⇒ 自然高远超 260，必然触发"限高 + 滚动"分支。
            //   放在 host **之后**，`findButtonN()` 的下标口径才不变（#0 短列表触发框、
            //   #1 host、#2 长列表触发框）。
            Select::make(self::longOptions())
                ->value('v15')
                ->placeholder('请选择编号')
                ->onChanged(function (string $value): void {
                    $this->changed[] = $value;
                }),
        ]);
    }

    /** @return list<Option> 30 个选项（行高由主题定，夹具不猜） */
    private static function longOptions(): array
    {
        $out = [];
        for ($i = 1; $i <= 30; $i++) {
            $out[] = Option::make('选项 ' . sprintf('%02d', $i), 'v' . $i);
        }

        return $out;
    }

    /** 触发按钮（`Select` build 出来的那个 `Button`）的 Element */
    public function trigger(): ?Element
    {
        return self::findButton($this->root());
    }

    /** host 按钮的 Element（跳过触发按钮） */
    public function host(): ?Element
    {
        $seen = 0;

        return self::findButtonN($this->root(), 1, $seen);
    }

    /** 长列表（30 项）那个 `Select` 的触发按钮 Element（主树第 3 个按钮） */
    public function longTrigger(): ?Element
    {
        $seen = 0;

        return self::findButtonN($this->root(), 2, $seen);
    }

    /** 长列表触发按钮的客户区中心（没排过 ⇒ `null`） */
    public function longTriggerCenter(): ?array
    {
        $box = $this->longTrigger()?->effectiveBox();
        if ($box === null) {
            return null;
        }

        [$x, $y] = self::absoluteOrigin($box);
        $size = $box->size();

        return [$x + ($size?->width ?? 0.0) / 2.0, $y + ($size?->height ?? 0.0) / 2.0];
    }

    /**
     * 栈顶面板里的滚动视口（`PopupPanel::maxHeight()` 的判据要读它的 `maxScroll()`）。
     *
     * ★ 走 `visitChildren()` 而不是手抄盒链：`RenderProxyBox::$child` 是 protected，
     *   而"直接孩子"这个假设一旦被"面板里再套一层"打破就会静默取错。
     */
    public function panelScrollView(): ?RenderScrollView
    {
        $box = $this->overlays()->top()?->element?->effectiveBox();
        if ($box === null) {
            return null;
        }

        return self::findScrollView($box);
    }

    /** 浮层栈层数（`0` = 没有面板） */
    public function overlayCount(): int
    {
        return $this->overlays()->count();
    }

    /** 栈顶条目（读参数 / 落点用） */
    public function topEntry(): ?OverlayEntry
    {
        return $this->overlays()->top();
    }

    /** 当前焦点 Element（`$focus` 是 protected） */
    public function focusedElement(): ?Element
    {
        return $this->focus?->focused();
    }

    /** 浮层面板里的全部选项按钮（按视觉顺序） */
    public function optionButtons(): array
    {
        $out = [];
        self::collectButtons($this->overlays()->top()?->element, $out);

        return $out;
    }

    /** 面板里第 `$i` 个选项按钮的客户区中心（没排过 ⇒ `null`） */
    public function optionCenter(int $i): ?array
    {
        $buttons = $this->optionButtons();
        $element = $buttons[$i] ?? null;
        $box = $element?->effectiveBox();
        if ($box === null) {
            return null;
        }

        [$x, $y] = self::absoluteOrigin($box);
        $size = $box->size();

        return [$x + ($size?->width ?? 0.0) / 2.0, $y + ($size?->height ?? 0.0) / 2.0];
    }

    /** 触发按钮的客户区中心（没排过 ⇒ `null`） */
    public function triggerCenter(): ?array
    {
        $box = $this->trigger()?->effectiveBox();
        if ($box === null) {
            return null;
        }

        [$x, $y] = self::absoluteOrigin($box);
        $size = $box->size();

        return [$x + ($size?->width ?? 0.0) / 2.0, $y + ($size?->height ?? 0.0) / 2.0];
    }

    // ── 下面三个是夹具自己的小工具（`Element::visitChildren()` 只访问直接子）──

    private static function findButton(?Element $element): ?Element
    {
        if ($element === null) {
            return null;
        }

        if ($element->widget() instanceof Button) {
            return $element;
        }

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            $hit = self::findButton($element->childAt($i));
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /** 第 `$want` 个按钮（0 起） */
    private static function findButtonN(?Element $element, int $want, int &$seen): ?Element
    {
        if ($element === null) {
            return null;
        }

        if ($element->widget() instanceof Button) {
            if ($seen === $want) {
                return $element;
            }
            ++$seen;
        }

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            $hit = self::findButtonN($element->childAt($i), $want, $seen);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /** @param list<Element> $out */
    private static function collectButtons(?Element $element, array &$out): void
    {
        if ($element === null) {
            return;
        }

        if ($element->widget() instanceof Button) {
            $out[] = $element;

            return;   // 按钮没有子按钮，收一层就够
        }

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            self::collectButtons($element->childAt($i), $out);
        }
    }

    /** 子树里第一个滚动视口（没有 ⇒ `null`） */
    private static function findScrollView(RenderBox $box): ?RenderScrollView
    {
        if ($box instanceof RenderScrollView) {
            return $box;
        }

        $kids = [];
        $box->visitChildren(static function ($child) use (&$kids): void {
            $kids[] = $child;
        });

        foreach ($kids as $kid) {
            if (!$kid instanceof RenderBox) {
                continue;
            }

            $hit = self::findScrollView($kid);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * 盒的客户区原点。
     *
     * ★ **必须走 `RenderBox::absoluteOrigin()`**，不能手抄父链累加：子盒 offset 住
     *   「内容盒」坐标（padding 位移不进 `setOffset`），手写累加会差一个 padding
     *   而且**不报错**（`MEMORY.md` 里最贵的那条不变量）。
     */
    private static function absoluteOrigin(\Drift\Layout\RenderBox $box): array
    {
        return \Drift\Layout\RenderBox::absoluteOrigin($box);
    }
}
