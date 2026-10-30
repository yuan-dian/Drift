<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Widget\FutureBuilder;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：根就是一个 `FutureBuilder`（把一个注入的 `Future` 画成文本）。
 *
 * ★ 存在的理由：`FutureBuilder` 的判据全在"四态怎么流转"，而那只有**真挂树**
 *   （initState 订阅 → 帧内 setState → settle 重建 → builder 再收到新快照）
 *   才走得到。纯单测只能验 `AsyncValue` 的四态互斥（已在 `Unit/Async`）。
 *
 * ★ `$snapshots` 是**观测面**：builder 每次被调用都记一笔，测试读最后一笔。
 *   比"从树上找 Text 再比字符串"稳 —— 后者依赖布局 / 裁剪，与语义无关。
 */
final class FutureBuilderApplication extends Application
{
    /** @var list<AsyncValue> builder 每次被调用的快照（按时间正序） */
    public array $snapshots = [];

    public function __construct(private ?Future $future)
    {
        // ★ 必须调父构造器：基类在构造器里建七个拆分片（闸门
        //   `AppSubclassCtorParent` 守着；漏了 AOT 真机 exit=255）。
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return FutureBuilder::make($this->future, function (AsyncValue $value): Widget {
            $this->snapshots[] = $value;

            // 四态写全（fold 缺键会当场抛，测试里那等于红）
            // ★ 四支一律 `AsyncValue` 一个参数 —— 混合签名（data 收值、idle 零参）
            //   在解释器下写错也不报，只在 AOT 崩（见 `AsyncValue::fold()` 类注释）。
            return $value->fold([
                'idle' => static fn(AsyncValue $v): Widget => Text::make('idle'),
                'loading' => static fn(AsyncValue $v): Widget => Text::make('loading'),
                'error' => static fn(AsyncValue $v): Widget => Text::make('error'),
                'data' => static fn(AsyncValue $v): Widget => Text::make('data'),
            ]);
        });
    }

    public function title(): string
    {
        return 'FutureBuilder';
    }
}
