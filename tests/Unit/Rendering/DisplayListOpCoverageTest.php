<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Rendering\DisplayList;

/**
 * op 注册面的静态对账：`OP_*` 常量、`replay()` 分支、`record()` 白名单必须同集。
 *
 * ★ 新增 `OP_*` 时最容易漏的是 `replay()` 的分支 —— 那条命令会被静默丢掉，
 *   现象是"矩形全对、内容没了"，解释器与 golden 都不会报错。
 */
final class DisplayListOpCoverageTest extends SmokeTestCase
{
    public function testOpsAreContiguousAndReplayCoversAll(): void
    {
        $ops = $this->opConstants();
        $values = array_values($ops);
        sort($values);

        check(
            'DisplayList · OP_* 值域从 1 起连续（record 白名单是闭区间，靠这条兜住）',
            $values === range(1, count($values)),
            'values=' . implode(',', $values),
        );
        check(
            'DisplayList · 值域上界 == OP_TRANSFORM（record 白名单边界）',
            max($values) === DisplayList::OP_TRANSFORM,
            'max=' . max($values),
        );

        $names = array_keys($ops);
        sort($names);
        $replay = $this->replayOps();
        sort($replay);

        check(
            'DisplayList · replay() 覆盖了每一个 OP_*（漏一个就静默丢像素）',
            $replay === $names,
            'missing=' . implode(',', array_diff($names, $replay))
                . ' extra=' . implode(',', array_diff($replay, $names)),
        );

        $this->flushChecks();
    }

    public function testExtractorCatchesAMissingBranch(): void
    {
        // 变异自检：故意少写一个分支，提取器必须少一个 op，否则上面的判据是恒绿的空壳。
        $names = array_keys($this->opConstants());
        $fake = '';
        foreach ($names as $i => $name) {
            if ($i === 0) {
                continue;
            }
            $fake .= "if (\$op === self::{$name}) { }\n";
        }
        preg_match_all('/self::(OP_[A-Z_]+)/', $fake, $m);
        $got = array_values(array_unique($m[1]));

        check(
            'DisplayList · 对账提取器能发现缺一个分支（判据会红）',
            count($got) === count($names) - 1 && !in_array($names[0], $got, true),
            'got=' . implode(',', $got),
        );

        $this->flushChecks();
    }

    /** @return array<string, int> 名字 => 值 */
    private function opConstants(): array
    {
        $ops = [];
        foreach ((new \ReflectionClass(DisplayList::class))->getConstants() as $name => $value) {
            if (strncmp($name, 'OP_', 3) === 0) {
                $ops[$name] = $value;
            }
        }

        return $ops;
    }

    /** @return list<string> replay() 方法体里引用的 OP_* 名字 */
    private function replayOps(): array
    {
        $ref = new \ReflectionClass(DisplayList::class);
        $src = (string) file_get_contents((string) $ref->getFileName());
        $pos = strpos($src, 'public function replay(');
        if ($pos === false) {
            return [];
        }

        preg_match_all('/self::(OP_[A-Z_]+)/', substr($src, $pos), $m);

        return array_values(array_unique($m[1]));
    }
}
