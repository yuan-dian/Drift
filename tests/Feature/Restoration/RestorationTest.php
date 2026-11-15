<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Restoration;

use Drift\App\Restoration\RestorableValue;
use Drift\App\Restoration\RestorationBucket;
use Drift\App\Restoration\RestorationStore;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Routing\NavigationStack;
use Drift\Routing\RouteTable;
use Drift\Widget\SizedBox;
use Drift\Widget\Widget;

/** 业务状态恢复：跨进程冷启动 + 容错分支 + 导航栈 + 带参路由。 */
final class RestorationTest extends SmokeTestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = (string) tempnam(sys_get_temp_dir(), 'drift-restore-');
        @unlink($this->path);   // store 构造要见到「文件不存在」
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runScript(string $name): array
    {
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/' . $name, $this->path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            return [-1, '', 'proc_open 失败'];
        }

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), trim($out), trim($err)];
    }

    /** P0-3 主判据：进程 A 写、进程 B 冷启动读，中间不共享任何内存。 */
    public function testColdStartAcrossProcesses(): void
    {
        [$a, , $errA] = $this->runScript('writer.php');
        check('进程 A 写入并退出 0', $a === 0, $errA);
        check('进程 A 真的产出了状态文件', is_file($this->path), $this->path);

        [$b, $out, $errB] = $this->runScript('reader.php');
        check('进程 B 冷启动退出 0', $b === 0, $errB);
        check(
            '四项全复原（表单 / 页面 / 滚动偏移 / 选中态），不靠同进程变量或内存单例',
            json_decode($out, true) === [
                'form' => '张三',
                'nav' => 'signup',
                'scroll' => 300,
                'selection' => [2, 5],
            ],
            $out . ' / ' . $errB,
        );

        $this->flushChecks();
    }

    public function testStoreBranches(): void
    {
        $empty = new RestorationStore('');
        $empty->bucket()->write('x', 1);
        $empty->persist();
        check('空路径 ⇒ 空操作（persist 早退，脏标志不清）',
            $empty->bucket()->read('x') === 1 && $empty->bucket()->isDirty());

        file_put_contents($this->path, '{not json');
        $bad = new RestorationStore($this->path);
        check('坏文件 ⇒ 空 bucket（静默降级，不中止启动）', $bad->bucket()->all() === []);

        file_put_contents($this->path, '{"v":99,"data":{"x":1}}');
        $wrong = new RestorationStore($this->path);
        check('版本不符 ⇒ 空 bucket', $wrong->bucket()->all() === []);

        $text = '{"v":1,"data":{"x":1}}';
        file_put_contents($this->path, $text);
        $loaded = new RestorationStore($this->path);
        $loaded->persist();
        check(
            '只装载不改动 ⇒ 不覆盖原文件（否则每次退出都白写一次）',
            (string) file_get_contents($this->path) === $text,
        );

        check(
            'isValue 拒绝对象 / INF，接受嵌套标量与数组',
            !RestorationBucket::isValue(new \stdClass())
                && !RestorationBucket::isValue(INF)
                && RestorationBucket::isValue(['a' => [1, true, null, 1.5, '中']]),
        );

        $view = new RestorableValue($loaded->bucket(), 'y', 'default');
        check('RestorableValue 未写入时给默认值', $view->value() === 'default');
        $view->set('z');
        check('RestorableValue 的 set 直接落进同一个 bucket', $loaded->bucket()->read('y') === 'z');
        $loaded->persist();
        check('persist 后清脏', !$loaded->bucket()->isDirty());
        $loaded->bucket()->write('y', 'z');
        check('与落盘值相同的写入不标脏（不会每次退出都白写）', !$loaded->bucket()->isDirty());
        check('落盘内容确实是 y=z', str_contains((string) file_get_contents($this->path), '"y": "z"'));

        $this->flushChecks();
    }

    public function testNavigationStack(): void
    {
        $changes = [];
        $stack = new NavigationStack('users', static function (array $s) use (&$changes): void {
            $changes[] = $s;
        });

        check('初始页 = home', $stack->current() === 'users' && !$stack->canPop());
        check('栈底 pop 返回 false', !$stack->pop());
        check('push 带参数', $stack->push('signup', ['id' => 7])
            && $stack->current() === 'signup'
            && $stack->args() === ['id' => 7]);
        check('onChange 只在真变更时触发一次', count($changes) === 1, (string) count($changes));

        $stack->push('settings');
        check('pop 回上一页且参数还在', $stack->pop()
            && $stack->current() === 'signup'
            && $stack->args() === ['id' => 7]);
        check('pop 之后可前进', $stack->canGoForward() && $stack->forward() && $stack->current() === 'settings');
        $stack->pop();
        $stack->replace('users');
        check('replace 换栈顶、不留下前进历史', !$stack->canGoForward() && $stack->current() === 'users');

        $guarded = new NavigationStack(
            'users',
            null,
            static fn(string $from, string $to): bool => $to !== 'settings',
        );
        check('守卫拦下 push', !$guarded->push('settings') && $guarded->current() === 'users');
        check('守卫放行 push', $guarded->push('signup') && $guarded->current() === 'signup');
        $noPop = new NavigationStack('users', null, static fn(string $from, string $to): bool => $from !== 'signup');
        $noPop->push('signup');
        check('守卫同样管 pop（离开 signup 被拒）', !$noPop->pop() && $noPop->current() === 'signup');

        $saved = $stack->toArray();
        $restored = NavigationStack::fromArray($saved, 'fallback');
        check('toArray / fromArray 往返一致', $restored->toArray() === $saved);
        check('非法存档回首页（外部文件不让启动失败）',
            NavigationStack::fromArray(['entries' => 'bad', 'index' => 0], 'fallback')->current() === 'fallback');
        check('index 越界夹到最后一页',
            NavigationStack::fromArray(['entries' => [['name' => 'a', 'args' => []]], 'index' => 99], 'fallback')
                ->current() === 'a');

        $this->flushChecks();
    }

    public function testRouteTableArgs(): void
    {
        $plain = RouteTable::make()->route('a', fn(): Widget => SizedBox::make()->width(10.0));
        check('既有零参 route() 仍能 build', $plain->build('a') !== null);
        check('零参 route() 收到 args 也不炸', $plain->build('a', ['x' => 1]) !== null);

        $seen = [];
        $withArgs = RouteTable::make()->routeArgs('b', function (array $a) use (&$seen): Widget {
            $seen[] = $a;

            return SizedBox::make();
        });
        check('routeArgs 的工厂收到参数', $withArgs->build('b', ['id' => 7]) !== null && $seen === [['id' => 7]]);

        $both = RouteTable::make()
            ->route('x', fn(): Widget => SizedBox::make())
            ->routeArgs('y', fn(array $a): Widget => SizedBox::make());
        check('has / names 覆盖两种注册形态', $both->has('x') && $both->has('y')
            && $both->names() === ['x', 'y']);

        $this->flushChecks();
    }
}
