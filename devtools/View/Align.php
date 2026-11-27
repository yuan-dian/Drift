<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

/**
 * 跨域视图：把 `align()` 的三域事实并排成可读文本，并按规则说一句发现。
 *
 * ## 与查询层的分工
 *
 * `align()` 只给事实（每域 found / 实际帧号 / 窗口）。"这算不算问题"在这里判：
 * 与 `Brief` 同形——规则确定、可复算、指回可复核的地址，不冒充 bug。
 * 视图不采集、不改查询层的任何一个字段。
 *
 * ## 三条纪律
 *
 * 1. **纯函数**：同样的 answer 逐字节相同；无时间戳、无随机。
 * 2. **stale 必须显眼**：几何回退到旧帧时印出真实帧号并标 `stale`，
 *    不许把上一帧的快照说成当前帧的事实。
 * 3. **缺就说缺**：三域各自报 miss 原因，不用空数组冒充"没问题"。
 *
 * @internal 证据层视图；`devtools/bin/debug-node.php` 是它唯一的调用方
 */
final class Align
{
    /**
     * @param array<string, mixed> $answer `align()` 的返回
     * @return list<string>
     */
    public static function lines(array $answer): array
    {
        $frame = (int) ($answer['frame'] ?? 0);
        $node = (int) ($answer['node'] ?? 0);
        $lines = [
            \sprintf('节点 #%d  %s  @帧 %d', $node, (string) ($answer['path'] ?? ''), $frame),
        ];
        if (($answer['found'] ?? false) !== true) {
            $lines[] = '  三域都没有这个地址：' . (string) ($answer['reason'] ?? 'unknown');

            return $lines;
        }

        // ---- 因果
        $causal = $answer['causal'] ?? [];
        if (($causal['found'] ?? false) === true) {
            $lines[] = \sprintf(
                '  因果  @%d  %s%s',
                (int) ($causal['frame'] ?? 0),
                ($causal['rebuilt'] ?? false) === true ? '本帧重建' : '本帧存在但未重建',
                $causal['causes'] === [] ? '' : '，' . \count($causal['causes']) . ' 条因果边',
            );
        } else {
            $lines[] = '  因果  缺：' . (string) ($causal['reason'] ?? 'unknown');
        }

        // ---- 几何：stale 时把真实帧号印出来，这是 S4 的头号红线。
        $geo = $answer['geometry'] ?? [];
        if (($geo['found'] ?? false) === true) {
            $size = $geo['size'] ?? [];
            $stale = ($geo['stale'] ?? false) === true;
            $lines[] = \sprintf(
                '  几何  @%d%s  %sx%s',
                (int) ($geo['frame'] ?? 0),
                $stale ? '  ← 陈旧：本帧没重新采样，这是上一次的快照' : '',
                self::num($size['w'] ?? null),
                self::num($size['h'] ?? null),
            );
        } else {
            $lines[] = '  几何  缺：' . (string) ($geo['reason'] ?? 'unknown');
        }

        // ---- 网络
        $network = $answer['network'] ?? [];
        if (($network['found'] ?? false) === true) {
            $parts = [];
            foreach ($network['requests'] ?? [] as $one) {
                $parts[] = \sprintf('#%d %s %s(%s)',
                    (int) ($one['request'] ?? 0), (string) ($one['method'] ?? ''),
                    (string) ($one['url'] ?? ''), (string) ($one['status'] ?? 0));
            }
            $lines[] = '  网络  @' . (int) ($network['frame'] ?? $frame) . '  ' . \implode('；', $parts);
        } else {
            $lines[] = '  网络  缺：' . (string) ($network['reason'] ?? 'unknown');
        }

        // ---- 窗口自报：三个域的窗口不一样，不能让读者以为全景都在。
        $windows = $answer['windows'] ?? [];
        $lines[] = \sprintf(
            '  窗口  帧 %s / 几何 %s（%d 帧）/ 网络 %s（%d 条）',
            self::num($windows['frames'] ?? null),
            self::num($windows['geometry']['from'] ?? null) . '..' . self::num($windows['geometry']['to'] ?? null),
            (int) ($windows['geometry']['capacity'] ?? 0),
            self::num($windows['network']['from'] ?? null) . '..' . self::num($windows['network']['to'] ?? null),
            (int) ($windows['network']['capacity'] ?? 0),
        );

        $finding = self::finding($answer);
        if ($finding !== null) {
            $lines[] = '  → ' . $finding;
        }

        return $lines;
    }

    /**
     * 一条可复算的发现：重建了、几何没变、也没有网络归因 ⇒ 指向父传播。
     *
     * 只说"指向"，不说"就是"：`parent` 边是否存在还要看 `why` 的 causes，
     * 而这里只拿到计数。给出复核地址，让读者自己确认。
     *
     * @param array<string, mixed> $answer
     */
    private static function finding(array $answer): ?string
    {
        $causal = $answer['causal'] ?? [];
        $geo = $answer['geometry'] ?? [];
        $network = $answer['network'] ?? [];
        if (($causal['found'] ?? false) !== true || ($causal['rebuilt'] ?? false) !== true) {
            return null;
        }
        if (($geo['found'] ?? false) !== true || ($geo['stale'] ?? false) !== true) {
            return null;
        }
        if (($network['found'] ?? false) === true) {
            return null;
        }

        return '本帧重建、几何仍是上一次的快照、无请求落地：'
            . '优先查 why(' . (int) ($answer['node'] ?? 0) . ', ' . (int) ($answer['frame'] ?? 0)
            . ') 的父边是否只是传播';
    }

    private static function num(mixed $value): string
    {
        return \is_numeric($value) ? (string) $value : '—';
    }
}
