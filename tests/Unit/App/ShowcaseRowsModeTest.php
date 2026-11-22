<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use App\Showcase\Jobs\FetchRowsJob;
use App\Showcase\ShowcaseApplication;
use Drift\App\AppScope;
use Drift\App\Io\HttpResponse;
use Drift\App\Io\HttpTransport;
use Drift\Async\Future;
use Drift\Dev\Tests\Support\RecordingTaskHost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 组合根取数两档判据：http 走 UI 侧 AsyncHttp，local 仍走 worker。
 *
 * 两档共用 `\Closure(int): Future` 契约，页面零改动；这里钉住"来源真的换了"，
 * 否则最直接的退化是开关失效、始终走 worker —— 页面照样有数据，没人发现。
 */
final class ShowcaseRowsModeTest extends TestCase
{
    #[Test]
    public function http档走帧内传输并解码(): void
    {
        $response = new HttpResponse(
            200,
            ['content-type' => 'application/json'],
            (string) \json_encode(['ok' => true, 'items' => [['id' => 1]], 'total' => 137]),
            'http://127.0.0.1:8098/api/rows?page=1&per=20',
            12.0,
        );
        [$app, $transport, $host] = $this->make(ShowcaseApplication::ROWS_HTTP, $response);

        $got = null;
        $error = null;
        $app->fetchRows(1)
            ->then(static function (mixed $rows) use (&$got): void { $got = $rows; })
            ->catch(static function (\Throwable $e) use (&$error): void { $error = $e; });

        self::assertNull($error, '不该失败：' . ($error === null ? '' : $error->getMessage()));
        self::assertCount(1, $transport->submitted, 'http 档必须走帧内传输');
        self::assertSame('GET', $transport->submitted[0]['method']);
        self::assertStringContainsString('page=1&per=20', $transport->submitted[0]['url']);
        self::assertSame([], $host->submitted, '小 JSON 不该再占 worker');
        self::assertSame(137, $got['total'] ?? null);
        self::assertSame([['id' => 1]], $got['items'] ?? null);
        self::assertStringContainsString('HTTP http://127.0.0.1:8098', $got['source'] ?? '');
        self::assertStringContainsString('ms', $got['source'] ?? '');
    }

    #[Test]
    public function local档仍把慢任务交worker(): void
    {
        $answer = ['items' => [], 'total' => 137, 'source' => '本地慢任务'];
        [$app, $transport, $host] = $this->make(ShowcaseApplication::ROWS_LOCAL, $answer, FetchRowsJob::class);

        $got = null;
        $app->fetchRows(3)
            ->then(static function (mixed $rows) use (&$got): void { $got = $rows; });

        self::assertSame([], $transport->submitted, 'local 档不该发 HTTP');
        self::assertCount(1, $host->submitted, 'local 档仍要 submit 到 worker');
        self::assertInstanceOf(FetchRowsJob::class, $host->submitted[0]);
        self::assertSame($answer, $got);
    }

    #[Test]
    public function http档服务端错误进error态(): void
    {
        $response = new HttpResponse(500, [], '{"ok":false}', 'http://127.0.0.1:8098/api/rows', 3.0);
        [$app] = $this->make(ShowcaseApplication::ROWS_HTTP, $response);

        $error = null;
        $app->fetchRows(2)
            ->then(static function (mixed $rows): void {})
            ->catch(static function (\Throwable $e) use (&$error): void { $error = $e; });

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertStringContainsString('取表格失败', $error->getMessage());
    }

    /** @return array{ShowcaseApplication, StubTransport, RecordingTaskHost} */
    private function make(string $mode, mixed $answer, ?string $jobClass = null): array
    {
        $scope = new AppScope();
        $host = new RecordingTaskHost($jobClass === null ? [] : [$jobClass => $answer]);
        $scope->setTaskHost($host);
        $transport = new StubTransport($answer);
        $scope->setHttpTransport($transport);

        return [new ShowcaseApplication($scope, $mode), $transport, $host];
    }
}

/** 按预制答案立刻结算的传输替身：只记账，不碰网络。 */
final class StubTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, opts: array<string, mixed>}> */
    public array $submitted = [];

    public function __construct(private readonly mixed $answer)
    {
    }

    public function submit(string $method, string $url, array $opts): Future
    {
        $this->submitted[] = ['method' => $method, 'url' => $url, 'opts' => $opts];

        return $this->answer instanceof \Throwable
            ? Future::rejected($this->answer)
            : Future::resolved($this->answer);
    }

    public function pump(): void
    {
    }

    public function inFlight(): int
    {
        return \count($this->submitted);
    }
}
