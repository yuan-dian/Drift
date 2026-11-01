<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;
use Drift\Geometry\Matrix2D;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Text\GlyphBitmap;
use Drift\Text\Paragraph;

/**
 * 软光栅画布：无头预览、CI、测试（§7.2 裁决 1）。
 *
 * ★ **无 OS 依赖**是它的全部价值 —— §14 确定性的物质基础：布局层零 OS 依赖
 *   ⇒ 可在 `php.exe` 里直接跑完整渲染，不编译、不开窗口。
 *
 * ★ 光栅顺序 = 录制顺序，且只依赖（命令流、裁剪、当前颜色）⇒ 同一份
 *   `DisplayList` 重放必然得到同一张位图（G3）。
 *
 * 覆盖能力：轴对齐矩形（填充 / 描边 / 圆角）、椭圆、线段、平移、矩形裁剪、
 * **路径裁剪（每行 x 区间掩码，W1/L3-2）**、路径填充与描边（扫描线 + 奇偶，
 * W1/L3-1）、**层不透明度（alpha 乘数栈，W1/L3-3）**、**图像（最近邻 blit，
 * W8-1）**、source-over 混合、**段落（字形位图按覆盖度混合）**。
 *
 * ★ 逻辑 → 物理只走 `Scale::quantizeRect()`（§6.1 的唯一量化点）。画布本身是
 *   **物理像素**空间，构造尺寸就是像素尺寸。
 *
 * @api
 */
final class RasterCanvas implements Canvas
{
    private string $pixels;
    private float $offsetX = 0.0;
    private float $offsetY = 0.0;

    /**
     * 当前仿射变换（局部 → 父局部）；`null` = 没有（绝大多数绘制走这条路）。
     *
     * ★ 与 `$offsetX/$offsetY` 的关系是**互斥的两段**，不是叠加：一旦有了矩阵，
     *   后续的 `translate()` 会折进矩阵（`translated()`），偏移停在进入时的值并被
     *   吸收进矩阵的平移分量。两段混用就是"先取整再变换"与"先变换再取整"的
     *   半个像素账 —— 与 `SkiaCanvas` 的 `$devMode` 是同一条纪律。
     */
    private ?Matrix2D $transform = null;
    private Rect $clip;
    private readonly Scale $scale;

    /** @var list<array{float, float, ?Matrix2D}> 变换栈：[offsetX, offsetY, transform]（裁剪独立成栈） */
    private array $stack = [];

    /**
     * 路径裁剪的行掩码（`05` §4.2）：`list<array{int, int, list<array{int, int}>}>`。
     *
     * 每条目 = `[y0, y1, spans]`：像素行 y ∈ [y0, y1) 的可绘区间是 spans
     * （已量化、已排序、互不相交、已裁到压入那一刻的 clip 与画布）。条目按
     * y0 升序 ⇒ 查询走二分（`clipRowSpansForRow`）。
     *
     * null = 当前没有路径裁剪 ⇒ 走快路直接放行（绝大多数帧如此）。
     *
     * ★ 为什么压入时一次算完、不在每次绘制时重算：push 一次 O(行数)，
     *   之后每像素 O(1) 查表（`05` §4.2 第 ④ 步）。反过来做会把裁剪成本
     *   按绘制次数反复付。
     */
    private ?array $clipRows = null;

    /**
     * 裁剪栈：与变换栈独立（两条栈分别配对，W8 分别计深）。
     *
     * ★ 每条目是 **`[Rect, ?array]` 一对**而不是只有 Rect：`pushClip(Rect)` 与
     *   `pushClipPath(Path)` 共用同一条栈（`Canvas` 接口契约，弹栈都用
     *   `popClip()`），两种裁剪任意交错时 `popClip` 必须把两个字段一起恢复。
     *   只存 Rect 的话，`pushClipPath` 之后的 `popClip` 会把行掩码漏在外面 ——
     *   表现为"裁剪没弹掉"（后续全被裁），且静默。
     *
     * @var list<array{Rect, ?array}>
     */
    private array $clipStack = [];

    /**
     * 层不透明度栈（`05` §5.6）：栈顶 = 当前生效的乘数（空栈 ⇒ 1.0）。
     *
     * ★ 这是 **alpha 乘数栈**，不是真离屏：每个 draw 的颜色 alpha 各乘一次
     *   （`effectiveColor`）⇒ 层内重叠子之间会重复相乘，视觉上比 Skia 的真
     *   离屏合成**更透**。Extended 层的有意近似 —— 真离屏在纯 PHP 里是一块
     *   `array<int,int>`（1080p 约 200 万元素），内存与 GC 压力远超这 20 行
     *   的收益。差异由 smoke 夹具钉住（`05` §12.2 #9）。
     *
     * @var list<float>
     */
    private array $layerAlpha = [];

    /**
     * §13.5 的两个纯性能缓存（**不影响输出**——同输入同字节，golden 逐字节比对过）：
     *
     * 1. `opaqueChunks`：`r,g,b` → 四个预计算的单字节串。不透明矩形填充省掉
     *    每像素 4 次 `chr()` 调用（字符串偏移写在 refcount==1 时原地完成，O(1)）。
     * 2. `blendLuts`：`r,g,b,alpha` → 四条 256 项目标的预混合表。半透明填充从
     *    "每像素 8 次 `round()`" 变成 "每像素 4 次查表"。
     *
     * ★ 为什么只缓存"填充"不缓存"字形"：字形的 alpha 每像素都在变（覆盖度
     *   0..255），一个键只摊一个像素 ⇒ 建表比直接算还贵。字形仍走 `blend()`。
     * ★ 两个缓存都设上限、超限整体清零：它们是**纯性能缓存**，清了只回到慢，
     *   不会错（颜色键空间理论上开放，防的是"字号动画扫几千个 alpha"那种）。
     *
     * @var array<string, array<int, string>>
     */
    private array $opaqueChunks = [];

    /** @var array<string, array<int, array<int, string>>> */
    private array $blendLuts = [];

    private const CACHE_MAX = 128;

