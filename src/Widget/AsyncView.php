<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Async\AsyncValue;
use Drift\Core\Readable;
use Drift\Errors;

/**
 * 异步四态视图：把 `AsyncValue::fold()` 的四支收成一个控件。
 *
 * ```php
 * AsyncView::of($store->page(), fn (AsyncValue $v): Widget => $this->rows($v))
 *     ->error(fn (AsyncValue $v): Widget => RetryCard::make($v->message()))
 *     ->widget();
 * ```
 *
 * ★ `data` 支必须给：载荷类型只有调用方知道，框架猜不出来。其余三支有可用默认，
 *   但默认不是空白 —— 四态任何一态都必须有内容，`fold()` 的穷尽性由它守住。
 * ★ 源收 `Readable` 而不是 `AsyncValue`：`get()` 发生在 `Bind` 的 build 期，读即订阅；
 *   传值会让页面自己建一条订阅，等于把 A19 再实现一遍。
 * ★ 刷新中走 `data` 支的规则不在本类：那是 `AsyncValue::fold()` 的契约，重复一份必漂。
 * ★ 源返回错类型可降级 ⇒ 一条诊断 + 可见错误文本，不 abort。
 *
 * @api
 */
final class AsyncView
{
    /** @var \Closure(AsyncValue): Widget */
    private \Closure $idle;
    /** @var \Closure(AsyncValue): Widget */
    private \Closure $loading;
    /** @var \Closure(AsyncValue): Widget */
    private \Closure $error;

    /** error 默认支的重试动作；null = 错误态只显示消息，不画重试按钮 */
    private ?\Closure $retry = null;

    /** 调用方显式给过 `error()` ⇒ retryOn 不得覆盖它 */
    private bool $errorOverridden = false;

    /** @param \Closure(AsyncValue): Widget $data */
    private function __construct(
        private readonly Readable $source,
        private readonly \Closure $data,
    ) {
        $this->idle = static fn (AsyncValue $v): Widget => Center::make(Text::make('尚未加载')->caption());
        $this->loading = static fn (AsyncValue $v): Widget => Center::make(
            Column::make()->spacing(12.0)->children([
                Spinner::make()->diameter(28.0),
                Text::make('加载中…')->caption(),
            ])
        );
        $this->error = static fn (AsyncValue $v): Widget => Center::make(
            Text::make($v->message() !== '' ? $v->message() : '加载失败')->error()
        );
    }

    /** @param \Closure(AsyncValue): Widget $data */
    public static function of(Readable $source, \Closure $data): self
    {
        return new self($source, $data);
    }

    /**
     * 给 error 默认支装一个"重试"按钮 —— 省掉每个异步页重抄 `Center(Column(错误+按钮))`。
     *
     * ★ 只在没显式覆写 `error()` 时生效：调用方给了定制 error 支就尊重它（分页页
     *   要在失败时保留旧行，那个形状框架猜不到）。`retryOn()` 与 `error()` 的先后
     *   顺序不敏感 —— 默认值在 `widget()` 时才定型。
     * ★ 动作是 0 参闭包（`Button::onPressed` 的约定）；读 running 态的门控由调用方
     *   在闭包外决定不了，故本按钮不自带 enabled——重试调用方自己判重入。
     *
     * @param \Closure(): void $action
     */
    public function retryOn(\Closure $action): self
    {
        $this->retry = $action;

        return $this;
    }

    /** @param \Closure(AsyncValue): Widget $builder */
    public function idle(\Closure $builder): self
    {
        $this->idle = $builder;

        return $this;
    }

    /** @param \Closure(AsyncValue): Widget $builder */
    public function loading(\Closure $builder): self
    {
        $this->loading = $builder;

        return $this;
    }

    /** @param \Closure(AsyncValue): Widget $builder */
    public function error(\Closure $builder): self
    {
        $this->error = $builder;
        $this->errorOverridden = true;

        return $this;
    }

    public function widget(): Widget
    {
        $source = $this->source;
        $idle = $this->idle;
        $loading = $this->loading;
        $data = $this->data;
        $error = $this->error;
        $retry = $this->retry;

        // 显式 error() 优先；否则 retryOn() 给了动作就用"错误 + 重试"默认支。
        if ($retry !== null && !$this->errorOverridden) {
            $error = static function (AsyncValue $v) use ($retry): Widget {
                return Center::make(Column::make()->spacing(12.0)->children([
                    ErrorPlaceholder::make($v->message() !== '' ? $v->message() : '加载失败'),
                    Button::make('重试')->onPressed($retry),
                ]));
            };
        }

        return Bind::make(static function (BuildContext $ctx) use ($source, $idle, $loading, $data, $error): Widget {
            $raw = $source->get();
            if (!$raw instanceof AsyncValue) {
                Errors::report(
                    'AsyncView 的源必须返回 AsyncValue（收到 ' . \get_debug_type($raw) . '）',
                    Errors::DIAGNOSTIC,
                );

                return Text::make('异步状态不可用')->error();
            }

            return $raw->fold([
                'idle' => $idle,
                'loading' => $loading,
                'data' => $data,
                'error' => $error,
            ]);
        });
    }
}
