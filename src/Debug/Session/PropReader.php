<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

use Drift\Widget\Element;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Drift\Widget\Widget;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;
use UnitEnum;

/**
 * 属性域的读取器：反射读 Widget / State 配置，编成 JSON 安全的带 tag 值。
 *
 * ## 三条不能换的规则
 *
 * ① **字段界线由声明类回答，不维护名单**。只取 Widget / State **子类自己声明**
 *    的字段（`ReflectionProperty::getDeclaringClass()`）；基类字段里 Widget 侧
 *    除 `sealed`（挂载期状态，不是配置）外全留、State 侧全排除
 *    （`widget` / `element` / `dirty` / `disposers` 都是框架内部态）。
 *    与 `parent-flex` 判据同一条纪律：新增控件自动覆盖，清单不会腐烂。
 *
 * ② **值带 tag**。`NAN` / `INF` 会让 `json_encode` 直接失败，而
 *    `Modifier::$width` 默认就是 `NAN`；闭包与资源根本不能序列化。九类 tag：
 *    `scalar` / `string` / `list` / `map` / `obj` / `closure` / `resource` /
 *    `ref` / `cut`（另有 `unreadable`，反射自身失败时兜底）。
 *
 * ③ **能力探测，不猜构建形态**。一次性读自己的私有字段：读得到才开整域。
 *    AOT 下反射是部分能力，读不到就自报 `reflection-unavailable` —— 返回空数组
 *    会让人以为"这个控件没有配置"，那是比没有更坏的证据。
 *
 * ## 显式协议是增强钩子
 *
 * 对象若有公开 `debugProps(): array`，用它替代反射（控件可以藏掉内部缓存、
 * 给派生量）。没有就走反射，所以新控件不需要写任何东西就可见。
 *
 * @api
 */
final class PropReader
{
    /** 字符串超过就截断并报原长。 */
    private const int STRING_LIMIT = 200;

    /** 单次读取最多展开多少个对象/数组节点。 */
    private const int NODE_LIMIT = 200;

    /** 反射能力缓存：`null` = 还没探过。 */
    private ?bool $available = null;

    /** 只用于能力探测的私有字段（读自己，不引入跨层依赖）。 */
    private int $probe = 1;

    /** 本读取器只在 dev 形态可用；探测失败时 Query 会自报，不返回空证据。 */
    public function available(): bool
    {
        if ($this->available === null) {
            try {
                $property = new ReflectionProperty(self::class, 'probe');
                $this->available = $property->getValue($this) === 1;
            } catch (\Throwable) {
                $this->available = false;
            }
        }

        return $this->available;
    }

    /**
     * @param int $maxDepth  对象/数组展开深度
     * @param int $maxFields 每个对象/数组最多列几项
     */
    public function read(
        Element $element,
        int $node,
        int $frame,
        int $maxDepth,
        int $maxFields,
        bool $redact,
    ): PropSnapshot {
        $t0 = \hrtime(true);
        $widget = $element->widget();
        $state = $element instanceof StatefulElement ? $element->state() : null;
        $budget = [
            'depth' => \max(0, \min(8, $maxDepth)),
            'fields' => \max(1, \min(400, $maxFields)),
            'nodes' => self::NODE_LIMIT,
        ];

        $fields = [];
        $omitted = 0;
        $cut = 0;
        $cycles = 0;
        $seen = [];

        foreach ([[$widget, Widget::class], [$state, State::class]] as [$object, $base]) {
            if ($object === null) {
                continue;
            }

            // 显式协议整组替代反射：控件可以藏掉内部缓存、给派生量。
            $custom = $this->customProps($object);
            if ($custom !== null) {
                foreach ($custom as $name => $raw) {
                    if (\count($fields) >= $budget['fields']) {
                        ++$omitted;
                        continue;
                    }
                    $fields[] = [
                        'name' => (string) $name,
                        'owner' => 'debugProps',
                        'value' => $this->encode($raw, $budget['depth'], $seen, $cut, $cycles, $budget),
                    ];
                }
                continue;
            }

            foreach ($this->groupsOf($object, $base) as $group => $properties) {
                foreach ($properties as $property) {
                    if (\count($fields) >= $budget['fields']) {
                        ++$omitted;
                        continue;
                    }
                    try {
                        $raw = $property->getValue($object);
                    } catch (\Throwable) {
                        $raw = null;
                        $fields[] = [
                            'name' => $property->getName(),
                            'owner' => $group,
                            'value' => ['kind' => 'unreadable'],
                        ];
                        continue;
                    }
                    $fields[] = [
                        'name' => $property->getName(),
                        'owner' => $group,
                        'value' => $this->encode($raw, $budget['depth'], $seen, $cut, $cycles, $budget),
                    ];
                }
            }
        }

        $redacted = 0;
        $fields = $this->redactFields($fields, $redact, $redacted);

        return new PropSnapshot(
            $node,
            $frame,
            self::shortClass($widget::class),
            self::shortClass($element::class),
            $state === null ? null : self::shortClass($state::class),
            $fields,
            $omitted,
            $cut,
            $cycles,
            $redacted,
            $redact,
            (\hrtime(true) - $t0) / 1e6,
        );
    }

