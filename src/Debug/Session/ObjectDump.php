<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;
use UnitEnum;

/**
 * 活对象的一次性内省 dump（A55：交给 PHP 原语，不自建反射框架）。
 *
 * ## 定位：内省，不是证据
 *
 * 输入是**当下**的活对象，输出是给人当场看的 JSON-safe 结构。它不进证据包、
 * 不进指纹、不写 Recorder —— 一帧过去后活对象已变，把当时的字段写进包等于
 * 声称一件无法复算的事。查询层的 {@see Query::props()} 与 dev 壳的
 * `Evaluator` 共用这一份实现：两个入口看到同一个对象，结论必须一致。
 *
 * ## 为什么反射而不是 `__debugInfo()`
 *
 * `__debugInfo()` 由**被观测的类**定义：全仓没有一个类实现它，Widget / State
 * 的私有配置（`mods` / `draft` / `caret`）因此一个都看不到。让框架给几十个控件
 * 逐个写 `__debugInfo` 就是 A43 批判过的"每个控件写 debugFillProperties，漏写
 * 静默少一屏"。ReflectionProperty 直接读到所有声明层的 private 字段，零侵入
 * 全覆盖，新控件自动可见 —— 一份实现，不再有"每个类自己说"的第二套协议。
 *
 * ## 三条不能换的规则
 *
 * ① **逐层上溯，按声明类归属**。`getProperties()` 不返回父类的 private 字段，
 *    而 Widget 的配置核心（`mods` / `key` / `focus`）都在基类；逐层取且只收
 *    declaringClass 恰是该层的字段，既不漏也不重复计数。
 * ② **回指不展开**。Widget ↔ State ↔ Element 互相指，遇到已展开对象回 `ref`；
 *    否则一个 `$node` dump 会拉进整棵活树。
 * ③ **值先收成文本再脱敏**。NAN / INF 不是合法 JSON，闭包与资源不能序列化，
 *    `__toString()` 的值对象（Color）收成文本比逐字段展开可读。之后统一过一遍
 *    {@see Redactor}，规则表只有一份。
 *
 * @api 由 Query::props 与 Evaluator 共用；L2 内省，不进 AOT 产物
 */
final class ObjectDump
{
    /** 字符串超过就截断并报原长。 */
    private const int STRING_LIMIT = 200;

    /** 深度 / 每层字段 / 总节点三道预算，任一耗尽都自报而不是静默。 */
    private const int DEFAULT_MAX_DEPTH = 3;
    private const int DEFAULT_MAX_FIELDS = 120;
    private const int NODE_BUDGET = 200;

    /**
     * 框架内部态：挂载状态与 State 的框架字段，不是控件配置。
     *
     * ★ 按**声明类**判，不按字段名全局排：`widget` / `element` 在 State 基类是
     *   内部指针，但控件自己也可能有个叫 `widget` 的配置字段，全局排会误伤。
     *   名单只有框架基类这几项 —— 业务字段永远全收，新控件自动可见（A43①）。
     */
    private const array INTERNAL = [
        'Widget' => ['sealed' => true],
        'State' => [
            'widget' => true, 'element' => true, 'dirty' => true, 'disposers' => true,
            'watchSlots' => true, 'reactiveDeps' => true, 'reactiveCleanupHooked' => true,
            'nextDisposerId' => true, 'initializing' => true, 'initialized' => true,
            'activated' => true, 'lastTickedFrame' => true, 'lastTickedMore' => true,
        ],
    ];

    /** 一次性缓存反射能力：AOT 下反射部分能力，读不到 private 就整域自报不可用，不返回空字段让人误以为"没有配置"（A43⑤）。 */
    private static ?bool $reflectionAvailable = null;

    public static function available(): bool
    {
        if (self::$reflectionAvailable === null) {
            try {
                // 读自己的一个真实 private 静态字段：这是"能否反射读 private"的最小验证。
                // 不能用常量 —— ReflectionProperty 读不到 class constant，会假阴性。
                $property = new ReflectionProperty(self::class, 'reflectionAvailable');
                self::$reflectionAvailable = $property->getValue() === null;
            } catch (\Throwable) {
                self::$reflectionAvailable = false;
            }
        }

        return self::$reflectionAvailable;
    }

