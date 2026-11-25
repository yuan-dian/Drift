<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 基线回归的出口：写、读、断言三件事的退出码与输出。
 *
 * ## 为什么要跑真子进程
 *
 * 这三件事的契约有一半在**退出码**上（0 / 2 / 3），而退出码只有真跑进程才看得见。
 * 单元判据能断言"回归数算对了"，但"算对了却没让 CI 红"同样是一次假绿 ——
 * 那正是基线这个功能唯一的目的。
 */
final class BaselineCliTest extends SmokeTestCase
{
    /** 有 6 条发现的一页：2 条耗时类 + 4 条确定性。 */
    private const array DRIVE = ['--page=table', '--frames=1', '--click=400,300'];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @\unlink($file);
        }
        $this->files = [];
        parent::tearDown();
    }

    public function testSavedBaselineIsReadableAndComparesClean(): void
    {
        $base = $this->save('clean');

        $raw = \json_decode((string) \file_get_contents($base), true);
        self::assertIsArray($raw);
        check('基线带格式版本', ($raw['format'] ?? 0) === 1, (string) ($raw['format'] ?? ''));
        check('基线收了全部发现', \count($raw['findings'] ?? []) === 6, (string) \count($raw['findings'] ?? []));
        check('基线记了排除数', ($raw['excluded'] ?? -1) === 0, (string) ($raw['excluded'] ?? ''));

        [$code, $out, $err] = $this->brief([...self::DRIVE, '--baseline=' . $base, '--assert']);
        check('同源对比退出 0', $code === 0, $err);
        check('同源对比零回归', \str_contains($out, '回归 0'), $out);
        // 不能断言"这次肯定偏离 N%"：同一份脚本重跑，耗时可能落在容差内
        // （实测 -4.5% / +2.6%）。断言应钉在**契约**上：耗时类行一律列出，
        // 且一律自报不计断言 —— 两条都不依赖外部抖动。
        check('耗时类行带 ~ 标记', \str_contains($out, '~ '), $out);
        check('耗时类自报不计断言', \str_contains($out, '不计断言'), $out);
        $this->flushChecks();
    }

    public function testDeterministicRegressionExitsThree(): void
    {
        $base = $this->save('worse', static function (array $file): array {
            foreach ($file['findings'] as $i => $row) {
                if ($row['rule'] === 'fanout') {
                    $file['findings'][$i]['value'] = 100.0;
                }
            }

            return $file;
        });

        [$code, $out, $err] = $this->brief([...self::DRIVE, '--baseline=' . $base, '--assert']);
        check('确定性回归退出 3', $code === 3, $err);
        check('回归数写进输出', \str_contains($out, '回归 1'), $out);
        check('回归行指出地址', \str_contains($out, 'frame:2 node:1'), $out);
        $this->flushChecks();
    }

    /**
     * 耗时类变化不能红。这条是整份设计的核心判据：
     * 本机实测帧耗时抖动 ±7%，拿它当断言等于每天制造一次假红。
     */
    public function testTimingDriftDoesNotFailTheAssert(): void
    {
        $base = $this->save('timing', static function (array $file): array {
            foreach ($file['findings'] as $i => $row) {
                if ($row['rule'] === 'slow-frame') {
                    $file['findings'][$i]['value'] = 1.0;
                }
            }

            return $file;
        });

        [$code, $out, $err] = $this->brief([...self::DRIVE, '--baseline=' . $base, '--assert', '--json']);
        check('耗时类大偏差仍退出 0', $code === 0, $err . $out);

        $payload = \json_decode($out, true);
        self::assertIsArray($payload, 'JSON 解不开：' . $out);
        check('regressions 不计耗时', ($payload['baseline']['regressions'] ?? -1) === 0, (string) ($payload['baseline']['regressions'] ?? ''));
        check('unstable 记下了它', ($payload['baseline']['unstable'] ?? 0) >= 1, (string) ($payload['baseline']['unstable'] ?? ''));
        $this->flushChecks();
    }

    public function testBadInputsFailLoudly(): void
    {
        [$code, $out, $err] = $this->brief(['--page=table', '--assert']);
        check('--assert 缺 --baseline 退出 2', $code === 2, $err);
        check('且说明为什么', \str_contains($err, '--baseline'), $err);

        [$code, , $err] = $this->brief([...self::DRIVE, '--baseline=' . $this->path('missing')]);
        check('基线不存在退出 2', $code === 2, $err);
        check('不当空基线读', \str_contains($err, '读不到基线'), $err);

        $bad = $this->write('bad-format', '{"format":9,"findings":[]}');
        [$code, , $err] = $this->brief([...self::DRIVE, '--baseline=' . $bad]);
        check('版本不认识退出 2', $code === 2, $err);
        check('报出支持的版本', \str_contains($err, '支持 1'), $err);

        $partial = $this->write('no-findings', '{"format":1}');
        [$code, , $err] = $this->brief([...self::DRIVE, '--baseline=' . $partial]);
        check('缺 findings 退出 2', $code === 2, $err);

        $base = $this->save('mutex');
        [$code, , $err] = $this->brief([...self::DRIVE, '--baseline=' . $base, '--save-baseline=' . $this->path('x')]);
        check('读写互斥退出 2', $code === 2, $err);
        $this->flushChecks();
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed>|null $mutate
     */
    private function save(string $tag, ?callable $mutate = null): string
    {
        $file = $this->path($tag);
        [$code, $out, $err] = $this->brief([...self::DRIVE, '--save-baseline=' . $file]);
        self::assertSame(0, $code, '写基线失败：' . $err . $out);

        if ($mutate !== null) {
            $data = \json_decode((string) \file_get_contents($file), true);
            self::assertIsArray($data);
            \file_put_contents($file, (string) \json_encode($mutate($data), \JSON_UNESCAPED_UNICODE));
        }

        return $file;
    }

    private function write(string $tag, string $body): string
    {
        $file = $this->path($tag);
        \file_put_contents($file, $body);

        return $file;
    }

    private function path(string $tag): string
    {
        $file = \sys_get_temp_dir() . '/drift-baseline-' . \getmypid() . '-' . $tag . '.json';
        $this->files[] = $file;

        return $file;
    }

    /** @return array{int, string, string} */
    private function brief(array $args): array
    {
        $root = \dirname(__DIR__, 3);
        $proc = \proc_open(
            \array_merge([\PHP_BINARY, $root . '/tools/debug-brief.php'], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($proc, 'debug-brief 起不来');
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), $out, $err];
    }
}
