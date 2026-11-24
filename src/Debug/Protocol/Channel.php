<?php
declare(strict_types=1);

namespace Drift\Debug\Protocol;

/**
 * 一条**有身份**的通道：仍然是 {@see Transport}，但多一个能写进日志的名字。
 *
 * 名字是给「这条连接是谁」这个问题用的：多客户端时，一条坏消息必须能指到
 * 具体那条连接（"127.0.0.1:51234 发了不认识的方法"），否则排查只能靠猜
 * 「大概是我另开的那个终端」。
 *
 * 不加 `id()`：对象身份就是身份。加一个字符串 id 只会多一处要保证唯一的地方，
 * 而 `spl_object_id()` 已经回答了这个问题。
 *
 * @api
 */
interface Channel extends Transport
{
    /** 通道名，只用于日志与报错（如 `pid 1234`、`127.0.0.1:51234`）。 */
    public function peer(): string;
}
