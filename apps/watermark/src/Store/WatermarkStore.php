<?php
declare(strict_types=1);

namespace App\Watermark\Store;

use App\Watermark\Api\Config;
use App\Watermark\Api\SignInfo;
use App\Watermark\Api\TaskResult;
use App\Watermark\Jobs\AddTaskJob;
use App\Watermark\Jobs\DownloadResultsJob;
use App\Watermark\Jobs\FetchSignJob;
use App\Watermark\Jobs\PollResultJob;
use App\Watermark\Jobs\UploadBatchJob;
use Drift\App\Job;
use Drift\App\JobRunner;
use Drift\App\TaskHost;
use Drift\Async\AsyncCancelled;
use Drift\Async\AsyncScope;
use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\State\AsyncCommand;
use Drift\State\Subject;
use Drift\Widget\Environment;

/**
 * 全部业务状态（一张卡片一块，互不干扰）。
 *
 * ## 一条流水线，一个命令
 *
 * 五步（签名 → 上传 → 建任务 → 轮询 → 下载）写成**一段顺序代码**，由一个
 * `AsyncScope` 承载，外面套**一个** `AsyncCommand`：
 *
 * | 入口 | 用户动作 | 顺序代码 |
 * |---|---|---|
 * | `Flow::Prewarm` | 首屏（构造器） | 取签名，失败不说话 |
 * | `Flow::Upload` | 点「上传」 | 签名不够新才取 → 上传（进度上界面） |
 * | `Flow::Submit` | 点「提交任务」 | 建任务 → `pollLoop()` → 下载 |
 *
 * ★ **为什么不给每一步一个命令**（迁移前的形状）：那样「签名过期要先取再传」必须拆成
 *   「设标志 → 在别的命令的结算回调里读标志 → 调下一个命令」，于是有
 *   `uploadAfterSign`；「轮询」必须拆成「排定时器 → 回调里查 → 结算里再排」，
 *   于是有 `polling` + `scheduleNextPoll` + 回调里的二次守卫 + 5 个 `absorb*`。
 *   一条线性的事被拆成三份（状态机 / 回调 / 跨进程序列化），**全是命令边界逼出来的**。
 *
 * ★ 一个命令也带来一个界面上的正确性：**互斥由形状保证**。
 *   `Phase` 是唯一一个「在忙什么」，`canUpload()` / `canSubmit()` 都判它。
 *   迁移前五个 `isRunning()` 互不排斥，轮询中点「提交任务」能起第二条轮询。
 *
 * ★ 取消：`stopPolling()` 取消的是**整条流水线**（此刻剩下的只有"继续查"和
 *   "查完下载"，按了停止就是都不要了）。`AsyncScope` 会往协程里投
 *   `AsyncCancelled` ⇒ `runFlow()` 的 `finally` 归位阶段 —— 这是订阅形状做不到的：
 *   `AsyncCommand::cancel()` 不广播结算，想靠订阅缝清理就会漏。
 *
 * ## 轮询的节奏归谁
 *
 * ★ 本类**不持有** `Application`（Store 是纯状态，§4.3 的"Widget 不许依赖 App"
 *   同一条纪律往上延伸一层）。定时器能力通过构造器注入的 {@see Environment} 表达：
 *   ```php
 *   new WatermarkStore($host, $base, $app->environment());
 *   ```
 *   它同时是 `AsyncScope::task()` 的延时函数 ⇒ `sleep()` 用的就是它。
 * ★ `$env === null`（解释器 / 单测）⇒ `sleep()` 返回 `false` ⇒ **只查一次**，
 *   循环自己退出，不假装在轮询。测试里不能有"自己转起来的定时器"。
 *
 * ## 消息为什么分三份
 *
 * 原文就是三块独立的状态区（`uploadStatus` / `taskStatus` / 结果区）。合成一条
 * 全局消息的话，"上传失败"会把"任务已提交"覆盖掉，用户看不出是哪一步挂了。
 */
final class WatermarkStore extends Subject
{
    /** @var list<SelectedImage> */
    private array $selected = [];

