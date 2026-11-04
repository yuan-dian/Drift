<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

/**
 * **指纹的通道表**：把"页 → 一帧 → 指纹"这件事的读写口径收在一处。
 *
 * ★ 为什么要有这个类（2026-11-04）：加绘制指纹时冒出来的真实问题是
 *   **同一条 TSV 行会被三方各写一遍**（AOT 探针、解释器侧入口、比较器）。
 *   `DEBT` §37.5 ② 的教训已经吃过一次 —— 两侧各写一遍 float 格式化，
 *   结果 "格式不同" 冒充 "数值不同"，误报比漏报难查。
 *   ⇒ 写侧只有 `row()`，读侧只有 `parse()`，中间是 `shape()` / `columns()`。
 *     **同一处的两个面**，不给人留第二份。
 *
 * ★ 通道是可以再加的（语义树 / 命中测试都是同一形状）。加通道时只动这个文件和
 *   一个 `XxxFingerprint` —— 比较器不认识具体通道，只认识 `shape()` 出来的五列。
 *
 * @internal 量具专用，不进公共 API
 */
final class Fingerprint
{
    public const GEOM = 'geom';
    public const PAINT = 'paint';

    /** @var list<string> 输出顺序 = 判据顺序 */
    public const CHANNELS = [self::GEOM, self::PAINT];

    /**
     * 上次 `snapshot()` 里**没能序列化**的槽类型。
     *
     * ★ 它必须显性存在：未知类型两侧都会变成 `?类名`，于是**指纹仍然相等** ——
     *   判据静默地少覆盖一类命令，而报告全绿。这是最难发现的一种失效：
     *   不是"红了查不出"，是"绿了其实没验"。探针单判一条。
     *
     * @var list<string>
     */
    private static array $unknown = [];

    /**
     * 通道的"个体"名（报错文案用）。
     *
     * ★ 写"个节点"而不是"个 RenderBox"：文案要对人有用，类型名对他没用。
     */
    public static function unit(string $ch): string
    {
        return $ch === self::PAINT ? '条命令' : '个节点';
    }

    /**
     * 下界判据：**这个通道靠哪一列自证"东西真的存在"**。
     *
     * ★ 两个通道自证的列不同，这不是不一致：几何的"结构量"是节点数，
     *   绘制的"结构量"是落笔数（`cmds` 里可以有 2000 条 `TRANSLATE` 却一笔没画）。
     *
     * @return array{0: string, 1: int, 2: string} [shape 键, 下界, 中文名]
     */
    public static function bound(string $ch): array
    {
        // 实测基线（27 页 1200×800）：2278 个 RenderBox / 1384 次落笔。
        // 留 ~12% 余量抓"渲染成空"，同时不因正常改页而天天红（§36.8 的教训：
        // 硬编码精确值 ⇒ 改一页就红 ⇒ 红久了没人看）。
        return $ch === self::PAINT ? ['v2', 1200, '落笔'] : ['v1', 2000, '节点'];
    }

    /** @return array{0: string, 1: string, 2: string} 三个旁证列的名字 */
    public static function columns(string $ch): array
    {
        return $ch === self::PAINT
            ? ['命令数', '落笔数', '裁剪数']
            : ['节点数', '宽和', '高和'];
    }

    /**
     * 把某个通道的原始行规范成**五列同名形状** —— 比较器只认这个。
     *
     * ★ float 一律 `%.3f`（走 `sprintf`，不走 `(string)`）：后者受 `precision` ini
     *   影响，而 AOT 产物的 ini 来自 `PHPRC=_build/ini`，与解释器不是同一份（§37.5 ②）。
     *
     * @param array<string, mixed> $row
     * @return array{page: string, hash: string, v1: string, v2: string, v3: string}
     */
    public static function shape(string $ch, array $row): array
    {
        if ($ch === self::PAINT) {
            return [
                'page' => (string) $row['page'],
                'hash' => (string) $row['hash'],
                'v1' => (string) $row['cmds'],
                'v2' => (string) $row['draws'],
                'v3' => (string) $row['clips'],
            ];
        }

        return [
            'page' => (string) $row['page'],
            'hash' => (string) $row['hash'],
            'v1' => (string) $row['nodes'],
            'v2' => \sprintf('%.3f', $row['sumW']),
            'v3' => \sprintf('%.3f', $row['sumH']),
        ];
    }

