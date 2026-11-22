# 异步开发实战

> 本篇解决：把"别卡住界面线程"的活写成可替换、可取消、可观测的 `Job` + `TaskHost`，并用 `Future` / `AsyncScope` / `AsyncValue` 把结果安全地画出来。

异步代码最容易错的地方不是线程，而是边界：谁在后台跑、谁在 UI 线程结算、失败有没有人接、取消停到了哪一步。Drift 把这些边界收进几个固定的类，写应用时只在这些类之间接线。

## 三个角色：Job、TaskHost、Future

- `Job` 说"做什么"，是接口，**任务必须是类，不是闭包**。参数由构造器注入，`run(): mixed` 无参，可重放、可单测、可静态分析；闭包过不了跨线程边界。
- `TaskHost` 说"在哪跑、结果怎么回来"。换后端只换组合根一行，`Job` 不改。
- `Future` 说"将来会有值、失败、进度"，可被 `then()` / `catch()` / `all()` 组合。

`Job::run()` 里**不许碰 Widget / Element / State**，只处理业务数据；要改界面就在回调里改（那一段在 UI 线程的帧内执行）。`Future::resolve()` / `reject()` 也只在 UI 线程的帧内调，后端只能把结算入队。

## 定义任务

一个普通后台动作实现 `Job` 即可。需要跨进程或跨线程时实现 `NamedJob`（名字 + JSON 可表达的参数），需要墙钟上限时再实现 `TimedJob`。三者正交，业务类通常同时实现后两个。

```php
final class FetchUsersJob implements NamedJob, TimedJob
{
    use JobArgs;

    public const NAME = 'users.fetch';
    /** 顺序 == 构造器参数顺序 */
    private const FIELDS = ['query'];

    public function __construct(private readonly string $query) {}

    public function timeoutMs(): int { return 60_000; }

    public function run(): mixed
    {
        // 只碰业务数据；不 new Widget、不 setState()
        return (new ApiClient())->search($this->query);
    }
}
```

`JobArgs` 让 `args()` / `fromArgs()` 只依赖 `FIELDS` 一份名单。**键名与顺序必须等于构造器参数名与顺序**：`fromArgs()` 按位置填（`new self(...$values)`），名字对不上取到 `null`，顺序错把两个参数装反，两种都是静默错（两侧一起退化成默认值，界面看着正常，只有 worker 里跑错参数）。判据用反射比对，别手写 `args()`。参数只能是标量或标量数组：对象、resource、闭包过不去。

`TimedJob::timeoutMs()` 是允许的最长墙钟（毫秒，从派发给 worker 起算），写成"最坏耗时 + 余量"，不是性能预算。`0` 表示不声明、回落到 Host 兜底，不是"不限"。

## 选 TaskHost：三后端一行切换

组合根继承 `AppEntry`，用 `jobs()` 声明后台任务表（worker 角色据此干活）；`start()` 里 `AppScope::useBackgroundJobs($this->jobs())` 收掉取 exe → 建池 → 预热 → 装端口。业务侧用 `Application::submit($job)` 提交，不碰后端类型。

★ 三条边界，选错都不报错：
- `SyncTaskHost` 在 UI 线程同步跑完——慢任务直接卡帧，只适合毫秒级。
- `ProcessTaskHost` 是**跑 PHP 代码的唯一异步后端**：每个 worker 是一整套 AOT 运行时，所以池容量是组合根的决定。
- `ThreadTaskHost` 只跑 **C++ 侧注册的原生处理函数**（跨线程只传任务名 + 两个标量槽）。PHP 的 `NamedJob::run()` **不会**被执行——它不是"轻量进程池"，`submit()` 会当场 reject。

| 后端 | 在哪跑 | 取消能力 `CancelSupport` | 进度 | `needsPolling()` |
|---|---|---|---|---|
| `SyncTaskHost` | 调用者（UI）线程同步跑完，结果入队、下一帧回贴 | `AlreadyDone`（没有对象可取消） | 无 | `true`（有界的一次，跑完就不空转） |
| `ThreadTaskHost` | C++ 原生 IO 线程池，只跑 `NamedJob` 的两个标量槽 | `DetachedOnly`（只能"不交付"，仍会跑完） | 无 | `false`，靠 `PostMessageW` 叫醒 |
| `ProcessTaskHost` | 常驻子进程池，跑 AOT 代码，`NamedJob` + 注册表 | `Killable`（排队能摘、在途能杀 worker 真省 CPU） | 有（进度包走管道） | `true` |

