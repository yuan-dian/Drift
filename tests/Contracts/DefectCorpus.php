<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Contracts;

/**
 * 缺陷题库：重建验证体系时唯一不可丢的资产。
 *
 * 每条 = 历史上真实修过的缺陷 + 把它复原到工作树的最小操作。
 * 判据不写在这里：由 run-corpus.php 对每套验证体系跑一遍矩阵，比较覆盖率。
 * V2 必须抓到 V1 抓到的每一个缺陷，才允许删 V1。
 */
final class DefectCorpus
{
    /**
     * @return list<array{
     *     id: string,
     *     from: string,
     *     note: string,
     *     op: array{kind: string, path?: string, from?: string, to?: string}
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'id'   => 'D0',
                'from' => '空对照',
                'note' => '不注入。任何判据变红都说明量具自己在假红',
                'op'   => ['kind' => 'none'],
            ],
            [
                'id'   => 'D1',
                'from' => 'S0 · 整页 golden 删页静默全绿',
                'note' => '删掉一个页面文件，冻结清单与基线都还在',
                'op'   => [
                    'kind' => 'remove-file',
                    'path' => 'apps/showcase/src/Pages/AlignPage.php',
                ],
            ],
            [
                'id'   => 'D2',
                'from' => 'S1 · 删页改名遗留的孤儿基线',
                'note' => '造一个没有对应场景的基线目录',
                'op'   => [
                    'kind' => 'add-dir',
                    'path' => 'golden/page/GhostPage',
                ],
            ],
            [
                'id'   => 'D3',
                'from' => '§43 · 语义 token 零消费者',
                'note' => '行高不再读主题，写死成旧 baseline',
                'op'   => [
                    'kind' => 'replace',
                    'path' => 'src/Widget/TableState.php',
                    'from' => '$view->rowHeightPx() ?? $ctx->metric(MetricToken::RowHeight),',
                    'to'   => '$view->rowHeightPx() ?? 44.0,',
                ],
            ],
            [
                'id'   => 'D4',
                'from' => 'S0 · 冻结清单漏登记',
                'note' => '清单里删一项，磁盘与基线都还在',
                'op'   => [
                    'kind' => 'replace',
                    'path' => 'tools/Golden/PageScenes.php',
                    'from' => "            'AlignPage',\n",
                    'to'   => '',
                ],
            ],
        ];
    }

    /**
     * 注入缺陷，返回还原闭包。锚点必须唯一，否则宁可炸也不静默测错东西。
     *
     * @param array{kind: string, path?: string, from?: string, to?: string} $op
     */
    public static function apply(array $op, string $root): \Closure
    {
        $kind = $op['kind'];
        if ($kind === 'none') {
            return static function (): void {};
        }

        $path = $root . '/' . ($op['path'] ?? '');
        $bak  = $path . '.corpus-bak';

        if (\is_file($bak) || \is_dir($bak)) {
            throw new \RuntimeException('上次注入没还原干净：' . $bak);
        }

        if ($kind === 'remove-file') {
            if (!\is_file($path)) {
                throw new \RuntimeException('找不到待删文件：' . $path);
            }
            \rename($path, $bak);

            return static function () use ($path, $bak): void {
                if (\is_file($bak)) {
                    \rename($bak, $path);
                }
            };
        }

        if ($kind === 'add-dir') {
            if (\is_dir($path)) {
                throw new \RuntimeException('待造目录已存在：' . $path);
            }
            \mkdir($path, 0777, true);

            return static function () use ($path): void {
                if (\is_dir($path)) {
                    \rmdir($path);
                }
            };
        }

        if ($kind === 'replace') {
            $src = \file_get_contents($path);
            if ($src === false) {
                throw new \RuntimeException('读不到：' . $path);
            }
            $from = (string) $op['from'];
            $to   = (string) $op['to'];
            // 工作树可能是 CRLF（core.autocrlf=true），锚点按 LF 写 ⇒ 按文件实际行尾适配。
            if (\str_contains($src, "\r\n")) {
                $from = \str_replace("\n", "\r\n", $from);
                $to   = \str_replace("\n", "\r\n", $to);
            }
            if (\substr_count($src, $from) !== 1) {
                throw new \RuntimeException('锚点不唯一（仓库漂移了？）：' . $path);
            }
            \file_put_contents($bak, $src);
            \file_put_contents($path, \str_replace($from, $to, $src));

            return static function () use ($path, $bak): void {
                if (\is_file($bak)) {
                    \file_put_contents($path, \file_get_contents($bak));
                    \unlink($bak);
                }
            };
        }

        throw new \RuntimeException('未知注入类型：' . $kind);
    }
}
