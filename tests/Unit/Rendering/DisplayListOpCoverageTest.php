<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Rendering\Canvas;
use Drift\Rendering\DisplayList;

/**
 * op 注册面的静态对账：`OP_*` 常量、`replay()` 分支、`record()` 白名单必须同集。
 *
 * ★ 新增 `OP_*` 时最容易漏的是 `replay()` 的分支 —— 那条命令会被静默丢掉，
 *   现象是"矩形全对、内容没了"，解释器与 golden 都不会报错。
 * ★ 第二个漏点是 `record()` 的深度计数：漏补一个栈 op ⇒ `assertBalanced()`
 *   查不出未配对，而画面已经错了。
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
            'DisplayList · 值域上界 == OP_DRAW_LIST（record 白名单边界）',
            max($values) === DisplayList::OP_DRAW_LIST,
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

    public function testRecordDepthCountersCoverEveryStackOp(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(DisplayList::class))->getFileName());
        $body = $this->methodBody($src, 'public function record(');
        $pos = strpos($body, '$this->length++;');

        check(
            'DisplayList · 定位到 record() 的深度计数段（白名单检查在它之前）',
            $pos !== false,
            'body=' . substr($body, 0, 40),
        );

        // 期望集合在这里按定义列出，不从被测实现反推：新增栈 op 漏补计数 ⇒ 红。
        $expected = [
            'OP_LAYER_POP', 'OP_LAYER_PUSH', 'OP_POP_CLIP', 'OP_PUSH_CLIP',
            'OP_PUSH_CLIP_PATH', 'OP_RESTORE', 'OP_SAVE',
        ];
        $counted = $pos === false ? [] : $this->opsIn(substr($body, $pos));
        sort($counted);

        check(
            'DisplayList · record() 深度计数覆盖全部栈 op（漏一个 assertBalanced 就查不出）',
            $counted === $expected,
            'missing=' . implode(',', array_diff($expected, $counted))
                . ' extra=' . implode(',', array_diff($counted, $expected)),
        );
        check(
            'DisplayList · 一次性 op 不进深度计数（否则帧末会假红）',
            !in_array('OP_BLUR_BACKDROP', $counted, true),
            'counted=' . implode(',', $counted),
        );

        $this->flushChecks();
    }

    /**
     * 接口形状 ↔ 命令流：新增 push/pop 方法却漏一条栈 op，或反过来，都会让
     * 「作用域化」的替代方案（04 §4.1）失去依据 ⇒ 这里直接对账，不靠人评审。
     */
    public function testCanvasStackMethodsMatchDisplayListStackOps(): void
    {
        $contract = [
            'save'         => 'OP_SAVE',
            'restore'      => 'OP_RESTORE',
            'pushClip'     => 'OP_PUSH_CLIP',
            'popClip'      => 'OP_POP_CLIP',
            'pushLayer'    => 'OP_LAYER_PUSH',
            'popLayer'     => 'OP_LAYER_POP',
            'pushClipPath' => 'OP_PUSH_CLIP_PATH',
        ];

        $methods = [];
        foreach ((new \ReflectionClass(Canvas::class))->getMethods() as $method) {
            $name = $method->getName();
            if ($name === 'save' || $name === 'restore'
                || str_starts_with($name, 'push') || str_starts_with($name, 'pop')) {
                $methods[] = $name;
            }
        }
        $declared = array_keys($contract);
        sort($methods);
        sort($declared);

        check(
            'Canvas · 栈方法集合恰好是登记的 7 个（新增 push*/pop* 必须同步 op）',
            $methods === $declared,
            'actual=' . implode(',', $methods),
        );

        $ops = $this->opConstants();
        $mapped = array_values($contract);
        $unknown = array_values(array_diff($mapped, array_keys($ops)));
        sort($mapped);
        check(
            'Canvas · 每个栈方法都映射到真实的 OP_* 常量',
            $unknown === [],
            'unknown=' . implode(',', $unknown),
        );

        $src = (string) file_get_contents((string) (new \ReflectionClass(DisplayList::class))->getFileName());
        $body = $this->methodBody($src, 'public function record(');
        $pos = strpos($body, '$this->length++;');
        $counted = $pos === false ? [] : $this->opsIn(substr($body, $pos));
        sort($counted);
        check(
            'Canvas · 栈方法 ↔ record() 深度计数的栈 op 同集',
            $mapped === $counted,
            'missing=' . implode(',', array_diff($mapped, $counted))
                . ' extra=' . implode(',', array_diff($counted, $mapped)),
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
        $got = $this->opsIn($fake);

        check(
            'DisplayList · 对账提取器能发现缺一个分支（判据会红）',
            count($got) === count($names) - 1 && !in_array($names[0], $got, true),
            'got=' . implode(',', $got),
        );

        $this->flushChecks();
    }

    public function testExtractorStopsAtMethodEnd(): void
    {
        // 变异自检：提取器若一路截到文件末尾，会把后面方法的 OP_* 也算进 replay 集合。
        $fake = <<<'PHP'
    public function replay(Canvas $canvas): void
    {
        if ($op === self::OP_SAVE) { }
    }

    public function later(): void
    {
        if ($x === self::OP_DRAW_RECT) { }
    }
PHP;
        $got = $this->opsIn($this->methodBody($fake, 'public function replay('));

        check(
            'DisplayList · replay 提取器停在方法体结束（不误纳后面方法的 OP_*）',
            $got === ['OP_SAVE'],
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

        return $this->opsIn($this->methodBody($src, 'public function replay('));
    }

    /**
     * 按花括号配平截出一个方法体。这两个方法体里没有含花括号的字符串，
     * 所以字符级配平够用；截断到方法结束才不会把后面方法里的 OP_* 算进来。
     */
    private function methodBody(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            return '';
        }
        $open = strpos($src, '{', $start);
        if ($open === false) {
            return '';
        }

        $depth = 0;
        $end = $open;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        return substr($src, $open, $end - $open + 1);
    }

    /** @return list<string> 去重后的 OP_* 名字 */
    private function opsIn(string $body): array
    {
        preg_match_all('/self::(OP_[A-Z_]+)/', $body, $m);

        return array_values(array_unique($m[1]));
    }
}
