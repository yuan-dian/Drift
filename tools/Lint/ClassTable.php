<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

/**
 * 全仓类表：FQN ⇒ 类信息 + 路径 ⇒ 类信息表，外加三个层级查询。
 *
 * ★ 为什么要有这一个类：`dead-subscription` / `select-purity` / 以及后面几条
 *   （`subscriber-mark` / `nested-subscribe`）都要"先建全仓类表再查"。
 *   各写一份 = 同一个口径有四个落点，改一处漏三处（本仓已为这类重复付过学费）。
 * ★ 与 `SourceQuery` 的分工：`SourceQuery` 管**单个文件内**的 token 结构
 *   （属性/方法体区间/继承表），本类管**跨文件**的符号解析与传递闭包。
 *   两者都不做语义推断 —— 推不出来的（如"订阅发生在某 SubscribingWidget 子树内"）
 *   一律留给规则自己声明诚实边界，不在这里猜。
 */
final class ClassTable
{
    /**
     * @param array<string, array<string, mixed>> $all  FQN ⇒ 类信息
     * @param array<string, list<array<string, mixed>>> $byFile 文件路径 ⇒ 该类里的全部类
     */
    private function __construct(
        private readonly array $all,
        private readonly array $byFile,
    ) {
    }

    public static function fromProject(Project $project): self
    {
        $all = [];
        $byFile = [];
        foreach ($project->files() as $file) {
            $infos = SourceQuery::classes($file);
            $byFile[$file->path()] = $infos;
            foreach ($infos as $info) {
                $all[$info['fqn']] = $info;
            }
        }

        return new self($all, $byFile);
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->all;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function byFile(): array
    {
        return $this->byFile;
    }

    /**
     * 实现了某接口（含传递地）的全集。
     *
     * ★ `Cell` 并不 `extends Subject`（它是独立类）⇒ 判定"是不是订阅者"
     *   与"是不是可订阅物"是**两个方向**的查询，别混成一个方法。
     *
     * @return array<string, true>
     */
    public function implementorsOf(string $interface): array
    {
        $out = [];
        foreach (array_keys($this->all) as $fqn) {
            if ($this->descendsFrom($fqn, $interface, viaImplements: true)) {
                $out[$fqn] = true;
            }
        }

        return $out;
    }

    /**
     * `$seeds` 及其在本仓库内的全部子类型。
     *
     * @param list<string> $seeds
     * @return array<string, true>
     */
    public function subtypesOf(array $seeds): array
    {
        $out = [];
        foreach ($seeds as $seed) {
            $out[$seed] = true;
        }
        foreach (array_keys($this->all) as $fqn) {
            foreach ($seeds as $seed) {
                if ($this->descendsFrom($fqn, $seed)) {
                    $out[$fqn] = true;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * `$fqn` 是否（传递地）继承/实现了 `$ancestor`。
     */
    public function descendsFrom(string $fqn, string $ancestor, bool $viaImplements = false): bool
    {
        $seen = [];
        $queue = [$fqn];

        while ($queue !== []) {
            $current = array_pop($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            if ($current === $ancestor) {
                return true;
            }
            $info = $this->all[$current] ?? null;
            if ($info === null) {
                continue;
            }
            foreach ($info['extends'] as $parent) {
                $queue[] = $parent;
            }
            if ($viaImplements) {
                foreach ($info['implements'] as $parent) {
                    $queue[] = $parent;
                }
            }
        }

        return false;
    }
}
