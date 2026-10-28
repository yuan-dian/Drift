<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Commands;

use Drift\Commands\CommandArg;
use Drift\Commands\CommandRouter;
use Drift\Dev\Smoke\Commands\CommandWidget;
use Drift\Dev\Smoke\Commands\EchoCommands;
use Drift\Dev\Smoke\Commands\PickyCommands;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;

/**
 * 命令：参数值对象 / 注册表 / 冒泡链第 ① 环（§11.4.3）。
 *
 * ## 判据分三段，各守一件事
 *
 * ① `CommandArg` 是**双槽**结构（`i` + `s`），不是裸 int —— 页面 ID 与字符串 key
 *   共用一条通道，砍成一槽两种调用方就得互相猜。
 * ② `CommandRouter` 的"没接"有两种形态，都**不许静默**：handler 返回 false（不是我管的）、
 *    名字根本没注册（打错字）。后者必须进 Diagnostic 环形缓冲。
 * ③ 冒泡链第 ① 环是 `State::handleCommand()`，**默认不接**；接了就不再走注册表。
 *    顺序反了页面永远收不到自己的命令。
 *
 * ★ "同名重复注册 ⇒ Fatal"用子进程验 `exit 3` + Errors.log，依赖入口脚本是 smoke ⇒
 *   留在 `tools/smoke.php`。
 */
final class CommandTest extends SmokeTestCase
{
    private function logPath(): string
    {
        return \sys_get_temp_dir() . '/drift-cmd-' . \getmypid() . '.log';
    }

    public function testCommandArgHasTwoSlots(): void
    {
        check(
            'Commands · arg 不是裸 int（int id 与 string key 各有一个槽）',
            CommandArg::none()->i === 0 && CommandArg::none()->s === ''
                && CommandArg::id(7)->i === 7 && CommandArg::id(7)->s === ''
                && CommandArg::key('nav.users')->s === 'nav.users' && CommandArg::key('nav.users')->i === 0
        );

        $this->flushChecks();
    }

    public function testRouterDispatches(): void
    {
        $echo = new EchoCommands();
        $router = CommandRouter::make()->on('user.del', $echo);
        check('Commands · on() 返回同一个注册表（可链式，不每步 new）', $router->names() === ['user.del']);
        check(
            'Commands · dispatch 把名字与参数原样递给 handler',
            $router->dispatch('user.del', CommandArg::id(42)) && $echo->seen === [['user.del', 42, '']]
        );

        $this->flushChecks();
    }

    /**
     * "没接"的两种形态都不许静默。
     *
     * ★ 第二、三条必须成对：只验"返回 false"分不出"记了诊断"与"什么都没发生"；
     *   环形缓冲的前后差量才是"真的写进去了"。
     */
    public function testUnhandledCommandIsReported(): void
    {
        $log = $this->logPath();

        // handler 说"不是我管" ⇒ 视为没接（调用方继续第 ④ 环）
        $picky = new PickyCommands('nav.users');
        $pickyRouter = CommandRouter::make()->on('nav.users', $picky);
        check(
            'Commands · handler 返回 false ⇒ 视为没接（继续冒泡的语义）',
            !$pickyRouter->dispatch('nav.signup', CommandArg::none())
        );

        // 没人接 ⇒ Diagnostic 级上报，**不静默**（菜单项亮了但点了什么都没发生最难查）
        fresh($log);
        $ringBefore = count(Errors::recentDiagnostics());
        check('Commands · 未注册的名字 ⇒ 返回 false 且不中止', !$pickyRouter->dispatch('ghost.cmd', CommandArg::none()));
        check(
            'Commands · 未注册 ⇒ 进了 Diagnostic 环形缓冲（不是静默）',
            count(Errors::recentDiagnostics()) === $ringBefore + 1
        );

        $this->flushChecks();
    }

    public function testStateCommandBubbling(): void
    {
        $context = smokeContext();
        $log = $this->logPath();

        // 冒泡链第 ① 环：State 默认"不接"
        check('Commands · State 默认不接命令（不接是常态，不做成抽象）', (new CommandWidget())->createState()->handleCommand('x', CommandArg::none()) === false);

        // 第 ① 环接了 ⇒ 不需要走到注册表
        fresh($log);
        $cmdElement = (new CommandWidget())->createElement();
        $cmdElement->mount(null, $context);
        $cmdState = $cmdElement->state();
        $taken = $cmdState->handleCommand('widget.echo', CommandArg::id(3));
        $skipped = $cmdState->handleCommand('other.thing', CommandArg::none());
        check(
            'Commands · State 接管自己的命令、其余放行（冒泡链有明确返回值）',
            $taken && !$skipped && $cmdState->handled === ['widget.echo', 'other.thing']
        );
        $cmdElement->unmount();

        $this->flushChecks();
    }
}
