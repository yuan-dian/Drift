<?php
declare(strict_types=1);

namespace Drift\Input;

use Drift\Errors;

/**
 * 应用级**加速键表**（§9.1 路由链第 ① 环：焦点之前）。
 *
 * ```php
 * final class UsersApplication extends Application
 * {
 *     public function accelerators(): AcceleratorTable
 *     {
 *         return AcceleratorTable::make()
 *             ->bind(KeyChord::ctrl('R'), 'users.refresh')
 *             ->bind(KeyChord::ctrl('N'), 'nav.signup')
 *             ->bind(KeyChord::f(5),        'users.refresh');
 *     }
 * }
 * ```
 *
 * ★ **应用级数据，不是平台能力**（§9.1）⇒ 零新端口：`EventLoop` 派发 `KeyEvent`
 *   **之前**查一次表，命中就 `dispatch()` 命令走全链，不再往下走焦点。
 *
 * ★ **两条让位规则**（§9.1）：
 *   ① **IME 组合期不吃快捷键** —— 当前输入层还没有 `ImeEvent`，组合期按键根本不
 *      以 `KeyEvent` 到达 ⇒ 规则天然成立；等 M4 补 IME 接线时在同一点加判据。
 *   ② **编辑命令优先** —— 焦点控件（`TextInputClient`）可 `claimKeys()` 声明独占
 *      键集（`Ctrl+A/C/V/X/Z`），命中时让位给输入框。接线见
 *      `Application::dispatchInput()`：`resolve()` 命中**且**焦点不独占才派发。
 *
 * ★ **冲突即 Fatal**：同一个组合绑两次 ⇒ 响亮失败，不是后绑覆盖前绑
 *   （G2：静默丢一条快捷键 = 用户按了没反应且不报错）。
 *
 * @api
 */
final class AcceleratorTable
{
    /**
     * @var array<string, array{0: KeyChord, 1: string}> 组合键 ⇒ [chord, 命令名]
     *
     * ★ 用字符串键而不是 `KeyChord` 对象键：PHP 数组键不接受对象，而 `spl_object_id`
     *   会混淆"同一个值对象的不同实例"——值对象按值相等（`is()` 全字段比较）。
     */
    private array $bindings = [];

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * 绑一条加速键。**重复绑定同一组合 ⇒ Fatal**（见类 docblock）。
     *
     * @param string $command 命令名（走 §11.4.3 全链：冒泡 → 注册表）
     */
    public function bind(KeyChord $chord, string $command): static
    {
        if ($command === '') {
            Errors::report('AcceleratorTable：命令名不能为空（' . $chord->label() . '）');
        }

        $key = self::keyOf($chord);
        if (isset($this->bindings[$key])) {
            Errors::report(sprintf(
                'AcceleratorTable：组合 %s 被绑了两次（%s 与 %s）—— 冲突必须显式解决，不是后绑覆盖',
                $chord->label(),
                $this->bindings[$key][1],
                $command,
            ));
        }

        $this->bindings[$key] = [$chord, $command];

        return $this;
    }

    /** 查表：命中 ⇒ 命令名；未命中 ⇒ `null`（调用方继续走焦点链） */
    public function resolve(KeyChord $chord): ?string
    {
        return $this->bindings[self::keyOf($chord)][1] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->bindings === [];
    }

    public function count(): int
    {
        return count($this->bindings);
    }

    /**
     * 组合的字符串键（值语义）。
     *
     * ★ 四个字段全进键：`Ctrl+R` 与 `Ctrl+Shift+R` 是两条不同的加速键，
     *   只按 `label()` 拼会在修饰键顺序变化时漏判 —— 而 `label()` 是给人看的。
     */
    private static function keyOf(KeyChord $chord): string
    {
        return sprintf(
            '%s|%d|%d|%d',
            $chord->key,
            $chord->ctrl ? 1 : 0,
            $chord->alt ? 1 : 0,
            $chord->shift ? 1 : 0,
        );
    }
}
