<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\DevTools\App\DebugSession;
use Drift\DevTools\Session\Digest;
use Drift\DevTools\Session\Query;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Center;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * A44 判据：点选反查必须给出**可追问的完整命中链**，且坐标口径与真输入一致。
 *
 * 挑的都是"看起来正常、实际错"的形状：
 * - 只给最深盒、丢掉包装层 ⇒ 报告可读，但说不出点在哪个盒里；
 * - 把父 Element 当成无主盒的拥有者 ⇒ 自绘 chrome 永远指向父控件；
 * - 浮层原点没减 ⇒ 命中整体偏一个锚点，且不报错；
 * - 点空了回根节点 ⇒ 查询自洽但答案全错。
 */
final class PickTest extends SmokeTestCase
{
    private ?DebugSession $session = null;

    protected function tearDown(): void
    {
        $this->session?->close();
        $this->session = null;
        parent::tearDown();
    }

    private function open(Widget $root): DebugSession
    {
        $constraints = BoxConstraints::loose(new Size(300.0, 200.0));
        $app = new PickApplication($root);
        $app->attach(new SurfaceProbe(), $constraints);
        $this->session = DebugSession::open($app, $constraints, 600);
        $this->session->pump(0.0);

        return $this->session;
    }

    public function testHitChainIsRootToLeafAndImmediatelyAddressable(): void
    {
        $session = $this->open(Center::make(Text::make('hello')));

        // 根盒只有文本大小（Center 不撑满），(10,8) 落在文本里。
        $answer = $session->query('pick', ['x' => 10, 'y' => 8]);
        check('中心点命中', ($answer['found'] ?? false) === true, \json_encode($answer));
        check('命中范围报的是主树', ($answer['scope'] ?? null) === 'main', (string) ($answer['scope'] ?? ''));
        check('根在链首（不是只给最深盒）', ($answer['chain'][0]['widget'] ?? null) === 'Center', \json_encode($answer['chain'] ?? []));
        check('最深命中盒是文本', ($answer['hit']['box'] ?? null) === 'RenderText', \json_encode($answer['hit'] ?? []));
        check('链至少两层', \count($answer['chain'] ?? []) >= 2, 'chain=' . \count($answer['chain'] ?? []));

        $hit = $answer['hit'] ?? [];
        check('命中节点已发号', ($hit['node'] ?? 0) > 0, \json_encode($hit));
        check('命中节点有 Widget 类型', ($hit['widget'] ?? null) === 'Text', (string) ($hit['widget'] ?? ''));
        check('路径自证包含根', \str_contains((string) ($hit['path'] ?? ''), 'Center > Text'), (string) ($hit['path'] ?? ''));

        $props = $session->query('props', ['node' => $hit['node']]);
        check('拿命中节点可直接追问属性', ($props['found'] ?? false) === true, \json_encode($props));
        $this->flushChecks();
    }

    public function testReadingPickDoesNotChangeDigestAfterFrame(): void
    {
        $session = $this->open(Center::make(Text::make('hello')));
        $before = Digest::of($session->recorder());

        $session->query('pick', ['x' => 10.0, 'y' => 8.0]);
        $after = Digest::of($session->recorder());

        check(
            '同一棵树点选不改写证据指纹',
            $before === $after,
            \json_encode($before) . ' vs ' . \json_encode($after),
        );
        $this->flushChecks();
    }

    public function testMissAndNoRootsAreSeparateReasons(): void
    {
        $session = $this->open(Center::make(Text::make('hello')));

        $miss = $session->query('pick', ['x' => -20, 'y' => -20]);
        check('点空了报 no-hit', ($miss['reason'] ?? null) === 'no-hit', \json_encode($miss));
        check('点空了不给命中节点', \array_key_exists('hit', $miss) && $miss['hit'] === null, \json_encode($miss));
        check('点空了链为空', ($miss['chain'] ?? null) === [], \json_encode($miss));

        $query = new Query($session->recorder());
        $empty = $query->pick(0.0, 0.0, []);
        check('无树根报 no-roots', ($empty['reason'] ?? null) === 'no-roots', \json_encode($empty));
        check('两种 miss 不可混为一谈', ($miss['reason'] ?? null) !== ($empty['reason'] ?? null));
        $this->flushChecks();
    }

    public function testViewportCoordinatesAreShiftedByRootOrigin(): void
    {
        $session = $this->open(Center::make(Text::make('hello')));
        $app = $session->app();
        $root = $app->root();
        check('测试前提：根存在', $root !== null);

        // 直接给查询面一个带原点的根：与主树原样，只是整体右移 100 / 下移 50。
        $query = new Query($session->recorder());
        $roots = [['element' => $root, 'x' => 100.0, 'y' => 50.0]];

        $shifted = $query->pick(110.0, 58.0, $roots);
        $unshifted = $query->pick(10.0, 8.0, $roots);

        check('减掉原点后命中', ($shifted['found'] ?? false) === true, \json_encode($shifted));
        check('原点没减就点空（不是碰巧命中）', ($unshifted['reason'] ?? null) === 'no-hit', \json_encode($unshifted));
        $this->flushChecks();
    }

    public function testOwnerlessLinksAreReportedNotAttributedToParent(): void
    {
        $session = $this->open(Center::make(Text::make('hello')));
        $answer = $session->query('pick', ['x' => 10.0, 'y' => 8.0]);

        $links = $answer['chain'] ?? [];
        check('前提：链非空', $links !== [], \json_encode($links));
        $nodes = \array_column($links, 'node');
        // 这里每个盒恰好都有 Element 拥有，所以 ownerless 必须是 0 —— 若映射
        // 把无主盒硬塞给父节点，这一步会把它记成 0 但链上出现重复节点号。
        check('无主盒计数自报', ($answer['ownerless'] ?? -1) === 0, (string) ($answer['ownerless'] ?? ''));
        check('每环至多一个拥有者（没被父覆盖）', \count($nodes) === \count(\array_unique($nodes)), \json_encode($nodes));
        $this->flushChecks();
    }
}

/** @internal 判据夹具 */
final class PickApplication extends Application
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
