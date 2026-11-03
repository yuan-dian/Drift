<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Commands\CommandArg;
use Drift\Dev\Smoke\App\NavApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Widget\Activatable;
use Drift\Widget\ActivationContext;
use Drift\Widget\AppBar;
use Drift\Widget\Button;
use Drift\Widget\Checkbox;
use Drift\Widget\Element;
use Drift\Widget\Menu\Menu;
use Drift\Widget\NavButton;
use Drift\Widget\Radio;
use Drift\Widget\Segment;
use Drift\Widget\Toggle;

/**
 * 激活语义：**指针出口** + **能力声明** + **覆写/兜底**（§9.1）。
 *
 * ## 与另外三个文件的分工
 *
 * | 文件 | 判的是 |
 * |---|---|
 * | `Feature/App/ShellCommandTest` | 键从 `Dock → AppBar → 按钮` 走到底（App 层端到端） |
 * | 本文件 | 激活**本身**：鼠标按下能不能激活、谁能被激活、覆写与兜底的次序 |
 * | `Feature/Input/KeyRoutingPriorityTest` | 焦点链先于加速键（控件认得的键应用收不到） |
 * | `Feature/Input/PressClaimTest` | 谁能"认领"一次按下（让位规则） |
 *
 * ## 为什么能力声明要用**反射扫源码**
 *
 * 框架侧已经把 4 条 `instanceof` 换成了一次 `instanceof Activatable`（S1 第 10 步）。
 * 代价是**漏 `implements` 的后果完全静默**：框架 `return`，Enter 什么都不做，
 * 而"直调 `activate()`"的用例照样绿（那种调用不经过框架）。
 *
 * 硬编码一张"应该有 6 个"的表只能挡住**已经知道**的那几个 —— 新控件忘了声明照样过。
 * 所以判据改成结构性的：**凡是声明了 `activate()` 的控件，都必须 implements
 * `Activatable`**。扫描本身要自证（看到已知成员 + 覆盖数量），否则"差集为空"恒真。
 */
final class ActivationTest extends SmokeTestCase
{
    private static function open(): NavApplication
    {
        $app = new NavApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        return $app;
    }

    /** @return array{0: float, 1: float}|null 元素盒的中心（客户区绝对坐标）；没盒 ⇒ null */
    private static function centerOf(?Element $element): ?array
    {
        $box = $element?->effectiveBox();
        $size = $box?->size();
        if ($box === null || $size === null) {
            return null;
        }
        [$x, $y] = boxOrigin($box);

        return [$x + $size->width / 2.0, $y + $size->height / 2.0];
    }

