<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 会话的**读**面：按名字分派一次查询，拿回一份纯数组。
 *
 * ## 为什么要有这个接口
 *
 * 同一个消费者的代码要能跑在两种进程拓扑上：记录就在手边（同进程），或记录在
 * 另一个进程里（远端）。没有接口时，"读一次记录"这件事会被写两遍 —— 一遍本地、
 * 一遍发消息，然后两份在某个字段上悄悄分叉，而报告看起来都正常。
 *
 * 接口的定义域只有一件事：**输入是一串标量参数，输出是一份数组**。它不承诺
 * 返回值里有什么类型之外的东西（对象、闭包、句柄都不行），这正是它能跨进程的
 * 原因，也是实现方必须守的边界。
 *
 * ## 为什么读与驱动分成两个接口
 *
 * 面板只需要读；重放只需要驱动。合成一个"会话"接口会让只读的消费者拿到
 * `pump()`，于是"顺手推一帧看看"变成合法写法 —— 读就不纯了。
 *
 * @api
 */
interface SessionQueries
{
    /**
     * @param array<string, mixed> $args
     *
     * @return array<array-key, mixed> 纯数组（标量 / 数组 / null，不含对象）
     *
     * @throws \InvalidArgumentException 未知查询或参数不对
     */
    public function query(string $name, array $args = []): array;
}
