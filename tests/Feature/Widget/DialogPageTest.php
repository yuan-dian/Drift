<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\DialogApplication;
use Drift\Dev\Smoke\App\FakeDialog;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\Text;

/**
 * 页 30「原生对话框 Dialog」：点按钮 ⇒ 端口被调 ⇒ 结果写回页面（A5-①）。
 *
 * ★★ **为什么这一条必须是 feature 而不是 unit**：`Dialog` 端口的四个返回值
 *   （`?string` × 2 / `void` / `bool`）在**页面**上要翻译成三种可见状态
 *   （拿到路径 / 取消 / 计数加一）。这条翻译链只有真挂树、真派发指针事件才走得到；
 *   纯单测只能验 `filterText()`（已在 `Unit/Platform/Win32DialogTest`）。
 *
 * ★★ **为什么不用 `check()` 数"有墨"**：这一页要验的是**状态流转**，不是像素。
 *   像素由真机截图那道 T2 守（模态框弹不弹得出来只有真机能证）。
 *
 * ★ 视口取 1000×1400：整页（标题 + 两段卡片 + 能力边界四条）在一屏里排得下
 *   ⇒ 按钮都在裁剪区内，命中测试打得到。用小视口会得到"按钮在 ScrollView 之外"
 *   的假红（那是夹具问题，不是代码问题）。
 */
final class DialogPageTest extends SmokeTestCase
{
    private const W = 1000.0;

    private const H = 1400.0;

