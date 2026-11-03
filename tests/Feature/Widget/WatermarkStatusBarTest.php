<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use App\Watermark\Store\StatusMessage;
use App\Watermark\Store\WatermarkStore;
use App\Watermark\Widgets\StatusBar;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\SubjectBuilder;
use Drift\Widget\Element;
use Drift\Widget\Text;

/**
 * 状态栏（`apps/watermark`）—— 2026-11-03 从 190 行手写订阅器收成 `SubjectBuilder`。
 *
 * ## 为什么这条测试必须存在
 *
 * 那个改写**删掉了一整类代码**（`StatefulWidget` + 私有 `State` + `watch()` +
 * 自写门控），但删得对不对，四条闸门一条也看不出来：`php -l` / `lint` / `smoke` /
 * `phpunit` 都不渲染这个控件。改写的失败模式是**静默的**：
 * 订阅没接上 ⇒ 界面永远停在首帧的值；门控写错 ⇒ 每次通知都重建（看不出来，只是慢）。
 *
 * ## 判据三条
 *
 * | 用例 | 钉住的不变式 |
 * |---|---|
 * | ① 是框架控件在订阅 | 订阅责任落在 `SubjectBuilder`（它 `implements SubscribingWidget`），不是应用又写了一份 |
 * | ② 首帧就有值 | `store` 的初始值是 `info('准备就绪')` —— 用户第一眼该看到它（**不是空白**） |
 * | ③ 值变了才重建 | 值对象靠 `equals()` 门控：换了内容要更新；同内容的新实例不该重建 |
 *
 * ★ ③ 用反射改 `statusLine`：它是私有字段且**只有异步链会改**（`startUpload` /
 *   轮询结算）。为了测一行 UI 去搭一整条假的后台链，会让这条判据依赖三个与本
 *   控件无关的东西 —— 反射在这里是更小的谎。
 */
final class WatermarkStatusBarTest extends SmokeTestCase
{
    /** @var list<string> 临时文件，测完清掉 */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @\unlink($path);
        }
        $this->tmp = [];
    }

    public function testStatusBarFollowsStoreStatus(): void
    {
        $store = $this->store();

        // ① 订阅责任在框架控件上（应用侧不再 implements SubscribingWidget）
        $bar = StatusBar::make($store);
        check(
            '状态栏 · 订阅责任落在 SubjectBuilder（应用不再手写订阅器）',
            $bar instanceof SubjectBuilder,
            '实际类型 = ' . \get_class($bar)
        );

        $element = $bar->createElement();
        $element->mount(null, smokeContext());

        // ② 首帧就有值（旧实现靠 initState 里"先读一次"，新实现靠 SubjectBuilder 自己读）
        check(
            '状态栏 · 首帧显示 store 的初始状态（不是空栏）',
            self::text($element, '准备就绪'),
            '首帧没画出来 ⇒ 用户会看到一条空栏'
        );

        // ③ 内容变了 ⇒ 更新
        $this->forceStatus($store, StatusMessage::success('上传完成，可以提交任务了'));
        check('状态栏 · 状态变了 ⇒ 标脏', $element->state()->needsBuild(), '订阅没接上');
        $element->rebuild();
        check(
            '状态栏 · 重建后显示新文案',
            self::text($element, '上传完成，可以提交任务了'),
            '重建了但文案没换 ⇒ 切片或 builder 取错了值'
        );

        // ④ 同内容（新实例）⇒ 不重建（`equals()` 门控；没有它这里会一直标脏）
        $this->forceStatus($store, StatusMessage::success('上传完成，可以提交任务了'));
        check(
            '状态栏 · 内容相同的新实例被 equals 挡住（不重建）',
            !$element->state()->needsBuild(),
            '值对象门控没生效 ⇒ 每次通知都重建这一行'
        );

        $element->unmount();
        $this->flushChecks();
    }

    /** 树上有没有这句 `Text` 的内容 */
    private static function text(Element $root, string $content): bool
    {
        return findElement($root, static function (Element $e) use ($content): bool {
            $w = $e->widget();

            return $w instanceof Text && $w->content() === $content;
        }) !== null;
    }

    /**
     * 直接改 `statusLine` 并广播。
     *
     * ★ 私有字段 + 反射：那条链路上真正的写点都在异步回调里（`startUpload` /
     *   轮询结算），为一条 UI 判据搭假后台会让它依赖三个无关的东西。
     */
    private function forceStatus(WatermarkStore $store, StatusMessage $message): void
    {
        $field = new \ReflectionProperty(WatermarkStore::class, 'statusLine');
        // ★ 不调 `setAccessible(true)`：PHP 8.1 起反射默认可达，8.5 起该调用是
        //   deprecation（本仓 `phpunit` 把 deprecation 当噪音，会掩盖真的那条）。
        $field->setValue($store, $message);

        $store->notifyChanged();
    }

    private function store(): WatermarkStore
    {
        $file = (string) \tempnam(\sys_get_temp_dir(), 'drift-wm-');
        $path = $file . '.jpg';
        \rename($file, $path);
        $this->tmp[] = $path;

        // 不碰网络的 store：本用例只画界面，一次任务也不发。
        $store = new WatermarkStore(null, 'http://127.0.0.1:1');
        self::assertSame(1, $store->addFiles([$path]), '夹具没被选上 ⇒ 后面全靠巧合');

        return $store;
    }
}