`SyncTaskHost` 是默认后端，永远可用；同步耗时超过阈值只报一次诊断，提示用 `ProcessTaskHost` 换掉。它的诚实边界是"大响应会短暂停顿、`loading` 对用户不可见"，不要靠先广播一次 `loading` 来假装异步。

`JobRunner::exec(?TaskHost $host, Job $job)` 是显式选路点：`host` 非空就提交，为 `null` 就在当前栈跑（测试 / 解释器）。它与 `Application::submit()` 的 `null` 语义相反——后者返回 `Future::rejected()`，表示"本应用声明不用后台"。两条路返回同一种 `Future`，失败一律落成 `rejected`，调用点不必分支。

## Future：组合、失败与进度

应用侧从 `submit()` 拿到 `Future`。`then()` / `catch()` 返回新 Future，回调返回另一个 Future 会自动摊平，回调自己抛异常会走失败链。

**任务失败没人 `catch()` 会走 Fatal 序列**：失败被吞掉的表现是界面永远转圈且不报错。提交与挂回调写在同一同步块里就能满足。判"没人接"只看链末端，因此 `$f->then($ok)->catch($err)` 是**一条链**，不要写成 `$f->then($ok); $f->catch($err);`（后者派生出一条没人接的失败链）。

- `Future::resolved()` / `rejected()` 造的是**值**，不是任务；只有 `pending()` 造的（及从中链出的）才在"没人接"时喊。
- `Future::all($futures)`：全部成功才算成功，结果按**入参键序**返回；任一条失败则其余被取消；空数组立刻成功为 `[]`。
- `Future::allSettled()`：各自成败，元素含 `ok` / `value` / `error`（消息字符串）/ `code`，永不失败。
- `Future::race()`：先结算的赢，其余取消；空数组永不结算（会被帧时钟的 pending 记账暴露，而不是假装跑过）。
- 组合器会传播取消到入参，并继承"是任务"的标记，避免"组合"变成吞失败的暗渠。

## AsyncScope：把多步流水线写成顺序代码

两步、无循环、中间值不上界面，用 `then()` 更轻。有轮询、有重试、每步都要更新界面时，用 `AsyncScope`：底层是 PHP `Fiber`（用户态协程），`task()` 返回的就是一条普通 `Future`。

```php
$this->flow = $this->asyncCommand(fn (array $paths): Future => AsyncScope::task(
    function (AsyncScope $co) use ($paths): array {
        $sign = $co->await($this->host->submit(new SignJob()));
        $co->await($this->host->submit(new UploadJob($paths, $sign)));
        return ['ok' => true];
    },
    $this->delay,   // 需要 sleep() 才给：组合根把 Application::after() 递进来
));
```

要点：

- `await(Future $future, ?callable $onProgress = null)` 成功返回值、失败原样抛异常，于是"第三步失败回退重来"就是普通 `try/catch`。第二个参数是**进度入口**：订阅正确顺序由它保证，不必记 `onProgress()` 的坑。
- `sleep(int $ms): bool` 必须判返回值。`false` 表示这个作用域没有定时器（`task()` 没给第二参），这一次**没有睡**；在 `while` 里忽略它会退化成零延时重试，一轮一轮发请求且不报错。
- 取消是往协程投 `AsyncCancelled` 异常，所以 body 里的 `catch (\Throwable)` 会连它一起接住。业务侧写 `catch (AsyncCancelled $e) { throw $e; }` 放在前面；即使忘了，`await()` / `sleep()` 入口也会把取消重新抛出来。
- `src/Async` 不许依赖 `Drift\Platform`：定时器靠注入。`Environment::delay()` 是组合根递下来的能力（`AsyncScope::task()` 的第二参就是它），控件与命令不自己去拿平台。

## 命令本身是可观察值

