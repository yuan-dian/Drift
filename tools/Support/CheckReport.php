<?php
declare(strict_types=1);

namespace Drift\Dev\Support;

/**
 * 一份判据报告 —— **文本与人读、JSON 与机器读，都从同一个 `$items` 渲染**。
 *
 * ```php
 * $r = new CheckReport('frame-tax', $items, '6 项判据全过（500 行 / 30 帧）');
 * $r->emit(in_array('--json', $argv, true));   // 逐条 + 收尾 + exit
 * ```
 *
 * ★ 为什么不是"给每个工具加个 `--json` 分支"（2026-11-03）：
 *   那样文本与 JSON 是**两段代码**，今天一致、下个月必漂；而 agent 读的是 JSON、
 *   人读的是文本 ⇒ 漂了以后**两边都看不出**。本仓已经栽过两次同形的坑
 *   （§15 九段两个账本、§16 活跃表与全扫的标记位）。
 *   这里把"要说什么"（`$items`）与"怎么说"（`text()` / `json()`）分开：
 *   加一种输出格式不会碰到判据，加一条判据不会碰到格式。
 *
 * ★ 三条构造期不变量（都是"静默绿壳"的形状，必须在构造时炸）：
 *   ① `$items` 不能为空 —— "0 项全过"是没有扫描的假绿；
 *   ② `$id` 不能重复 —— 同一件事被报两遍（§16.3 那处多余的 `requestAnimationScan()`）；
 *   ③ `$tool` 不能为空 —— 否则 JSON 里读不出是哪份报告。
 */
final class CheckReport
{
    /**
     * @param string            $tool    工具标识（`frame-tax` / `gate` / `golden`）
     * @param list<CheckItem>   $items
     * @param string            $summary 全过时印在收尾那一行**括号里的**那句话。
     *        失败时不用它 —— 收尾会自动变成 `--- FAIL（N 条）---`，
     *        否则"全过"与"失败"两句话可能同时出现（本仓踩过的绿壳）。
     */
    public function __construct(
        public readonly string $tool,
        private readonly array $items,
        private readonly string $summary,
    ) {
        if ($tool === '') {
            throw new \InvalidArgumentException('CheckReport：$tool 不能为空');
        }
        if ($items === []) {
            throw new \InvalidArgumentException(sprintf(
                'CheckReport(%s)：判据列表为空 —— "0 项全过"是没有扫描的假绿',
                $tool,
            ));
        }

        $seen = [];
        foreach ($items as $i => $item) {
            if (!$item instanceof CheckItem) {
                throw new \InvalidArgumentException(sprintf(
                    'CheckReport(%s)：第 %d 项不是 CheckItem（拿了 %s）',
                    $tool,
                    $i,
                    get_debug_type($item),
                ));
            }
            if (isset($seen[$item->id])) {
                throw new \InvalidArgumentException(sprintf(
                    'CheckReport(%s)：判据 id 重复 "%s" —— 同一件事报两遍',
                    $tool,
                    $item->id,
                ));
            }
            $seen[$item->id] = true;
        }
    }

    /** @return list<CheckItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function ok(): bool
    {
        return $this->failures() === [];
    }

    /** @return list<CheckItem> */
    public function failures(): array
    {
        return array_values(array_filter($this->items, static fn(CheckItem $i): bool => !$i->ok));
    }

    /** @return list<CheckItem> 没测到的项（不是失败，但也**不是通过**） */
    public function skipped(): array
    {
        return array_values(array_filter($this->items, static fn(CheckItem $i): bool => $i->skipped));
    }

    /** 进程退出码。★ 唯一来源 —— 调用方不许自己再算一次。 */
    public function exitCode(): int
    {
        return $this->ok() ? 0 : 1;
    }

    /**
     * 单条渲染 —— **一种格式只有一个函数**。
     *
     * ★ 为什么是 static：`golden` 有 60 个场景、跑完要 6s，它必须**边跑边打**
     *   （否则人以为卡死）。如果它自己拼一行、`text()` 又拼一行，就是第二份格式；
     *   这里给它一个可单独调用的渲染函数，两边逐字同源。
     */
    public static function line(CheckItem $item): string
    {
        // ★ 显示用大写 `FAIL`：它是全屏里最需要一眼看到的那一类，且与既有输出一致。
        //   JSON 里仍是小写 `fail`（`status()`）—— 机器读的东西不该带表演性。
        $label = $item->status() === 'fail' ? 'FAIL' : $item->status();
        $line = sprintf('  %-4s  %s', $label, $item->id);

        return $item->text === '' ? $line : $line . ' ' . $item->text;
    }

    /**
     * 即时打印一条（`--json` 时什么也不做）。
     *
     * ★ 给"边跑边打"的调用方（`golden` 60 个场景）：它们**不能**等到最后才印，
     *   也不能自己拼行 —— 这个函数是 `line()` 的带门版本，两处逐字同源。
     */
    public static function emitLine(CheckItem $item, bool $asJson = false): void
    {
        if (!$asJson) {
            echo self::line($item), "\n";
        }
    }

    /** 逐条正文（不含收尾那一行）。跳过印 `skip`，不许印成 `ok`。 */
    public function text(): string
    {
        $out = '';
        foreach ($this->items as $item) {
            $out .= self::line($item) . "\n";
        }

        return $out;
    }

    /**
     * 收尾那一行（带前导换行，与旧格式逐字一致）。全过与失败**互斥**。
     *
     * ★ 失败时带总数：`--- FAIL（11 / 471 条）---`。只有失败数的话，"红了几条"在
     *   471 行的 smoke 日志里等于没信息 —— 得自己数正文。
     */
    public function summaryLine(): string
    {
        if ($this->ok()) {
            return sprintf("\n--- %s ---\n", $this->summary);
        }

        return sprintf("\n--- FAIL（%d / %d 条）---\n", count($this->failures()), count($this->items));
    }

    /**
     * 机器可读：**一个** JSON 文档（紧凑，无缩进）。`--json` 时 stdout 上只有它。
     *
     * ★ 不加 `JSON_PRETTY_PRINT`：agent 用 `json_decode` 吞，缩进只费字节。
     */
    public function json(): string
    {
        return (string) json_encode(
            [
                'tool' => $this->tool,
                'ok' => $this->ok(),
                'summary' => $this->ok() ? $this->summary : sprintf('%d 条判据未通过', count($this->failures())),
                'items' => array_map(static fn(CheckItem $i): array => $i->toArray(), $this->items),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * 输出并退出。`$asJson` 时 stdout 上**只有 JSON**（正文与收尾都不印）——
     * agent 的 `2>&1 | json` 与 `stdout | json` 必须给同一个结果，否则它得先猜。
     *
     * `$echoText = false`：调用方已经**边跑边印**过每一条（`golden` 有 60 个场景，
     * 跑完才印等于 6 秒无输出，人以为卡死）。此时只补收尾那一行 —— 条目格式仍然
     * 出自 `line()` 这一份，不产生第二套。
     */
    public function emit(bool $asJson = false, bool $echoText = true): void
    {
        if ($asJson) {
            echo $this->json(), "\n";
            exit($this->exitCode());
        }

        if ($echoText) {
            echo $this->text();
        }
        if ($this->ok()) {
            echo $this->summaryLine();
        } else {
            fwrite(STDERR, $this->summaryLine());
        }

        exit($this->exitCode());
    }
}
