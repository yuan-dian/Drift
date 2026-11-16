<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\CoreJobs;
use Drift\App\Job;
use Drift\App\NamedJob;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `NamedJob` 的**跨进程参数通道**契约 —— 把"加一个异步动作时的静默遗漏"变成红。
 *
 * ## 为什么需要这条判据（它补的是哪个盲区）
 *
 * `StoreHostChainTest` 已经有 roundtrip：真跑一遍链路，拿实际提交的 `args()` 去
 * registry 重建，比较 **`args()` 与 `args()`**。它能抓"注册表漏登记"和"重建后类型变了"，
 * 但抓不到最阴的一类：
 *
 * ```php
 * // 构造器有 5 个参数，args() 只吐了 4 个（漏了 base，而它有默认值）
 * return ['objectKeys' => …, 'time' => …, 'address' => …, 'imageSize' => …];
 * ```
 *
 * 两侧都退化成默认值 ⇒ roundtrip 的 `args()` 依然相等，**判据通过，而参数真的丢了**。
 * 比较 `args()` 与 `args()` 永远不可能发现 `args()` 本身不全。
 *
 * ⇒ 本判据把比较对象换成**构造器签名**（反射读，不受 `args()` 的自我描述影响），
 *   并要求两侧只有一份名单：`array_keys(args()) === 参数名集`，**且顺序相同**
 *   （`JobArgs` 的 `fromArgs()` 是按位置填的）。
 *
 * ## 判据分五段
 *
 * | # | 钉住什么 | 漏了会怎样 |
 * |---|---|---|
 * | ① | `args()` 键集 == 构造参数名集 | 参数静默退化默认值（上面那段） |
 * | ①b | `args()` 键**序** == 构造参数顺序 | 同类型的两个参数被装反（`fromArgs` 按位置填，不报错） |
 * | ② | `args()` 的每个值 JSON 可表达 | 进程池看着能过，换线程池静默丢（只搬 int/str） |
 * | ③ | `name()` 形态合法且全局唯一；与 `NAME` 常量一致 | registry 键与 `name()` 分叉，worker 里"没这个任务" |
 * | ④ | 每个 `NamedJob` 都在某个 registry 里，且能重建出等价 `args()` | UI 侧一切正常，真机 worker 才炸（§17.4.5） |
 *
 * ★ 枚举范围按**目录约定**来（`apps/<应用>/src/Jobs/`、`tools/Smoke/App/`），所以新增
 *   一个 Job **不需要**来这个文件里补一行 —— 放进 Jobs 目录就自动被判据覆盖。
 *   这是它比"每个 Job 手写一条测试"更值的地方。
 * ★ 诚实边界：`probes/` 下的探针自带 `NamedJob` 实现，不在上述目录约定内，
 *   不参与（探针是要跑 AOT 的独立实验，不属于任何应用的 registry）。
 * ★ 样例参数由反射生成（有默认值用默认值，其余按类型给零值），所以不需要为每个
 *   Job 手写夹具 —— 但也因此**构造器里不要放对象参数**：那种 Job 跨进程根本搬不动，
 *   生成样例时会当场红（这是判据想要的结果，不是它做不到）。
 */
final class NamedJobContractTest extends TestCase
{
    /** @var null|array<string, Job> 合并后的注册表（懒建，四个 registry 各一次） */
    private static ?array $registry = null;

    /** @var null|list<class-string<NamedJob>> */
    private static ?array $classes = null;

    #[Test]
    public function 每个任务的参数键与构造参数名一一对应(): void
    {
        foreach (self::namedJobs() as $class) {
            $job = self::instance($class);
            $params = self::parameterNames($class);
            $keys = \array_keys($job->args());

            \sort($params);
            \sort($keys);

            self::assertSame(
                $params,
                $keys,
                $class . '：args() 的键集必须等于构造参数名集。' . PHP_EOL
                . '  构造参数：' . \implode(', ', $params) . PHP_EOL
                . '  args()  ：' . \implode(', ', $keys) . PHP_EOL
                . '  缺的那个参数在 registry 重建时会退化成默认值 —— UI 侧完全正常，'
                . '只有真机 worker 里跑的是错参数（改了名却没同步两侧，症状就是这样）'
            );
        }
    }

