<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use App\Watermark\Store\StatusMessage;
use App\Watermark\Store\WatermarkStore;
use Drift\Async\AsyncValue;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\SubjectBuilder;
use Drift\Widget\Widget;

/**
 * 三条消息切片都要按**内容**门控，而不是按 `StatusMessage` 的实例身份（§69）。
 *
 * ★ 为什么单独一条：`WatermarkStatusBarTest` 只量 `statusLine()`，而它原本就
 *   显式写了 `->equals(...)`；`UploadCard::message()` / `TaskCard::taskMessage()`
 *   与 §68 新增的下载文案**都没有**，从前只在「该字段被重新赋成同内容新实例」
 *   时白白重建一次 —— 不会崩、不会报，只是静默多重建。
 *
 * ★ 判据量的是**可观察的重建次数**（builder 跑几次 / element 是否标脏），
 *   不是「`StatusMessage` 有没有 implements Equatable」这种实现同源的写法。
 *   把 `Equatable` 去掉、或把 `SubjectBuilder` 的默认门控改回恒 `===`，本文件必红。
 */
final class MessageValueGateTest extends SmokeTestCase
{
    public function testAllMessageSlicesGateOnContent(): void
    {
        $store = new WatermarkStore(null, 'http://127.0.0.1:1');
        $ctx = smokeContext();

        $fields = ['uploadMessage', 'taskMessage', 'downloadMessage'];
        $getters = [
            'uploadMessage' => static fn(WatermarkStore $s): StatusMessage => $s->uploadMessage(),
            'taskMessage' => static fn(WatermarkStore $s): StatusMessage => $s->taskMessage(),
            'downloadMessage' => static fn(WatermarkStore $s): StatusMessage => $s->downloadMessage(),
        ];

        $runs = array_fill_keys($fields, 0);
        $elements = [];
        foreach ($fields as $field) {
            $widget = SubjectBuilder::make($store)
                ->select($getters[$field])
                ->builder(static function (mixed $message) use (&$runs, $field): Widget {
                    $runs[$field]++;

                    return new LeafProbe(1.0);
                });
            $element = $widget->createElement();
            $element->mount(null, $ctx);
            $elements[$field] = $element;
        }
        check(
            '消息切片 · 首帧三条各建一次',
            $runs === array_fill_keys($fields, 1),
            'runs=' . json_encode($runs)
        );

        foreach ($fields as $field) {
            // 同内容的新实例：三个字段初值都是 none() ⇒ 与当前值内容相同
            $this->force($store, $field, StatusMessage::none());
            $dirty = [];
            foreach ($fields as $other) {
                if ($elements[$other]->state()->needsBuild()) {
                    $dirty[] = $other;
                }
            }
            check(
                '消息切片 · ' . $field . ' 同内容新实例不触发任何重建',
                $dirty === [],
                '被误判为变化的切片：' . implode(',', $dirty)
            );

            // 内容真的变了 ⇒ 只有该切片标脏，其余两条保持不动（细粒度）
            $this->force($store, $field, StatusMessage::error($field . ' 失败'));
            $dirty = [];
            foreach ($fields as $other) {
                if ($elements[$other]->state()->needsBuild()) {
                    $dirty[] = $other;
                }
            }
            check(
                '消息切片 · ' . $field . ' 内容变了只重建自己',
                $dirty === [$field],
                '实际标脏：' . implode(',', $dirty)
            );
            $elements[$field]->rebuild();
        }

        check(
            '消息切片 · 三条各只多重建一次',
            $runs === array_fill_keys($fields, 2),
            'runs=' . json_encode($runs)
        );

        foreach ($elements as $element) {
            $element->unmount();
        }
        $this->flushChecks();
    }

    /** 私有字段 + 反射：真正的写点在异步回调里，为一条 UI 判据搭假后台不值当。 */
    private function force(WatermarkStore $store, string $field, StatusMessage $message): void
    {
        if ($field === 'downloadMessage') {
            // 下载段收成 AsyncValue 后消息是投影，写点改到唯一真值。
            $value = $message->kind === StatusMessage::ERROR
                ? AsyncValue::error($message->text)
                : AsyncValue::idle();
            (new \ReflectionProperty(WatermarkStore::class, 'downloads'))->setValue($store, $value);
        } else {
            (new \ReflectionProperty(WatermarkStore::class, $field))->setValue($store, $message);
        }
        $store->notifyChanged();
    }
}
