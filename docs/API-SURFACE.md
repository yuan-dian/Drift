# Drift2 API 面清单（@api / @internal）

> 由 `php tools/lint.php --api` 自动生成，勿手改。
> 口径：docblock **独立标注行**（` * @api` 或单行 `/** @api … */`）；
> 行内描述性提及（如「它本身是 @internal 的实现细节」这类文字）不计入。

## 总览

| 层 | @api | @internal |
|---|---|---|
| App | 54 | 42 |
| Commands | 7 | 0 |
| Core | 2 | 0 |
| Errors.php | 1 | 3 |
| Geometry | 6 | 0 |
| Input | 16 | 4 |
| Layout | 38 | 21 |
| Logging | 9 | 3 |
| Platform | 12 | 10 |
| Rendering | 31 | 0 |
| Routing | 8 | 6 |
| State | 9 | 11 |
| Text | 29 | 3 |
| Theme | 3 | 0 |
| Widget | 131 | 167 |
| **合计** | **356** | **270** |

覆盖 297 个文件 · 描述性提及 25 处（grep 口径的污染源，不计入）

## App 层

### @api（应用可读面） · 54

| 符号 | 种类 | 位置 |
|---|---|---|
| `Application` | class | src/App/Application.php:51 |
| `Application::commands()` | method | src/App/Application.php:364 |
| `Application::taskHost()` | method | src/App/Application.php:390 |
| `Application::accelerators()` | method | src/App/Application.php:442 |
| `Application::clipboard()` | method | src/App/Application.php:464 |
| `Application::metrics()` | method | src/App/Application.php:493 |
| `Application::attach()` | method | src/App/Application.php:543 |
| `Application::onSystemThemeChanged()` | method | src/App/Application.php:652 |
| `Application::frame()` | method | src/App/Application.php:694 |
| `Application::repaintForResize()` | method | src/App/Application.php:901 |
| `Application::after()` | method | src/App/Application.php:962 |
| `Application::wantsFrame()` | method | src/App/Application.php:996 |
| `Application::submitSliced()` | method | src/App/Application.php:1362 |
| `Application::io()` | method | src/App/Application.php:1396 |
| `Application::capturePointer()` | method | src/App/Application.php:1412 |
| `Application::releasePointer()` | method | src/App/Application.php:1434 |
| `Application::dispatchInput()` | method | src/App/Application.php:1453 |
| `Application::dispatch()` | method | src/App/Application.php:1612 |
| `Application::showMenu()` | method | src/App/Application.php:1738 |
| `Application::showSnackbar()` | method | src/App/Application.php:1754 |
| `Application::overlays()` | method | src/App/Application.php:1788 |
| `Application::showModal()` | method | src/App/Application.php:1808 |
| `Application::closeModal()` | method | src/App/Application.php:1818 |
| `Frame` | class | src/App/Frame.php:17 |
| `Frame::run()` | method | src/App/Frame.php:79 |
| `FrameClock` | class | src/App/FrameClock.php:10 |
| `FramePhase` | class | src/App/FramePhase.php:8 |
| `IoPoller` | class | src/App/Io/IoPoller.php:6 |
| `IoPoller::watch()` | method | src/App/Io/IoPoller.php:39 |
| `IoPoller::unwatch()` | method | src/App/Io/IoPoller.php:68 |
| `Job` | interface | src/App/Job.php:6 |
| `Job::run()` | method | src/App/Job.php:24 |
| `NamedJob` | interface | src/App/NamedJob.php:6 |
| `Paths` | class | src/App/Paths.php:9 |
| `Paths::userConfig()` | method | src/App/Paths.php:32 |
| `ProcessTaskHost` | class | src/App/ProcessTaskHost.php:6 |
| `ProcessTaskHost::__construct()` | method | src/App/ProcessTaskHost.php:85 |
| `ProcessTaskHost::shutdown()` | method | src/App/ProcessTaskHost.php:200 |
| `ProcessWorker` | class | src/App/ProcessWorker.php:6 |
| `ProcessWorker::serve()` | method | src/App/ProcessWorker.php:30 |
| `PumpHost::after()` | method | src/App/PumpHost.php:111 |
| `PumpHost::io()` | method | src/App/PumpHost.php:257 |
| `PumpHost::submitSliced()` | method | src/App/PumpHost.php:273 |
| `SlicedJob` | interface | src/App/SlicedJob.php:6 |
| `SlicedJob::step()` | method | src/App/SlicedJob.php:30 |
| `SlicedJob::result()` | method | src/App/SlicedJob.php:47 |
| `SyncTaskHost` | class | src/App/SyncTaskHost.php:6 |
| `TaskHost` | interface | src/App/TaskHost.php:6 |
| `TaskHost::submit()` | method | src/App/TaskHost.php:50 |
| `TaskHost::pending()` | method | src/App/TaskHost.php:70 |
| `TaskHost::drain()` | method | src/App/TaskHost.php:83 |
| `WindowStateStore` | class | src/App/WindowStateStore.php:28 |
| `WindowStateStore::restore()` | method | src/App/WindowStateStore.php:76 |
| `WindowStateStore::persist()` | method | src/App/WindowStateStore.php:142 |

### @internal（框架内部） · 42

