<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Platform;

use Drift\App\EventLoop;
use Drift\App\WindowSet;
use Drift\Dev\Tests\Support\HeadlessPlatform;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Platform\InputSource;
use Drift\Platform\Platform;
use Drift\Platform\PlatformHooks;
use Drift\Platform\Screen;
use Drift\Platform\WakeReason;
use Drift\Platform\WindowHost;
use Drift\Platform\Win32\Win32Platform;
use Drift\Platform\X11\X11Platform;

/**
 * 「平台 = 可替换的接口」这条口径的契约自证。
 *
 * ★ 此前 `Platform` 只有一个实现，所有测试都用真的 `Win32Platform` + 假 C 桥；
 *   `EventLoop` / `WindowSet` 注释里的「任意平台实现」从来没有第二个实现验过。
 *   这里的最小实现只证明接口可替换，**不取代** `WindowSetTest` 里
 *   「桥可假，策略不可假」的既有裁决。
 */
final class PlatformContractTest extends SmokeTestCase
{
    private const EXPECTED = [
        'clipboard', 'configDir', 'consumeModalBlockedMs', 'dialog', 'fatalBox',
        'hooks', 'imeCaret', 'input', 'now', 'pumpEvents', 'screen', 'surface', 'tray',
        'waitEvents', 'window',
    ];

    public function testInterfaceShapeIsPinned(): void
    {
        $methods = [];
        foreach ((new \ReflectionClass(Platform::class))->getMethods() as $method) {
            $methods[] = $method->getName();
        }
        sort($methods);

        check(
            'Platform · 接口方法集 == 登记的 15 个（新增端口必须同步契约）',
            $methods === self::EXPECTED,
            'actual=' . implode(',', $methods),
        );

        $this->flushChecks();
    }

    public function testWin32ImplementsEveryPort(): void
    {
        $reflection = new \ReflectionClass(Win32Platform::class);
        $missing = [];
        foreach (self::EXPECTED as $name) {
            if (!$reflection->hasMethod($name) || $reflection->getMethod($name)->isAbstract()) {
                $missing[] = $name;
            }
        }

        check('Platform · Win32Platform implements Platform', $reflection->implementsInterface(Platform::class));
        check(
            'Platform · Win32Platform 实现全部端口（新增方法漏实现即红）',
            $missing === [],
            'missing=' . implode(',', $missing),
        );

        $this->flushChecks();
    }

    public function testX11ImplementsEveryPort(): void
    {
        $reflection = new \ReflectionClass(X11Platform::class);
        $missing = [];
        foreach (self::EXPECTED as $name) {
            if (!$reflection->hasMethod($name) || $reflection->getMethod($name)->isAbstract()) {
                $missing[] = $name;
            }
        }

        check(
            'Platform · X11Platform 实现全部端口（跨平台漏实现即红）',
            $missing === [],
            'missing=' . implode(',', $missing),
        );
        check('Platform · X11 hooks() 返回 PlatformHooks', (new X11Platform())->hooks() instanceof PlatformHooks);

        $this->flushChecks();
    }

    public function testHeadlessIsAWorkingSecondImplementation(): void
    {
        $platform = new HeadlessPlatform();
        check('Platform · HeadlessPlatform 是第二个真正的实现', $platform instanceof Platform);
        check('Headless · screen() 返回 Screen', $platform->screen(1) instanceof Screen);
        check('Headless · window() 返回 WindowHost', $platform->window() instanceof WindowHost);
        check('Headless · input() 返回 InputSource', $platform->input(1) instanceof InputSource);
        check('Headless · 三个可空壳服务都答 null', $platform->surface(1) === null
            && $platform->clipboard() === null && $platform->dialog() === null && $platform->tray() === null);
        check('Headless · waitEvents() 返回 WakeReason', $platform->waitEvents(-1.0) instanceof WakeReason);
        check('Headless · pumpEvents() 返回条数', $platform->pumpEvents() === 0);
        check('Headless · consumeModalBlockedMs() 读后为 0', $platform->consumeModalBlockedMs() === 0.0);
        check('Headless · configDir() 空串 = 不持久化', $platform->configDir() === '');
        check('Headless · hooks() 返回 PlatformHooks', $platform->hooks() instanceof PlatformHooks);
        check('Headless · now() 单调', $platform->now() < $platform->now());
        $platform->fatalBox('t', 'b');
        $platform->imeCaret(1, null);
        check('Headless · void 端口可调用且不抛', in_array('fatalBox', $platform->calls, true)
            && in_array('imeCaret', $platform->calls, true));

        $this->flushChecks();
    }

    public function testConsumersTypeAgainstTheInterface(): void
    {
        $run = (string) (new \ReflectionMethod(EventLoop::class, 'run'))->getParameters()[0]->getType();
        $ctor = (string) (new \ReflectionMethod(WindowSet::class, '__construct'))->getParameters()[0]->getType();

        check('组合根 · EventLoop::run() 只认 Platform', $run === Platform::class, "type={$run}");
        check('组合根 · WindowSet 构造只认 Platform', $ctor === Platform::class, "type={$ctor}");
        check(
            '组合根 · src/App 不出现 Win32Platform 类型名（注释与字符串不算）',
            $this->win32TypeNamesInApp() === [],
            'hits=' . implode(',', $this->win32TypeNamesInApp()),
        );

        $this->flushChecks();
    }

    public function testHeadlessCanBeInjectedIntoTheCompositionRoot(): void
    {
        $platform = new HeadlessPlatform();
        check('组合根 · WindowSet 能接受第二实现', new WindowSet($platform, '') instanceof WindowSet);

        EventLoop::run($platform, []);
        check(
            '组合根 · EventLoop 在第二实现上真的跑过并退出',
            in_array('window', $platform->calls, true)
                && in_array('now', $platform->calls, true)
                && in_array('waitEvents', $platform->calls, true),
            'calls=' . implode(',', array_unique($platform->calls)),
        );

        $this->flushChecks();
    }

    /** @return list<string> */
    private function win32TypeNamesInApp(): array
    {
        $root = dirname(__DIR__, 3) . '/src/App';
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                    continue;
                }
                if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                if (str_contains($token[1], 'Win32Platform')) {
                    $hits[] = $file->getFilename() . ':' . $token[2];
                }
            }
        }

        return $hits;
    }
}
