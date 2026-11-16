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

组合根用 `AppScope::setTaskHost()` 装配；业务侧用 `Application::submit($job)` 提交，不碰后端类型。

| 后端 | 在哪跑 | 取消能力 `CancelSupport` | 进度 | `needsPolling()` |
|---|---|---|---|---|
| `SyncTaskHost` | 调用者（UI）线程同步跑完，结果入队、下一帧回贴 | `AlreadyDone`（没有对象可取消） | 无 | `true`（有界的一次，跑完就不空转） |
| `ThreadTaskHost` | C++ 原生 IO 线程池，只跑 `NamedJob` 的两个标量槽 | `DetachedOnly`（只能"不交付"，仍会跑完） | 无 | `false`，靠 `PostMessageW` 叫醒 |
| `ProcessTaskHost` | 常驻子进程池，跑 AOT 代码，`NamedJob` + 注册表 | `QueuedOnly`（排队中的能真摘掉） | 有（进度包走管道） | `true` |

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
- `src/Async` 不许依赖 `Drift\Platform`：定时器靠注入。`Application::after()` 是组合根递下来的能力，控件与命令只收闭包，不自己去拿平台。

## AsyncValue 四态与 fold

`AsyncValue` 是异步状态的唯一形状：`idle` / `loading` / `data` / `error`，不可变（构造子返回新实例）。载荷与状态是两个维度：四态都可带载荷，用独立的 `hasValue` 布尔表达，因为载荷本身可以是 `null`。

画界面一律用 `fold()` 一次写完四支，四个分支签名完全一致：`callable(AsyncValue): mixed`。缺键当场抛，把"界面空白"停在开发期；手写 `if/else` 会漏态、会判断顺序颠倒。刷新中（`loading` 且带旧值）走 **`data`** 支——列表继续画；`error` 不这样路由，失败必须让用户看见，哪怕旧内容还在。

```php
$command->value()->fold([
    'idle'    => fn (AsyncValue $v) => Text::make('还没开始'),
    'loading' => fn (AsyncValue $v) => $v->isRefreshing() ? $this->list($v) : Spinner::make(),
    'data'    => fn (AsyncValue $v) => $this->list($v),
    'error'   => fn (AsyncValue $v) => ErrorView::make($v->message(), $v->code()),
]);
```

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

取消深度由 `TaskHost::cancelSupport()` 声明，界面据此决定要不要提示"仍在后台运行"：线程后端摘不回已派发的活，记账不能减（否则 `pending()` 掉 0、帧时钟睡死，结果永远躺在完成队列）；进程后端只摘排队中的，已派发的同样"不交付"。

## 组合根怎么把能力递给控件

控件与命令不直接拿平台或定时器，全部由组合根在装配时递进来：

- `AppScope::setTaskHost($host)` 定后台后端；`Application::submit()` 是应用侧唯一提交入口。
- `Application::after(int $ms, \Closure $cb)` 的回调在 `frame()` 内、结算前跑，里面的 `setState()` 同帧生效。把 `[$app, 'after']` 作为第二参传给 `AsyncScope::task()`，作用域就获得 `sleep()` 能力。
- `State::asyncCommand($task, $combine)` 在 `initState()` 里造命令：它把命令的广播接成 `markDirty()`，并在 `onDispose()` 里统一 `dispose()`。
- 图片等资源走 `ImageAssets::install()`，业务只用 `Image::make()->asset($src)`，见[异步资源](./async-assets.md)。

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
