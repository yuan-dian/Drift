<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;

final class ErrorsDescribeTest extends SmokeTestCase
{
    private const FORMAT = '/^消息（ErrorsDescribeTest\.php:\d+）$/';

    public function testDescribeFormatsMessageBasenameAndLine(): void
    {
        $e = new \RuntimeException('消息');
        $got = Errors::describe($e);

        check(
            'describe · 消息 + basename + 行号',
            preg_match(self::FORMAT, $got) === 1,
            'got=' . $got,
        );
        check(
            'describe · 不泄露目录路径',
            !str_contains($got, '/') && !str_contains($got, '\\'),
            'got=' . $got,
        );
        check(
            'describe · 保留异常自身消息',
            str_contains($got, '消息'),
            'got=' . $got,
        );

        $this->flushChecks();
    }

    public function testErrorSitesShareDescribeAndNoLongerBuildTheTriple(): void
    {
        $sites = [
            'src/App/Frame.php' => 3,
            'src/App/InputRouter.php' => 3,
            'src/App/OverlayLayout.php' => 1,
            'src/App/PumpHost.php' => 1,
            'src/Layout/RenderErrorBoundary.php' => 1,
            'src/Widget/ErrorBoundaryElement.php' => 1,
        ];

        $root = repoRoot();
        $scanned = 0;
        foreach ($sites as $rel => $expected) {
            $src = file_get_contents($root . '/' . $rel);
            $calls = substr_count($src, 'Errors::describe(');
            check(
                "$rel · $expected 处走 describe",
                $calls === $expected,
                "calls=$calls expected=$expected",
            );
            check(
                "$rel · 不再自拼 getFile 三元组",
                !str_contains($src, 'basename('),
                'found basename(',
            );
            $scanned++;
        }

        check('量具自证 · 扫到 6 个上报文件', $scanned === 6, 'scanned=' . $scanned);
        $this->flushChecks();
    }

    public function testDescribeIsTheOnlyPlaceBuildingTheTriple(): void
    {
        $src = file_get_contents(repoRoot() . '/src/Errors.php');
        check(
            'Errors.php · 三元组单点在 describe 内',
            str_contains($src, 'basename($e->getFile())') && str_contains($src, 'function describe('),
            'missing single point',
        );
        $this->flushChecks();
    }
}
