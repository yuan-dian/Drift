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
     *
     * ★★ 编码边界（2026-11-12，CI 上炸出来的）：`gate` 把子进程的 stdout/stderr
     *   **原始字节**塞进 `items[].data` —— Windows 本地代码页（GBK / cp1252）、
     *   被截断的多字节串、工具链的本地化报错都可能不是合法 UTF-8。`json_encode`
     *   默认遇非法字节直接抛 `JsonException: Malformed UTF-8` ⇒ 整份报告没了，
     *   CI 只看到退出码 255，**看不到到底是哪条判据失败**。文本模式不序列化，
     *   所以本地只跑 `--fast` 时永远绿 —— 这条路径以前根本没人验过。
     *
     *   两件事一起做，缺一不可：
     *   ① `JSON_INVALID_UTF8_SUBSTITUTE` ⇒ 绝不再因编码崩掉（非法字节换 U+FFFD）；
     *   ② 递归扫出非法字段路径，写进顶层 `encoding.nonUtf8Fields` ⇒ **不静默**。
     *      否则报告能出，但"这段输出被动过手脚"没人知道 —— 正是本项目最忌的
     *      「不报错、只是没效果」。要看原始现场就单独跑那条判据（文本模式原样转发）。
     *
     *   ★ 只放一处：所有 `--json` 工具（gate / golden / frame-tax / m2 / rva2sym）
     *     共用这一个出口，别在每个采集点各修一遍。不猜编码（把 GBK 还原成 UTF-8
     *     会把 cp1252 的半个字节拼成汉字，反而误导），也不依赖 mbstring。
     */
    public function json(): string
    {
        $payload = [
            'tool' => $this->tool,
            'ok' => $this->ok(),
            'summary' => $this->ok() ? $this->summary : sprintf('%d 条判据未通过', count($this->failures())),
            'items' => array_map(static fn(CheckItem $i): array => $i->toArray(), $this->items),
        ];

        $bad = [];
        self::scanUtf8($payload, '', $bad);
        if ($bad !== []) {
            $payload['encoding'] = [
                'substituted' => true,
                'nonUtf8Fields' => $bad,
                'note' => '这些字段含非法 UTF-8 字节，已按 U+FFFD 替换；原始现场请单独跑该条判据（文本模式不替换）',
            ];
        }

        return (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * 递归收集「不是合法 UTF-8 的字符串」的字段路径。
     *
     * ★ `preg_match('//u', $s)` 是 PCRE 对整串做 UTF-8 有效性校验：合法返 1、
     *   非法返 false（不发 warning）。这里只负责**标不标**，替换交给 `json_encode`
     *   的 C 实现 —— 两边不各写一套 UTF-8 状态机。
     *
     * @param list<string> $bad
     */
    private static function scanUtf8(mixed $value, string $path, array &$bad): void
    {
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                $bad[] = $path;
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                self::scanUtf8($v, $path === '' ? (string) $k : $path . '.' . $k, $bad);
            }
        }
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
