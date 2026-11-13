<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Contracts;

/**
 * 缺陷题库：重建验证体系时唯一不可丢的资产。
 *
 * 每条 = 历史上真实修过的缺陷 + 把它复原到工作树的最小操作。
 * 判据不写在这里：由 run-corpus.php 对每套验证体系跑一遍矩阵，比较覆盖率。
 * V2 必须抓到 V1 抓到的每一个缺陷，才允许删 V1。
 *
 * 两条纪律：
 *   1. `aot === false` 的条目**必须**至少被一条通道抓到 —— 抓不到就是假盲区，
 *      要么补通道，要么说明它本来就不是"可被解释器侧发现"的缺陷。
 *   2. `aot === true` 的条目是**已知缺口**（AOT 产物才炸，解释器全绿），
 *      如实记录但不计入覆盖率分母，否则 S3 的对比会自己骗自己。
 */
final class DefectCorpus
{
    /**
     * @return list<array{
     *     id: string,
     *     class: string,
     *     from: string,
     *     note: string,
     *     aot?: bool,
     *     op: array{kind: string, path?: string, from?: string, to?: string, edits?: list<array{from: string, to: string}>}
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'id'    => 'D0',
                'class' => 'control',
                'from'  => '空对照',
                'note'  => '不注入。任何通道变红都说明量具自己在假红',
                'op'    => ['kind' => 'none'],
            ],

            // ── 静态类：lint 用文本扫描模拟编译期保证 ──────────────────────
            [
                'id'    => 'L1',
                'class' => 'static',
                'from'  => 'A17 · 编译期注解漏全限定名（#[\X]）',
                'note'  => '`#[\Override]` 少写反斜杠 ⇒ 注解静默无效，覆写不再被校验',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Layout/RenderButton.php',
                    'from' => "    #[\\Override]\n    public function outlineRadius(): float\n",
                    'to'   => "    #[Override]\n    public function outlineRadius(): float\n",
                ],
            ],
            [
                'id'    => 'L2',
                'class' => 'static',
                'from'  => 'G1 · namespace 与 PSR-4 路径不符',
                'note'  => '命名空间少一个字母 ⇒ 自动加载找不到类，只在真机炸',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Geometry/Scale.php',
                    'from' => 'namespace Drift\\Geometry;',
                    'to'   => 'namespace Drift\\Geometri;',
                ],
            ],
            [
                'id'    => 'L3',
                'class' => 'static',
                'from'  => '§19 · 手写「平移再画子盒」替代 paintChildAt',
                'note'  => '四行手抄，写反/漏 translate 时栈仍配平、整帧不报错',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Layout/RenderDock.php',
                    'from' => "            \$this->paintChildAt(\$canvas, \$box);\n",
                    'to'   => "            \$canvas->save();\n"
                        . "            \$canvas->translate(\$box->offsetX(), \$box->offsetY());\n"
                        . "            \$box->paintComposed(\$canvas);\n"
                        . "            \$canvas->restore();\n",
                ],
            ],
            [
                'id'    => 'L4',
                'class' => 'static',
                'from'  => 'G1 · new 的短名类不在本文件可见',
                'note'  => '`new Size(` 写成 `new Paint(`（Rendering 的类，本文件没 use）⇒ 运行期 class not found',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Geometry/Rect.php',
                    'from' => 'return new Size($this->width, $this->height);',
                    'to'   => 'return new Paint($this->width, $this->height);',
                ],
            ],
            [
                'id'    => 'L5',
                'class' => 'static',
                'from'  => 'G2 · 变量名含非 ASCII 字节',
                'note'  => '`$logical` 改名成 `$逻辑` ⇒ 双引号插值会把中文吃进变量名',
                'op'    => [
                    'kind' => 'replace-all',
                    'path' => 'src/Geometry/Scale.php',
                    'from' => '$logical',
                    'to'   => '$逻辑',
                ],
            ],

            [
                'id'    => 'L6',
                'class' => 'doc',
                'from'  => 'apidoc · 新增 @api 但清单没重生成',
                'note'  => '`API-SURFACE.md` 抬头写着「自动生成、勿手改」，却没有东西检查它是新的',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Geometry/Scale.php',
                    'from' => "    public function isIdentity(): bool\n    {\n        return \$this->dpr === 1.0;\n    }\n",
                    'to'   => "    public function isIdentity(): bool\n    {\n        return \$this->dpr === 1.0;\n    }\n\n"
                        . "    /**\n     * 语料注入用的探针方法（新增 @api 却不重生成清单）。\n     *\n     * @api\n     */\n"
                        . "    public function corpusProbe(): float\n    {\n        return 0.0;\n    }\n",
                ],
            ],

            // ── 文档类：docref 守「引用指向的东西存在吗」 ──────────────────
            [
                'id'    => 'R1',
                'class' => 'doc',
                'from'  => 'docref · markdown 相对链接死链',
                'note'  => '文档里链到不存在的分册 ⇒ 读者点进去 404，没有任何东西会报错',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'DESIGN.md',
                    'from' => '> 历史裁决与探针证据见 [`docs/_archive/DESIGN-v2-2026-10-14.md`](docs/_archive/DESIGN-v2-2026-10-14.md)。',
                    'to'   => "> 历史裁决与探针证据见 [`docs/_archive/DESIGN-v2-2026-10-14.md`](docs/_archive/DESIGN-v2-2026-10-14.md)。\n"
                        . ">\n> 语料注入：[死链](docs/NOPE-CORPUS.md)。",
                ],
            ],
            [
                'id'    => 'R2',
                'class' => 'doc',
                'from'  => 'docref · 跨册 § 引用指向不存在的小节',
                'note'  => '`x.md` §99.9` ⇒ 裁决依据指向虚无',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'DESIGN.md',
                    'from' => '**当前定位**：',
                    'to'   => "**当前定位**（见 `docs/AOT-CONSTRAINTS.md` §99.9）：",
                ],
            ],
            [
                'id'    => 'R3',
                'class' => 'doc',
                'from'  => 'docref · 项目名写成目录名',
                'note'  => '正文把项目写成 `Drift2` ⇒ 文档与代码两个名字，搜索与引用都会漂',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'DESIGN.md',
                    'from' => '**当前定位**：`v0.1.0-preview.1`',
                    'to'   => '**当前定位**（Drift2）：`v0.1.0-preview.1`',
                ],
            ],

            // ── 集合类：S0/S1 的冻结清单与孤儿基线 ────────────────────────
            [
                'id'    => 'D1',
                'class' => 'set',
                'from'  => 'S0 · 整页 golden 删页静默全绿',
                'note'  => '删掉一个页面文件，冻结清单与基线都还在',
                'op'    => [
                    'kind' => 'remove-file',
                    'path' => 'apps/showcase/src/Pages/AlignPage.php',
                ],
            ],
            [
                'id'    => 'D2',
                'class' => 'set',
                'from'  => 'S1 · 删页改名遗留的孤儿基线',
                'note'  => '造一个没有对应场景的基线目录',
                'op'    => [
                    'kind' => 'add-dir',
                    'path' => 'golden/page/GhostPage',
                ],
            ],
            [
                'id'    => 'D3',
                'class' => 'set',
                'from'  => 'S0 · 冻结清单漏登记',
                'note'  => '清单里删一项，磁盘与基线都还在',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'tools/Golden/PageScenes.php',
                    'from' => "            'AlignPage',\n",
                    'to'   => '',
                ],
            ],

            // ── 像素类：整页基线的独立路径 ────────────────────────────────
            [
                'id'    => 'G1',
                'class' => 'pixel',
                'from'  => '§40 · 主题色值漂一档',
                'note'  => 'Accent 的 B 通道 +1 ⇒ 只有真把 RGBA 算出来的判据看得见',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Rendering/ColorToken.php',
                    'from' => 'self::Accent  => Color::fromRgb(0x09, 0x69, 0xDA),',
                    'to'   => 'self::Accent  => Color::fromRgb(0x09, 0x69, 0xDB),',
                ],
            ],
            [
                'id'    => 'G2',
                'class' => 'pixel',
                'from'  => '§43 · 度量 token 改一档',
                'note'  => '行高 44 → 40 ⇒ 布局整体位移，像素基线必须报红',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Rendering/MetricToken.php',
                    'from' => 'self::RowHeight       => 44.0,',
                    'to'   => 'self::RowHeight       => 40.0,',
                ],
            ],

            // ── 单元类：纯算术 / 纯函数 ───────────────────────────────────
            [
                'id'    => 'U1',
                'class' => 'unit',
                'from'  => '§6.1 · 量化从四舍五入退化成截断',
                'note'  => '`round` 改 `floor` ⇒ 相邻矩形不再共享像素边，半像素缝',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Geometry/Scale.php',
                    'from' => 'return round($logical * $this->dpr);',
                    'to'   => 'return floor($logical * $this->dpr);',
                ],
            ],
            [
                'id'    => 'U2',
                'class' => 'unit',
                'from'  => '§13.1 · flex 分母把弹性子的自然尺寸也减掉',
                'note'  => '弹性子第一遍照量、又参与扣减 ⇒ 双重扣减 ⇒ `Expanded` 拿不到剩余空间',
                'op'    => [
                    'kind'  => 'replace-set',
                    'path'  => 'src/Layout/RenderFlex.php',
                    'edits' => [
                        [
                            'from' => "            if (\$flex > 0 && \$mainBounded && !\$needsNatural) {\n"
                                . "                \$mains[\$index] = 0.0;   // 占位：第二遍回填\n"
                                . "                continue;\n            }\n",
                            'to'   => '',
                        ],
                        [
                            'from' => "                if (\$kid->flex() === 0) {\n"
                                . "                    \$fixedMain += \$mains[\$index];\n                }\n",
                            'to'   => "                \$fixedMain += \$mains[\$index];\n",
                        ],
                    ],
                ],
            ],
            [
                'id'    => 'U3',
                'class' => 'unit',
                'from'  => '§46 · after() 的基准丢了「上一次输入」',
                'note'  => '帧外回调的 due 落在过去 ⇒ 延时变成立刻，且不报错',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/App/PumpHost.php',
                    'from' => "'due' => \\max(\$this->host->lastTimestampMs(), \$this->host->lastInputMs())",
                    'to'   => "'due' => \$this->host->lastTimestampMs()",
                ],
            ],

            // ── 行为类：交互语义 ──────────────────────────────────────────
            [
                'id'    => 'B1',
                'class' => 'behavior',
                'from'  => '§44 · 两相位叠色把 hover 接到 press 进度',
                'note'  => '悬停时 pressProgress 恒 0 ⇒ 悬停反馈整块消失',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Layout/RenderBox.php',
                    'from' => '$a = (int) \\round($hover->a * $this->hoverProgress());',
                    'to'   => '$a = (int) \\round($hover->a * $this->pressProgress());',
                ],
            ],
            [
                'id'    => 'B2',
                'class' => 'behavior',
                'from'  => '§42 · 重建换盒后直接置位（删掉「先归零」）',
                'note'  => '新盒继承了 hovered=true ⇒ setHovered 同值早退 ⇒ 停在暗帧',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/App/FocusManager.php',
                    'from' => "        \$next?->setHovered(false);\n"
                        . "        \$this->hoverBox?->setHovered(false);\n"
                        . "        \$this->hoverBox = \$next;\n",
                    'to'   => "        \$this->hoverBox = \$next;\n",
                ],
            ],
            [
                'id'    => 'B3',
                'class' => 'behavior',
                'from'  => '§20 · 键位映射把「消费」合成 bool',
                'note'  => '栈空时返回 null 而非 `None` ⇒ 键静默穿透到应用加速键',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Input/TextKeymap.php',
                    'from' => 'return $draft->undo() ?? TextChange::None;',
                    'to'   => 'return $draft->undo();',
                ],
            ],
            [
                'id'    => 'B4',
                'class' => 'behavior',
                'from'  => '§43 · 语义 token 零消费者',
                'note'  => '行高不再读主题，写死成旧 baseline',
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Widget/TableState.php',
                    'from' => '$view->rowHeightPx() ?? $ctx->metric(MetricToken::RowHeight),',
                    'to'   => '$view->rowHeightPx() ?? 44.0,',
                ],
            ],

            // ── AOT 类：解释器侧全绿、只有产物炸（已知缺口，不计分母）──────
            [
                'id'    => 'A1',
                'class' => 'aot',
                'from'  => '§67 · enum 里出现非 case 常量',
                'note'  => 'tpc codegen 出错 ⇒ 真机 0xC0000005，解释器完全正常',
                'aot'   => true,
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/Widget/WindowSize.php',
                    'from' => "    case Large;\n",
                    'to'   => "    case Large;\n\n    public const int DefaultWidth = 600;\n",
                ],
            ],
            [
                'id'    => 'A2',
                'class' => 'aot',
                'from'  => 'AOT · `self` 当静态属性的类型',
                'note'  => '真机 `Fatal: class "Drift\\Geometry\\self"`，解释器不校验',
                'aot'   => true,
                'op'    => [
                    'kind' => 'replace',
                    'path' => 'src/App/PumpHost.php',
                    'from' => "final class PumpHost\n{\n",
                    'to'   => "final class PumpHost\n{\n    private static self \$corpusProbe;\n",
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

        if ($kind === 'replace' || $kind === 'replace-all') {
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
            $hits = \substr_count($src, $from);
            if ($kind === 'replace' ? $hits !== 1 : $hits < 1) {
                throw new \RuntimeException(
                    ($kind === 'replace' ? '锚点不唯一' : '锚点不存在') . "（命中 {$hits} 次，仓库漂移了？）：" . $path
                );
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

        if ($kind === 'replace-set') {
            $src = \file_get_contents($path);
            if ($src === false) {
                throw new \RuntimeException('读不到：' . $path);
            }
            $crlf = \str_contains($src, "\r\n");
            $next = $src;
            foreach ($op['edits'] as $i => $edit) {
                $from = $crlf ? \str_replace("\n", "\r\n", $edit['from']) : $edit['from'];
                $to   = $crlf ? \str_replace("\n", "\r\n", $edit['to']) : $edit['to'];
                $hits = \substr_count($next, $from);
                if ($hits !== 1) {
                    throw new \RuntimeException("第 {$i} 处锚点不唯一（命中 {$hits} 次）：" . $path);
                }
                $next = \str_replace($from, $to, $next);
            }
            \file_put_contents($bak, $src);
            \file_put_contents($path, $next);

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
