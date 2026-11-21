<?php
declare(strict_types=1);

namespace Drift\State;

/**
 * 纯函数状态机：step 决定"变成什么 + 该做什么"，本类只负责推进与发号。
 *
 * ★ 与线性流水线（`AsyncScope`）的分工：那里状态就是协程的指令指针，天然互斥；
 *   本类给的是**多事件源仲裁**的场景（防抖 / 拖拽 / 对话框流程）——几个事件随时
 *   都能进来，只有"当前持有的那个 attempt"的答案算数。watermark 的
 *   签名→上传→轮询不要搬进来：搬进来就是把线性代码拆回回调（见 `WatermarkStore`）。
 *
 * ★ 本类不执行命令：返回 `Effects`，由持有环境能力的调用方执行。于是 `src/State`
 *   不依赖环境，step 也能脱离窗口单测。
 *
 * ```php
 * $machine = new Machine($state, static function (mixed $s, mixed $e, Seq $seq): Effects {
 *     if ($e->kind === Query::KIND) {
 *         return new Effects(Probe::waiting($seq->next(), $e->query), ['schedule']);
 *     }
 *     if ($s->kind === Probe::WAITING && $e->attempt === $s->attempt) {
 *         return new Effects(Probe::done($s->query), ['land']);
 *     }
 *
 *     return new Effects($s, []);   // 过期答案：什么也不做
 * });
 *
 * foreach ($machine->dispatch($event)->commands as $cmd) { ... }
 * ```
 *
 * @api
 */
final class Machine
{
    /** ★ 一个实例一副发号器：重建 Machine 就等于作废它发出的所有 attempt */
    private Seq $seq;

    /** @param \Closure(mixed, mixed, Seq): Effects $step */
    public function __construct(
        private mixed $state,
        private \Closure $step,
    ) {
        $this->seq = new Seq();
    }

    public function state(): mixed
    {
        return $this->state;
    }

    /**
     * 推进一步：step 是纯函数，本类只应用新状态并把命令原样交回。
     *
     * ★ 先取变量再调用（AOT 已验证形态）；`$this->state` 每一步都换成 step 的结果，
     *   调用方不得直接写状态——否则"非法状态"又从后门回来了。
     */
    public function dispatch(mixed $event): Effects
    {
        $step = $this->step;
        $fx = $step($this->state, $event, $this->seq);
        $this->state = $fx->state;

        return $fx;
    }
}
