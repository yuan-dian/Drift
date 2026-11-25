<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一个会话的两面：**读**（{@see SessionQueries}）与**驱动**（{@see SessionDriver}）。
 *
 * ## 为什么要有这个合成接口
 *
 * 读与驱动分开是对的：面板只要读，重放只要驱动。"顺手推一帧再读"不该是读方的
 * 权利。但**面板**两者都要——它一边推帧（`n`）一边把同一个会话问十几次。
 *
 * 没有这个接口时，面板只能收两个参数（`$queries, $driver`），于是"本地会话配远端
 * 驱动"在类型上完全合法：查询读的是 A 会话的记录，推帧推的是 B 会话的应用。两份
 * 结果都正常，凑在一起是假的。合成接口把"同一个会话"这件事写进类型里。
 *
 * 实现方只有两个：{@see \Drift\App\DebugSession}（记录在手边）与
 * {@see \Drift\Debug\Protocol\RemoteSession}（记录在另一个进程里）。消费者不需要
 * 知道是哪一个——这正是 D3 判据"跨进程读到的值与同进程逐值相同"的前提。
 *
 * ## 为什么 `close()` 也进接口
 *
 * 没有它，每个消费者都要自己猜"这个会话跑完之后谁来关"。本地那份关闭时会**从
 * 窗口上解绑**录制器：不解绑，那个窗口的帧会继续写进一份已经没人读的记录，而
 * 想再开一个会话又会被"这个窗口已有会话"拒掉。于是一段"开 N 次"的循环（`loop`）
 * 必须知道手里这个东西能关，否则它写的就不是循环，是猜。
 *
 * @api
 */
interface Session extends SessionQueries, SessionDriver
{
    /**
     * 通知会话：这次驱动结束了。
     *
     * `$at` 是调用方知道的事实（只跑到第 N 帧就停了），不是它猜的状态名。
     * 返回与 `query('state')` 同形的数组：控制面与观察面不能有两套形状。
     * 本地与远端都走这里 —— 一处实现各自翻译，调用方不必知道自己连的是哪一个。
     *
     * @return array{session: string, state: string, reason: ?string, lastFrame: int}
     */
    public function finish(?int $at = null): array;

    /** 结束这次会话。重复调用必须无害。 */
    public function close(): void;
}