| 符号 | 种类 | 位置 |
|---|---|---|
| `Application::$zeroViewportFrames` | property | src/App/Application.php:272 |
| `Application::focus()` | method | src/App/Application.php:340 |
| `Application::tasks()` | method | src/App/Application.php:413 |
| `Application::lastTimestampMs()` | method | src/App/Application.php:423 |
| `Application::lastInputMs()` | method | src/App/Application.php:433 |
| `Application::$metricsCache` | property | src/App/Application.php:486 |
| `Application::uiThreadId()` | method | src/App/Application.php:604 |
| `Application::assertUiThread()` | method | src/App/Application.php:614 |
| `Application::zeroViewportFrames()` | method | src/App/Application.php:1102 |
| `Application::semanticsMode()` | method | src/App/Application.php:1297 |
| `Application::slicedCount()` | method | src/App/Application.php:1390 |
| `Application::captureNode()` | method | src/App/Application.php:1446 |
| `Application::register()` | method | src/App/Application.php:1665 |
| `Application::beginDrag()` | method | src/App/Application.php:1688 |
| `Application::updateDrag()` | method | src/App/Application.php:1696 |
| `Application::dropDrag()` | method | src/App/Application.php:1704 |
| `Application::cancelDrag()` | method | src/App/Application.php:1714 |
| `Application::rootBox()` | method | src/App/Application.php:1721 |
| `Application::dismissOverlay()` | method | src/App/Application.php:1828 |
| `Application::toggleWidgetPanel()` | method | src/App/Application.php:1850 |
| `Completion` | class | src/App/Completion.php:6 |
| `DragController` | class | src/App/DragController.php:10 |
| `FocusManager` | class | src/App/FocusManager.php:18 |
| `FrameClock::isArmed()` | method | src/App/FrameClock.php:178 |
| `FrameClock::stallCount()` | method | src/App/FrameClock.php:184 |
| `FrameClock::worstGapMs()` | method | src/App/FrameClock.php:190 |
| `InputHost` | interface | src/App/InputHost.php:10 |
| `InputRouter` | class | src/App/InputRouter.php:23 |
| `OverlayHost` | interface | src/App/OverlayHost.php:10 |
| `OverlayLayout` | class | src/App/OverlayLayout.php:10 |
| `OverlayLayout::syncPanelSignatures()` | method | src/App/OverlayLayout.php:100 |
| `OverlayManager` | class | src/App/OverlayManager.php:14 |
| `OverlayManager::onHoverHit()` | method | src/App/OverlayManager.php:153 |
| `OverlayManager::dismissOverlay()` | method | src/App/OverlayManager.php:558 |
| `OverlayManager::focusTrapRoots()` | method | src/App/OverlayManager.php:571 |
| `OverlayManager::toggleWidgetPanel()` | method | src/App/OverlayManager.php:642 |
| `PumpHost` | class | src/App/PumpHost.php:9 |
| `PumpHost::slicedCount()` | method | src/App/PumpHost.php:251 |
| `PumpHostHost` | interface | src/App/PumpHostHost.php:6 |
| `ScrollInputRouter` | class | src/App/ScrollInputRouter.php:14 |
| `TextEditingBridge` | class | src/App/TextEditingBridge.php:14 |
| `TextEditingHost` | interface | src/App/TextEditingHost.php:8 |

## Commands 层

### @api（应用可读面） · 7

| 符号 | 种类 | 位置 |
|---|---|---|
| `CommandArg` | class | src/Commands/CommandArg.php:6 |
| `CommandHandler` | interface | src/Commands/CommandHandler.php:6 |
| `CommandHandler::handle()` | method | src/Commands/CommandHandler.php:22 |
| `CommandRouter` | class | src/Commands/CommandRouter.php:8 |
| `CommandRouter::make()` | method | src/Commands/CommandRouter.php:31 |
| `CommandRouter::on()` | method | src/Commands/CommandRouter.php:39 |
| `CommandRouter::dispatch()` | method | src/Commands/CommandRouter.php:63 |

## Core 层

### @api（应用可读面） · 2

| 符号 | 种类 | 位置 |
|---|---|---|
| `Computed` | class | src/Core/Computed.php:8 |
| `Versioned` | interface | src/Core/Versioned.php:6 |

## Errors.php 层

### @api（应用可读面） · 1

| 符号 | 种类 | 位置 |
|---|---|---|
| `Errors` | class | src/Errors.php:9 |

### @internal（框架内部） · 3

| 符号 | 种类 | 位置 |
|---|---|---|
| `Errors::setLogPath()` | method | src/Errors.php:112 |
| `Errors::recentDiagnostics()` | method | src/Errors.php:123 |
| `Errors::reset()` | method | src/Errors.php:129 |

## Geometry 层

### @api（应用可读面） · 6

| 符号 | 种类 | 位置 |
|---|---|---|
| `BoxConstraints` | class | src/Geometry/BoxConstraints.php:8 |
| `EdgeInsets` | class | src/Geometry/EdgeInsets.php:6 |
| `Point` | class | src/Geometry/Point.php:6 |
| `Rect` | class | src/Geometry/Rect.php:6 |
| `Scale` | class | src/Geometry/Scale.php:6 |
| `Size` | class | src/Geometry/Size.php:6 |

## Input 层

### @api（应用可读面） · 16

| 符号 | 种类 | 位置 |
|---|---|---|
| `AcceleratorTable` | class | src/Input/AcceleratorTable.php:8 |
| `CursorShape` | enum | src/Input/CursorShape.php:6 |
| `FocusNode` | class | src/Input/FocusNode.php:6 |
| `InputEvent` | interface | src/Input/InputEvent.php:6 |
| `KeyChord` | class | src/Input/KeyChord.php:6 |
| `KeyEvent` | class | src/Input/KeyEvent.php:6 |
| `PointerEvent` | class | src/Input/PointerEvent.php:6 |
| `PointerPhase` | enum | src/Input/PointerPhase.php:6 |
| `ScrollEvent` | class | src/Input/ScrollEvent.php:6 |
| `SemanticsInvokeEvent` | class | src/Input/SemanticsInvokeEvent.php:6 |
| `SemanticsNode` | class | src/Input/SemanticsNode.php:6 |
| `SemanticsRole` | enum | src/Input/SemanticsRole.php:6 |
| `SemanticsSetValueEvent` | class | src/Input/SemanticsSetValueEvent.php:6 |
| `SemanticsTree` | class | src/Input/SemanticsTree.php:6 |
| `ThemeChangedEvent` | class | src/Input/ThemeChangedEvent.php:6 |
| `WindowDeactivatedEvent` | class | src/Input/WindowDeactivatedEvent.php:6 |

### @internal（框架内部） · 4

| 符号 | 种类 | 位置 |
|---|---|---|
| `GestureArena` | class | src/Input/GestureArena.php:6 |
| `GestureRecognizer` | class | src/Input/GestureRecognizer.php:6 |
| `GestureRecognizer::claims()` | method | src/Input/GestureRecognizer.php:58 |
| `GestureRecognizer::win()` | method | src/Input/GestureRecognizer.php:64 |

## Layout 层

### @api（应用可读面） · 38

