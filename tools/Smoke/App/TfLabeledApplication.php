<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Column;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * IME 定位夹具：**带 label** 的文本字段。
 *
 * ★ 存在理由（2026-10-19 真机 bug 的回归夹具）：`TextField` 有 label 时
 *   `TextFieldState::build()` 返回 `Column[Text(标签), 字段盒]` —— 焦点元素的
 *   `effectiveBox()` 于是是 **`Column` 的盒**。若 `caretRect()` 用它判
 *   `instanceof RenderTextField` ⇒ 恒 `null` ⇒ 壳收到"无插入符" ⇒
 *   **候选框贴屏幕左上角**（用户实测：showcase 表单里带 label 的手机号/验证码
 *   错、不带 label 的姓名/邮箱对；users 搜索框带 label ⇒ 全错）。
 *
 * `TfApplication` 的两个字段都没有 label ⇒ 过去一直绿，掩盖了这个 bug。
 *
 * @internal 测试夹具
 */
final class TfLabeledApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->paddingAll(24.0)->children([
            TextField::make()->label('邮箱')->value('a@b.c'),
        ]);
    }
}