    /**
     * 对编码后的字段树统一脱敏：键名由 `Redactor::isSecretKey()` 判，
     * 字符串值由 `Redactor::scrub()` 认 Bearer / JWT 这类没有敏感键名的位置。
     *
     * ★ 放在编码之后而不是编码过程中：规则表只有一份，穿两个参数进递归
     *   容易漏掉某一条分支；后处理只有一处，新增编码分支自动覆盖。
     *
     * @param list<array{name: string, owner: string, value: array<string, mixed>}> $fields
     */
    private function redactFields(array $fields, bool $redact, int &$count): array
    {
        if (!$redact) {
            return $fields;
        }
        foreach ($fields as &$field) {
            if (Redactor::isSecretKey((string) ($field['name'] ?? ''))) {
                $field['value'] = ['kind' => 'string', 'value' => Redactor::REDACTED];
                ++$count;
                continue;
            }
            $field['value'] = $this->redactValue($field['value'] ?? [], $count);
        }
        unset($field);

        return $fields;
    }

    /** @param array<string, mixed> $value */
    private function redactValue(array $value, int &$count): array
    {
        if (($value['kind'] ?? null) === 'string') {
            $scrubbed = Redactor::scrub((string) ($value['value'] ?? ''));
            if ($scrubbed['count'] > 0) {
                $value['value'] = $scrubbed['text'];
                $count += $scrubbed['count'];
            }

            return $value;
        }
        foreach (['props', 'items'] as $key) {
            if (!\is_array($value[$key] ?? null)) {
                continue;
            }
            foreach ($value[$key] as &$child) {
                $name = (string) ($child['name'] ?? $child['key'] ?? '');
                if ($name !== '' && Redactor::isSecretKey($name)) {
                    $child['value'] = ['kind' => 'string', 'value' => Redactor::REDACTED];
                    ++$count;
                    continue;
                }
                if (\is_array($child['value'] ?? null)) {
                    $child['value'] = $this->redactValue($child['value'], $count);
                }
            }
            unset($child);
        }

        return $value;
    }

    /**
     * 控件自带的描述协议（有则整组替代反射；没有或坏了返回 null）。
     *
     * @return array<string, mixed>|null
     */
    private function customProps(object $object): ?array
    {
        if (!\method_exists($object, 'debugProps')) {
            return null;
        }
        try {
            $custom = $object->debugProps();

            return \is_array($custom) ? $custom : null;
        } catch (\Throwable) {
            // 可选的增强坏了 ⇒ 退回反射，不让一个钩子把整域打死。
            return null;
        }
    }

