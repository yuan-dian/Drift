<?php
declare(strict_types=1);

namespace App;

use \Override;
use \Getter as Gen;

/**
 * attribute-unqualified 的"不该响"夹具。
 *
 * 三种正确写法各一处：
 *   ① 写全限定名 `#[\MustUse]`
 *   ② `use \Override;` 后写短名 `#[Override]`（父类真有 `build()`，PHP 自己也验）
 *   ③ `use ... as ...` 别名 `#[Gen]`（别名不在内建表里 ⇒ 不判，诚实的边界）
 * 另有一处非内建名 `#[Deprecated]`（PHP 原生注解）—— 不在内建表 ⇒ 不报。
 *
 * ★ `#[Override]` 那一处必须**真的有父类方法**：PHP 8.3+ 原生就会校验
 *   `#[\Override]`（漏反斜杠的那版它反而不校验 —— 这正是本规则要抓的静默）。
 */
abstract class AttributeGoodBase
{
    abstract public function build(): string;
}

class AttributeGood extends AttributeGoodBase
{
    #[\MustUse]
    public function mustUse(): int
    {
        return 1;
    }

    #[Override]
    public function build(): string
    {
        return 'good';
    }

    #[Gen]
    private string $name = '';

    #[Deprecated]
    public function legacy(): void
    {
    }
}
