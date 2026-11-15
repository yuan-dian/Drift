<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Rendering\CanvasCapabilities;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\SkiaCanvas;

/**
 * 能力位的语义契约：位只回答「能不能发起」，画质差异单独登记。
 *
 * ★ 位掩码装不下「Raster 置位但产出是近似」这件事；此前它散在类头与各控件注释里，
 *   而 golden 只跑 Raster ⇒ 差异没人验。这张表把 DEBT D5① 的「两后端都必须回答」
 *   做实：新增位不登记就红，把一个位悄悄改成近似也红。
 */
final class CanvasCapabilityContractTest extends SmokeTestCase
{
    /**
     * 位 => [Raster 置位, Skia 置位, 语义]。
     * exact = 两后端同义；approx = Raster 置位但产出是降级近似；optional = 缺位由调用方降级。
     */
    private const CONTRACT = [
        'CAP_ELLIPSE'   => [true,  true,  'exact'],
        'CAP_LINE'      => [true,  true,  'exact'],
        'CAP_GRADIENT'  => [true,  true,  'approx'],
        'CAP_DASH'      => [true,  true,  'approx'],
        'CAP_CLIP_PATH' => [true,  true,  'approx'],
        'CAP_PATH'      => [true,  true,  'approx'],
        'CAP_LAYER'     => [true,  true,  'approx'],
        'CAP_BACKDROP'  => [true,  true,  'approx'],
        'CAP_IMAGE'     => [true,  true,  'approx'],
        'CAP_SHADOW'    => [false, true,  'optional'],
    ];

    public function testContractCoversEveryCapabilityConstant(): void
    {
        $constants = $this->capConstants();
        $names = array_keys($constants);
        $contract = array_keys(self::CONTRACT);
        sort($names);
        sort($contract);

        check(
            'CanvasCapabilities · 每个 CAP_* 都有契约条目（新增位不登记就红）',
            $names === $contract,
            'missing=' . implode(',', array_diff($names, $contract))
                . ' extra=' . implode(',', array_diff($contract, $names)),
        );

        $all = 0;
        foreach ($constants as $bit) {
            $all |= $bit;
        }
        check(
            'CanvasCapabilities · ALL_MASK 恰好是全部 CAP_* 的或（漏位即红）',
            $all === CanvasCapabilities::ALL_MASK,
            "computed={$all} declared=" . CanvasCapabilities::ALL_MASK,
        );

        $this->flushChecks();
    }

    public function testContractMatchesBothBackends(): void
    {
        $constants = $this->capConstants();
        $raster = $this->maskOf(self::CONTRACT, $constants, 0);
        $skia = $this->maskOf(self::CONTRACT, $constants, 1);

        check(
            'CanvasCapabilities · 契约的 Raster 列 == supportedCapabilities()',
            $raster === RasterCanvas::supportedCapabilities(),
            "contract={$raster} backend=" . RasterCanvas::supportedCapabilities(),
        );
        check(
            'CanvasCapabilities · 契约的 Skia 列 == SkiaCanvas::capabilities()',
            $skia === $this->skiaCaps(),
            "contract={$skia} backend={$this->skiaCaps()}",
        );
        check(
            'CanvasCapabilities · Raster ⊆ Skia ⊆ ALL_MASK（接口形状对齐最严格的一端）',
            ($raster & ~$skia) === 0 && ($skia & ~CanvasCapabilities::ALL_MASK) === 0,
            "raster={$raster} skia={$skia}",
        );

        $this->flushChecks();
    }

    public function testSemanticClassesAreExplicit(): void
    {
        $classes = [];
        foreach (self::CONTRACT as $name => $row) {
            $classes[$row[2]][] = $name;
        }
        foreach ($classes as &$names) {
            sort($names);
        }
        unset($names);

        $known = array_keys($classes);
        sort($known);
        check(
            'CanvasCapabilities · 语义只有 exact / approx / optional 三档',
            $known === ['approx', 'exact', 'optional'],
            'classes=' . implode(',', $known),
        );

        $approx = $classes['approx'] ?? [];
        $expected = [
            'CAP_BACKDROP', 'CAP_CLIP_PATH', 'CAP_DASH', 'CAP_GRADIENT',
            'CAP_IMAGE', 'CAP_LAYER', 'CAP_PATH',
        ];
        check(
            'CanvasCapabilities · 近似位逐个点名（Raster 置位但产出是降级）',
            $approx === $expected,
            'declared=' . implode(',', $approx),
        );
        check(
            'CanvasCapabilities · 唯一缺位的是 CAP_SHADOW（由 ShadowPainter 降级）',
            ($classes['optional'] ?? []) === ['CAP_SHADOW'],
            'optional=' . implode(',', $classes['optional'] ?? []),
        );

        $this->flushChecks();
    }

    public function testContractIsNotATautology(): void
    {
        $constants = $this->capConstants();

        $missing = self::CONTRACT;
        unset($missing['CAP_DASH']);
        check(
            '变异自检 · 删掉一个契约条目 ⇒ 集合对账必须红',
            !$this->contractMatches($missing, $constants),
        );

        $flipped = self::CONTRACT;
        $flipped['CAP_SHADOW'] = [true, true, 'optional'];
        check(
            '变异自检 · 把缺位改成置位 ⇒ 掩码对账必须红',
            !$this->contractMatches($flipped, $constants),
        );

        $this->flushChecks();
    }

    /** @return array<string, int> */
    private function capConstants(): array
    {
        $out = [];
        foreach ((new \ReflectionClass(CanvasCapabilities::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'CAP_')) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, array{0: bool, 1: bool, 2: string}> $contract
     *  @param array<string, int> $constants
     */
    private function maskOf(array $contract, array $constants, int $index): int
    {
        $mask = 0;
        foreach ($contract as $name => $answer) {
            if ($answer[$index]) {
                $mask |= $constants[$name] ?? 0;
            }
        }

        return $mask;
    }

    /** @param array<string, array{0: bool, 1: bool, 2: string}> $contract
     *  @param array<string, int> $constants
     */
    private function contractMatches(array $contract, array $constants): bool
    {
        $names = array_keys($contract);
        $expected = array_keys($constants);
        sort($names);
        sort($expected);
        if ($names !== $expected) {
            return false;
        }

        return $this->maskOf($contract, $constants, 0) === RasterCanvas::supportedCapabilities()
            && $this->maskOf($contract, $constants, 1) === $this->skiaCaps();
    }

    private function skiaCaps(): int
    {
        return (new SkiaCanvas(8, 8))->capabilities();
    }
}
