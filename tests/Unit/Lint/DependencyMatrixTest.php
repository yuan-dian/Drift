<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Lint;

use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rules\DependencyDirection;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 依赖矩阵的 burn-down 对账（`tools/Lint/Rules/DependencyDirection.php`）。
 *
 * ★ 越界由 lint 自己报，这里只守**表的形状**：零流量授权回潮、双向边悄悄多一对、
 *   文件例外变成死条目。这三类都是 lint 恒绿但读不出架构形状的情况。
 */
final class DependencyMatrixTest extends SmokeTestCase
{
    public function testEveryAllowedEdgeHasRealTraffic(): void
    {
        $matrix = $this->matrix();
        foreach ($matrix['allowed'] as $from => $targets) {
            foreach ($targets as $to) {
                if ($to === $from) {
                    continue;
                }
                check(
                    '依赖矩阵 · ' . $from . ' → ' . $to . ' 有真实引用（零流量授权不得回潮）',
                    ($matrix['flow'][$from . '>' . $to] ?? 0) > 0,
                    'traffic=0',
                );
            }
        }

        $this->flushChecks();
    }

    public function testOnlyDocumentedCyclesAreBidirectional(): void
    {
        $matrix = $this->matrix();
        $actual = [];
        foreach (array_keys($matrix['flow']) as $edge) {
            [$from, $to] = explode('>', $edge);
            if (!isset($matrix['flow'][$to . '>' . $from])) {
                continue;
            }
            $pair = [$from, $to];
            sort($pair);
            $actual[$pair[0] . '|' . $pair[1]] = true;
        }
        $actual = array_keys($actual);
        sort($actual);
        $declared = $matrix['cycles'];
        sort($declared);

        check(
            '依赖矩阵 · 双向边恰好是登记过的两对（新增一对必须先裁决）',
            $actual === $declared,
            'actual=' . implode(',', $actual) . ' declared=' . implode(',', $declared),
        );

        $this->flushChecks();
    }

    public function testFileExceptionsAreAllUsed(): void
    {
        $matrix = $this->matrix();
        foreach ($matrix['exceptions'] as $path => $segments) {
            foreach ($segments as $segment) {
                check(
                    '依赖矩阵 · ' . basename($path) . ' → ' . $segment . ' 例外仍有真实引用',
                    ($matrix['exceptionFlow'][$path . '|' . $segment] ?? 0) > 0,
                    'dead exception',
                );
            }
        }

        $this->flushChecks();
    }

    public function testExtractorIsNotATautology(): void
    {
        $matrix = $this->matrix();
        check('对账 · 提取器抓得到真实边', ($matrix['flow']['Widget>Rendering'] ?? 0) > 0);
        check('对账 · 自身引用不算跨层边', !isset($matrix['flow']['Widget>Widget']));
        check('对账 · 不存在的边查不到', !isset($matrix['flow']['Logging>Routing']));

        $this->flushChecks();
    }

    /** @return array{allowed: array, cycles: array, exceptions: array, flow: array, exceptionFlow: array} */
    private function matrix(): array
    {
        $rc = new \ReflectionClass(DependencyDirection::class);
        $constant = static fn (string $name): mixed => $rc->getReflectionConstant($name)->getValue();
        $allowed = $constant('ALLOWED');
        $unlayered = $constant('UNLAYERED');
        $exceptions = $constant('FILE_EXCEPTIONS');
        $cycles = $constant('DOCUMENTED_CYCLES');

        $project = Project::scan(dirname(__DIR__, 3), [], ['src']);
        $segment = static function (string $fqn) use ($unlayered): ?string {
            if (!str_starts_with($fqn, Project::FRAMEWORK_PREFIX)) {
                return null;
            }
            $parts = explode('\\', substr($fqn, strlen(Project::FRAMEWORK_PREFIX)));

            return count($parts) >= 2 && !in_array($parts[0], $unlayered, true) ? $parts[0] : null;
        };

        $flow = [];
        $exceptionFlow = [];
        foreach ($project->files() as $file) {
            $from = $file->namespace();
            if (!str_starts_with($from, Project::FRAMEWORK_PREFIX)) {
                continue;
            }
            $parts = explode('\\', substr($from, strlen(Project::FRAMEWORK_PREFIX)));
            $fromSegment = $parts[0];
            if (in_array($fromSegment, $unlayered, true) || !isset($allowed[$fromSegment])) {
                continue;
            }

            foreach ($file->references() as $reference) {
                $toSegment = $segment($reference['name']);
                if ($toSegment === null || !isset($allowed[$toSegment]) || $toSegment === $fromSegment) {
                    continue;
                }
                $flow[$fromSegment . '>' . $toSegment] = ($flow[$fromSegment . '>' . $toSegment] ?? 0) + 1;

                $key = $file->path() . '|' . $toSegment;
                if (in_array($toSegment, $exceptions[$file->path()] ?? [], true)) {
                    $exceptionFlow[$key] = ($exceptionFlow[$key] ?? 0) + 1;
                }
            }
        }

        return [
            'allowed' => $allowed,
            'cycles' => $cycles,
            'exceptions' => $exceptions,
            'flow' => $flow,
            'exceptionFlow' => $exceptionFlow,
        ];
    }
}
