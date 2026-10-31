# Drift2 API 面清单（@api / @internal）

> 由 `php tools/lint.php --api` 自动生成，勿手改。
> 口径：docblock **独立标注行**（` * @api` 或单行 `/** @api … */`）；
> 行内描述性提及（如「它本身是 @internal 的实现细节」这类文字）不计入。

## 总览

| 层 | @api | @internal |
|---|---|---|
| App | 80 | 50 |
| Async | 14 | 3 |
| Commands | 7 | 0 |
| Core | 2 | 0 |
| Errors.php | 2 | 3 |
| Geometry | 9 | 0 |
| Input | 18 | 5 |
| Layout | 41 | 44 |
| Logging | 9 | 3 |
| Platform | 21 | 14 |
| Rendering | 32 | 1 |
| Routing | 8 | 6 |
| State | 18 | 11 |
| Text | 32 | 3 |
| Theme | 3 | 0 |
| Widget | 171 | 219 |
| **合计** | **467** | **362** |

覆盖 370 个文件 · 描述性提及 30 处（grep 口径的污染源，不计入）

## App 层

### @api（应用可读面） · 80

| 符号 | 种类 | 位置 |
|---|---|---|
| `Application` | class | src/App/Application.php:48 |
| `Application::commands()` | method | src/App/Application.php:399 |
| `Application::scope()` | method | src/App/Application.php:419 |
| `Application::taskHost()` | method | src/App/Application.php:448 |
| `Application::submit()` | method | src/App/Application.php:474 |
| `Application::accelerators()` | method | src/App/Application.php:551 |
| `Application::clipboard()` | method | src/App/Application.php:574 |
| `Application::metrics()` | method | src/App/Application.php:596 |
| `Application::attach()` | method | src/App/Application.php:617 |
| `Application::onSystemThemeChanged()` | method | src/App/Application.php:737 |
| `Application::onCloseRequested()` | method | src/App/Application.php:753 |
| `Application::frame()` | method | src/App/Application.php:821 |
| `Application::repaintForResize()` | method | src/App/Application.php:1059 |
| `Application::after()` | method | src/App/Application.php:1115 |
| `Application::wantsFrame()` | method | src/App/Application.php:1149 |
| `Application::nextDeadlineMs()` | method | src/App/Application.php:1232 |
| `Application::io()` | method | src/App/Application.php:1579 |
| `Application::capturePointer()` | method | src/App/Application.php:1595 |
| `Application::releasePointer()` | method | src/App/Application.php:1617 |
| `Application::dispatchInput()` | method | src/App/Application.php:1636 |
| `Application::dispatch()` | method | src/App/Application.php:1803 |
| `Application::showMenu()` | method | src/App/Application.php:1929 |
| `Application::showSnackbar()` | method | src/App/Application.php:1945 |
| `Application::overlays()` | method | src/App/Application.php:1979 |
| `Application::showModal()` | method | src/App/Application.php:1999 |
| `Application::closeModal()` | method | src/App/Application.php:2009 |
| `AppScope` | class | src/App/AppScope.php:18 |
| `EventLoop` | class | src/App/EventLoop.php:10 |
| `Frame` | class | src/App/Frame.php:18 |
| `Frame::run()` | method | src/App/Frame.php:174 |
| `FrameClock` | class | src/App/FrameClock.php:9 |
| `FramePhase` | class | src/App/FramePhase.php:8 |
| `Http` | class | src/App/Io/Http.php:6 |
| `Http::get()` | method | src/App/Io/Http.php:79 |
| `Http::post()` | method | src/App/Io/Http.php:93 |
| `Http::request()` | method | src/App/Io/Http.php:111 |
| `HttpException` | class | src/App/Io/HttpException.php:6 |
| `HttpException::url()` | method | src/App/Io/HttpException.php:52 |
| `HttpException::status()` | method | src/App/Io/HttpException.php:62 |
| `HttpResponse` | class | src/App/Io/HttpResponse.php:6 |
| `HttpResponse::status()` | method | src/App/Io/HttpResponse.php:83 |
| `HttpResponse::ok()` | method | src/App/Io/HttpResponse.php:93 |
| `HttpResponse::header()` | method | src/App/Io/HttpResponse.php:107 |
| `HttpResponse::headers()` | method | src/App/Io/HttpResponse.php:117 |
| `HttpResponse::body()` | method | src/App/Io/HttpResponse.php:127 |
| `HttpResponse::url()` | method | src/App/Io/HttpResponse.php:137 |
| `HttpResponse::elapsedMs()` | method | src/App/Io/HttpResponse.php:147 |
| `HttpResponse::json()` | method | src/App/Io/HttpResponse.php:157 |
| `HttpResponse::snippet()` | method | src/App/Io/HttpResponse.php:191 |
| `IoPoller` | class | src/App/Io/IoPoller.php:6 |
| `IoPoller::watch()` | method | src/App/Io/IoPoller.php:39 |
| `IoPoller::unwatch()` | method | src/App/Io/IoPoller.php:68 |
| `Job` | interface | src/App/Job.php:6 |
| `Job::run()` | method | src/App/Job.php:24 |
| `NamedJob` | interface | src/App/NamedJob.php:6 |
| `Paths` | class | src/App/Paths.php:9 |
| `Paths::userConfig()` | method | src/App/Paths.php:32 |
| `ProcessTaskHost` | class | src/App/ProcessTaskHost.php:9 |
| `ProcessTaskHost::$requestTerminateMs` | property | src/App/ProcessTaskHost.php:105 |
| `ProcessTaskHost::__construct()` | method | src/App/ProcessTaskHost.php:137 |
| `ProcessTaskHost::prewarm()` | method | src/App/ProcessTaskHost.php:176 |
| `ProcessTaskHost::shutdown()` | method | src/App/ProcessTaskHost.php:460 |
| `ProcessWorker` | class | src/App/ProcessWorker.php:8 |
| `ProcessWorker::serve()` | method | src/App/ProcessWorker.php:32 |
| `PumpHost::after()` | method | src/App/PumpHost.php:115 |
| `PumpHost::io()` | method | src/App/PumpHost.php:292 |
| `SyncTaskHost` | class | src/App/SyncTaskHost.php:9 |
| `TaskHost` | interface | src/App/TaskHost.php:8 |
| `TaskHost::submit()` | method | src/App/TaskHost.php:52 |
| `TaskHost::pending()` | method | src/App/TaskHost.php:90 |
| `TaskHost::drain()` | method | src/App/TaskHost.php:118 |
| `ThreadTaskHost` | class | src/App/ThreadTaskHost.php:15 |
| `ThreadTaskHost::__construct()` | method | src/App/ThreadTaskHost.php:102 |
| `ThreadTaskHost::shutdown()` | method | src/App/ThreadTaskHost.php:277 |
| `Window` | class | src/App/Window.php:10 |
| `WindowSet` | class | src/App/WindowSet.php:9 |
| `WindowStateStore` | class | src/App/WindowStateStore.php:32 |
| `WindowStateStore::__construct()` | method | src/App/WindowStateStore.php:124 |
| `WindowStateStore::restore()` | method | src/App/WindowStateStore.php:147 |
| `WindowStateStore::persist()` | method | src/App/WindowStateStore.php:219 |

