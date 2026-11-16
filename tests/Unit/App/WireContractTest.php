<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\JobArgs;
use Drift\App\Wire;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `Wire` 的名单契约 —— 把"加一个字段时的静默遗漏"变成红。
 *
 * ## 它补的是哪个盲区
 *
 * `toArray()` 现在是 trait 生成的（从 `FIELDS` 里逐个读同名属性），所以**名单就是
 * 全部实现**。名单漏一个字段的后果是静默的：
 *
 * ```php
 * private const FIELDS = ['taskId', 'status'];   // 漏了 errorMsg
 * ```
 *
 * `toArray()` 照常工作，只是少一个键 ⇒ 跨进程时那个字段变回默认值，UI 侧一点异常
 * 都看不到。而"名单比对自身"永远发现不了它，所以比较对象必须是**构造器签名**（反射读，
 * 不受名单的自我描述影响）。
 *
 * ## 判据两条
 *
 * | # | 钉住什么 | 漏了会怎样 |
 * |---|---|---|
 * | ① | `FIELDS` == 构造参数名集，**且顺序相同** | 漏字段 ⇒ 跨进程丢值；顺序错 ⇒ `fromArgs()` 按位置填反（同类型参数不报错） |
 * | ② | `FIELDS` 的每一项都是**真属性** | `toArray()` 里 `$this->{$field}` 取不到 ⇒ 解释器 warning、AOT 真机崩 |
 *
 * ★ 枚举按**目录约定**（`src/`、`apps/<应用>/src/` 下大写开头的文件），所以新增一个
 *   值对象 / 任务**不需要**来这里补一行。
 * ★ 诚实边界：只认"构造参数与属性同名"的形态（构造器提升）。刻意把参数存到别的
 *   属性名下（`__construct(string $raw) { $this->value = $raw; }`）会让 ① 报出来 ——
 *   那种形态下 `toArray()` 的键名与参数名本来就该分家，届时需要自己的实现而不是 `Wire`。
 */
final class WireContractTest extends TestCase
{
    #[Test]
    public function 名单与构造参数一一对应且顺序相同(): void
    {
        foreach (self::wireClasses() as $class) {
            $fields = self::fieldsOf($class);
            $params = self::constructorParams($class);

            self::assertSame(
                $params,
                $fields,
                $class . '：FIELDS 必须等于构造参数名（顺序也要一致）。' . PHP_EOL
                . '  构造参数：' . \implode(', ', $params) . PHP_EOL
                . '  FIELDS  ：' . \implode(', ', $fields) . PHP_EOL
                . '  漏一个 ⇒ toArray() 少一个键 ⇒ 跨进程时那个字段静默变回默认值；'
                . '顺序错 ⇒ fromArgs() 按位置把两个同类型参数填反，没有 TypeError'
            );
        }
    }

    #[Test]
    public function 名单里的每一项都是真属性(): void
    {
        foreach (self::wireClasses() as $class) {
            $props = self::propertyNames($class);

            foreach (self::fieldsOf($class) as $field) {
                self::assertContains(
                    $field,
                    $props,
                    $class . '：FIELDS 里的 "' . $field . '" 不是属性 —— '
                    . 'toArray() 的动态读取不到它（解释器只 warning，AOT 真机直接崩）。'
                    . '构造器参数要写成提升属性：__construct(private readonly string $' . $field . ')'
                );
            }
        }
    }

    // ══════════════════ 发现 ══════════════════

    /**
     * 所有 `use Wire` 的具体类。
     *
     * @return list<class-string>
     */
    private static function wireClasses(): array
    {
        $root = \dirname(__DIR__, 3);

        $roots = [['dir' => $root . '/src', 'ns' => 'Drift']];
        foreach (\glob($root . '/apps/*/src') ?: [] as $dir) {
            $app = \basename(\dirname($dir));
            $roots[] = ['dir' => $dir, 'ns' => 'App\\' . \str_replace(' ', '', \ucwords(\str_replace('-', ' ', $app)))];
        }

        $out = [];
        foreach ($roots as $one) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($one['dir'], \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
                $name = $file->getFilename();
                // 只认 PSR-4 类名：小写开头的可能是入口脚本（对脚本做 class_exists 会跑起来）
                if (\preg_match('/^[A-Z][A-Za-z0-9_]*\.php$/', $name) !== 1) {
                    continue;
                }

                $relative = \substr($file->getPathname(), \strlen($one['dir']) + 1, -\strlen('.php'));
                $fqcn = $one['ns'] . '\\' . \str_replace('/', '\\', $relative);

                // ★ 先按**源码**缩候选，再反射：全量 `class_exists` 会触发 `src/` 里
                //   所有类的 autoload，而 `Platform/Win32/ResizeHook.php` 顶层声明了函数
                //   ⇒ 第二次 require 直接 fatal（`Cannot redeclare`）。
                //   只有用到本 trait 的文件才值得加载。
                $source = (string) \file_get_contents($file->getPathname());
                if (\preg_match('/^use\s+Drift\\\\App\\\\(Wire|JobArgs)\s*;/m', $source) !== 1) {
                    continue;
                }

                if (!\class_exists($fqcn)) {
                    continue;
                }
                // `class_uses` 只看直接 use：用了 `JobArgs` 的类不会列出 `Wire`
                $traits = \class_uses($fqcn) ?: [];
                if (!\in_array(Wire::class, $traits, true) && !\in_array(JobArgs::class, $traits, true)) {
                    continue;
                }
                $out[] = $fqcn;
            }
        }

        \sort($out);

        return $out;
    }

    // ══════════════════ 反射工具 ══════════════════

    /** @param class-string $class */
    private static function fieldsOf(string $class): array
    {
        $const = (new \ReflectionClass($class))->getReflectionConstant('FIELDS');
        self::assertNotFalse($const, $class . '：用了 Wire 却没定义 private const FIELDS');

        $value = $const->getValue();
        self::assertIsArray($value, $class . '：FIELDS 必须是数组');

        return \array_values($value);
    }

    /** @param class-string $class */
    private static function constructorParams(string $class): array
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();
        if ($ctor === null) {
            return [];
        }

        $names = [];
        foreach ($ctor->getParameters() as $param) {
            $names[] = $param->getName();
        }

        return $names;
    }

    /** @param class-string $class */
    private static function propertyNames(string $class): array
    {
        $names = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $names[] = $prop->getName();
        }

        return $names;
    }
}
