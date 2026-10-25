<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;

/**
 * 应用的组合根必须**建 `FrameClock` 并 `tick()`**，不能只 `frame()`。
 *
 * ## 这条闸门是给一个真实事故补的（2026-10-25，`apps/tutorial` ④ 段）
 *
 * 现象：点「开始加载」→ 出现「加载中…」→ **停在那里**。1500ms 后的截图与 400ms 时
 * **md5 逐字节相同**。而 smoke 14 条断言全绿、lint / docref / selftest 全绿。
 *
 * 根因链条（每一步都"不报错"）：
 *
 * ```text
 * ① pump() 阻塞在壳的 GetMessageW —— 没有输入事件时循环一直睡
 * ② Application::wantsFrame() 只是"回答要不要跑下一帧"，**没人问就没人答**
 * ③ 定时器从不上膛 ⇒ after() 排的回调永不触发、Spinner 永不转
 * ④ 后台结果只能等下一次鼠标移动捎带 ⇒ 看起来像"卡死"，日志里一个字都没有
 * ```
 *
 * ⇒ 闸门抓不到的真正原因：smoke 里每一帧都是**显式跑**的（`$app->frame(1200.0, …)`），
 * 永远不需要"有人把循环叫醒"。**缺的是驱动方，不是判据** —— 所以这条只能在
 * 组合根上静态判。
 *
 * ## 判据（宁漏勿误）
 *
 * 只判**应用的组合根**（路径 `apps/<name>/bin/<os>/Bootstrap.php`），要求文件里
 * 同时出现三样：
 *
 * | 必须出现 | 少了会怎样 |
 * |---|---|
 * | `new FrameClock(` | 没有任何东西在管"什么时候跑下一帧" |
 * | `->pace(` | 进循环前没上膛 ⇒ 第一次 `pump()` 就无限阻塞（首帧就排了定时器时） |
 * | `->tick(` | 帧还是自己 `frame()` 跑的 ⇒ 跑完不重新上膛，第二帧起就停摆 |
 *
 * ★ 按**路径**判而不是按类：组合根本来就无类型声明、不受 PSR-4 管（`main.php` 同款），
 *   `Bootstrap` 这个类名是约定而不是接口。按路径判与 `dependency-direction` 同口径。
 * ★ **不判"有没有调 `frame()`"** —— `FrameClock::tick()` 内部就会调它，两个都判会误报。
 *
 * @api 闸门：`tools/lint.php`
 */
final class AppBootstrapFrameClock implements Rule
{
    /** 组合根路径：`apps/<name>/bin/<os>/Bootstrap.php` */
    private const BOOTSTRAP_PATH = '#^apps/[^/]+/bin/[^/]+/Bootstrap\.php$#';

    public function name(): string
    {
        return 'app-bootstrap-frame-clock';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return '应用的组合根必须建 FrameClock 并 tick() —— 只 frame() 的话 after()/动画在真机上永不触发（smoke 全绿）';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            if (preg_match(self::BOOTSTRAP_PATH, $file->path()) !== 1) {
                continue;
            }

            $missing = $this->missingPieces($file);
            if ($missing === []) {
                continue;
            }

            $found[] = new Diagnostic(
                $this->name(),
                Diagnostic::ERROR,
                $file->path(),
                $this->loopLine($file),
                sprintf(
                    '组合根缺少%s —— `pump()` 阻塞在 GetMessageW，没人按 `wantsFrame()` 上膛定时器 ⇒ '
                        . 'after()/动画/后台结果在真机上永远不动（smoke 全绿，因为用例里每帧都是显式跑的）'
                        . "\n" . '修法：`$clock = new FrameClock($platform->timers()); $clock->pace($app->wantsFrame());` '
                        . '进循环前上膛，循环里把 `$app->frame(...)` 换成 `$clock->tick($app, $platform->now(), $screen->viewport())`，'
                        . '退出前 `$clock->stop()`。',
                    implode('、', $missing),
                ),
            );
        }

        return $found;
    }

    /** @return list<string> 缺哪几样（空数组 = 通过） */
    private function missingPieces(SourceFile $file): array
    {
        $code = $file->code();
        $missing = [];

        if (!str_contains($code, 'new FrameClock(')) {
            $missing[] = ' `new FrameClock(...)`';
        }
        if (!str_contains($code, '->pace(')) {
            $missing[] = ' `->pace(...)`（进循环前上膛）';
        }
        if (!str_contains($code, '->tick(')) {
            $missing[] = ' `->tick(...)`（跑帧 + 重新上膛）';
        }

        return $missing;
    }

    /**
     * 报在 `while (...->pump())` 那一行（循环入口），比报第 1 行好定位。
     *
     * ★ 必须锚 `^\s*while`：类头注释里也写着 "`while ($platform->pump())` 里…"
     *   （引用句式），只按 `->pump()` 找会报到注释行上（实测第一版报到 21 行）。
     */
    private function loopLine(SourceFile $file): int
    {
        $line = 1;
        foreach (explode("\n", $file->code()) as $index => $text) {
            if (preg_match('#^\s*while\s*\(.*->pump\(\s*\)#', $text) === 1) {
                $line = $index + 1;
                break;
            }
        }

        return $line;
    }
}