`AsyncCommand` 实现 `Readable`：`value()` / `isRunning()` 在 `build()` 期间读就订阅它——和 `Cell::get()` 走同一条采集栈。

```php
// 页面直接读命令，不需要 Store 先转发一次广播
Bind::make(fn(): Widget => $this->list($this->store->page()->value()))
```

- 事件处理器里读**不**订阅（帧外没有采集帧）：`run()` 前"看一眼当前值"是正常需求。
- 变化频率 = 四态变化次数：发起时一次 `loading`、结算时一次 `data`/`error`，加上进度通知。
- 一个命令被多处读 ⇒ 多处各自重建；只该变一段就把那段包进自己的 `Bind`。
- 以前靠 `Store::notifyChanged()` 把命令的变化"转发"给页面，那是每个 Store 都要手写一遍、漏写就静默陈旧的活（2026-11-22 起不需要了）。

## AsyncValue 四态与 fold

`AsyncValue` 是异步状态的唯一形状：`idle` / `loading` / `data` / `error`，不可变（构造子返回新实例）。载荷与状态是两个维度：四态都可带载荷，用独立的 `hasValue` 布尔表达，因为载荷本身可以是 `null`。

画控件用 `AsyncView`：它把 `fold()` 四支包成控件，源收 `Readable`（通常是 `AsyncCommand`），读点即订阅。`data` 支必须给，另外三支有可用默认；默认不是空白，四态任何一态都得有内容。只把四态折成字符串 / 数字时直接调 `fold()`，四个分支签名仍要完全一致：`callable(AsyncValue): mixed`。

```php
AsyncView::of($command, fn (AsyncValue $v): Widget => $this->list($v))
    ->loading(fn (AsyncValue $v): Widget => $v->isRefreshing()
        ? $this->list($v)
        : Spinner::make())
    ->error(fn (AsyncValue $v): Widget => ErrorView::make($v->message(), $v->code()))
    ->widget();
```

`fold()` 的路由规则没有第二份实现：缺键当场抛，把"界面空白"停在开发期；手写 `if/else` 会漏态、会判断顺序颠倒。刷新中（`loading` 且带旧值）走 **`data`** 支——列表继续画；`error` 不这样路由，失败必须让用户看见，哪怕旧内容还在。

错误态要能重试时，用 `retryOn()` 给默认支装一个重试按钮，省掉每个页面重抄 `Center(Column(错误 + 按钮))`：

```php
AsyncView::of($command, $dataBuilder)
    ->retryOn($command->retry(...))
    ->widget();
```

`retryOn()` 是 0 参动作闭包，走 `Button::onPressed` 的约定；它只作用于默认 error 支，显式给了 `error()` 就以显式支为准（分页场景失败时要保留旧行，那个形状框架猜不到）。按钮不自带 enabled：error 支显示时命令已不在途（`loading` 与 `error` 互斥），按钮只在可点时出现。注意 `AsyncCommand::retry()` **有意不去重**——在途点它会打断重来，别把这个按钮当成其它场景的通用去重入口。

- `AsyncValue::loading()->carriedFrom($previous)` 让刷新保留上一次的值；`isRefreshing()` 是"细进度条 / 小转圈"的判据。
- `dataOr($fallback)` 把"没数据时用什么画"收在一处，避免拿 `mixed` 的 `value()` 直接 `foreach`。
- `hasProgress()` / `progressOr()` 与载荷同手法：进度是**第五个维度**，不是第五态，`fold()` 仍四个键。
- 策略分支看 `code()`，不要看 `message()`：文案会改，同类错误措辞可能不同。

## 进度：第二参、onProgress 与 JobProgress

进度只喂**根任务**，不沿 `then()` / `catch()` 派生链传递。把 `onProgress()` 挂在派生链上会留一条 `progress/derived-chain` 的 `[DX]` 诊断——过去它只表现为"进度条不动"。`AsyncScope::await($root, $onProgress)` 把挂错链的问题从根上消掉，但传进去的仍必须是没派生过的那条（例如 `submit()` 的返回值）。

