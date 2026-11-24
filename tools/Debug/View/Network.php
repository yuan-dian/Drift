<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 网络视图：把查询面给出的骨架与报文压成可读文本。
 *
 * ## 它不采集、不脱敏、不判断
 *
 * 输入只有 `network()` / `networkBody()` 的返回；脱敏与截断是查询层的事，
 * 这里只负责如实展示查询层给的元信息（redacted / truncated / window）。
 * 视图若自己再改一个字，"同一份证据不同消费者结论一致"就断了。
 *
 * ## 两条纪律
 *
 * 1. **纯函数**：同样的 answer 必须给出逐字节相同的行；无时间戳、无随机。
 * 2. **自报裁剪**：列表只列了 N 条 / 窗口多少，必须写出来。读者会把
 *    "我没看到"当成"不存在"，而命令本身看不出来。
 *
 * @internal 证据层视图；`tools/debug-network.php` 是它唯一的调用方
 */
final class Network
{
    /**
     * 骨架列表：表头 + 每行一条请求，末行报窗口与裁剪。
     *
     * @param array<string, mixed> $answer `network()` 的返回
     * @return list<string>
     */
    public static function skeleton(array $answer): array
    {
        $window = $answer['window'] ?? [];
        $total = (int) ($answer['total'] ?? 0);
        $rows = $answer['rows'] ?? [];
        $shown = \count($rows);
        $lines = [\sprintf(
            '请求骨架  %d 条（窗口 %d..%d / 容量 %d）%s',
            $total,
            (int) ($window['from'] ?? 0),
            (int) ($window['to'] ?? 0),
            (int) ($window['capacity'] ?? 0),
            $shown < $total ? "，本次只列 {$shown} 条" : '',
        )];

        if ($shown === 0) {
            $lines[] = '  （无请求；可用 --request=N 查具体报文）';

            return $lines;
        }

        $lines[] = '  ID  方法   状态  字节   耗时     帧      路径、来源';
        foreach ($rows as $row) {
            $status = (int) ($row['status'] ?? 0);
            $lines[] = \sprintf(
                '  #%-3d %-6s %-4s %-6d %-7.1f %d→%d  %s %s',
                (int) ($row['request'] ?? 0),
                (string) ($row['method'] ?? ''),
                $status === 0 ? 'ERR' : (string) $status,
                (int) ($row['bytes'] ?? 0),
                (float) ($row['elapsedMs'] ?? 0.0),
                (int) ($row['startedAtFrame'] ?? 0),
                (int) ($row['settledAtFrame'] ?? 0),
                (string) ($row['path'] ?? ''),
                (string) ($row['initiator'] ?? ''),
            );
        }

        return $lines;
    }

    /**
     * 一条报文：先报元信息（格式 / 字节 / 脱敏 / 截断），再逐行打印文本。
     * 查不到时把 miss 原因与窗口一起说，不静默空输出。
     *
     * @param array<string, mixed> $answer `networkBody()` 的返回
     * @return list<string>
     */
    public static function body(array $answer): array
    {
        $request = (int) ($answer['request'] ?? 0);
        if (($answer['found'] ?? false) !== true) {
            $window = $answer['window'] ?? [];

            return [\sprintf(
                '请求 #%d 查不到：%s（报文窗口 %d..%d / 容量 %d，已存 %d 条）',
                $request,
                (string) ($answer['miss'] ?? 'unknown'),
                (int) ($window['from'] ?? 0),
                (int) ($window['to'] ?? 0),
                (int) ($window['capacity'] ?? 0),
                (int) ($window['requests'] ?? 0),
            )];
        }

        // 关掉脱敏时标题必须说清"含明文"：`redacted: 0` 与"没扫过"是两件事，
        // 只报数字会让读者把明文当成"已检查且干净"。
        $plain = ($answer['requestMeta']['redact'] ?? true) === false
            || ($answer['responseMeta']['redact'] ?? true) === false;
        $lines = [
            "请求 #{$request} 报文（"
            . ($plain ? '含明文，未脱敏' : '已脱敏')
            . '；只看这一条，不进证据包）',
        ];
        foreach (['request' => '请求', 'response' => '响应'] as $key => $name) {
            if (!\array_key_exists($key . 'Body', $answer)) {
                continue;
            }
            /** @var array{bytes: int, truncated: bool, redacted: int, redact: bool, format: string} $meta */
            $meta = $answer[$key . 'Meta'] ?? [];
            $lines[] = \sprintf(
                '  %s  %s  %d 字节  %s  %s',
                $name,
                (string) ($meta['format'] ?? 'text'),
                (int) ($meta['bytes'] ?? 0),
                ($meta['redact'] ?? true) === false
                    ? '未脱敏（明文）'
                    : '脱敏 ' . (int) ($meta['redacted'] ?? 0) . ' 处',
                ($meta['truncated'] ?? false) === true ? '已截断' : '完整',
            );
            foreach (\explode("\n", (string) ($answer[$key . 'Body'] ?? '')) as $line) {
                $lines[] = "    {$line}";
            }
        }

        return $lines;
    }
}