| 符号 | 种类 | 位置 |
|---|---|---|
| `FlexAlign` | enum | src/Layout/FlexAlign.php:6 |
| `ImageFit` | enum | src/Layout/ImageFit.php:6 |
| `LayoutSelfCheck` | class | src/Layout/LayoutSelfCheck.php:9 |
| `Overflow` | enum | src/Layout/Overflow.php:6 |
| `RenderAppBar` | class | src/Layout/RenderAppBar.php:13 |
| `RenderBox` | class | src/Layout/RenderBox.php:13 |
| `RenderBox::overflow()` | method | src/Layout/RenderBox.php:485 |
| `RenderBox::setCompose()` | method | src/Layout/RenderBox.php:617 |
| `RenderBox::paintComposed()` | method | src/Layout/RenderBox.php:655 |
| `RenderBox::hitTest()` | method | src/Layout/RenderBox.php:698 |
| `RenderButton` | class | src/Layout/RenderButton.php:19 |
| `RenderDivider` | class | src/Layout/RenderDivider.php:13 |
| `RenderDock` | class | src/Layout/RenderDock.php:12 |
| `RenderFlex` | class | src/Layout/RenderFlex.php:12 |
| `RenderIcon` | class | src/Layout/RenderIcon.php:13 |
| `RenderImage` | class | src/Layout/RenderImage.php:13 |
| `RenderMenuItem` | class | src/Layout/RenderMenuItem.php:16 |
| `RenderObject` | class | src/Layout/RenderObject.php:11 |
| `RenderObject::cursor()` | method | src/Layout/RenderObject.php:55 |
| `RenderObject::cursorAt()` | method | src/Layout/RenderObject.php:77 |
| `RenderObject::panelRect()` | method | src/Layout/RenderObject.php:94 |
| `RenderObject::acceptsHover()` | method | src/Layout/RenderObject.php:117 |
| `RenderPopupPanel` | class | src/Layout/RenderPopupPanel.php:16 |
| `RenderProgress` | class | src/Layout/RenderProgress.php:13 |
| `RenderProxy` | class | src/Layout/RenderProxy.php:9 |
| `RenderProxyBox` | class | src/Layout/RenderProxyBox.php:8 |
| `RenderRichText` | class | src/Layout/RenderRichText.php:19 |
| `RenderScrollView` | class | src/Layout/RenderScrollView.php:16 |
| `RenderSegment` | class | src/Layout/RenderSegment.php:15 |
| `RenderSegmentedControl` | class | src/Layout/RenderSegmentedControl.php:13 |
| `RenderSlider` | class | src/Layout/RenderSlider.php:13 |
| `RenderSpinner` | class | src/Layout/RenderSpinner.php:13 |
| `RenderStack` | class | src/Layout/RenderStack.php:10 |
| `RenderText` | class | src/Layout/RenderText.php:16 |
| `RenderTextField` | class | src/Layout/RenderTextField.php:20 |
| `RenderToggle` | class | src/Layout/RenderToggle.php:16 |
| `StackAlign` | enum | src/Layout/StackAlign.php:6 |
| `StackFit` | enum | src/Layout/StackFit.php:6 |

### @internal（框架内部） · 21

| 符号 | 种类 | 位置 |
|---|---|---|
| `LayoutTolerance` | class | src/Layout/LayoutTolerance.php:6 |
| `RenderBox::clipsOverflow()` | method | src/Layout/RenderBox.php:496 |
| `RenderCustomPaint` | class | src/Layout/RenderCustomPaint.php:12 |
| `RenderDock::slot()` | method | src/Layout/RenderDock.php:44 |
| `RenderFlex::clearChildren()` | method | src/Layout/RenderFlex.php:98 |
| `RenderModalHost` | class | src/Layout/RenderModalHost.php:14 |
| `RenderObject::$cursor` | property | src/Layout/RenderObject.php:27 |
| `RenderObject::setCursor()` | method | src/Layout/RenderObject.php:69 |
| `RenderObject::setHovered()` | method | src/Layout/RenderObject.php:134 |
| `RenderObject::adoptChild()` | method | src/Layout/RenderObject.php:193 |
| `RenderRichText::styleSize()` | method | src/Layout/RenderRichText.php:50 |
| `RenderRichText::lineCount()` | method | src/Layout/RenderRichText.php:56 |
| `RenderRichText::paragraph()` | method | src/Layout/RenderRichText.php:62 |
| `RenderRichText::spans()` | method | src/Layout/RenderRichText.php:68 |
| `RenderScrollView::setBarOpacity()` | method | src/Layout/RenderScrollView.php:90 |
| `RenderSegmentedControl::clearChildren()` | method | src/Layout/RenderSegmentedControl.php:66 |
| `RenderStack::clearChildren()` | method | src/Layout/RenderStack.php:67 |
| `RenderText::styleSize()` | method | src/Layout/RenderText.php:50 |
| `RenderText::lineCount()` | method | src/Layout/RenderText.php:56 |
| `RenderTextField::styleSize()` | method | src/Layout/RenderTextField.php:234 |
| `RenderTextField::isShowingPlaceholder()` | method | src/Layout/RenderTextField.php:240 |

## Logging 层

### @api（应用可读面） · 9

| 符号 | 种类 | 位置 |
|---|---|---|
| `CategoryLogger` | class | src/Logging/CategoryLogger.php:6 |
| `Log` | class | src/Logging/Log.php:8 |
| `LogEntry` | class | src/Logging/LogEntry.php:6 |
| `LogFormatter` | interface | src/Logging/LogFormatter.php:6 |
| `LogSink` | interface | src/Logging/LogSink.php:6 |
| `PatternFormatter` | class | src/Logging/PatternFormatter.php:8 |
| `RingSink` | class | src/Logging/RingSink.php:8 |
| `StderrSink` | class | src/Logging/StderrSink.php:6 |
| `StreamSink` | class | src/Logging/StreamSink.php:8 |

### @internal（框架内部） · 3

| 符号 | 种类 | 位置 |
|---|---|---|
| `CategoryLogger::__construct()` | method | src/Logging/CategoryLogger.php:20 |
| `Log::emit()` | method | src/Logging/Log.php:179 |
| `Log::reset()` | method | src/Logging/Log.php:246 |

## Platform 层

### @api（应用可读面） · 12

| 符号 | 种类 | 位置 |
|---|---|---|
| `Clipboard` | interface | src/Platform/Clipboard.php:6 |
| `Dialog` | interface | src/Platform/Dialog.php:6 |
| `InputSource` | interface | src/Platform/InputSource.php:8 |
| `Platform` | interface | src/Platform/Platform.php:6 |
| `Screen` | interface | src/Platform/Screen.php:9 |
| `SurfaceProvider` | interface | src/Platform/SurfaceProvider.php:8 |
| `TimerHost` | interface | src/Platform/TimerHost.php:6 |
| `Tray` | interface | src/Platform/Tray.php:6 |
| `Win32Platform` | class | src/Platform/Win32/Win32Platform.php:30 |
| `WindowHost` | interface | src/Platform/WindowHost.php:11 |
| `WindowHost::setCursor()` | method | src/Platform/WindowHost.php:102 |
| `WindowHost::pushSemantics()` | method | src/Platform/WindowHost.php:114 |

### @internal（框架内部） · 10

