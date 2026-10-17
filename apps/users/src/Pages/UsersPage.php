<?php
declare(strict_types=1);

namespace App\Users\Pages;

use Drift\Geometry\EdgeInsets;
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

use App\Users\Store\LoadStatus;
use App\Users\Store\User;
use App\Users\Store\UserStore;
use App\Users\Widgets\UserRow;

/**
 * 列表页（§A.2.9）：搜索 + 三态列表 + 删除。
 *
 * ★ **订阅点在页这一层，行内只吃值**（§11.3.2 纪律 2）：`SubjectBuilder` 的
 *   `select` 是切片（只跟自己那一份数据），列表重排不会每行广播一次。
 * ★ 三态用 **`match` 无 default**（§12.1 第 2 条封闭域）—— 加一个状态编译期就叫。
 *
 * ★ **头部固定、列表可滚**：页面是 `Dock`（top = 标题 + 搜索行，center =
 *   可滚动列表）—— 而不是 `Column + Expanded`：`RenderFlex` 的 flex 分母含
 *   弹性子的**自然尺寸**，内容比视口长时弹性项拿 0（2026-10-17 观察，与
 *   "分剩余空间"的文档语义不符，待裁决）；`Dock` 的 center 槽拿**紧约束**
 *   高度 ⇒ 视口有界、列表真能滚。滚动位置是页面级纯 UI 态 ⇒ 放 `State`
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

                        Button::primary('刷新')->command('users.refresh'),

                        Spacer::make(),

                        Button::primary('新建用户')->command('nav.signup'),
                    ]),
                ])
            )
            ->center(
                ScrollView::make(
                    Column::make()->spacing(8)->padding(EdgeInsets::only(24.0, 0.0, 24.0, 24.0))->children([
                        // ── 列表：订阅点在这层，行内只吃值 ──
                        SubjectBuilder::make($this->store)
                            ->select(static fn(UserStore $s): LoadStatus => $s->status())
                            ->builder(fn(LoadStatus $st): Widget => match ($st) {
                                LoadStatus::Loading => Spinner::make(),
                                LoadStatus::Error => Row::make()->spacing(8)->children([
                                    Text::make('加载失败')->error(),
                                    Button::primary('重试')->command('users.refresh'),
                                ]),
                                LoadStatus::Ready => SubjectBuilder::make($this->store)
                                    ->select(static fn(UserStore $s): array => $s->visible()) // ★ 切片：只跟过滤结果
                                    ->builder(static fn(array $rows): Widget => Each::make(
                                        static fn(User $u): int => $u->id, // ★ key 必填（R4）
                                    )->items($rows, static fn(User $u): Widget => UserRow::make($u))),
                                LoadStatus::Idle => Text::make('尚未加载')->caption(),
                            }),
                    ])
                )
            );
    }
}
