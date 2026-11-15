<?php
declare(strict_types=1);

namespace Drift\App\Restoration;

use Drift\Errors;

/**
 * 可恢复状态的唯一容器。值限 JSON 可表达的标量/数组，写盘时不会静默变形。
 *
 * @api
 */
final class RestorationBucket
{
    /** @var array<string, mixed> */
    private array $values = [];

    private bool $dirty = false;

    /** @param array<string, mixed> $initial 来自 store 的装载，不算改动 */
    public function __construct(array $initial = [])
    {
        foreach ($initial as $key => $value) {
            if (!is_string($key)) {
                Errors::report('恢复状态：键必须是字符串');
            }
            self::assertValue($value);
            $this->values[$key] = $value;
        }
    }

    public function read(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function write(string $key, mixed $value): void
    {
        self::assertValue($value);
        if ($this->has($key) && $this->values[$key] === $value) {
            return;
        }
        $this->values[$key] = $value;
        $this->dirty = true;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function clearDirty(): void
    {
        $this->dirty = false;
    }

    /** 外部文件的输入用它判定，不用 assertValue —— 坏文件该降级，不该中止启动。 */
    public static function isValue(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ((!is_string($key) && !is_int($key)) || !self::isValue($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private static function assertValue(mixed $value): void
    {
        if (!self::isValue($value)) {
            Errors::report('恢复状态：值只能是标量或数组（收到 ' . gettype($value) . '）');
        }
    }
}
