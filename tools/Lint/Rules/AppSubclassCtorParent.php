<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\ClassTable;
use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;
use Drift\Dev\Lint\SourceQuery;

/**
 * `Application` 的子类**覆写构造器就必须调 `parent::__construct()`**（I2 闸门）。
 *
 * ## 这条闸门是给一个真实事故补的（2026-10-25）
 *
 * `ShowcaseApplication::__construct(private ?Clipboard $clipboard = null) {}` ——
 * 2026-10-21 剪贴板接线时覆写了构造器、**没调父构造器**，于是基类在构造器里
 * 建的七个拆分片（`router` / `textEditing` / `scrollInput` / `overlayHost` /
 * `input` / `overlayLayout` / `pumps`）**全部未初始化**。
 *
 * 后果链条（每一步都"不报错"）：
 *
 * ```text
 * ① smoke 全绿 —— 79 处 ->attach() 用的都是调了父构造器的测试应用
 * ② AOT 构建成功 —— 未初始化属性不参与可达性分析
 * ③ 真机启动 exit=255，错误只有一句 "Attempt to read property on null"，
 *    没有文件名、没有行号（AOT 把未初始化属性读成 null，解释器本该抛
 *    "Typed property must not be accessed before initialization"）
 * ④ 定位要靠手插 fwrite 探针，且第一层探针落在 attach() 里完全打不出来
 *    —— 因为崩在调用本身，不在被调方
 * ```
 *
 * ⇒ 这类缺陷**只有真机能证伪**，而代价是一小时的二分。闸门把它变成构建期一条红。
 *
 * ## 判据（宁漏勿误）
 *
 * - 只判**声明在 `src/App/Application.php` 的那个基类**的后代（`ClassTable`
 *   传递继承，含"子类的子类"）。
 * - 类里**没有** `__construct` ⇒ 放行（继承父构造器，本来就没问题）。
 * - 有 `__construct` 且方法体里出现 `parent::__construct` ⇒ 放行。
 * - 其余 ⇒ ERROR，报在构造器声明那一行。
 *
 * ★ **为什么不写成"覆写构造器一律必须调父构造器"**：那会误报大量合法覆写
 *   （父构造器是空壳 / 可选）。**误报比漏报贵** —— 一条误报就会让整条闸门被
 *   加白名单绕开，之后真问题也一起被绕。将来若有第二个基类的构造器承担
 *   "必做初始化"，把它加进 `REQUIRES_PARENT_CTOR` 就行，判据不变。
 *
 * @api 闸门：`tools/lint.php`
 */
final class AppSubclassCtorParent implements Rule
{
    /**
     * 「覆写构造器必须调父构造器」的基类 FQN => 基类为子类准备的东西（报错文案用）。
     *
     * ★ 只收"构造器里有必做初始化"的基类。基类构造器是空壳的话，不调也不会坏
     *   ⇒ 收进来只会制造误报。
     */
    private const REQUIRES_PARENT_CTOR = [
        'Drift\App\Application' => '七个拆分片（router / textEditing / scrollInput / overlayHost / input / overlayLayout / pumps）',
    ];

    public function name(): string
    {
        return 'app-subclass-ctor-parent';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return 'Application 的子类覆写 __construct 必须调 parent::__construct() —— 否则基类建的拆分片全是未初始化（真机 exit=255，无行号）';
    }

    public function check(Project $project): array
    {
        $found = [];
        $table = ClassTable::fromProject($project);

        foreach ($project->files() as $file) {
            foreach (SourceQuery::classes($file) as $class) {
                $base = $this->requiredBase($table, $class['fqn']);
                if ($base === null) {
                    continue; // 不是那几个基类的后代（含基类自己）
                }

                $ctor = $class['methods']['__construct'] ?? null;
                if ($ctor === null) {
                    continue; // 没覆写 ⇒ 继承父构造器，本来就没问题
                }

                if ($this->callsParentCtor($file, $ctor['start'], $ctor['end'])) {
                    continue;
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $ctor['line'],
                    sprintf(
                        '%s 覆写了 __construct 却没调 parent::__construct() —— 基类在构造器里建的%s会全部未初始化；真机表现为启动即崩（exit=255，错误信息无行号）',
                        $class['name'],
                        self::REQUIRES_PARENT_CTOR[$base],
                    ),
                );
            }
        }

        return $found;
    }

    /**
     * 该类是否（传递地）继承某个"构造器有必做初始化"的基类；返回那个基类 FQN。
     *
     * ★ 基类自己不算 —— `Application` 自己的构造器当然不调 `parent::__construct()`。
     */
    private function requiredBase(ClassTable $table, string $fqn): ?string
    {
        foreach (self::REQUIRES_PARENT_CTOR as $base => $_) {
            if ($fqn === $base) {
                return null;
            }
            if ($table->descendsFrom($fqn, $base)) {
                return $base;
            }
        }

        return null;
    }

    /**
     * 方法体 `[start, end]` 里有没有 `parent::__construct`。
     *
     * ★ `parent` 在 PHP 里是 **T_STRING**（不是保留字 token）⇒ 按字符串比，
     *   且**不区分大小写**（PHP 关键字大小写不敏感，`PARENT::__CONSTRUCT()` 合法）。
     * ★ 只扫方法体区间：类里别的属性默认值 / 别的方法里的 `parent::__construct()`
     *   不算数（那正是"看起来调了"的假绿来源）。
     */
    private function callsParentCtor(SourceFile $file, int $start, int $end): bool
    {
        $tokens = $file->tokens();

        for ($i = $start; $i <= $end && $i < count($tokens); $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_STRING || strcasecmp($tok[1], 'parent') !== 0) {
                continue;
            }

            $colon = SourceQuery::nextIndex($tokens, $i + 1);
            if ($colon === null || $tokens[$colon][0] !== T_DOUBLE_COLON) {
                continue;
            }

            $method = SourceQuery::nextIndex($tokens, $colon + 1);
            if ($method === null || !is_array($tokens[$method]) || $tokens[$method][0] !== T_STRING) {
                continue;
            }
            if (strcasecmp($tokens[$method][1], '__construct') === 0) {
                return true;
            }
        }

        return false;
    }
}
