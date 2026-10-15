<?php
declare(strict_types=1);

namespace App;

use Drift\State\SubscribingWidget;

/**
 * 夹具：`select-purity` 的已知错误（§16.6.3 #6）。
 *
 * 不该响（都是标量 / enum / readonly class）：
 * - `: string`
 * - `: UserStatus`（enum）
 * - `: UserRow`（readonly class）
 *
 * 该响：
 * - `: int|string`（联合类型）              ⇒ error ×1
 * - `: UserBox` + `new UserBox(...)`       ⇒ error ×2（类型不对 + 体内 new）
 */
enum UserStatus: string
{
    case Idle = 'idle';
    case Busy = 'busy';
}

readonly class UserRow
{
    public function __construct(public int $id)
    {
    }
}

class UserBox
{
    public function __construct(private int $id)
    {
    }
}

// ★ 本类真的调 `->select()` ⇒ 按 §10.3 第 5 条必须 implements SubscribingWidget
//   （不加会被 `subscriber-mark` 抓 —— 那是它该抓的，别为迁就夹具把规则改松）。
final class SelectFixture implements SubscribingWidget
{
    public function good(): void
    {
        $this->source->select(fn(User $u): string => $u->name());
        $this->source->select(fn(User $u): UserStatus => $u->status());
        $this->source->select(fn(User $u): UserRow => new UserRow($u->id()));
    }

    public function bad(): void
    {
        $this->source->select(fn(User $u): int|string => $u->id());
        $this->source->select(fn(User $u): UserBox => new UserBox($u->id()));
    }
}
