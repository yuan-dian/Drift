<?php
declare(strict_types=1);

namespace Drift\State;

use Drift\Core\Versioned;
use Drift\Errors;

/**
 * 业务数据的广播端（§11.1 / §11.3.1）。
 *
 * ★ **不认识 GUI**：子类（`Store`）住在应用包的 `store/` 目录，依赖的只有本类 ——
 *   "业务层零 GUI 依赖"因此是**结构事实**，不是纪律承诺（§4 目标 8）。
 *
 * ★ **无门控**：一次 `notifyChanged()` 可能改了 5 个字段，比较任何一端都没有
 *   意义。要"设同值不重建"，门控写在**调用点**（改之前先比一下），而不是在这里
 *   猜 —— 见 `State::setState()` 的示例。
 *
 * ★ **子类有两种，不是只有 Store**：本类是"**会广播的东西**"这一
 *   个概念，业务数据（`Store`）是它、一次异步请求的四态（`AsyncCommand`）也是它。
 *   ⇒ "跨页共享"与"页面内"因此共用同一套订阅设施，不需要第二套机制。
 *
 * ★ 实现 `Versioned`：`Computed` 靠它判断"依赖变了没有"，
 *   而 `Computed` 住 `Core` ⇒ 版本号只说"变了没有"，不提 Subject。
 *
 * @api
 */
abstract class Subject implements Versioned
{
    /**
     * @var array<int, array{int, string, callable(): void}> 订阅者；键 = 自增 id
     *     （稳定 id 才能在"遍历中退订"时不出错）。第 0 项是**属主的对象身份**。
     */
    private array $subscribers = [];

    private int $nextId = 1;

    /** @internal 版本号：广播一次 +1（`Computed` 的依赖判据） */
    private int $version = 0;

    /**
     * 广播"我的数据变了"（§11.3.1）。业务代码改完数据**手动调一次**。
     *
     * ★ **每个订阅者异常隔离**（§11.3 RT-3，GUI 仓全仓 0 个 try/catch 的后果
     *   是"第一个炸、后面全收不到"）：异常先记账，**通知完全部订阅者之后**才
     *   往 `Errors` 报 —— 当场报 = 当场中止 = 其余订阅者又收不到了，隔离就白做。
     */
    public function notifyChanged(): void
    {
        $this->version++;        // 先推版本：回调里读到的就是新版本

        $failures = [];

        foreach ($this->subscribers as $entry) {
            try {
                ($entry[2])();
            } catch (\Throwable $e) {
                $failures[] = sprintf('%s: %s', $entry[1], $e->getMessage());
            }
        }

        foreach ($failures as $failure) {
            Errors::report('订阅者异常已隔离，其余订阅者已收到通知 —— ' . $failure, Errors::RECOVERABLE);
        }
    }

    /**
     * 订阅（§11.1）。
     *
     * @param object $owner 订阅的属主 —— `unsubscribeAllOf()` 按它整批退
     */
    public function subscribeFor(object $owner, callable $handler): Subscription
    {
        $id = $this->nextId++;
        $this->subscribers[$id] = [spl_object_id($owner), get_class($owner), $handler];

        return Subscription::of($this, $id);
    }

    /** 退掉某个 `$owner` 的**全部**订阅（§11.3 RT-1 的另一半） */
    public function unsubscribeAllOf(object $owner): void
    {
        // ★ 属主按**对象身份**认，不按类名 —— 同一个类会有很多实例（每个页面的
        //   State 各一个），按类名认会把别人的订阅一起退掉。
        $ownerId = spl_object_id($owner);

        foreach ($this->subscribers as $id => $entry) {
            if ($entry[0] === $ownerId) {
                unset($this->subscribers[$id]);
            }
        }
    }

    /** @internal `Subscription::cancel()` 走这里；按 id 退，不碰同属主的其他订阅 */
    public function unsubscribeId(int $id): void
    {
        unset($this->subscribers[$id]);
    }

    /** @inherit */
    public function version(): int
    {
        return $this->version;
    }
}