### @internal（框架内部） · 50

| 符号 | 种类 | 位置 |
|---|---|---|
| `Application::$zeroViewportFrames` | property | src/App/Application.php:289 |
| `Application::focus()` | method | src/App/Application.php:375 |
| `Application::tasks()` | method | src/App/Application.php:522 |
| `Application::lastTimestampMs()` | method | src/App/Application.php:532 |
| `Application::lastInputMs()` | method | src/App/Application.php:542 |
| `Application::uiThreadId()` | method | src/App/Application.php:689 |
| `Application::assertUiThread()` | method | src/App/Application.php:699 |
| `Application::closeRequested()` | method | src/App/Application.php:785 |
| `Application::zeroViewportFrames()` | method | src/App/Application.php:1319 |
| `Application::semanticsMode()` | method | src/App/Application.php:1514 |
| `Application::captureNode()` | method | src/App/Application.php:1629 |
| `Application::register()` | method | src/App/Application.php:1856 |
| `Application::beginDrag()` | method | src/App/Application.php:1879 |
| `Application::updateDrag()` | method | src/App/Application.php:1887 |
| `Application::dropDrag()` | method | src/App/Application.php:1895 |
| `Application::cancelDrag()` | method | src/App/Application.php:1905 |
| `Application::rootBox()` | method | src/App/Application.php:1912 |
| `Application::dismissOverlay()` | method | src/App/Application.php:2019 |
| `Application::toggleWidgetPanel()` | method | src/App/Application.php:2041 |
| `Completion` | class | src/App/Completion.php:8 |
| `CompletionQueue` | class | src/App/CompletionQueue.php:6 |
| `DragController` | class | src/App/DragController.php:10 |
| `FocusManager` | class | src/App/FocusManager.php:18 |
| `Frame::$settleRunCount` | property | src/App/Frame.php:60 |
| `Frame::$settleSkipCount` | property | src/App/Frame.php:63 |
| `Frame::$paintRunCount` | property | src/App/Frame.php:98 |
| `Frame::$paintSkipCount` | property | src/App/Frame.php:101 |
| `Frame::settleRunCount()` | method | src/App/Frame.php:150 |
| `Frame::settleSkipCount()` | method | src/App/Frame.php:156 |
| `Frame::paintRunCount()` | method | src/App/Frame.php:162 |
| `Frame::paintSkipCount()` | method | src/App/Frame.php:168 |
| `FrameClock::stallCount()` | method | src/App/FrameClock.php:186 |
| `FrameClock::worstGapMs()` | method | src/App/FrameClock.php:192 |
| `InputHost` | interface | src/App/InputHost.php:10 |
| `InputRouter` | class | src/App/InputRouter.php:24 |
| `OverlayHost` | interface | src/App/OverlayHost.php:10 |
| `OverlayLayout` | class | src/App/OverlayLayout.php:10 |
| `OverlayLayout::syncPanelSignatures()` | method | src/App/OverlayLayout.php:100 |
| `OverlayManager` | class | src/App/OverlayManager.php:14 |
| `OverlayManager::onHoverHit()` | method | src/App/OverlayManager.php:153 |
| `OverlayManager::dismissOverlay()` | method | src/App/OverlayManager.php:558 |
| `OverlayManager::focusTrapRoots()` | method | src/App/OverlayManager.php:571 |
| `OverlayManager::toggleWidgetPanel()` | method | src/App/OverlayManager.php:642 |
| `PumpHost` | class | src/App/PumpHost.php:10 |
| `PumpHostHost` | interface | src/App/PumpHostHost.php:6 |
| `ScrollInputRouter` | class | src/App/ScrollInputRouter.php:17 |
| `TextEditingBridge` | class | src/App/TextEditingBridge.php:14 |
| `TextEditingHost` | interface | src/App/TextEditingHost.php:8 |
| `Window::__construct()` | method | src/App/Window.php:36 |
| `Window::mount()` | method | src/App/Window.php:110 |

## Async 层

### @api（应用可读面） · 14

| 符号 | 种类 | 位置 |
|---|---|---|
| `AsyncValue` | class | src/Async/AsyncValue.php:6 |
| `AsyncValue::carriedFrom()` | method | src/Async/AsyncValue.php:145 |
| `AsyncValue::fold()` | method | src/Async/AsyncValue.php:241 |
| `AsyncValue::stateName()` | method | src/Async/AsyncValue.php:348 |
| `Future` | class | src/Async/Future.php:8 |
| `Future::pending()` | method | src/Async/Future.php:112 |
| `Future::resolved()` | method | src/Async/Future.php:131 |
| `Future::rejected()` | method | src/Async/Future.php:144 |
| `Future::then()` | method | src/Async/Future.php:160 |
| `Future::catch()` | method | src/Async/Future.php:177 |
| `Future::cancel()` | method | src/Async/Future.php:191 |
| `Future::all()` | method | src/Async/Future.php:331 |
| `Future::allSettled()` | method | src/Async/Future.php:396 |
| `Future::race()` | method | src/Async/Future.php:475 |

### @internal（框架内部） · 3

| 符号 | 种类 | 位置 |
|---|---|---|
| `Future::__construct()` | method | src/Async/Future.php:102 |
| `Future::resolve()` | method | src/Async/Future.php:523 |
| `Future::reject()` | method | src/Async/Future.php:548 |

## Commands 层

### @api（应用可读面） · 7

| 符号 | 种类 | 位置 |
|---|---|---|
| `CommandArg` | class | src/Commands/CommandArg.php:6 |
| `CommandHandler` | interface | src/Commands/CommandHandler.php:6 |
| `CommandHandler::handle()` | method | src/Commands/CommandHandler.php:22 |
| `CommandRouter` | class | src/Commands/CommandRouter.php:8 |
| `CommandRouter::make()` | method | src/Commands/CommandRouter.php:48 |
| `CommandRouter::on()` | method | src/Commands/CommandRouter.php:58 |
| `CommandRouter::dispatch()` | method | src/Commands/CommandRouter.php:90 |

## Core 层

### @api（应用可读面） · 2

| 符号 | 种类 | 位置 |
|---|---|---|
| `Computed` | class | src/Core/Computed.php:8 |
| `Versioned` | interface | src/Core/Versioned.php:6 |

## Errors.php 层

### @api（应用可读面） · 2

| 符号 | 种类 | 位置 |
|---|---|---|
| `Errors` | class | src/Errors.php:9 |
| `Errors::installFatalBox()` | method | src/Errors.php:91 |

### @internal（框架内部） · 3

| 符号 | 种类 | 位置 |
|---|---|---|
| `Errors::setLogPath()` | method | src/Errors.php:122 |
| `Errors::recentDiagnostics()` | method | src/Errors.php:133 |
| `Errors::reset()` | method | src/Errors.php:139 |

## Geometry 层

### @api（应用可读面） · 9

