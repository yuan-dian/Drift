<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use App\Watermark\Store\StatusMessage;
use App\Watermark\Store\WatermarkStore;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Cell;
use Drift\Widget\Bind;
use Drift\Widget\BuildContext;
use Drift\Widget\Widget;

/**
 * 两条消息格子都要按**内容**门控，而不是按 `StatusMessage` 的实例身份（§69）。
 *
 * ★ 为什么单独一条：`WatermarkStatusBarTest` 只量 `statusLine()`；上传区与任务区
 *   的两条消息没有自己的判据，从前只在「该字段被重新赋成同内容新实例」时白白
 *   重建一次 —— 不会崩、不会报，只是静默多重建。
 *
 * ★ 判据量的是**可观察的重建次数**（builder 跑几次 / element 是否标脏），
 *   不是「`StatusMessage` 有没有 implements Equatable」这种实现同源的写法。
 *   把 `Equatable` 去掉、或把 `Cell` 的默认门控改回恒 `===`，本文件必红。
 */
final class MessageValueGateTest extends SmokeTestCase
{
    public function testAllMessageSlicesGateOnContent(): void
    {
        $store = new WatermarkStore(null, 'http://127.0.0.1:1');
        $ctx = smokeContext();

        /** @var array<string, \Closure(WatermarkStore): StatusMessage> */
        $readers = [
            'uploadMessage' => static fn (WatermarkStore $s): StatusMessage => $s->uploadMessage(),
            'taskMessage' => static fn (WatermarkStore $s): StatusMessage => $s->taskMessage(),
        ];
        $fields = array_keys($readers);

        $runs = array_fill_keys($fields, 0);
        $elements = [];
        foreach ($readers as $field => $read) {
            // ★ 读点就在 builder 里：`Bind` 采集它读到的格子，不用 `select` 声明。
            $widget = Bind::make(static function (BuildContext $ctx) use ($read, $store, &$runs, $field): Widget {
                $read($store);
                $runs[$field]++;

                return new LeafProbe(1.0);
            });
            $element = $widget->createElement();
            $element->mount(null, $ctx);
            $elements[$field] = $element;
        }
        check(
            '消息格子 · 首帧两条各建一次',
            $runs === array_fill_keys($fields, 1),
            'runs=' . json_encode($runs)
        );

        foreach ($fields as $field) {
            // 同内容的新实例：两个字段初值都是 none() ⇒ 与当前值内容相同
            $this->force($store, $field, StatusMessage::none());
            $dirty = [];
            foreach ($fields as $other) {
                if ($elements[$other]->state()->needsBuild()) {
                    $dirty[] = $other;
                }
            }
            check(
                '消息格子 · ' . $field . ' 同内容新实例不触发任何重建',
                $dirty === [],
                '被误判为变化的格子：' . implode(',', $dirty)
            );

            // 内容真的变了 ⇒ 只有该格子标脏，另一条保持不动（细粒度）
            $this->force($store, $field, StatusMessage::error($field . ' 失败'));
            $dirty = [];
            foreach ($fields as $other) {
                if ($elements[$other]->state()->needsBuild()) {
                    $dirty[] = $other;
                }
            }
            check(
                '消息格子 · ' . $field . ' 内容变了只重建自己',
                $dirty === [$field],
                '实际标脏：' . implode(',', $dirty)
            );
            $elements[$field]->rebuild();
        }

        check(
            '消息格子 · 两条各只多重建一次',
            $runs === array_fill_keys($fields, 2),
            'runs=' . json_encode($runs)
        );

        foreach ($elements as $element) {
            $element->unmount();
        }
        $this->flushChecks();
    }

    /** 私有格子 + 反射：真正的写点在异步回调里，为一条 UI 判据搭假后台不值当。 */
    private function force(WatermarkStore $store, string $field, StatusMessage $message): void
    {
        $cell = (new \ReflectionProperty(WatermarkStore::class, $field))->getValue($store);
        self::assertInstanceOf(Cell::class, $cell);

        // 走格子的 `set()` 而不是绕过它写字段：门控就住在那里。
        $cell->set($message);
    }
}