    /**
     * `fromArgs()` 是**按位置**填的（`new self(...$values)`）⇒ 键序也是契约。
     *
     * ★ 与上一条分开写：上一条 `sort()` 掉了顺序（它管"有没有漏"），这一条管
     *   "顺序对不对"。漏了这条，`FIELDS` 里两个字段写反就是把两个参数装反 ——
     *   类型相同的参数（如两个 `string`）连 `TypeError` 都不会有，全链路静默。
     */
    #[Test]
    public function 参数键的顺序与构造参数顺序一致(): void
    {
        foreach (self::namedJobs() as $class) {
            $job = self::instance($class);
            $params = self::parameterNames($class);
            $keys = \array_keys($job->args());

            self::assertSame(
                $params,
                $keys,
                $class . '：args() 的键序必须等于构造参数顺序（fromArgs 按位置填）。' . PHP_EOL
                . '  构造参数：' . \implode(', ', $params) . PHP_EOL
                . '  args()  ：' . \implode(', ', $keys) . PHP_EOL
                . '  顺序错了不会报错：同类型的两个参数会被装反，UI 侧照样正常'
            );
        }
    }

    #[Test]
    public function 每个任务的参数值都能过线程序列化(): void
    {
        foreach (self::namedJobs() as $class) {
            $args = self::instance($class)->args();

            self::assertNotFalse(
                \json_encode($args),
                $class . '：args() 里有 JSON 表达不了的值（对象 / 闭包 / NAN）'
            );
            self::assertSame(
                [],
                self::nonScalarPaths($args),
                $class . '：这些位置不是标量/标量数组 —— ThreadTaskHost 只搬 int/str 两个槽，'
                . '进程池能过、换线程池就静默丢：' . \implode('、', self::nonScalarPaths($args))
            );
        }
    }

    #[Test]
    public function 任务名形态合法且全局唯一(): void
    {
        $seen = [];

        foreach (self::namedJobs() as $class) {
            $job = self::instance($class);
            $name = $job->name();

            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9]*\.[a-zA-Z][a-zA-Z0-9_]*$/',
                $name,
                $class . '：name() 应当形如「域.动作」（如 users.fetch）'
            );
            self::assertArrayNotHasKey(
                $name,
                $seen,
                '任务名重复：' . $name . ' 已被 ' . ($seen[$name] ?? '(未知)') . ' 占用'
            );
            $seen[$name] = $class;

