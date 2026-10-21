<?php
declare(strict_types=1);

namespace App;

/**
 * attribute-unqualified 的"该响"夹具。
 *
 * 三处都是命名空间内漏反斜杠的内建编译期注解：
 *   `#[Override]`      ⇒ 解析成 `App\Override`，静默无效
 *   `#[Getter]`        ⇒ 解析成 `App\Getter`，不生成 getter
 *   `#[Immutable]`     ⇒ 解析成 `App\Immutable`，不校验
 */
class AttributeBad
{
    #[Override]
    public function build(): string
    {
        return 'bad';
    }

    #[Getter]
    private string $name = '';

    #[Immutable]
    public function touch(): void
    {
    }
}
