<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\ComboBoxApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Widget\Button;
use Drift\Widget\Option;
use Drift\Widget\OptionStatus;

/**
 * ComboBox 的跨树重读与远程搜索（2026-10-24）。
 *
 * ★ 第一块钉的是 2026-10-24 之前 `ComboBox` 的**静默缺陷**：面板开着时应用换掉
 *   `options`（远程取数就是这个形状），面板**纹丝不动**、不报错。现在由框架负责
 *   （`Application::syncPanelSignatures()`）：锚点 Widget 换了 ⇒ 求签名 ⇒ 变了就标脏。
 * ★ 面板内容比主树**晚一帧**（`placeOverlays()` 跑在主树 settle 之前）⇒ 每次改完
 *   都要跑**两帧**，否则会把"晚一帧"误判成"没生效"。
 *
 * 远程搜索钉四条只靠读代码看不出来的事：
 * ① `onSearch` 只挂在"用户输入"上 —— 开关面板时的内部复位**不许**触发它；
 * ② 声明了 `onSearch` ⇒ **本地过滤让位**：判据要选"本地会筛掉一项"的表；
 * ③ 三态不是 ready 时 Enter **不许**选中（应用可能还留着上一批 options）；
 * ④ 换选项表之后字段**不许变空白**（远程搜索必然走到这里）。
 */
