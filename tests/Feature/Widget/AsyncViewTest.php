<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Async\AsyncValue;
use Drift\Errors;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Cell;
use Drift\Widget\ActivationContext;
use Drift\Widget\AsyncView;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\Text;
use Drift\Widget\Widget;

final class AsyncViewTest extends SmokeTestCase
{
    public function testFourArmsFollowTheReadable(): void
    {
        $cell = Cell::of(AsyncValue::idle());
        $view = AsyncView::of(
            $cell,
            static fn (AsyncValue $v): Widget => Text::make('data:' . (string) $v->dataOr('')),
        )->widget();

        $element = $view->createElement();
        $element->mount(null, smokeContext());
        check('AsyncView · idle 默认支有内容', self::hasText($element, '尚未加载'));

        $this->advance($element, $cell, AsyncValue::loading());
        check('AsyncView · loading 默认支画出转圈提示', self::hasText($element, '加载中…'));

        $this->advance($element, $cell, AsyncValue::data('42'));
        check('AsyncView · data 支拿到载荷', self::hasText($element, 'data:42'));

        $this->advance($element, $cell, AsyncValue::error('服务挂了'));
        check('AsyncView · error 默认支显示消息', self::hasText($element, '服务挂了'));

        $element->unmount();
        $this->flushChecks();
    }

    public function testRefreshStillUsesDataArm(): void
    {
        $previous = AsyncValue::data('旧值');
        $cell = Cell::of($previous);
        $view = AsyncView::of(
            $cell,
            static fn (AsyncValue $v): Widget => Text::make('data:' . (string) $v->dataOr('空')),
        )->widget();

        $element = $view->createElement();
        $element->mount(null, smokeContext());
        $this->advance($element, $cell, AsyncValue::loading()->carriedFrom($previous));

        check(
            'AsyncView · 刷新中沿用 fold 的 data 路由（不闪回转圈）',
            self::hasText($element, 'data:旧值') && !self::hasText($element, '加载中…'),
        );

        $element->unmount();
        $this->flushChecks();
    }

    public function testRetryOnAddsRetryButtonToDefaultErrorArm(): void
    {
        $cell = Cell::of(AsyncValue::idle());
        $fired = 0;
        $view = AsyncView::of(
            $cell,
            static fn (AsyncValue $v): Widget => Text::make('data'),
        )
            ->retryOn(static function () use (&$fired): void { $fired++; })
            ->widget();

        $element = $view->createElement();
        $element->mount(null, smokeContext());
        $this->advance($element, $cell, AsyncValue::error('服务挂了'));

        check('AsyncView · retryOn ⇒ error 默认支有重试按钮', self::buttonLabeled($element, '重试') !== null);
        self::buttonLabeled($element, '重试')?->activate(ActivationContext::none());
        check('AsyncView · 点一次重试 ⇒ 闭包被调一次', $fired === 1, 'fired=' . $fired);

        $element->unmount();
        $this->flushChecks();
    }

    public function testExplicitErrorWinsOverRetryOn(): void
    {
        $cell = Cell::of(AsyncValue::idle());
        $view = AsyncView::of($cell, static fn (AsyncValue $v): Widget => Text::make('data'))
            ->error(static fn (AsyncValue $v): Widget => Text::make('定制错误'))
            ->retryOn(static function (): void {})
            ->widget();

        $element = $view->createElement();
        $element->mount(null, smokeContext());
        $this->advance($element, $cell, AsyncValue::error('服务挂了'));

        check('AsyncView · 显式 error() 优先于 retryOn 的默认支', self::hasText($element, '定制错误'));
        check('AsyncView · 显式 error 时不多画重试按钮', self::buttonLabeled($element, '重试') === null);

        $element->unmount();
        $this->flushChecks();
    }

    public function testWrongSourceTypeIsReported(): void
    {
        $cell = Cell::of('不是 AsyncValue');
        $view = AsyncView::of($cell, static fn (AsyncValue $v): Widget => Text::make('data'))->widget();

        $element = $view->createElement();
        $element->mount(null, smokeContext());
        check(
            'AsyncView · 源类型错 ⇒ 诊断 + 可见错误文本（不静默空白）',
            self::hasText($element, '异步状态不可用') && Errors::recentDiagnostics() !== [],
        );

        $element->unmount();
        $this->flushChecks();
    }

    private function advance(Element $element, Cell $cell, AsyncValue $value): void
    {
        $cell->set($value);
        check('AsyncView · 源变化 ⇒ 标脏', $element->state()->needsBuild());
        $element->rebuild();
    }

    private static function hasText(Element $root, string $content): bool
    {
        return findElement($root, static function (Element $e) use ($content): bool {
            $w = $e->widget();

            return $w instanceof Text && $w->content() === $content;
        }) !== null;
    }

    private static function buttonLabeled(Element $root, string $label): ?Button
    {
        $found = findElement($root, static function (Element $e) use ($label): bool {
            $w = $e->widget();

            return $w instanceof Button && $w->label() === $label;
        });

        $widget = $found?->widget();

        return $widget instanceof Button ? $widget : null;
    }
}
