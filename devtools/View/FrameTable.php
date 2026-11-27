<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

/**
 * 帧表：`frames` 查询的答案 + 驱动方量到的墙钟，合成一个可挑的集合。
 *
 * ## 为什么要有这一层
 *
 * 三件小事原先各写了两遍，散在 `ReportRunner` / `Report` 与 `Panel` 里：
 *
 * 1. 把墙钟并进帧表、缺了用 `cost` 兜底；
 * 2. 挑"最慢的**有重建**的帧"；
 * 3. 按帧号线性找一个帧。
 *
 * 两份实现当时逐行相同，所以看不出问题。但**兜底目标已经分岔**：面板挑不到时回
 * 最后一帧（它要看"当下"），报告挑不到时回最慢帧（它问的是"为什么慢"）。分岔本身
 * 是对的——抄一份实现不是。
 *
 * 所以这一层只提供"挑"，不提供"挑不到时怎么办"：{@see self::slowest()} 挑不到回
 * `0`，{@see self::last()} 空表回 `0`，退回哪个由调用方按自己的问题决定。
 *
 * ## 墙钟的三个来源
 *
 * `frames` 查询回的是 `cost`（录制时量的整帧耗时）。驱动方（`Replay` 的每帧回调、
 * 面板的 `pump()`）量到的是 `wall`，比 `cost` 准——它含了帧外的等待。两者都要，
 * 于是优先级是：调用方喂进来的 `$wallById` > 帧自带的 `wall` > `cost`。
 * 第二个来源存在，是因为 `Report::lines()` 拿到的帧表已经被上游合并过一次；
 * 不认它就会把上游量好的墙钟覆盖成 `cost`，而那种覆盖不报错、只让数字悄悄变。
 *
 * @internal 视图层助手；`src/` 不依赖它
 */
final class FrameTable
{
    /**
     * @param list<array<string, mixed>> $frames
     * @param array<int, float>          $wallById
     */
    private function __construct(
        private readonly array $frames,
        private readonly array $wallById,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $frames   `frames` 查询的 `frames` 段
     * @param array<int, float>          $wallById 驱动方量到的墙钟（帧号 => 毫秒）
     */
    public static function of(array $frames, array $wallById = []): self
    {
        return new self($frames, $wallById);
    }

    /**
     * 每个帧都带上 `wall`，缺的用 `cost` 补。
     *
     * 不改入参：`$frames` 可能是查询结果的缓存，就地改会让第二次调用读到已被改过的值。
     *
     * @return list<array<string, mixed>>
     */
    public function merged(): array
    {
        $out = [];
        foreach ($this->frames as $frame) {
            $frame['wall'] = $this->wallOf($frame);
            $out[] = $frame;
        }

        return $out;
    }

    /** @return list<int> */
    public function ids(): array
    {
        $ids = [];
        foreach ($this->frames as $frame) {
            $ids[] = (int) $frame['id'];
        }

        return $ids;
    }

    /**
     * 整张帧表的墙钟序列，顺序与帧表一致。
     *
     * 分位数与"最后一帧多慢"都从这里取：自己再 `array_map` 一遍就是在帧表外面
     * 又写一份"墙钟怎么算"。
     *
     * @return list<float>
     */
    public function walls(): array
    {
        $walls = [];
        foreach ($this->frames as $frame) {
            $walls[] = $this->wallOf($frame);
        }

        return $walls;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        foreach ($this->frames as $frame) {
            if ((int) $frame['id'] === $id) {
                return $frame;
            }
        }

        return null;
    }

    /**
     * 这一帧的墙钟（毫秒）。
     *
     * 三个来源按优先级取（见类注释）。`cost` 一定在：`frames` 查询的
     * `summary()` 保证每行都有它。
     *
     * @param array<string, mixed> $frame
     */
    public function wallOf(array $frame): float
    {
        $id = (int) $frame['id'];
        if (\array_key_exists($id, $this->wallById)) {
            return $this->wallById[$id];
        }
        if (\array_key_exists('wall', $frame)) {
            return (float) $frame['wall'];
        }

        return (float) $frame['cost'];
    }

    /**
     * 最慢的那一帧；`$rebuiltOnly` 只要重建过的。
     *
     * 挑不到（空表 / 全都没重建）回 `0`——**不**替调用方选兜底：面板要"当下"、
     * 报告要"为什么慢"，两者不是同一件事。
     */
    public function slowest(bool $rebuiltOnly = false): int
    {
        $best = 0;
        $bestWall = -1.0;
        foreach ($this->frames as $frame) {
            if ($rebuiltOnly && (int) $frame['rebuilt'] === 0) {
                continue;
            }
            $wall = $this->wallOf($frame);
            if ($wall > $bestWall) {
                $bestWall = $wall;
                $best = (int) $frame['id'];
            }
        }

        return $best;
    }

    /** 最后一帧的帧号；空表回 `0`。 */
    public function last(): int
    {
        if ($this->frames === []) {
            return 0;
        }

        return (int) $this->frames[\count($this->frames) - 1]['id'];
    }
}
