<?php
declare(strict_types=1);

namespace Drift\DevTools\Protocol;

use Drift\DevTools\Session\QueryRouter;

/**
 * 调试协议的版本与能力目录：**一份声明，多个出口共用**。
 *
 * ## 为什么要这一层
 *
 * 客户端以前只能靠"发一个方法试试，看回不回 `unknown_method`"来认识宿主。这条
 * 路走通了也只回答"有没有"，答不了"现在能不能用"：`eval` 默认关、`pause` 只对
 * 自走宿主有意义、`pick` 要真窗口布防 —— 三者的方法名都在，调用却都被拒。
 * Chrome DevTools 的 `Schema.getDomains` / `Browser.getVersion`、LSP 的
 * `initialize.capabilities`、Flutter VM service 的 `getVersion` 都在做同一件事：
 * **让客户端先问再做**，别把发现做成试探。
 *
 * ## 声明从哪来
 *
 * 方法集合取 {@see Methods::ALL}，查询集合取 {@see QueryRouter::QUERIES}，查询
 * 参数取 {@see QueryRouter::QUERY_SPECS}，错误码取 {@see ProtocolError::ALL}。
 * 这里**不**另抄一份名单：抄下来的第二份名单一定 rotten —— 加一个查询只改一处
 * 时，能力目录会继续说"没有这个能力"，而客户端按它根本不会去用。
 * 每份声明都做双向闭合：少了当场抛，多了也当场抛。
 *
 * ## 它不干什么
 *
 * 不替代实际的拒绝逻辑。`available=false` 是描述，真正拦住调用的是宿主分派里
 * 那一处判断；两者必须同源（Host 的 `unavailable()` 同时喂给分派和这里），
 * 否则"目录说能用、调用被拒"和"目录说不能用、调用却成功"都会出现。
 *
 * @api
 */
final class Capabilities
{
    /**
     * 协议主版本。
     *
     * ★ 加方法 / 加查询 / 加可选字段**不加版本**：能力目录本身就是用来声明这些的，
     *   把它塞进版本号等于让每个新查询都逼所有客户端升一次主版本。
     * 只有**同名字段语义变了**或旧客户端会误解新响应时才加一。
     *
     * 2：多了事件——一种**没有 `id` 的消息**。按版本 1 实现的客户端读到它会把
     * "id 对不上"判成通道错乱并抛 `BAD_RESPONSE`，所以这是必须加一的那种。
     */
    public const int VERSION = 2;

    /**
     * 比它更老的客户端不被这份声明接受；`null`（没报版本）按当前版本处理。
     *
     * 直接等于 {@see self::VERSION}：**不保留向下区间**。区间是给"要同时伺候两代
     * 客户端"留的位置，而这里没有那种需求（仓库里没有版本 1 的客户端）。
     * 留 `1` 反而有害：仓根那个 AOT 产物可能是上一版生成的，它发 `protocol: 1`
     * 时该收到明确的 `unsupported_version`（去重新构建），而不是一个看不出原因的
     * `bad_response`（以为通道乱了）。
     */
    public const int MIN_VERSION = 2;

    /**
     * 方法的角色与参数。`Methods::ALL` 是能力面的唯一成员来源，这张表只补元数据；
     * 少一项会让 `describe()` 当场抛 —— 那比"能力目录悄悄少一列"好得多。
     *
     * `params` 是**名字**（不是形状）：方法的参数校验在宿主分派里，能力目录不写
     * 第二套类型规则。
     *
     * @var array<string, array{kind: string, params: list<string>}>
     */
    private const array METHOD_META = [
        Methods::OPEN => ['kind' => 'session', 'params' => ['script', 'token']],
        Methods::FRAME => ['kind' => 'drive', 'params' => ['t']],
        Methods::INPUT => ['kind' => 'drive', 'params' => ['step']],
        Methods::EFFECT => ['kind' => 'drive', 'params' => ['name', 'args']],
        Methods::CAPABILITIES => ['kind' => 'discovery', 'params' => ['version']],
        Methods::QUERY => ['kind' => 'read', 'params' => ['name', 'args']],
        Methods::EVAL => ['kind' => 'introspect', 'params' => ['expression', 'node']],
        Methods::PAUSE => ['kind' => 'control', 'params' => []],
        Methods::HIGHLIGHT => ['kind' => 'control', 'params' => ['node']],
        Methods::INSPECT => ['kind' => 'control', 'params' => ['layers']],
        Methods::PICK => ['kind' => 'control', 'params' => ['enabled']],
        Methods::RESUME => ['kind' => 'control', 'params' => ['frames']],
        Methods::FINISH => ['kind' => 'finish', 'params' => ['at']],
        Methods::CLOSE => ['kind' => 'session', 'params' => []],
    ];

