<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Core\Readable;
use Drift\State\Subject;

/**
 * 夹具：`dead-subscription` 的两个射程维度（§16.6.3 #4 / A16）。
 *
 * 该响（error ×2）：
 * - 声明位置：类体属性 vs 构造器提升参数（`__construct(private SessionSignal $s)`）
 *
 * 不该响：
 * - `ReadsReadableSignalNotReported`：读的是 `Readable`（`get()` 自己记账）
 * - `ReadsSilentSignalInCallback`：读发生在事件回调里（延迟发生，不是 build 期）
 *
 * ★ 射程 = **非 `Readable` 的 `Subject`**。原判据是"读 `Subject`/`Cell` ⇒ 必须
 *   implements `SubscribingWidget`"，那是个做不到的承诺：`watch()` 是
 *   `State::watch()`（`protected`），`StatelessWidget` 标了接口也没有订阅袋；
 *   而 `Cell` 的读点本来就由最近的 `StatefulElement` 采集（2026-11-24 收窄）。
 * ★ 因此 `SessionSignal` 是刻意的**非 `Readable`** 形态：`Subject` 的快照方法
 *   不记账，在 build 里读它就是"读了但没订"。
 */
final class SessionSignal extends Subject
{
    public function isOpen(): bool
    {
        return false;
    }
}

/** 同一家族里接上 `Readable` 的那一支 —— 差别只在"读点自己会 `Tracker::record()`"。 */
final class ReadableSignal extends Subject implements Readable
{
    public function isOpen(): bool
    {
        return false;
    }

    public function get(): mixed
    {
        return null;
    }
}

final class ReadsSilentSignal extends StatelessWidget
{
    private SessionSignal $session;

    public function build(): Widget
    {
        return Text::make($this->session->isOpen() ? 'open' : 'closed');
    }
}

final class ReadsSilentSignalPromoted extends StatelessWidget
{
    public function __construct(private readonly SessionSignal $session)
    {
        parent::__construct();
    }

    public function build(): Widget
    {
        return Text::make($this->session->isOpen() ? 'open' : 'closed');
    }
}

/**
 * 读**实现了 `Readable`** 的可观察值 ⇒ **不该响**：`get()` 自己 `Tracker::record()`，
 * 读点由最近的 `StatefulElement` 采集帧建边 —— 界面照常更新，只是重建面比 `Bind` 宽。
 *
 * ★ 这条钉的是**射程边界**：把 `silentSubjects()` 里的 `Readable` 过滤删掉，它立刻变红。
 *   没有它，"为什么 `Cell` / `AsyncCommand` 不判"就只是注释里的一句话。
 * ★ 为什么夹具里不用真的 `Cell`：夹具项目只扫本目录，`src/State/Cell.php` 不在类表里
 *   ⇒ 它的 `implements Readable` 看不见（当年能判 `Cell` 是因为 `Cell` 被硬编码成种子）。
 *   而 `Readable` 是**按名字**解析的（`descendsFrom` 走 `implements` 列表）⇒ 这个最小
 *   代表就够；真实 `Cell` / `AsyncCommand` 的 `implements Readable` 由它们自己的测试守。
 */
final class ReadsReadableSignalNotReported extends StatelessWidget
{
    private ReadableSignal $session;

    public function build(): Widget
    {
        return Text::make($this->session->isOpen() ? 'open' : 'closed');
    }
}

/**
 * 读发生在事件回调里 ⇒ 不在 build 期，**不该响**。
 *
 * 钉住 `closureEnd()` 的闭包边界：跳过逻辑一旦失效，这里会多报一条。
 * 读的类型必须在射程内，否则这条夹具就算失效也不会红
 * —— 2026-11-24 从 `Cell` 换成 `SessionSignal` 正是为此。
 */
final class ReadsSilentSignalInCallback extends StatelessWidget
{
    private SessionSignal $session;

    public function build(): Widget
    {
        return Button::make('重置')->onPressed(fn() => $this->session->isOpen());
    }
}
