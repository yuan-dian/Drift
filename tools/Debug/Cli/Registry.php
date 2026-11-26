<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\Cli;

/**
 * 调试出口注册表：`drift debug --help`、总帮助、分发、两级 `--help` 的唯一来源。
 *
 * 这里只放「是什么」（动词 / 脚本 / 一句话摘要 / 走不走 schema）。参数定义住
 * `OptionSet`，用法示例从各脚本的文档块读——三样东西各有各的归属，注册表把它们
 * 关联起来，不代替它们。
 */
final class Registry
{
    /**
     * @var array<string, Entry>|null
     */
    private static ?array $entries = null;

    /** @return array<string, Entry> */
    public static function all(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }

        $rows = [
            ['brief', 'debug-brief.php', '预算内的证据简报：哪里不对、为什么、下一步查哪', true],
            ['targets', 'debug-targets.php', '把当前目录所有活会话并列成一份视图（不跨窗口 join）', false],
            ['report', 'debug-report.php', '帧因果报告：哪些节点重建了、因为谁', true],
            ['panel', 'debug-panel.php', '同一个会话上反复追问：时间轴 + 因果树 + 原因', true],
            ['network', 'debug-network.php', '网络骨架与报文（按 requestId 取，脱敏 + 截断）', true],
            ['async', 'debug-async.php', '异步任务时间线：提交 / 派发 / 结算帧、状态与落地节点', true],
            ['tree', 'debug-tree.php', '结构树：第 N 帧那棵树（谁在、谁已消失、谁这一帧重建了）', true],
            ['tree-diff', 'debug-tree-diff.php', '两帧结构对比：谁出生、谁离开、谁在中间重建了', true],
            ['node', 'debug-node.php', '一个节点的三域对齐：因果 / 几何 / 网络', true],
            ['find', 'debug-find.php', '在证据里找：给一段文本，回一串可追问的地址', true],
            ['eval', 'debug-eval.php', 'dev 壳内省：对活宿主求任意 PHP 表达式（默认关，不是证据）', false],
            ['pick', 'debug-pick.php', '按视口坐标点选，给命中链与每层路径', true],
            ['pick-live', 'debug-pick-live.php', '布防后到真窗口点一下，回命中节点的身份与路径（live attach）', false],
            ['props', 'debug-props.php', '按节点读 Widget / State 配置', true],
            ['probe', 'debug-probe.php', 'dev 轻量内省：内存存活跨度 / 源码位置（不是证据）', true],
            ['highlight', 'debug-highlight.php', '让一个节点在屏上被描边，并回答"真的上屏了没"', true],
            ['host', 'debug-host.php', '宿主进程（被 --remote 拉起，也可手工对着 stdio 发消息）', false],
            ['serve', 'debug-serve.php', '常驻宿主：自己按脚本推帧，等人 attach 上来看', true],
            ['ctl', 'debug-ctl.php', '控制面：让自走的宿主停一下 / 走一步 / 接着跑', false],
            ['web', 'debug-web.php', '浏览器视图：真机画面 + 几何叠加 + 发现，跟着应用自动刷新', true],
            ['loop', 'debug-loop.php', '重开 N 次：证明关掉这一页再打开是等价的', true],
        ];

        $entries = [];
        foreach ($rows as [$verb, $script, $about, $driver]) {
            $entries[$verb] = new Entry($verb, $script, $about, $driver);
        }

        return self::$entries = $entries;
    }

    public static function get(string $verb): Entry
    {
        $entry = self::all()[$verb] ?? null;
        if ($entry === null) {
            throw new \InvalidArgumentException(
                '未注册的调试出口：' . $verb . '（可用：' . \implode(' / ', \array_keys(self::all())) . '）',
            );
        }

        return $entry;
    }

    /** @return list<string> 可直接拼进两份帮助；调用方决定缩进 */
    public static function lines(int $indent): array
    {
        $pad = \str_repeat(' ', $indent);
        $width = 0;
        foreach (\array_keys(self::all()) as $verb) {
            $width = \max($width, \strlen($verb));
        }

        $lines = [];
        foreach (self::all() as $verb => $entry) {
            $lines[] = $pad . \sprintf('%-' . $width . 's  %s', $verb, $entry->about);
        }

        return $lines;
    }

    /**
     * 从脚本文档块里读「用法：」段。只有示例，参数表不在这里——参数表属于 schema。
     * 没有用法段的出口（panel / host）回 null，帮助照常出，不让文档缺失变成报错。
     */
    public static function examples(Entry $entry): ?string
    {
        $src = @\file_get_contents($entry->path());
        if ($src === false || \preg_match('/\n \* (用法：(?:\n \* .*)*)/', $src, $m) !== 1) {
            return null;
        }

        $lines = [];
        foreach (\explode("\n", \rtrim($m[1])) as $line) {
            $lines[] = (string) \preg_replace('/^ \* ?/', '', $line);
        }
        \array_shift($lines); // 「用法：」这个标题由调用方写

        $lines = \array_values(\array_filter($lines, static fn(string $l): bool => \trim($l) !== ''));

        return $lines === [] ? null : \implode("\n", $lines);
    }
}