    public static function supports(int $version): bool
    {
        return $version >= self::MIN_VERSION && $version <= self::VERSION;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(?int $clientVersion = null): array
    {
        if ($clientVersion !== null && !self::supports($clientVersion)) {
            throw new ProtocolError(
                ProtocolError::UNSUPPORTED_VERSION,
                "协议版本 {$clientVersion} 不受支持（本宿主支持 " . self::MIN_VERSION . '..' . self::VERSION . '）',
            );
        }

        return [
            'protocol' => self::VERSION,
            'minProtocol' => self::MIN_VERSION,
            // 没报就是没报。不填成 VERSION：客户端需要区分"我没声明"与"我声明了 1"，
            // 后者意味着它真的按 1 的语义实现过，前者只意味着它还没握手。
            'clientProtocol' => $clientVersion,
            'methods' => self::methods(),
            'queries' => self::queries(),
            // 事件是第三种方向（宿主自己开口），所以单独一栏而不是塞进 methods：
            // 客户端按它决定"要不要等某个事件"，与动词的"能不能调"是两个问题。
            'events' => Events::describe(),
            'errors' => ProtocolError::ALL,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function methods(): array
    {
        // 先反向闭合：`METHOD_META` 里多出来的键不会被下面的循环看见，于是"退役了
        // 一个动词却留着它的角色声明"不会有任何症状 —— 直到有人按目录去调用。
        foreach (self::METHOD_META as $name => $_meta) {
            if (!\in_array($name, Methods::ALL, true)) {
                throw new \LogicException("能力目录给未登记的方法 {$name} 写了角色声明：Methods::ALL 里没有它");
            }
        }

        $out = [];
        foreach (Methods::ALL as $name) {
            $meta = self::METHOD_META[$name] ?? null;
            if ($meta === null) {
                // 新动词登记了却没说明它是什么：目录宁可拒绝回答，也不交一张
                // 少一列且看起来完整的清单。
                throw new \LogicException("能力目录缺少方法 {$name} 的角色声明");
            }
            $out[] = [
                'name' => $name,
                'kind' => $meta['kind'],
                'params' => $meta['params'],
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function queries(): array
    {
        $out = [];
        foreach (QueryRouter::QUERIES as $name => $about) {
            $spec = QueryRouter::QUERY_SPECS[$name] ?? null;
            $selfServed = \in_array($name, QueryRouter::SELF_SERVED, true);
            if ($spec === null && !$selfServed) {
                throw new \LogicException("查询 {$name} 在能力面上有名字，却没有协议形状也没有会话实现");
            }

            $params = [];
            if ($spec !== null) {
                foreach ($spec[1] as $key => $shape) {
                    $type = (string) $shape[0];
                    $required = !\array_key_exists(1, $shape) && !\str_starts_with($type, '?');
                    $item = [
                        'name' => (string) $key,
                        'type' => $type === 'inject' ? 'inject' : $type,
                        'required' => $required,
                        'injected' => $type === 'inject',
                    ];
                    // 必填参数**没有** default 键：给个 null 会让它看起来像"可空、
                    // 缺省 null"，而协议层其实会因缺参报错。没给就是没给。
                    if ($type === 'inject') {
                        // 第二项是会话侧的注入源，不是调用方可填的默认值。
                        $item['source'] = (string) ($shape[1] ?? '');
                    } elseif (\array_key_exists(1, $shape)) {
                        $item['default'] = $shape[1];
                    } elseif (\str_starts_with($type, '?')) {
                        $item['default'] = null;
                    }
                    $params[] = $item;
                }
            }

            $out[] = [
                'name' => $name,
                'about' => $about,
                'origin' => $selfServed ? 'session' : 'query',
                'params' => $params,
            ];
        }

        foreach (QueryRouter::QUERY_SPECS as $name => $_spec) {
            if (!\array_key_exists($name, QueryRouter::QUERIES)) {
                throw new \LogicException("查询 {$name} 有协议形状却不在能力面上：客户端永远发现不了它");
            }
        }

        return $out;
    }
}
