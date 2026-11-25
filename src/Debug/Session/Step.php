<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 脚本里的一步：**一个时间戳 + 一个种类 + 一份扁平标量数据**。
 *
 * ## 为什么值只能是标量
 *
 * 脚本要能存成 JSON、跨进程传、跨机器比对。`Element` / `Canvas` / Skia handle /
 * 整棵子树一旦进来，脚本就变成"只能在录它的那个进程里读"的东西 —— 那正是要避免的。
 * 这里**在构造处拒绝**，而不是靠注释约定：一份混进了对象的脚本，等到序列化时才爆，
 * 就分不清是"谁写坏的"。
 *
 * 扁平是硬约束（不做"允许一层嵌套"）：嵌套只要开一个口子，第二个口子就会以
 * "就这一次"的理由出现。效果的具名参数写成 `data` 里的额外键即可
 * （见 {@see self::effect()}）。
 *
 * ## 数字统一成"JSON 能原样往返"的形态
 *
 * `json_encode(400.0)` 写出的是 `400`，读回来是 int。如果构造时保留 float，
 * 同一份脚本"存盘再读"之后 `toArray()` 就不再逐个相等 —— 一个只由编码方式造成的
 * 假差异，却会让"逐值比对"的判据和摘要都变得不可信。所以没有小数部分的浮点在
 * 构造时收成整数；读回时输入解码再按字段的类型转回 float（坐标、delta 都是）。
 *
 * `t` 例外：它**始终**是 float，因为时间戳的小数部分有语义。
 *
 * ## 时间戳可以是 null
 *
 * `dispatchInput($event)` 不传时间戳是**合法**的（测试与解释器就这样），语义与传
 * 一个数字不同（`after()` 的基准、双击判定都受影响）⇒ 脚本如实记 `null`，
 * 重放时也传 `null`。拿 0.0 冒充会把"没时间"变成"时刻为零"。
 *
 * @api
 */