    /**
     * 反射读一个活对象的全部声明字段（含各层 private），编成 JSON-safe 结构。
     *
     * @return array<string, mixed> 字段表与裁剪账
     */
    public static function fields(object $value, int $maxDepth = self::DEFAULT_MAX_DEPTH, int $maxFields = self::DEFAULT_MAX_FIELDS, bool $redact = true): array
    {
        $budget = ['nodes' => self::NODE_BUDGET];
        $depth = \max(0, \min(8, $maxDepth));
        $width = \max(1, \min(400, $maxFields));
        $seen = [];
        $cut = 0;
        $cycles = 0;

        [$fields, $omitted] = self::declaredFields($value, $depth, $width, $seen, $cut, $cycles, $budget);

        $redacted = 0;
        if ($redact) {
            $fields = self::redactFields($fields, $redacted);
        }

        return [
            'fields' => $fields,
            'omitted' => $omitted,
            'cut' => $cut,
            'cycles' => $cycles,
            'redacted' => $redacted,
            'redact' => $redact,
        ];
    }

    /**
     * 反射读一个活对象的字段**原始值**（名 ⇒ 原值，不编码），供 `Evaluator` 用。
     *
     * eval 的回值按 PHP 原语呈现（裸值、自己递归），但"看到哪些字段"必须与
     * props 一致 —— 所以逐层上溯与 INTERNAL 排除名单走同一份逻辑，只是这里
     * 返回原值交给调用方递归，不在这里扣预算。
     *
     * @return array<string, mixed>
     */
    public static function rawFields(object $value): array
    {
        $out = [];
        for ($class = new ReflectionObject($value); $class !== false; $class = $class->getParentClass()) {
            $internal = self::INTERNAL[self::shortName($class->getName())] ?? [];
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                if (isset($internal[$property->getName()]) || isset($out[$property->getName()])) {
                    continue;
                }
                try {
                    $out[$property->getName()] = $property->isInitialized($value)
                        ? $property->getValue($value)
                        : null;
                } catch (\Throwable) {
                    $out[$property->getName()] = null;
                }
            }
        }

