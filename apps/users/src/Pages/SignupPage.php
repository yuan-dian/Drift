<?php
declare(strict_types=1);

namespace App\Users\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\State\SubjectBuilder;
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
 * 注册页（§A.2.9）：表单（每字段一个订阅切片 + 提交错误回显）。
 *
 * ★ **每个字段一个 `SubjectBuilder`**（漏了 ⇒ 值变了不刷新 ⇒ lint `dead-subscription`
 *   守）。值与该字段的错误合成一个切片，错误不多占一条订阅。
 */
final class SignupPage extends StatelessWidget
{
    public function __construct(private readonly SignupForm $form)
    {
        parent::__construct();
    }

    public static function make(SignupForm $form): self
    {
        return new self($form);
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(12)->padding(EdgeInsets::all(24.0))->children([
            Text::make('注册新用户')->title(),

            // 值 + 该字段错误一起切（数组按值 `===` 门控，不再多挂一条订阅）
            SubjectBuilder::make($this->form)
                ->select(static fn(SignupForm $f): array => [$f->name, $f->errorOf('name')])
                ->builder(fn(array $s): Widget => TextField::make()
                    ->value($s[0])
                    ->label('姓名')
                    ->error($s[1])
                    ->maxLength(32)
                    ->autoFocus()
                    ->onChanged(fn(string $x) => $this->form->name = $x)),

            SubjectBuilder::make($this->form)
                ->select(static fn(SignupForm $f): array => [$f->email, $f->errorOf('email')])
                ->builder(fn(array $s): Widget => TextField::make()
                    ->value($s[0])
                    ->label('邮箱')
                    ->error($s[1])
                    ->maxLength(128)
                    ->onChanged(fn(string $x) => $this->form->email = $x)),

            // 提交错误也是业务状态 ⇒ 订阅回 UI（§11.4.3）
            SubjectBuilder::make($this->form)
                ->select(static fn(SignupForm $f): string => $f->submitError)
                ->builder(static fn(string $err): Widget => $err === ''
                    ? Row::make()
                    : Text::make($err)->error()),

            Row::make()->spacing(8)->children([
                Button::primary('提交')->command('form.submit'),

                Spacer::make(),

                // ★ 纯 UI 回调 ⇒ 闭包合法（不碰业务状态）
                Button::make('清空')->onPressed(fn() => $this->form->reset()),
            ]),
        ]);
    }
}