只有 `ProcessTaskHost` 会喂进度；同步与线程后端 `hasProgress()` 恒 `false`，界面应画不定长转圈，不是错误。任务侧在 `Job::run()` 里调 `JobProgress::report($fraction)`：

- 它按 1% 最小粒度节流（进度条的可见精度就是这个量级），倒退与同值一律吞掉（`1.0` 永不被节流吞）。
- 没有 sink 时是空操作：同一个 `Job` 换到同步后端照常跑。
- `AsyncCommand` 在 `start()` 里订阅根任务的 `onProgress()`，把值 `withProgress()` 广播出去；对派生链它故意不挂，避免框架自己制造噪音。

## 超时、错误码与取消

`ProcessTaskHost` 的超限从**派发给 worker** 起算（排队不算）。超时判失败并杀掉那个 worker，池会自动补一个；否则 worker 永远占着坑、容量悄悄掉一格且没有报错。失败带 `Failure::CODE_TIMEOUT`。

`Failure` 是跨进程边界的结构化错误载体，`Failure::codeOf()` 是全链路唯一提取点。具名码：`CODE_TIMEOUT`、`CODE_WORKER_DIED`、`CODE_HOST_UNAVAILABLE` 都是瞬时故障、重试有意义；`CODE_JOB_REJECTED`（不是 `NamedJob`、参数转不了 JSON、注册表里没有）是**装配写错**，重试无用，界面该画"配置错误"而不是"重试"。空串 `''` 表示源异常没给分类，不是一种错误类型；用 `AsyncCommand::mapError()` 补应用语义。

`Future::cancel()` 是**尽力而为**：调后端钩子 + 标记"结果不要了"，此后迟到的结算被忽略；它不会让 Future 变成失败。取消沿派生链上溯到根，代价是树的剪枝——同一根被两处扇出，取消一处另一处也收不到结果。要复用就复用**结果值**，不要复用 Future。有运行时兜底：被取消节点若有多条派生链，会报 `future/fanout-cancel` 诊断，把静默剪枝变红。

取消深度由 `TaskHost::cancelSupport()` 声明，界面据此决定要不要提示"仍在后台运行"，四档按"能省多少活"递增：`AlreadyDone`（同步后端没有在跑的活）→ `DetachedOnly`（线程后端摘不回已派发的活，只能"不交付"）→ `QueuedOnly`（能撤排队中的）→ `Killable`（连在途任务也真停）。`killsRunning()` 问的就是"取消会不会让后台真的停手"。

进程后端是 `Killable`：`pickIdle()` 保证每个 worker 在途严格 ≤1，所以取消一条在途任务可以安全地杀掉独占它的 worker、下帧补池——不连坐别的活，也没有"取消后后台还在烧 CPU"。线程后端摘不回已派发的活，记账不能减（否则 `pending()` 掉 0、帧时钟睡死，结果永远躺在完成队列）。取消不是失败：不投 `Failure`、不触发 onError，`Future` 保持未结算，靠 `AsyncCommand::abandon()` 不再等它。这与 Electron `utilityProcess.kill()` / JavaFX `Future.cancel(true)` 是同一路子——终止执行单元比让任务自己轮询取消标志少一层协议。

## 组合根怎么把能力递给控件

控件与命令不直接拿平台或定时器，全部由组合根在装配时递进来：

- `AppScope::setTaskHost($host)` 定后台后端；`Application::submit()` 是应用侧唯一提交入口。
- `Application::after(int $ms, \Closure $cb)` 的回调在 `frame()` 内、结算前跑，里面的 `setState()` 同帧生效。把 `[$app, 'after']` 作为第二参传给 `AsyncScope::task()`，作用域就获得 `sleep()` 能力。
- `State::asyncCommand($task, $combine)` 在 `initState()` 里造命令：它把命令的广播接成 `markDirty()`，并在 `onDispose()` 里统一 `dispose()`。
- 图片等资源走 `ImageAssets::install()`，业务只用 `Image::make()->asset($src)`，见[异步资源](./async-assets.md)。

## UI 线程发 HTTP：AsyncHttp

`Http` 是阻塞的，只许在 `Job::run()`（worker 进程）里用。UI 线程要发请求用 `AsyncHttp`，拿到的仍是 `Future<HttpResponse>`，与 `submit()` 的产物同形：

