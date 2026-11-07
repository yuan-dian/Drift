<?php
declare(strict_types=1);

namespace Drift\State;

/**
 * 值相等契约：让 `SubjectBuilder` 的 `select` 门控按值比较，而不是按对象身份。
 *
 * ★ 为什么是显式接口：PHP `==` 对标量有隐式转换，`===` 对对象又只比身份，
 *   两头都不适合当默认；「算不算同一个值」只能由值对象自己回答。
 * ★ 不要求实现者不可变：相等语义由实现者保证，框架只负责在门控点问一次。
 *
 * @api
 */
interface Equatable
{
    public function equals(Equatable $other): bool;
}
