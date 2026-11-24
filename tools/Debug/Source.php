<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\App\Replay;
use Drift\Debug\Protocol\RemoteSession;
use Drift\Debug\Session\Evidence;
use Drift\Debug\Session\QueryRouter;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\SessionQueries;

/**
 * 「这一份证据从哪来」：四种来源选一种，出口只管拿到一个可查的东西。
 *
 * | 来源 | 触发 | 拿到的 |
 * |---|---|---|
 * | 现场跑 | 给了驱动脚本（本地或 `--remote`） | 活会话，帧是刚推的 |
 * | 证据包 | `--from=<文件>` | 离线复算的只读面 |
 * | 接现有会话 | `--attach` | 别人正在跑的那一次运行 |
 *
 * ## 为什么要把这团东西抽出来
 *
 * `brief` 与 `network` 各有一份 `--from` 分支（互斥检查、`hydrate`、标签取值，约 20
 * 行）。加 `--attach` 就是第三份，而 8 个出口早晚都要有。三份之后，任何一条纪律
 * （比如「`--from` 与 `--out` 互斥」，这一轮就改了两遍）都得改三处，而漏改一处
 * 不会报错 —— 只会让某个出口遵守另一套规矩。
 *
 * ## 互斥全在这里判
 *
 * 四种来源对应的动作互不相容，而它们的错误消息要说得具体（"包里已经有完整的驱动
 * 记录"比"参数冲突"有用得多）。分散在出口里就只能各写一套措辞。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Source
{
    /**
     * @param \Closure():void|null $cleanup 收尾。`null` = 没有要收的（离线读包）
     */
    private function __construct(
        private readonly SessionQueries $queries,
        private readonly string $label,
        private readonly bool $attached,
        private readonly ?string $out,
        private readonly ?\Closure $cleanup,
    ) {
    }

    /**
     * @param string|null $from  证据包路径
     * @param bool        $attach 接一个已经在跑的会话
     * @param bool        $remote 现场跑，但放到子进程里
     * @param Script|null $drive  现场跑的驱动脚本；`null` = 不跑
     * @param int|null    $at     只重放到第 N 帧
     * @param string|null $out    把这次会话导出成证据包
     * @param string|null $session 多会话时按登记 id 挑一个
     * @param Discovery|null $discovery 只给测试注入；默认按当前目录
     *
     * @throws \InvalidArgumentException 参数组合说不通
     * @throws \RuntimeException         跑不起来 / 找不到会话
     */
    public static function resolve(
        ?string $from = null,
        bool $attach = false,
        bool $remote = false,
        ?Script $drive = null,
        ?int $at = null,
        ?string $out = null,
        ?string $session = null,
        ?Discovery $discovery = null,
    ): self {
        if ($from !== null) {
            // 包里已经有完整的驱动记录，再给驱动参数就是两套说法，不猜以谁为准。
            if ($drive !== null || $attach) {
                throw new \InvalidArgumentException(
                    '--from 与 --page / 目标 / --attach / 驱动参数互斥：包里已经有完整的驱动记录',
                );
            }
            if ($out !== null) {
                // 离线读出的录制器是游离实例，不再产生新记录。真要另存（裁域 / 换格式）
                // 得重跑一次会话——顺手"读进来再写出去"会写出与源包不同的东西而不自知。
                throw new \InvalidArgumentException(
                    '--from 与 --out 不能同用：离线复算不产生新记录，要另存请重跑一次会话',
                );
            }

            $offline = Recorder::hydrate(Evidence::read($from));

            return new self(
                QueryRouter::offline($offline),
                // 标签优先取包里的脚本目标：那才是"这份证据是跑哪一页得到的"。
                $offline->script()?->target ?? \basename($from),
                false,
                null,
                null,
            );
        }

        if ($attach) {
            if ($drive !== null) {
                throw new \InvalidArgumentException(
                    '--attach 与 --page / 目标 / 驱动参数互斥：会话已经在跑，再指定页面等于想换一个会话',
                );
            }
            if ($out !== null) {
                throw new \InvalidArgumentException(
                    '--attach 与 --out 不能同用：记录在跑它的那个进程里，这里导不出来',
                );
            }

            $discovery ??= Discovery::inDirectory((string) \getcwd());
            $entry = self::pick($discovery, $session);
            $live = new RemoteSession(self::connect($discovery, $entry));
            $live->attach($entry['token']);

            return new self(
                $live,
                $entry['target'] !== '' ? $entry['target'] : 'attached',
                true,
                null,
                // 退出只断开：接别人的会话时走人，不该把人家正在看的那次运行结束掉。
                static function () use ($live): void {
                    $live->detach();
                },
            );
        }

        if ($drive === null) {
            throw new \InvalidArgumentException(
                '不知道该跑哪一页：给 --page=<路由id> 或目标类名，或者用 --from=<证据包> / --attach',
            );
        }
        if ($out !== null && $remote) {
            throw new \InvalidArgumentException('--out 不能与 --remote 同用：导出要本地那份记录');
        }

        if ($remote) {
            $live = new RemoteSession(ProcessTransport::spawn([
                \PHP_BINARY,
                \dirname(__DIR__, 2) . '/tools/debug-host.php',
            ]));
            $live->open($drive->fork());
        } else {
            $opened = Runner::open($drive->target, $drive->width, $drive->height, $drive->meta(), $drive->fork());
            $live = $opened['session'];
        }

        Replay::apply($live, $drive, [], $at, static function (int $id, float $ms): void {
        });

        return new self($live, $drive->target, false, $out, static function () use ($live): void {
            $live->close();
        });
    }

    public function queries(): SessionQueries
    {
        return $this->queries;
    }

    /** 给人看的名字：页面路由 / 包里的目标 / 发现文件里记的目标。 */
    public function label(): string
    {
        return $this->label;
    }

    /** 这份东西是不是别人的会话。`--follow` 之类的参数只对它有意义。 */
    public function isAttached(): bool
    {
        return $this->attached;
    }

    /**
     * `--out` 落地。只有现场跑才有记录可导（互斥已在 `resolve()` 里挡过）。
     *
     * 必须在取完事实之后调用：几何是帧末采的，早于最后一帧导出会少一段。
     */
    public function export(): void
    {
        if ($this->out === null) {
            return;
        }

        $recorder = Recorder::current();
        if ($recorder === null) {
            throw new \RuntimeException('--out 要本地那份记录，当前没有录制器');
        }

        Evidence::write($recorder, $this->out);
    }

    public function close(): void
    {
        if ($this->cleanup !== null) {
            ($this->cleanup)();
        }
    }

    /**
     * 挑一个会话。多个时要求点名，不猜 —— 猜错的表现是"读到的数据对不上"，
     * 而那种错会被归到证据层。
     *
     * @return array{id: string, pid: int, port: int, token: string, target: string, startedAt: string}
     */
    private static function pick(Discovery $discovery, ?string $session): array
    {
        $live = $discovery->live();

        if ($session !== null) {
            foreach ($live as $entry) {
                if ($entry['id'] === $session) {
                    return $entry;
                }
            }

            throw new \RuntimeException(
                "这个目录里没有 id 为 {$session} 的活会话（{$discovery->path()}）",
            );
        }

        if ($live === []) {
            throw new \RuntimeException(
                "当前目录没有运行中的 drift 会话（{$discovery->path()}）。\n"
                . '先起一个：drift debug serve --page=<路由id> --frames=1',
            );
        }

        if (\count($live) > 1) {
            $lines = [];
            foreach ($live as $entry) {
                $lines[] = \sprintf("  %s  pid %-6d %s", $entry['id'], $entry['pid'], $entry['target']);
            }

            throw new \RuntimeException(
                "这个目录有 " . \count($live) . " 个会话在跑，用 --session=<id> 指定一个：\n" . \implode("\n", $lines),
            );
        }

        return $live[0];
    }

    /**
     * 连上去。失败时把「进程还在不在」一起报 —— 只说连接被拒绝等于把用户扔在门口。
     *
     * @param array{id: string, pid: int, port: int, token: string, target: string, startedAt: string} $entry
     */
    private static function connect(Discovery $discovery, array $entry): SocketChannel
    {
        try {
            return SocketChannel::connect($entry['port']);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($discovery->diagnose($entry) . "\n（" . $e->getMessage() . '）', 0, $e);
        }
    }
}
