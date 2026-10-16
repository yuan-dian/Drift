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
 * §16.6.3 #8：订阅者标记（G1，§10.3 第 5 条）。
 *
 * 判据：某个类的方法体里出现 `->select(` / `->watch(` **调用**
 * ⇒ 该类必须 `implements SubscribingWidget`，或位于 `Drift\Widget\State` 子树内。
 *
 * 理由（§10.3 第 5 条）：订阅责任**只能落在实现了 `SubscribingWidget` 的控件上**
 * （`SubjectBuilder` / `PageHost` / 用户自写的 `StatusBar`），不能落在渲染型控件上。
 * 判据是**接口标记**，不是命名约定 —— 命名约定被方案自己的例子否证
 * （`StatusBar` 名字里没有 `*Builder`）。
 *
 * ★ 与 `dead-subscription`（A16）的分工：那条问"读了 Subject/Cell 却没标订阅者"，
 *   本条问"发起订阅动作（select/watch）却没标订阅者"。**同一个面的两种入口**，
 *   所以共用同一个 `ClassTable`，不各建一张表。
 * ★ `State` 子树豁免：`watch()` 的账簿本来就归 `State`（`State::watch()`），
 *   在 `State` 子类里订自己的 bag 是天经地义 —— 与 `dead-subscription` 同一条理由。
 * ★ 只认**实例方法调用**形态（`->select(` / `->watch(`）。静态调用
 *   `SubjectBuilder::select(` 是这条规则的**反面教材**（框架自己也这么用），
 *   它不产生"谁在订阅"的问题 ⇒ 不判。
 */
final class SubscriberMark implements Rule
{
    private const SUBSCRIBING_WIDGET = 'Drift\State\SubscribingWidget';

    private const STATE = 'Drift\Widget\State';

    /** 订阅动作的方法名（小写） */
    private const ACTIONS = ['select', 'watch'];

    public function name(): string
    {
        return 'subscriber-mark';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return '调 ->select()/->watch() 的类必须 implements SubscribingWidget（订阅责任是接口标记，不是命名约定）';
    }

    public function check(Project $project): array
    {
        $table = ClassTable::fromProject($project);
        $subscribing = $table->implementorsOf(self::SUBSCRIBING_WIDGET);
        $byFile = $table->byFile();

        $found = [];
        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue; // 工具跑在 php.exe 里、不进 AOT 产物 ⇒ 产物纪律不适用（同 nano-hygiene）
            }
            foreach ($byFile[$file->path()] ?? [] as $info) {
                if (isset($subscribing[$info['fqn']])) {
                    continue;
                }
                if ($table->descendsFrom($info['fqn'], self::STATE)) {
                    continue; // watch() 的账簿归 State
                }

                foreach ($this->actions($file, $info) as $hit) {
                    $found[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::ERROR,
                        $file->path(),
                        $hit['line'],
                        sprintf(
                            '%s 调了 ->%s()，但没 implements SubscribingWidget ⇒ 订阅责任只剩命名约定（§10.3 第 5 条）',
                            $info['name'],
                            $hit['action']
                        )
                    );
                }
            }
        }

        return $found;
    }

    /**
     * 类内所有方法体里的订阅动作调用点。
     *
     * @param array<string, mixed> $info
     * @return list<array{action: string, line: int}>
     */
    private function actions(SourceFile $file, array $info): array
    {
        $hits = [];

        foreach ($info['methods'] as $range) {
            foreach (SourceQuery::methodCalls($file, $range['start'], $range['end'], self::ACTIONS) as $hit) {
                // ★ 只认**自己承担订阅**的形态（`$this->select(...)`）。
                //   `SubjectBuilder::make($store)->select(...)` / `$this->widget->select(...)`
                //   是"配置一个专职订阅者"—— 订阅责任在**那个**对象身上（它 implements
                //   `SubscribingWidget`），调用方不是订阅者。2026-10-16 修：原判据见 `->`
                //   就报，把 §A.2.9 的官方写法（页面用 `SubjectBuilder`）判成违规。
                if (!$this->receiverIsThis($file, $hit['index'])) {
                    continue;
                }

                $hits[] = ['action' => $hit['name'], 'line' => $hit['line']];
            }
        }

        return $hits;
    }

    /** `->` 的左边是不是 `$this` */
    private function receiverIsThis(SourceFile $file, int $arrowIndex): bool
    {
        $tokens = $file->tokens();
        $prev = SourceQuery::prevIndex($tokens, $arrowIndex - 1);
        if ($prev === null || !is_array($tokens[$prev])) {
            return false;
        }

        return $tokens[$prev][0] === T_VARIABLE && $tokens[$prev][1] === '$this';
    }
}