| 符号 | 种类 | 位置 |
|---|---|---|
| `Alignment` | class | src/Geometry/Alignment.php:6 |
| `Axis` | enum | src/Geometry/Axis.php:6 |
| `BoxConstraints` | class | src/Geometry/BoxConstraints.php:8 |
| `EdgeInsets` | class | src/Geometry/EdgeInsets.php:6 |
| `Matrix2D` | class | src/Geometry/Matrix2D.php:8 |
| `Point` | class | src/Geometry/Point.php:6 |
| `Rect` | class | src/Geometry/Rect.php:6 |
| `Scale` | class | src/Geometry/Scale.php:6 |
| `Size` | class | src/Geometry/Size.php:6 |

## Input 层

### @api（应用可读面） · 18

| 符号 | 种类 | 位置 |
|---|---|---|
| `AcceleratorTable` | class | src/Input/AcceleratorTable.php:8 |
| `CloseRequestedEvent` | class | src/Input/CloseRequestedEvent.php:6 |
| `CursorShape` | enum | src/Input/CursorShape.php:6 |
| `FilesDroppedEvent` | class | src/Input/FilesDroppedEvent.php:6 |
| `FocusNode` | class | src/Input/FocusNode.php:6 |
| `InputEvent` | interface | src/Input/InputEvent.php:6 |
| `KeyChord` | class | src/Input/KeyChord.php:6 |
| `KeyEvent` | class | src/Input/KeyEvent.php:6 |
| `PointerEvent` | class | src/Input/PointerEvent.php:6 |
| `PointerPhase` | enum | src/Input/PointerPhase.php:6 |
| `ScrollEvent` | class | src/Input/ScrollEvent.php:8 |
| `SemanticsInvokeEvent` | class | src/Input/SemanticsInvokeEvent.php:6 |
| `SemanticsNode` | class | src/Input/SemanticsNode.php:6 |
| `SemanticsRole` | enum | src/Input/SemanticsRole.php:6 |
| `SemanticsSetValueEvent` | class | src/Input/SemanticsSetValueEvent.php:6 |
| `SemanticsTree` | class | src/Input/SemanticsTree.php:6 |
| `ThemeChangedEvent` | class | src/Input/ThemeChangedEvent.php:6 |
| `WindowDeactivatedEvent` | class | src/Input/WindowDeactivatedEvent.php:6 |

### @internal（框架内部） · 5

| 符号 | 种类 | 位置 |
|---|---|---|
| `GestureArena` | class | src/Input/GestureArena.php:6 |
| `GestureRecognizer` | class | src/Input/GestureRecognizer.php:6 |
| `GestureRecognizer::claims()` | method | src/Input/GestureRecognizer.php:58 |
| `GestureRecognizer::win()` | method | src/Input/GestureRecognizer.php:64 |
| `TapTracker` | class | src/Input/TapTracker.php:8 |

## Layout 层

### @api（应用可读面） · 41

| 符号 | 种类 | 位置 |
|---|---|---|
| `ColumnWidths` | class | src/Layout/ColumnWidths.php:6 |
| `ExtentWindow` | class | src/Layout/ExtentWindow.php:6 |
| `FlexAlign` | enum | src/Layout/FlexAlign.php:6 |
| `ImageFit` | enum | src/Layout/ImageFit.php:6 |
| `LayoutSelfCheck` | class | src/Layout/LayoutSelfCheck.php:9 |
| `Overflow` | enum | src/Layout/Overflow.php:6 |
| `RenderAppBar` | class | src/Layout/RenderAppBar.php:13 |
| `RenderBackdropFilter` | class | src/Layout/RenderBackdropFilter.php:11 |
| `RenderBox` | class | src/Layout/RenderBox.php:15 |
| `RenderBox::overflow()` | method | src/Layout/RenderBox.php:727 |
| `RenderBox::setCompose()` | method | src/Layout/RenderBox.php:890 |
| `RenderBox::paintComposed()` | method | src/Layout/RenderBox.php:1073 |
| `RenderBox::hitTest()` | method | src/Layout/RenderBox.php:1182 |
| `RenderButton` | class | src/Layout/RenderButton.php:19 |
| `RenderDivider` | class | src/Layout/RenderDivider.php:13 |
| `RenderDock` | class | src/Layout/RenderDock.php:12 |
| `RenderFlex` | class | src/Layout/RenderFlex.php:12 |
| `RenderIcon` | class | src/Layout/RenderIcon.php:13 |
| `RenderImage` | class | src/Layout/RenderImage.php:13 |
| `RenderMenuItem` | class | src/Layout/RenderMenuItem.php:16 |
| `RenderObject` | class | src/Layout/RenderObject.php:11 |
| `RenderObject::cursor()` | method | src/Layout/RenderObject.php:122 |
| `RenderObject::cursorAt()` | method | src/Layout/RenderObject.php:144 |
| `RenderObject::panelRect()` | method | src/Layout/RenderObject.php:161 |
| `RenderObject::acceptsHover()` | method | src/Layout/RenderObject.php:184 |
| `RenderPopupPanel` | class | src/Layout/RenderPopupPanel.php:16 |
| `RenderProgress` | class | src/Layout/RenderProgress.php:13 |
| `RenderProxy` | class | src/Layout/RenderProxy.php:9 |
| `RenderProxyBox` | class | src/Layout/RenderProxyBox.php:8 |
| `RenderRichText` | class | src/Layout/RenderRichText.php:19 |
| `RenderScrollView` | class | src/Layout/RenderScrollView.php:17 |
| `RenderSegment` | class | src/Layout/RenderSegment.php:15 |
| `RenderSegmentedControl` | class | src/Layout/RenderSegmentedControl.php:13 |
| `RenderSlider` | class | src/Layout/RenderSlider.php:13 |
| `RenderSpinner` | class | src/Layout/RenderSpinner.php:13 |
| `RenderStack` | class | src/Layout/RenderStack.php:11 |
| `RenderText` | class | src/Layout/RenderText.php:16 |
| `RenderTextField` | class | src/Layout/RenderTextField.php:20 |
| `RenderToggle` | class | src/Layout/RenderToggle.php:16 |
| `RenderWrap` | class | src/Layout/RenderWrap.php:11 |
| `StackFit` | enum | src/Layout/StackFit.php:6 |

### @internal（框架内部） · 44

