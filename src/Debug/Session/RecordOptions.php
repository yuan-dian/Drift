<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一次录制的采集预算。
 *
 * 因果与帧号是主路径，不能关；其余域必须显式声明，避免"装了录制器就全录"。
 * 值对象不可变：会话中途不换预算，读者才能从 manifest 判断哪些域可信。
 */
final readonly class RecordOptions
{
    public const int DEFAULT_CAPACITY = Recorder::DEFAULT_CAPACITY;

    /**
     * @param int $capacity 帧窗口；几何 / 网络 / 报文仍各有独立窗口
     * @param bool $network 记网络骨架（方法 / URL / 状态 / 帧号 / 发起者）
     * @param bool $bodies 抓请求 / 响应体；默认关，隐私与体积理由见证据模型
     * @param bool $paint 记每帧重画集合与层缓存命中 / 未命中；默认关，先证明预算
     * @param bool $async 记 TaskHost 任务状态跃迁；默认关，先证明预算
     * @param bool $redact 报文脱敏；关掉时 manifest 必须说"含明文"
     */
    public function __construct(
        private int $capacity = self::DEFAULT_CAPACITY,
        private ?Script $script = null,
        private bool $redact = true,
        private GeometryCapture $geometry = GeometryCapture::Changed,
        private bool $network = true,
        private bool $bodies = false,
        private bool $paint = false,
        private bool $async = false,
    ) {
        if ($this->capacity < 1) {
            throw new \InvalidArgumentException('录制帧窗口至少为 1');
        }
        if ($this->bodies && !$this->network) {
            // 正文按 requestId 挂骨架；没有骨架的正文永远落不了账，manifest 却会声称抓了。
            throw new \InvalidArgumentException('抓报文正文必须同时打开网络骨架');
        }
    }

    /** 开发默认：因果 + 变化几何 + 网络骨架；报文按需开。 */
    public static function default(int $capacity = self::DEFAULT_CAPACITY): self
    {
        return new self(capacity: $capacity);
    }

    /** 常驻最小档：只留帧与因果，不碰布局树与 IO 正文。 */
    public static function framesOnly(int $capacity = self::DEFAULT_CAPACITY, ?Script $script = null): self
    {
        return new self(
            capacity: $capacity,
            script: $script,
            geometry: GeometryCapture::Off,
            network: false,
            bodies: false,
        );
    }

    /** 完整取证：几何全量 + 网络骨架 + 报文。 */
    public static function full(int $capacity = self::DEFAULT_CAPACITY, ?Script $script = null): self
    {
        return new self(
            capacity: $capacity,
            script: $script,
            geometry: GeometryCapture::All,
            network: true,
            bodies: true,
            paint: true,
            async: true,
        );
    }

    /**
     * 显式构造。旧的位置参数 / 命名参数统一在这里归一，不散到各个调用点。
     */
    public static function of(
        int $capacity = self::DEFAULT_CAPACITY,
        ?Script $script = null,
        bool $redact = true,
        GeometryCapture $geometry = GeometryCapture::Changed,
        bool $network = true,
        bool $bodies = false,
        bool $paint = false,
        bool $async = false,
    ): self {
        return new self($capacity, $script, $redact, $geometry, $network, $bodies, $paint, $async);
    }

    /** 兼容旧的 `install(int, ?Script, bool)` / `open(int, ?Script, bool)` 调用。 */
    public static function fromLegacy(self|int|null $options, ?Script $script, bool $redact): self
    {
        if ($options instanceof self) {
            if ($script !== null || $redact !== true) {
                throw new \InvalidArgumentException('传 RecordOptions 时不要再给 script / redactBodies；用 with*() 派生');
            }

            return $options;
        }

        $capacity = $options ?? self::DEFAULT_CAPACITY;

        return self::of(capacity: $capacity, script: $script, redact: $redact);
    }

    /** @return array{capacity: int, geometry: string, network: bool, bodies: bool, paint: bool, async: bool, redact: bool} */
    public function toManifest(): array
    {
        return [
            'capacity' => $this->capacity,
            'geometry' => $this->geometry->value,
            'network' => $this->network,
            'bodies' => $this->bodies,
            'paint' => $this->paint,
            'async' => $this->async,
            'redact' => $this->redact,
        ];
    }

    /** @param array<string, mixed> $raw */
    public static function fromManifest(array $raw): self
    {
        $geometry = GeometryCapture::tryFrom((string) ($raw['geometry'] ?? GeometryCapture::Changed->value));
        if ($geometry === null) {
            throw new \InvalidArgumentException('不认识的几何采集档位：' . (string) ($raw['geometry'] ?? ''));
        }

        return new self(
            capacity: \max(1, (int) ($raw['capacity'] ?? self::DEFAULT_CAPACITY)),
            redact: (bool) ($raw['redact'] ?? true),
            geometry: $geometry,
            network: (bool) ($raw['network'] ?? true),
            bodies: (bool) ($raw['bodies'] ?? false),
            paint: (bool) ($raw['paint'] ?? false),
            async: (bool) ($raw['async'] ?? false),
        );
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function script(): ?Script
    {
        return $this->script;
    }

    public function redact(): bool
    {
        return $this->redact;
    }

    public function geometry(): GeometryCapture
    {
        return $this->geometry;
    }

    public function network(): bool
    {
        return $this->network;
    }

    public function bodies(): bool
    {
        return $this->bodies;
    }

    public function paint(): bool
    {
        return $this->paint;
    }

    public function async(): bool
    {
        return $this->async;
    }

    /** 段耗时量具只在需要分段归因的档位开；frames-only 不付这笔税。 */
    public function profileStages(): bool
    {
        // 绘制域也要：`repaint-storm` 拿 paint 段耗时与重画节点数一起看才有区分度，
        // 只给节点数会把"画了 400 个便宜盒"和"画了 3 个天价盒"排成一档。
        return $this->geometry !== GeometryCapture::Off || $this->network || $this->bodies || $this->paint;
    }

    public function withCapacity(int $capacity): self
    {
        return new self($capacity, $this->script, $this->redact, $this->geometry, $this->network, $this->bodies, $this->paint, $this->async);
    }

    public function withScript(?Script $script): self
    {
        return new self($this->capacity, $script, $this->redact, $this->geometry, $this->network, $this->bodies, $this->paint, $this->async);
    }

    public function withRedact(bool $redact): self
    {
        return new self($this->capacity, $this->script, $redact, $this->geometry, $this->network, $this->bodies, $this->paint, $this->async);
    }

    public function withGeometry(GeometryCapture $geometry): self
    {
        return new self($this->capacity, $this->script, $this->redact, $geometry, $this->network, $this->bodies, $this->paint, $this->async);
    }

    public function withNetwork(bool $network): self
    {
        return new self($this->capacity, $this->script, $this->redact, $this->geometry, $network, $this->bodies, $this->paint, $this->async);
    }

    public function withBodies(bool $bodies): self
    {
        return new self($this->capacity, $this->script, $this->redact, $this->geometry, $this->network, $bodies, $this->paint, $this->async);
    }

    public function withPaint(bool $paint): self
    {
        return new self($this->capacity, $this->script, $this->redact, $this->geometry, $this->network, $this->bodies, $paint, $this->async);
    }

    public function withAsync(bool $async): self
    {
        return new self($this->capacity, $this->script, $this->redact, $this->geometry, $this->network, $this->bodies, $this->paint, $async);
    }
}
