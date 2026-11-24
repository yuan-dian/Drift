<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\App\DebugSession;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Center;
use Drift\Widget\Element;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * S3b 判据：属性能从活树读成 JSON 安全的证据，且不污染被观测对象。
 *
 * 每条都挑一个"看起来正常、实际错"的形状：
 * - 面板类名写成 Element 类（StatefulElement）vs Widget 实类（Table）；
 * - 反射漏掉父类 private 字段（Widget::$mods 消失，但报告照样可读）；
 * - 闭包被字符串化 / 被调用、NAN 裸放进 JSON、循环引用无限递归；
 * - 读取改了结构版本号 ⇒ "看一眼"把证据改了；
 * - 节点已卸载与号不存在混成一句 ⇒ 把"地址写错"说成"节点没了"。
 */
final class PropsTest extends SmokeTestCase
{
    private ?DebugSession $session = null;

    protected function tearDown(): void
    {
        $this->session?->close();
        $this->session = null;
        parent::tearDown();
    }

    /** @return array{0: Application, 1: DebugSession} */
    private function open(Widget $root, bool $redact = true): array
    {
        $constraints = BoxConstraints::loose(new Size(300.0, 200.0));
        $app = new PropsApplication($root);
        $app->attach(new SurfaceProbe(), $constraints);
        $this->session = DebugSession::open($app, $constraints, 600, null, $redact);
        $this->session->pump(0.0);

        return [$app, $this->session];
    }

    /** @param array<string, mixed> $answer */
    private function field(array $answer, string $name): ?array
    {
        foreach ($answer['fields'] ?? [] as $field) {
            if (($field['name'] ?? null) === $name) {
                return $field;
            }
        }

        return null;
    }

    public function testIdentityAndDeclaringClassBoundary(): void
    {
        [$app, $session] = $this->open(new StatefulProbe());
        $node = $app->root()?->debugId ?? 0;
        $answer = $session->query('props', ['node' => $node]);

        check('属性查询命中', ($answer['found'] ?? false) === true, \json_encode($answer));
        check('面板类名是 Widget 实类而非 Element 类', ($answer['widget'] ?? null) === 'StatefulProbe', (string) ($answer['widget'] ?? ''));
        check('Element 实类单独可查', ($answer['element'] ?? null) === 'StatefulElement', (string) ($answer['element'] ?? ''));
        check('State 实类单独可查', ($answer['state'] ?? null) === 'ProbedState', (string) ($answer['state'] ?? ''));

        $names = \array_column($answer['fields'] ?? [], 'name');
        check('子类业务字段在', \in_array('count', $names, true), \json_encode($names));
        check('State 基类内部字段不在', !\in_array('dirty', $names, true) && !\in_array('element', $names, true), \json_encode($names));
        check('Widget 的 sealed 内部态不在', !\in_array('sealed', $names, true), \json_encode($names));
        // ReflectionObject::getProperties() 不返回父类 private 字段：逐层上溯才拿得到 mods。
        check('父类 private 配置 mods 在', \in_array('mods', $names, true), \json_encode($names));
        check('mods 归属 Widget 而不是子类', ($this->field($answer, 'mods')['owner'] ?? null) === 'Widget');
        check('计数值是标量 7', ($this->field($answer, 'count')['value']['value'] ?? null) === 7);
        $this->flushChecks();
    }

