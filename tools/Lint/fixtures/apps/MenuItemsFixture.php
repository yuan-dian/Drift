<?php
declare(strict_types=1);

namespace App;

use Drift\State\Subject;

/**
 * `nested-subscribe` 干净用例：单参 items（菜单形态）里的 watch 不该响。
 * 钉住"只有第二参是闭包的 items 才在射程内"这条边界。
 */
final class PlainList
{
    public function build(Subject $source): void
    {
        $this->items([
            $source,
            static fn (Subject $s): mixed => $s->watch(static function (): void {
            }),
        ]);
    }

    /** @param list<mixed> $entries */
    private function items(array $entries): void
    {
        foreach ($entries as $entry) {
            if (\is_callable($entry)) {
                $entry();
            }
        }
    }
}