| 符号 | 种类 | 位置 |
|---|---|---|
| `LayoutTolerance` | class | src/Layout/LayoutTolerance.php:8 |
| `LayoutTolerance::reset()` | method | src/Layout/LayoutTolerance.php:116 |
| `RenderBox::layoutEpoch()` | method | src/Layout/RenderBox.php:213 |
| `RenderBox::layoutCount()` | method | src/Layout/RenderBox.php:219 |
| `RenderBox::paintCount()` | method | src/Layout/RenderBox.php:236 |
| `RenderBox::replayCount()` | method | src/Layout/RenderBox.php:242 |
| `RenderBox::markNeedsLayout()` | method | src/Layout/RenderBox.php:248 |
| `RenderBox::clipsOverflow()` | method | src/Layout/RenderBox.php:738 |
| `RenderBox::invalidateLayerCache()` | method | src/Layout/RenderBox.php:1050 |
| `RenderCustomPaint` | class | src/Layout/RenderCustomPaint.php:12 |
| `RenderDock::slot()` | method | src/Layout/RenderDock.php:44 |
| `RenderFlex::clearChildren()` | method | src/Layout/RenderFlex.php:98 |
| `RenderModalHost` | class | src/Layout/RenderModalHost.php:14 |
| `RenderObject::$cursor` | property | src/Layout/RenderObject.php:33 |
| `RenderObject::markNeedsPaint()` | method | src/Layout/RenderObject.php:93 |
| `RenderObject::paintEpoch()` | method | src/Layout/RenderObject.php:111 |
| `RenderObject::setCursor()` | method | src/Layout/RenderObject.php:136 |
| `RenderObject::setHovered()` | method | src/Layout/RenderObject.php:201 |
| `RenderObject::adoptChild()` | method | src/Layout/RenderObject.php:277 |
| `RenderRichText::styleSize()` | method | src/Layout/RenderRichText.php:50 |
| `RenderRichText::lineCount()` | method | src/Layout/RenderRichText.php:56 |
| `RenderRichText::paragraph()` | method | src/Layout/RenderRichText.php:62 |
| `RenderRichText::spans()` | method | src/Layout/RenderRichText.php:68 |
| `RenderScrollView::setBarOpacity()` | method | src/Layout/RenderScrollView.php:152 |
| `RenderScrollView::scrollAxes()` | method | src/Layout/RenderScrollView.php:172 |
| `RenderScrollView::thumbRectInRoot()` | method | src/Layout/RenderScrollView.php:399 |
| `RenderSegmentedControl::clearChildren()` | method | src/Layout/RenderSegmentedControl.php:66 |
| `RenderStack::clearChildren()` | method | src/Layout/RenderStack.php:81 |
| `RenderTable` | class | src/Layout/RenderTable.php:14 |
| `RenderTable::setHeader()` | method | src/Layout/RenderTable.php:233 |
| `RenderTable::setBody()` | method | src/Layout/RenderTable.php:243 |
| `RenderTable::setBarOpacity()` | method | src/Layout/RenderTable.php:254 |
| `RenderTable::focusRect()` | method | src/Layout/RenderTable.php:265 |
| `RenderTable::scrollAxes()` | method | src/Layout/RenderTable.php:276 |
| `RenderTable::thumbRectInRoot()` | method | src/Layout/RenderTable.php:322 |
| `RenderText::styleSize()` | method | src/Layout/RenderText.php:50 |
| `RenderText::lineCount()` | method | src/Layout/RenderText.php:56 |
| `RenderTextField::styleSize()` | method | src/Layout/RenderTextField.php:259 |
| `RenderTextField::isShowingPlaceholder()` | method | src/Layout/RenderTextField.php:265 |
| `RenderWrap::clearChildren()` | method | src/Layout/RenderWrap.php:96 |
| `ScrollBarGeometry` | class | src/Layout/ScrollBarGeometry.php:10 |
| `ScrollBarHost` | interface | src/Layout/ScrollBarHost.php:9 |
| `ScrollBarHost::setScrollOffset()` | method | src/Layout/ScrollBarHost.php:68 |
| `ScrollBarHost::setBarOpacity()` | method | src/Layout/ScrollBarHost.php:94 |

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

### @api（应用可读面） · 21

| 符号 | 种类 | 位置 |
|---|---|---|
| `Backdrop` | enum | src/Platform/Backdrop.php:6 |
| `Clipboard` | interface | src/Platform/Clipboard.php:6 |
| `Dialog` | interface | src/Platform/Dialog.php:6 |
| `InputSource` | interface | src/Platform/InputSource.php:8 |
| `Platform` | interface | src/Platform/Platform.php:6 |
| `Platform::consumeModalBlockedMs()` | method | src/Platform/Platform.php:116 |
| `Screen` | interface | src/Platform/Screen.php:9 |
| `SurfaceProvider` | interface | src/Platform/SurfaceProvider.php:8 |
| `TimerHost` | interface | src/Platform/TimerHost.php:6 |
| `Tray` | interface | src/Platform/Tray.php:6 |
| `WakeReason` | enum | src/Platform/WakeReason.php:6 |
| `Win32Platform` | class | src/Platform/Win32/Win32Platform.php:35 |
| `WindowHost` | interface | src/Platform/WindowHost.php:11 |
| `WindowHost::close()` | method | src/Platform/WindowHost.php:42 |
| `WindowHost::isAlive()` | method | src/Platform/WindowHost.php:74 |
| `WindowHost::setCloseGuard()` | method | src/Platform/WindowHost.php:90 |
| `WindowHost::setMinSize()` | method | src/Platform/WindowHost.php:111 |
| `WindowHost::setCursor()` | method | src/Platform/WindowHost.php:211 |
| `WindowHost::pushSemantics()` | method | src/Platform/WindowHost.php:223 |
| `WindowMaterial` | class | src/Platform/WindowMaterial.php:8 |
| `WindowMaterialCapabilities` | class | src/Platform/WindowMaterialCapabilities.php:6 |

### @internal（框架内部） · 14

| 符号 | 种类 | 位置 |
|---|---|---|
| `Platform::imeCaret()` | method | src/Platform/Platform.php:163 |
| `ResizeHookState::pump()` | method | src/Platform/Win32/ResizeHookState.php:31 |
| `Win32Clipboard` | class | src/Platform/Win32/Win32Clipboard.php:12 |
| `Win32Dialog` | class | src/Platform/Win32/Win32Dialog.php:13 |
| `Win32Dialog::consumeModalBlockedMs()` | method | src/Platform/Win32/Win32Dialog.php:49 |
| `Win32InputSource` | class | src/Platform/Win32/Win32InputSource.php:9 |
| `Win32RasterSurface` | class | src/Platform/Win32/Win32RasterSurface.php:14 |
| `Win32Screen` | class | src/Platform/Win32/Win32Screen.php:15 |
| `Win32SkiaSurface` | class | src/Platform/Win32/Win32SkiaSurface.php:15 |
| `Win32SurfaceProvider` | class | src/Platform/Win32/Win32SurfaceProvider.php:9 |
| `Win32SurfaceProvider::resolveBackend()` | method | src/Platform/Win32/Win32SurfaceProvider.php:75 |
| `Win32TimerHost` | class | src/Platform/Win32/Win32TimerHost.php:11 |
| `Win32WindowHost` | class | src/Platform/Win32/Win32WindowHost.php:39 |
| `WindowHost::destroy()` | method | src/Platform/WindowHost.php:60 |

## Rendering 层

### @api（应用可读面） · 32

