<?php
declare(strict_types=1);

namespace App\Users\Commands;

use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use App\Users\Store\AppStore;
use App\Users\Store\SignupForm;
use App\Users\Store\UserSource;
use App\Users\Store\UserStore;

/**
 * 注册命令（§A.2.10）：校验 ⇒ 提交（走源的唯一出口）⇒ 回跳列表页。
 *
 * ★ 依赖从**构造点**来（§23.2 O-15 方案 E）：handler 是注册期单例 ⇒ 必须无状态
 *   （跨命令状态属于 Store）。
 */
final class SignupCommands implements CommandHandler
{
    public function __construct(
        private SignupForm $form,
        private UserSource $src,
        private AppStore $app,
        private UserStore $users,
    ) {
    }

    public function handle(string $name, CommandArg $arg): bool
    {
        if ($name !== 'form.submit') {
            return false;
        }

        $errs = $this->form->validate();
        if ($errs !== []) {
            $this->form->fail(implode('；', $errs)); // ★ 反馈走 Store，不走 State

            return true;
        }

        $ok = $this->src->submit('/api/signup', $this->form->toApiPayload()); // ★ 唯一序列化出口
        if (!$ok) {
            $this->form->fail('提交失败，请重试');

            return true;
        }

        $this->form->reset();
        $this->app->say('注册成功');
        $this->app->navigate('users');
        $this->users->load();

        return true;
    }
}