    /**
     * 字段分组：声明类短名 => 字段表（按类名 + 字段名排序，输出可复算）。
     *
     * @return array<string, list<ReflectionProperty>>
     */
    private function groupsOf(object $object, string $base): array
    {
        $byOwner = [];
        // 逐层上溯：ReflectionObject::getProperties() **不返回父类的 private 字段**，
        // 只看它会漏掉 Widget::$mods —— 那正是"这个控件配了什么"最核心的一项。
        // 每层只收 declaringClass 恰是该层的字段，避免把继承来的 public/protected 数两遍。
        $class = $object::class;
        while ($class !== false) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                if ($class === $base) {
                    // State 基类字段全是框架内部态；Widget 基类只有 sealed 是内部态。
                    if ($base === State::class || $property->getName() === 'sealed') {
                        continue;
                    }
                }
                $byOwner[self::shortClass($class)][] = $property;
            }
            $parent = $reflection->getParentClass();
            $class = $parent === false ? false : $parent->getName();
        }
        \ksort($byOwner, \SORT_STRING);
        foreach ($byOwner as &$properties) {
            \usort($properties, static fn(ReflectionProperty $a, ReflectionProperty $b): int =>
                \strcmp($a->getName(), $b->getName()));
        }
        unset($properties);

        return $byOwner;
    }

    /**
     * 值 => JSON 安全的带 tag 结构。
     *
     * @param array<string, mixed>    $seen   已展开对象的 `spl_object_id`
     * @param array<string, int>      $budget 深度 / 每项上限 / 剩余节点数（按引用扣）
     */
    private function encode(
        mixed $value,
        int $depth,
        array &$seen,
        int &$cut,
        int &$cycles,
        array &$budget,
    ): array {
        if ($value === null || \is_bool($value) || \is_int($value)) {
            return ['kind' => 'scalar', 'value' => $value];
        }
        if (\is_float($value)) {
            // NAN / INF 不是合法 JSON 值：裸放会让整个证据包 encode 失败。
            return [
                'kind' => 'scalar',
                'value' => \is_finite($value)
                    ? $value
                    : (\is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')),
            ];
        }
        if (\is_string($value)) {
            $length = \strlen($value);
            if ($length <= self::STRING_LIMIT) {
                return ['kind' => 'string', 'value' => $value];
            }

            return [
                'kind' => 'string',
                'value' => \mb_strcut($value, 0, self::STRING_LIMIT, 'UTF-8'),
                'length' => $length,
                'truncated' => true,
            ];
        }
        if (\is_array($value)) {
            if ($budget['nodes'] <= 0) {
                ++$cut;

                return ['kind' => 'cut', 'class' => 'array'];
            }
            --$budget['nodes'];
            $items = [];
            $omitted = 0;
            foreach ($value as $key => $child) {
                if (\count($items) >= $budget['fields']) {
                    ++$omitted;
                    continue;
                }
                $items[] = [
                    'key' => (string) $key,
                    'value' => $this->encode($child, $depth - 1, $seen, $cut, $cycles, $budget),
                ];
            }

            return [
                'kind' => \array_is_list($value) ? 'list' : 'map',
                'count' => \count($value),
                'items' => $items,
                'omitted' => $omitted,
            ];
        }
        if (\is_object($value)) {
            return $this->encodeObject($value, $depth, $seen, $cut, $cycles, $budget);
        }
        if (\is_resource($value)) {
            return ['kind' => 'resource', 'type' => \get_resource_type($value)];
        }

        return ['kind' => 'scalar', 'value' => \gettype($value)];
    }

    /** @param array<string, mixed> $budget */
    private function encodeObject(
        object $value,
        int $depth,
        array &$seen,
        int &$cut,
        int &$cycles,
        array &$budget,
    ): array {
        // 闭包：不调用、不序列化，只报定义位置 —— "这个回调哪来的"本身是答案。
        if ($value instanceof \Closure) {
            return ['kind' => 'closure', 'at' => self::closureSite($value)];
        }
        if ($value instanceof UnitEnum) {
            return ['kind' => 'scalar', 'value' => $value::class . '::' . $value->name];
        }
        // 有 __toString() 的值对象收成文本（Color ⇒ #RRGGBBAA）。这是通用规则，
        // 不是会腐烂的类名表；抛异常就退回逐字段展开。
        if (\method_exists($value, '__toString')) {
            try {
                return $this->encode((string) $value, $depth, $seen, $cut, $cycles, $budget);
            } catch (\Throwable) {
                // 落到下面的通用对象分支。
            }
        }

        $id = \spl_object_id($value);
        if (isset($seen[$id])) {
            ++$cycles;

            return ['kind' => 'ref', 'to' => $id];
        }
        if ($depth <= 0 || $budget['nodes'] <= 0) {
            ++$cut;

            return ['kind' => 'cut', 'class' => self::shortClass($value::class)];
        }
        --$budget['nodes'];
        $seen[$id] = true;
        $properties = [];
        $omitted = 0;
        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            if (\count($properties) >= $budget['fields']) {
                ++$omitted;
                continue;
            }
            try {
                $child = $property->getValue($value);
            } catch (\Throwable) {
                $child = null;
            }
            $properties[] = [
                'name' => $property->getName(),
                'value' => $this->encode($child, $depth - 1, $seen, $cut, $cycles, $budget),
            ];
        }
        unset($seen[$id]);

        return [
            'kind' => 'obj',
            'class' => self::shortClass($value::class),
            'props' => $properties,
            'omitted' => $omitted,
        ];
    }

    private static function closureSite(\Closure $closure): string
    {
        try {
            $reflection = new \ReflectionFunction($closure);
            $file = $reflection->getFileName();

            return $file === false ? '?' : \basename($file) . ':' . $reflection->getStartLine();
        } catch (\Throwable) {
            return '?';
        }
    }

    private static function shortClass(string $class): string
    {
        $at = \strrpos($class, '\\');

        return $at === false ? $class : \substr($class, $at + 1);
    }
}
