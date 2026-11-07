<?php
declare(strict_types=1);

namespace App;

use Drift\App\JobProgress;

/** 夹具：`await-progress` 的进度来源（会报进度的 Job）。 */
final class ProgressReportingJob
{
    public function run(): void
    {
        JobProgress::report(0.5);
    }
}