| 符号 | 种类 | 位置 |
|---|---|---|
| `ResizeHookState::pump()` | method | src/Platform/Win32/ResizeHookState.php:23 |
| `Win32Clipboard` | class | src/Platform/Win32/Win32Clipboard.php:12 |
| `Win32InputSource` | class | src/Platform/Win32/Win32InputSource.php:9 |
| `Win32RasterSurface` | class | src/Platform/Win32/Win32RasterSurface.php:14 |
| `Win32Screen` | class | src/Platform/Win32/Win32Screen.php:15 |
| `Win32SkiaSurface` | class | src/Platform/Win32/Win32SkiaSurface.php:15 |
| `Win32SurfaceProvider` | class | src/Platform/Win32/Win32SurfaceProvider.php:9 |
| `Win32SurfaceProvider::resolveBackend()` | method | src/Platform/Win32/Win32SurfaceProvider.php:75 |
| `Win32TimerHost` | class | src/Platform/Win32/Win32TimerHost.php:11 |
| `Win32WindowHost` | class | src/Platform/Win32/Win32WindowHost.php:27 |

## Rendering 层

### @api（应用可读面） · 31

| 符号 | 种类 | 位置 |
|---|---|---|
| `AnimatedValue` | class | src/Rendering/AnimatedValue.php:6 |
| `BoxStyle` | class | src/Rendering/BoxStyle.php:6 |
| `Canvas` | interface | src/Rendering/Canvas.php:9 |
| `CanvasCapabilities` | class | src/Rendering/CanvasCapabilities.php:8 |
| `Color` | class | src/Rendering/Color.php:8 |
| `ColorToken` | enum | src/Rendering/ColorToken.php:8 |
| `DashPattern` | class | src/Rendering/DashPattern.php:8 |
| `DisplayList` | class | src/Rendering/DisplayList.php:8 |
| `DividerStyle` | class | src/Rendering/DividerStyle.php:6 |
| `Elevation` | class | src/Rendering/Elevation.php:6 |
| `GpuContext` | interface | src/Rendering/GpuContext.php:7 |
| `Gradient` | class | src/Rendering/Gradient.php:8 |
| `ImageHandle` | class | src/Rendering/ImageHandle.php:8 |
| `ImageLoader` | class | src/Rendering/ImageLoader.php:8 |
| `MetricToken` | enum | src/Rendering/MetricToken.php:6 |
| `Modifier` | class | src/Rendering/Modifier.php:11 |
| `Paint` | class | src/Rendering/Paint.php:8 |
| `PaintStyle` | enum | src/Rendering/PaintStyle.php:6 |
| `Path` | class | src/Rendering/Path.php:8 |
| `PngDecoder` | class | src/Rendering/PngDecoder.php:8 |
| `ProgressStyle` | class | src/Rendering/ProgressStyle.php:6 |
| `RasterCanvas` | class | src/Rendering/RasterCanvas.php:12 |
| `RecordingCanvas` | class | src/Rendering/RecordingCanvas.php:9 |
| `RenderSurface` | interface | src/Rendering/RenderSurface.php:7 |
| `SegmentedStyle` | class | src/Rendering/SegmentedStyle.php:6 |
| `Shadow` | class | src/Rendering/Shadow.php:9 |
| `ShadowPainter` | class | src/Rendering/ShadowPainter.php:8 |
| `SkiaCanvas` | class | src/Rendering/SkiaCanvas.php:34 |
| `SkiaCanvas::handle()` | method | src/Rendering/SkiaCanvas.php:107 |
| `SurfaceProfile` | class | src/Rendering/SurfaceProfile.php:6 |
| `ToggleStyle` | class | src/Rendering/ToggleStyle.php:6 |

## Routing 层

### @api（应用可读面） · 8

| 符号 | 种类 | 位置 |
|---|---|---|
| `PageHost` | class | src/Routing/PageHost.php:10 |
| `PageHost::make()` | method | src/Routing/PageHost.php:47 |
| `PageHost::source()` | method | src/Routing/PageHost.php:58 |
| `PageHost::select()` | method | src/Routing/PageHost.php:71 |
| `PageHost::table()` | method | src/Routing/PageHost.php:87 |
| `RouteTable` | class | src/Routing/RouteTable.php:9 |
| `RouteTable::make()` | method | src/Routing/RouteTable.php:31 |
| `RouteTable::route()` | method | src/Routing/RouteTable.php:39 |

### @internal（框架内部） · 6

| 符号 | 种类 | 位置 |
|---|---|---|
| `NoPage` | class | src/Routing/NoPage.php:8 |
| `PageHost::sourceSubject()` | method | src/Routing/PageHost.php:96 |
| `PageHost::selectFn()` | method | src/Routing/PageHost.php:102 |
| `PageHost::routeTable()` | method | src/Routing/PageHost.php:108 |
| `PageHostState` | class | src/Routing/PageHostState.php:13 |
| `PageHostState::cachedCount()` | method | src/Routing/PageHostState.php:70 |

## State 层

### @api（应用可读面） · 9

| 符号 | 种类 | 位置 |
|---|---|---|
| `Cell` | class | src/State/Cell.php:9 |
| `Cell::setIfChanged()` | method | src/State/Cell.php:76 |
| `Subject` | class | src/State/Subject.php:9 |
| `SubjectBuilder` | class | src/State/SubjectBuilder.php:12 |
| `SubjectBuilder::make()` | method | src/State/SubjectBuilder.php:50 |
| `SubjectBuilder::select()` | method | src/State/SubjectBuilder.php:63 |
| `SubjectBuilder::builder()` | method | src/State/SubjectBuilder.php:79 |
| `SubscribingWidget` | interface | src/State/SubscribingWidget.php:6 |
| `Subscription` | class | src/State/Subscription.php:6 |

### @internal（框架内部） · 11

| 符号 | 种类 | 位置 |
|---|---|---|
| `Cell::$version` | property | src/State/Cell.php:27 |
| `Cell::$owner` | property | src/State/Cell.php:30 |
| `Cell::$bound` | property | src/State/Cell.php:40 |
| `Cell::makeFor()` | method | src/State/Cell.php:59 |
| `Subject::$version` | property | src/State/Subject.php:34 |
| `Subject::unsubscribeId()` | method | src/State/Subject.php:90 |
| `SubjectBuilder::source()` | method | src/State/SubjectBuilder.php:96 |
| `SubjectBuilder::selectFn()` | method | src/State/SubjectBuilder.php:102 |
| `SubjectBuilder::builderFn()` | method | src/State/SubjectBuilder.php:108 |
| `SubjectBuilderState` | class | src/State/SubjectBuilder.php:120 |
| `Subscription::of()` | method | src/State/Subscription.php:30 |