    /**
     * 一条 TSV 行：`通道 \t 页 \t hash \t v1 \t v2 \t v3`。
     *
     * ★ 通道名放在**第一列**而不是"段头"：段头要靠状态机解析，一旦顺序错了
     *   两段会串味；第一列是每行自带的事实，解析器只需看它。
     *
     * @param array{page: string, hash: string, v1: string, v2: string, v3: string} $s
     */
    public static function row(string $ch, array $s): string
    {
        return $ch . "\t" . $s['page'] . "\t" . $s['hash']
            . "\t" . $s['v1'] . "\t" . $s['v2'] . "\t" . $s['v3'];
    }

    /**
     * 从混着标题/判据的输出里抽出指纹行，按通道分桶。
     *
     * ★ 三条过滤且**都要**：6 列（防 detail 行）、首列是已知通道（防别的表格）、
     *   第三列是 32 位十六进制（防"页名里有 tab"这类脏数据）。少一条就会把
     *   别的表混进来，而混进来的后果是"清单不同"——一条假红。
     *
     * @return array<string, list<array{page: string, hash: string, v1: string, v2: string, v3: string}>>
     */
    public static function parse(string $raw): array
    {
        $out = [];
        foreach (self::CHANNELS as $ch) {
            $out[$ch] = [];
        }

        foreach (\explode("\n", $raw) as $line) {
            $line = \rtrim($line, "\r");
            if (\substr_count($line, "\t") !== 5) {
                continue;
            }
            $c = \explode("\t", $line);
            if (!isset($out[$c[0]])) {
                continue;
            }
            if (\strlen($c[2]) !== 32 || !\ctype_xdigit($c[2])) {
                continue;
            }
            $out[$c[0]][] = ['page' => $c[1], 'hash' => $c[2], 'v1' => $c[3], 'v2' => $c[4], 'v3' => $c[5]];
        }

        return $out;
    }

    /**
     * 27 页**各挂一次**，产出两通道的规范行 —— 解释器侧与 AOT 侧共用同一循环。
     *
     * ★ 一次挂载出两份指纹，不是为了省时间（虽然省了一半）：是为了让"几何"与
     *   "绘制"**来自同一帧**。"两帧恰好相同"要另证（E②/P①），同帧不需要。
     *
     * @return array<string, list<array{page: string, hash: string, v1: string, v2: string, v3: string}>>
     */
    public static function snapshot(): array
    {
        $out = [];
        foreach (self::CHANNELS as $ch) {
            $out[$ch] = [];
        }
        self::$unknown = [];

        foreach (ShowcasePages::names() as $name) {
            $f = PageFrame::of($name);
            $out[self::GEOM][] = self::shape(self::GEOM, GeomFingerprint::of($f->root()) + ['page' => $name]);

            $p = PaintFingerprint::of($f->commands());
            $out[self::PAINT][] = self::shape(self::PAINT, $p + ['page' => $name]);
            foreach ($p['unknown'] as $cls) {
                if (!\in_array($cls, self::$unknown, true)) {
                    self::$unknown[] = $cls;
                }
            }
        }

        return $out;
    }

    /** @return list<string> 上次 `snapshot()` 遇到的未知槽类型（空 = 覆盖完整） */
    public static function unknownTypes(): array
    {
        return self::$unknown;
    }

    /**
     * 单页明细（定位用）。
     *
     * @return list<string>
     */
    public static function detail(string $ch, string $page): array
    {
        $f = PageFrame::of($page);

        return $ch === self::PAINT
            ? PaintFingerprint::detail($f->commands())
            : GeomFingerprint::detail($f->root());
    }
}