    public function testClosuresResourcesCyclesAndNanAreEncodedNotRaw(): void
    {
        $widget = new OddWidget();
        $widget->cycle = $widget;
        $widget->resource = \fopen('php://memory', 'r+');
        $widget->color = Color::fromHex('#336699');
        $widget->big = \range(1, 300);
        $widget->password = 'hunter2';
        $widget->note = 'Bearer abcdefghijklmnopqrstuvwxyz12';
        [$app, $session] = $this->open($widget);
        $node = $app->root()?->debugId ?? 0;

        try {
            $answer = $session->query('props', ['node' => $node]);
        } finally {
            if (\is_resource($widget->resource)) {
                \fclose($widget->resource);
            }
        }

        $closure = $this->field($answer, 'callback')['value'] ?? [];
        check('闭包带 closure tag', ($closure['kind'] ?? null) === 'closure', \json_encode($closure));
        check('闭包报定义位置', \str_contains((string) ($closure['at'] ?? ''), 'PropsTest.php:'), (string) ($closure['at'] ?? ''));
        check('闭包没有被调用', OddWidget::$calls === 0, 'calls=' . OddWidget::$calls);

        $resource = $this->field($answer, 'resource')['value'] ?? [];
        check('资源带 resource tag 与类型', ($resource['kind'] ?? null) === 'resource' && ($resource['type'] ?? null) === 'stream', \json_encode($resource));

        // 顶层 cycle 是对象本身；第二次遇到同一个对象才该变成 ref —— 那才是
        // "认出循环"的证据。断言第二层，不是第一层。
        $cycleObject = $this->field($answer, 'cycle')['value'] ?? [];
        $cycle = null;
        foreach ($cycleObject['props'] ?? [] as $property) {
            if ($property['name'] === 'cycle') {
                $cycle = $property['value'];
            }
        }
        check('循环引用给 ref 而不是递归', ($cycle['kind'] ?? null) === 'ref', \json_encode($cycle));
        check('循环引用计数被报出', ($answer['cycles'] ?? 0) >= 1, 'cycles=' . ($answer['cycles'] ?? 0));

        // Modifier::$width 默认 NAN：裸放会让整个证据包 json_encode 失败。
        $mods = $this->field($answer, 'mods')['value'] ?? [];
        $nan = null;
        foreach ($mods['props'] ?? [] as $property) {
            if ($property['name'] === 'width') {
                $nan = $property['value'];
            }
        }
        check('NAN 编成字符串', ($nan['value'] ?? null) === 'NAN', \json_encode($nan));
        check('整份证据能跨 JSON 边界', \json_encode($answer) !== false);

        $color = $this->field($answer, 'color')['value'] ?? [];
        check('有 __toString 的值对象收成文本', ($color['kind'] ?? null) === 'string' && ($color['value'] ?? null) === '#336699FF', \json_encode($color));

        $big = $this->field($answer, 'big')['value'] ?? [];
        check('大数组自报省略数', ($big['kind'] ?? null) === 'list' && ($big['omitted'] ?? 0) > 0, \json_encode(\array_slice($big, 0, 2)));

        check('凭证键原样换掉', ($this->field($answer, 'password')['value']['value'] ?? null) === '[REDACTED]');
        check('无敏感键名的 Bearer 也换掉', \str_contains((string) ($this->field($answer, 'note')['value']['value'] ?? ''), '[REDACTED]'));
        check('脱敏处数被报出', ($answer['redacted'] ?? 0) >= 2, 'redacted=' . ($answer['redacted'] ?? 0));
        check('脱敏状态自报', ($answer['redact'] ?? false) === true);
        $this->flushChecks();
    }

    public function testRedactionCanBeTurnedOffAndSaysSo(): void
    {
        $widget = new OddWidget();
        $widget->password = 'hunter2';
        [$app, $session] = $this->open($widget, false);
        $node = $app->root()?->debugId ?? 0;

        $answer = $session->query('props', ['node' => $node]);
        check('关掉脱敏后能看到原文', ($this->field($answer, 'password')['value']['value'] ?? null) === 'hunter2');
        check('脱敏计数为 0', ($answer['redacted'] ?? -1) === 0);
        check('但必须自报没扫过', ($answer['redact'] ?? true) === false);
        $this->flushChecks();
    }

    public function testBudgetsAreReportedNotSilentlyApplied(): void
    {
        [$app, $session] = $this->open(new OddWidget());
        $node = $app->root()?->debugId ?? 0;

        $shallow = $session->query('props', ['node' => $node, 'maxDepth' => 0]);
        check('深度预算耗尽自报 cut', ($shallow['cut'] ?? 0) > 0, 'cut=' . ($shallow['cut'] ?? 0));

        $few = $session->query('props', ['node' => $node, 'maxFields' => 2]);
        check('字段预算耗尽自报 omitted', ($few['omitted'] ?? 0) > 0, 'omitted=' . ($few['omitted'] ?? 0));
        $this->flushChecks();
    }

    public function testReadingDoesNotChangeTheEvidence(): void
    {
        [$app, $session] = $this->open(new ProbedStatefulWidget());
        $node = $app->root()?->debugId ?? 0;
        $before = \json_encode($session->query('digest'));

        $session->query('props', ['node' => $node]);

        $after = \json_encode($session->query('digest'));
        check('读取属性不改指纹', $after === $before, 'before=' . $before . ' after=' . $after);
        check('读取属性不新增帧', ($session->query('stats')['frames'] ?? -1) === 1);
        $this->flushChecks();
    }