final readonly class Step
{
    /** 帧边界（`t` = 传给 `frame()` 的时间戳） */
    public const string FRAME = 'frame';

    /** 按键（`chord` + `text`） */
    public const string KEY = 'key';

    /** 指针（`phase` / `x` / `y` / `button` / 修饰键 / `clickCount`） */
    public const string POINTER = 'pointer';

    /** 滚轮（`x` / `y` / `delta` / `axis`） */
    public const string SCROLL = 'scroll';

    /** 驱动方注册的具名副作用（异步结算、换页……），`name` + 扁平参数 */
    public const string EFFECT = 'effect';

    /**
     * 高亮节点（`node`，`0` = 清除）。
     *
     * ★ 它**不是输入**：不改树、不改布局、不进 `Facts`，只让下一次绘制多画一层描边。
     *   但它必须在这里 —— 见 {@see self::highlight()} 的"为什么进脚本"。
     */
    public const string HIGHLIGHT = 'highlight';

    /** 能记但**不能重放**的输入（未知事件类）；重放时明确失败，不静默跳过 */
    public const string OPAQUE = 'opaque';

    public const array KINDS = [
        self::FRAME,
        self::KEY,
        self::POINTER,
        self::SCROLL,
        self::EFFECT,
        self::HIGHLIGHT,
        self::OPAQUE,
    ];

    /** @var array<string, scalar|null> 归一化后的数据（只读） */
    public readonly array $data;

    /**
     * 数据不走构造器提升：它要**归一化后再存**（见类注释里的数字规则），
     * 提升会在构造体跑之前就把原值写进只读属性。
     *
     * @param array<string, scalar|null> $data
     *
     * @throws \InvalidArgumentException 种类未知，或数据里出现了非标量
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?float $t,
        array $data = [],
    ) {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(
                "未知的步骤种类：{$kind}（可用：" . \implode(' / ', self::KINDS) . '）',
            );
        }

        $clean = [];
        foreach ($data as $key => $value) {
            if (!\is_string($key)) {
                throw new \InvalidArgumentException("步骤 {$kind} 的数据键必须是字符串");
            }
            if (\is_float($value) && \is_finite($value) && $value === (float) (int) $value && \abs($value) < 9.0e15) {
                $clean[$key] = (int) $value;
                continue;
            }
            if (\is_scalar($value) || $value === null) {
                $clean[$key] = $value;
                continue;
            }

            throw new \InvalidArgumentException(
                "步骤 {$kind} 的数据 {$key} 只能是标量，实际是 " . \get_debug_type($value)
                . '：脚本必须能存成 JSON 并跨进程传，不能带对象或数组',
            );
        }

        $this->data = $clean;
    }

    public static function frame(float $t): self
    {
        return new self(self::FRAME, $t);
    }

    /**
     * 具名副作用：`$args` 平铺进 `data`，`name` 是保留键。
     *
     * @param array<string, scalar|null> $args
     */
    public static function effect(string $name, array $args, ?float $t): self
    {
        if ($name === '' || $name === 'name') {
            throw new \InvalidArgumentException("效果名不能为空，也不能叫 name（它是保留键）");
        }
        if (isset($args['name'])) {
            throw new \InvalidArgumentException("效果参数不能用保留键 name");
        }

        return new self(self::EFFECT, $t, ['name' => $name] + $args);
    }

    public static function opaque(string $class, ?float $t): self
    {
        return new self(self::OPAQUE, $t, ['class' => $class]);
    }

    /**
     * 高亮一个节点；`$node = 0` 是清除。
     *
     * ## 为什么注解要进脚本，而不是只存在录制器里
     *
     * `Script::digest()` 只覆盖 steps。注解若只记在记录层，"同一份脚本跑两遍"
     * 会让"一次高亮了一次没高亮"的两次运行拿到**同一个驱动指纹** —— 差异被
     * 指纹掩盖，而指纹的全部价值就是不让差异被掩盖。
     *
     * 进了脚本之后，`--save` / `--replay` / `--remote` 全部自然覆盖：重放的人
     * 不必知道"高亮该怎么重放"，它只是一步。
     *
     * ## 为什么 `0` 是清除而不是用 `null`
     *
     * 数据键只能是标量。`null` 也是标量，但"没给这个键"与"给了 null"要分开读，
     * 而节点号本身从 1 起（{@see Recorder::$seq}）—— 用 0 当哨兵不会和任何真号撞。
     */
    public static function highlight(int $node, ?float $t): self
    {
        if ($node < 0) {
            throw new \InvalidArgumentException("高亮的节点号不能为负：{$node}（0 表示清除）");
        }

        return new self(self::HIGHLIGHT, $t, ['node' => $node]);
    }

    /** @return array{kind: string, t: float|null, data: array<string, scalar|null>} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 't' => $this->t, 'data' => $this->data];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @throws \InvalidArgumentException 形状不对
     */
    public static function fromArray(array $raw): self
    {
        $kind = $raw['kind'] ?? null;
        if (!\is_string($kind)) {
            throw new \InvalidArgumentException('步骤缺少 kind');
        }
        $t = $raw['t'] ?? null;
        if ($t !== null && !\is_int($t) && !\is_float($t)) {
            throw new \InvalidArgumentException("步骤 {$kind} 的 t 必须是数字或 null");
        }
        $data = $raw['data'] ?? [];
        if (!\is_array($data)) {
            throw new \InvalidArgumentException("步骤 {$kind} 的 data 必须是映射");
        }

        /** @var array<string, scalar|null> $data */
        return new self($kind, $t === null ? null : (float) $t, $data);
    }

    /** 一句话描述，给报告与错误信息用。 */
    public function describe(): string
    {
        $text = $this->data['text'] ?? '';
        $chord = (string) ($this->data['chord'] ?? '');

        return match ($this->kind) {
            self::FRAME => '帧@' . \number_format((float) $this->t, 1, '.', ''),
            // 有可打印文本就显示文本（`井`），否则显示键名（`Tab`）。
            self::KEY => '按键 ' . (\is_string($text) && $text !== '' ? $text : $chord),
            self::POINTER => \sprintf('指针 %s(%s,%s)', (string) ($this->data['phase'] ?? ''), (string) ($this->data['x'] ?? ''), (string) ($this->data['y'] ?? '')),
            self::SCROLL => \sprintf('滚轮(%s,%s)×%s', (string) ($this->data['x'] ?? ''), (string) ($this->data['y'] ?? ''), (string) ($this->data['delta'] ?? '')),
            self::EFFECT => '效果 ' . (string) ($this->data['name'] ?? ''),
            self::HIGHLIGHT => (int) ($this->data['node'] ?? 0) > 0
                ? '高亮 #' . (string) $this->data['node']
                : '清除高亮',
            default => '不可重放 ' . (string) ($this->data['class'] ?? ''),
        };
    }
}
