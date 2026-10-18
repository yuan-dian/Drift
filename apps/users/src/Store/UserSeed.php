<?php
declare(strict_types=1);

namespace App\Users\Store;

/**
 * 演示用的用户种子（**JSON 友好**的原始数组，不是 `User` 实例）。
 *
 * ★ 为什么要单独一层：后台任务跨进程只能传"数组"（`NamedJob` 的 `args()` / 返回值都要能
 *   `json_encode`）⇒ 业务数据必须先有**原始形态**，再在 UI 侧 `hydrate()` 成对象。
 *   这也让"同一个种子"能被两个进程各自读到（`MemoryUserSource` 与 worker 共用）。
 *
 * ★ 它**不是**真实数据源：真形态是 `HttpUserSource`（M4）。这里用固定种子是为了
 *   让"后台取数"这条链在没有网络的环境里也能端到端跑通。
 */
final class UserSeed
{
    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    public static function all(): array
    {
        return [
            ['id' => 1, 'name' => '张伟', 'email' => 'zhangwei@example.com'],
            ['id' => 2, 'name' => '李娜', 'email' => 'lina@example.com'],
            ['id' => 3, 'name' => '王强', 'email' => 'wangqiang@example.com'],
            ['id' => 4, 'name' => '赵敏', 'email' => 'zhaomin@example.com'],
            ['id' => 5, 'name' => '陈晨', 'email' => 'chenchen@example.com'],
            ['id' => 6, 'name' => '刘洋', 'email' => 'liuyang@example.com'],
            ['id' => 7, 'name' => '孙丽', 'email' => 'sunli@example.com'],
            ['id' => 8, 'name' => '周杰', 'email' => 'zhoujie@example.com'],
            ['id' => 9, 'name' => '吴静', 'email' => 'wujing@example.com'],
            ['id' => 10, 'name' => '郑昊', 'email' => 'zhenghao@example.com'],
            ['id' => 11, 'name' => '冯雪', 'email' => 'fengxue@example.com'],
            ['id' => 12, 'name' => '蒋涛', 'email' => 'jiangtao@example.com'],
            ['id' => 13, 'name' => '韩梅', 'email' => 'hanmei@example.com'],
            ['id' => 14, 'name' => '杨柳', 'email' => 'yangliu@example.com'],
            ['id' => 15, 'name' => '朱军', 'email' => 'zhujun@example.com'],
        ];
    }

    /**
     * 原始数组 → `User` 实例（**UI 侧**做：worker 只回 JSON，不碰 UI 类型）。
     *
     * @param array<int, mixed> $raw
     *
     * @return list<User>
     */
    public static function hydrate(array $raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = new User(
                (int) ($row['id'] ?? 0),
                (string) ($row['name'] ?? ''),
                (string) ($row['email'] ?? ''),
            );
        }

        return $out;
    }
}
