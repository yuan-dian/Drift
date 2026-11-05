<?php
declare(strict_types=1);

namespace App;

use Drift\Async\AsyncScope;
use Drift\Async\Future;

/** 夹具：`await-scope` 的两种越界 —— 属性链 / 函数内赋值偷带作用域。 */
final class AwaitScopeBad
{
    private AsyncScope $scope;

    public function __construct(AsyncScope $scope)
    {
        $this->scope = $scope;
    }

    public function viaProperty(Future $f): void
    {
        $this->scope->await($f);
    }

    public function viaLocal(Future $f): void
    {
        $co = $this->scope;
        $co->sleep(10);
        $co->await($f);
    }
}
