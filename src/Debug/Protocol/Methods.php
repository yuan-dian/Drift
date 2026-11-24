<?php
declare(strict_types=1);

namespace Drift\Debug\Protocol;

/**
 * 协议里的**方法名**。客户端与宿主共用这一份，避免两边各写一串字符串。
 *
 * 只有六个，而且刻意只有六个：三个"驱动"（`frame` / `input` / `effect`）、
 * 一个"读"（`query`）、一个"开"、一个"关"。凡是能由 `query` 表达的，都不另开动词 ——
 * 每多一个动词，对端就多一份"哪个先哪个后"的状态要维护，而状态会漂。
 *
 * `script` / `digest` 因此是**查询名**，不是方法名。
 *
 * @api
 */
final class Methods
{
    /** 建应用、挂表面、开会话。参数 `script`（脚本骨架：目标 + 视口 + 环境）。 */
    public const string OPEN = 'open';

    /** 推一帧。参数 `t`。返回 `{id, wall}`。 */
    public const string FRAME = 'frame';

    /** 派发一条输入。参数 `step`（与脚本里的步骤同形状）。 */
    public const string INPUT = 'input';

    /** 执行具名副作用。参数 `name` + `args`。返回 `{value}`。 */
    public const string EFFECT = 'effect';

    /** 分派一次查询。参数 `name` + `args`（与本地 `query()` 完全同形）。 */
    public const string QUERY = 'query';

    /** 卸录制器、关会话。 */
    public const string CLOSE = 'close';

    public const array ALL = [
        self::OPEN,
        self::FRAME,
        self::INPUT,
        self::EFFECT,
        self::QUERY,
        self::CLOSE,
    ];
}
