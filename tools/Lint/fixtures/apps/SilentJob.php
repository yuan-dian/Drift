<?php
declare(strict_types=1);

namespace App;

/** 夹具：`await-progress` 的不该响形态（不报进度的 Job）。 */
final class SilentJob
{
    public function run(): void
    {
    }
}