## Text 层

### @api（应用可读面） · 29

| 符号 | 种类 | 位置 |
|---|---|---|
| `BitmapFontFace` | class | src/Text/BitmapFontFace.php:8 |
| `DeterministicFontCollection` | class | src/Text/DeterministicFontCollection.php:6 |
| `DeterministicFontManager` | class | src/Text/DeterministicFontManager.php:8 |
| `DeterministicMetricsProvider` | class | src/Text/DeterministicMetricsProvider.php:8 |
| `DeterministicShaper` | class | src/Text/DeterministicShaper.php:6 |
| `FontCollection` | interface | src/Text/FontCollection.php:6 |
| `FontFace` | interface | src/Text/FontFace.php:6 |
| `FontFamily` | class | src/Text/FontFamily.php:6 |
| `FontLocator` | class | src/Text/FontLocator.php:6 |
| `FontManager` | interface | src/Text/FontManager.php:6 |
| `FontMetrics` | class | src/Text/FontMetrics.php:6 |
| `FontSlant` | enum | src/Text/FontSlant.php:6 |
| `FontWeight` | enum | src/Text/FontWeight.php:6 |
| `Glyph` | class | src/Text/Glyph.php:6 |
| `GlyphBitmap` | class | src/Text/GlyphBitmap.php:6 |
| `GlyphRun` | class | src/Text/GlyphRun.php:6 |
| `NotdefFontFace` | class | src/Text/NotdefFontFace.php:6 |
| `Paragraph` | class | src/Text/Paragraph.php:8 |
| `TextAlign` | enum | src/Text/TextAlign.php:6 |
| `TextInputClient` | interface | src/Text/TextInputClient.php:8 |
| `TextLayout` | class | src/Text/TextLayout.php:9 |
| `TextLine` | class | src/Text/TextLine.php:6 |
| `TextMetricsProvider` | interface | src/Text/TextMetricsProvider.php:8 |
| `TextShaper` | interface | src/Text/TextShaper.php:6 |
| `TextSpan` | class | src/Text/TextSpan.php:6 |
| `TextStyle` | class | src/Text/TextStyle.php:6 |
| `TtfFace` | class | src/Text/TtfFace.php:8 |
| `TtfFace::fromFile()` | method | src/Text/TtfFace.php:85 |
| `Utf8` | class | src/Text/Utf8.php:6 |

### @internal（框架内部） · 3

| 符号 | 种类 | 位置 |
|---|---|---|
| `CjkGlyphData` | class | src/Text/CjkGlyphData.php:6 |
| `DeterministicFontData` | class | src/Text/DeterministicFontData.php:6 |
| `LatinGlyphData` | class | src/Text/LatinGlyphData.php:6 |

## Theme 层

### @api（应用可读面） · 3

| 符号 | 种类 | 位置 |
|---|---|---|
| `SystemTheme` | enum | src/Theme/SystemTheme.php:6 |
| `ThemeData` | class | src/Theme/ThemeData.php:11 |
| `ThemeScope` | class | src/Theme/ThemeScope.php:11 |

## Widget 层

### @api（应用可读面） · 131