    /**
     * 鼠标**按下**即激活（§9.1 动作缝的另一半，与 Enter/Space 同一个 `Activatable` 出口）。
     *
     * ★ 三条缺一不可：
     *   ① 按下列表 ⇒ 命令带 arg（证明"能激活"）；
     *   ② 点空白 ⇒ **不**再派发（挡的是"焦点非空就激活"这种退化实现 —— 它会让
     *      每次点空白都重复触发上一个焦点控件）；
     *   ③ 按下取消 ⇒ `onPressed`（证明出口不是"只走命令系统"）。
     * ★ ② 的落点**必须可证**在按钮之外：条高由 `AppBar` 的盒量出来，落点取条下方
     *   20px，再复核"没有任何 Button 盒包含它"。目测坐标是第 29 批踩过的坑。
     */
    public function testPointerDownActivatesTheHitWidget(): void
    {
        $app = self::open();
        $root = $app->rootElement();

        $navEl = $root === null ? null : findElement(
            $root,
            static fn(Element $e): bool => $e->widget() instanceof NavButton
        );
        $cancelEl = $root === null ? null : findElement(
            $root,
            static fn(Element $e): bool => $e->widget() instanceof Button && !$e->widget() instanceof NavButton
        );

        $navCenter = self::centerOf($navEl);
        check(
            '激活 · 前置：取到「列表」按钮的盒中心（否则下面的派发点没着落）',
            $navCenter !== null,
            $navCenter === null ? '盒为 null' : ''
        );
        if ($navCenter === null) {
            return;
        }

        $app->dispatchInput(PointerEvent::down($navCenter[0], $navCenter[1]));
        check(
            '激活 · 鼠标按下「列表」⇒ 命令带 arg 派发（与 Enter 同入口）',
            $app->recorder()->seen === ['nav.users#7/'],
            \implode(',', $app->recorder()->seen)
        );

        // ② 点空白：落点从条高推出来，再复核"不在任何 Button 盒内"
        $barBox = $root === null ? null : findElement(
            $root,
            static fn(Element $e): bool => $e->widget() instanceof AppBar
        )?->effectiveBox();
        $barH = $barBox?->size()?->height ?? 0.0;
        $emptyX = 160.0;
        $emptyY = $barH + 20.0;

        $hitsButton = false;
        $walk = function (Element $el) use (&$walk, &$hitsButton, $emptyX, $emptyY): void {
            if ($hitsButton) {
                return;
            }
            $box = $el->widget() instanceof Button ? $el->effectiveBox() : null;
            $size = $box?->size();
            if ($box !== null && $size !== null) {
                [$bx, $by] = boxOrigin($box);
                if ($emptyX >= $bx && $emptyX <= $bx + $size->width
                    && $emptyY >= $by && $emptyY <= $by + $size->height) {
                    $hitsButton = true;

                    return;
                }
            }
            $el->visitChildren($walk);
        };
        if ($root !== null) {
            $walk($root);
        }

        check(
            '激活 · 前置：空白落点在条外且不在任何 Button 盒内（否则下面"不误触发"恒真）',
            $barH > 0.0 && $emptyY > $barH && $emptyY < 240.0 && !$hitsButton,
            \sprintf('条高=%.1f 落点=(%.0f,%.1f) 命中按钮=%s', $barH, $emptyX, $emptyY, $hitsButton ? 'yes' : 'no')
        );

        $app->dispatchInput(PointerEvent::down($emptyX, $emptyY));
        check(
            '激活 · 前置：点空白**没有**移走焦点（仍在「列表」上 ⇒ 退化实现必然重复派发）',
            $app->focus()?->focused() === $navEl,
            $app->focus()?->focused() === $navEl ? '' : '焦点被清掉了 —— 下面那条判据失效'
        );
        check(
            '激活 · 点空白（无控件可落）⇒ 不重复触发上一个焦点控件',
            $app->recorder()->seen === ['nav.users#7/'],
            \implode(',', $app->recorder()->seen)
        );

        $cancelCenter = self::centerOf($cancelEl);
        check(
            '激活 · 前置：取到「取消」按钮的盒中心',
            $cancelCenter !== null,
            $cancelCenter === null ? '盒为 null' : ''
        );
        if ($cancelCenter === null) {
            return;
        }

        $app->dispatchInput(PointerEvent::down($cancelCenter[0], $cancelCenter[1]));
        check(
            '激活 · 鼠标按下「取消」⇒ onPressed 被调（纯 UI 回调路径，不是只走命令）',
            $app->pressed === ['cancelled'],
            \implode(',', $app->pressed)
        );
    }

    /**
     * 激活键的**边界**：无焦点不激活、方向键不激活。
     *
     * ★ 两条都是"不该发生"的判据 ⇒ 恒真风险最高：`dispatchInput` 整个坏掉时它们
     *   全绿。所以每条前面都先钉住前提（无焦点 / 焦点确实在按钮上）。
     */
    public function testActivationKeysHaveBoundaries(): void
    {
        $idle = self::open();
        check(
            '激活 · 前置：新应用还没有焦点（否则"无焦点按 Enter"恒真）',
            $idle->focus()?->focused() === null,
            $idle->focus()?->focused() === null ? '' : '已经有焦点了'
        );
        $idle->dispatchInput(KeyEvent::down(KeyChord::enter()));
        check(
            '激活 · 无焦点时 Enter ⇒ 无事发生（不崩、不误派发）',
            $idle->recorder()->seen === [] && $idle->pressed === [],
            'seen=' . \implode(',', $idle->recorder()->seen) . ' pressed=' . \implode(',', $idle->pressed)
        );

        $quiet = self::open();
        $quiet->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $quietNav = $quiet->rootElement() === null ? null : findElement(
            $quiet->rootElement(),
            static fn(Element $e): bool => $e->widget() instanceof NavButton
        );
        check(
            '激活 · 前置：Tab 之后焦点在「列表」按钮上（否则"方向键不激活"恒真）',
            $quietNav !== null && $quiet->focus()?->focused() === $quietNav,
            $quiet->focus()?->focused() === $quietNav ? '' : '焦点不在「列表」上'
        );
        $quiet->dispatchInput(KeyEvent::down(KeyChord::left()));
        check(
            '激活 · 焦点在按钮上按方向键 ⇒ 不激活（只有 Enter/Space）',
            $quiet->recorder()->seen === [] && $quiet->pressed === [],
            'seen=' . \implode(',', $quiet->recorder()->seen) . ' pressed=' . \implode(',', $quiet->pressed)
        );
    }

