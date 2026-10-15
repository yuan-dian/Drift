<?php
declare(strict_types=1);

namespace Drift\Dev\Preview;

use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Padding;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * @internal 表单页 —— `TextField` 的**契约示例**（§20 纪律 4：没进示例 = 没交付）。
 *
 * 这就是 §A.2.9 旗舰表单的**可运行子集**：把那页今天仍为 0 文件的 `Spinner` ·
 * `CursorShape`（M2）· `Widget\Drag\Draggable`（M3）拿掉之后，剩下的
 * `Padding` + `Column` + `Text` + `TextField` **今天就能编译、能预览**。
 *
 * ```bash
 * php tools/drift-preview.php --page=tools/Preview/FormPage.php --width=360 --height=200
 * ```
 *
 * ★ 它同时演示 `TextField` 的两条显示路径：
 *   · 给了 `value()` ⇒ 画真实文本（"张伟"）
 *   · 没给 ⇒ 画占位符（"name@example.com"），且**高度不塌成 0**
 *   ★ 编辑态（插入符）**不在此演示**：它要 `FocusNode` + `KeyEvent` 才有人驱动，
 *     属输入层（M3）；今天由 `tools/smoke.php` 直接驱动 `TextFieldState` 覆盖。
 */
final class FormPage extends StatelessPage
{
    public function build(BuildContext $context): Widget
    {
        return Padding::make(
            Column::make()->children([
                Text::make('注册')->title(),
                TextField::make()->label('姓名')->placeholder('请输入姓名')->value('张伟'),
                TextField::make()->label('邮箱')->placeholder('name@example.com'),
            ]),
        )->all(16.0);
    }
}