            // 有 NAME 常量就必须与 name() 一致：常量喂 registry 键（worker 侧），
            // name() 喂 args 通道（UI 侧）—— 两者分叉时 worker 会答"没这个任务"。
            $constName = $class . '::NAME';
            if (\defined($constName)) {
                self::assertSame(
                    \constant($constName),
                    $name,
                    $class . '：NAME 常量与 name() 返回值不一致'
                );
            }
        }
    }

    #[Test]
    public function 每个任务都已在注册表登记且能原样重建(): void
    {
        $registry = self::registry();

        foreach (self::namedJobs() as $class) {
            $job = self::instance($class);
            $name = $job->name();

            self::assertArrayHasKey(
                $name,
                $registry,
                $class . '（' . $name . '）没在任何 JobRegistry 登记 —— UI 侧提交得出去，'
                . 'worker 进程里却查不到这个名字；这类错只在真机跨进程时才现形'
            );

            $rebuilt = $registry[$name]($job->args());

            self::assertInstanceOf(Job::class, $rebuilt, $name . '：注册表返回的不是 Job');
            self::assertSame(\get_class($job), \get_class($rebuilt), $name . '：重建后类型变了');
            self::assertSame(
                $job->args(),
                $rebuilt->args(),
                $name . '：参数在重建后丢失或被改写（注册表读的键与 args() 吐的键对不上）'
            );
        }
    }

    // ══════════════════ 枚举 ══════════════════

    /** @return list<class-string<NamedJob>> */
    private static function namedJobs(): array
    {
        if (self::$classes !== null) {
            return self::$classes;
        }

        $root = \dirname(__DIR__, 3);
        $classes = [];

        foreach (self::classFiles($root . '/apps/*/src/Jobs/*.php') as $file) {
            $classes[] = self::fqcn($file, 'App\\' . self::studly(\basename(\dirname($file, 3))) . '\\Jobs\\');
        }

        // 框架自带任务（FetchImageJob 等）和应用任务同一套契约，漏了就发现不了。
        foreach (self::classFiles($root . '/src/App/*Job.php') as $file) {
            $classes[] = self::fqcn($file, 'Drift\\App\\');
        }

        foreach (self::classFiles($root . '/tools/Smoke/App/*.php') as $file) {
            $classes[] = self::fqcn($file, 'Drift\\Dev\\Smoke\\App\\');
        }

        $out = [];
        foreach ($classes as $class) {
            if (!\class_exists($class)) {
                continue;   // 目录里可能有 trait / 非类文件
            }
            if (!\is_subclass_of($class, NamedJob::class)) {
                continue;
            }
            $out[] = $class;
        }

        self::assertNotSame([], $out, '一个 NamedJob 都没找到 ⇒ 目录约定变了，这条判据已成空转');

        return self::$classes = $out;
    }

    /** @return array<string, Job> */
    private static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $root = \dirname(__DIR__, 3);
        $map = [];

        // 按约定发现：apps/<名称>/src/Jobs/JobRegistry.php ⇒ App\<名称>\Jobs\JobRegistry。
        // 新加一个应用不需要来改这里 —— 它自己的 registry 会被这条 glob 带到。
        foreach (\glob($root . '/apps/*/src/Jobs/JobRegistry.php') ?: [] as $file) {
            $app = \basename(\dirname($file, 3));
            $class = 'App\\' . self::studly($app) . '\\Jobs\\JobRegistry';
            if (!\class_exists($class)) {
                continue;
            }
            $map += $class::make();
        }

        // 框架自带任务由 CoreJobs 合并进每个应用；它漏登记时所有应用的网络图一起废。
        $map += CoreJobs::register([]);

        // 冒烟夹具的注册表不在 apps/ 下，单独收（它是"跨进程跑 AOT 代码"的证明链）。
        $smoke = 'Drift\\Dev\\Smoke\\App\\SmokeJobRegistry';
        if (\class_exists($smoke)) {
            $map += $smoke::make();
        }

        self::assertNotSame([], $map, '一个注册表都没读到 ⇒ 判据空转');

        return self::$registry = $map;
    }

    private static function fqcn(string $file, string $namespace): string
    {
        return $namespace . \basename($file, '.php');
    }

    /**
     * 只留"文件名看起来是类"的条目。
     *
     * ★ 不是洁癖：`tools/Smoke/App/worker.php` 是 worker 进程入口脚本。对它做
     *   `class_exists('…\\worker')` 会触发 autoload ⇒ **require 那个脚本** ⇒
     *   当场跑起 worker 主循环，整个判据挂死在那里（第一次跑就踩到了）。
     *   PSR-4 的类名必须大写开头，用它当闸门。
     *
     * @return list<string>
     */
    private static function classFiles(string $pattern): array
    {
        $out = [];
        foreach (\glob($pattern) ?: [] as $file) {
            if (\preg_match('/^[A-Z][A-Za-z0-9_]*\.php$/', \basename($file)) === 1) {
                $out[] = $file;
            }
        }

        return $out;
    }

    private static function studly(string $name): string
    {
        return \str_replace(' ', '', \ucwords(\str_replace(['-', '_'], ' ', $name)));
    }

    // ══════════════════ 反射工具 ══════════════════

    /**
     * @param class-string<NamedJob> $class
     *
     * @return NamedJob
     */
    private static function instance(string $class): NamedJob
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();
        if ($ctor === null) {
            $job = new $class();
            self::assertInstanceOf(NamedJob::class, $job, $class . '：无构造器却 new 不出来');

            return $job;
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $args[] = self::sampleValue($class, $param);
        }

        $job = new $class(...$args);
        self::assertInstanceOf(NamedJob::class, $job);

        return $job;
    }

    /** 样例参数：有默认值用默认值，否则按类型给一个"明显不是默认值"的样本 */
    private static function sampleValue(string $class, \ReflectionParameter $param): mixed
    {
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        $type = $param->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';

        if ($name === 'string') {
            return 'sample';
        }
        if ($name === 'int') {
            return 7;
        }
        if ($name === 'float') {
            return 1.5;
        }
        if ($name === 'bool') {
            return true;
        }
        if ($name === 'array' || $name === 'iterable') {
            return ['sample'];
        }

        self::fail(
            $class . '::__construct($' . $param->getName() . ') 的类型是 ' . $name . ' —— '
            . 'NamedJob 的参数必须能跨进程（标量 / 标量数组），对象参数搬不过去。'
            . '把它摊平成数组再传，或把这个任务改成不跨进程的 Job'
        );
    }

    /** @return list<string> 构造参数名（按声明顺序） */
    private static function parameterNames(string $class): array
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();

        return $ctor === null ? [] : \array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $ctor->getParameters()
        );
    }

    /**
     * 递归找"不是标量/标量数组"的路径 —— 只给失败消息用。
     *
     * @return list<string>
     */
    private static function nonScalarPaths(mixed $value, string $path = ''): array
    {
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $key => $one) {
                $out = \array_merge($out, self::nonScalarPaths($one, $path . '[' . (string) $key . ']'));
            }

            return $out;
        }

        return $value === null || \is_scalar($value) ? [] : [$path === '' ? '(根)' : $path];
    }
}
