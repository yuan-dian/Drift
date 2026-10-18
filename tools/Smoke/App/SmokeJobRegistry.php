<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Job;

/** @internal 冒烟夹具：worker 侧的**注册表**（UI 侧也用同一份 —— 两侧必须一致）。 */
final class SmokeJobRegistry
{
    /** @return array<string, callable(array<string, mixed>): Job> */
    public static function make(): array
    {
        return [
            'smoke.sum' => static function (array $args): Job {
                return new NamedSumJob((int) ($args['to'] ?? 0));
            },
            'smoke.boom' => static function (array $args): Job {
                return new NamedBoomJob((string) ($args['message'] ?? 'boom'));
            },
            'smoke.exit' => static function (array $args): Job {
                return new NamedExitJob();
            },
            'smoke.sleep' => static function (array $args): Job {
                return new NamedSleepJob((int) ($args['ms'] ?? 0));
            },
        ];
    }
}