    public function __construct(
        private readonly int $width,
        private readonly int $height,
        ?Color $background = null,
        ?Scale $scale = null,
    ) {
        $this->pixels = str_repeat("\x00", $width * $height * 4);
        $this->clip = new Rect(0.0, 0.0, (float) $width, (float) $height);
        $this->scale = $scale ?? Scale::identity();

        if ($background !== null) {
            $this->clear($background);
        }
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /**
     * 原始 RGBA 字节流（行优先，每像素 4 字节）。
     *
     * ★★ **是预乘（premultiplied）RGBA，不是直通 alpha**：混合公式
     *   `dst = src·a + dst·(1−a)`（见 `blend()`）算出来的就是预乘结果。
     *   底色全不透明时两者**逐字节相同**（`a == 255` ⇒ 预乘 = 直通），所以这个
     *   区别在 M1 阶段从未显形；底色一旦带 alpha 就立刻分开 ——
     *   `#33669980` 清屏后这里是 `(26, 51, 76, 128)`，不是 `(51, 102, 153, 128)`。
     *
     * ★ 这个事实是**承重**的：`UpdateLayeredWindow` 要的正是预乘 BGRA ⇒
     *   壳侧只需 R↔B 换位，**不能再乘一次 alpha**（否则半透明区被二次压暗）。
     *   Skia 侧同样是预乘（`N32Premul`）⇒ 两个后端在这一层终于口径一致。
     */
    public function toRgba(): string
    {
        return $this->pixels;
    }

    /** 读一个像素（**预乘** RGBA，见 `toRgba()` 的长注释） */
    public function pixelAt(int $x, int $y): Color
    {
        $i = ($y * $this->width + $x) * 4;
        return new Color(
            ord($this->pixels[$i]),
            ord($this->pixels[$i + 1]),
            ord($this->pixels[$i + 2]),
            ord($this->pixels[$i + 3])
        );
    }

    public function clear(Color $color): void
    {
        $this->rasterize(
            new Rect(0.0, 0.0, (float) $this->width, (float) $this->height),
            0.0,
            $color,
            null,
            0.0
        );
    }

    public function save(): void
    {
        $this->stack[] = [$this->offsetX, $this->offsetY, $this->transform];
    }

    public function restore(): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return; // 未配对的 restore 由 W8 在录制侧拦截，光栅侧静默忽略即可
        }
        $this->offsetX = $frame[0];
        $this->offsetY = $frame[1];
        $this->transform = $frame[2];
    }

    public function translate(float $dx, float $dy): void
    {
        // ★ 已在矩阵模式 ⇒ 平移**折进矩阵**（`CTM · T`），不能动 offset：
        //   offset 是"矩阵之后"的外层平移，混用会让 `rotate(90); translate(10,0)`
        //   画成 `R(p) + (10,0)` 而不是 `R(p + (10,0))`。
        if ($this->transform !== null) {
            $this->transform = $this->transform->translated($dx, $dy);

            return;
        }

        $this->offsetX += $dx;
        $this->offsetY += $dy;
    }

    /**
     * 追加仿射变换（`CTM' = CTM · m`）。
     *
     * ★ 纯平移且尚未进矩阵模式 ⇒ 并进 offset（老路，零额外成本）。
     * ★ 进入矩阵模式时把**已累加的 offset 吸收**成矩阵的平移分量，
     *   之后 offset 恒为 0 且不再变 —— 两段不混用。
     */
    public function transform(Matrix2D $matrix): void
    {
        // ★★ AOT：读另一个对象的属性是 `php::Var`，`Float += Var` 编译期 C2679
        //   （解释器/phpunit 全绿，只有构建会红）⇒ 显式 `(float)` 落局部。
        $tx = (float) $matrix->tx;
        $ty = (float) $matrix->ty;

        if ($this->transform === null) {
            if ($matrix->isTranslationOnly()) {
                $this->offsetX += $tx;
                $this->offsetY += $ty;

                return;
            }

            // CTM = T(offset) · m ⇒ 点 p 先被 m 变换、再平移 offset
            $base = Matrix2D::translation($this->offsetX, $this->offsetY);
            $this->transform = $matrix->then($base);
            $this->offsetX = 0.0;
            $this->offsetY = 0.0;

            return;
        }

        // CTM' = CTM · m ⇒ 「先 m 再旧 CTM」
        $this->transform = $matrix->then($this->transform);
    }

    /** 局部逻辑点 → 设备**浮点**坐标（未取整；取整仍然只在 `Scale` 那一处） */
    /**
     * 逻辑点 → 设备坐标（**不取整**，调用方自己 `round()`）。
     *
     * ★ 无矩阵时走 `Scale::deviceX()` —— 那条"加偏移再乘 dpr"的公式全仓只有
     *   一份（此前这里与 `SkiaCanvas` 各写一遍，见 `Scale::deviceRect()` 的注释）。
     */
    private function transformedX(float $x, float $y): float
    {
        $t = $this->transform;
        if ($t === null) {
            return $this->scale->deviceX($x, $this->offsetX);
        }

        return $t->applyX($x, $y) * $this->scale->dpr;
    }

    private function transformedY(float $x, float $y): float
    {
        $t = $this->transform;
        if ($t === null) {
            return $this->scale->deviceY($y, $this->offsetY);
        }

        return $t->applyY($x, $y) * $this->scale->dpr;
    }

    public function pushClip(Rect $rect): void
    {
        $this->clipStack[] = [$this->clip, $this->clipRows];
        $this->clip = $this->clip->intersect($this->toDevice($rect));
    }

    public function popClip(): void
    {
        $previous = array_pop($this->clipStack);
        if ($previous === null) {
            return; // 未配对的 popClip 由 W8 在录制侧拦截，光栅侧静默忽略即可
        }
        [$this->clip, $this->clipRows] = $previous;
    }

    public function drawRect(Rect $rect, Paint $paint, float $radius = 0.0): void
    {
        $device = $this->toDevice($rect);

        if (!$paint->isStroke()) {
            $this->rasterize($device, $radius, $paint->color, null, 0.0, $paint->gradient);
            return;
        }

        // 描边 = 外圈减去内圈：一个循环搞定，圆角自然跟随
        $w = $paint->strokeWidth;
        $hole = new Rect(
            $device->left + $w,
            $device->top + $w,
            $device->width - 2.0 * $w,
            $device->height - 2.0 * $w
        );
        // ★ R2 降级：矩形/椭圆描边的虚线退化为实线（虚线只在 drawLine 上做真虚线），
        //   故这里无视 $paint->dash，只透传渐变（描边渐变仍是实色渐变，不是虚线）。
        $this->rasterize($device, $radius, $paint->color, $hole, max(0.0, $radius - $w), $paint->gradient);
    }

    /**
     * 段落绘制：把已排好的字形逐个光栅上屏。
     *
     * ★ 字形位图在**这里**才产生（录制时只存 `Paragraph` 引用）—— 于是同一份
     *   `DisplayList` 在 Skia 后端能用真字体重画，在软光栅这里用 face 的位图，
     *   两侧共用同一套步进与基线（§14.2）。
     *
     * ★ 没有位图的字形**不画**（`rasterize()` 返回 `null`）—— 不是画一个方框顶上。
     */
    public function drawParagraph(Paragraph $paragraph, Paint $paint, float $x = 0.0, float $y = 0.0): void
    {
        if ($paint->color->a === 0) {
            return;
        }

        // ★ 文本**必须**吃画布变换：`$x/$y` 是逻辑坐标，父盒给的位置是靠
        //   `translate()` 传进来的（`RenderProxyBox::paint` 就是这么把子盒偏移
        //   递下去的）。不加上当前偏移 ⇒ `Center` 算好的居中偏移画不出来，
        //   文字永远贴在左上角 —— 而 `drawRect` 走 `toDevice()` 是对的，于是
        //   "矩形跟着变换、文字不跟"这种半边错的状态最难发现。
        // ★ 矩阵模式下只变换**原点**：字形本身仍是水平位图（软光栅没有字形的
        //   仿射能力）⇒ 缩放时字号不变、旋转时文字不跟着转。这是 `RasterCanvas`
        //   有意的降级（见类注释）；真机的 `SkiaCanvas` 把变换下推到 CTM，文字
        //   是完整变换的 —— 所以 **"文字跟着变换"这条只能靠真机判**。
        $t = $this->transform;
        if ($t === null) {
            $originX = $x + $this->offsetX;
            $originY = $y + $this->offsetY;
        } else {
            $originX = $t->applyX($x, $y);
            $originY = $t->applyY($x, $y);
        }

        foreach ($paragraph->lines as $line) {
            $baseline = $originY + $line->baseline;
            $pen = $originX + $line->offsetX;

            foreach ($line->runs as $run) {
                // ★ 每个 run 自己的颜色与字号（W8-5 富文本）；`-1` = 用整段的 Paint。
                //   单段路径的 run 恒 `-1`、style === 段落 style ⇒ 逐字节不变。
                $runColor = $run->argb >= 0 ? Color::fromArgb($run->argb) : $paint->color;
                $pixelSize = $run->style->size * $this->scale->dpr;

                foreach ($run->glyphs as $glyph) {
                    $bitmap = $run->face->rasterize($glyph->codepoint, $pixelSize);
                    if ($bitmap !== null) {
                        $this->blendBitmap($bitmap, $runColor, $pen + $glyph->offsetX, $baseline + $glyph->offsetY);
                    }
                    $pen += $glyph->advance;
                }
            }
        }
    }


    /**
     * P1 起 Core 两 op 真实现；Extended 在 W1/L3-1 起补（`CAP_PATH` 已提档）。
     *
     * ★ `CAP_PATH` 置位的含义是"能画路径"，**不是"与 Skia 逐像素相同"**：
     *   Raster 这一份是硬边无抗锯齿、奇偶规则、只做 Miter+Butt（`05` §3.3/§3.4）。
     *   两个后端的像素级 golden 本来就不互比（既有约定），能力位只回答"能不能画"。
     */
    /**
     * 软光栅的**支持面**（静态版本；`RecordingCanvas` 录制时按回放端降级要用它）。
     *
     * ★ 平铺叶子常量的一次 `|` —— tpc 对"常量链"会生成坏代码，见
     *   `CanvasCapabilities::ALL_MASK` 的注释。一份定义、两处引用（这里与
     *   `RecordingSurface`），避免"录制能力"和"回放能力"各拼一份然后漂移。
     */
    public static function supportedCapabilities(): int
    {
        return CanvasCapabilities::CAP_ELLIPSE | CanvasCapabilities::CAP_LINE
            | CanvasCapabilities::CAP_GRADIENT | CanvasCapabilities::CAP_DASH
            | CanvasCapabilities::CAP_CLIP_PATH | CanvasCapabilities::CAP_PATH
            | CanvasCapabilities::CAP_LAYER | CanvasCapabilities::CAP_IMAGE
            | CanvasCapabilities::CAP_BACKDROP;
    }

    public function capabilities(): int
    {
        // ★ Extended 层：Raster 允许降级近似（§7.2 D2 表）—— 渐变做逐像素实渐变
        //   （比"分带"更好），虚线在**线段**上做真虚线、在矩形/椭圆描边上退化为实线。
        //   两种都"产出了像素"，故置位；Skia 端做完整实现。
        // ★ `CAP_CLIP_PATH` 在 W1/L3-2 真正落地：补了每行 x 区间掩码（`05` §4.2）
        //   后，Extended 层的缺口从 1 变 0。这一位一直在 Extended 段（`05` §1.1
        //   第 6 行的"矛盾"：标着 Extended 而 Raster 响亮失败），本步消掉的正是
        //   那个矛盾。掩码是硬边无抗锯齿的像素区间，与 `CAP_PATH` 同一族取舍。
        // ★ `CAP_LAYER` 在 W1/L3-3 置位：alpha 乘数栈（`05` §5.6）。Extended 层
        //   的有意近似 —— 与真离屏只差"层内重叠子更透"这一条，写进 docblock。
        // ★ `CAP_IMAGE` 在 W8-1 置位：`drawImage` 走最近邻 blit（见该方法 docblock）。
        //   1:1 时与 Skia 逐字节相同；缩放时 Skia 双线性、Raster 最近邻 —— 这是
        //   **有意的双端差异**，conformance 基线只钉 1:1（详见 drawImage 注释）。
        // ★ `CAP_BACKDROP` 也置位：`blurBackdrop()` 是**空实现**（不模糊）。
        //   与 `CAP_LAYER` 同一档"近似也算处理" —— 结构/透明度对，只少一层糊。
        return self::supportedCapabilities();
    }

    /**
     * 椭圆（外接矩形内切）填充 / 描边。
     *
     * ★ 不用 `rasterize()` 的区间法：那套靠"圆角只在上下边带行出现"，
     *   而椭圆**每一行**的 x 范围都在变 ⇒ 逐行用谓词求跨度。谓词是像素
     *   中心版 `((px-cx)/rx)² + ((py-cy)/ry)² <= 1`，与 `insideRoundRect`
     *   同一族（中心采样；软光栅只对圆角/字形做覆盖度，其余不抗锯齿）。
     * ★ 描边 = 外椭圆**扩**半宽减内椭圆**收**半宽（居中描边，与 Skia 的
     *   `drawOval` 同口径，两后端可互做 conformance）。注意这与 `drawRect`
     *   的"整宽内缩"是两套 —— drawRect 的口径是历史遗留，新 op 按正确
     *   形态来。
     * ★ 宽度不乘 dpr（两个后端 conformance 的统一口径）。
     */
    public function drawEllipse(Rect $rect, Paint $paint): void
    {
        if ($paint->color->a === 0) {
            return;
        }
        $device = $this->toDevice($rect);
        if ($device->width <= 0.0 || $device->height <= 0.0) {
            return;
        }

        $outer = $device;
        $hole = null;
        if ($paint->isStroke()) {
            $w = $paint->strokeWidth;
            $outer = new Rect(
                $device->left - $w / 2.0,
                $device->top - $w / 2.0,
                $device->width + $w,
                $device->height + $w
            );
            $hole = new Rect(
                $device->left + $w / 2.0,
                $device->top + $w / 2.0,
                $device->width - $w,
                $device->height - $w
            );
        }
        $this->rasterizeEllipse($outer, $paint->color, $hole, $paint->gradient);
    }

    /**
     * 线段（胶囊体谓词）。
     *
     * ★ 为什么不用 Bresenham + 圆头冲程：软光栅的老风格是"谓词逐像素"
     *   （`insideRoundRect` / 椭圆同一祖宗），胶囊体谓词（点到线段距离
     *   <= 半宽）对任意角度、任意宽度只有一个真相；"冲程叠加"在斜线上
     *   会露接缝。代价是 O(包围盒) 而非 O(像素数) —— 线段都小，与椭圆的
     *   逐行 sqrt 同款成本。
     * ★ 无抗锯齿 ⇒ 边缘与 Skia 不同，像素级 golden 不跨后端比（既有约定）。
     * ★ 端点取整：逻辑坐标叠加当前偏移后**一次**量化（与 `blendBitmap` /
     *   `drawParagraph` 的笔位同口径）；宽度不乘 dpr。
     */
    public function drawLine(float $x1, float $y1, float $x2, float $y2, Paint $paint): void
    {
        if ($paint->color->a === 0) {
            return;
        }
        CanvasCapabilities::assertStrokePaint($paint, 'drawLine');

        $half = $paint->strokeWidth / 2.0;
        $dpr = $this->scale->dpr;
        $ax = round($this->transformedX($x1, $y1));
        $ay = round($this->transformedY($x1, $y1));
        $bx = round($this->transformedX($x2, $y2));
        $by = round($this->transformedY($x2, $y2));

        $xa = max((int) ceil(min($ax, $bx) - $half - 0.5), (int) ceil($this->clip->left - 0.5), 0);
        $xb = min((int) floor(max($ax, $bx) + $half - 0.5) + 1, (int) ceil($this->clip->right() - 0.5), $this->width);
        $ya = max((int) ceil(min($ay, $by) - $half - 0.5), (int) ceil($this->clip->top - 0.5), 0);
        $yb = min((int) floor(max($ay, $by) + $half - 0.5) + 1, (int) ceil($this->clip->bottom() - 0.5), $this->height);
        if ($xa >= $xb || $ya >= $yb) {
            return;
        }

        $ex = $bx - $ax;
        $ey = $by - $ay;
        $len2 = $ex * $ex + $ey * $ey;
        $r2 = $half * $half;
        $color = $this->effectiveColor($paint->color);

        // ★ 虚线：沿弧长（逻辑单位，与 DashPattern 同口径）算"在 on 段还是 off 段"。
        //   s = t × 逻辑长度；相位 phase 让相邻段错开。Raster 在**线段**上做真虚线
        //   （矩形/椭圆描边退化为实线，见 drawRect/drawEllipse）。
        $dash = $paint->dash;
        $logicalLen = $len2 > 0.0 ? sqrt($len2) / $dpr : 0.0;
        $dashPeriod = $dash !== null ? $dash->period() : 0.0;
        $dashOn = $dash !== null ? $dash->on : 0.0;
        $dashPhase = $dash !== null ? $dash->phase : 0.0;

        for ($y = $ya; $y < $yb; $y++) {
            $py = $y + 0.5;
            for ($x = $xa; $x < $xb; $x++) {
                $px = $x + 0.5;
                if ($len2 <= 0.0) {
                    $dx = $px - $ax;
                    $dy = $py - $ay;
                } else {
                    $t = (($px - $ax) * $ex + ($py - $ay) * $ey) / $len2;
                    $t = $t < 0.0 ? 0.0 : ($t > 1.0 ? 1.0 : $t);
                    $dx = $px - ($ax + $t * $ex);
                    $dy = $py - ($ay + $t * $ey);
                }
                if ($dx * $dx + $dy * $dy > $r2) {
                    continue;
                }
                if ($dash !== null) {
                    $s = $t * $logicalLen + $dashPhase;
                    $m = fmod($s, $dashPeriod);
                    if ($m < 0.0) {
                        $m += $dashPeriod;
                    }
                    if ($m >= $dashOn) {
                        continue; // 落在空段：跳过点（虚线的"虚"）
                    }
                }
                $this->blend($x, $y, $color);
            }
        }
    }

    /**
     * 椭圆光栅：逐行求跨度写 span；`$hole` 非空时同行写两段（外减内）。
     *
     * @param ?Rect $hole 内椭圆（描边的洞）；null = 实心
     */
    private function rasterizeEllipse(Rect $ellipse, Color $color, ?Rect $hole, ?Gradient $gradient = null): void
    {
        $color = $this->effectiveColor($color);
        $cx = $ellipse->left + $ellipse->width / 2.0;
        $cy = $ellipse->top + $ellipse->height / 2.0;
        $rx = $ellipse->width / 2.0;
        $ry = $ellipse->height / 2.0;
        if ($rx <= 0.0 || $ry <= 0.0) {
            return;
        }

        // 与 rasterize() 同口径的区间：外框两端含 ∩ 裁剪左闭右开 ∩ 画布
        $xa = max((int) ceil($ellipse->left - 0.5), (int) ceil($this->clip->left - 0.5), 0);
        $xb = min((int) floor($ellipse->right() - 0.5) + 1, (int) ceil($this->clip->right() - 0.5), $this->width);
        $ya = max((int) ceil($ellipse->top - 0.5), (int) ceil($this->clip->top - 0.5), 0);
        $yb = min((int) floor($ellipse->bottom() - 0.5) + 1, (int) ceil($this->clip->bottom() - 0.5), $this->height);
        if ($xa >= $xb || $ya >= $yb) {
            return;
        }

        $opaque = $color->a >= 255;
        $lut = $opaque ? null : $this->blendLut($color);
        $ry2 = $ry * $ry;

        for ($y = $ya; $y < $yb; $y++) {
            $py = $y + 0.5;
            $dy = $py - $cy;
            $t = 1.0 - ($dy * $dy) / $ry2;
            if ($t <= 0.0) {
                continue; // 本行中心在椭圆外
            }
            $dx = $rx * sqrt($t);
            $x0 = max((int) ceil($cx - $dx - 0.5), $xa);
            $x1 = min((int) floor($cx + $dx - 0.5) + 1, $xb);
            if ($x0 >= $x1) {
                continue;
            }

            if ($hole === null || $hole->width <= 0.0 || $hole->height <= 0.0) {
                $this->fillSpanOrGrad($y, $x0, $x1, $color, $opaque, $lut, $gradient);
                continue;
            }

            // 内椭圆跨度（退化/本行中心在洞外 ⇒ 无洞）
            $hcx = $hole->left + $hole->width / 2.0;
            $hcy = $hole->top + $hole->height / 2.0;
            $hrx = $hole->width / 2.0;
            $hry = $hole->height / 2.0;
            $ht = ($hrx > 0.0 && $hry > 0.0) ? 1.0 - (($py - $hcy) * ($py - $hcy)) / ($hry * $hry) : 0.0;
            if ($ht <= 0.0) {
                $this->fillSpanOrGrad($y, $x0, $x1, $color, $opaque, $lut, $gradient);
                continue;
            }
            $hdx = $hrx * sqrt($ht);
            $h0 = max((int) ceil($hcx - $hdx - 0.5), $x0);
            $h1 = min((int) floor($hcx + $hdx - 0.5) + 1, $x1);
            if ($h0 < $h1) {
                $this->fillSpanOrGrad($y, $x0, $h0, $color, $opaque, $lut, $gradient);
                $this->fillSpanOrGrad($y, $h1, $x1, $color, $opaque, $lut, $gradient);
            } else {
                $this->fillSpanOrGrad($y, $x0, $x1, $color, $opaque, $lut, $gradient);
            }
        }
    }

    /**
     * 路径填充 / 描边（`05` §3；W1/L3-1 从 Optional 提档 Extended 后真实现）。
     *
     * ## 算法：扫描线 + 奇偶
     *
     * 路径没有解析式谓词（不像矩形/椭圆）⇒ 只能按行求与路径边的交点，
     * 排序后成对填区间。`O(边数 × 行数)`，且每行只填连续区间 —— 与 `rasterize()`
     * 同一条填色路径（`05` §3.1 的方案表）。
     *
     * ★ **奇偶（even-odd）而不是非零环绕**：`Path` 不记录子路径方向，非零环绕要
     *   改数据格式（`05` §3.2）。奇偶对 99% 的实际路径（图标、箭头、勾）结果相同；
     *   要"挖洞"就用两个反向子路径。
     * ★ **奇偶跨所有子路径一起算**：两个重叠矩形子路径，重叠区必须不填
     *   （`05` §12.2 #3）。逐个子路径填会让重叠区填两遍。
     *
     * ## 三个必须记住的口径
     *
     * ① **逐点吃画布偏移**：`translate()` 只改 PHP 侧 `offsetX/offsetY`，从不调
     *    后端（`05` §1.3）。所以每个点都要自己加偏移再乘 dpr。P4 踩过一次：
     *    几何走画布 `translate()` ⇒ 落在画布原点、被盒裁掉 ⇒ **真机完全无墨，
     *    而解释器全过**。
     * ② **无抗锯齿**：与 `drawRect` / `drawEllipse` 一致（硬边、像素中心谓词），
     *    三个形状在同一张图上边缘风格统一（`05` §3.3）。
     * ③ **宽度不乘 dpr**：与 `drawLine` / `drawEllipse` 同一口径（两个后端
     *    conformance 的统一约定）。路径坐标本身已在设备像素空间。
     *
     * ## 描边（`05` §3.4）
     *
     * 把路径偏移成环再填，**不是**沿路径逐段画粗线（接缝会重叠/有缝）：
     * · 开放路径：单环 = 一侧偏移正向 + 另一侧偏移反向，末端 Butt 直接闭
     * · 闭合路径：两条偏移环按行取**对称差**（内环被外环包住时即"外减内"）
     * 只做 Miter join + Butt cap；Round / Bevel 记在 §13。miter 超限按
     * miter-limit 截断（仍是 miter，只是不无限伸成尖刺）。
     * ★ 虚线退化为实线（与 `drawRect` 的 R2 降级同口径；真虚线只在 `drawLine`）。
     */
    public function drawPath(Path $path, Paint $paint): void
    {
        if ($paint->color->a === 0 || $path->isEmpty()) {
            return;
        }

        $subpaths = $this->flattenPath($path);
        if ($subpaths === []) {
            return;
        }

        if ($paint->isStroke()) {
            $this->strokeSubpaths($subpaths, $paint);
            return;
        }

        $edges = [];
        foreach ($subpaths as $sub) {
            foreach ($this->ringEdges($sub[0], $sub[1]) as $e) {
                $edges[] = $e;
            }
        }
        $this->fillEdges($edges, $paint->color, $paint->gradient);
    }

    /** 二次贝塞尔扁平化的固定段数（`05` §3.1；自适应扁平化记在 §13） */
    private const QUAD_SEGMENTS = 16;

    /** 扫描线成本上界：边数 × 行数（`05` §3.5） */
    private const SCANLINE_BUDGET = 200000;

    /** miter 接缝长度上界（单位为半宽；SVG 默认 miter-limit = 4） */
    private const MITER_LIMIT = 4.0;

    /**
     * 扁平化路径 → 子路径表（坐标已在**设备像素**空间）。
     *
     * @return list<array{list<array{float, float}>, bool}> [顶点表, 是否闭合]
     */
    private function flattenPath(Path $path): array
    {
        $subpaths = [];
        $current = [];
        $start = null;
        foreach ($path->commands() as $c) {
            $verb = (int) $c[0];

            if ($verb === Path::CMD_MOVE) {
                if ($current !== []) {
                    $subpaths[] = [$current, false];
                }
                $current = [[round($this->transformedX($c[1], $c[2])), round($this->transformedY($c[1], $c[2]))]];
                $start = $current[0];
                continue;
            }

            if ($verb === Path::CMD_LINE) {
                if ($current === []) {
                    continue; // 没有 MOVE 就打点：忽略（路径未开始）
                }
                $current[] = [round($this->transformedX($c[1], $c[2])), round($this->transformedY($c[1], $c[2]))];
                continue;
            }

            if ($verb === Path::CMD_QUAD) {
                if ($current === []) {
                    continue;
                }
                $p0 = $current[count($current) - 1];
                $cx = round($this->transformedX($c[1], $c[2]));
                $cy = round($this->transformedY($c[1], $c[2]));
                $px = round($this->transformedX($c[3], $c[4]));
                $py = round($this->transformedY($c[3], $c[4]));
                for ($i = 1; $i <= self::QUAD_SEGMENTS; $i++) {
                    $t = (float) $i / (float) self::QUAD_SEGMENTS;
                    $u = 1.0 - $t;
                    // 二次贝塞尔 B(t) = (1-t)²P0 + 2(1-t)tC + t²P
                    $current[] = [
                        $u * $u * $p0[0] + 2.0 * $u * $t * $cx + $t * $t * $px,
                        $u * $u * $p0[1] + 2.0 * $u * $t * $cy + $t * $t * $py,
                    ];
                }
                continue;
            }

            // CMD_CLOSE：只标记闭合，**不把首点接回来** —— 闭边由
            // `ringEdges($pts, true)` 补。接回来会让"首点重复一次"的环
            // 在偏移时被当成两个独立顶点（miter 退化成单边法向，角上出缺口）。
            if ($current !== []) {
                $subpaths[] = [$current, true];
                $current = [];
                $start = null;
            }
        }

        if ($current !== []) {
            $subpaths[] = [$current, false];
        }

        return $subpaths;
    }

    /**
     * 顶点表 → 线段表。`$closed` 为真时补上首尾相接的那条边。
     *
     * ★ 闭合是**显式参数**而不是"表里已经有重复首点"：偏移环（`offsetRing`）
     *   返回的是去重后的环，两种形状必须走同一条路，否则闭合环会少最后一条边
     *   ⇒ 奇偶交点落单 ⇒ **整个形状画不出来**（闭合描边第一版 0 墨）。
     *
     * @param list<array{float, float}> $points
     * @return list<array{float, float, float, float}> [x1,y1,x2,y2]
     */
    private function ringEdges(array $points, bool $closed = false): array
    {
        $edges = [];
        $n = count($points);
        if ($n < 2) {
            return $edges;
        }

        for ($i = 0; $i + 1 < $n; $i++) {
            $edges[] = [
                $points[$i][0], $points[$i][1],
                $points[$i + 1][0], $points[$i + 1][1],
            ];
        }
        if ($closed && $n > 2) {
            $edges[] = [
                $points[$n - 1][0], $points[$n - 1][1],
                $points[0][0], $points[0][1],
            ];
        }

        return $edges;
    }

    /**
     * 奇偶填充：所有子路径的边合在一起算交点（`05` §3.2 + §12.2 #3）。
     *
     * @param list<array{float, float, float, float}> $edges
     */
    private function fillEdges(array $edges, Color $color, ?Gradient $gradient): void
    {
        if ($edges === []) {
            return;
        }
        $color = $this->effectiveColor($color);

        $bounds = $this->edgeBounds($edges);
        $minY = $bounds[1];
        $maxY = $bounds[3];
        [$ya, $yb] = $this->rowRange($minY, $maxY);
        if ($ya >= $yb) {
            return;
        }

        $rows = $yb - $ya;
        $cost = count($edges) * $rows;
        if ($cost > self::SCANLINE_BUDGET) {
            Errors::report(sprintf(
                'drawPath 的扫描线成本超预算：%d 条边 × %d 行 = %d 次交点（上界 %d）。'
                    . "\n" . '修法：① 简化路径（贝塞尔已经按 %d 段扁平化）；'
                    . '② 改用 drawImage。Raster 是测试后端，不中止，但这一帧会明显变慢。',
                count($edges),
                $rows,
                $cost,
                self::SCANLINE_BUDGET,
                self::QUAD_SEGMENTS
            ), Errors::DIAGNOSTIC);
        }

        $opaque = $color->a >= 255;
        $lut = $opaque ? null : $this->blendLut($color);

        for ($y = $ya; $y < $yb; $y++) {
            foreach ($this->rowPixelSpans($edges, $y + 0.5) as $span) {
                $this->fillSpanOrGrad($y, $span[0], $span[1], $color, $opaque, $lut, $gradient);
            }
        }
    }

    /**
     * 一行的奇偶 span 表（已量化成像素区间、已裁到 clip 与画布）。
     *
     * ★ 这是**唯一**的扫描线实现：填充、开放描边、闭合描边、（W1/L3-2 的）
     *   `pushClipPath` 全都经过它 —— `05` §4.2 说的"一次实现两处用"。
     *
     * @param list<array{float, float, float, float}> $edges
     * @return list<array{int, int}> 左闭右开，已排序且互不相交
     */
    private function rowPixelSpans(array $edges, float $py): array
    {
        $xs = [];
        foreach ($edges as $e) {
            $lo = $e[1] < $e[3] ? $e[1] : $e[3];
            $hi = $e[1] < $e[3] ? $e[3] : $e[1];
            // ★ 半开区间 [lo, hi)：水平边（lo == hi）天然不产交点；
            //   相邻边共享的顶点也恰好被算一次（不重不漏）。
            if ($py < $lo || $py >= $hi) {
                continue;
            }
            $xs[] = $e[0] + ($py - $e[1]) * ($e[2] - $e[0]) / ($e[3] - $e[1]);
        }

        $n = count($xs);
        if ($n < 2) {
            return [];
        }
        sort($xs);

        $out = [];
        // 奇偶：排序后成对填；落单的那个丢弃（只可能是数值边界）
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $span = $this->spanToPixels($xs[$i], $xs[$i + 1]);
            if ($span[0] < $span[1]) {
                $out[] = $span;
            }
        }

        return $out;
    }

    /**
     * 一段浮点 x 区间 → 像素区间（左闭右开），并裁到 clip 与画布。
     *
     * 口径与 `rasterize()` 逐字相同：像素中心 px = x + 0.5，区间两端含。
     *
     * @return array{int, int}
     */
    private function spanToPixels(float $x0, float $x1): array
    {
        return [
            max((int) ceil($x0 - 0.5), (int) ceil($this->clip->left - 0.5), 0),
            min((int) floor($x1 - 0.5) + 1, (int) ceil($this->clip->right() - 0.5), $this->width),
        ];
    }

    /**
     * 行的可绘范围：几何包围盒 ∩ clip ∩ 画布。
     *
     * 口径与 `rasterize()` 逐字相同：像素中心 py = y + 0.5，两端含。
     *
     * @return array{int, int} 左闭右开；空范围时两个值相等
     */
    private function rowRange(float $minY, float $maxY): array
    {
        return [
            max((int) ceil($minY - 0.5), (int) ceil($this->clip->top - 0.5), 0),
            min((int) floor($maxY - 0.5) + 1, (int) ceil($this->clip->bottom() - 0.5), $this->height),
        ];
    }

    /**
     * @param list<array{float, float, float, float}> $edges
     * @return array{float, float, float, float} [minX, minY, maxX, maxY]
     */
    private function edgeBounds(array $edges): array
    {
        $minX = $edges[0][0];
        $minY = $edges[0][1];
        $maxX = $edges[0][2];
        $maxY = $edges[0][3];
        foreach ($edges as $e) {
            if ($e[0] < $minX) {
                $minX = $e[0];
            }
            if ($e[2] < $minX) {
                $minX = $e[2];
            }
            if ($e[0] > $maxX) {
                $maxX = $e[0];
            }
            if ($e[2] > $maxX) {
                $maxX = $e[2];
            }
            if ($e[1] < $minY) {
                $minY = $e[1];
            }
            if ($e[3] < $minY) {
                $minY = $e[3];
            }
            if ($e[1] > $maxY) {
                $maxY = $e[1];
            }
            if ($e[3] > $maxY) {
                $maxY = $e[3];
            }
        }

        return [$minX, $minY, $maxX, $maxY];
    }

    /**
     * 描边：把每个子路径偏移成环再填（`05` §3.4）。
     *
     * @param list<array{list<array{float, float}>, bool}> $subpaths
     */
    private function strokeSubpaths(array $subpaths, Paint $paint): void
    {
        $half = $paint->strokeWidth / 2.0;
        if ($half <= 0.0) {
            return;
        }

        foreach ($subpaths as $sub) {
            $pts = $sub[0];
            if (count($pts) < 2) {
                continue;
            }

            if ($sub[1]) {
                // 闭合：两条偏移环按行取对称差
                $this->fillRingsXor(
                    $this->offsetRing($pts, $half, true),
                    $this->offsetRing($pts, $half, false),
                    $paint
                );
                continue;
            }

            // 开放：单环 = 一侧偏移正向 + 另一侧偏移反向，末端 Butt 直接闭
            $ring = array_merge(
                $this->offsetOpen($pts, $half, true),
                array_reverse($this->offsetOpen($pts, $half, false))
            );
            $this->fillEdges($this->ringEdges($ring, true), $paint->color, $paint->gradient);
        }
    }

    /**
     * 闭合多边形的偏移环：每个顶点做 miter。
     *
     * ★ 先去掉尾部的重复点：`Path::close()` 会把首点接回来，于是环常以
     *   "首点重复一次"的形式给出。不去的话首/尾两个顶点各有一条**退化边**，
     *   miter 退化成"沿单边法向偏移"，角上会出现缺口（实测矩形描边少 13% 墨）。
     *
     * @param list<array{float, float}> $pts
     * @return list<array{float, float}>
     */
    private function offsetRing(array $pts, float $half, bool $left): array
    {
        $n = count($pts);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->miterPoint(
                $pts[($i - 1 + $n) % $n],
                $pts[$i],
                $pts[($i + 1) % $n],
                $half,
                $left
            );
        }

        return $out;
    }

    /**
     * 开放折线的偏移点列：首尾取所在边的法向，中间顶点做 miter。
     *
     * @param list<array{float, float}> $pts
     * @return list<array{float, float}>
     */
    private function offsetOpen(array $pts, float $half, bool $left): array
    {
        $n = count($pts);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === 0) {
                // 首点：沿**第一条边**的法向偏移（Butt cap 的起点）
                $out[] = $this->normalOffset($pts[0], $pts[1], $pts[0], $half, $left);
                continue;
            }
            if ($i === $n - 1) {
                // 尾点：沿**最后一条边**的法向偏移（Butt cap 的终点）
                $out[] = $this->normalOffset($pts[$n - 2], $pts[$n - 1], $pts[$n - 1], $half, $left);
                continue;
            }
            $out[] = $this->miterPoint($pts[$i - 1], $pts[$i], $pts[$i + 1], $half, $left);
        }

        return $out;
    }

    /**
     * 点 $p 沿**有向线段** $a→$b 的法向偏移 $half（$left 选左侧）。
     *
     * ★ 方向由 $a→$b 定，与"偏移哪个点"解耦 —— 否则首点要用反向边时只能把
     *   $a/$p 对调，左右侧会跟着翻（开放折线描边第一版就这么画空了）。
     *
     * @return array{float, float}
     */
    private function normalOffset(array $a, array $b, array $p, float $half, bool $left): array
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $len = sqrt($dx * $dx + $dy * $dy);
        if ($len <= 0.0) {
            return [$p[0], $p[1]];
        }
        $nx = -$dy / $len;
        $ny = $dx / $len;
        if (!$left) {
            $nx = -$nx;
            $ny = -$ny;
        }

        return [$p[0] + $nx * $half, $p[1] + $ny * $half];
    }

    /**
     * 顶点 $cur 的 miter 点：入射边与出射边各自偏移后的两条直线的交点。
     *
     * ★ miter 超限（接缝过长会伸成尖刺）时按 miter-limit 截断到
     *   `$half × MITER_LIMIT` —— 仍是 miter，只是不无限伸长。
     *
     * @return array{float, float}
     */
    private function miterPoint(array $prev, array $cur, array $next, float $half, bool $left): array
    {
        $d1x = $cur[0] - $prev[0];
        $d1y = $cur[1] - $prev[1];
        $d2x = $next[0] - $cur[0];
        $d2y = $next[1] - $cur[1];
        $l1 = sqrt($d1x * $d1x + $d1y * $d1y);
        $l2 = sqrt($d2x * $d2x + $d2y * $d2y);
        if ($l1 <= 0.0 || $l2 <= 0.0) {
            // 退化边：退回另一条边的法向
            return $l1 > 0.0
                ? $this->normalOffset($prev, $cur, $cur, $half, $left)
                : $this->normalOffset($cur, $next, $cur, $half, $left);
        }
        $d1x /= $l1;
        $d1y /= $l1;
        $d2x /= $l2;
        $d2y /= $l2;

        $s = $left ? 1.0 : -1.0;
        $p1x = $cur[0] - $d1y * $s * $half;
        $p1y = $cur[1] + $d1x * $s * $half;
        $p2x = $cur[0] - $d2y * $s * $half;
        $p2y = $cur[1] + $d2x * $s * $half;

        $denom = $d1x * $d2y - $d1y * $d2x;
        if (abs($denom) < 1e-9) {
            // 共线（或反向折）：两条偏移线平行，没有唯一交点
            return [$p2x, $p2y];
        }

        // P1 + t·d1 = P2 + s·d2 解 t
        $wx = $p2x - $p1x;
        $wy = $p2y - $p1y;
        $t = ($wx * $d2y - $wy * $d2x) / $denom;
        $mx = $p1x + $t * $d1x;
        $my = $p1y + $t * $d1y;

        $dx = $mx - $cur[0];
        $dy = $my - $cur[1];
        $dist = sqrt($dx * $dx + $dy * $dy);
        $limit = $half * self::MITER_LIMIT;
        if ($dist > $limit && $dist > 0.0) {
            $k = $limit / $dist;
            $mx = $cur[0] + $dx * $k;
            $my = $cur[1] + $dy * $k;
        }

        return [$mx, $my];
    }

    /**
     * 两条环按行取对称差后填（闭合路径描边用）。
     *
     * ★ 为什么是**对称差**而不是"外减内"：偏移环哪条在外取决于路径走向，
     *   而对称差与顺序无关 —— 内环被外环包住时它恰好就是"外减内"。
     * ★ 在**像素**层取对称差而不是浮点层：直接复用 `spanToPixels` 的量化口径，
     *   且区间表是整数，边界扫描退化成"排序去重 + 逐段判归属"。
     */
    private function fillRingsXor(array $ringA, array $ringB, Paint $paint): void
    {
        $edgesA = $this->ringEdges($ringA, true);
        $edgesB = $this->ringEdges($ringB, true);
        if ($edgesA === [] || $edgesB === []) {
            return;
        }

        $boundsA = $this->edgeBounds($edgesA);
        $minA = $boundsA[1];
        $maxA = $boundsA[3];
        $boundsB = $this->edgeBounds($edgesB);
        $minB = $boundsB[1];
        $maxB = $boundsB[3];
        [$ya, $yb] = $this->rowRange(min($minA, $minB), max($maxA, $maxB));
        if ($ya >= $yb) {
            return;
        }

        $color = $this->effectiveColor($paint->color);
        $opaque = $color->a >= 255;
        $lut = $opaque ? null : $this->blendLut($color);

        for ($y = $ya; $y < $yb; $y++) {
            $py = $y + 0.5;
            $sa = $this->rowPixelSpans($edgesA, $py);
            $sb = $this->rowPixelSpans($edgesB, $py);
            if ($sa === [] && $sb === []) {
                continue;
            }
            foreach ($this->xorPixelSpans($sa, $sb) as $span) {
                $this->fillSpanOrGrad($y, $span[0], $span[1], $color, $opaque, $lut, $paint->gradient);
            }
        }
    }

    /**
     * 两个像素区间集（各自已排序且不相交）的对称差。
     *
     * @param list<array{int, int}> $a
     * @param list<array{int, int}> $b
     * @return list<array{int, int}>
     */
    private function xorPixelSpans(array $a, array $b): array
    {
        if ($a === []) {
            return $b;
        }
        if ($b === []) {
            return $a;
        }

        $marks = [];
        foreach ($a as $iv) {
            $marks[$iv[0]] = true;
            $marks[$iv[1]] = true;
        }
        foreach ($b as $iv) {
            $marks[$iv[0]] = true;
            $marks[$iv[1]] = true;
        }
        $keys = array_keys($marks);
        sort($keys);

        $out = [];
        $n = count($keys);
        for ($i = 0; $i + 1 < $n; $i++) {
            $lo = (int) $keys[$i];
            $hi = (int) $keys[$i + 1];
            if ($lo >= $hi) {
                continue;
            }
            $inA = $this->spanHasPixel($a, $lo);
            $inB = $this->spanHasPixel($b, $lo);
            if ($inA === $inB) {
                continue; // 两侧都在（重叠）或都不在 ⇒ 不进对称差
            }
            if ($out !== [] && $out[count($out) - 1][1] === $lo) {
                $out[count($out) - 1][1] = $hi; // 与上一段相邻 ⇒ 合并
            } else {
                $out[] = [$lo, $hi];
            }
        }

        return $out;
    }

    /**
     * @param list<array{int, int}> $spans
     */
    private function spanHasPixel(array $spans, int $x): bool
    {
        foreach ($spans as $iv) {
            if ($x >= $iv[0] && $x < $iv[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * 图像：把 `ImageHandle` 的内存 RGBA 直接 blit 到设备像素。
     *
     * ★ **最近邻采样**，不是双线性 —— 这是**有意的双端差异**，理由要写清：
     *   Raster 是**测试后端**（§7.2 裁决 1），它的全部价值是"同一份命令流重放
     *   必然得到同一张位图"（G3）。最近邻是纯整数运算（一次除法建表、之后全是
     *   数组下标）⇒ 可复现、零浮点抖动；双线性要引入采样权重与浮点乘加，
     *   收益只在"放大看得顺眼"——而放大质量该由**真后端**负责（Skia 的
     *   `drawImageRect` 默认双线性）。
     *   ⇒ **1:1 时两后端逐字节相同**（双线性在像素中心取样时权重恰为 1/0），
     *   conformance 基线因此只钉 1:1；缩放只断"几何落位"，不断字节。
     *
     * ★ 源矩形**夹到图内**再算映射：`?Rect $src` 的 null = 整图；越界按 Skia
     *   `drawImageRect` 的口径夹紧（夹到 0 面积 ⇒ 不画，不报错）。
     *
     * ★ 采样表**每列/每行各算一次**：内层循环里绝不出现除法（一帧图像是
     *   O(像素) 次迭代，一次除法都不能有）。
     */
    public function drawImage(ImageHandle $image, ?Rect $src, Rect $dst): void
    {
        if ($image->isEmpty()) {
            return;
        }

        // ★ 显式 `(int)` 收窄：`$image->width` 是属性读取 ⇒ tpc 推成 `php::Var`，
        //   后面 `$ih - 1` 再赋回 `$sy`（已被推成 `php::Int`）会撞
        //   "Cannot assign php::Var to php::Int"，只有 T2 构建看得到。
        $iw = (int) $image->width;
        $ih = (int) $image->height;

        // ① 源区域（图像像素坐标，夹到图内）
        if ($src === null) {
            $sx0 = 0.0;
            $sy0 = 0.0;
            $sx1 = (float) $iw;
            $sy1 = (float) $ih;
        } else {
            $sx0 = max(0.0, $src->left);
            $sy0 = max(0.0, $src->top);
            $sx1 = min((float) $iw, $src->right());
            $sy1 = min((float) $ih, $src->bottom());
        }
        $sw = $sx1 - $sx0;
        $sh = $sy1 - $sy0;
        if ($sw <= 0.0 || $sh <= 0.0) {
            return;
        }

        // ② 目标区域（设备像素；逻辑 → 物理只走 toDevice 这一个量化点）
        $device = $this->toDevice($dst);
        $dx0 = (int) round($device->left);
        $dy0 = (int) round($device->top);
        $dw = (int) round($device->width);
        $dh = (int) round($device->height);
        if ($dw <= 0 || $dh <= 0) {
            return;
        }

        // ③ 夹到画布 + 矩形裁剪（路径裁剪在像素层另算，见 ⑤）
        $x0 = max($dx0, 0, (int) $this->clip->left);
        $x1 = min($dx0 + $dw, $this->width, (int) $this->clip->right());
        $y0 = max($dy0, 0, (int) $this->clip->top);
        $y1 = min($dy0 + $dh, $this->height, (int) $this->clip->bottom());
        if ($x0 >= $x1 || $y0 >= $y1) {
            return;
        }

        // ④ 采样表：目标像素中心反算源像素下标（夹到源区域内，防越界）
        //   ★ 用 if 不用嵌套三元：tpc 对 `?:` 的 codegen 在"两支类型不同 / 嵌套"
        //     时会撞 C2678，只有 T2 构建看得到（`08` 的 AOT 三坑之一）。
        $colSrc = [];
        for ($x = $x0; $x < $x1; $x++) {
            $sx = (int) ($sx0 + ($x - $dx0 + 0.5) / $dw * $sw);
            if ($sx >= $iw) {
                $sx = $iw - 1;
            }
            if ($sx < 0) {
                $sx = 0;
            }
            $colSrc[$x - $x0] = $sx;
        }
        $rowSrc = [];
        for ($y = $y0; $y < $y1; $y++) {
            $sy = (int) ($sy0 + ($y - $dy0 + 0.5) / $dh * $sh);
            if ($sy >= $ih) {
                $sy = $ih - 1;
            }
            if ($sy < 0) {
                $sy = 0;
            }
            $rowSrc[$y - $y0] = $sy;
        }

        // ⑤ 层不透明度：与 effectiveColor 同口径（乘到**源 alpha** 上，不改 RGB
        //    —— 改 RGB 会把"半透明覆盖"变成"变暗"，与 Skia 的离屏合成不同色）
        $factor = $this->layerAlpha === [] ? 1.0 : $this->layerAlpha[count($this->layerAlpha) - 1];

        $rgba = $image->rgba();
        $stride = $iw * 4;
        $canvasW = $this->width;

        // ★ foreach 的键值变量必须换名（`$srcY` 而不是 `$sy`）：tpc 已把 `$sy`
        //   推成 `php::Int`（上一段 for 循环），再让 foreach 往它写 `php::Var`
        //   会撞 "Cannot assign php::Var to php::Int"，只有 T2 看得到。
        foreach ($rowSrc as $rowIdx => $srcY) {
            $y = $y0 + $rowIdx;
            $rowSpans = $this->clipRowSpansForRow($y);
            if ($rowSpans === []) {
                continue; // 该行被路径裁光
            }
            $srcRow = $srcY * $stride;
            foreach ($colSrc as $colIdx => $srcX) {
                $x = $x0 + $colIdx;
                if ($rowSpans !== null && !$this->spanHasPixel($rowSpans, $x)) {
                    continue;
                }

                $si = $srcRow + $srcX * 4;
                $sa = ord($rgba[$si + 3]);
                if ($sa === 0) {
                    continue;
                }
                if ($factor < 1.0) {
                    $sa = (int) round($sa * $factor);
                    if ($sa <= 0) {
                        continue;
                    }
                }

                // source-over，与 blend() 逐字同式（只是省掉 Color 对象）
                $p = ($y * $canvasW + $x) * 4;
                if ($sa === 255) {
                    $this->pixels[$p] = $rgba[$si];
                    $this->pixels[$p + 1] = $rgba[$si + 1];
                    $this->pixels[$p + 2] = $rgba[$si + 2];
                    $this->pixels[$p + 3] = "\xFF";
                    continue;
                }
                $inverse = 255 - $sa;
                $this->pixels[$p] = chr((int) round((ord($rgba[$si]) * $sa + ord($this->pixels[$p]) * $inverse) / 255));
                $this->pixels[$p + 1] = chr((int) round((ord($rgba[$si + 1]) * $sa + ord($this->pixels[$p + 1]) * $inverse) / 255));
                $this->pixels[$p + 2] = chr((int) round((ord($rgba[$si + 2]) * $sa + ord($this->pixels[$p + 2]) * $inverse) / 255));
                $this->pixels[$p + 3] = chr(min(255, $sa + (int) round(ord($this->pixels[$p + 3]) * $inverse / 255)));
            }
        }
    }

    public function drawShadow(Shadow $shadow): void
    {
        $this->unsupportedOp('drawShadow', 'Optional');
    }

    /**
     * 压入层（`05` §5.6）：**alpha 乘数栈**，不是真离屏。
     *
     * 栈顶乘数随每个 draw 的颜色 alpha 一起算下去（`effectiveColor`）；
     * 嵌套时乘数相乘 ⇒ "层内再开层"的语义与真离屏一致。
     *
     * ★ 与 Skia 的真离屏只差一处：**层内重叠子各乘一次**（Skia 是先合成再乘
     *   一次）⇒ Raster 上重叠处更透。Extended 层的有意近似（内存零开销 vs
     *   一块 1080p 的 `array<int,int>`），smoke 夹具 #9 把差异钉住。
     */
    public function pushLayer(float $opacity): void
    {
        $top = $this->layerAlpha === [] ? 1.0 : $this->layerAlpha[count($this->layerAlpha) - 1];
        $this->layerAlpha[] = $top * max(0.0, min(1.0, $opacity));
    }

    public function popLayer(): void
    {
        if ($this->layerAlpha === []) {
            return; // 未配对：W8 的 layerDepth 断言在录制侧抓（§1.7）
        }
        array_pop($this->layerAlpha);
    }

    /**
     * 层不透明度栈作用到颜色上（`05` §5.6）。
     *
     * 空栈 / 栈顶 >= 1 时原样返回（快路：绝大多数帧没有层，不造新 Color）。
     */
    private function effectiveColor(Color $color): Color
    {
        if ($this->layerAlpha === []) {
            return $color;
        }
        $factor = $this->layerAlpha[count($this->layerAlpha) - 1];
        if ($factor >= 1.0) {
            return $color;
        }

        return new Color($color->r, $color->g, $color->b, (int) round($color->a * $factor));
    }

    /**
     * 模糊背景（毛玻璃底，Extended）—— **Raster 不做模糊**。
     *
     * 这是 `CAP_BACKDROP` 这一位"置位但近似"的全部内容：调用合法、不报错、
     * 一个像素都不改。效果 = 毛玻璃面板退化成半透明面板（结构与透明度照常，
     * 只少一层糊）。
     *
     * ★ 为什么不响亮失败（对比 `drawShadow`）：模糊是**质感**，不是层次信息。
     *   缺了它卡片仍然读得出"浮在内容之上"；而缺了阴影，浮起关系就丢了。
     *   所以阴影是 Optional（Raster 报 E-OP-UNSUPPORTED），模糊是 Extended。
     * ★ 为什么不实现真模糊：软光栅要在 PHP 里对整块面板做可分离卷积
     *   （1080p 面板 ~2M 像素 × 2 遍 × ~17 抽头）—— 每帧几十毫秒，而它只服务
     *   测试后端。金色基线本来也不覆盖模糊质量。
     */
    public function blurBackdrop(float $sigma, Rect $rect): void
    {
    }

    /**
     * 压入路径裁剪（`05` §4）：每行 x 区间掩码。
     *
     * 与 `pushClip(Rect)` 的差别只在"裁什么形状"：矩形是 O(1) 粗筛（`$clip`
     * 求交），路径是 O(行数) 精筛（行掩码；第 ④ 步之后每像素 O(1)）。两者
     * 叠加 = 求交；弹栈都用 `popClip()`（`Canvas` 接口契约，与 Skia 的
     * `save/restore` 同形态）。
     *
     * ★ 空路径 = 裁剪区为空（后面全被裁），但**仍然压栈**：录制侧无条件录
     *   `OP_PUSH_CLIP_PATH`（W8 的 `clipDepth` 同口径计深），光栅侧不压栈的
     *   话 `popClip` 会多弹一层、把外层的裁剪弹掉。
     */
    public function pushClipPath(Path $path): void
    {
        $edges = [];
        foreach ($this->flattenPath($path) as $sub) {
            foreach ($this->ringEdges($sub[0], $sub[1]) as $e) {
                $edges[] = $e;
            }
        }

        $previousClip = $this->clip;
        $previousRows = $this->clipRows;

        if ($edges === []) {
            $this->clipRows = [];
        } else {
            $bounds = $this->edgeBounds($edges);
            // 粗筛：clip 缩到路径包围盒（行掩码随后做精筛）。O(1)，收益是
            // "路径完全在裁剪区外 ⇒ rowRange 直接空"（`05` §10 #4）。
            $this->clip = $this->clip->intersect(
                new Rect($bounds[0], $bounds[1], $bounds[2] - $bounds[0], $bounds[3] - $bounds[1])
            );
            $this->clipRows = $this->buildClipRows($edges);
        }

        $this->clipStack[] = [$previousClip, $previousRows];
    }

    /**
     * 压入路径裁剪时构建行掩码（`05` §4.2 第 ② 步）。
     *
     * ★ 与**已有的**行掩码按行求交：只把 `$clip` 缩到包围盒是不够的 ——
     *   "外圆内大矩形"的场景，矩形的包围盒覆盖整个圆 ⇒ 外圆的约束被丢掉。
     *   嵌套语义（夹具 #6）要求两个裁剪逐行求交。
     * ★ 连续相同行合并成一个条目：圆/菱形每行 spans 都变、合并省不了多少，
     *   但"横条裁切"这类上下同形的场景能省一半条目。
     *
     * @param list<array{float, float, float, float}> $edges
     * @return list<array{int, int, list<array{int, int}>}>
     */
    private function buildClipRows(array $edges): array
    {
        $bounds = $this->edgeBounds($edges);
        [$ya, $yb] = $this->rowRange($bounds[1], $bounds[3]);

        $rows = [];
        for ($y = $ya; $y < $yb; $y++) {
            $spans = $this->rowPixelSpans($edges, $y + 0.5);
            if ($this->clipRows !== null) {
                $spans = $this->intersectSpans($spans, $this->clipRowSpansForRow($y));
            }
            if ($spans === []) {
                continue;
            }
            $last = count($rows) - 1;
            if ($last >= 0 && $rows[$last][1] === $y && $rows[$last][2] === $spans) {
                $rows[$last][1] = $y + 1;
                continue;
            }
            $rows[] = [$y, $y + 1, $spans];
        }

        return $rows;
    }

    /**
     * 两个已排序、互不相交的像素区间表求交（`05` §4.2 的嵌套语义）。
     *
     * @param list<array{int, int}> $a
     * @param list<array{int, int}> $b
     * @return list<array{int, int}>
     */
    private function intersectSpans(array $a, array $b): array
    {
        $out = [];
        $i = 0;
        $j = 0;
        $na = count($a);
        $nb = count($b);
        while ($i < $na && $j < $nb) {
            $lo = max($a[$i][0], $b[$j][0]);
            $hi = min($a[$i][1], $b[$j][1]);
            if ($lo < $hi) {
                $out[] = [$lo, $hi];
            }
            if ($a[$i][1] <= $b[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $out;
    }

    /**
     * 一行上的路径裁剪区间（像素、左闭右开、已排序不相交）。
     *
     * @return ?array null = 没有路径裁剪（整行放行）；[] = 整行被裁
     */
    private function clipRowSpansForRow(int $y): ?array
    {
        if ($this->clipRows === null) {
            return null;
        }

        // 二分：条目按 y0 升序，行 y ∈ [y0, y1)
        $rows = $this->clipRows;
        $lo = 0;
        $hi = count($rows) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $entry = $rows[$mid];
            if ($y < $entry[0]) {
                $hi = $mid - 1;
            } elseif ($y >= $entry[1]) {
                $lo = $mid + 1;
            } else {
                return $entry[2];
            }
        }

        return [];
    }

    private function unsupportedOp(string $op, string $tier): void
    {
        Errors::report(CanvasCapabilities::unsupportedMessage($op, 'RasterCanvas', $tier));
    }
    private function toDevice(Rect $rect): Rect
    {
        $t = $this->transform;
        if ($t !== null) {
            // 矩阵模式：轴对齐外接框（旋转矩形的按行扫描无法直接表达，见类注释的降级说明）
            return $this->scale->quantizeRect($t->applyRect($rect));
        }

        return $this->scale->deviceRect($rect, $this->offsetX, $this->offsetY);
    }

    /** 覆盖位图按 source-over 混合（覆盖率即 alpha） */
    private function blendBitmap(GlyphBitmap $bitmap, Color $color, float $penX, float $baselineY): void
    {
        $color = $this->effectiveColor($color);
        // 位图已在设备像素空间：`bearingX/Y` 是相对笔位的偏移
        $left = (int) round($penX * $this->scale->dpr) + $bitmap->bearingX;
        $top = (int) round($baselineY * $this->scale->dpr) + $bitmap->bearingY;

        $clipLeft = (int) $this->clip->left;
        $clipRight = (int) $this->clip->right();
        $clipTop = (int) $this->clip->top;
        $clipBottom = (int) $this->clip->bottom();

        for ($by = 0; $by < $bitmap->height; $by++) {
            $y = $top + $by;
            if ($y < 0 || $y >= $this->height || $y < $clipTop || $y >= $clipBottom) {
                continue;
            }
            // ★ 路径裁剪也裁文字（与 Skia 的 clipPath 同口径，能力等价）：
            //   一行查一次掩码，行内逐像素判。无路径裁剪时是 null，只多一次
            //   调用/行 —— 文字是热点路径，不能每像素查。
            $rowSpans = $this->clipRowSpansForRow($y);
            for ($bx = 0; $bx < $bitmap->width; $bx++) {
                $x = $left + $bx;
                if ($x < 0 || $x >= $this->width || $x < $clipLeft || $x >= $clipRight) {
                    continue;
                }
                if ($rowSpans !== null && !$this->spanHasPixel($rowSpans, $x)) {
                    continue;
                }
                $coverage = $bitmap->coverageAt($bx, $by);
                if ($coverage === 0) {
                    continue;
                }
                $this->blend($x, $y, $color, $coverage);
            }
        }
    }

    private function rasterize(Rect $rect, float $radius, Color $color, ?Rect $hole, float $holeRadius, ?Gradient $gradient = null): void
    {
        $color = $this->effectiveColor($color);
        if ($color->a === 0) {
            return;
        }

        // ★ 全部区间换算成**整数像素**，与旧的"每像素判谓词"逐点等价：
        //   · `insideRoundRect` 的矩形门是**两端含**（`px >= left && px <= right`）
        //     ⇒ 区间 [ceil(left-0.5), floor(right-0.5)+1)
        //   · `clip` 是**左闭右开**（`px >= left && px < right`）
        //     ⇒ 区间 [ceil(left-0.5), ceil(right-0.5))
        //   px = x + 0.5 是像素中心。含等号的一侧在 .5 恰好对齐时也一致。
        $rx0 = (int) ceil($rect->left - 0.5);
        $rx1 = (int) floor($rect->right() - 0.5) + 1;
        $ry0 = (int) ceil($rect->top - 0.5);
        $ry1 = (int) floor($rect->bottom() - 0.5) + 1;

        $xa = max($rx0, (int) ceil($this->clip->left - 0.5), 0);
        $xb = min($rx1, (int) ceil($this->clip->right() - 0.5), $this->width);
        $ya = max($ry0, (int) ceil($this->clip->top - 0.5), 0);
        $yb = min($ry1, (int) ceil($this->clip->bottom() - 0.5), $this->height);
        if ($xa >= $xb || $ya >= $yb) {
            return;
        }

        $opaque = $color->a >= 255;
        $lut = $opaque ? null : $this->blendLut($color);

        for ($y = $ya; $y < $yb; $y++) {
            $py = $y + 0.5;

            // 圆角的行内区间：中部行整段在内；上下边带行从两端向内扫
            // （谓词仍走 insideRoundRect 逐点判 ⇒ 与旧实现同一个字节；
            //   扫描最多 radius 个像素，远小于整行宽度）。
            $x0 = $xa;
            $x1 = $xb;
            if ($radius > 0.0 && ($py < $rect->top + $radius || $py > $rect->bottom() - $radius)) {
                while ($x0 < $x1 && !$this->insideRoundRect($x0 + 0.5, $py, $rect, $radius)) {
                    $x0++;
                }
                while ($x1 > $x0 && !$this->insideRoundRect($x1 - 0.5, $py, $rect, $radius)) {
                    $x1--;
                }
            }
            if ($x0 >= $x1) {
                continue;
            }

            // 描边的洞：当前行落在洞的纵向范围内时，才做横向挖空（挖掉洞的那一段
            // 剩左右两段）；当前行在洞之上/之下（顶/底边带）⇒ 整行都是墨（顶/底
            // 边要完整画出，不能反过来被洞的 x 区间吃掉）。
            // ★ 修复：旧实现对**每一行**都横向挖掉洞的 x 区间，无视行是否在洞的纵向
            //   范围内 ⇒ 矩形描边的顶/底边整条被吞掉，只剩左右两条竖边。P3 的
            //   像素断言首次戳中；golden prims 的描边矩形旧基线即此坏输出，已重基线。
            if ($hole !== null && $py >= $hole->top && $py < $hole->bottom()) {
                $h0 = max($x0, (int) ceil($hole->left - 0.5));
                $h1 = min($x1, (int) floor($hole->right() - 0.5) + 1);
                if ($h0 < $h1 && $holeRadius > 0.0
                    && ($py < $hole->top + $holeRadius || $py > $hole->bottom() - $holeRadius)
                ) {
                    while ($h0 < $h1 && !$this->insideRoundRect($h0 + 0.5, $py, $hole, $holeRadius)) {
                        $h0++;
                    }
                    while ($h1 > $h0 && !$this->insideRoundRect($h1 - 0.5, $py, $hole, $holeRadius)) {
                        $h1--;
                    }
                }
                if ($h0 < $h1) {
                    $this->fillSpanOrGrad($y, $x0, $h0, $color, $opaque, $lut, $gradient);
                    $this->fillSpanOrGrad($y, $h1, $x1, $color, $opaque, $lut, $gradient);
                } else {
                    // 本行完全在洞里（或洞比墨区大）⇒ 整行都是墨
                    $this->fillSpanOrGrad($y, $x0, $x1, $color, $opaque, $lut, $gradient);
                }
            } else {
                // 无洞 / 当前行在洞纵向范围之外（顶/底边缘带）⇒ 整行墨
                $this->fillSpanOrGrad($y, $x0, $x1, $color, $opaque, $lut, $gradient);
            }
            continue;
        }
    }

    /**
     * 一段水平像素 run：有渐变走逐像素插值，否则走常量色快速路径。
     */
    private function fillSpanOrGrad(int $y, int $xa, int $xb, Color $color, bool $opaque, ?array $lut, ?Gradient $gradient): void
    {
        // ★ 路径裁剪的行掩码在这里统一施加 —— 它是**所有**填充的最终出口
        //   （`rasterize` 的矩形/圆角/描边洞、`fillEdges` 的扫描线全经过它），
        //   一个注入点覆盖整条绘制路径。
        // ★ 无路径裁剪（`null`）时**整段直画**：原先每行每段都要过 `clipRow()`，
        //   而无裁剪时它返回 `[[$lo, $hi]]` —— 每行新分配 1 个数组 + 1 个内数组
        //   （全屏矩形 ≈ 1080 行）。`analysis/17` §2.1 点名的就是这个。
        // ★ `[]`（有掩码但本行不在任何裁剪区间）与 `null` 语义不同：前者是"这一行
        //   整段不可画"，落到下面的 `foreach` 自然零次迭代。
        $spans = $this->clipRowSpansForRow($y);

        if ($spans === null) {
            if ($gradient === null) {
                $this->fillSpan($y, $xa, $xb, $color, $opaque, $lut);

                return;
            }

            $this->fillSpanGrad($y, $xa, $xb, $gradient);

            return;
        }

        foreach ($spans as $s) {
            $a = max($xa, $s[0]);
            $b = min($xb, $s[1]);

            if ($a >= $b) {
                continue;
            }

            if ($gradient === null) {
                $this->fillSpan($y, $a, $b, $color, $opaque, $lut);

                continue;
            }

            $this->fillSpanGrad($y, $a, $b, $gradient);
        }
    }

    /** 渐变段：逐像素按位置取色（source-over 混合，半透明自然过渡） */
    private function fillSpanGrad(int $y, int $x0, int $x1, Gradient $g): void
    {
        $py = (float) ($y + 0.5);
        for ($x = $x0; $x < $x1; $x++) {
            $this->blend($x, $y, $this->gradientColorAt($g, (float) ($x + 0.5), $py));
        }
    }

    /**
     * 渐变采样：设备像素 (px,py) → 颜色。
     *
     * ★ 渐变端点/圆心是**逻辑坐标**（与形状同空间），转设备空间 = (logical + offset) × dpr
     *   —— 与 `toDevice()` 同一变换（只是不量化，渐变要连续）。逐像素采样即正确渐变，
     *   比 R2 允许的"分带"更平滑。
     */
    private function gradientColorAt(Gradient $g, float $px, float $py): Color
    {
        $dpr = $this->scale->dpr;

        if ($g->isRadial()) {
            $cx = $this->transformedX($g->x1, $g->y1);
            $cy = $this->transformedY($g->x1, $g->y1);
            $radius = max($g->x2 * $dpr, 1e-6);
            $dx = $px - $cx;
            $dy = $py - $cy;
            $t = sqrt($dx * $dx + $dy * $dy) / $radius;
        } else {
            $x1 = $this->transformedX($g->x1, $g->y1);
            $y1 = $this->transformedY($g->x1, $g->y1);
            $x2 = $this->transformedX($g->x2, $g->y2);
            $y2 = $this->transformedY($g->x2, $g->y2);
            $ex = $x2 - $x1;
            $ey = $y2 - $y1;
            $len2 = $ex * $ex + $ey * $ey;
            $t = $len2 <= 0.0 ? 0.0 : (($px - $x1) * $ex + ($py - $y1) * $ey) / $len2;
        }

        if ($t < 0.0) {
            $t = 0.0;
        } elseif ($t > 1.0) {
            $t = 1.0;
        }

        $colors = $g->colors;
        $stops = $g->stops;
        $n = count($colors);

        if ($stops === []) {
            if ($n === 1) {
                return $colors[0];
            }
            $seg = $t * ($n - 1);
            $i = (int) $seg;
            if ($i >= $n - 1) {
                return $colors[$n - 1];
            }
            return Color::lerp($colors[$i], $colors[$i + 1], $seg - $i);
        }

        if ($t <= $stops[0]) {
            return $colors[0];
        }
        if ($t >= $stops[$n - 1]) {
            return $colors[$n - 1];
        }
        for ($i = 1; $i < $n; $i++) {
            if ($t <= $stops[$i]) {
                $a = $stops[$i - 1];
                $b = $stops[$i];
                $f = $b > $a ? ($t - $a) / ($b - $a) : 0.0;

                return Color::lerp($colors[$i - 1], $colors[$i], $f);
            }
        }

        return $colors[$n - 1];
    }

    /** 写一段水平像素 run（同一行、同一颜色；opaque 逐像素原地写，半透明查表逐像素） */
    private function fillSpan(int $y, int $xa, int $xb, Color $color, bool $opaque, ?array $lut): void
    {
        if ($xa >= $xb) {
            return;
        }

        // 不透明：预计算四个单字节串，逐像素原地写（字符串偏移写在 refcount==1 时
        // 是 in-place 的 O(1)）。★ 试过"整行一次 substr_replace"—— 它对整块像素串
        // 是 O(整串) 拷贝（900×640×4 = 2.3MB/行）⇒ 全幅矩形反而慢一倍，已回退。
        if ($opaque) {
            $key = $color->r . ',' . $color->g . ',' . $color->b;
            $chunk = $this->opaqueChunks[$key] ?? null;
            if ($chunk === null) {
                if (count($this->opaqueChunks) >= self::CACHE_MAX) {
                    $this->opaqueChunks = [];
                }
                $chunk = [chr($color->r), chr($color->g), chr($color->b), "\xFF"];
                $this->opaqueChunks[$key] = $chunk;
            }
            [$c0, $c1, $c2, $c3] = $chunk;

            $base = $y * $this->width;
            for ($x = $xa; $x < $xb; $x++) {
                $i = ($base + $x) * 4;
                $this->pixels[$i] = $c0;
                $this->pixels[$i + 1] = $c1;
                $this->pixels[$i + 2] = $c2;
                $this->pixels[$i + 3] = $c3;
            }

            return;
        }

        // 半透明：目标字节预混合表（alpha 在本 span 内恒定 ⇒ 每像素 4 次查表）
        $base = $y * $this->width;
        for ($x = $xa; $x < $xb; $x++) {
            $i = ($base + $x) * 4;
            $this->pixels[$i] = $lut[0][ord($this->pixels[$i])];
            $this->pixels[$i + 1] = $lut[1][ord($this->pixels[$i + 1])];
            $this->pixels[$i + 2] = $lut[2][ord($this->pixels[$i + 2])];
            $this->pixels[$i + 3] = $lut[3][ord($this->pixels[$i + 3])];
        }
    }

    /**
     * 半透明填充的预混合表：四条 256 项目标字节。
     *
     * 公式与 `blend()` 逐字节相同（`(int) round((c*alpha + old*(255-alpha)) / 255)`）
     * ⇒ 输出与旧逐像素实现完全一致。
     *
     * @return array<int, array<int, string>>
     */
    private function blendLut(Color $color): array
    {
        $key = $color->r . ',' . $color->g . ',' . $color->b . ',' . $color->a;
        $lut = $this->blendLuts[$key] ?? null;
        if ($lut !== null) {
            return $lut;
        }

        $inverse = 255 - $color->a;
        $tables = [];
        foreach ([$color->r, $color->g, $color->b] as $channel) {
            $table = [];
            for ($old = 0; $old < 256; $old++) {
                $table[$old] = chr((int) round(($channel * $color->a + $old * $inverse) / 255));
            }
            $tables[] = $table;
        }
        // alpha 通道：dst_a = min(255, alpha + round(old_a * inverse / 255))
        $alphaTable = [];
        for ($old = 0; $old < 256; $old++) {
            $alphaTable[$old] = chr(min(255, $color->a + (int) round($old * $inverse / 255)));
        }
        $tables[] = $alphaTable;

        if (count($this->blendLuts) >= self::CACHE_MAX) {
            $this->blendLuts = [];
        }
        $this->blendLuts[$key] = $tables;

        return $tables;
    }

    private function insideRoundRect(float $px, float $py, Rect $rect, float $radius): bool
    {
        if ($px < $rect->left || $px > $rect->right() || $py < $rect->top || $py > $rect->bottom()) {
            return false;
        }
        if ($radius <= 0.0) {
            return true;
        }

        $radius = min($radius, min($rect->width, $rect->height) / 2.0);
        $cx = min(max($px, $rect->left + $radius), $rect->right() - $radius);
        $cy = min(max($py, $rect->top + $radius), $rect->bottom() - $radius);
        $dx = $px - $cx;
        $dy = $py - $cy;

        return $dx * $dx + $dy * $dy <= $radius * $radius;
    }

    /**
     * source-over 混合。
     *
     * `$coverage` 是字形位图的覆盖度（0..255）：`alpha = 颜色 alpha × 覆盖率`，
     * 于是**抗锯齿的边缘**与"半透明色"走同一条混合路径，不另开一条。
     */
    private function blend(int $x, int $y, Color $color, int $coverage = 255): void
    {
        $i = ($y * $this->width + $x) * 4;
        $alpha = (int) round($color->a * $coverage / 255);
        if ($alpha <= 0) {
            return;
        }

        if ($alpha === 255) {
            $this->pixels[$i] = chr($color->r);
            $this->pixels[$i + 1] = chr($color->g);
            $this->pixels[$i + 2] = chr($color->b);
            $this->pixels[$i + 3] = "\xFF";
            return;
        }

        $inverse = 255 - $alpha;
        $this->pixels[$i] = chr((int) round(($color->r * $alpha + ord($this->pixels[$i]) * $inverse) / 255));
        $this->pixels[$i + 1] = chr((int) round(($color->g * $alpha + ord($this->pixels[$i + 1]) * $inverse) / 255));
        $this->pixels[$i + 2] = chr((int) round(($color->b * $alpha + ord($this->pixels[$i + 2]) * $inverse) / 255));
        $this->pixels[$i + 3] = chr(min(255, $alpha + (int) round(ord($this->pixels[$i + 3]) * $inverse / 255)));
    }
}