| 符号 | 种类 | 位置 |
|---|---|---|
| `Activatable` | interface | src/Widget/Activatable.php:6 |
| `ActivationContext` | class | src/Widget/ActivationContext.php:8 |
| `AnimationController` | class | src/Widget/AnimationController.php:6 |
| `AppBar` | class | src/Widget/AppBar.php:13 |
| `AspectRatio` | class | src/Widget/AspectRatio.php:9 |
| `BoxWidget` | class | src/Widget/BoxWidget.php:9 |
| `BuildContext` | class | src/Widget/BuildContext.php:13 |
| `Button` | class | src/Widget/Button.php:19 |
| `ButtonVariant` | enum | src/Widget/ButtonVariant.php:6 |
| `Card` | class | src/Widget/Card.php:11 |
| `Center` | class | src/Widget/Center.php:9 |
| `Checkbox` | class | src/Widget/Checkbox.php:9 |
| `Column` | class | src/Widget/Column.php:6 |
| `ComboBox` | class | src/Widget/ComboBox.php:11 |
| `ComboBoxState` | class | src/Widget/ComboBox.php:334 |
| `Curve` | class | src/Widget/Curve.php:6 |
| `CustomPaint` | class | src/Widget/CustomPaint.php:9 |
| `CustomPainter` | class | src/Widget/CustomPainter.php:9 |
| `Divider` | class | src/Widget/Divider.php:11 |
| `Dock` | class | src/Widget/Dock.php:10 |
| `Draggable` | class | src/Widget/Drag/Draggable.php:8 |
| `DragTarget` | class | src/Widget/Drag/DragTarget.php:6 |
| `Each` | class | src/Widget/Each.php:9 |
| `EditableText` | interface | src/Widget/EditableText.php:9 |
| `Element` | class | src/Widget/Element.php:9 |
| `ErrorBoundary` | class | src/Widget/ErrorBoundary.php:9 |
| `ErrorBoundaryElement` | class | src/Widget/ErrorBoundaryElement.php:10 |
| `ErrorPlaceholder` | class | src/Widget/ErrorPlaceholder.php:6 |
| `Expanded` | class | src/Widget/Expanded.php:9 |
| `Flex` | class | src/Widget/Flex.php:12 |
| `FlexElement` | class | src/Widget/FlexElement.php:8 |
| `Grid` | class | src/Widget/Grid.php:8 |
| `Icon` | class | src/Widget/Icon.php:11 |
| `IconButton` | class | src/Widget/IconButton.php:6 |
| `IconName` | enum | src/Widget/IconName.php:6 |
| `IconSet` | class | src/Widget/IconSet.php:8 |
| `Image` | class | src/Widget/Image.php:12 |
| `Key` | class | src/Widget/Key.php:6 |
| `LazyColumn` | class | src/Widget/LazyColumn.php:6 |
| `LazyGrid` | class | src/Widget/LazyGrid.php:9 |
| `LazyList` | class | src/Widget/LazyList.php:10 |
| `LazyRow` | class | src/Widget/LazyRow.php:6 |
| `ContextMenu` | class | src/Widget/Menu/ContextMenu.php:11 |
| `Menu` | class | src/Widget/Menu/Menu.php:14 |
| `MenuBar` | class | src/Widget/Menu/MenuBar.php:11 |
| `MenuItem` | class | src/Widget/Menu/MenuItem.php:15 |
| `Modal` | class | src/Widget/Modal.php:9 |
| `NavButton` | class | src/Widget/NavButton.php:6 |
| `Offstage` | class | src/Widget/Offstage.php:6 |
| `Offstage::make()` | method | src/Widget/Offstage.php:32 |
| `Offstage::visible()` | method | src/Widget/Offstage.php:44 |
| `OffstageElement` | class | src/Widget/OffstageElement.php:6 |
| `Option` | class | src/Widget/Option.php:8 |
| `Option::normalize()` | method | src/Widget/Option.php:61 |
| `Option::fromRows()` | method | src/Widget/Option.php:159 |
| `OptionStatus` | class | src/Widget/OptionStatus.php:6 |
| `Padding` | class | src/Widget/Padding.php:10 |
| `PopupPanel` | class | src/Widget/PopupPanel.php:12 |
| `Progress` | class | src/Widget/Progress.php:12 |
| `ProxyElement` | class | src/Widget/ProxyElement.php:8 |
| `Radio` | class | src/Widget/Radio.php:9 |
| `RenderBoxWidget` | class | src/Widget/RenderBoxWidget.php:8 |
| `RichText` | class | src/Widget/RichText.php:14 |
| `Row` | class | src/Widget/Row.php:6 |
| `Scrollable` | interface | src/Widget/Scrollable.php:8 |
| `ScrollView` | class | src/Widget/ScrollView.php:8 |
| `ScrollViewState` | class | src/Widget/ScrollViewState.php:10 |
| `Segment` | class | src/Widget/Segment.php:14 |
| `SegmentedControl` | class | src/Widget/SegmentedControl.php:14 |
| `SegmentedControlElement` | class | src/Widget/SegmentedControlElement.php:8 |
| `Select` | class | src/Widget/Select.php:11 |
| `SemanticsBuilder` | class | src/Widget/SemanticsBuilder.php:11 |
| `SemanticsValueEditable` | interface | src/Widget/SemanticsValueEditable.php:6 |
| `SizedBox` | class | src/Widget/SizedBox.php:9 |
| `Slider` | class | src/Widget/Slider.php:17 |
| `Snackbar` | class | src/Widget/Snackbar.php:6 |
| `SnackbarPanel` | class | src/Widget/SnackbarPanel.php:8 |
| `Spacer` | class | src/Widget/Spacer.php:9 |
| `Span` | class | src/Widget/Span.php:11 |
| `Spinner` | class | src/Widget/Spinner.php:11 |
| `Stack` | class | src/Widget/Stack.php:11 |
| `StackElement` | class | src/Widget/StackElement.php:8 |
| `State` | class | src/Widget/State.php:12 |
| `State::markDirty()` | method | src/Widget/State.php:137 |
| `State::tickFrame()` | method | src/Widget/State.php:176 |
| `State::initState()` | method | src/Widget/State.php:216 |
| `State::didChangeFocus()` | method | src/Widget/State.php:229 |
| `State::cell()` | method | src/Widget/State.php:245 |
| `State::watch()` | method | src/Widget/State.php:263 |
| `State::handleCommand()` | method | src/Widget/State.php:423 |
| `StatefulElement` | class | src/Widget/StatefulElement.php:6 |
| `StatefulWidget` | class | src/Widget/StatefulWidget.php:8 |
| `StatefulWidget::createRenderObjectFromState()` | method | src/Widget/StatefulWidget.php:27 |
| `StatelessElement` | class | src/Widget/StatelessElement.php:6 |
| `StatelessWidget` | class | src/Widget/StatelessWidget.php:6 |
| `States` | class | src/Widget/States.php:6 |
| `Text` | class | src/Widget/Text.php:10 |
| `TextField` | class | src/Widget/TextField.php:9 |
| `TextFieldState` | class | src/Widget/TextFieldState.php:14 |
| `ThemeScopeElement` | class | src/Widget/ThemeScopeElement.php:6 |
| `Toggle` | class | src/Widget/Toggle.php:9 |
| `ToggleBase` | class | src/Widget/ToggleBase.php:14 |
| `TooltipPanel` | class | src/Widget/TooltipPanel.php:8 |
| `Widget` | class | src/Widget/Widget.php:17 |
| `Widget::__construct()` | method | src/Widget/Widget.php:154 |
| `Widget::key()` | method | src/Widget/Widget.php:181 |
| `Widget::mutate()` | method | src/Widget/Widget.php:258 |
| `Widget::focusOrder()` | method | src/Widget/Widget.php:283 |
| `Widget::skipFocus()` | method | src/Widget/Widget.php:301 |
| `Widget::claimKeys()` | method | src/Widget/Widget.php:316 |
| `Widget::cursor()` | method | src/Widget/Widget.php:339 |
| `Widget::padding()` | method | src/Widget/Widget.php:410 |
| `Widget::boxSize()` | method | src/Widget/Widget.php:479 |
| `Widget::background()` | method | src/Widget/Widget.php:552 |
| `Widget::shadow()` | method | src/Widget/Widget.php:585 |
| `Widget::opacity()` | method | src/Widget/Widget.php:603 |
| `Widget::translate()` | method | src/Widget/Widget.php:625 |
| `Widget::clip()` | method | src/Widget/Widget.php:638 |
| `Widget::centerContent()` | method | src/Widget/Widget.php:656 |
| `Widget::grow()` | method | src/Widget/Widget.php:673 |
| `Widget::draggable()` | method | src/Widget/Widget.php:740 |
| `Widget::dragTarget()` | method | src/Widget/Widget.php:767 |
| `Widget::contextMenu()` | method | src/Widget/Widget.php:790 |
| `Widget::hover()` | method | src/Widget/Widget.php:813 |
| `Widget::pointer()` | method | src/Widget/Widget.php:842 |
| `Widget::keyboard()` | method | src/Widget/Widget.php:888 |
| `Widget::tooltip()` | method | src/Widget/Widget.php:931 |
| `Widget::overlayPanel()` | method | src/Widget/Widget.php:970 |
| `Widget::overlaySignature()` | method | src/Widget/Widget.php:1025 |
| `Widget::semantics()` | method | src/Widget/Widget.php:1079 |
| `Widget::semanticsRange()` | method | src/Widget/Widget.php:1120 |

### @internal（框架内部） · 167

