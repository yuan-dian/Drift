<?php
declare(strict_types=1);

namespace Drift\Logging;

/**
 * ② 层：把 `LogEntry` 变成一行文本（`12` §4.7）。
 *
 * ★ 三层的分界（**① 与 ③ 语义互斥**是本接口存在的理由）：
 *   ① pattern 字符串 ⇒ `PatternFormatter`（零代码改样式）
 *   ② 格式化器对象 ⇒ 本接口（条件/对齐/字段排序）
 *   ③ 完全接管 ⇒ `LogSink`（改去向）
 *   Qt 文档明写 `qSetMessagePattern()` 装了自定义 handler 后**不生效** ——
 *   框架不再格式化。Drift2 同约定：sink 自己产出字符串 = 接管格式化，
 *   pattern 不再参与；想"既走 sink 又改格式"，改的是本接口而不是 sink。
  * @api
*/
interface LogFormatter
{
    public function format(LogEntry $entry): string;
}