| 符号 | 种类 | 位置 |
|---|---|---|
| `AnimatedValue` | class | src/Rendering/AnimatedValue.php:6 |
| `BoxStyle` | class | src/Rendering/BoxStyle.php:6 |
| `Canvas` | interface | src/Rendering/Canvas.php:10 |
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
| `Modifier` | class | src/Rendering/Modifier.php:14 |
| `Modifier::withBoxStyleIfBlank()` | method | src/Rendering/Modifier.php:499 |
| `Paint` | class | src/Rendering/Paint.php:8 |
| `PaintStyle` | enum | src/Rendering/PaintStyle.php:6 |
| `Path` | class | src/Rendering/Path.php:8 |
| `PngDecoder` | class | src/Rendering/PngDecoder.php:8 |
| `ProgressStyle` | class | src/Rendering/ProgressStyle.php:6 |
| `RasterCanvas` | class | src/Rendering/RasterCanvas.php:13 |
| `RecordingCanvas` | class | src/Rendering/RecordingCanvas.php:10 |
| `RenderSurface` | interface | src/Rendering/RenderSurface.php:7 |
| `SegmentedStyle` | class | src/Rendering/SegmentedStyle.php:6 |
| `Shadow` | class | src/Rendering/Shadow.php:9 |
| `ShadowPainter` | class | src/Rendering/ShadowPainter.php:8 |
| `SkiaCanvas` | class | src/Rendering/SkiaCanvas.php:40 |
| `SkiaCanvas::handle()` | method | src/Rendering/SkiaCanvas.php:125 |
| `SurfaceProfile` | class | src/Rendering/SurfaceProfile.php:6 |
| `ToggleStyle` | class | src/Rendering/ToggleStyle.php:6 |

### @internal（框架内部） · 1

| 符号 | 种类 | 位置 |
|---|---|---|
| `LayerCache` | class | src/Rendering/LayerCache.php:6 |

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

### @api（应用可读面） · 18

| 符号 | 种类 | 位置 |
|---|---|---|
| `AsyncCommand` | class | src/State/AsyncCommand.php:9 |
| `AsyncCommand::make()` | method | src/State/AsyncCommand.php:197 |
| `AsyncCommand::value()` | method | src/State/AsyncCommand.php:231 |
| `AsyncCommand::isRunning()` | method | src/State/AsyncCommand.php:241 |
| `AsyncCommand::run()` | method | src/State/AsyncCommand.php:251 |
| `AsyncCommand::append()` | method | src/State/AsyncCommand.php:291 |
| `AsyncCommand::retry()` | method | src/State/AsyncCommand.php:340 |
| `AsyncCommand::cancel()` | method | src/State/AsyncCommand.php:360 |
| `AsyncCommand::dispose()` | method | src/State/AsyncCommand.php:382 |
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
| `Subject::$version` | property | src/State/Subject.php:38 |
| `Subject::unsubscribeId()` | method | src/State/Subject.php:94 |
| `SubjectBuilder::source()` | method | src/State/SubjectBuilder.php:96 |
| `SubjectBuilder::selectFn()` | method | src/State/SubjectBuilder.php:102 |
| `SubjectBuilder::builderFn()` | method | src/State/SubjectBuilder.php:108 |
| `SubjectBuilderState` | class | src/State/SubjectBuilder.php:120 |
| `Subscription::of()` | method | src/State/Subscription.php:30 |

## Text 层

### @api（应用可读面） · 32

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
| `TextBoundary` | class | src/Text/TextBoundary.php:6 |
| `TextChange` | enum | src/Text/TextChange.php:6 |
| `TextDraft` | class | src/Text/TextDraft.php:6 |
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

### @api（应用可读面） · 171