    /** 唯一的流水线命令（参数是 `Flow` 入口） */
    private AsyncCommand $flow;

    /** 现在走到哪一步 —— 界面的「在忙什么」只有这一个来源 */
    private Phase $phase = Phase::Idle;

    // ── 签名 ────────────────────────────────────────────────────────────
    private ?SignInfo $sign = null;

    // ── 上传 ────────────────────────────────────────────────────────────
    /** @var list<string> 已上传的对象键（顺序 = 选中顺序） */
    private array $uploadedKeys = [];

    private StatusMessage $uploadMessage;

    /** 上传进度（`0..1`）—— 由 `UploadBatchJob` 的进度通知直接写，不另存命令的值 */
    private float $uploadFraction = 0.0;

    // ── 表单 ────────────────────────────────────────────────────────────
    private string $time = '';

    private string $address = '';

    private string $imageSize = '2K';

    // ── 任务 ────────────────────────────────────────────────────────────
    private string $taskId = '';

    private StatusMessage $taskMessage;

    // ── 结果 ────────────────────────────────────────────────────────────
    private ?TaskResult $lastResult = null;

    /** 底部状态栏的一句话（整个应用的"现在在干什么"） */
    private StatusMessage $statusLine;

    /**
     * @param null|TaskHost $host 非 null ⇒ 全部走后台（§17.4.5）；null ⇒ 同步（测试）
     * @param null|Environment $env 窗口能力门面；null ⇒ 不自动轮询
     */
    public function __construct(
        private readonly ?TaskHost $host = null,
        private readonly string $apiBase = Config::API_BASE,
        private readonly ?Environment $env = null,
    ) {
        $this->uploadMessage = StatusMessage::none();
        $this->taskMessage = StatusMessage::none();
        $this->statusLine = StatusMessage::info('准备就绪');

        // ★ 时间默认"现在"（原文 `DOMContentLoaded` 里 `formatDateTime(new Date())`）。
        //   用户几乎总是要传"此刻"的水印，让他每次手打一遍没有意义。
        $this->time = \date('Y-m-d H:i:s');

        // 门面的 `delay()` 与 `AsyncScope::task()` 要的 `callable` 差一个返回值，
        // 这里转接一次；不转就得让门面长出一个只为本类服务的 `__invoke`。
        $scheduler = $env === null
            ? null
            : static fn (int $ms, \Closure $callback): int => $env->delay($ms, $callback);

        // ★ 闭包声明一个参数（`AsyncCommand::run()` 永远传一个）：混合签名在解释器
        //   下写错不报、只在 AOT 崩 —— 本仓已经炸过一次。
        $this->flow = AsyncCommand::make(function (mixed $entry) use ($scheduler): Future {
            return AsyncScope::task(
                function (AsyncScope $co) use ($entry): void {
                    $this->runFlow($entry, $co);
                },
                // ★ 延时函数就是门面的 `delay`：`sleep()` 与"排定时器"从此是同一件事，
                //   应用手里再没有第二个能排定时器的地方。
                $scheduler,
            );
        });
    }

    // ══════════════════ 读（界面订阅这些切片） ══════════════════

    /** @return list<SelectedImage> */
    public function selected(): array
    {
        return $this->selected;
    }

    public function selectedCount(): int
    {
        return \count($this->selected);
    }

    public function canUpload(): bool
    {
        return $this->selected !== [] && $this->phase === Phase::Idle;
    }

    /** 上传进度（`0..1`）—— 只在 `Phase::Uploading` 时有意义 */
    public function uploadProgress(): float
    {
        return $this->uploadFraction;
    }

    public function isUploading(): bool
    {
        return $this->phase === Phase::Uploading;
    }

    public function uploadMessage(): StatusMessage
    {
        return $this->uploadMessage;
    }

    /** @return list<string> */
    public function uploadedKeys(): array
    {
        return $this->uploadedKeys;
    }

    public function time(): string
    {
        return $this->time;
    }

    public function address(): string
    {
        return $this->address;
    }

