<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

use Drift\Dev\Lint\Rules\DependencyDirection;
use Drift\Dev\Lint\Rules\InternalLeak;
use Drift\Dev\Lint\Rules\NamespaceMismatch;
use Drift\Dev\Lint\Rules\NanoHygiene;
use Drift\Dev\Lint\Rules\OrphanTrait;

/**
 * 规则集与执行器。
 */
final class Linter
{
    /** @param list<Rule> $rules */
    public function __construct(private readonly array $rules)
    {
    }

    public static function defaultRules(): self
    {
        return new self([
            new DependencyDirection(),
            new InternalLeak(),
            new NamespaceMismatch(),
            new NanoHygiene(),
            new OrphanTrait(),
        ]);
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @return list<Diagnostic> */
    public function run(Project $project): array
    {
        $all = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->check($project) as $diagnostic) {
                $all[] = $diagnostic;
            }
        }

        usort($all, static function (Diagnostic $a, Diagnostic $b): int {
            return [$a->file, $a->line, $a->rule] <=> [$b->file, $b->line, $b->rule];
        });

        return $all;
    }
}