| 符号 | 种类 | 位置 |
|---|---|---|
| `Activatable` | interface | src/Widget/Activatable.php:6 |
| `ActivationContext` | class | src/Widget/ActivationContext.php:8 |
| `Align` | class | src/Widget/Align.php:10 |
| `AnimatedOpacity` | class | src/Widget/AnimatedOpacity.php:8 |
| `AnimationController` | class | src/Widget/AnimationController.php:6 |
| `AppBar` | class | src/Widget/AppBar.php:13 |
| `AspectRatio` | class | src/Widget/AspectRatio.php:9 |
| `BackdropFilter` | class | src/Widget/BackdropFilter.php:10 |
| `BackdropFilter::make()` | method | src/Widget/BackdropFilter.php:52 |
| `BackdropFilter::sigma()` | method | src/Widget/BackdropFilter.php:67 |
| `BuildContext` | class | src/Widget/BuildContext.php:13 |
| `Button` | class | src/Widget/Button.php:19 |
| `Button::enabled()` | method | src/Widget/Button.php:146 |
| `ButtonVariant` | enum | src/Widget/ButtonVariant.php:6 |
| `CalendarView` | class | src/Widget/CalendarView.php:9 |
| `Card` | class | src/Widget/Card.php:11 |
| `Center` | class | src/Widget/Center.php:8 |
| `Checkbox` | class | src/Widget/Checkbox.php:9 |
| `Column` | class | src/Widget/Column.php:6 |
| `ComboBox` | class | src/Widget/ComboBox.php:11 |
| `ComboBoxState` | class | src/Widget/ComboBox.php:334 |
| `Curve` | class | src/Widget/Curve.php:6 |
| `CustomPaint` | class | src/Widget/CustomPaint.php:9 |
| `CustomPainter` | class | src/Widget/CustomPainter.php:9 |
| `DatePicker` | class | src/Widget/DatePicker.php:8 |
| `DateTimePicker` | class | src/Widget/DateTimePicker.php:8 |
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
| `LocalDate` | class | src/Widget/LocalDate.php:8 |
| `LocalDateTime` | class | src/Widget/LocalDateTime.php:8 |
| `LocalTime` | class | src/Widget/LocalTime.php:8 |
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
| `Opacity` | class | src/Widget/Opacity.php:9 |
| `Option` | class | src/Widget/Option.php:8 |
| `Option::normalize()` | method | src/Widget/Option.php:61 |
| `Option::fromRows()` | method | src/Widget/Option.php:159 |
| `OptionStatus` | class | src/Widget/OptionStatus.php:6 |
| `Padding` | class | src/Widget/Padding.php:10 |
| `PopupPanel` | class | src/Widget/PopupPanel.php:12 |
| `Progress` | class | src/Widget/Progress.php:12 |
| `ProxyElement` | class | src/Widget/ProxyElement.php:8 |
| `Radio` | class | src/Widget/Radio.php:9 |
| `RenderBoxWidget` | class | src/Widget/RenderBoxWidget.php:9 |
| `RichText` | class | src/Widget/RichText.php:14 |
| `Row` | class | src/Widget/Row.php:6 |
| `Scrollable` | interface | src/Widget/Scrollable.php:9 |
| `ScrollView` | class | src/Widget/ScrollView.php:8 |
| `ScrollViewState` | class | src/Widget/ScrollViewState.php:11 |
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
| `State` | class | src/Widget/State.php:14 |
| `State::markDirty()` | method | src/Widget/State.php:142 |
| `State::tickFrame()` | method | src/Widget/State.php:182 |
| `State::initState()` | method | src/Widget/State.php:222 |
| `State::didChangeFocus()` | method | src/Widget/State.php:235 |
| `State::cell()` | method | src/Widget/State.php:251 |
| `State::asyncCommand()` | method | src/Widget/State.php:269 |
| `State::watch()` | method | src/Widget/State.php:324 |
| `State::handleCommand()` | method | src/Widget/State.php:573 |
| `StatefulElement` | class | src/Widget/StatefulElement.php:6 |
| `StatefulWidget` | class | src/Widget/StatefulWidget.php:8 |
| `StatefulWidget::createRenderObjectFromState()` | method | src/Widget/StatefulWidget.php:27 |
| `StatelessElement` | class | src/Widget/StatelessElement.php:6 |
| `StatelessWidget` | class | src/Widget/StatelessWidget.php:6 |
| `States` | class | src/Widget/States.php:6 |
| `Table` | class | src/Widget/Table.php:10 |
| `TableColumn` | class | src/Widget/TableColumn.php:10 |
| `TableColumnWidth` | class | src/Widget/TableColumnWidth.php:8 |
| `TableRange` | class | src/Widget/TableRange.php:6 |
| `TableSelection` | class | src/Widget/TableSelection.php:6 |
| `TableSelectionBehavior` | enum | src/Widget/TableSelectionBehavior.php:6 |
| `TableSelectionMode` | enum | src/Widget/TableSelectionMode.php:6 |
| `TableSort` | class | src/Widget/TableSort.php:8 |
| `TableSortDirection` | enum | src/Widget/TableSortDirection.php:6 |
| `TabView` | class | src/Widget/TabView.php:6 |
| `TabView::make()` | method | src/Widget/TabView.php:91 |
| `Text` | class | src/Widget/Text.php:10 |
| `TextField` | class | src/Widget/TextField.php:9 |
| `TextFieldState` | class | src/Widget/TextFieldState.php:15 |
| `ThemeScopeElement` | class | src/Widget/ThemeScopeElement.php:6 |
| `TimeView` | class | src/Widget/TimeView.php:10 |
| `Toggle` | class | src/Widget/Toggle.php:9 |
| `ToggleBase` | class | src/Widget/ToggleBase.php:14 |
| `TooltipPanel` | class | src/Widget/TooltipPanel.php:8 |
| `WidgetFocus::focusOrder()` | method | src/Widget/Traits/WidgetFocus.php:22 |
| `WidgetFocus::skipFocus()` | method | src/Widget/Traits/WidgetFocus.php:40 |
| `WidgetFocus::claimKeys()` | method | src/Widget/Traits/WidgetFocus.php:60 |
| `WidgetFocus::cursor()` | method | src/Widget/Traits/WidgetFocus.php:77 |
| `WidgetInteraction::draggable()` | method | src/Widget/Traits/WidgetInteraction.php:23 |
| `WidgetInteraction::dragTarget()` | method | src/Widget/Traits/WidgetInteraction.php:50 |
| `WidgetInteraction::contextMenu()` | method | src/Widget/Traits/WidgetInteraction.php:73 |
| `WidgetInteraction::hover()` | method | src/Widget/Traits/WidgetInteraction.php:96 |
| `WidgetInteraction::pointer()` | method | src/Widget/Traits/WidgetInteraction.php:125 |
| `WidgetInteraction::windowDrag()` | method | src/Widget/Traits/WidgetInteraction.php:171 |
| `WidgetInteraction::onFilesDropped()` | method | src/Widget/Traits/WidgetInteraction.php:206 |
| `WidgetInteraction::keyboard()` | method | src/Widget/Traits/WidgetInteraction.php:236 |
| `WidgetInteraction::tooltip()` | method | src/Widget/Traits/WidgetInteraction.php:279 |
| `WidgetInteraction::overlayPanel()` | method | src/Widget/Traits/WidgetInteraction.php:318 |
| `WidgetInteraction::overlaySignature()` | method | src/Widget/Traits/WidgetInteraction.php:386 |
| `WidgetModifiers::padding()` | method | src/Widget/Traits/WidgetModifiers.php:36 |
| `WidgetModifiers::boxSize()` | method | src/Widget/Traits/WidgetModifiers.php:117 |
| `WidgetModifiers::background()` | method | src/Widget/Traits/WidgetModifiers.php:190 |
| `WidgetModifiers::gradient()` | method | src/Widget/Traits/WidgetModifiers.php:207 |
| `WidgetModifiers::shadow()` | method | src/Widget/Traits/WidgetModifiers.php:249 |
| `WidgetModifiers::opacity()` | method | src/Widget/Traits/WidgetModifiers.php:267 |
| `WidgetModifiers::translate()` | method | src/Widget/Traits/WidgetModifiers.php:289 |
| `WidgetModifiers::disabled()` | method | src/Widget/Traits/WidgetModifiers.php:302 |
| `WidgetModifiers::clip()` | method | src/Widget/Traits/WidgetModifiers.php:345 |
| `WidgetModifiers::repaintBoundary()` | method | src/Widget/Traits/WidgetModifiers.php:363 |
| `WidgetModifiers::alignContent()` | method | src/Widget/Traits/WidgetModifiers.php:380 |
| `WidgetModifiers::centerContent()` | method | src/Widget/Traits/WidgetModifiers.php:404 |
| `WidgetModifiers::grow()` | method | src/Widget/Traits/WidgetModifiers.php:422 |
| `WidgetModifiers::positioned()` | method | src/Widget/Traits/WidgetModifiers.php:475 |
| `WidgetModifiers::transform()` | method | src/Widget/Traits/WidgetModifiers.php:539 |
| `WidgetModifiers::rotate()` | method | src/Widget/Traits/WidgetModifiers.php:565 |
| `WidgetModifiers::scale()` | method | src/Widget/Traits/WidgetModifiers.php:578 |
| `WidgetSemantics::semantics()` | method | src/Widget/Traits/WidgetSemantics.php:21 |
| `WidgetSemantics::semanticsRange()` | method | src/Widget/Traits/WidgetSemantics.php:62 |
| `TreeNode` | class | src/Widget/TreeNode.php:8 |
| `TreeView` | class | src/Widget/TreeView.php:9 |
| `Widget` | class | src/Widget/Widget.php:21 |
| `Widget::__construct()` | method | src/Widget/Widget.php:207 |
| `Widget::key()` | method | src/Widget/Widget.php:239 |
| `Widget::mutate()` | method | src/Widget/Widget.php:316 |
| `Wrap` | class | src/Widget/Wrap.php:12 |
| `WrapElement` | class | src/Widget/WrapElement.php:8 |
| `YearMonth` | class | src/Widget/YearMonth.php:8 |

### @internal（框架内部） · 219

