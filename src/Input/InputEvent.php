<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 已归一化的平台事件，是 `InputSource::poll()` 的唯一返回类型。
 *
 * 标记接口，不带方法：事件字段差异太大，公共方法只会重复 `instanceof`。
 * 实现者是 `final readonly` 值对象；Win32 `MSG` 在 `Platform::pumpEvents()` 就翻成这些对象。
 * 新增方法必须有真实消费者（A15）。
 *
 * @api
 */
interface InputEvent
{
}
