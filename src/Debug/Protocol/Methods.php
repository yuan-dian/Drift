<?php
declare(strict_types=1);

namespace Drift\Debug\Protocol;

/**
 * 协议里的**方法名**。客户端与宿主共用这一份，避免两边各写一串字符串。
 *
 * ## 按角色分组，不是"越少越好"
 *
 * | 角色 | 方法 | 方向 |
 * |---|---|---|
 * | 会话 | `open` / `close` | 客户端说，宿主做 |
 * | 驱动 | `frame` / `input` / `effect` | 同上 |
 * | 读 | `query` | 同上 |
 * | 内省 | `eval` | 同上；**只对活宿主有意义**，默认关，结果不是证据 |
 * | 干预 | `pause` / `resume` / `highlight` | 同上，但 `pause`/`resume` **只对自走宿主有意义** |
 * | 收尾 | `finish` | 客户端说，宿主标结束；**只对从属宿主有意义** |
 *
 * 纪律还在，只是换了写法：凡是能由 `query` 表达的，都不另开动词 —— 每多一个动词，
 * 对端就多一份"哪个先哪个后"的状态要维护，而状态会漂。`script` / `digest` 因此是
 * **查询名**，不是方法名。
 *
 * `eval` 是这条纪律的**唯一例外**，理由写在 {@see \Drift\Dev\Debug\Evaluator}：它要摸
 * 活应用，而 `query` 那层刻意不认识应用（离线包没有应用）；`effect` 会写脚本，而
 * 求值明确不进包。它不是"顺手能做的事"，是内省这条独立的线。
 *
 * 干预这一组是唯一的反向流：它改的不是记录，是**推进的节奏**（`highlight` 改的是
 * 下一帧画什么样，同样是"改推进"而不是"改记录"）。加方法时往里填，
 * 不是破例 —— 但也别把"顺手能做的事"都塞进来（断点是一整套设计）。
 *
 * @api
 */
final class Methods
{
    /** 建应用、挂表面、开会话。参数 `script`（脚本骨架：目标 + 视口 + 环境）。 */
    public const string OPEN = 'open';

    /** 推一帧。参数 `t`。返回 `{id, wall}`。自走宿主上被拒（帧由脚本给）。 */
    public const string FRAME = 'frame';

    /** 派发一条输入。参数 `step`（与脚本里的步骤同形状）。 */
    public const string INPUT = 'input';

    /** 执行具名副作用。参数 `name` + `args`。返回 `{value}`。 */
    public const string EFFECT = 'effect';

    /** 分派一次查询。参数 `name` + `args`（与本地 `query()` 完全同形）。 */
    public const string QUERY = 'query';

    /**
     * 在活应用上求一个 PHP 表达式。参数 `expression` + 可选 `node`。
     *
     * ★ 宿主默认拒绝（回 `not_applicable`）：任意代码执行是安全面，
     *   要显式 `debug serve --allow-eval` 才开，且只绑 loopback。
     * ★ 结果不是证据：不进包、不进指纹、不参与重放（A55）。
     *   求值若改了应用状态，回执的 `evidenceChanged` 会如实报出来。
     */
    public const string EVAL = 'eval';

    /** 让自走的宿主停一下。回执与一次 `state` 查询同形。 */
    public const string PAUSE = 'pause';

    /**
     * 高亮一个节点。参数 `node`（正整数；`0` = 清除）+ 可选 `t`。
     *
     * ★ 这是**干预**组的第二个动词，不是查询：它改的是"下一次绘制的样子"，
     *   而"现在高亮了谁"是 `query('highlight')`。两者分开，跨进程时也一样。
     * ★ 回执给 `{node, drawnFrame}`：宿主侧的记录能直接回答"上屏了没"，
     *   客户端不必再补一次查询（两次查询之间可能已经又跑了几帧）。
     */
    public const string HIGHLIGHT = 'highlight';

    /**
     * 布防 / 撤防**活窗口拾取**。参数 `enabled`（布尔）。
     *
     * ★ 布防后真窗口的下一次点击不进应用，只走 `pick` → `highlight`，点完自动撤防。
     *   与查询名 `pick` 的关系、与 `highlight` 动词和 `highlight` 查询的关系完全同形：
     *   动词改"下一次会发生什么"，查询读"现在是什么"。
     * ★ 只对有真窗口的 live 宿主有意义，自走宿主回 `not_applicable`。
     * ★ 暂停态布防不会生效（A57：暂停连输入一起挡），回执如实报 `armed=false`。
     */
    public const string PICK = 'pick';

    /**
     * 让自走的宿主继续。参数 `frames`（可选正整数）：给了就只走这么多帧，
     * 走完回 `paused` + `reason=step`。`1` 就是单步 —— 推进的是脚本自己的下一帧，
     * 客户端不必（也不该）提供时间戳。
     */
    public const string RESUME = 'resume';

    /**
     * 客户端通知：这次驱动结束了。参数 `at`（可选正整数）：因为 `--at` 停的。
     * 回执与一次 `state` 查询同形。自走宿主上被拒（何时停由它自己判）。
     */
    public const string FINISH = 'finish';

    /** 卸录制器、关会话。 */
    public const string CLOSE = 'close';

    public const array ALL = [
        self::OPEN,
        self::FRAME,
        self::INPUT,
        self::EFFECT,
        self::QUERY,
        self::EVAL,
        self::PAUSE,
        self::HIGHLIGHT,
        self::PICK,
        self::RESUME,
        self::FINISH,
        self::CLOSE,
    ];
}