    public function imageSize(): string
    {
        return $this->imageSize;
    }

    public function taskMessage(): StatusMessage
    {
        return $this->taskMessage;
    }

    public function taskId(): string
    {
        return $this->taskId;
    }

    public function isSubmitting(): bool
    {
        return $this->phase === Phase::Submitting;
    }

    /**
     * 流水线在跑（任何一步）。
     *
     * ★ 与 `canUpload()` / `canSubmit()` 分工：**那两个回答"能不能点这个动作"**，
     *   本方法回答"现在是不是在忙"。按钮的 `enabled` 必须用前者 —— 用本方法会
     *   在"没选图"时也把按钮点亮（症状是点了才被告知缺什么）。
     */
    public function isBusy(): bool
    {
        return $this->phase !== Phase::Idle;
    }

    /**
     * 此刻按「停止」有意义吗。
     *
     * ★ 只覆盖提交之后的两档（`Submitting` / `Polling`）。结果图下载已交给
     *   `Image` 控件自治，没有"整批停止"这回事；`Signing` / `Uploading` 不提供
     *   停止：用户刚点完上传，意图明确，打断半截上传只会留个半截对象。
     */
    public function canStop(): bool
    {
        return $this->phase === Phase::Submitting
            || $this->phase === Phase::Polling;
    }

    /** 停止按钮的文案：停的是什么就说什么，别让用户猜 */
    public function stopLabel(): string
    {
        return $this->phase === Phase::Polling ? '停止轮询' : '取消提交';
    }

    /**
     * 「为什么还不能提交」—— `''` ⇒ 能提交。
     *
     * ★ 按钮灰着的时候旁边必须有一句话，否则用户只会觉得按钮坏了。更重要的是
     *   **这份判据与 `canSubmit()` 是同一份**：以前这里是两套（按钮读
     *   `isSubmitting()`、守卫读 `canSubmit()`），于是"上传中按钮还是亮的，点了
     *   才被告知还有一步没做完"。
     */
    public function submitBlocker(): string
    {
        if ($this->uploadedKeys === []) {
            return '请先上传图片';
        }
        if ($this->time === '' || $this->address === '') {
            return '请填写时间和地址';
        }
        if ($this->phase !== Phase::Idle) {
            return '还有一步没做完，请稍候';
        }

        return '';
    }

    /** 能提交任务 —— 与 `submitBlocker()` 同一份判据（按钮亮不亮、点不点得动不会分叉） */
    public function canSubmit(): bool
    {
        return $this->submitBlocker() === '';
    }

    public function isPolling(): bool
    {
        return $this->phase === Phase::Polling;
    }

    public function lastResult(): ?TaskResult
    {
        return $this->lastResult;
    }

    public function statusLine(): StatusMessage
    {
        return $this->statusLine;
    }

    // ══════════════════ 写（用户动作） ══════════════════

    /**
     * 加一批选中的文件（**去重**：同一个绝对路径只留一份）。
     *
     * ★ 去重是**业务**决定：原文的 `handleFiles()` 不去重（用户拖两次同一个文件会
     *   出现两张一模一样的缩略图，然后传两遍、建一个带两张同图的任务）。这里去掉，
     *   因为它对用户没有一处是好的。
     *
     * @param list<string> $paths
     *
     * @return int 真正加进去的数量（去重 / 非文件之后剩下的）
     */
    public function addFiles(array $paths): int
    {
        // ★ 忙的时候**不加**：整批上传的 `$items` 在发起那一刻就定下来了，中途追加
        //   的图不会被传；而这里若照旧清空 `uploadedKeys`，随后的上传又会把它覆盖成
        //   "只含旧批次" ⇒ 界面显示 3 张、任务里只有 1 张，且**没有任何报错**。
        //   拒绝 + 说清原因，比"看起来加进去了"诚实。
        if ($this->isBusy()) {
            $this->uploadMessage = StatusMessage::error('正在处理中，完成后才能添加图片');
            $this->notifyChanged();

            return 0;
        }

        $existing = [];
        foreach ($this->selected as $one) {
            $existing[$one->path] = true;
        }

        $added = 0;
        foreach ($paths as $path) {
            $image = SelectedImage::fromPath($path);
            if ($image === null) {
                continue;
            }
            if (isset($existing[$image->path])) {
                continue;
            }
            $existing[$image->path] = true;
            $this->selected[] = $image;
            $added++;
        }

        if ($added > 0) {
            // ★ 换了选择 ⇒ 旧的"已上传"列表作废：那些键对应的是上一批文件，
            //   留着会让用户以为"新选的也传好了"。
            $this->uploadedKeys = [];
            $this->uploadMessage = StatusMessage::none();
            $this->notifyChanged();
        }

        return $added;
    }

