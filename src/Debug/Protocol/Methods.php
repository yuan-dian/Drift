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
 * | 干预 | `pause` / `resume` | 同上，但**只对自走宿主有意义** |
 * | 收尾 | `finish` | 客户端说，宿主标结束；**只对从属宿主有意义** |
 *
 * 纪律还在，只是换了写法：凡是能由 `query` 表达的，都不另开动词 —— 每多一个动词，
 * 对端就多一份"哪个先哪个后"的状态要维护，而状态会漂。`script` / `digest` 因此是
 * **查询名**，不是方法名。
 *
 * 干预这一组是唯一的反向流：它改的不是记录，是**推进的节奏**。加方法时往里填，
 * 不是破例 —— 但也别把"顺手能做的事"都塞进来（断点、高亮各是一整套设计）。
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

    /** 让自走的宿主停一下。回执与一次 `state` 查询同形。 */
    public const string PAUSE = 'pause';

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
        self::PAUSE,
        self::RESUME,
        self::FINISH,
        self::CLOSE,
    ];
}
