<?php
declare(strict_types=1);

namespace Drift\App\Restoration;

/**
 * bucket 的类型化视图。不持有第二份值，set 即落进唯一真相源。
 *
 * @api
 */
final class RestorableValue
{
    public function __construct(
        private readonly RestorationBucket $bucket,
        private readonly string $key,
        private readonly mixed $default = null,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function value(): mixed
    {
        return $this->bucket->read($this->key, $this->default);
    }

    public function set(mixed $value): void
    {
        $this->bucket->write($this->key, $value);
    }
}
