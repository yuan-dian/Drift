<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Widget\State;
use Drift\Widget\StatefulWidget;

/**
 * @internal 反向自检：与 `LeafBoxWidget` 同一个 State，但**不**覆写
 * `createRenderObjectFromState()` ⇒ 必须走 `build()`。
 *
 * 存在的理由：只写正例的量具"永远绿"—— 计数器自己坏了也看不出来。
 */
final class LeafBoxFallbackWidget extends StatefulWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new LeafBoxState();
    }
}
