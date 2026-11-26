<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一份**离线的证据**：从文件读回来、尚未装进 {@see Recorder} 的那堆记录。
 *
 * ## 为什么要一个中间对象
 *
 * 让 `hydrate()` 直接收七个参数的话，其中五个是同型的数组 —— 调用处写反了 PHP
 * 不报错（类型一样），而"几何被当成网络"的后果是查询给出空结果，看起来像
 * "这一域没采到"。字段名换成一串具名属性，写反至少要写错名字。
 *
 * 也不让读取器直接往 `Recorder` 的私有字段里灌：那样"文件格式"与"记录层内部
 * 形状"就焊在一起，将来加一域要同时改两处，而漏改一处只会在某个查询上显形。
 *
 * ## 为什么不带 generation
 *
 * 代际是"这一轮录制"的防具（帧号重用不命中旧缓存）。跨包没有意义 —— 导入的是
 * 一个新实例，它的派生物全部现算。写进包里只会让人以为"这个数有意义"。
 *
 * @internal 证据包的中间形状，不是稳定 API
 */
final readonly class EvidenceBag
{
    public function __construct(
        /** @var array<int, array{p: int, c: string, x: bool, s?: int, f?: int}> 节点号 ⇒ 父 / 短类名 / 是否 Flex / 寿命两端 */
        public array $nodes = [],
        /** @var list<FrameRecord> 按帧号升序 */
        public array $frames = [],
        /** @var array<int, array<int, GeometryRecord>> 帧号 ⇒ 节点号 ⇒ 几何 */
        public array $geometry = [],
        /** @var array<int, PaintRecord> 帧号 ⇒ 绘制事实 */
        public array $paint = [],
        /** @var array<int, NetworkRecord> requestId ⇒ 骨架 */
        public array $network = [],
        /** @var array<int, AsyncRecord> id => 任务状态跃迁 */
        public array $async = [],
        /** @var array<int, NetworkBodyRecord> requestId ⇒ 报文 */
        public array $bodies = [],
        /** @var array<int, true> 没有自己盒的节点（复合控件） */
        public array $boxless = [],
        /**
         * 已回收节点：`['f' => 出生帧, 's' => 最后见到帧, 'g' => 离开树的那帧]`。
         *
         * 旧证据包里是单个 int（最后见到帧）；读取方一律经
         * {@see Recorder::evictedNodes()} 归一，不要在这里各自判类型。
         *
         * @var array<int, array{f: int, s: int, g: int}|int>
         */
        public array $evicted = [],
        /**
         * 节点 ⇒ 离开树的那一帧（几何采样里再也看不到它的那一帧）。
         *
         * 空表有两个意思：真没有节点离开过，或者这份证据没采几何、根本不知道。
         * 调用方不能只看"空"就断定"全活着"，要结合 `options->geometry()`。
         *
         * @var array<int, int>
         */
        public array $gone = [],
        public ?Script $script = null,
        /**
         * 采集预算。脱敏开关也在这里：包里存的是明文时若一律报"已脱敏"，读者会把
         * 凭证当成"检查过且干净"——`redacted: 0` 不等于没敏感字段。
         */
        public ?RecordOptions $options = null,
        /**
         * 包里**声明包含**哪些域，值域见 {@see Evidence::D_*}。
         *
         * 声明不等于非空：`network` 在域内但一份请求都没发，与"这个版本不写网络域"
         * 是两件事。前者查询答"0 条请求"，后者必须答"这一域不在包里"。
         *
         * @var list<string>
         */
        public array $domains = [],
    ) {
    }

    /**
     * 回收记录的形状归一：两种形状读成同一种。
     *
     * 新的是数组（父 / 类型 / 寿命三端），旧证据包与"直接往属性里塞 int"的测试
     * 路径只有最后见到帧。归一放在**读口**，不要在消费者里各判一次类型。
     *
     * ★ 放在这里而不是 `Recorder` 或 `History`：`Recorder::hydrate()`（写面）与
     *   `History::evictedNodes()`（审查面）都要用它，而 `Recorder` 引用的每个类型都
     *   会被 tpc 编进产物 —— 反过来（`History` 持有它）就把审查面带进了写面。
     *   本类本来就在写面白名单里（它是 `hydrate()` 的参数类型），两边都能用。
     *
     * @param array<string, mixed>|int $meta
     * @return array{p: int, c: string, x: bool, f: int, s: int, g: int}
     */
    public static function evictedRow(array|int $meta): array
    {
        if (\is_array($meta)) {
            return [
                'p' => (int) ($meta['p'] ?? -2),
                'c' => (string) ($meta['c'] ?? ''),
                'x' => (bool) ($meta['x'] ?? false),
                // 旧包里没有 `f`：拿 `s` 顶上而不是 0 —— 0 会被读成"出生帧未知"，
                // 让整张表报"不可回放"，而它其实是"只知道活到 `s`"。
                'f' => (int) ($meta['f'] ?? $meta['s'] ?? 0),
                's' => (int) ($meta['s'] ?? 0),
                'g' => (int) ($meta['g'] ?? 0),
            ];
        }

        return ['p' => -2, 'c' => '', 'x' => false, 'f' => $meta, 's' => $meta, 'g' => 0];
    }
}
