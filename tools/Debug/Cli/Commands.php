<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\Cli;

/**
 * 各出口的特有选项，集中放在这里。
 *
 * 驱动词表不在此列——它由 {@see OptionSet::driver()} 唯一定义。
 * 集中声明的第二个用处是测试能构造任意出口的 schema，直接比"同一个词是不是同一个意思"。
 */
final class Commands
{
    /** @return array<string, list<Opt>> */
    public static function extras(): array
    {
        $slow = static fn(): Opt => Opt::float('slow', 16.7, 0.0, null, '慢帧阈值 ms');
        $fanout = static fn(): Opt => Opt::int('fanout', 100, 1, null, '扇出节点阈值');
        $repeat = static fn(): Opt => Opt::int('repeat', 50, 0, null, '重复重建阈值 %');
        // 规则选择三个词在 brief 与 web 上同义：两个出口都走 `Brief::project()`，
        // 一处能筛、一处不能筛，同一份证据就有两种看法。
        $rules = static fn(): array => [
            Opt::append('rule', '只跑这些规则（可重复，或逗号分隔）'),
            Opt::append('without', '跳过这些规则（可重复，或逗号分隔）'),
            Opt::str('min-severity', null, 'info|warning|error：低于它的发现不呈现'),
        ];

        return [
            'brief' => [
                Opt::int('budget', 40, 8, null, '文本行预算'),
                Opt::int('text-width', 100, 40, null, '排版宽度（不是视口宽度）'),
                Opt::str('from', null, '读证据包，不再驱动'),
                Opt::str('out', null, '导出证据包'),
                Opt::str('session', null, '点名一个常驻会话'),
                Opt::flag('attach', '接一个已经在跑的会话'),
                Opt::flag('rules', '列出全部规则（编号 / 严重度 / 说明）后退出'),
                Opt::str('baseline', null, '读基线文件，与当前发现对比'),
                Opt::str('save-baseline', null, '把当前发现写成基线文件（与 --baseline 互斥）'),
                Opt::flag('assert', '有确定性回归时以状态码 3 退出（需 --baseline）'),
                Opt::float('tolerance', 5.0, 0.0, null, '基线容差（百分比）'),
                $slow(), $fanout(), $repeat(), ...$rules(),
            ],
            'network' => [
                Opt::int('request', 0, 1, null, '按 requestId 取报文'),
                Opt::str('part', 'both', 'both|request|response'),
                Opt::int('limit', 50, 0, null, '骨架条数，0=不截断'),
                Opt::int('maxBytes', 8192, 0, null, '单条 body 上限，0=不截断'),
                Opt::str('from', null, '读证据包'),
                Opt::str('out', null, '导出证据包'),
            ],
            'serve' => [
                Opt::float('speed', 0.0, 0.0, null, '两次推进之间等多久（0=尽快）'),
                // 安全开关，默认关：eval 让宿主执行任意 PHP。与 loopback 是两道独立的门。
                Opt::flag('allow-eval', '允许 debug eval 在这个宿主上执行表达式（默认关）'),
            ],
            'web' => [
                Opt::str('from', null, '读证据包'),
                Opt::flag('attach', '接常驻宿主'),
                Opt::str('session', null, '点名一个常驻会话'),
                Opt::int('port', 0, 0, null, '监听端口，0=系统挑'),
                Opt::str('host', '127.0.0.1', '监听地址'),
                $slow(), $fanout(), $repeat(), ...$rules(),
            ],
            'loop' => [
                Opt::int('runs', 3, 1, null, '重开几次'),
                Opt::str('out', null, '导出第 1 轮'),
                Opt::flag('assert', '不一致时以状态码 3 退出'),
            ],
            'node' => [
                Opt::int('node', 0, 0, null, '节点 id（必填）'),
                Opt::int('frame', 0, 0, null, '帧号（必填）'),
            ],
            // `--pattern` 不叫 `--text`：后者已经是驱动词表里的"输入文本"，
            // 同名同形会让 `debug find --text=x` 有两种解释。
            'find' => [
                Opt::str('pattern', null, '要搜的字面子串（必填，不做正则）'),
                Opt::str('domain', 'all', 'all|node|request'),
                Opt::int('limit', 50, 1, null, '最多给多少条命中'),
                Opt::flag('case-sensitive', '区分大小写（默认不区分）'),
                Opt::str('from', null, '读证据包，不再驱动'),
            ],
            'pick' => [
                Opt::float('x', 0.0, null, null, '触点 x'),
                Opt::float('y', 0.0, null, null, '触点 y'),
                Opt::int('node', 0, 0, null, '节点 id'),
            ],
            'props' => [
                Opt::int('node', 0, 0, null, '节点 id'),
                Opt::int('frame', 0, 0, null, '帧号'),
                Opt::int('maxDepth', 3, 0, null, '属性树深度'),
                Opt::int('maxFields', 120, 1, null, '每层字段数'),
            ],
            'probe' => [
                Opt::str('what', 'memory', 'memory|source：看内存存活跨度还是源码位置'),
                Opt::str('source', null, 'source 视图的调用点字符串（file:line）'),
                Opt::int('limit', 40, 1, null, 'memory 视图最多列几个类型'),
            ],
            'highlight' => [
                // 必填由出口自己判：`0` 是合法值（清除），缺省值没法同时表达"没给"。
                // `--remote` / `--json` 不在这里 —— 它们是驱动词表里的通用词。
                Opt::int('node', 0, 0, null, '节点 id（0 = 清除）'),
            ],
            'report' => [
                Opt::flag('verify', '验证模式'),
                Opt::int('top', 10, 1, null, 'top N'),
                Opt::int('depth', 6, 1, null, '展开深度'),
                Opt::int('why', 0, null, null, '解释第几条发现'),
                Opt::int('frame', 0, 0, null, '帧号'),
                Opt::str('save', null, '保存脚本'),
                Opt::str('replay', null, '读回脚本'),
            ],
            'panel' => [
                Opt::flag('shots', '截图模式'),
                Opt::str('replay', null, '读回脚本'),
                Opt::str('keys', null, '按键序列'),
                Opt::int('frame', 0, 0, null, '帧号'),
                Opt::str('screen', null, '终端屏幕 宽x高'),
                Opt::str('save', null, '面板不支持，留在 schema 里为了给专门提示'),
            ],
        ];
    }