    /**
     * 能力声明：**凡是声明了 `activate()` 的控件都必须 implements `Activatable`**。
     *
     * ★ 扫描自证三条：① 看到已知成员（`Button` / `Segment`）；② 覆盖数量足够
     *   （`src/Widget` 下 113 个文件，类 ≥ 90 —— 路径写错或正则失效时这条先红）；
     *   ③ 差集为空。少了 ①②，扫描扫出 0 个类也是"差集为空"。
     * ★ 顺带发现：smoke 里那张"四个控件"的表**漏了 `IconButton` / `NavButton` /
     *   `MenuItem` / `Radio` / `Toggle`**（它们由父类或自身声明）。改成扫描之后不必
     *   维护白名单，新控件漏声明会自己红。
     */
    public function testEveryWidgetWithActivateDeclaresTheCapability(): void
    {
        [$withActivate, $classCount] = self::scanWidgetClasses();

        $missing = [];
        foreach ($withActivate as $class) {
            if (!\is_subclass_of($class, Activatable::class)) {
                $missing[] = $class;
            }
        }

        check(
            'Activatable · 前置：扫描真的看到了已知成员（否则"差集为空"恒真）',
            \in_array(Button::class, $withActivate, true)
                && \in_array(Segment::class, $withActivate, true)
                && $classCount >= 90,
            \sprintf('带 activate() 的 %d 个 / 扫到 %d 个类', \count($withActivate), $classCount)
        );
        check(
            'Activatable · 声明了 activate() 的控件都 implements Activatable（漏一个 ⇒ 框架激活静默失效）',
            $missing === [],
            $missing === [] ? '' : '缺：' . \implode(', ', $missing)
        );
    }

    /**
     * `Menu` 覆写 `activate()` ⇒ **只**走 `onOpen`，不落 `Button` 的命令。
     *
     * ★★ 判据必须**两边都记**（同一个数组里）：只记 `onOpen` 的话，"命令也被派发了"
     *   这件事看不出来 —— 实测第一版就是这么写的，把 `Menu::activate()` 改成
     *   `parent::activate()` 之后照样绿。
     */
    public function testMenuOverridesActivateToOnlyOpen(): void
    {
        $saw = [];
        $menu = Menu::make('文件')
            ->command('menu.should.not.fire')
            ->onOpen(static function (Menu $m, ?Element $anchor) use (&$saw): void {
                $saw[] = $anchor === null ? 'open:no-anchor' : 'open:anchor';
            });
        $menu->activate(new ActivationContext(null, static function (string $command, CommandArg $arg) use (&$saw): void {
            $saw[] = 'cmd:' . $command;
        }));

        check(
            'Activatable · Menu::activate ⇒ 只走 onOpen（Button 的命令不被派发）',
            $saw === ['open:no-anchor'],
            \implode(',', $saw)
        );
    }

