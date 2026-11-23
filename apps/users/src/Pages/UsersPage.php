<?php
declare(strict_types=1);

namespace App\Users\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Async\AsyncValue;
use Drift\Widget\AsyncView;
use Drift\Widget\Bind;
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
 * 列表页（§A.2.9）：搜索 + 四态列表 + 删除。
 *
 * ★ **订阅粒度落在"段"上**：搜索框、刷新行、列表各包一层 `Bind`，builder 里
 *   读了什么就订什么（搜索词格 / 命令 / 行派生值）。列表重排不会每行广播一次，
 *   改搜索词也不会让刷新按钮重建 —— 以前靠 `SubjectBuilder` 的 `select` 手写
 *   切片做到同一件事，现在依赖由采集给出，**漏写切片**这个失效模式不存在。
 * ★ 四态交给 `AsyncView`：框架提供 idle/loading/error 默认内容，页面只写 data 与
 *   真正要定制的分支；`fold()` 的穷尽性仍由框架守着。
 * ★★ **2026-10-31 的真机 bug 必须留在注释里**：本页是 `StatelessWidget`
 *   （`build()` 只在这棵子树被造出来时跑一次），原先那句
 *   `Button::primary('刷新')->enabled(!$this->store->page()->isRunning())` 取的是
 *   **浇筑那一刻**的值；首屏正在加载 ⇒ 按钮**永久灰掉**（数据到了也不恢复）。
 *   ⇒ 页面里**任何**读会变的值都必须在 `Bind`（或别的 State）之内，否则它就是快照。
 *
 * ★ **头部固定、列表可滚**：页面是 `Dock`（top = 标题 + 搜索行，center =
 *   可滚动列表）。滚动位置是页面级纯 UI 态 ⇒ 放 `State`（§12.3 判据：承载它的
 *   Element 不会被回收 —— 本容器不做虚拟化）。
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
        $store = $this->store;

        return Dock::make()
            ->top(
                Column::make()->spacing(16)->padding(EdgeInsets::all(24.0))->children([
                    Text::make('用户目录')->title(),

                    Row::make()->spacing(8)->children([
                        // ── 搜索框：读 `query` 格 ⇒ 只这一段跟着输入重建 ──
                        Bind::make(static fn (BuildContext $ctx): Widget => TextField::make()
                            ->value($store->query())
                            ->label('搜索')
                            ->placeholder('输入姓名')
                            ->onChanged(static fn(string $x) => $store->setQuery($x))),

                        // ★★ 「刷新」的可用态必须在订阅里读（2026-10-31 真机抓到的 bug）。
                        //   读的是命令：`isRunning()` 变化总是伴随一次 `emit()` ⇒
                        //   版本一定跟着动，不会漏帧。
                        Bind::make(static function (BuildContext $ctx) use ($store): Widget {
                            $running = $store->page()->isRunning();

                            $items = [
                                Button::primary('刷新')->enabled(!$running)->command('users.refresh'),
                            ];

                            // ★ 刷新时**列表不消失**（旧值还在，见 `AsyncValue` 的
                            //   两维模型）⇒ "正在刷新"必须有人说一句，否则看起来
                            //   像"点了没反应"。
                            if ($running) {
                                $items[] = Spinner::make()->diameter(12.0);
                                $items[] = Text::make('刷新中…')->caption();
                            }

                            return Row::make()->spacing(8)->children($items);
                        }),

                        Spacer::make(),

                        Button::primary('新建用户')->command('nav.signup'),
                    ]),
                ])
            )
            ->center(
                ScrollView::make(
                    Column::make()->spacing(8)->padding(EdgeInsets::only(24.0, 0.0, 24.0, 24.0))->children([
                        // ── 四态：源是可读命令，读点落在 AsyncView 内部 ──
                        AsyncView::of(
                            $store->page(),
                            // 行只在这一段里读：搜索词变 ⇒ 只重画列表，搜索框不动。
                            static fn(AsyncValue $v): Widget => Bind::make(
                                static fn (BuildContext $ctx): Widget => Each::make(
                                    static fn(User $u): int => $u->id, // ★ key 必填（R4）
                                )->items(
                                    $store->visible(),
                                    static fn(User $u): Widget => UserRow::make($u),
                                ),
                            ),
                        )
                            ->loading(static fn(AsyncValue $v): Widget => Spinner::make())
                            ->error(static fn(AsyncValue $v): Widget => Row::make()->spacing(8)->children([
                                Text::make('加载失败')->error(),
                                Button::primary('重试')->command('users.refresh'),
                            ]))
                            ->widget(),
                    ])
                )
            );
    }
}
