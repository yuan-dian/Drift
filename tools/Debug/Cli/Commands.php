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

        return [
            'brief' => [
                Opt::int('budget', 40, 8, null, '文本行预算'),
                Opt::int('text-width', 100, 40, null, '排版宽度（不是视口宽度）'),
                Opt::str('from', null, '读证据包，不再驱动'),
                Opt::str('out', null, '导出证据包'),
                Opt::str('session', null, '点名一个常驻会话'),
                Opt::flag('attach', '接一个已经在跑的会话'),
                $slow(), $fanout(), $repeat(),
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
            ],
            'web' => [
                Opt::str('from', null, '读证据包'),
                Opt::flag('attach', '接常驻宿主'),
                Opt::str('session', null, '点名一个常驻会话'),
                Opt::int('port', 0, 0, null, '监听端口，0=系统挑'),
                Opt::str('host', '127.0.0.1', '监听地址'),
                $slow(), $fanout(), $repeat(),
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
