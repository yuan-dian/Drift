<?php
declare(strict_types=1);

namespace App;

/** 夹具：`cjk-var-name` 的已知错误 —— 双引号插值里变量紧跟中文，两条。 */
final class CjkVarNameBad
{
    public function count(int $count): string
    {
        return "共 $count项";
    }

    public function total(int $n): string
    {
        return "$n条记录";
    }
}