    /** 移除第 `$index` 个（越界 ⇒ 无事发生） */
    public function removeAt(int $index): void
    {
        if (!isset($this->selected[$index])) {
            return;
        }

        $rest = [];
        foreach ($this->selected as $i => $one) {
            if ($i !== $index) {
                $rest[] = $one;
            }
        }
        $this->selected = $rest;
        $this->uploadedKeys = [];
        $this->uploadMessage = StatusMessage::none();
        $this->notifyChanged();
    }

    public function clearSelection(): void
    {
        if ($this->selected === [] && $this->uploadedKeys === []) {
            return;
        }

        $this->selected = [];
        $this->uploadedKeys = [];
        $this->uploadMessage = StatusMessage::none();
        $this->notifyChanged();
    }

    public function setTime(string $time): void
    {
        if ($time === $this->time) {
            return;
        }
        $this->time = $time;
        $this->notifyChanged();
    }

    public function setAddress(string $address): void
    {
        if ($address === $this->address) {
            return;
        }
        $this->address = $address;
        $this->notifyChanged();
    }

    public function setImageSize(string $size): void
    {
        if ($size === $this->imageSize) {
            return;
        }
        $this->imageSize = $size;
        $this->notifyChanged();
    }

    /**
     * 首屏预热：取一次签名（失败**不打扰用户** —— 上传时会再取一次）。
     *
     * ★ 原文就是这样（`DOMContentLoaded` 里 `fetchSign()`，失败只在
     *   `uploadStatus` 里写一行）。预热的意义是把那一次 RTT 挪到用户还在看界面的
     *   时候。
     * ★ 有别的东西在跑时直接跳过：`run()` 换参数会**作废并 cancel** 上一条，
     *   预热不该打断真正的业务。
     */
    public function prewarm(): void
    {
        if ($this->phase !== Phase::Idle) {
            return;
        }
        $this->flow->run(Flow::Prewarm);
    }

    /** 点「上传」 */
    public function upload(): void
    {
        if (!$this->canUpload()) {
            return;
        }
        $this->flow->run(Flow::Upload);
    }

    /** 点「提交任务」 */
    public function submitTask(): void
    {
        // ★ 原因只有一份（`submitBlocker()`）：界面拿它画灰按钮旁那行字，
        //   这里拿它兜住"按钮之外还有快捷键 / 别的入口"那条路。
        $blocker = $this->submitBlocker();
        if ($blocker !== '') {
            $this->taskMessage = StatusMessage::error($blocker);
            $this->notifyChanged();

            return;
        }

        $this->flow->run(Flow::Submit);
    }

    /**
     * 用户点「停止」（轮询中 / 下载中 / 提交中）。
     *
     * ★ 名字不叫 `stopPolling()`：它取消的是**整条流水线**（`AsyncScope` 一取消，
     *   剩下的"继续查"和"查完下载"都不要了）。原来的名字只覆盖 `Phase::Polling`，
     *   于是下载阶段按不到停止 —— 而下载恰恰是最可能让人想停的一步。
     */
    public function stop(): void
    {
        if (!$this->canStop()) {
            return;
        }

        $this->flow->cancel();
        $this->notifyChanged();
    }

    // ══════════════════ 内部：流水线本体（顺序代码） ══════════════════