    /**
     * `Button` 的次序：**命令优先**，没有命令才回落 `onPressed`。
     *
     * ★ 两条成对：只验"有命令 ⇒ 命令"会奖励"`onPressed` 从此不触发"（纯 UI 按钮全哑）；
     *   只验"无命令 ⇒ 回调"会奖励"命令系统没接上"（导航按钮点了没反应，且不报错）。
     */
    public function testButtonPrefersCommandAndFallsBackToOnPressed(): void
    {
        $saw = [];
        $withCommand = Button::make('b')
            ->command('cmd.a')
            ->onPressed(static function () use (&$saw): void {
                $saw[] = 'pressed';
            });
        $withCommand->activate(new ActivationContext(null, static function (string $command, CommandArg $arg) use (&$saw): void {
            $saw[] = 'cmd:' . $command;
        }));
        check(
            'Activatable · Button::activate ⇒ 命令优先，onPressed 不调',
            $saw === ['cmd:cmd.a'],
            \implode(',', $saw)
        );

        $plain = [];
        Button::make('b')->onPressed(static function () use (&$plain): void {
            $plain[] = 'pressed';
        })->activate(ActivationContext::none());
        check(
            'Activatable · 无命令的 Button::activate ⇒ 回落 onPressed（上下文可以什么都不给）',
            $plain === ['pressed'],
            \implode(',', $plain)
        );
    }

    /**
     * `ToggleBase` 家族：激活 = 换值，且**按 `kind` 分支**（单选钮给的是自己的值，不是布尔）。
     *
     * ★ 三条覆盖三种分支：开关（布尔翻转）/ 单选钮未选（给自己的值）/ 单选钮已选（不重复通知）。
     *   只验开关会漏掉 `KIND_RADIO` 那条分支 —— 它给的是 `radioValue` 而不是 `!$on`。
     */
    public function testToggleFamilyActivateChangesValue(): void
    {
        $checkboxSaw = [];
        Checkbox::make('c')->checked(false)->onChanged(static function (bool $on) use (&$checkboxSaw): void {
            $checkboxSaw[] = $on ? 'on' : 'off';
        })->activate(ActivationContext::none());
        check(
            'Activatable · Checkbox::activate ⇒ 换值（false ⇒ true）',
            $checkboxSaw === ['on'],
            \implode(',', $checkboxSaw)
        );

        $radioSaw = [];
        Radio::make('r')->value('v2')->checked(false)->onChanged(static function (string $value) use (&$radioSaw): void {
            $radioSaw[] = $value;
        })->activate(ActivationContext::none());
        check(
            'Activatable · Radio::activate ⇒ 给自己的值 v2（不是布尔）',
            $radioSaw === ['v2'],
            \implode(',', $radioSaw)
        );

        $alreadySaw = [];
        Radio::make('r')->value('v2')->checked(true)->onChanged(static function (string $value) use (&$alreadySaw): void {
            $alreadySaw[] = $value;
        })->activate(ActivationContext::none());
        check(
            'Activatable · 已选中的 Radio::activate ⇒ 不再通知（重复点不该再派发）',
            $alreadySaw === [],
            \implode(',', $alreadySaw)
        );
    }

    /**
     * 扫 `src/Widget` 下所有类，返回 [声明了 `activate()` 的类, 扫到的类总数]。
     *
     * ★ 用源码正则找 `namespace` + `class`，再 `class_exists` 走自动加载 —— 比
     *   `get_declared_classes()` 可靠：后者只包含**已经被加载过**的类，取决于执行顺序。
     * ★ 抽象类（`ToggleBase`）与 final 类一视同仁：`method_exists` 对两者都成立。
     *
     * @return array{0: list<class-string>, 1: int}
     */
    private static function scanWidgetClasses(): array
    {
        $dir = repoRoot() . '/src/Widget';
        $withActivate = [];
        $classCount = 0;

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) \file_get_contents($file->getPathname());
            if (\preg_match('/^namespace\s+([^;]+);/m', $src, $ns) !== 1) {
                continue;
            }
            if (\preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cls) !== 1) {
                continue;
            }
            $fqn = $ns[1] . '\\' . $cls[1];
            if (!\class_exists($fqn)) {
                continue;
            }
            $classCount++;
            if (\method_exists($fqn, 'activate')) {
                $withActivate[] = $fqn;
            }
        }

        return [$withActivate, $classCount];
    }
}
