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
        /** @var array<int, array{p: int, c: string, x: bool}> 节点号 ⇒ 父 / 短类名 / 是否 Flex */
        public array $nodes = [],
        /** @var list<FrameRecord> 按帧号升序 */
        public array $frames = [],
        /** @var array<int, array<int, GeometryRecord>> 帧号 ⇒ 节点号 ⇒ 几何 */
        public array $geometry = [],
        /** @var array<int, PaintRecord> 帧号 ⇒ 绘制事实 */
        public array $paint = [],
        /** @var array<int, NetworkRecord> requestId ⇒ 骨架 */
        public array $network = [],
        /** @var array<int, NetworkBodyRecord> requestId ⇒ 报文 */
        public array $bodies = [],
        /** @var array<int, true> 没有自己盒的节点（复合控件） */
        public array $boxless = [],
        /** @var array<int, int> 已回收节点 id => 最后见到的帧 */
        public array $evicted = [],
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
}