    /** @return array{0: DialogApplication, 1: FakeDialog, 2: BoxConstraints} */
    private static function boot(?FakeDialog $dialog = null): array
    {
        $dialog = $dialog ?? new FakeDialog();
        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));

        $app = new DialogApplication($dialog);
        $app->attach($surface, $constraints);
        $app->frame(16.0, $constraints);

        return [$app, $dialog, $constraints];
    }

    /** 推一帧（`setState` 之后必须再跑一帧才会重建 + 重排，否则读到的是旧树） */
    private static function tick(DialogApplication $app, BoxConstraints $constraints, float $ts = 33.0): void
    {
        $app->frame($ts, $constraints);
    }

    /** 页面上以某前缀开头的那条 `Text` 的内容；找不到 ⇒ 空串 */
    private static function textOf(Element $root, string $prefix): string
    {
        $found = findElement(
            $root,
            static fn(Element $el): bool => $el->widget() instanceof Text
                && str_starts_with($el->widget()->content(), $prefix)
        );

        return $found === null ? '' : $found->widget()->content();
    }

    /** 点一个**按标签**找的按钮（真派发 down + up，走命中链与手势判定） */
    private static function click(DialogApplication $app, string $label): bool
    {
        $element = findElement(
            $app->root(),
            static fn(Element $el): bool => $el->widget() instanceof Button
                && $el->widget()->label() === $label
        );

        $box = $element?->renderObject();
        $size = $box?->size();
        if ($box === null || $size === null) {
            return false;
        }

        [$x, $y] = RenderBox::absoluteOrigin($box);
        $cx = $x + $size->width * 0.5;
        $cy = $y + $size->height * 0.5;

        $app->dispatchInput(PointerEvent::down($cx, $cy));
        $app->dispatchInput(PointerEvent::up($cx, $cy));

        return true;
    }

    public function testFourButtonsAreOnTheTree(): void
    {
        [$app] = self::boot();

        $labels = ['打开一个文件', '选保存位置', '弹一个提示', '问一个确认'];
        $missing = [];
        foreach ($labels as $label) {
            $hit = findElement(
                $app->root(),
                static fn(Element $el): bool => $el->widget() instanceof Button
                    && $el->widget()->label() === $label
            );
            if ($hit === null) {
                $missing[] = $label;
            }
        }

        check(
            '对话框页 · 四个方法各有一个按钮（四个都建出来了，不是"只写了两个"）',
            $missing === [],
            '缺：' . implode('/', $missing)
        );
        check(
            '对话框页 · 首屏还没选过（三态里的初始态）',
            self::textOf($app->root(), '结果：') === '结果：还没选过',
            '实际文案：' . self::textOf($app->root(), '结果：')
        );
    }

    public function testOpenFilePassesTheFilterContract(): void
    {
        [$app, $fake, $constraints] = self::boot();

        check('对话框页 · 点「打开一个文件」命中（按钮在裁剪区内）', self::click($app, '打开一个文件'));
        self::tick($app, $constraints);

        check('对话框页 · 端口被调了**一次**（不是零次、也不是两次）', $fake->openCalls === 1, 'calls=' . $fake->openCalls);
        check(
            '对话框页 · 递下去的筛表是三项、首项是 Markdown（`Dialog::openFile()` 文档里的形状）',
            count($fake->lastFilters) === 3
                && ($fake->lastFilters[0] ?? null) === ['Markdown 文件 (*.md)', '*.md'],
            '实际：' . json_encode($fake->lastFilters, JSON_UNESCAPED_UNICODE)
        );
        check('对话框页 · 标题也递下去了（不是空串）', $fake->lastTitle === '打开一个文件', 'title=' . $fake->lastTitle);
    }

    public function testCancelIsDistinctFromPickedPath(): void
    {
        [$app, $fake, $constraints] = self::boot();

        // ① 取消：替身给空串 ⇒ 端口翻成 null ⇒ 页面走"取消"这一支
        $fake->nextPath = '';
        self::click($app, '打开一个文件');
        self::tick($app, $constraints);
        $afterCancel = self::textOf($app->root(), '结果：');
        check(
            '对话框页 · 取消 ⇒ 页面说"取消了"，不是"还没选过"（`null` 真的走到了）',
            str_contains($afterCancel, '取消'),
            '实际文案：' . $afterCancel
        );

        // ② 选中：同一页再点一次，端口给路径 ⇒ 页面必须换成路径
        $fake->nextPath = 'D:/tmp/合同.md';
        self::click($app, '打开一个文件');
        self::tick($app, $constraints, 49.0);
        $afterPick = self::textOf($app->root(), '结果：');
        check(
            '对话框页 · 拿到路径 ⇒ 页面上就是那条路径（取消态被覆盖，不是叠着显示）',
            str_contains($afterPick, 'D:/tmp/合同.md') && !str_contains($afterPick, '取消'),
            '实际文案：' . $afterPick
        );
        check('对话框页 · 两次点击 ⇒ 端口两次（没有"第二次被吞掉"）', $fake->openCalls === 2, 'calls=' . $fake->openCalls);
    }

    public function testSaveFileSeedsTheDefaultName(): void
    {
        [$app, $fake, $constraints] = self::boot();

        $fake->nextPath = 'D:/tmp/未命名.md';
        check('对话框页 · 点「选保存位置」命中', self::click($app, '选保存位置'));
        self::tick($app, $constraints);

        check('对话框页 · 走的是 `saveFile`（不是 `openFile`）', $fake->saveCalls === 1 && $fake->openCalls === 0);
        check(
            '对话框页 · 保存对话框带默认文件名（不带目录，只填名）',
            $fake->lastBody === '未命名.md',
            'defaultName=' . $fake->lastBody
        );
        check(
            '对话框页 · 保存也走同一套筛表',
            count($fake->lastFilters) === 3,
            'count=' . count($fake->lastFilters)
        );
    }

    public function testConfirmCountsBothAnswers(): void
    {
        [$app, $fake, $constraints] = self::boot();

        // ① 否
        $fake->answer = false;
        self::click($app, '问一个确认');
        self::tick($app, $constraints);
        $afterNo = self::textOf($app->root(), '确认的结果：');
        check(
            '对话框页 · `confirm()` 给 false ⇒ "否"计数 +1、"是"不动',
            str_contains($afterNo, '是 0 次') && str_contains($afterNo, '否 1 次'),
            '实际文案：' . $afterNo
        );

        // ② 是
        $fake->answer = true;
        self::click($app, '问一个确认');
        self::tick($app, $constraints, 49.0);
        $afterYes = self::textOf($app->root(), '确认的结果：');
        check(
            '对话框页 · `confirm()` 给 true ⇒ "是"计数 +1（两个答案分开记账）',
            str_contains($afterYes, '是 1 次') && str_contains($afterYes, '否 1 次'),
            '实际文案：' . $afterYes
        );
        check('对话框页 · 两次确认 ⇒ 端口被调两次', $fake->confirmCalls === 2, 'calls=' . $fake->confirmCalls);
    }

    public function testAlertDoesNotTouchThePickState(): void
    {
        [$app, $fake, $constraints] = self::boot();

        self::click($app, '弹一个提示');
        self::tick($app, $constraints);

        check('对话框页 · 点「弹一个提示」⇒ 端口被调一次', $fake->alertCalls === 1, 'calls=' . $fake->alertCalls);
        check(
            '对话框页 · 提示不动文件结果（两个段各记各的状态）',
            self::textOf($app->root(), '结果：') === '结果：还没选过',
            '实际文案：' . self::textOf($app->root(), '结果：')
        );
        check(
            '对话框页 · 提示也不碰确认计数',
            str_contains(self::textOf($app->root(), '确认的结果：'), '是 0 次 · 否 0 次'),
            '实际文案：' . self::textOf($app->root(), '确认的结果：')
        );
    }
}