| 符号 | 种类 | 位置 |
|---|---|---|
| `AnimationController::start()` | method | src/Widget/AnimationController.php:253 |
| `AnimationController::finish()` | method | src/Widget/AnimationController.php:276 |
| `AppBar::titleText()` | method | src/Widget/AppBar.php:92 |
| `AppBar::kidList()` | method | src/Widget/AppBar.php:98 |
| `AppBar::actionList()` | method | src/Widget/AppBar.php:104 |
| `AppBar::barKids()` | method | src/Widget/AppBar.php:110 |
| `AppBarElement` | class | src/Widget/AppBarElement.php:8 |
| `BoxWidget::$box` | property | src/Widget/BoxWidget.php:27 |
| `BoxWidget::box()` | method | src/Widget/BoxWidget.php:30 |
| `BuildContext::withTheme()` | method | src/Widget/BuildContext.php:48 |
| `Card::$box` | property | src/Widget/Card.php:74 |
| `Card::box()` | method | src/Widget/Card.php:154 |
| `ComboBox::optionsValue()` | method | src/Widget/ComboBox.php:285 |
| `ComboBox::valueText()` | method | src/Widget/ComboBox.php:291 |
| `ComboBox::placeholderText()` | method | src/Widget/ComboBox.php:297 |
| `ComboBox::triggerWidthValue()` | method | src/Widget/ComboBox.php:303 |
| `ComboBox::changedHandler()` | method | src/Widget/ComboBox.php:309 |
| `ComboBox::statusValue()` | method | src/Widget/ComboBox.php:315 |
| `ComboBox::openHandler()` | method | src/Widget/ComboBox.php:321 |
| `ComboBox::searchHandler()` | method | src/Widget/ComboBox.php:327 |
| `ComboBoxState::$query` | property | src/Widget/ComboBox.php:344 |
| `ComboBoxState::$editing` | property | src/Widget/ComboBox.php:347 |
| `ComboBoxState::$dismiss` | property | src/Widget/ComboBox.php:358 |
| `ComboBoxState::$knownValue` | property | src/Widget/ComboBox.php:367 |
| `ComboBoxState::$knownLabel` | property | src/Widget/ComboBox.php:379 |
| `ComboBoxState::querySubject()` | method | src/Widget/ComboBox.php:416 |
| `ComboBoxState::panelContent()` | method | src/Widget/ComboBox.php:424 |
| `ComboBoxState::panelSignature()` | method | src/Widget/ComboBox.php:467 |
| `ComboBoxState::matches()` | method | src/Widget/ComboBox.php:487 |
| `ComboBoxState::commit()` | method | src/Widget/ComboBox.php:526 |
| `ComboBoxState::forwardKey()` | method | src/Widget/ComboBox.php:539 |
| `ComboBoxState::onPanelClosed()` | method | src/Widget/ComboBox.php:561 |
| `ComboBoxQuery` | class | src/Widget/ComboBox.php:800 |
| `Curve::LINEAR` | const | src/Widget/Curve.php:29 |
| `Curve::EASE_IN` | const | src/Widget/Curve.php:32 |
| `Curve::EASE_OUT` | const | src/Widget/Curve.php:35 |
| `Curve::EASE_IN_OUT` | const | src/Widget/Curve.php:38 |
| `Curve::kind()` | method | src/Widget/Curve.php:65 |
| `Dock::slotKid()` | method | src/Widget/Dock.php:93 |
| `Dock::overflowValue()` | method | src/Widget/Dock.php:106 |
| `DockElement` | class | src/Widget/DockElement.php:8 |
| `Element::$box` | property | src/Widget/Element.php:56 |
| `Element::bumpStructure()` | method | src/Widget/Element.php:84 |
| `Element::structureVersion()` | method | src/Widget/Element.php:90 |
| `Element::onMount()` | method | src/Widget/Element.php:113 |
| `Element::syncBox()` | method | src/Widget/Element.php:132 |
| `Element::effectiveBox()` | method | src/Widget/Element.php:182 |
| `Element::markNeedsBuild()` | method | src/Widget/Element.php:209 |
| `Element::findBox()` | method | src/Widget/Element.php:228 |
| `Element::findBoxOf()` | method | src/Widget/Element.php:258 |
| `Element::effectiveBoxOf()` | method | src/Widget/Element.php:276 |
| `Element::adoptBoxes()` | method | src/Widget/Element.php:294 |
| `Element::didUpdateWidget()` | method | src/Widget/Element.php:320 |
| `Element::applyContext()` | method | src/Widget/Element.php:336 |
| `Element::settle()` | method | src/Widget/Element.php:378 |
| `Grid::rowOf()` | method | src/Widget/Grid.php:151 |
| `Grid::chunk()` | method | src/Widget/Grid.php:181 |
| `LazyList::keyMapper()` | method | src/Widget/LazyList.php:126 |
| `LazyList::buildItem()` | method | src/Widget/LazyList.php:187 |
| `LazyListState` | class | src/Widget/LazyListState.php:11 |
| `Modal::bindDismiss()` | method | src/Widget/Modal.php:169 |
| `ModalHost` | class | src/Widget/ModalHost.php:10 |
| `OffstageElement::$parked` | property | src/Widget/OffstageElement.php:22 |
| `OptionPanel` | class | src/Widget/OptionPanel.php:10 |
| `OptionPanel::source()` | method | src/Widget/OptionPanel.php:70 |
| `OptionPanel::content()` | method | src/Widget/OptionPanel.php:76 |
| `OptionPanel::minWidthValue()` | method | src/Widget/OptionPanel.php:82 |
| `OptionPanel::maxHeightValue()` | method | src/Widget/OptionPanel.php:88 |
| `OptionPanel::notifyClosed()` | method | src/Widget/OptionPanel.php:94 |
| `OptionPanelState` | class | src/Widget/OptionPanel.php:110 |
| `ScrollBarFade` | class | src/Widget/ScrollBarFade.php:9 |
| `ScrollViewBox` | class | src/Widget/ScrollViewBox.php:10 |
| `ScrollViewBox::make()` | method | src/Widget/ScrollViewBox.php:39 |
| `Select::optionsValue()` | method | src/Widget/Select.php:243 |
| `Select::valueValue()` | method | src/Widget/Select.php:249 |
| `Select::placeholderText()` | method | src/Widget/Select.php:255 |
| `Select::triggerWidthValue()` | method | src/Widget/Select.php:261 |
| `Select::statusValue()` | method | src/Widget/Select.php:267 |
| `Select::onOpenHandler()` | method | src/Widget/Select.php:273 |
| `Select::onChangedHandler()` | method | src/Widget/Select.php:279 |
| `Select::labelAt()` | method | src/Widget/Select.php:297 |
| `SelectState` | class | src/Widget/Select.php:317 |
| `SelectState::$signal` | property | src/Widget/Select.php:327 |
| `SelectState::$dismiss` | property | src/Widget/Select.php:330 |
| `SelectState::$highlight` | property | src/Widget/Select.php:337 |
| `SelectPanelSignal` | class | src/Widget/Select.php:606 |
| `SemanticsBuilder::__construct()` | method | src/Widget/SemanticsBuilder.php:34 |
| `SpinnerState` | class | src/Widget/SpinnerState.php:6 |
| `State::$pendingDirty` | property | src/Widget/State.php:23 |
| `State::$buildingState` | property | src/Widget/State.php:35 |
| `State::$rebuiltCount` | property | src/Widget/State.php:47 |
| `State::$boxEpoch` | property | src/Widget/State.php:50 |
| `State::$initializing` | property | src/Widget/State.php:94 |
| `State::$initialized` | property | src/Widget/State.php:97 |
| `State::$lastTickedFrame` | property | src/Widget/State.php:100 |
| `State::attach()` | method | src/Widget/State.php:103 |
| `State::element()` | method | src/Widget/State.php:115 |
| `State::dropSubscriptions()` | method | src/Widget/State.php:282 |
| `State::callInit()` | method | src/Widget/State.php:299 |
| `State::consumeDirty()` | method | src/Widget/State.php:321 |
| `State::pendingDirty()` | method | src/Widget/State.php:333 |
| `State::rebuiltCount()` | method | src/Widget/State.php:339 |
| `State::boxEpoch()` | method | src/Widget/State.php:345 |
| `State::noteBoxReplaced()` | method | src/Widget/State.php:353 |
| `State::takeMountedThisFrame()` | method | src/Widget/State.php:364 |
| `State::mountedCount()` | method | src/Widget/State.php:378 |
| `State::noteRebuilt()` | method | src/Widget/State.php:384 |
| `State::enterBuild()` | method | src/Widget/State.php:396 |
| `State::leaveBuild()` | method | src/Widget/State.php:407 |
| `State::didUpdateWidget()` | method | src/Widget/State.php:413 |
| `StatefulElement::$boxFromState` | property | src/Widget/StatefulElement.php:18 |
| `StatefulElement::syncBox()` | method | src/Widget/StatefulElement.php:45 |
| `TextFieldBox` | class | src/Widget/TextFieldBox.php:17 |
| `TextFieldBox::make()` | method | src/Widget/TextFieldBox.php:54 |
| `TextFieldState::$buffer` | property | src/Widget/TextFieldState.php:39 |
| `TextFieldState::$caret` | property | src/Widget/TextFieldState.php:42 |
| `TextFieldState::$editing` | property | src/Widget/TextFieldState.php:45 |
| `TextFieldState::$selAnchor` | property | src/Widget/TextFieldState.php:48 |
| `TextFieldState::$selExtent` | property | src/Widget/TextFieldState.php:51 |
| `Widget::$sealed` | property | src/Widget/Widget.php:30 |
| `Widget::$key` | property | src/Widget/Widget.php:43 |
| `Widget::$focus` | property | src/Widget/Widget.php:46 |
| `Widget::$cursor` | property | src/Widget/Widget.php:49 |
| `Widget::$focusable` | property | src/Widget/Widget.php:52 |
| `Widget::$draggable` | property | src/Widget/Widget.php:55 |
| `Widget::$dragTarget` | property | src/Widget/Widget.php:58 |
| `Widget::$contextMenu` | property | src/Widget/Widget.php:61 |
| `Widget::$hoverHandler` | property | src/Widget/Widget.php:64 |
| `Widget::$pointerHandler` | property | src/Widget/Widget.php:67 |
| `Widget::$pointerOwnsPress` | property | src/Widget/Widget.php:76 |
| `Widget::$keyHandler` | property | src/Widget/Widget.php:89 |
| `Widget::$tooltipText` | property | src/Widget/Widget.php:97 |
| `Widget::$overlayPanelFactory` | property | src/Widget/Widget.php:109 |
| `Widget::$overlaySignature` | property | src/Widget/Widget.php:119 |
| `Widget::$semRole` | property | src/Widget/Widget.php:129 |
| `Widget::$semName` | property | src/Widget/Widget.php:132 |
| `Widget::$semValue` | property | src/Widget/Widget.php:135 |
| `Widget::$semEnabled` | property | src/Widget/Widget.php:138 |
| `Widget::$semRangeMin` | property | src/Widget/Widget.php:141 |
| `Widget::__clone()` | method | src/Widget/Widget.php:164 |
| `Widget::childContext()` | method | src/Widget/Widget.php:197 |
| `Widget::createElement()` | method | src/Widget/Widget.php:212 |
| `Widget::identity()` | method | src/Widget/Widget.php:228 |
| `Widget::seal()` | method | src/Widget/Widget.php:238 |
| `Widget::isSealed()` | method | src/Widget/Widget.php:252 |
| `Widget::focusNode()` | method | src/Widget/Widget.php:333 |
| `Widget::cursorShape()` | method | src/Widget/Widget.php:363 |
| `Widget::mods()` | method | src/Widget/Widget.php:388 |
| `Widget::expandChildren()` | method | src/Widget/Widget.php:394 |
| `Widget::draggableValue()` | method | src/Widget/Widget.php:761 |
| `Widget::dragTargetValue()` | method | src/Widget/Widget.php:784 |
| `Widget::contextMenuValue()` | method | src/Widget/Widget.php:807 |
| `Widget::hoverHandlerValue()` | method | src/Widget/Widget.php:836 |
| `Widget::pointerHandlerValue()` | method | src/Widget/Widget.php:876 |
| `Widget::pointerOwnsPressValue()` | method | src/Widget/Widget.php:882 |
| `Widget::keyHandlerValue()` | method | src/Widget/Widget.php:925 |
| `Widget::tooltipTextValue()` | method | src/Widget/Widget.php:962 |
| `Widget::overlayPanelValue()` | method | src/Widget/Widget.php:1019 |
| `Widget::overlaySignatureValue()` | method | src/Widget/Widget.php:1073 |
| `Widget::semanticsRole()` | method | src/Widget/Widget.php:1148 |
| `Widget::semanticsName()` | method | src/Widget/Widget.php:1154 |
| `Widget::semanticsValue()` | method | src/Widget/Widget.php:1160 |
| `Widget::semanticsEnabled()` | method | src/Widget/Widget.php:1166 |
| `Widget::semanticsRangeMin()` | method | src/Widget/Widget.php:1172 |
| `Widget::semanticsRangeMax()` | method | src/Widget/Widget.php:1178 |
| `Widget::markFocusable()` | method | src/Widget/Widget.php:1184 |
| `Widget::isFocusable()` | method | src/Widget/Widget.php:1197 |