    /**
     * 三个入口共用的外壳。
     *
     * ★ `finally` 是**唯一的**收摊点：成功 / 失败 / 取消三条路都经过它。
     *   订阅形状做不到这一点 —— `AsyncCommand::cancel()` 不广播结算，靠订阅缝清理
     *   一定会漏（这就是迁移前 `polling` 会卡在 true 的原因）。
     */
    private function runFlow(mixed $entry, AsyncScope $co): void
    {
        try {
            if ($entry === Flow::Prewarm) {
                $this->sign = $this->fetchSign($co, false);
            } elseif ($entry === Flow::Upload) {
                $this->runUploadFlow($co);
            } elseif ($entry === Flow::Submit) {
                $this->runSubmitFlow($co);
            }
        } finally {
            $this->phase = Phase::Idle;
            $this->uploadFraction = 0.0;
            $this->notifyChanged();
        }
    }

    /**
     * 取一份签名。
     *
     * @param bool $report 失败要不要写到 `uploadMessage`、要不要占用 `Phase::Signing`
     *                     （预热两样都不要：它是后台的，用户不该被它挡住任何按钮）
     */
    private function fetchSign(AsyncScope $co, bool $report): ?SignInfo
    {
        // ★ `phase` 只在**用户看得见的那次**取签名里占位。预热不占 —— 占了的话
        //   首屏那一两秒里 `isBusy()` 为 true，`canUpload()` 为 false，用户看着
        //   有图却点不动「上传」；而预热本来就该是"什么都不挡"的。
        if ($report) {
            $this->phase = Phase::Signing;
            $this->uploadMessage = StatusMessage::loading('正在获取上传凭证…');
            $this->notifyChanged();
        }

        try {
            $raw = $co->await($this->exec(new FetchSignJob($this->apiBase)));
        } catch (AsyncCancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            // ★ 预热失败**不打扰用户**：上传时会再取一次。只有"上传前那次"失败才要报。
            if ($report) {
                $this->uploadMessage = StatusMessage::error('获取上传凭证失败：' . $e->getMessage());
                $this->notifyChanged();
            }

            return null;
        }

        $sign = SignInfo::fromArray($raw);
        if ($sign === null && $report) {
            $this->uploadMessage = StatusMessage::error('上传凭证格式不对（缺字段）');
            $this->notifyChanged();
        }

        return $sign;
    }

    /**
     * 「上传」入口。
     *
     * ★ 签名可复用（多个文件共用、有效期比一次上传长），所以**够新就不重取** ——
     *   签名接口在网关上是限流的。
     * ★ 迁移前这一段是 `uploadAfterSign` 标志 + `relayAfterSign()` 接力：两个方法、
     *   一个跨命令的标志，只为了表达"取完了接着传"。现在是一个 `if`。
     */
    private function runUploadFlow(AsyncScope $co): void
    {
        $sign = $this->sign;
        if ($sign === null || $sign->isExpired(self::nowMs())) {
            $this->sign = null;
            // ★ 原文的判据是 `Date.now() > +expireTime`，这里更保守（留 60s 余量），
            //   理由见 `SignInfo::isExpired()`。
            $sign = $this->fetchSign($co, true);
        }

        // ★ 拿不到凭证就到此为止：消息已由 `fetchSign()` 写好，再往下走只会拿
        //   一张过期签名拼出一批坏对象键。
        if ($sign === null) {
            return;
        }

        $this->uploadFiles($co, $sign);
    }