        return $out;
    }

    /**
     * 逐层取非静态字段，按声明类短名分组；字段名排序保证输出可复算。
     *
     * @param array<string, true> $seen
     * @param array<string, int>  $budget
     * @return array{0: list<array{name: string, owner: string, value: array<string, mixed>}>, 1: int}
     */
    private static function declaredFields(object $value, int $depth, int $width, array &$seen, int &$cut, int &$cycles, array &$budget): array
    {
        $id = \spl_object_id($value);
        $seen[$id] = true;
        $fields = [];
        $omitted = 0;

        for ($class = new ReflectionObject($value); $class !== false; $class = $class->getParentClass()) {
            $owner = self::shortName($class->getName());
            $internal = self::INTERNAL[$owner] ?? [];
            $properties = [];
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                $properties[] = $property;
            }
            \usort($properties, static fn(ReflectionProperty $a, ReflectionProperty $b): int => \strcmp($a->getName(), $b->getName()));
            foreach ($properties as $property) {
                $name = $property->getName();
                if (isset($internal[$name])) {
                    continue;
                }
                if (\count($fields) >= $width) {
                    ++$omitted;
                    continue;
                }
                try {
                    $raw = $property->isInitialized($value) ? $property->getValue($value) : null;
                    $encoded = self::encode($raw, $depth, $seen, $cut, $cycles, $budget);
                } catch (\Throwable) {
                    $encoded = ['kind' => 'unreadable'];
                }
                $fields[] = ['name' => $name, 'owner' => $owner, 'value' => $encoded];
            }
        }
        unset($seen[$id]);

        return [$fields, $omitted];
    }

    /** @param array<string, true> $seen @param array<string, int> $budget */
    private static function encode(mixed $value, int $depth, array &$seen, int &$cut, int &$cycles, array &$budget): array
    {
        if ($value === null || \is_bool($value) || \is_int($value)) {
            return ['kind' => 'scalar', 'value' => $value];
        }
        if (\is_float($value)) {
            // NAN / INF 让 json_encode 直接失败 —— Modifier::$width 默认就是 NAN。
            return ['kind' => 'scalar', 'value' => \is_finite($value)
                ? $value
                : (\is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF'))];
        }
        if (\is_string($value)) {
            $length = \strlen($value);
            return $length <= self::STRING_LIMIT
                ? ['kind' => 'string', 'value' => $value]
                : ['kind' => 'string', 'value' => \mb_strcut($value, 0, self::STRING_LIMIT, 'UTF-8'), 'length' => $length, 'truncated' => true];
        }
        if ($value instanceof \Closure) {
            return ['kind' => 'closure', 'at' => self::closureSite($value)];
        }
        if ($value instanceof UnitEnum) {
            return ['kind' => 'scalar', 'value' => $value::class . '::' . $value->name];
        }
        if (\is_resource($value)) {
            return ['kind' => 'resource', 'type' => \get_resource_type($value)];
        }
        if (\is_array($value)) {
            if ($budget['nodes']-- <= 0) {
                ++$cut;
                return ['kind' => 'cut', 'class' => 'array'];
            }
            $items = [];
            $omitted = 0;
            foreach ($value as $key => $child) {
                if (\count($items) >= self::DEFAULT_MAX_FIELDS) {
                    ++$omitted;
                    continue;
                }
                $items[] = ['key' => (string) $key, 'value' => self::encode($child, $depth - 1, $seen, $cut, $cycles, $budget)];
            }
            return ['kind' => \array_is_list($value) ? 'list' : 'map', 'count' => \count($value), 'items' => $items, 'omitted' => $omitted];
        }
        if (\is_object($value)) {
            if (\method_exists($value, '__toString')) {
                try {
                    return self::encode((string) $value, $depth, $seen, $cut, $cycles, $budget);
                } catch (\Throwable) {
                    // 落到通用对象分支。
                }
            }
            $id = \spl_object_id($value);
            if (isset($seen[$id])) {
                ++$cycles;
                return ['kind' => 'ref', 'to' => $id];
            }
            if ($depth <= 0 || $budget['nodes'] <= 0) {
                ++$cut;
                return ['kind' => 'cut', 'class' => self::shortName($value::class)];
            }
            $seen[$id] = true;
            [$properties] = self::declaredFields($value, $depth - 1, self::DEFAULT_MAX_FIELDS, $seen, $cut, $cycles, $budget);
            unset($seen[$id]);
            return ['kind' => 'obj', 'class' => self::shortName($value::class), 'props' => $properties];
        }

        return ['kind' => 'scalar', 'value' => \get_debug_type($value)];
    }

    /** @param list<array{name: string, owner: string, value: array<string, mixed>}> $fields */
    private static function redactFields(array $fields, int &$count): array
    {
        foreach ($fields as &$field) {
            if (Redactor::isSecretKey((string) $field['name'])) {
                $field['value'] = ['kind' => 'string', 'value' => Redactor::REDACTED];
                ++$count;
                continue;
            }
            $field['value'] = self::redactValue($field['value'], $count);
        }
        unset($field);

        return $fields;
    }

    /** @param array<string, mixed> $value */
    private static function redactValue(array $value, int &$count): array
    {
        if (($value['kind'] ?? null) === 'string') {
            $scrubbed = Redactor::scrub((string) ($value['value'] ?? ''));
            if ($scrubbed['count'] > 0) {
                $value['value'] = $scrubbed['text'];
                $count += $scrubbed['count'];
            }
            return $value;
        }
        foreach (['items', 'props'] as $key) {
            if (!\is_array($value[$key] ?? null)) {
                continue;
            }
            foreach ($value[$key] as &$child) {
                $name = (string) ($child['name'] ?? $child['key'] ?? '');
                if ($name !== '' && Redactor::isSecretKey($name)) {
                    $child['value'] = ['kind' => 'string', 'value' => Redactor::REDACTED];
                    ++$count;
                } elseif (\is_array($child['value'] ?? null)) {
                    $child['value'] = self::redactValue($child['value'], $count);
                }
            }
            unset($child);
        }

        return $value;
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

    private static function shortName(string $class): string
    {
        $at = \strrpos($class, '\\');

        return $at === false ? $class : \substr($class, $at + 1);
    }
}
