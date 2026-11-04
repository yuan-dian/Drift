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
use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\State\AsyncCommand;
use Drift\State\Subject;

/**
 * 全部业务状态（一张卡片一块，互不干扰）。
 *
 * ## 三条异步链，三个命令
 *
 * | 命令 | 参数 | 谁触发 | 载荷 |
 * |---|---|---|---|
 * | `sign` | `null` | 首屏 / 上传前发现过期 | `array`（`SignInfo::toArray()`） |
 * | `upload` | `null` | 点"上传" | `array{keys: list<string>}` |
 * | `task` | `null` | 点"提交任务" | `array{taskId: string}` |
 * | `poll` | `string`（taskId） | 定时器 | `array`（`TaskResult::toArray()`） |
 *
 * ★ **一个命令一件事**，不把"取签名 + 上传"合成一个：签名是可复用的（多个文件
 *   共用一份、有效期比一次上传长），合成之后每批都要重签一次，而签名接口在网关
 *   上是限流的。
 * ★ 四条链共用一套 `AsyncCommand`（四态 / 去重 / 取消 / 旧请求作废），**不手写
 *   第二套状态机** —— `UserStore` 的类注释里有这条的完整理由。
 *
 * ## 轮询的节奏归谁
 *
 * ★ 本类**不持有** `Application`（Store 是纯状态，§4.3 的"Widget 不许依赖 App"
 *   同一条纪律往上延伸一层）。节奏通过构造器注入的 `$scheduler` 表达：
 *   ```php
 *   new WatermarkStore($host, $base, fn (int $ms, \Closure $cb): int => $this->after($ms, $cb));
 *   ```
 *   与 `UsersRoot::menuOpener()` 是同一个"应用把平台能力包成闭包往下递"的模式。
 * ★ `$scheduler === null`（解释器 / 单测）⇒ **不自动轮询**，只能手动
 *   `pollOnce()`。这是有意的：测试里不能有"自己转起来的定时器"。
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

    // ── 签名 ────────────────────────────────────────────────────────────
    private AsyncCommand $signCommand;

    private ?SignInfo $sign = null;

    /**
     * "取完签名之后要不要接着上传"。
     *
     * ★ 这是**接力**而不是嵌套：`upload()` 发现签名过期 ⇒ 先 `signCommand->run()`，
     *   在它的落地回调里看到这个标志为真 ⇒ 调 `startUpload()`。
     *   不用"在任务里顺手取签名"：那样签名就不可复用了（见类注释）。
     */
    private bool $uploadAfterSign = false;

    // ── 上传 ────────────────────────────────────────────────────────────
    private AsyncCommand $uploadCommand;

    /** @var list<string> 已上传的对象键（顺序 = 选中顺序） */
    private array $uploadedKeys = [];

    private StatusMessage $uploadMessage;

    // ── 表单 ────────────────────────────────────────────────────────────
    private string $time = '';

    private string $address = '';

    private string $imageSize = '2K';

    // ── 任务 ────────────────────────────────────────────────────────────
    private AsyncCommand $taskCommand;

    private string $taskId = '';

    private StatusMessage $taskMessage;

    // ── 轮询 ────────────────────────────────────────────────────────────
    private AsyncCommand $pollCommand;

    private bool $polling = false;

    private ?TaskResult $lastResult = null;

    // ── 结果图下载 ──────────────────────────────────────────────────────
    private AsyncCommand $downloadCommand;

    /** @var array<string, string> 远程 URL → 本地路径 */
    private array $localPaths = [];

    /** @var array<string, string> 下载失败的 URL → 原因 */
    private array $failedDownloads = [];

    /** 底部状态栏的一句话（整个应用的"现在在干什么"） */
    private StatusMessage $statusLine;

    /**
     * @param null|TaskHost $host 非 null ⇒ 全部走后台（§17.4.5）；null ⇒ 同步（测试）
     * @param null|callable(int, \Closure): int $scheduler 排一个延时回调；null ⇒ 不自动轮询
     */
    public function __construct(
        private readonly ?TaskHost $host = null,
        private readonly string $apiBase = Config::API_BASE,
        private $scheduler = null,
    ) {
        $this->uploadMessage = StatusMessage::none();
        $this->taskMessage = StatusMessage::none();
        $this->statusLine = StatusMessage::info('准备就绪');

        // ★ 时间默认"现在"（原文 `DOMContentLoaded` 里 `formatDateTime(new Date())`）。
        //   用户几乎总是要传"此刻"的水印，让他每次手打一遍没有意义。
        $this->time = \date('Y-m-d H:i:s');

        // ★ 每个闭包都**声明一个参数**：`AsyncCommand::run()` 永远传一个
        //   （混合签名在解释器下写错不报、只在 AOT 崩 —— 本仓已经炸过一次）。
        $this->signCommand = AsyncCommand::make(function (mixed $unused): Future {
            return $this->runSign();
        });
        $this->uploadCommand = AsyncCommand::make(function (mixed $payload): Future {
            return $this->runUpload($payload);
        });
        $this->taskCommand = AsyncCommand::make(function (mixed $unused): Future {
            return $this->runAddTask();
        });
        $this->pollCommand = AsyncCommand::make(function (mixed $taskId): Future {
            return $this->runPoll((string) $taskId);
        });
        $this->downloadCommand = AsyncCommand::make(function (mixed $urls): Future {
            return $this->runDownload(\is_array($urls) ? \array_values($urls) : []);
        });

        // ★ 落地回调都在**构造器**里挂一次（不在 run 里挂 —— 每 run 一次多一份
        //   订阅，订阅数会随操作次数增长）。
        $this->signCommand->subscribeFor($this, function (): void {
            $this->absorbSign();
        });
        $this->uploadCommand->subscribeFor($this, function (): void {
            $this->absorbUpload();
        });
        $this->taskCommand->subscribeFor($this, function (): void {
            $this->absorbTask();
        });
        $this->pollCommand->subscribeFor($this, function (): void {
            $this->absorbPoll();
        });
        $this->downloadCommand->subscribeFor($this, function (): void {
            $this->absorbDownload();
        });

        // ★ 副作用只挂**结算**缝：值订阅对每一次通知都跑（run() 会先发一趟 loading，
        //   进度还会再发几趟），接力 / 排期 / 触发下载只能在 land()·fail() 之后做。
        $this->signCommand->subscribeSettledFor($this, function (): void {
            $this->relayAfterSign();
        });
        $this->taskCommand->subscribeSettledFor($this, function (): void {
            $this->pollAfterSubmit();
        });
        $this->pollCommand->subscribeSettledFor($this, function (AsyncValue $value): void {
            $this->scheduleAfterPoll($value);
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
        return $this->selected !== [] && !$this->uploadCommand->isRunning();
    }

    public function uploadValue(): AsyncValue
    {
        return $this->uploadCommand->value();
    }

    /** 上传进度（`0..1`）—— 从命令的第五维读，不另存一份 */
    public function uploadProgress(): float
    {
        return $this->uploadCommand->value()->progressOr(0.0);
    }

    public function isUploading(): bool
    {
        return $this->uploadCommand->isRunning();
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
        return $this->taskCommand->isRunning();
    }

    /** 能提交任务 = 有已上传的图 + 时间地址都填了 + 没在提交中 */
    public function canSubmit(): bool
    {
        return $this->uploadedKeys !== []
            && $this->time !== ''
            && $this->address !== ''
            && !$this->taskCommand->isRunning();
    }

    public function isPolling(): bool
    {
        return $this->polling;
    }

    public function lastResult(): ?TaskResult
    {
        return $this->lastResult;
    }

    /** 轮询命令的四态（页面用 `fold()` 画"查询失败/查询中"） */
    public function pollValue(): AsyncValue
    {
        return $this->pollCommand->value();
    }

    /**
     * 已下载到本地的结果图（`url => 本地路径`）。
     *
     * ★ 页面按 `lastResult()->downloadUrls` 的顺序取，**不**直接遍历这个 map：
     *   它的键序是下载完成顺序，而用户要看的是服务端给的顺序。
     *
     * @return array<string, string>
     */
    public function localPaths(): array
    {
        return $this->localPaths;
    }

    /** @return array<string, string> 下载失败的 URL → 原因 */
    public function failedDownloads(): array
    {
        return $this->failedDownloads;
    }

    public function isDownloading(): bool
    {
        return $this->downloadCommand->isRunning();
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
     */
    public function prewarm(): void
    {
        if ($this->signCommand->isRunning()) {
            return;
        }
        $this->signCommand->run(null);
    }

    /** 点"上传" */
    public function upload(): void
    {
        if ($this->selected === [] || $this->uploadCommand->isRunning()) {
            return;
        }

        // ★ 签名过期 / 还没取 ⇒ 先取，取完在 `absorbSign()` 里接力。
        //   原文的判据是 `Date.now() > +expireTime`，这里更保守（留 60s 余量），
        //   理由见 `SignInfo::isExpired()`。
        $sign = $this->sign;
        if ($sign === null || $sign->isExpired(self::nowMs())) {
            $this->sign = null;
            $this->uploadAfterSign = true;
            $this->uploadMessage = StatusMessage::loading('正在获取上传凭证…');
            $this->notifyChanged();
            $this->signCommand->run(null);

            return;
        }

        $this->startUpload($sign);
    }

    /** 点"提交任务" */
    public function submitTask(): void
    {
        if (!$this->canSubmit()) {
            // ★ 不静默返回：用户点了按钮什么都没发生，会以为按钮坏了。
            //   真正的原因（没传图 / 没填时间）由 `canSubmit()` 的三个条件给，
            //   这里给一句人话。
            if ($this->uploadedKeys === []) {
                $this->taskMessage = StatusMessage::error('请先上传图片');
            } else {
                $this->taskMessage = StatusMessage::error('请填写时间和地址');
            }
            $this->notifyChanged();

            return;
        }

        $this->taskMessage = StatusMessage::loading('正在提交任务…');
        $this->notifyChanged();
        $this->taskCommand->run(null);
    }

    /** 用户点"停止轮询" */
    public function stopPolling(): void
    {
        if (!$this->polling) {
            return;
        }
        $this->polling = false;
        $this->pollCommand->cancel();
        $this->notifyChanged();
    }

    /**
     * 查一次（**手动**入口；自动节奏由 `$scheduler` 排）。
     *
     * ★ 公开是给"用户点重试"用的；自动轮询走的是 `scheduleNextPoll()`。
     */
    public function pollOnce(): void
    {
        if ($this->taskId === '' || $this->pollCommand->isRunning()) {
            return;
        }
        $this->pollCommand->run($this->taskId);
    }

    // ══════════════════ 内部：发起 ══════════════════

    private function startUpload(SignInfo $sign): void
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

        $this->uploadedKeys = [];
        $this->uploadMessage = StatusMessage::loading('正在上传 0 / ' . (string) \count($items) . '…');
        $this->statusLine = StatusMessage::info('正在上传 ' . (string) \count($items) . ' 个文件…');
        $this->notifyChanged();

        // ★ 快照传进去（不是 `$this->selected`）：`AsyncCommand` 的闭包统一收一个
        //   `mixed`，而这里要两样东西（签名 + 文件表）⇒ 打成一个数组。
        //   ★ 同步路径与后台路径**同一个入参** —— 两条路只有"谁来跑"不同，
        //   参数形状必须一致，否则某天换成后台就会 TypeError。
        $this->uploadCommand->run(['sign' => $sign->toArray(), 'items' => $items]);
    }

    private function runSign(): Future
    {
        // ★ 同步/后台只差"谁来跑 `Job::run()`"，选路由 `exec()` 一处决定
        //   （2026-11-03，P0）。原先这里手写了一份 `ApiClient->sign()` 的同步副本
        //   —— 与 `FetchSignJob::run()` 逐字节相同，改一处要记得改两处。
        return $this->exec(new FetchSignJob($this->apiBase));
    }

    private function runUpload(mixed $payload): Future
    {
        // ★ 入参是 `startUpload()` 拍的**快照**，不是活引用 —— 用户在上传途中
        //   改列表 / 清空，传的东西与屏幕上那一批仍然一致。
        $sign = [];
        $items = [];
        if (\is_array($payload)) {
            $rawSign = $payload['sign'] ?? [];
            $rawItems = $payload['items'] ?? [];
            $sign = \is_array($rawSign) ? $rawSign : [];
            $items = \is_array($rawItems) ? $rawItems : [];
        }

        // ★ 同步路径与后台路径**同一个入参** —— 两条路只有"谁来跑"不同，
        //   参数形状必须一致（`exec()` 保证这一点：它把同一个 Job 交给两边的其中一个）。
        return $this->exec(new UploadBatchJob($sign, $items, $this->apiBase));
    }

    private function runAddTask(): Future
    {
        return $this->exec(new AddTaskJob($this->uploadedKeys, $this->time, $this->address, $this->imageSize, $this->apiBase));
    }

    private function runPoll(string $taskId): Future
    {
        return $this->exec(new PollResultJob($taskId, $this->apiBase));
    }

    /**
     * 下载一批结果图。
     *
     * ★ 与其它三个命令不同，它的**入参是 URL 列表**（不是 `null`）：同一轮任务
     *   只有一批图，但"重试下载"会再跑一次 ⇒ 参数要能独立表达"下哪几张"。
     *
     * @param list<string> $urls
     */
    private function runDownload(array $urls): Future
    {
        // ★ 复用 `DownloadResultsJob` 那一份（含 `fetchOne` + 部分失败聚合）。
        //   同步路径原先手写了一份 `fetchToTemp`，与 `fetchOne` 是同一件事的两份实现。
        return $this->exec(new DownloadResultsJob($urls));
    }

    /**
     * 唯一的选路点（2026-11-03，P0）—— 五个动作都走这里，不再各写一遍
     * `if ($host === null) { …同步副本… } else { submit }`。
     *
     * ★ `null` 永远表示"在当前栈上同步跑"（解释器 / 单测），**不表示"拒绝"** ——
     *   后者是 `Application::submit()` 的语义，两者混在一起会让症状变模糊。
     */
    private function exec(Job $job): Future
    {
        return JobRunner::exec($this->host, $job);
    }

    // ══════════════════ 内部：落地 ══════════════════

    private function absorbSign(): void
    {
        $value = $this->signCommand->value();

        if ($value->hasData()) {
            $raw = $value->value();
            $this->sign = SignInfo::fromArray($raw);
            if ($this->sign === null) {
                $this->uploadMessage = StatusMessage::error('上传凭证格式不对（缺字段）');
            }
        } elseif ($value->hasError()) {
            // ★ 预热失败**不打扰用户**：上传时会再取一次。只有"上传前那次"失败才要报。
            if ($this->uploadAfterSign) {
                $this->uploadMessage = StatusMessage::error('获取上传凭证失败：' . $value->message());
            }
        }

        $this->notifyChanged();
    }

    /** 结算缝：签名到手 ⇒ 立刻开始上传（用户点的是"上传"，不是"取签名"）。 */
    private function relayAfterSign(): void
    {
        $relay = $this->uploadAfterSign;
        $this->uploadAfterSign = false;

        if ($relay && $this->sign !== null && !$this->uploadCommand->isRunning()) {
            $this->startUpload($this->sign);
        }
    }

    private function absorbUpload(): void
    {
        $value = $this->uploadCommand->value();

        if ($value->hasData()) {
            $raw = $value->value();
            $keys = \is_array($raw) ? ($raw['keys'] ?? []) : [];
            $this->uploadedKeys = [];
            foreach (\is_array($keys) ? $keys : [] as $key) {
                if (\is_string($key) && $key !== '') {
                    $this->uploadedKeys[] = $key;
                }
            }
            $this->uploadMessage = StatusMessage::success(
                '全部 ' . (string) \count($this->uploadedKeys) . ' 个文件上传成功'
            );
            $this->statusLine = StatusMessage::success('上传完成，可以提交任务了');
        } elseif ($value->hasError()) {
            $this->uploadedKeys = [];
            $this->uploadMessage = StatusMessage::error($value->message());
            $this->statusLine = StatusMessage::error('上传失败');
        } elseif ($value->isLoading()) {
            // ★ 进度条与文案一起动：只动进度条的话，用户在 0% 时看不出"在干什么"
            $this->uploadMessage = StatusMessage::loading(
                '正在上传… ' . (string) (int) \round($value->progressOr(0.0) * 100.0) . '%'
            );
        }

        $this->notifyChanged();
    }

    private function absorbTask(): void
    {
        $value = $this->taskCommand->value();

        if ($value->hasData()) {
            $raw = $value->value();
            $taskId = \is_array($raw) ? ($raw['taskId'] ?? '') : '';
            $this->taskId = \is_string($taskId) ? $taskId : '';

            if ($this->taskId === '') {
                $this->taskMessage = StatusMessage::error('建任务成功但没拿到任务 id');
            } else {
                $this->taskMessage = StatusMessage::success('任务已提交（ID ' . $this->taskId . '），正在查询结果…');
                $this->statusLine = StatusMessage::info('等待处理结果…');
                $this->polling = true;
                $this->lastResult = null;
            }
        } elseif ($value->hasError()) {
            $this->taskMessage = StatusMessage::error($value->message());
            $this->statusLine = StatusMessage::error('提交任务失败');
        }

        $this->notifyChanged();
    }

    /** 结算缝：提交成功 ⇒ 立刻查一次（不等 10 秒）。 */
    private function pollAfterSubmit(): void
    {
        if ($this->polling) {
            $this->pollOnce();
        }
    }

    private function absorbPoll(): void
    {
        $value = $this->pollCommand->value();

        if ($value->hasData()) {
            $raw = $value->value();
            $result = TaskResult::fromArray($raw, $this->taskId);
            if ($result !== null) {
                $this->lastResult = $result;
                if ($result->isTerminal()) {
                    $this->polling = false;
                    $this->statusLine = $result->isSuccess()
                        ? StatusMessage::success('处理完成')
                        : StatusMessage::error($result->failureReason());
                }
            }
        } elseif ($value->hasError()) {
            // ★ 查询失败**不终止轮询**：网关抖一下不该让整轮白跑。
            //   原文也只 `console.error`，然后等下一个 `setInterval`。
            //   ⇒ 这里只更新底部状态，`$polling` 保持 true。
            $this->statusLine = StatusMessage::error('查询结果失败（会继续重试）：' . $value->message());
        }

        $this->notifyChanged();
    }

    /** 结算缝：排下一轮 / 触发下载（手写门控已由 AsyncCommand 承担）。 */
    private function scheduleAfterPoll(AsyncValue $value): void
    {
        if ($this->polling) {
            $this->scheduleNextPoll();

            return;
        }

        if (!$value->hasData()) {
            return;
        }

        // 用本次结算的原始值复现"刚终结且成功"，不依赖 lastResult 的残留
        $result = TaskResult::fromArray($value->value(), $this->taskId);
        if ($result !== null && $result->isTerminal() && $result->isSuccess()
            && !$this->downloadCommand->isRunning()) {
            $this->startDownload($result->downloadUrls);
        }
    }

    /** @param list<string> $urls */
    private function startDownload(array $urls): void
    {
        if ($urls === []) {
            return;
        }

        $this->localPaths = [];
        $this->failedDownloads = [];
        $this->downloadCommand->run($urls);
    }

    private function absorbDownload(): void
    {
        $value = $this->downloadCommand->value();

        if ($value->hasData()) {
            $raw = $value->value();
            $paths = \is_array($raw) ? ($raw['paths'] ?? []) : [];
            $failed = \is_array($raw) ? ($raw['failed'] ?? []) : [];

            $this->localPaths = [];
            foreach (\is_array($paths) ? $paths : [] as $url => $path) {
                if (\is_string($url) && \is_string($path) && $path !== '') {
                    $this->localPaths[$url] = $path;
                }
            }

            $this->failedDownloads = [];
            foreach (\is_array($failed) ? $failed : [] as $url => $reason) {
                if (\is_string($url) && $reason !== '') {
                    $this->failedDownloads[$url] = \is_string($reason) ? $reason : '下载失败';
                }
            }

            if ($this->failedDownloads !== []) {
                $this->statusLine = StatusMessage::error(
                    '有 ' . (string) \count($this->failedDownloads) . ' 张结果图没能下载'
                );
            }
        } elseif ($value->hasError()) {
            $this->statusLine = StatusMessage::error('下载结果图失败：' . $value->message());
        }

        $this->notifyChanged();
    }

    // ══════════════════ 内部：轮询节奏 ══════════════════

    /**
     * 排下一次轮询（`$scheduler` 为空 ⇒ 不排，测试里只能手动 `pollOnce()`）。
     *
     * ★ 用注入的 `after()` 而不是"每帧检查时间"：后者要求 `wantsFrame()` 恒真
     *   ⇒ 帧时钟一直转，CPU 不归零（空闲不空转是本仓的一条硬判据）。
     */
    private function scheduleNextPoll(): void
    {
        $scheduler = $this->scheduler;
        if ($scheduler === null) {
            return;
        }

        $scheduler(Config::POLL_INTERVAL_MS, function (): void {
            if (!$this->polling) {
                return;   // 期间用户点了"停止轮询"
            }
            $this->pollOnce();
        });
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
}
