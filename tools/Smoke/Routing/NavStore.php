<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Routing;

use Drift\State\Subject;

/** @internal 冒烟夹具：页名的唯一真相源（= AppStore 的 currentPage 形态）。 */
final class NavStore extends Subject
{
    private string $page = 'users';

    /** 每次 notify 的次数（含页名没变的那些）*/
    private int $notifies = 0;

    public function page(): string
    {
        return $this->page;
    }

    public function notifyCount(): int
    {
        return $this->notifies;
    }

    public function go(string $name): void
    {
        $this->page = $name;
        $this->notifyChanged();
    }

    /** 改别的字段（页名不变）⇒ PageHost 不该重建，但通知必须到 */
    public function touchOtherField(): void
    {
        $this->notifyChanged();
    }

    public function notifyChanged(): void
    {
        $this->notifies++;

        parent::notifyChanged();
    }
}