    private function uploadFiles(AsyncScope $co, SignInfo $sign): void
    {
        $now = self::nowMs();

        $items = [];
        foreach ($this->selected as $image) {
            // ★ 扩展名空 ⇒ 退回 `jpg`（原文 `file.name.split('.').pop() || 'jpg'`）。
            //   不带扩展名的对象键 OSS 能存，但取回来时没有 `Content-Type`。
            $ext = $image->extension === '' ? 'jpg' : $image->extension;
            $items[] = [
                'path' => $image->path,
                'key' => $sign->objectKey($ext, $now, self::randomToken()),
                'name' => $image->name,
            ];
        }

        $this->phase = Phase::Uploading;
        $this->uploadedKeys = [];
        $this->uploadFraction = 0.0;
        $this->uploadMessage = StatusMessage::loading('正在上传 0 / ' . (string) \count($items) . '…');
        $this->statusLine = StatusMessage::info('正在上传 ' . (string) \count($items) . ' 个文件…');
        $this->notifyChanged();

        $future = $this->exec(new UploadBatchJob($sign->toArray(), $items, $this->apiBase));

        try {
            // ★ 进度交给 `await()` 挂（它保证排在结算回调之前），这里不再手工接线。
            $raw = $co->await($future, function (float $fraction): void {
                $this->uploadFraction = $fraction;
                // ★ 进度条与文案一起动：只动进度条的话，用户在 0% 时看不出"在干什么"
                $this->uploadMessage = StatusMessage::loading(
                    '正在上传… ' . (string) (int) \round($fraction * 100.0) . '%'
                );
                $this->notifyChanged();
            });
        } catch (AsyncCancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->uploadedKeys = [];
            $this->uploadMessage = StatusMessage::error($e->getMessage());
            $this->statusLine = StatusMessage::error('上传失败');
            $this->notifyChanged();

            return;
        }

        $this->uploadedKeys = self::stringList(\is_array($raw) ? ($raw['keys'] ?? []) : []);
        $this->uploadMessage = StatusMessage::success(
            '全部 ' . (string) \count($this->uploadedKeys) . ' 个文件上传成功'
        );
        $this->statusLine = StatusMessage::success('上传完成，可以提交任务了');
        $this->notifyChanged();
    }

    private function runSubmitFlow(AsyncScope $co): void
    {
        $this->phase = Phase::Submitting;
        $this->taskMessage = StatusMessage::loading('正在提交任务…');
        $this->notifyChanged();

        try {
            $raw = $co->await($this->exec(new AddTaskJob(
                $this->uploadedKeys,
                $this->time,
                $this->address,
                $this->imageSize,
                $this->apiBase,
            )));
        } catch (AsyncCancelled $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->taskMessage = StatusMessage::error($e->getMessage());
            $this->statusLine = StatusMessage::error('提交任务失败');
            $this->notifyChanged();

            return;
        }

        $taskId = \is_array($raw) ? ($raw['taskId'] ?? '') : '';
        $this->taskId = \is_string($taskId) ? $taskId : '';

        if ($this->taskId === '') {
            $this->taskMessage = StatusMessage::error('建任务成功但没拿到任务 id');
            $this->notifyChanged();

            return;
        }

        $this->lastResult = null;
        $this->taskMessage = StatusMessage::success('任务已提交（ID ' . $this->taskId . '），正在查询结果…');
        $this->statusLine = StatusMessage::info('等待处理结果…');
        $this->notifyChanged();

        $this->pollLoop($co, $this->taskId);
    }

    /**
     * 查到终结为止。
     *
     * ★ **「每轮只排一个定时器」在这段形状里结构上不可能违反**：定时器由
     *   `AsyncScope::sleep()` 在内部生成，本方法手里没有任何"再排一个"的地方。
     *   迁移前它是 `polling` + `scheduleNextPoll()` + 回调二次守卫 + `scheduleAfterPoll()`
     *   四件套，曾经咬过两次（每轮排两个 ⇒ 定时器按 2ⁿ 增长）。
     */
    private function pollLoop(AsyncScope $co, string $taskId): void
    {
        $this->phase = Phase::Polling;
        $this->notifyChanged();

        $rounds = 0;
        $failures = 0;

        while (true) {
            $rounds++;
            $result = $this->fetchResult($co, $taskId);

            if ($result === null) {
                $failures++;
            } else {
                $failures = 0;
                $this->lastResult = $result;
                $this->notifyChanged();

                if ($result->isTerminal()) {
                    $this->statusLine = $result->isSuccess()
                        ? StatusMessage::success('处理完成')
                        : StatusMessage::error($result->failureReason());
                    $this->notifyChanged();

                    // 结果图的下载交给 Image 控件自治（见 ResultCard），流水线到此收工。
                    return;
                }
            }

            // ★ 两个退出上界收在同一处：`fetchResult()` 只负责「查一次失败不该停」，
            //   不知道自己被轮了几遍；散开写就会出现「失败永远继续」的永动循环。
            //   轮数按查过几次算，失败按连续几次算（成功一次即归零）。
            if ($failures >= Config::POLL_MAX_FAILURES || $rounds >= Config::POLL_MAX_ROUNDS) {
                $this->statusLine = StatusMessage::error(
                    $failures >= Config::POLL_MAX_FAILURES
                        ? '查询结果连续失败，已停止自动重试'
                        : '查询超过最大轮数，已停止自动轮询'
                );
                $this->notifyChanged();

                return;
            }

            if (!$co->sleep(Config::POLL_INTERVAL_MS)) {
                // ★ 本环境没有定时器 ⇒ 不假装在轮询：只查这一次。
                //   （迁移前 `isPolling()` 在同样的情况下返回 true —— 那是假的：
                //   那一拍根本没人排。）
                return;
            }
        }
    }

