<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Input\KeyEvent;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;
use Drift\Widget\Widget;

/**
 * `keyboard()` 缝的**祖先链**夹具（2026-10-24）。
 *
 * 存在理由：`FocusManager::dispatchKey()` 原先只读**焦点控件自己**的 widget
 * ⇒ 包裹式控件（`ComboBox` 包 `TextField` 那种）声明的 `keyboard()` 是**死的**，
 * 而 `Widget::keyboard()` 的 docblock 一直写着"任何控件都能挂"。
 * 修法是沿父链找"最近的声明者"（与 `pointer()` / `contextMenu()` 同构）。
 *
 * 本夹具给的就是那个最小形状：**外层 `Column` 声明 `keyboard()`，里面放一个
 * 可聚焦的 `TextField`** —— 焦点落在 `TextField` 上，键必须先过外层那一关。
 *
 * @internal 测试夹具
 */
final class KeyAncestorApplication extends Application
{
    /** @var list<string> 外层（祖先）`keyboard()` 收到的键名 */
    public array $ancestorLog = [];

    /** 外层 handler 是否吃掉（`false` = 让位给下游 `TextFieldState`） */
    public bool $ancestorEats = true;

    public function buildRoot(): Widget
    {
        return Column::make()->paddingAll(24.0)->spacing(8.0)->children([
            // ★ 声明在**外层**：焦点会落在里面的 TextField 上，这层自己永远不是焦点控件
            TextField::make()->value('ab'),
        ])->keyboard(function (KeyEvent $event, Element $self): bool {
            $this->ancestorLog[] = $event->chord->key;

            return $this->ancestorEats;
        });
    }

    /** 第一个 `TextField` 的 Element（点它拿焦点） */
    public function fieldElement(): ?Element
    {
        return self::findField($this->root);
    }

    /**
     * 那个 `TextField` 的 `TextFieldState`（判据要读它的 `text()`：
     * 外层 `keyboard()` 吃掉 Backspace ⇒ 文本不变；让位 ⇒ 文本被删一个字符）。
     */
    public function fieldState(): ?TextFieldState
    {
        $el = $this->fieldElement();
        if (!$el instanceof StatefulElement) {
            return null;
        }
        $state = $el->state();

        return $state instanceof TextFieldState ? $state : null;
    }

    private static function findField(?Element $element): ?Element
    {
        if ($element === null) {
            return null;
        }
        if ($element->widget() instanceof TextField) {
            return $element;
        }
        $hit = null;
        $element->visitChildren(static function ($child) use (&$hit): void {
            if ($hit === null && $child instanceof Element) {
                $hit = self::findField($child);
            }
        });

        return $hit;
    }
}
