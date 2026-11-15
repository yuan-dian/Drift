<?php
declare(strict_types=1);

namespace Drift\App\Restoration;

use Drift\Errors;

/**
 * 业务状态跨进程落盘。构造即恢复（无时机可漏），persist 由组合根在退出时调一次。
 *
 * @api
 */
final class RestorationStore
{
    private const int VERSION = 1;

    private RestorationBucket $bucket;

    /** @param string $path 完整路径；`''` = 平台没给配置目录 ⇒ 整个 store 退化为空操作 */
    public function __construct(private readonly string $path)
    {
        $this->bucket = new RestorationBucket();
        $this->restore();
    }

    public function bucket(): RestorationBucket
    {
        return $this->bucket;
    }

    private function restore(): void
    {
        if ($this->path === '' || !is_file($this->path)) {
            return;
        }

        $text = file_get_contents($this->path);
        if ($text === false) {
            return;
        }

        $data = json_decode($text, true);
        if (!is_array($data)
            || ($data['v'] ?? null) !== self::VERSION
            || !is_array($data['data'] ?? null)
        ) {
            Errors::report('业务状态：文件不可读或版本不符 —— 用空状态启动', Errors::DIAGNOSTIC);

            return;
        }

        foreach ($data['data'] as $key => $value) {
            if (!is_string($key) || !RestorationBucket::isValue($value)) {
                Errors::report('业务状态：文件里有非法值 —— 用空状态启动', Errors::DIAGNOSTIC);

                return;
            }
        }

        $this->bucket = new RestorationBucket($data['data']);
    }

    public function persist(): void
    {
        if ($this->path === '' || !$this->bucket->isDirty()) {
            return;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (!is_writable($dir)) {
            Errors::report('业务状态：配置目录不可写 —— 本次不保存', Errors::DIAGNOSTIC);

            return;
        }

        $text = json_encode(
            ['v' => self::VERSION, 'data' => $this->bucket->all()],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
        if ($text === false || file_put_contents($this->path, $text . "\n", LOCK_EX) === false) {
            Errors::report('业务状态：写不进配置文件 —— 本次不保存', Errors::DIAGNOSTIC);

            return;
        }

        $this->bucket->clearDirty();
    }
}
