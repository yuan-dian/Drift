<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Snackbar;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * Snackbar 夹具（W8-2）。
 *
 * 主树只有一个按钮（占位，让客户区有内容）；判据全部走 `push()` 这个直入口 ——
 * 通知的触发是**命令式**的（`showSnackbar()`），不需要点击链参与。
 *
 * ★ 坐标一律 `effectiveBox()` + `absoluteOrigin()` 现取（不赌字模）。
 * ★ `push()` 收的是**已组好的 Snackbar**（`duration` / `action` 都在调用点写），
 *   这样每个用例的意图在一行里看得见。
 *
 * @internal 测试夹具
 */
final class SnackbarApplication extends Application
{
    /** @var list<string> 动作按钮的回调记录（顺序 = 触发顺序） */
    public array $actions = [];

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Button::make('host')->onPressed(function (): void {
                $this->actions[] = 'host';
            }),
        ]);
    }

    /** 直推一条通知（测试用；`duration=0` = 永不自动消失） */
    public function push(Snackbar $snackbar): void
    {
        $this->showSnackbar($snackbar);
    }

    /** 当前通知里那行文字（不是通知层 ⇒ `null`） */
    public function snackText(): ?string
    {
        $found = self::findText($this->snackbarEntry()?->element);
        if (!$found instanceof Element) {
            return null;
        }

        $widget = $found->widget();

        return $widget instanceof Text ? $widget->content() : null;
    }

    /**
     * 通知里的动作按钮元素（没有 ⇒ `null`）。
     *
     * ★ 递归找：`Element::visitChildren()` **只访问直接子**，而通知面板是
     *   `PopupPanel → Column → Row → Button` 四层。
     */
    public function actionButton(): ?Element
    {
        return self::findButton($this->snackbarEntry()?->element);
    }

    /** 深度优先找第一个 `Text` 元素（通知面板里第一个 Text 就是正文） */
    private static function findText(?Element $element): ?Element
    {
        if ($element === null) {
            return null;
        }

        if ($element->widget() instanceof Text) {
            return $element;
        }

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            $hit = self::findText($element->childAt($i));
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /** 深度优先找第一个 `Button` 元素 */
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
}
