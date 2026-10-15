<?php
declare(strict_types=1);

namespace App;

use Drift\State\SubjectBuilder;

/**
 * 夹具：`cached-subscriber` 的已知错误（§16.6.3 #10 / A4）。
 *
 * - 属性声明类型是 `SubjectBuilder`            ⇒ error
 * - 方法里 `$this->x = SubjectBuilder::make(…)` ⇒ error
 */
final class CachedSubscriberFixture
{
    private ?SubjectBuilder $builder = null;

    public function make(): void
    {
        $this->builder = SubjectBuilder::make($this->user);
    }
}
