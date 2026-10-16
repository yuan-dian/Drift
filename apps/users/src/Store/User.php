<?php
declare(strict_types=1);

namespace App\Users\Store;

/** 行数据值对象（§A.2.8）—— 不可变，业务层不认识任何 GUI 类型 */
final readonly class User
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
    ) {
    }
}
