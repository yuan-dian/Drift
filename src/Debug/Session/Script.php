<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一份**可重放的驱动脚本**：目标 + 视口 + 环境 + 步骤序列。
 *
 * ## 它是什么，不是什么
 *
 * 它记的是"**怎么驱动**"（每一帧的时间戳、每一条输入、每一次具名副作用），
 * 不记"长什么样"（那是像素的事），也不记"为什么"（那是 {@see FrameRecord} 的事）。
 * 两者互补：脚本能复现一次运行，记录能解释这次运行。
 *
 * 三者缺一不可，否则"回放"只是演示：
 *
 * | 有 | 缺 | 后果 |
 * |---|---|---|
 * | 帧 | 输入 | 同一串帧，画面永远不动 |
 * | 帧 + 输入 | 具名副作用（异步结算等） | 页面一直转圈，异步路径一次都没走 |
 * | 帧 + 输入 + 副作用 | 目标与视口 | 换一页照样跑，报告却是另一个故事 |
 *
 * ## 为什么把目标与视口写进脚本
 *
 * 一份脚本只在"同一个目标 + 同一个视口"下有意义。重放时对不上就**拒绝**而不是
 * 硬跑：拿 `table` 的脚本跑 `form` 会得到一份语法正确、语义全错的报告，
 * 而报告本身看不出来。同理，`meta` 记环境（例如 `rows=6` 这种取数替身参数）——
 * 少了它，异步页重放会退化成"没有在途请求"，一帧都没变。
 *
 * ## 摘要（digest）只覆盖"驱动了什么"
 *
 * 记录摘要（{@see Digest}）回答"两次运行的因果是否逐值相同"，脚本摘要回答
 * "两次驱动是否逐值相同"。两者分开：驱动相同而因果不同，说明引擎不确定；
 * 驱动就不同，说什么都没意义。
 *
 * @api
 */
final class Script
{
    public const int VERSION = 1;

    /**
     * 步骤上限（防御：装了脚本却没关会话，指针移动会把内存吃满）。
     *
     * 触顶不是"少记一点"，而是**这份脚本不可重放** —— {@see self::truncated()}
     * 为真时重放方必须拒绝。宁可给一个明确的"不完整"，也不给一个看起来能跑、
     * 实际少了后半段的脚本。
     */
    public const int MAX_STEPS = 20000;

    /** @var list<Step> */
    private array $steps = [];

    /** @var array<string, scalar|null> */
    private array $meta = [];

    private bool $truncated = false;

    /**
     * @param array<string, scalar|null> $meta 环境参数（重放方要照着还原的东西）
     */
    public function __construct(
        public readonly string $target,
        public readonly float $width,
        public readonly float $height,
        array $meta = [],
    ) {
        foreach ($meta as $key => $value) {
            if (!\is_string($key) || (!\is_scalar($value) && $value !== null)) {
                throw new \InvalidArgumentException('脚本 meta 只能放"字符串键 ⇒ 标量"');
            }
        }
        $this->meta = $meta;
    }

    public function add(Step $step): void
    {
        if (\count($this->steps) >= self::MAX_STEPS) {
            $this->truncated = true;

            return;
        }

        $this->steps[] = $step;
    }

    /**
     * 同目标、同视口、同环境的**空脚本**：给"重放时顺便再录一份"用。
     *
     * 重放不能往原脚本里追加步骤 —— 那样第二次重放就会看到两倍的步骤，
     * 而"同一份脚本跑两遍"的判据正是要建立在"脚本不被改动"上。
     */
    public function fork(): self
    {
        return new self($this->target, $this->width, $this->height, $this->meta);
    }

    /** @return list<Step> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return array<string, scalar|null> */
    public function meta(): array
    {
        return $this->meta;
    }

    public function metaValue(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function count(?string $kind = null): int
    {
        if ($kind === null) {
            return \count($this->steps);
        }

        $n = 0;
        foreach ($this->steps as $step) {
            if ($step->kind === $kind) {
                ++$n;
            }
        }

        return $n;
    }

    /** 触顶过 ⇒ 脚本不完整，重放必须拒绝。 */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'target' => $this->target,
            'viewport' => [$this->width, $this->height],
            'meta' => $this->meta,
            'truncated' => $this->truncated,
            'steps' => \array_map(static fn(Step $step): array => $step->toArray(), $this->steps),
        ];
    }

    /** 人读的 JSON：脚本是要被打开、被改、被贴进 issue 的东西。 */
    public function json(): string
    {
        return \json_encode($this->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @throws \InvalidArgumentException 版本或形状不认
     */
    public static function fromArray(array $raw): self
    {
        $version = $raw['version'] ?? null;
        if ($version !== self::VERSION) {
            throw new \InvalidArgumentException(
                '脚本版本不认识：' . \json_encode($version) . '（本工具只读 ' . self::VERSION . '）',
            );
        }
        $target = $raw['target'] ?? null;
        if (!\is_string($target) || $target === '') {
            throw new \InvalidArgumentException('脚本缺少 target');
        }
        $viewport = $raw['viewport'] ?? null;
        if (!\is_array($viewport) || \count($viewport) !== 2) {
            throw new \InvalidArgumentException('脚本的 viewport 必须是 [宽, 高]');
        }
        $values = \array_values($viewport);
        $meta = $raw['meta'] ?? [];
        if (!\is_array($meta)) {
            throw new \InvalidArgumentException('脚本的 meta 必须是映射');
        }
        $steps = $raw['steps'] ?? [];
        if (!\is_array($steps)) {
            throw new \InvalidArgumentException('脚本的 steps 必须是列表');
        }

        /** @var array<string, scalar|null> $meta */
        $script = new self($target, (float) $values[0], (float) $values[1], $meta);
        foreach ($steps as $step) {
            if (!\is_array($step)) {
                throw new \InvalidArgumentException('脚本的步骤必须是映射');
            }
            $script->add(Step::fromArray($step));
        }
        if ((bool) ($raw['truncated'] ?? false)) {
            $script->truncated = true;
        }

        return $script;
    }

    public static function fromJson(string $json): self
    {
        try {
            $raw = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('脚本不是合法 JSON：' . $e->getMessage(), 0, $e);
        }
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('脚本顶层必须是对象');
        }

        return self::fromArray($raw);
    }

    /** 驱动侧摘要：只覆盖"驱动了什么"，不覆盖时间与耗时。 */
    public function digest(): string
    {
        return \hash('sha256', \json_encode(
            ['target' => $this->target, 'viewport' => [$this->width, $this->height], 'meta' => $this->meta, 'steps' => \array_map(
                static fn(Step $step): array => $step->toArray(),
                $this->steps,
            )],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        ));
    }
}
