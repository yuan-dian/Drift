<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Restoration;

use App\Users\Jobs\FetchUsersJob;
use App\Users\Store\MemoryUserSource;
use App\Users\UsersApplication;
use Drift\App\AppPaths;
use Drift\App\AppScope;
use Drift\App\Application;
use Drift\App\Restoration\RestorationStore;
use Drift\App\WindowSet;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Platform\Win32\Win32Platform;

/** 真消费者：users 应用冷启动复原，以及组合根退出时确实写回一次。 */
final class WiringTest extends SmokeTestCase
{
    private string $path = '';

    private AppPaths $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = (string) tempnam(sys_get_temp_dir(), 'drift-app-state-');
        $this->paths = AppPaths::at(sys_get_temp_dir(), 'drift-wiring-' . getmypid());
        @unlink($this->path);
        @unlink($this->paths->windowState());
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->paths->windowState());
        parent::tearDown();
    }

    /** @param array<string, mixed> $data */
    private function scopeWith(array $data): AppScope
    {
        file_put_contents($this->path, json_encode(['v' => 1, 'data' => $data], JSON_UNESCAPED_UNICODE));

        return AppScope::make()->setRestoration(new RestorationStore($this->path));
    }

    public function testUsersAppRestoresFormPageAndQuery(): void
    {
        $scope = $this->scopeWith([
            'form.name' => '李四',
            'form.email' => 'li@example.com',
            'users.query' => 'zhang',
            'nav' => [
                'entries' => [['name' => 'users', 'args' => []], ['name' => 'signup', 'args' => []]],
                'index' => 1,
            ],
        ]);

        $app = new UsersApplication(new MemoryUserSource(), null, FetchUsersJob::DEFAULT_BASE, $scope);

        check('冷启动 · 表单值复原',
            $app->signupForm()->name === '李四' && $app->signupForm()->email === 'li@example.com',
            $app->signupForm()->toApiPayload()['name'] ?? '');
        check('冷启动 · 当前页复原（不是回默认首页）', $app->appStore()->currentPage() === 'signup');
        check('冷启动 · 搜索词复原', $app->userStore()->query() === 'zhang');

        $app->signupForm()->name = '王五';
        check('改表单 ⇒ 直接落进 bucket（没有第二份真相源）',
            $scope->restoration()?->read('form.name') === '王五');

        $app->appStore()->navigate('settings');
        check('切页 ⇒ 导航存档跟着变',
            ($scope->restoration()?->read('nav')['index'] ?? null) === 2);
        check('返回回上一页', $app->appStore()->back() && $app->appStore()->currentPage() === 'signup');
        check('返回 ⇒ 导航存档同步（否则冷启动又跳到别页）',
            ($scope->restoration()?->read('nav')['index'] ?? null) === 1);

        $scope->restorationStore()?->persist();
        $cold = new RestorationStore($this->path);
        check('写回后全新 store 读到同样的四项',
            $cold->bucket()->read('form.name') === '王五'
                && $cold->bucket()->read('form.email') === 'li@example.com'
                && $cold->bucket()->read('users.query') === 'zhang'
                && ($cold->bucket()->read('nav')['entries'][1]['name'] ?? null) === 'signup');

        $this->flushChecks();
    }

    public function testWindowSetRunPersistsRestoration(): void
    {
        winLoopReset();
        $platform = new Win32Platform('soft');
        $scope = AppScope::make()->setRestoration(new RestorationStore($this->path));
        $set = new WindowSet($platform, $this->paths);

        $set->open('main', 320, 240, static function () use ($scope): Application {
            return new EchoApplication([], $scope);
        }, '窗');
        $scope->restoration()?->write('form.name', '赵六');

        winPumpScript([['type' => 'quit']]);
        $set->run();

        $cold = new RestorationStore($this->path);
        check('退出时 finally 把业务状态写回（全新实例可读）',
            $cold->bucket()->read('form.name') === '赵六',
            (string) @file_get_contents($this->path));
        check('几何文件与业务状态文件互不覆盖', is_file($this->paths->windowState()) && is_file($this->path));

        $this->flushChecks();
    }
}