    /** 查一次。失败返回 `null` 并只更新状态栏 —— **不终止轮询**。 */
    private function fetchResult(AsyncScope $co, string $taskId): ?TaskResult
    {
        try {
            $raw = $co->await($this->exec(new PollResultJob($taskId, $this->apiBase)));
        } catch (AsyncCancelled $e) {
            // ★ 必须是**第一个** catch：下面那个 `catch (\Throwable)` 是为了
            //   "查一次失败不该停轮询"，它会把用户点「停止」投进来的取消一起接住 ——
            //   接住之后循环会继续，又排一个定时器，用户看到的是"点了停止还在查"。
            //   本方法在 `while` 里，是唯一一处"吞异常 ⇒ 多跑一轮"的地方。
            throw $e;
        } catch (\Throwable $e) {
            // ★ 网关抖一下不该让整轮白跑。原文也只 `console.error`，然后等下一个
            //   `setInterval`。⇒ 这里只更新底部状态，循环继续。
            $this->statusLine = StatusMessage::error('查询结果失败（会继续重试）：' . $e->getMessage());
            $this->notifyChanged();

            return null;
        }

        return TaskResult::fromArray($raw, $taskId);
    }

    /**
     * 唯一的选路点 —— 五个动作都走这里，不再各写一遍
     * `if ($host === null) { …同步副本… } else { submit }`。
     *
     * ★ `null` 永远表示"在当前栈上同步跑"（解释器 / 单测），**不表示"拒绝"** ——
     *   后者是 `Application::submit()` 的语义，两者混在一起会让症状变模糊。
     */
    private function exec(Job $job): Future
    {
        return JobRunner::exec($this->host, $job);
    }

    // ══════════════════ 小工具 ══════════════════

    /**
     * 当前**墙钟**毫秒。
     *
     * ★ 用 `time()`（秒）× 1000 而不是 `hrtime`：`hrtime` 是单调时钟、起点任意，
     *   而这里要跟服务端给的 `expireTime`（真实时间戳）比。
     */
    private static function nowMs(): int
    {
        return \time() * 1000;
    }

    /**
     * 对象键里那一段随机串（原文 `Math.random().toString(36).substr(2, 9)`）。
     *
     * ★ 换成 `bin2hex(random_bytes(5))`（10 个 hex 字符）：原文那一段只有 9 个
     *   36 进制字符（约 46 位），碰撞概率在"同一毫秒内传两个同名文件"时不为零；
     *   这里 40 位、且是密码学随机。`Http` 生成 boundary 用的也是它。
     */
    private static function randomToken(): string
    {
        return \bin2hex(\random_bytes(5));
    }

    /**
     * 只留非空字符串（跨进程回来的数组不可信：AOT 下类型漂了不会报错，只会画错）。
     *
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? $raw : [] as $one) {
            if (\is_string($one) && $one !== '') {
                $out[] = $one;
            }
        }

        return $out;
    }
}
