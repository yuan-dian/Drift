<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Errors;
use Drift\Geometry\EdgeInsets;
use Drift\Logging\Log;
use Drift\Logging\LogEntry;
use Drift\Logging\PatternFormatter;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Divider;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 页 20「日志 Log」—— `12` §6 P2 的 devtools 面板。
 *
 * ★ **为什么这页要有按钮写日志**：showcase 的业务现在**不写日志**（`Log` 刚建好，
 *   还没接），空面板没意义。按钮让分类 / 级别 / 过滤三件事都有可见的样本 ——
 *   这也是"日志系统能不能用"的最小演示。
 * ★ **过滤在数据层**（`RingSink::recent($category, $minLevel)`）不在这里：
 *   Widget 只负责"选了哪个过滤 + 怎么画"，查询语义属于日志系统。
 * ★ 三个过滤档是**封闭域**（全部 / WARN+ / 单分类）—— 与 `FeedbackPage` 的
 *   `StubState` 同一形状，加一档编译期就叫。
 */
final class LogPage extends StatefulWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new LogPageState();
    }
}

/** 过滤档（页内封闭域） */
enum LogFilter
{
    case All;
    case WarnUp;
    case Layout;
}

final class LogPageState extends State
{
    private LogFilter $filter = LogFilter::All;

    /** `Log` 的默认环在 `attach()` 时挂上（应用侧才挂）；这页自己再挂一个够用的 */
    private bool $primed = false;

    public function initState(): void
    {
        $this->primeSink();
    }

    /**
     * 保证有一个 `RingSink` 可读。
     *
     * ★ 不 `clearSinks()`：那会把应用挂的其他 sink（如文件）一并摘掉，
     *   而"悄悄少一个 sink"是 G2 禁止的静默降级。
     */
    private function primeSink(): void
    {
        if ($this->primed) {
            return;
        }
        $this->primed = true;
        if (Log::recent() === [] && Log::sinks() === []) {
            Log::addSink(new \Drift\Logging\RingSink());
        }
    }

    public function build(BuildContext $ctx): Widget
    {
        $this->primeSink();

        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        $entries = $this->query();

        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('日志 Log')->title(),
            Text::make('五级 + 分类门槛 + 2000 条环（`12`）。过滤在数据层做，这里只管选哪一档、怎么画。')
                ->caption(),

            // ── 过滤档 ──
            Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('全部')
                    ->variant($this->filter === LogFilter::All ? ButtonVariant::Primary : ButtonVariant::Default)
                    ->onPressed(fn() => $this->pick(LogFilter::All)),
                Button::make('WARN 以上')
                    ->variant($this->filter === LogFilter::WarnUp ? ButtonVariant::Primary : ButtonVariant::Default)
                    ->onPressed(fn() => $this->pick(LogFilter::WarnUp)),
                Button::make('layout')
                    ->variant($this->filter === LogFilter::Layout ? ButtonVariant::Primary : ButtonVariant::Default)
                    ->onPressed(fn() => $this->pick(LogFilter::Layout)),
            ]),

            // ── 现场写几条（演示分类 / 级别）──
            Text::make('现场写入')->heading(),
            Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('写 INFO')->onPressed(function (): void {
                    Log::get('layout')->info('测量完成', ['h' => 320]);
                    $this->touch();
                }),
                Button::make('写 WARN')->onPressed(function (): void {
                    Log::get('net')->warn('请求超时', ['ms' => 3000]);
                    $this->touch();
                }),
                Button::make('写 ERROR')->onPressed(function (): void {
                    Log::get('render')->error('表面创建失败');
                    $this->touch();
                }),
                Button::make('走 Errors 桥')->onPressed(function (): void {
                    // ★ 桥 ①：DIAGNOSTIC 同时落 `Errors::$ring`（测试契约）与 `Log`（WARN）
                    Errors::report('手动触发一条诊断', Errors::DIAGNOSTIC);
                    $this->touch();
                }),
            ]),

            Divider::make(),

            // ── 条目 ──
            Text::make('最近 ' . count($entries) . ' 条（共 ' . count(Log::recent()) . ' 条）')
                ->heading(),
            $entries === [] ? Text::make('还没有日志 —— 用上面的按钮写几条。')->caption() : $this->list($entries),
        ]);
    }

    /** @return list<LogEntry> */
    private function query(): array
    {
        return match ($this->filter) {
            LogFilter::All => Log::recent(),
            LogFilter::WarnUp => Log::recent(null, Log::WARN),
            LogFilter::Layout => Log::recent('layout'),
        };
    }

    /** @param list<LogEntry> $entries */
    private function list(array $entries): Widget
    {
        $fmt = PatternFormatter::compile('%T %l %c: %m %f');
        $rows = [];
        foreach ($entries as $entry) {
            // ★ `Text` 没有 `mono()`（G2：契约破坏要响亮）——日志条目用 `body()` + `size(12)` 近似等宽。
            // @capability-gap: method|Drift\Widget\Text|mono 等宽字形方法仍未补
            $rows[] = Text::make($fmt->format($entry))->size(12.0)->body();
        }

        return Column::make()->spacing(4.0)->children($rows);
    }

    private function pick(LogFilter $filter): void
    {
        $this->setState(function () use ($filter): void {
            $this->filter = $filter;
        });
    }

    /** 写完日志要重画（`Log` 是全局的，本页状态没变也得重读） */
    private function touch(): void
    {
        $this->setState(function (): void {
        });
    }
}