| 符号 | 种类 | 位置 |
|---|---|---|
| `AnimatedScalar` | class | src/Widget/AnimatedScalar.php:8 |
| `AnimationController::start()` | method | src/Widget/AnimationController.php:256 |
| `AnimationController::finish()` | method | src/Widget/AnimationController.php:279 |
| `AppBar::titleText()` | method | src/Widget/AppBar.php:125 |
| `AppBar::kidList()` | method | src/Widget/AppBar.php:131 |
| `AppBar::actionList()` | method | src/Widget/AppBar.php:137 |
| `AppBar::barKids()` | method | src/Widget/AppBar.php:143 |
| `AppBar::paintsBoxChrome()` | method | src/Widget/AppBar.php:224 |
| `AppBarElement` | class | src/Widget/AppBarElement.php:8 |
| `BuildContext::withTheme()` | method | src/Widget/BuildContext.php:48 |
| `Button::paintsBoxChrome()` | method | src/Widget/Button.php:356 |
| `CalendarView::selectedValue()` | method | src/Widget/CalendarView.php:137 |
| `CalendarView::onSelectedHandler()` | method | src/Widget/CalendarView.php:143 |
| `CalendarView::onMonthChangeHandler()` | method | src/Widget/CalendarView.php:149 |
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
| `DatePicker::valueOf()` | method | src/Widget/DatePicker.php:125 |
| `DatePicker::initialMonthValue()` | method | src/Widget/DatePicker.php:131 |
| `DatePicker::placeholderText()` | method | src/Widget/DatePicker.php:137 |
| `DatePicker::widthValue()` | method | src/Widget/DatePicker.php:143 |
| `DatePicker::onChangedHandler()` | method | src/Widget/DatePicker.php:149 |
| `DatePickerState` | class | src/Widget/DatePicker.php:169 |
| `DatePickerState::$signal` | property | src/Widget/DatePicker.php:185 |
| `DateTimePicker::valueOf()` | method | src/Widget/DateTimePicker.php:145 |
| `DateTimePicker::initialMonthValue()` | method | src/Widget/DateTimePicker.php:151 |
| `DateTimePicker::placeholderText()` | method | src/Widget/DateTimePicker.php:157 |
| `DateTimePicker::widthValue()` | method | src/Widget/DateTimePicker.php:163 |
| `DateTimePicker::showsSeconds()` | method | src/Widget/DateTimePicker.php:169 |
| `DateTimePicker::onChangedHandler()` | method | src/Widget/DateTimePicker.php:175 |
| `DateTimePickerState` | class | src/Widget/DateTimePicker.php:194 |
| `Dock::slotKid()` | method | src/Widget/Dock.php:93 |
| `Dock::overflowValue()` | method | src/Widget/Dock.php:106 |
| `DockElement` | class | src/Widget/DockElement.php:8 |
| `Element::$box` | property | src/Widget/Element.php:56 |
| `Element::bumpStructure()` | method | src/Widget/Element.php:84 |
| `Element::structureVersion()` | method | src/Widget/Element.php:90 |
| `Element::$pairChecks` | property | src/Widget/Element.php:96 |
| `Element::pairChecks()` | method | src/Widget/Element.php:109 |
| `Element::onMount()` | method | src/Widget/Element.php:132 |
| `Element::syncBox()` | method | src/Widget/Element.php:151 |
| `Element::effectiveBox()` | method | src/Widget/Element.php:229 |
| `Element::markNeedsBuild()` | method | src/Widget/Element.php:256 |
| `Element::findBox()` | method | src/Widget/Element.php:275 |
| `Element::findBoxOf()` | method | src/Widget/Element.php:305 |
| `Element::effectiveBoxOf()` | method | src/Widget/Element.php:323 |
| `Element::adoptBoxes()` | method | src/Widget/Element.php:341 |
| `Element::didUpdateWidget()` | method | src/Widget/Element.php:367 |
| `Element::applyContext()` | method | src/Widget/Element.php:383 |
| `Element::settle()` | method | src/Widget/Element.php:425 |
| `Grid::rowOf()` | method | src/Widget/Grid.php:151 |
| `Grid::chunk()` | method | src/Widget/Grid.php:181 |
| `InlineEditorBox` | class | src/Widget/InlineEditorBox.php:17 |
| `InlineEditorBox::make()` | method | src/Widget/InlineEditorBox.php:58 |
| `InlineEditorBox::editorStyle()` | method | src/Widget/InlineEditorBox.php:64 |
| `LazyList::keyMapper()` | method | src/Widget/LazyList.php:130 |
| `LazyList::viewportExtentPx()` | method | src/Widget/LazyList.php:188 |
| `LazyList::initialOffsetPx()` | method | src/Widget/LazyList.php:194 |
| `LazyList::buildItem()` | method | src/Widget/LazyList.php:240 |
| `LazyListState` | class | src/Widget/LazyListState.php:12 |
| `Modal::bindDismiss()` | method | src/Widget/Modal.php:169 |
| `ModalHost` | class | src/Widget/ModalHost.php:10 |
| `OffstageElement::$parked` | property | src/Widget/OffstageElement.php:22 |
| `OptionPanel` | class | src/Widget/OptionPanel.php:10 |
| `OptionPanel::source()` | method | src/Widget/OptionPanel.php:73 |
| `OptionPanel::content()` | method | src/Widget/OptionPanel.php:79 |
| `OptionPanel::minWidthValue()` | method | src/Widget/OptionPanel.php:85 |
| `OptionPanel::maxHeightValue()` | method | src/Widget/OptionPanel.php:91 |
| `OptionPanel::scrollableValue()` | method | src/Widget/OptionPanel.php:97 |
| `OptionPanel::notifyClosed()` | method | src/Widget/OptionPanel.php:103 |
| `OptionPanelState` | class | src/Widget/OptionPanel.php:119 |
| `PickerPanelSignal` | class | src/Widget/PickerPanelSignal.php:8 |
| `PopupPanel::paintsBoxChrome()` | method | src/Widget/PopupPanel.php:131 |
| `RenderBoxWidget::paintsBoxChrome()` | method | src/Widget/RenderBoxWidget.php:35 |
| `RenderBoxWidget::paintsDisabledLook()` | method | src/Widget/RenderBoxWidget.php:56 |
| `RenderBoxWidget::defaultBoxStyle()` | method | src/Widget/RenderBoxWidget.php:79 |
| `ScrollBarFade` | class | src/Widget/ScrollBarFade.php:9 |
| `ScrollLane` | class | src/Widget/ScrollLane.php:6 |
| `ScrollMotion` | class | src/Widget/ScrollMotion.php:6 |
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
| `State::$pendingDirty` | property | src/Widget/State.php:25 |
| `State::$buildingState` | property | src/Widget/State.php:40 |
| `State::$rebuiltCount` | property | src/Widget/State.php:52 |
| `State::$initializing` | property | src/Widget/State.php:99 |
| `State::$initialized` | property | src/Widget/State.php:102 |
| `State::$lastTickedFrame` | property | src/Widget/State.php:105 |
| `State::attach()` | method | src/Widget/State.php:108 |
| `State::element()` | method | src/Widget/State.php:120 |
| `State::dropSubscriptions()` | method | src/Widget/State.php:343 |
| `State::dropCommands()` | method | src/Widget/State.php:360 |
| `State::callInit()` | method | src/Widget/State.php:377 |
| `State::consumeDirty()` | method | src/Widget/State.php:399 |
| `State::pendingDirty()` | method | src/Widget/State.php:411 |
| `State::markedCount()` | method | src/Widget/State.php:417 |
| `State::rebuiltCount()` | method | src/Widget/State.php:429 |
| `State::boxEpoch()` | method | src/Widget/State.php:435 |
| `State::noteBoxReplaced()` | method | src/Widget/State.php:446 |
| `State::takeMountedThisFrame()` | method | src/Widget/State.php:459 |
| `State::mountedCount()` | method | src/Widget/State.php:473 |
| `State::frameWantedCount()` | method | src/Widget/State.php:498 |
| `State::noteRebuilt()` | method | src/Widget/State.php:508 |
| `State::enterBuild()` | method | src/Widget/State.php:520 |
| `State::leaveBuild()` | method | src/Widget/State.php:531 |
| `State::didUpdateWidget()` | method | src/Widget/State.php:537 |
| `State::adoptWidget()` | method | src/Widget/State.php:558 |
| `StatefulElement::$boxFromState` | property | src/Widget/StatefulElement.php:18 |
| `StatefulElement::syncBox()` | method | src/Widget/StatefulElement.php:45 |
| `Table::keyMapper()` | method | src/Widget/Table.php:559 |
| `Table::copyHandler()` | method | src/Widget/Table.php:591 |
| `Table::selectionChangeHandler()` | method | src/Widget/Table.php:597 |
| `Table::rowTapHandler()` | method | src/Widget/Table.php:603 |
| `Table::columnWidthOverrides()` | method | src/Widget/Table.php:615 |
| `TableBox` | class | src/Widget/TableBox.php:11 |
| `TableBox::make()` | method | src/Widget/TableBox.php:45 |
| `TableElement` | class | src/Widget/TableElement.php:8 |
| `TableGeometry` | class | src/Widget/TableGeometry.php:9 |
| `TableGridPicker` | class | src/Widget/TableGridPicker.php:6 |
| `TableState` | class | src/Widget/TableState.php:29 |
| `TextFieldBox` | class | src/Widget/TextFieldBox.php:17 |
| `TextFieldBox::make()` | method | src/Widget/TextFieldBox.php:54 |
| `TextFieldState::$editing` | property | src/Widget/TextFieldState.php:49 |
| `WidgetFocus` | trait | src/Widget/Traits/WidgetFocus.php:10 |
| `WidgetFocus::focusNode()` | method | src/Widget/Traits/WidgetFocus.php:101 |
| `WidgetFocus::cursorShape()` | method | src/Widget/Traits/WidgetFocus.php:107 |
| `WidgetFocus::markFocusable()` | method | src/Widget/Traits/WidgetFocus.php:113 |
| `WidgetFocus::isFocusable()` | method | src/Widget/Traits/WidgetFocus.php:126 |
| `WidgetInteraction` | trait | src/Widget/Traits/WidgetInteraction.php:10 |
| `WidgetInteraction::draggableValue()` | method | src/Widget/Traits/WidgetInteraction.php:44 |
| `WidgetInteraction::dragTargetValue()` | method | src/Widget/Traits/WidgetInteraction.php:67 |
| `WidgetInteraction::contextMenuValue()` | method | src/Widget/Traits/WidgetInteraction.php:90 |
| `WidgetInteraction::hoverHandlerValue()` | method | src/Widget/Traits/WidgetInteraction.php:119 |
| `WidgetInteraction::pointerHandlerValue()` | method | src/Widget/Traits/WidgetInteraction.php:159 |
| `WidgetInteraction::pointerOwnsPressValue()` | method | src/Widget/Traits/WidgetInteraction.php:165 |
| `WidgetInteraction::windowDragHandlerValue()` | method | src/Widget/Traits/WidgetInteraction.php:200 |
| `WidgetInteraction::filesDropHandlerValue()` | method | src/Widget/Traits/WidgetInteraction.php:230 |
| `WidgetInteraction::keyHandlerValue()` | method | src/Widget/Traits/WidgetInteraction.php:273 |
| `WidgetInteraction::tooltipTextValue()` | method | src/Widget/Traits/WidgetInteraction.php:310 |
| `WidgetInteraction::overlayPanelValue()` | method | src/Widget/Traits/WidgetInteraction.php:374 |
| `WidgetInteraction::overlayPanelMaxWidthValue()` | method | src/Widget/Traits/WidgetInteraction.php:380 |
| `WidgetInteraction::overlaySignatureValue()` | method | src/Widget/Traits/WidgetInteraction.php:434 |
| `WidgetModifiers` | trait | src/Widget/Traits/WidgetModifiers.php:14 |
| `WidgetSemantics` | trait | src/Widget/Traits/WidgetSemantics.php:8 |
| `WidgetSemantics::semanticsRole()` | method | src/Widget/Traits/WidgetSemantics.php:90 |
| `WidgetSemantics::semanticsName()` | method | src/Widget/Traits/WidgetSemantics.php:96 |
| `WidgetSemantics::semanticsValue()` | method | src/Widget/Traits/WidgetSemantics.php:102 |
| `WidgetSemantics::semanticsEnabled()` | method | src/Widget/Traits/WidgetSemantics.php:108 |
| `WidgetSemantics::semanticsRangeMin()` | method | src/Widget/Traits/WidgetSemantics.php:114 |
| `WidgetSemantics::semanticsRangeMax()` | method | src/Widget/Traits/WidgetSemantics.php:120 |
| `TreeNode::assertNodes()` | method | src/Widget/TreeNode.php:86 |
| `TreeView::visibleRows()` | method | src/Widget/TreeView.php:246 |
| `Widget::$sealed` | property | src/Widget/Widget.php:59 |
| `Widget::$key` | property | src/Widget/Widget.php:72 |
| `Widget::$focus` | property | src/Widget/Widget.php:75 |
| `Widget::$cursor` | property | src/Widget/Widget.php:78 |
| `Widget::$focusable` | property | src/Widget/Widget.php:81 |
| `Widget::$draggable` | property | src/Widget/Widget.php:84 |
| `Widget::$dragTarget` | property | src/Widget/Widget.php:87 |
| `Widget::$contextMenu` | property | src/Widget/Widget.php:90 |
| `Widget::$hoverHandler` | property | src/Widget/Widget.php:93 |
| `Widget::$pointerHandler` | property | src/Widget/Widget.php:96 |
| `Widget::$pointerOwnsPress` | property | src/Widget/Widget.php:105 |
| `Widget::$windowDragHandler` | property | src/Widget/Widget.php:118 |
| `Widget::$filesDropHandler` | property | src/Widget/Widget.php:129 |
| `Widget::$keyHandler` | property | src/Widget/Widget.php:139 |
| `Widget::$tooltipText` | property | src/Widget/Widget.php:147 |
| `Widget::$overlayPanelFactory` | property | src/Widget/Widget.php:159 |
| `Widget::$overlaySignature` | property | src/Widget/Widget.php:172 |
| `Widget::$semRole` | property | src/Widget/Widget.php:182 |
| `Widget::$semName` | property | src/Widget/Widget.php:185 |
| `Widget::$semValue` | property | src/Widget/Widget.php:188 |
| `Widget::$semEnabled` | property | src/Widget/Widget.php:191 |
| `Widget::$semRangeMin` | property | src/Widget/Widget.php:194 |
| `Widget::__clone()` | method | src/Widget/Widget.php:217 |
| `Widget::childContext()` | method | src/Widget/Widget.php:255 |
| `Widget::createElement()` | method | src/Widget/Widget.php:270 |
| `Widget::identity()` | method | src/Widget/Widget.php:286 |
| `Widget::seal()` | method | src/Widget/Widget.php:296 |
| `Widget::isSealed()` | method | src/Widget/Widget.php:310 |
| `Widget::mods()` | method | src/Widget/Widget.php:374 |
| `Widget::expandChildren()` | method | src/Widget/Widget.php:380 |


