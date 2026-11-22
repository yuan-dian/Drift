<?php
declare(strict_types=1);

namespace App\Users\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Text\FilteringTextInputFormatter;
use Drift\Widget\Bind;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

use App\Users\Store\SignupForm;

/**
 * 注册页（§A.2.9）：表单（每字段一段 `Bind` + 提交错误回显）。
 *
 * ★ **每字段各订各的格子**（漏了 ⇒ 值变了不刷新 ⇒ 真机可见"打字没回显"）：
 *   字段值与该字段错误在同一个 builder 里读，错误不多占一条订阅。
 *   ⇒ 改邮箱不会让姓名框重建（光标与组合输入都在那个控件里）。
 * ★ 提交错误是业务状态 ⇒ 也走订阅（§11.4.3），不是"命令层顺手塞一句"。
 */
final class SignupPage extends StatelessWidget
{
    /** @var null|callable(): void `Enter` 提交的出口（组合根递的 `dispatch('form.submit')`） */
    private $submit = null;

    public function __construct(private readonly SignupForm $form, ?callable $submit = null)
    {
        parent::__construct();
        $this->submit = $submit;
    }

    public static function make(SignupForm $form, ?callable $submit = null): self
    {
        return new self($form, $submit);
    }

    /** 两个字段共用一条提交路（不复制校验/序列化） */
    private function submitted(): void
    {
        $submit = $this->submit;
        if ($submit !== null) {
            $submit();
        }
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(12)->padding(EdgeInsets::all(24.0))->children([
            Text::make('注册新用户')->title(),

            // 值 + 该字段错误一起读（都在这一个 builder 里 ⇒ 一条依赖集合）
            Bind::make(fn(): Widget => TextField::make()
                ->value($this->form->name)
                ->label('姓名')
                ->error($this->form->errorOf('name'))
                ->maxLength(32)
                ->autoFocus()
                ->onSubmitted(fn(string $_) => $this->submitted())
                ->onChanged(fn(string $x) => $this->form->name = $x)),

            Bind::make(fn(): Widget => TextField::make()
                ->value($this->form->email)
                ->label('邮箱')
                ->error($this->form->errorOf('email'))
                ->maxLength(128)
                ->inputFormatters(new FilteringTextInputFormatter('/\s+/u'))
                ->onSubmitted(fn(string $_) => $this->submitted())
                ->onChanged(fn(string $x) => $this->form->email = $x)),

            // 提交错误也是业务状态 ⇒ 订阅回 UI（§11.4.3）
            Bind::make(fn(): Widget => $this->form->submitError === ''
                ? Row::make()
                : Text::make($this->form->submitError)->error()),

            Row::make()->spacing(8)->children([
                Button::primary('提交')->command('form.submit'),

                Spacer::make(),

                // ★ 纯 UI 回调 ⇒ 闭包合法（不碰业务状态）
                Button::make('清空')->onPressed(fn() => $this->form->reset()),
            ]),
        ]);
    }
}
