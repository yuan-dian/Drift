<?php
declare(strict_types=1);

namespace App\Users\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Async\AsyncValue;
use Drift\State\SubjectBuilder;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Dock;
use Drift\Widget\Each;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Spacer;
use Drift\Widget\Spinner;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

use App\Users\Store\User;
use App\Users\Store\UserStore;
use App\Users\Widgets\UserRow;

/**
 * 列表页（§A.2.9）：搜索 + 三态列表 + 删除。
 *
 * ★ **订阅点在页这一层，行内只吃值**（§11.3.2 纪律 2）：`SubjectBuilder` 的
 *   `select` 是切片（只跟自己那一份数据），列表重排不会每行广播一次。
 * ★ 四态用 **`AsyncValue::fold()`**（漏一支当场抛）—— 以前是 `match` 无 default
 *   守的 `LoadStatus` 封闭域（§12.1 第 2 条）；那个枚举已被 `AsyncValue` 取代，
 *   "穷尽"由 `fold()` 在运行期入口保证。
 *
 * ★ **头部固定、列表可滚**：页面是 `Dock`（top = 标题 + 搜索行，center =
 *   可滚动列表）—— §12.1 第 3 条的"外壳 + 内容槽"形态（外壳持久、只换槽）。
 *   ★ 2026-10-17 起 `Column + Expanded` 同样可用：flex 分母已修为**只含非弹性子
 *   自然尺寸**（`RenderFlex` 阶段 2），弹性项不再被双重扣减（此前内容比视口长时
 *   拿 `tight(0)` 整块不可见）。Dock 仍优先 —— 外壳/内容槽是**语义分工**，
 *   不是绕缺陷。滚动位置是页面级纯 UI 态 ⇒ 放 `State`
 *   （§12.3 判据：承载它的 Element 不会被回收 —— 本容器不做虚拟化）。
 */
final class UsersPage extends StatelessWidget
{
    public function __construct(private readonly UserStore $store)
    {
        parent::__construct();
    }

    public static function make(UserStore $store): self
    {
        return new self($store);
    }

    public function build(BuildContext $ctx): Widget
    {
        return Dock::make()
            ->top(
                Column::make()->spacing(16)->padding(EdgeInsets::all(24.0))->children([
                    Text::make('用户目录')->title(),

                    Row::make()->spacing(8)->children([
                        // ── 搜索框：官方订阅形态（§11.4.2）—— 不提供 bind() ──
                        SubjectBuilder::make($this->store)
                            ->select(static fn(UserStore $s): string => $s->query())
                            ->builder(fn(string $q): Widget => TextField::make()
                                ->value($q)
                                ->label('搜索')
                                ->placeholder('输入姓名')
                                ->onChanged(fn(string $x) => $this->store->setQuery($x))),

                        // ★ 转圈时禁用「刷新」：判据直接读命令的 `isRunning()`。
                        //   Store 里的命令与页面里的命令是**同一个类** ⇒ "现在能不能点"
                        //   在两处是同一句话，不需要 Store 再暴露一个 `isLoading()`。
                        Button::primary('刷新')
                            ->enabled(!$this->store->page()->isRunning())
                            ->command('users.refresh'),

                        Spacer::make(),

                        Button::primary('新建用户')->command('nav.signup'),
                    ]),
                ])
            )
            ->center(
                ScrollView::make(
                    Column::make()->spacing(8)->padding(EdgeInsets::only(24.0, 0.0, 24.0, 24.0))->children([
                        // ── 列表：订阅点在这层，行内只吃值 ──
                        // ★ 切片是命令的**当前值对象**（`AsyncValue`）：它是不可变的，
                        //   没换值就是**同一个实例** ⇒ `SubjectBuilder` 的 `===` 门控
                        //   天然成立（搜索框改字不会让列表重建）。
                        SubjectBuilder::make($this->store)
                            ->select(static fn(UserStore $s): AsyncValue => $s->page()->value())
                            ->builder(fn(AsyncValue $v): Widget => $v->fold([
                                'loading' => static fn(AsyncValue $v): Widget => Spinner::make(),

                                'error' => static fn(AsyncValue $v): Widget => Row::make()->spacing(8)->children([
                                    Text::make('加载失败')->error(),
                                    Button::primary('重试')->command('users.refresh'),
                                ]),

                                'data' => fn(AsyncValue $v): Widget => SubjectBuilder::make($this->store)
                                    ->select(static fn(UserStore $s): array => $s->visible()) // ★ 切片：只跟过滤结果
                                    ->builder(static fn(array $rows): Widget => Each::make(
                                        static fn(User $u): int => $u->id, // ★ key 必填（R4）
                                    )->items($rows, static fn(User $u): Widget => UserRow::make($u))),

                                'idle' => static fn(AsyncValue $v): Widget => Text::make('尚未加载')->caption(),
                            ])),
                    ])
                )
            );
    }
}
