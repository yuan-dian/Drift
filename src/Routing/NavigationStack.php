<?php
declare(strict_types=1);

namespace Drift\Routing;

/**
 * 页名栈。不认识持久化：变更交给 onChange，冷启动用 fromArray() 复原。
 *
 * @api
 */
final class NavigationStack
{
    /** @var list<array{name: string, args: array<string, mixed>}> */
    private array $entries;

    private int $index = 0;

    /** @var null|\Closure(array<string, mixed>): void */
    private $onChange;

    /** @var null|\Closure(string, string): bool 离开当前页前问一次；false = 拦下 */
    private $guard;

    public function __construct(
        string $home = 'home',
        ?\Closure $onChange = null,
        ?\Closure $guard = null,
    ) {
        $this->entries = [['name' => $home, 'args' => []]];
        $this->onChange = $onChange;
        $this->guard = $guard;
    }

    /** 不合法的存档一律回首页（外部文件不应让启动失败）。 */
    public static function fromArray(
        array $state,
        string $home,
        ?\Closure $onChange = null,
        ?\Closure $guard = null,
    ): self {
        $stack = new self($home, $onChange, $guard);
        $entries = $state['entries'] ?? null;
        $index = $state['index'] ?? null;
        if (!is_array($entries) || $entries === [] || !is_int($index)) {
            return $stack;
        }

        $clean = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
                return $stack;
            }
            $clean[] = [
                'name' => $entry['name'],
                'args' => is_array($entry['args'] ?? null) ? $entry['args'] : [],
            ];
        }

        $stack->entries = $clean;
        $stack->index = max(0, min($index, count($clean) - 1));

        return $stack;
    }

    public function current(): string
    {
        return $this->entries[$this->index]['name'];
    }

    /** @return array<string, mixed> */
    public function args(): array
    {
        return $this->entries[$this->index]['args'];
    }

    public function canPop(): bool
    {
        return $this->index > 0;
    }

    public function canGoForward(): bool
    {
        return $this->index < count($this->entries) - 1;
    }

    public function push(string $name, array $args = []): bool
    {
        if (!$this->leave($name)) {
            return false;
        }
        // 新 push 截断前进历史（浏览器语义），否则 back 之后 push 会留两条未来
        $this->entries = array_slice($this->entries, 0, $this->index + 1);
        $this->entries[] = ['name' => $name, 'args' => $args];
        $this->index = count($this->entries) - 1;
        $this->changed();

        return true;
    }

    public function replace(string $name, array $args = []): bool
    {
        if (!$this->leave($name)) {
            return false;
        }
        // 与 push 同：换掉当前项就开始新分支，旧的前进历史不该还能跳回去
        $this->entries = array_slice($this->entries, 0, $this->index + 1);
        $this->entries[$this->index] = ['name' => $name, 'args' => $args];
        $this->changed();

        return true;
    }

    /** @return bool 守卫拦下 / 已在栈底 ⇒ false（调用方据此不广播） */
    public function pop(): bool
    {
        if (!$this->canPop() || !$this->leave($this->entries[$this->index - 1]['name'])) {
            return false;
        }
        $this->index--;
        $this->changed();

        return true;
    }

    /** @return bool 守卫拦下 / 没有前进历史 ⇒ false */
    public function forward(): bool
    {
        if (!$this->canGoForward() || !$this->leave($this->entries[$this->index + 1]['name'])) {
            return false;
        }
        $this->index++;
        $this->changed();

        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['entries' => $this->entries, 'index' => $this->index];
    }

    private function leave(string $to): bool
    {
        return $this->guard === null || (bool) ($this->guard)($this->current(), $to);
    }

    private function changed(): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)($this->toArray());
        }
    }
}
