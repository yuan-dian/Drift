<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;

/**
 * 应用的组合根必须**走 `EventLoop::run()`**，不许自己写消息循环。
 *
 * ## 这条闸门护的是什么（两次真事故的合并）
 *
 * ```text
 * 第一次（2026-10-25，apps/tutorial ④ 段）
 *   while ($platform->pump()) { $app->frame(...); }   ← 只跑帧，没有"叫醒"
 *   ⇒ 点「开始加载」后停住：1500ms 的截图与 400ms 时 md5 逐字节相同
 *   ⇒ smoke 全绿（用例里每帧都是**显式跑的**，缺的是驱动方）
 *
 * 第二次（2026-10-30，N2 拆分等待层时）
 *   把"等多久"交给 `FrameClock::nextWaitMs()` 之后，一个手写循环只要忘了
 *   把 `waitEvents()` 的返回值接到 `pumpEvents()` 上，就变成"事件投不出来 ⇒
 *   窗口对点击毫无反应"，同样**不报错**。
 * ```
 *
 * ⇒ 判据从"必须建 `FrameClock` 并 `tick()`"改成"**必须走 `EventLoop::run()`**"：
 *   等 / 派发 / 跑帧 / 光标 / IME / 退出这六件契约现在只在 `EventLoop` 里各写一次，
 *   组合根再抄一遍就是第六次出错机会。
 *
 * ## 为什么不给"我有特殊需求"留口子
 *
 * 需要一个不一样的循环时，**给 `EventLoop` 加钩子**，而不是在组合根里复制它。
 * 这条不是洁癖：`EventLoop` 里的每一条契约都有"漏掉即静默半失效"的代价
 * （见该类注释的次序表），复制出去就等于把那些代价也复制一份。
 *
 * ★ 按**路径**判而不是按类：组合根本来就无类型声明、不受 PSR-4 管（`main.php`
 *   同款），`Bootstrap` 这个类名是约定而不是接口。按路径判与 `dependency-direction`
 *   同口径。
 *
 * @api 闸门：`tools/lint.php`
 */
final class AppBootstrapLoop implements Rule
{
    /** 组合根路径：`apps/<name>/bin/<os>/Bootstrap.php` */
    private const BOOTSTRAP_PATH = '#^apps/[^/]+/bin/[^/]+/Bootstrap\.php$#';

    public function name(): string
    {
        return 'app-bootstrap-loop';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return '应用的组合根必须走 EventLoop::run() —— 手写消息循环会漏掉"等多久 / 派发 / 光标 / IME"其中一条，全部表现为不报错的半失效';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            if (preg_match(self::BOOTSTRAP_PATH, $file->path()) !== 1) {
                continue;
            }

            if ($this->hasLoopCall($file)) {
                continue;
            }

            $found[] = new Diagnostic(
                $this->name(),
                Diagnostic::ERROR,
                $file->path(),
                $this->loopLine($file),
                 '组合根没有走 `EventLoop::run($platform, $app, $handle)` —— 自己写消息循环时，'
                    . '"等多久（FrameClock::nextWaitMs）/ 派发（pumpEvents）/ 光标 / IME / 退出"'
                    . '五条契约里漏掉任何一条都是**不报错的半失效**（2026-10-25 真机：界面停住、'
                    . '日志一个字都没有；smoke 全绿，因为用例里每帧都是显式跑的）'
                    . "\n" . '修法：删掉自己那段 `while (...)`，换成一行 '
                    . '`EventLoop::run($platform, $app, $handle);`。'
                    . '确实需要不同循环 ⇒ 给 `EventLoop` 加钩子，不要在组合根里复制它。',
            );
        }

        return $found;
    }

    /**
     * 组合根里**真的调了** `EventLoop::run()` 吗（注释里的字样不算）。
     *
     * ★ 为什么不用 `str_contains($code, 'EventLoop::run(')`：第一版就是那样，结果
     *   夹具（注释里写着"都走 `EventLoop::run()`"）**被判定为合格** —— 一个只在
     *   注释里提过的组合根能过闸，这条规则就是空的。注释行以 `*` / `//` / `/*`
     *   开头，跳过它们即可（不需要 tokenizer：组合根的注释形状很固定）。
     */
    private function hasLoopCall(SourceFile $file): bool
    {
        foreach (explode("\n", $file->code()) as $text) {
            $line = ltrim($text);
            if (str_starts_with($line, '*') || str_starts_with($line, '//') || str_starts_with($line, '/*')) {
                continue;
            }
            if (str_contains($line, 'EventLoop::run(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 报在**自己写的那个循环**那一行；没有循环就报第 1 行。
     *
     * ★ 必须锚 `^\s*while`：类头注释里也写着 "`while ($platform->pump())` 里…"
     *   （引用句式），只按 `->pump()` 找会报到注释行上（实测第一版报到 21 行）。
     */
    private function loopLine(SourceFile $file): int
    {
        foreach (explode("\n", $file->code()) as $index => $text) {
            if (preg_match('#^\s*(while|for|do)\b#', $text) === 1) {
                return $index + 1;
            }
        }

        return 1;
    }
}