final class ComboBoxRemoteTest extends SmokeTestCase
{
    private float $t = 2000.0;

    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(360.0, 300.0));
    }

    private function boot(): ComboBoxApplication
    {
        $app = new ComboBoxApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $app->frame($this->t, $this->constraints());

        return $app;
    }

    private function tick(ComboBoxApplication $app): void
    {
        $this->t += 16.0;
        $app->frame($this->t, $this->constraints());
    }

    /** 面板内容比主树晚一帧 ⇒ 每次改完跑两帧 */
    private function settle(ComboBoxApplication $app): void
    {
        $this->tick($app);
        $this->tick($app);
    }

    private function tap(ComboBoxApplication $app, array $pt): void
    {
        $app->dispatchInput(PointerEvent::down($pt[0], $pt[1]));
        $app->dispatchInput(PointerEvent::up($pt[0], $pt[1]));
        $this->tick($app);
    }

    /** @return list<string> */
    private function labels(ComboBoxApplication $app): array
    {
        $out = [];
        foreach ($app->optionButtons() as $el) {
            $w = $el->widget();
            $out[] = $w instanceof Button ? $w->label() : '?';
        }

        return $out;
    }

    public function testOptionsChangeRebuildsPanelButSameContentDoesNot(): void
    {
        $app = $this->boot();
        $this->tap($app, $app->fieldCenter());
        check(
            'ComboBox · 换选项表前：面板 4 项（基线）',
            $this->labels($app) === ['北京市', '上海市', '广州市', '北京南站'],
            'rows=[' . \implode(',', $this->labels($app)) . ']',
        );

        $app->host()?->setOptions([
            Option::make('成都市', '510100'),
            Option::make('杭州市', '330100'),
        ]);
        $this->settle($app);
        check(
            'ComboBox 跨树 · 面板开着时换选项表 ⇒ 面板项数跟着变（受控值 ⇒ 面板重读）',
            $this->labels($app) === ['成都市', '杭州市'],
            'rows=[' . \implode(',', $this->labels($app)) . ']',
        );

        // ★ 反例的另一半：**内容没变就不许重建**。应用每次重建都会造新的 `Option`
        //   实例，若按对象身份比 ⇒ 面板每帧重建 ⇒ `ScrollView` 换盒、滚动位置回顶部。
        //   判据用**Widget 实例身份**：没重建 ⇒ 还是同一个实例。
        //   ★★ 必须捕获 **Widget**，不能捕获 Element：`Element::widget()` 是活动访问器，
        //     元素会被复用（`update()` 只换里面的 Widget）⇒ 拿 Element 再读 widget()
        //     拿到的是**当前**那个，断言恒真。这一版第一稿就是这么写的，反向自检当场
        //     抓到（去掉签名比对仍然全绿）。
        $before = $app->optionButtons()[0]?->widget();
        $app->host()?->setOptions([
            Option::make('成都市', '510100'),
            Option::make('杭州市', '330100'),
        ]);
        $this->settle($app);
        $after = $app->optionButtons()[0]?->widget();
        check(
            'ComboBox 跨树 · 换的是**同样的内容** ⇒ 面板不重建（滚动位置不会跳）',
            $before !== null && $after !== null && $before === $after,
            $before === null || $after === null
                ? '面板没项'
                : 'rebuilt=' . ($before === $after ? 'no' : 'yes'),
        );
    }

    /**
     * 远程搜索全链（`onSearch` + `status`）。
     *
     * ★ 11 条在一条状态链上（"加载中 Enter 不选"要求还没选过、"字段不变空白"要求
     *   已经选过"北京市"），拆开就要重放前缀，而重放本身没人验。
     */
    public function testRemoteSearchChain(): void
    {
        $app = $this->boot();
        $app->host()?->setRemote(true);
        $app->host()?->setOptions(ComboBoxApplication::cities());
        $app->host()?->setStatus(OptionStatus::ready());
        $this->settle($app);

        $this->tap($app, $app->fieldCenter());
        check(
            'ComboBox 远程 · 开面板 ⇒ onOpen 触发一次（不是每次应用重建）',
            $app->host()?->openCount === 1,
            'openCount=' . \var_export($app->host()?->openCount, true),
        );
        check(
            'ComboBox 远程 · 开面板时的内部清空**不**触发 onSearch（只有用户输入才算）',
            $app->host()?->searched === [],
            'searched=[' . \implode(',', $app->host()?->searched ?? []) . ']',
        );

        // ★ 这里必须跑**两帧**：`onSearch` 的回调把 status 改成 loading，而面板内容读
        //   的是"锚点当前那个 Widget" —— 它要等主树 settle 之后才换。第一帧面板还会用
        //   **旧** Widget（ready）重建一次（那是 `$query` 广播带出来的），第二帧框架的
        //   签名闸才看到新 Widget 并把面板换成"正在加载…"。
        $app->dispatchInput(KeyEvent::character('北'));
        $this->settle($app);
        check(
            'ComboBox 远程 · 打字 ⇒ onSearch 收到**当时的词**（不是空串、不是旧词）',
            $app->host()?->searched === ['北'],
            'searched=[' . \implode(',', $app->host()?->searched ?? []) . ']',
        );
        check(
            'ComboBox 远程 · 回调进加载态 ⇒ 面板只剩一行"正在加载…"、0 个候选',
            $this->labels($app) === [] && \in_array('正在加载…', $app->panelTexts(), true),
            'rows=[' . \implode(',', $this->labels($app)) . '] texts=' . \implode('|', $app->panelTexts()),
        );

        // ★★ ③ 加载中 Enter 不许选中 —— 此时 options 里**还留着 4 个城市**（应用没清旧数据）
        $changedBefore = \count($app->changed());
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        $this->tick($app);
        check(
            'ComboBox 远程 · 加载中 Enter ⇒ 不选中（旧 options 还在，但不许拿它当"看得见的项"）',
            \count($app->changed()) === $changedBefore && $app->overlayCount() === 1,
            'changed=' . \count($app->changed()) . ' overlay=' . $app->overlayCount(),
        );

        // 服务端结果到达：**表里含一项不含"北"的** —— 本地若还筛，就只剩 1 项
        $app->host()?->setOptions([
            Option::make('北京市', '110000'),
            Option::make('上海市', '310000'),
        ]);
        $app->host()?->setStatus(OptionStatus::ready());
        $this->settle($app);
        check(
            'ComboBox 远程 · 服务端结果原样显示（查询词「北」+ 含「上海市」⇒ 本地不再筛）',
            $this->labels($app) === ['北京市', '上海市'],
            'rows=[' . \implode(',', $this->labels($app)) . ']',
        );

        // ★★ ④ 选中之后换选项表 ⇒ 字段**不许变空白**
        $this->tap($app, $app->optionCenter(0));
        check(
            'ComboBox 远程 · 选中后字段回标签「北京市」（基线）',
            $app->value() === '110000' && $app->fieldState()?->text() === '北京市',
            'value=' . $app->value() . ' text=' . \var_export($app->fieldState()?->text(), true),
        );

        $app->host()?->setOptions([Option::make('杭州市', '330100')]);
        $this->settle($app);
        check(
            'ComboBox 远程 · 换选项表（已选值不在表里）⇒ 字段仍显示「北京市」，不变空白',
            $app->fieldState()?->text() === '北京市',
            'text=' . \var_export($app->fieldState()?->text(), true),
        );

        // ★ 反例的另一半：**没见过**的值不许拿旧标签冒充（否则字段会显示一个错的名字）
        $app->host()?->setValue('999999');
        $this->settle($app);
        check(
            'ComboBox 远程 · 反例：表里没有、也从没见过的值 ⇒ 字段空（不拿旧标签冒充）',
            $app->fieldState()?->text() === '',
            'text=' . \var_export($app->fieldState()?->text(), true),
        );

        // ★★ 真机缺陷的判据（2026-10-24）：主树 settle 之后、面板还没同步的那一帧，
        //    `wantsFrame()` **必须为真** —— 否则帧时钟不上膛，面板永远停在旧内容。
        //    现象（真机实测）：远程搜索的 `deliver()` 明明把选项换成了 2 项（日志 n=2），
        //    面板还列着旧的 4 项；而 smoke 因为总是**显式跑两帧**，从来看不出来。
        //    ★ 这一条不能用"再跑一帧面板就对了"来测 —— 那正是本缺陷**恰好**能通过的写法。
        $this->tap($app, $app->fieldCenter());
        $this->settle($app);
        $app->host()?->setOptions([Option::make('成都市', '510100')]);
        $this->tick($app);   // 只跑**一帧**：主树换了，面板还没跟上
        check(
            'ComboBox 远程 · 主树换了、面板还没同步 ⇒ wantsFrame() 为真（否则帧时钟不上膛）',
            $app->wantsFrame() === true,
            'wantsFrame=' . \var_export($app->wantsFrame(), true),
        );
        $this->tick($app);   // 第二帧：面板同步（`syncPanelSignatures()` 更新 `seenAnchor`）
        check(
            'ComboBox 远程 · 同步完 ⇒ wantsFrame() 回落（这条判据不自旋）',
            $app->wantsFrame() === false,
            'wantsFrame=' . \var_export($app->wantsFrame(), true),
        );
    }
}