    public function testUnmountedAndUnknownAreDifferentMisses(): void
    {
        [$app, $session] = $this->open(new SwapWidget());
        $unknown = $session->query('props', ['node' => 999999]);
        check('没发过的号报 unknown-node', ($unknown['reason'] ?? null) === 'unknown-node', \json_encode($unknown));

        $child = null;
        $app->root()?->visitChildren(static function (Element $element) use (&$child): void {
            if ($child === null) {
                $child = $element;
            }
        });
        check('夹具确实有子节点', $child !== null);
        $oldNode = $child?->debugId ?? 0;
        $first = $session->query('props', ['node' => $oldNode]);
        check('卸载前能读到', ($first['found'] ?? false) === true);

        SwapState::$last?->flip();
        $session->pump(16.0);

        $gone = $session->query('props', ['node' => $oldNode]);
        check('已卸载节点报 node-unmounted', ($gone['reason'] ?? null) === 'node-unmounted', \json_encode($gone));
        check('已卸载节点不是 unknown', ($gone['reason'] ?? null) !== 'unknown-node');
        $this->flushChecks();
    }

    public function testSnapshotWindowReplacesInsteadOfAppending(): void
    {
        [$app, $session] = $this->open(new SwapWidget());
        $node = $app->root()?->debugId ?? 0;

        $session->query('props', ['node' => $node]);
        $session->query('props', ['node' => $node]);
        $window = $session->query('props', ['node' => $node])['window'] ?? [];

        check('同一节点重读窗口只留一条', ($window['nodes'] ?? -1) === 1, \json_encode($window));
        check('窗口容量自报', ($window['capacity'] ?? 0) === 200);
        check('快照标记 stored', ($session->query('props', ['node' => $node])['stored'] ?? false) === true);
        $this->flushChecks();
    }

    public function testExplicitProtocolOverridesReflection(): void
    {
        [$app, $session] = $this->open(new DescribedWidget());
        $node = $app->root()?->debugId ?? 0;
        $answer = $session->query('props', ['node' => $node]);

        $names = \array_column($answer['fields'] ?? [], 'name');
        check('显式协议给的值在', \in_array('shown', $names, true), \json_encode($names));
        check('显式协议整组替代反射', !\in_array('secretInternal', $names, true), \json_encode($names));
        check('协议值仍走统一编码', ($this->field($answer, 'shown')['value']['value'] ?? null) === 42);
        $this->flushChecks();
    }
}

/** @internal 判据夹具 */
final class PropsApplication extends Application
{
    public function __construct(private readonly Widget $rootWidget)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return $this->rootWidget;
    }
}

/** @internal */
final class StatefulProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new ProbedState();
    }
}

/** @internal */
final class ProbedState extends State
{
    public int $count = 7;

    public function build(BuildContext $context): Widget
    {
        return Text::make('x');
    }
}

/** @internal */
final class ProbedStatefulWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new ProbedState();
    }
}

/** @internal */
final class OddWidget extends StatelessWidget
{
    public static int $calls = 0;

    public mixed $cycle = null;

    public mixed $resource = null;

    public ?Color $color = null;

    /** @var list<int> */
    public array $big = [];

    public string $password = '';

    public string $note = '';

    public \Closure $callback;

    public function __construct()
    {
        parent::__construct();
        self::$calls = 0;
        $this->callback = static function (): void {
            ++OddWidget::$calls;
        };
    }

    public function build(BuildContext $context): Widget
    {
        return Text::make('x');
    }
}

/** @internal */
final class SwapWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new SwapState();
    }
}

/** @internal */
final class SwapState extends State
{
    public static ?self $last = null;

    public bool $alt = false;

    public function initState(): void
    {
        self::$last = $this;
    }

    public function build(BuildContext $context): Widget
    {
        return $this->alt ? Center::make(Text::make('b')) : Text::make('a');
    }

    public function flip(): void
    {
        $this->setState(function (): void {
            $this->alt = true;
        });
    }
}

/** @internal */
final class DescribedWidget extends StatelessWidget
{
    public int $secretInternal = 99;

    /** @return array<string, int> */
    public function debugProps(): array
    {
        return ['shown' => 42];
    }

    public function build(BuildContext $context): Widget
    {
        return Text::make('x');
    }
}
