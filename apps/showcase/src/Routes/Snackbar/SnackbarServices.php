<?php
declare(strict_types=1);

namespace App\Showcase\Routes\Snackbar;

use Drift\Widget\Environment;
use Drift\Widget\Snackbar;

/**
 * 这一页实际够得到的能力：**只有一条** `notify`。
 *
 * 页面能调什么由这个类的方法表决定；环境门面的 delay / showModal 一个都不在这里。
 * 服务投影是页面内部选择，导航层不持有它。
 *
 * @api
 */
final readonly class SnackbarServices
{
    public function __construct(private Environment $env)
    {
    }

    public function notify(Snackbar $snackbar): void
    {
        $this->env->notify($snackbar);
    }
}
