<?php
declare(strict_types=1);

namespace App;

use Drift\State\Equatable;
use Drift\State\SubscribingWidget;

/**
 * 夹具：`select-purity` 的已知错误（§16.6.3 #6）。
 *
 * 不该响（稳定值契约）：
 * - `: string` / `: array`（值语义）
 * - `: UserStatus`（enum，单例）
 * - `: EquatableRow` + `new EquatableRow(...)`（显式值相等契约；**即使它也是 readonly**）
 *
 * 该响（每次返回新实例且没有值相等契约）：
 * - `: int|string`（联合类型）                    ⇒ error ×1
 * - `: UserBox` + `new UserBox(...)`             ⇒ error ×2（类型不对 + 体内 new）
 * - `: ReadonlyBox` + `new ReadonlyBox(...)`     ⇒ error ×2
 *
 * ★ 2026-11-08：从前 `readonly class UserRow` 是 **Good 形态**，即 lint 把
 *   「属性不可变」误当成「同值复用同一实例」。但 `SubjectBuilderState::sameValue()`
 *   只认 `Equatable` 与 `===`，对 readonly 无特判 ⇒ 返回类型 / 体内 new 两条通道
 *   都是假绿。现在 `readonly` 不再是免死金牌，`Equatable` 才是。
 * ★ 两条通道各有夹具：`typeProblem()` 验返回类型、`newTargets()` 验体内 new；
 *   只修一条时本夹具会少报 ⇒ selftest 红。
 *
 * 该响合计：bad() 5 条 + staticShape() 7 条 = **12 条**。
 */
enum UserStatus: string
{
    case Idle = 'idle';
    case Busy = 'busy';
}

/** readonly + Equatable：稳定性来自契约，不是来自 readonly。 */
readonly class EquatableRow implements Equatable
{
    public function __construct(public int $id)
    {
    }

    public function equals(Equatable $other): bool
    {
        return $other instanceof self && $this->id === $other->id;
    }
}

/** readonly 但无值相等契约 ⇒ 每次 new 都是新身份，`===` 永不相等。 */
readonly class ReadonlyBox
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
        $this->source->select(fn(User $u): EquatableRow => new EquatableRow($u->id()));
    }

    public function bad(): void
    {
        $this->source->select(fn(User $u): int|string => $u->id());
        $this->source->select(fn(User $u): UserBox => new UserBox($u->id()));
        $this->source->select(fn(User $u): ReadonlyBox => new ReadonlyBox($u->id()));
    }

    public function staticShape(): void
    {
        $this->source->select(static fn(User $u): string => $u->name());
        $this->source->select(static fn(User $u): array => $u->tags());
        $this->source->select(static fn(User $u): int|string => $u->id());
        $this->source->select(static fn(User $u): UserBox => new UserBox($u->id()));
        $this->source->select(static fn(User $u): ReadonlyBox => new ReadonlyBox($u->id()));
        $this->source->select(static function (User $u): UserBox {
            return new UserBox($u->id());
        });
        $this->source->select(static function (User $u): string {
            return $u->name();
        });
    }
}
