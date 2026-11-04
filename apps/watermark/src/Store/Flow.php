<?php
declare(strict_types=1);

namespace App\Watermark\Store;

/**
 * 流水线的**入口**（也是唯一的 `AsyncCommand` 的参数）。
 *
 * ★ 用 enum 而不是字符串：`AsyncCommand::duplicated()` 靠 `===` 去重，而枚举 case
 *   是进程内单例 ⇒ 同一个入口天然相等、不同入口天然不等。字符串魔法值写错不报错，
 *   只是"去重失效"或"误判成另一个入口"。
 *
 * ★ 没有 `Poll`（"重试查询"）：那个入口零消费者。按本仓纪律
 *   「未被消费的分支 = 未实现」不预留 —— 真要加时加一行 case + 复用 `pollLoop()`。
 *
 * @internal
 */
enum Flow
{
    /** 首屏预热取签名（失败不打扰用户） */
    case Prewarm;

    /** 点「上传」：签名不够新就先取，然后传 */
    case Upload;

    /** 点「提交任务」：建任务 → 轮询 → 下载 */
    case Submit;
}