    public static function set(string $command): OptionSet
    {
        return OptionSet::for($command, self::extras()[$command] ?? []);
    }

    /**
     * 两级 `--help` 共用这一段：出口自己问、从 `drift debug <verb> --help` 问，
     * 拿到的必须是同一串字节。参数表来自 schema，示例来自脚本文档块。
     */
    public static function helpFor(string $verb): string
    {
        $entry = Registry::get($verb);
        $examples = Registry::examples($entry);

        if (!$entry->driver) {
            return $examples === null
                ? "（{$entry->script} 的文档块里没有「用法：」段）\n"
                : "用法：\n{$examples}\n";
        }

        return self::set($verb)->help()
            . ($examples === null ? '' : "\n示例：\n{$examples}\n");
    }

    /** @param list<string> $argv 含脚本名 */
    public static function parseOrExit(string $command, array $argv): Parsed
    {
        if (\in_array('-h', $argv, true)) {
            $argv = \array_values(\array_filter($argv, static fn(string $a): bool => $a !== '-h'));
            $argv[] = '--help';
        }
        try {
            $parsed = self::set($command)->parse($argv);
            if ($parsed->bool('help')) {
                \fwrite(\STDOUT, self::helpFor($command));
                exit(0);
            }

            return $parsed;
        } catch (CliError $e) {
            \fwrite(\STDERR, $e->getMessage() . "\n");
            exit(2);
        }
    }
}
