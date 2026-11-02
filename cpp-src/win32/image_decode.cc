#include "image_decode.h"

#include <windows.h>
#include <wincodec.h>
#include <objbase.h>
#include <vector>

#pragma comment(lib, "windowscodecs.lib")   // IWICImagingFactory（系统组件，零体积增量）

namespace drift {
namespace win32 {

namespace {

/**
 * RAII 的 COM 初始化。
 *
 * ★ **`CoInitializeEx` 可能返回 `RPC_E_CHANGED_MODE`** —— 那不是失败，是"这个线程
 *   已经以另一种 apartment 初始化过"（UI 线程通常是 STA，worker 线程可能是 MTA）。
 *   WIC 在两种 apartment 下都能用，所以这个返回值**必须当成成功**。
 *
 * ★ 只有拿到 `S_OK` 才 `CoUninitialize()`：`S_FALSE`（已初始化过）与
 *   `RPC_E_CHANGED_MODE` 都不该由我们配对减计数 —— 配错了会把**别人的**
 *   COM 初始化减掉，症状是后续某个 COM 调用莫名其妙失败。
 */
class ComScope
{
public:
    ComScope()
    {
        const HRESULT hr = ::CoInitializeEx(NULL, COINIT_APARTMENTTHREADED);
        owns_ = SUCCEEDED(hr) && hr != S_FALSE;
        ok_ = SUCCEEDED(hr) || hr == RPC_E_CHANGED_MODE;
    }

    ~ComScope()
    {
        if (owns_) {
            ::CoUninitialize();
        }
    }

    ComScope(const ComScope &) = delete;
    ComScope &operator=(const ComScope &) = delete;

    bool ok() const { return ok_; }

private:
    bool owns_ = false;
    bool ok_ = false;
};

/** 最小的 COM 智能指针（不引 `Microsoft::WRL`，那个头会拖进一堆模板） */
template <typename T>
class ComPtr
{
public:
    ComPtr() = default;
    ~ComPtr()
    {
        if (ptr_ != NULL) {
            ptr_->Release();
        }
    }

    ComPtr(const ComPtr &) = delete;
    ComPtr &operator=(const ComPtr &) = delete;

    T **put()
    {
        release();
        return &ptr_;
    }

    T *get() const { return ptr_; }

    void release()
    {
        if (ptr_ != NULL) {
            ptr_->Release();
            ptr_ = NULL;
        }
    }

private:
    T *ptr_ = NULL;
};

}  // namespace

bool decodeImageToRgba(const std::wstring &path, unsigned int &outWidth, unsigned int &outHeight, std::string &outRgba)
{
    if (path.empty()) {
        return false;
    }

    ComScope com;
    if (!com.ok()) {
        return false;
    }

    ComPtr<IWICImagingFactory> factory;
    if (FAILED(::CoCreateInstance(
            CLSID_WICImagingFactory,
            NULL,
            CLSCTX_INPROC_SERVER,
            IID_PPV_ARGS(factory.put())))) {
        return false;
    }

    ComPtr<IWICBitmapDecoder> decoder;
    // ★ `WICDecodeMetadataCacheOnLoad`：不缓存元数据 ⇒ 少一次内存占用。
    //   本仓只解一帧、解完就丢，缓存没有任何收益。
    if (FAILED(factory.get()->CreateDecoderFromFilename(
            path.c_str(),
            NULL,
            GENERIC_READ,
            WICDecodeMetadataCacheOnLoad,
            decoder.put()))) {
        return false;   // 文件不存在 / 不是图片 / 格式没装解码器
    }

    ComPtr<IWICBitmapFrameDecode> frame;
    if (FAILED(decoder.get()->GetFrame(0, frame.put()))) {
        return false;   // 一张图都没有（损坏的容器）
    }

    ComPtr<IWICFormatConverter> converter;
    if (FAILED(factory.get()->CreateFormatConverter(converter.put()))) {
        return false;
    }

    // ★ 统一转到 32bppRGBA：源图可能是 24bppBGR / 索引色 / 16 位灰度 / 带 alpha 的
    //   PNG。不转的话 `CopyPixels` 出来的行跨度和通道顺序都得逐格式判断 ——
    //   那是把"格式矩阵"搬进本文件，而 WIC 已经把它做完了。
    if (FAILED(converter.get()->Initialize(
            frame.get(),
            GUID_WICPixelFormat32bppRGBA,
            WICBitmapDitherTypeNone,
            NULL,
            0.0,
            WICBitmapPaletteTypeCustom))) {
        return false;
    }

    UINT width = 0;
    UINT height = 0;
    if (FAILED(converter.get()->GetSize(&width, &height)) || width == 0 || height == 0) {
        return false;
    }

    // ★ 上限保护：一张 20000×20000 的图是 1.6 GB。这里不是"猜一个业务上限"，
    //   而是**乘法的溢出边界**：`width * 4 * height` 在 32 位 UINT 上会绕回来，
    //   绕回来的结果是"分配一个很小的缓冲，然后越界写"。判在乘法之前。
    const unsigned long long stride = static_cast<unsigned long long>(width) * 4ULL;
    const unsigned long long total = stride * static_cast<unsigned long long>(height);
    if (total > 0x7FFFFFFFULL) {
        return false;
    }

    outRgba.resize(static_cast<size_t>(total));
    if (FAILED(converter.get()->CopyPixels(NULL, static_cast<UINT>(stride), static_cast<UINT>(total),
            reinterpret_cast<BYTE *>(&outRgba[0])))) {
        outRgba.clear();
        return false;
    }

    outWidth = static_cast<unsigned int>(width);
    outHeight = static_cast<unsigned int>(height);
    return true;
}

}  // namespace win32
}  // namespace drift
