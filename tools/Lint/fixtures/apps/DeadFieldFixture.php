<?php
declare(strict_types=1);

namespace App;

/**
 * 夹具：`dead-field` 的已知错误 / 已知正确（2026-11-13）。
 *
 * 该响 1 条：`field('nmae')` 是孤立字面量，同文件没有任何行字段出处。
 *
 * 不该响的四条证据通道，各造一个**只由该通道提供证据**的字段名，
 * 免得某条通道失效时被别的通道掩盖（判据同源 = 假绿）：
 *  - ① `make('city')` 的列身份；
 *  - ② `'code' =>` 行数据键（字段名与列 key 不同，是合法写法）；
 *  - ③ `$row['tel']` 取用；
 *  - ④ `const FIELDS` 显式契约（行数据来自外部时的逃生口）。
 *
 * 另外三条「看着像但不是」：动态 `field('c' . $i)`、双引号、
 * 以及语句里没有 `TableColumn` 的同名私有方法 `$this->field('姓名', …)`。
 */
final class DeadFieldFixture
{
    /** 行数据来自外部时，用显式契约声明字段名（④） */
    private const FIELDS = ['memo'];

    private array $rows = [
        ['id' => 1, 'code' => '100000'],
    ];

    /** @return list<mixed> */
    public function columns(): array
    {
        return [
            TableColumn::make('id', 'ID')->field('id'),              // ① 列身份
            TableColumn::make('city', '城市')->field('city'),          // ①（外部数据形态）
            TableColumn::make('zip', '邮编')->field('code'),           // ② 行数据键
            TableColumn::make('phone', '电话')->field('tel'),          // ③ 见 read()
            TableColumn::make('note', '备注')->field('memo'),          // ④ 显式契约
            TableColumn::make('email', '邮箱')->field('nmae'),         // ← 该响
            TableColumn::make('c0', 'C0')->field('c' . (string) 0),   // 动态 ⇒ 跳过
        ];
    }

    /** @param array<string, mixed> $row */
    public function read(array $row): string
    {
        return (string) $row['tel'];
    }

    public function rows(): array
    {
        return $this->rows;
    }

    /** 同名但无关的私有方法：语句里没有 TableColumn ⇒ 不判 */
    public function labelled(): string
    {
        return $this->field('姓名', '甲');
    }

    private function field(string $label, string $value): string
    {
        return $label . $value;
    }
}