```php
$http = $app->http();              // 或组合根 $scope->asyncHttp()（同一个实例）
$http->get('https://api.example/user')->then(
    fn (HttpResponse $r) => $this->name->set($r->json()['name']),
);
// 多步顺序逻辑仍走 AsyncScope::await()，见上
```

**分流在门面内部，应用侧只有一个 API**：

| 请求 | 走哪 | 为什么 |
|---|---|---|
| 无 multipart、body ≤ 64KB | 帧内 `curl_multi`（`CurlMultiTransport`） | 不占 worker、不用配池大小；每帧 `exec + select(0)` 实测 ~12µs |
| multipart 或 body > 64KB | 进程池 `HttpRequestJob` | 流式上传的 fd / 进度回调留在 worker，不顶 UI 帧 |

两条路共用 `Http::prepare()` / `Http::finish()` —— 校验、TLS 信任根、头解析、错误分类（`status === null` = 传输失败）只有一份。进度用 `Future::onProgress()`，与 Job 的进度同一通道。

**带进度的上传**（`onProgress` 只在 multipart 有意义，普通 body 没有中途观察点，传了当场抛）：

```php
$http->request('POST', $url, [
    'fields' => ['key' => $objectKey],
    'files' => ['file' => $localPath],
    'onProgress' => fn (float $f) => $this->status->set(\round($f * 100) . '%'),
])->then(fn (HttpResponse $r) => /* ... */);
```

门面把 `onProgress` 挂到 `JobRunner` 返回的**根** Future 上（worker 侧 `JobProgress::report()` 经 `Future` 转接），进度由此回贴 UI。★ 别把回调挂在 `then()` 之后的派生 Future 上——进度只喂根任务，派生链上的订阅永远收不到（见 `Future::onProgress()` 的 `progress/derived-chain` 诊断）。

**两条支路都有 showcase 真机消费者**：`DRIFT_SHOWCASE_HTTP=1` 时「异步表格」页取数走小 JSON（帧内 curl_multi）、「上传示例」按钮走 multipart（进程池 + 进度回贴），由 `probe-async.py` 在 AOT 产物上各验一遍。零消费者的分支会退化成潜伏假实现——P12 的 `curl_close()` 崩溃正是只有真消费者才暴露得出来。

**传输后端可换**：`AppScope::setHttpTransport()` 接受 `HttpTransport` 接口（`submit` / `pump` / `inFlight`）。当前只有 curl_multi 实现；**Swoole 后端刻意不实现**——`Co\Http\Client` 单用无 hook、帧循环协程化也跑通了，但把 reactor 关进 `Swoole\Thread` 在 AOT 产物里会重入主入口（线程要重新加载 `.php`，与"源码全编进 exe"冲突），见[裁决](../decisions/README.md)。没有 AOT 线程入口支持之前，不埋一个在真机上跑不通的后端。

## 常见静默坑

- `Future` 失败没挂 `catch()`：Fatal 序列；症状是永久转圈。
- `then()` / `catch()` 分两次挂在同一个 Future 上：派生出没人接的链。
- `Future::all()` 按完成序取结果：键序是契约，错序的 `$result[0]` 张冠李戴且不报错。
- 派生链上挂 `onProgress()`：进度条永远不动（现会留下 `[DX]` 诊断）。
- `AsyncScope::sleep()` 忽略返回值：零延时重试轰炸请求。
- body 用 `catch (\Throwable)` 吞掉 `AsyncCancelled`：取消在下一个让出点重来，多跑几条同步语句。
- 两条消费者共用一条 Future 再取消：另一条永久 loading 且不报错。
- `Job::run()` 碰 Widget / Element / State：跨线程改写 UI，问题只在真机出现。
- 自定义 Job 手写 `args()`：键名 / 顺序漂移，两侧一起退化成默认值。

## 相关

- [异步资源](./async-assets.md)
- [帧管线](../concepts/frame-pipeline.md)
- [诊断与错误码](../reference/diagnostics.md)
- [AOT 约束与代码纪律](../reference/aot-constraints.md)
