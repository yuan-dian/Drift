<?php
declare(strict_types=1);

namespace App\Tutorial;

use Drift\App\Application;
use Drift\Widget\Widget;

/**
 * 第 3 个文件：应用本体。
 *
 * ★ **应用只写三样**（§4.5）：`buildRoot()` / `title()` / `commands()`。
 *   平台名**不出现在这里** —— 那是 `bin/win32/Bootstrap.php` 的职责。
 *
 * ★ 三个覆写是 `public` 不是 `protected`：基类把它们定义成 public，
 *   子类收窄可见性 ⇒ PHP Fatal。
 *
 * ★★★ **覆写了 `__construct()` 就必须调 `parent::__construct()`**
 *   —— 基类在构造器里建七个拆分片（router / textEditing / scrollInput /
 *   overlayHost / input / overlayLayout / pumps）。漏掉 ⇒ 那七个字段全是未初始化，
 *   真机 `attach()` 第一句就崩，而**错误信息只有一句
 *   `Attempt to read property on null`，没有文件名没有行号**（AOT 把未初始化属性
 *   读成 `null`，解释器本该报 `must not be accessed before initialization`）。
 *   闸门：`tools/Lint/Rules/AppSubclassCtorParent.php`。
 */
final class TutorialApplication extends Application
{
    /**
     * 能力接线：递**门面**，不递闭包、也不递 `$this`。
     *
     * ★ 控件层够不到 `Application` —— §4.3 禁 `Widget → App`（`$this->application()`
     *   这个方法**不存在**，而 tpc **不校验**方法存在性 ⇒ 写错了照样编译通过、
     *   smoke 也全绿，真机一点就崩）。所以能力只能由唯一持有 `$this` 的地方递下去。
     * ★ 门面上没有 `frame()` / `attach()` / `input`：页面拿到的是一组能力，
     *   不是整个应用。边界没有放松。
     */
    public function buildRoot(): Widget
    {
        return TutorialPage::make($this->environment());
    }

    public function title(): string
    {
        return 'Drift 教程';
    }
}
