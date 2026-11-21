<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use App\Watermark\Store\StatusMessage;
use App\Watermark\Store\WatermarkStore;
use App\Watermark\Widgets\StatusBar;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Cell;
use Drift\Widget\Bind;
use Drift\Widget\Element;
use Drift\Widget\Text;

/**
 * 状态栏（`apps/watermark`）—— 订阅责任在框架控件上，应用只排版。
 *
 * ## 为什么这条测试必须存在
 *
 * 这个控件被改写过多轮（190 行手写订阅器 → `SubjectBuilder` → `Bind`），每次都
 * 删掉一整类代码，而四条闸门一条也看不出来：`php -l` / `lint` / `smoke` /
 * `phpunit` 都不渲染它。改写的失败模式是**静默的**：订阅没接上 ⇒ 界面永远停在
 * 首帧的值；门控写错 ⇒ 每次通知都重建（看不出来，只是慢）。
 *
 * ## 判据三条
 *
 * | 用例 | 钉住的不变式 |
 * |---|---|
 * | ① 是框架控件在订阅 | 订阅责任落在 `Bind` 上，不是应用又写了一份 |
 * | ② 首帧就有值 | `store` 的初始值是 `info('准备就绪')` —— 用户第一眼该看到它（**不是空白**） |
 * | ③ 值变了才重建 | 门控在格子上（`StatusMessage` 实现 `Equatable`）：换了内容要更新；同内容的新实例不该重建 |
 *
 * ★ ③ 用反射拿格子再 `set()`：那个字段只有异步链会改（`runUploadFlow` /
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
            '状态栏 · 订阅责任落在 Bind（应用不再手写订阅器）',
            $bar instanceof Bind,
            '实际类型 = ' . \get_class($bar)
        );

        $element = $bar->createElement();
        $element->mount(null, smokeContext());

        // ② 首帧就有值（builder 在 mount 时就跑了一次，读点当场订上）
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
            '重建了但文案没换 ⇒ 读点或 builder 取错了值'
        );

        // ④ 同内容（新实例）⇒ 不重建（门控在格子上，问 `StatusMessage::equals()`）
        $this->forceStatus($store, StatusMessage::success('上传完成，可以提交任务了'));
        check(
            '状态栏 · 内容相同的新实例被格子门控挡住（不重建）',
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
     * 写 `statusLine` 那一格。
     *
     * ★ 不调 `setAccessible(true)`：PHP 8.1 起反射默认可达，8.5 起该调用是
     *   deprecation（本仓 `phpunit` 把 deprecation 当噪音，会掩盖真的那条）。
     */
    private function forceStatus(WatermarkStore $store, StatusMessage $message): void
    {
        $cell = (new \ReflectionProperty(WatermarkStore::class, 'statusLine'))->getValue($store);
        self::assertInstanceOf(Cell::class, $cell);

        $cell->set($message);
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
