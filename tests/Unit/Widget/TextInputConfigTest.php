<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\SemanticsValueEditable;
use Drift\Widget\TextArea;
use Drift\Widget\TextField;

/**
 * `TextInputConfig`：两个受控文本输入控件的共有配置面单点。
 *
 * ① 行为：同类走同一条链 —— 配置五个属性 → getter 回读 → 语义值跟随 →
 *   `setSemanticsValue` 经 onChanged 回写（含无回调 false）。证明 trait 读写的是
 *   **各宿主自己的**私有字段，而不是某类缺接线还照样绿。
 * ② 结构：两类不再各自声明 11 个方法、仍 `use TextInputConfig`、字段仍在。
 *   附量具自证防路径写坏时空跑恒绿。
 *
 * ★ ② 跳过 docblock：注释里出现方法名不算违规。
 */
final class TextInputConfigTest extends SmokeTestCase
{
    private const HOSTS = ['TextField', 'TextArea'];
    private const METHODS =
        'value|label|placeholder|onChanged|onSubmitted|valueText|labelText|placeholderText|'
        . 'changedHandler|submittedHandler|setSemanticsValue';

    /** @return array<string, TextField|TextArea> */
    private static function hosts(): array
    {
        return [
            'TextField' => TextField::make(),
            'TextArea' => TextArea::make(),
        ];
    }

    public function testEachHostServesSharedConfigThroughTrait(): void
    {
        foreach (self::hosts() as $name => $widget) {
            $got = [];
            $widget->value('你好')->label('备注')->placeholder('请输入')
                ->onChanged(static function (string $v) use (&$got): void { $got[] = 'change:' . $v; })
                ->onSubmitted(static function (string $v) use (&$got): void { $got[] = 'submit:' . $v; });

            check(
                "$name · 五个配置经 trait 写入并回读",
                $widget->valueText() === '你好'
                    && $widget->labelText() === '备注'
                    && $widget->placeholderText() === '请输入'
                    && $widget->changedHandler() !== null
                    && $widget->submittedHandler() !== null,
                'value=' . $widget->valueText() . ' label=' . (string) $widget->labelText(),
            );
            check(
                "$name · value() 顺带更新语义值（读屏读到同一份）",
                $widget->semanticsValue() === '你好',
                'sem=' . $widget->semanticsValue(),
            );

            check(
                "$name · 语义层写值走 onChanged 回写且不改受控值",
                $widget instanceof SemanticsValueEditable
                    && $widget->setSemanticsValue('世界') === true
                    && $got === ['change:世界']
                    && $widget->valueText() === '你好',
                'got=' . \implode(',', $got),
            );

            $bare = $name === 'TextField' ? TextField::make() : TextArea::make();
            check(
                "$name · 无 onChanged 时语义写值返回 false（不假装成功）",
                $bare->setSemanticsValue('x') === false,
                'bare=' . ($bare->setSemanticsValue('x') ? 'true' : 'false'),
            );
        }

        $this->flushChecks();
    }

    public function testHostsShareTraitAndNoLongerOwnTheMethods(): void
    {
        $bad = [];
        foreach (self::hostFiles() as $file => $src) {
            $code = self::codeOnly($src);
            $short = \basename($file);

            if (!\preg_match('/^[ \t]*use[ \t]+TextInputConfig[ \t]*;/m', $code)) {
                $bad[] = $short . '（未 use trait）';
            }
            if (\preg_match('/function[ \t]+(' . self::METHODS . ')\s*\(/', $code) === 1) {
                $bad[] = $short . '（仍自持共有方法）';
            }
            foreach (['$value', '$label', '$placeholder', '$onChanged', '$onSubmitted'] as $field) {
                if (!\str_contains($code, $field)) {
                    $bad[] = $short . '（丢了字段 ' . $field . '：trait 读写它）';
                }
            }
            if (!\str_contains($code, 'createState()')) {
                $bad[] = $short . '（丢了 createState：各自建不同 State）';
            }
        }

        \sort($bad);
        check(
            '两控件共用 TextInputConfig：不自持共有方法，保留字段与各自 createState',
            $bad === [],
            $bad === [] ? '' : \implode(', ', $bad),
        );
        $this->flushChecks();
    }

    /** 量具自证：扫描确实命中两个宿主，防止路径写坏时上一条恒绿 */
    public function testGaugeSeesBothHosts(): void
    {
        $found = \array_map('\basename', \array_keys(self::hostFiles()));
        \sort($found);

        check(
            '量具自证：确实找到 2 个文本输入宿主（扫描没写坏）',
            $found === ['TextArea.php', 'TextField.php'],
            \implode(', ', $found),
        );
        $this->flushChecks();
    }

    /** @return array<string, string> */
    private static function hostFiles(): array
    {
        $out = [];
        $root = \repoRoot() . '/src/Widget/';
        foreach (self::HOSTS as $name) {
            $file = $root . $name . '.php';
            if (\is_file($file)) {
                $out[$file] = (string) \file_get_contents($file);
            }
        }

        return $out;
    }

    private static function codeOnly(string $src): string
    {
        $keep = [];
        foreach (\explode("\n", $src) as $line) {
            $trim = \ltrim($line);
            if (\str_starts_with($trim, '*') || \str_starts_with($trim, '/*')
                || \str_starts_with($trim, '//') || \str_starts_with($trim, '#')) {
                continue;
            }
            $keep[] = $line;
        }

        return \implode("\n", $keep);
    }
}
