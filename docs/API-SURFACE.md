# Drift2 API 面清单（@api / @internal）

> 由 `php tools/lint.php --api` 自动生成，勿手改（`gate` 的 `apidoc` 项会核对）。
> 口径：docblock **独立标注行**（` * @api` 或单行 `/** @api … */`）；
> 行内描述性提及（如「它本身是 @internal 的实现细节」这类文字）不计入。
> ★ **不含行号**（2026-11-03）：行号每加一行代码就漂 —— 留着它等于给一个
> 100% 会过期的跳转目标；更要命的是它会让 `apidoc` 闸门**每次改代码都红**，
> 噪声淹没真信号（「新增了一个 @api」这种才该让人看见）。找行号用编辑器搜符号名。

## 总览

| 层 | @api | @internal |
|---|---|---|
| App | 85 | 54 |
| Async | 25 | 5 |
| Commands | 7 | 0 |
| Core | 2 | 38 |
| Diagnostics.php | 0 | 2 |
| Errors.php | 2 | 3 |
| Geometry | 9 | 0 |
| Input | 21 | 5 |
| Layout | 47 | 45 |
| Logging | 9 | 3 |
| Platform | 16 | 15 |
| Rendering | 34 | 1 |
| Routing | 8 | 6 |
| State | 19 | 8 |
| Text | 32 | 3 |
| Theme | 3 | 0 |
| Widget | 188 | 226 |
| **合计** | **507** | **414** |

覆盖 399 个文件 · 描述性提及 17 处（grep 口径的污染源，不计入）

## App 层

### @api（应用可读面） · 85

| 符号 | 种类 | 文件 |
|---|---|---|
| `Application` | class | src/App/Application.php |
| `Application::commands()` | method | src/App/Application.php |
| `Application::scope()` | method | src/App/Application.php |
| `Application::taskHost()` | method | src/App/Application.php |
| `Application::submit()` | method | src/App/Application.php |
| `Application::accelerators()` | method | src/App/Application.php |
| `Application::clipboard()` | method | src/App/Application.php |
| `Application::metrics()` | method | src/App/Application.php |
| `Application::attach()` | method | src/App/Application.php |
| `Application::onSystemThemeChanged()` | method | src/App/Application.php |
| `Application::onCloseRequested()` | method | src/App/Application.php |
| `Application::frame()` | method | src/App/Application.php |
| `Application::repaintForResize()` | method | src/App/Application.php |
| `Application::after()` | method | src/App/Application.php |
| `Application::wantsFrame()` | method | src/App/Application.php |
| `Application::needsFrameImmediately()` | method | src/App/Application.php |
| `Application::nextDeadlineMs()` | method | src/App/Application.php |
| `Application::io()` | method | src/App/Application.php |
| `Application::capturePointer()` | method | src/App/Application.php |
| `Application::releasePointer()` | method | src/App/Application.php |
| `Application::dispatchInput()` | method | src/App/Application.php |
| `Application::dispatch()` | method | src/App/Application.php |
| `Application::showMenu()` | method | src/App/Application.php |
| `Application::showSnackbar()` | method | src/App/Application.php |
| `Application::overlays()` | method | src/App/Application.php |
| `Application::showModal()` | method | src/App/Application.php |
| `Application::closeModal()` | method | src/App/Application.php |
| `AppScope` | class | src/App/AppScope.php |
| `EventLoop` | class | src/App/EventLoop.php |
| `Frame` | class | src/App/Frame.php |
| `Frame::run()` | method | src/App/Frame.php |
| `FrameClock` | class | src/App/FrameClock.php |
| `FramePhase` | class | src/App/FramePhase.php |
| `InputRouter::capturePointer()` | method | src/App/InputRouter.php |
| `InputRouter::releasePointer()` | method | src/App/InputRouter.php |
| `Http` | class | src/App/Io/Http.php |
| `Http::get()` | method | src/App/Io/Http.php |
| `Http::post()` | method | src/App/Io/Http.php |
| `Http::request()` | method | src/App/Io/Http.php |
| `HttpException` | class | src/App/Io/HttpException.php |
| `HttpException::url()` | method | src/App/Io/HttpException.php |
| `HttpException::status()` | method | src/App/Io/HttpException.php |
| `HttpResponse` | class | src/App/Io/HttpResponse.php |
| `HttpResponse::status()` | method | src/App/Io/HttpResponse.php |
| `HttpResponse::ok()` | method | src/App/Io/HttpResponse.php |
| `HttpResponse::header()` | method | src/App/Io/HttpResponse.php |
| `HttpResponse::headers()` | method | src/App/Io/HttpResponse.php |
| `HttpResponse::body()` | method | src/App/Io/HttpResponse.php |
| `HttpResponse::url()` | method | src/App/Io/HttpResponse.php |
| `HttpResponse::elapsedMs()` | method | src/App/Io/HttpResponse.php |
| `HttpResponse::json()` | method | src/App/Io/HttpResponse.php |
| `HttpResponse::snippet()` | method | src/App/Io/HttpResponse.php |
| `IoPoller` | class | src/App/Io/IoPoller.php |
| `IoPoller::watch()` | method | src/App/Io/IoPoller.php |
| `IoPoller::unwatch()` | method | src/App/Io/IoPoller.php |
| `Job` | interface | src/App/Job.php |
| `Job::run()` | method | src/App/Job.php |
| `JobProgress` | class | src/App/JobProgress.php |
| `JobProgress::report()` | method | src/App/JobProgress.php |
| `JobRunner` | class | src/App/JobRunner.php |
| `JobRunner::exec()` | method | src/App/JobRunner.php |
| `NamedJob` | interface | src/App/NamedJob.php |
| `Paths` | class | src/App/Paths.php |
| `Paths::userConfig()` | method | src/App/Paths.php |
| `ProcessTaskHost` | class | src/App/ProcessTaskHost.php |
| `ProcessTaskHost::$requestTerminateMs` | property | src/App/ProcessTaskHost.php |
| `ProcessTaskHost::__construct()` | method | src/App/ProcessTaskHost.php |
| `ProcessTaskHost::prewarm()` | method | src/App/ProcessTaskHost.php |
| `ProcessTaskHost::shutdown()` | method | src/App/ProcessTaskHost.php |
| `ProcessWorker` | class | src/App/ProcessWorker.php |
| `ProcessWorker::serve()` | method | src/App/ProcessWorker.php |
| `PumpHost::after()` | method | src/App/PumpHost.php |
| `PumpHost::io()` | method | src/App/PumpHost.php |
| `SyncTaskHost` | class | src/App/SyncTaskHost.php |
| `TaskHost` | interface | src/App/TaskHost.php |
| `ThreadTaskHost` | class | src/App/ThreadTaskHost.php |
| `ThreadTaskHost::__construct()` | method | src/App/ThreadTaskHost.php |
| `ThreadTaskHost::shutdown()` | method | src/App/ThreadTaskHost.php |
| `TimedJob` | interface | src/App/TimedJob.php |
| `Window` | class | src/App/Window.php |
| `WindowSet` | class | src/App/WindowSet.php |
| `WindowStateStore` | class | src/App/WindowStateStore.php |
| `WindowStateStore::__construct()` | method | src/App/WindowStateStore.php |
| `WindowStateStore::restore()` | method | src/App/WindowStateStore.php |
| `WindowStateStore::persist()` | method | src/App/WindowStateStore.php |

### @internal（框架内部） · 54

| 符号 | 种类 | 文件 |
|---|---|---|
| `Application::$zeroViewportFrames` | property | src/App/Application.php |
| `Application::$pipeline` | property | src/App/Application.php |
| `Application::focus()` | method | src/App/Application.php |
| `Application::tasks()` | method | src/App/Application.php |
| `Application::lastTimestampMs()` | method | src/App/Application.php |
| `Application::lastInputMs()` | method | src/App/Application.php |
| `Application::uiThreadId()` | method | src/App/Application.php |
| `Application::assertUiThread()` | method | src/App/Application.php |
| `Application::closeRequested()` | method | src/App/Application.php |
| `Application::frameCount()` | method | src/App/Application.php |
| `Application::pipeline()` | method | src/App/Application.php |
| `Application::zeroViewportFrames()` | method | src/App/Application.php |
| `Application::semanticsMode()` | method | src/App/Application.php |
| `Application::animationVisits()` | method | src/App/Application.php |
| `Application::activeAnimationCount()` | method | src/App/Application.php |
| `Application::captureNode()` | method | src/App/Application.php |
| `Application::dispatchInputInner()` | method | src/App/Application.php |
| `Application::register()` | method | src/App/Application.php |
| `Application::beginDrag()` | method | src/App/Application.php |
| `Application::updateDrag()` | method | src/App/Application.php |
| `Application::dropDrag()` | method | src/App/Application.php |
| `Application::cancelDrag()` | method | src/App/Application.php |
| `Application::rootBox()` | method | src/App/Application.php |
| `Application::dismissOverlay()` | method | src/App/Application.php |
| `Application::toggleWidgetPanel()` | method | src/App/Application.php |
| `Completion` | class | src/App/Completion.php |
| `CompletionQueue` | class | src/App/CompletionQueue.php |
| `DragController` | class | src/App/DragController.php |
| `FocusManager` | class | src/App/FocusManager.php |
| `FocusManager::focusableIds()` | method | src/App/FocusManager.php |
| `Frame::__construct()` | method | src/App/Frame.php |
| `FrameClock::stallCount()` | method | src/App/FrameClock.php |
| `FrameClock::worstGapMs()` | method | src/App/FrameClock.php |
| `InputHost` | interface | src/App/InputHost.php |
| `InputRouter` | class | src/App/InputRouter.php |
| `Http::tlsTrustOptions()` | method | src/App/Io/Http.php |
| `Http::trustBundlePath()` | method | src/App/Io/Http.php |
| `MultipartBody` | class | src/App/Io/MultipartBody.php |
| `JobProgress::bind()` | method | src/App/JobProgress.php |
| `OverlayHost` | interface | src/App/OverlayHost.php |
| `OverlayLayout` | class | src/App/OverlayLayout.php |
| `OverlayLayout::syncPanelSignatures()` | method | src/App/OverlayLayout.php |
| `OverlayManager` | class | src/App/OverlayManager.php |
| `OverlayManager::onHoverHit()` | method | src/App/OverlayManager.php |
| `OverlayManager::dismissOverlay()` | method | src/App/OverlayManager.php |
| `OverlayManager::focusTrapRoots()` | method | src/App/OverlayManager.php |
| `OverlayManager::toggleWidgetPanel()` | method | src/App/OverlayManager.php |
| `PumpHost` | class | src/App/PumpHost.php |
| `PumpHostHost` | interface | src/App/PumpHostHost.php |
| `ScrollInputRouter` | class | src/App/ScrollInputRouter.php |
| `TextEditingBridge` | class | src/App/TextEditingBridge.php |
| `TextEditingHost` | interface | src/App/TextEditingHost.php |
| `Window::__construct()` | method | src/App/Window.php |
| `Window::mount()` | method | src/App/Window.php |

## Async 层

### @api（应用可读面） · 25

| 符号 | 种类 | 文件 |
|---|---|---|
| `AsyncCancelled` | class | src/Async/AsyncCancelled.php |
| `AsyncScope` | class | src/Async/AsyncScope.php |
| `AsyncScope::task()` | method | src/Async/AsyncScope.php |
| `AsyncScope::await()` | method | src/Async/AsyncScope.php |
| `AsyncScope::sleep()` | method | src/Async/AsyncScope.php |
| `AsyncScope::isCancelled()` | method | src/Async/AsyncScope.php |
| `AsyncValue` | class | src/Async/AsyncValue.php |
| `AsyncValue::carriedFrom()` | method | src/Async/AsyncValue.php |
| `AsyncValue::withProgress()` | method | src/Async/AsyncValue.php |
| `AsyncValue::hasProgress()` | method | src/Async/AsyncValue.php |
| `AsyncValue::progressOr()` | method | src/Async/AsyncValue.php |
| `AsyncValue::fold()` | method | src/Async/AsyncValue.php |
| `AsyncValue::stateName()` | method | src/Async/AsyncValue.php |
| `AsyncValue::code()` | method | src/Async/AsyncValue.php |
| `Future` | class | src/Async/Future.php |
| `Future::pending()` | method | src/Async/Future.php |
| `Future::resolved()` | method | src/Async/Future.php |
| `Future::rejected()` | method | src/Async/Future.php |
| `Future::then()` | method | src/Async/Future.php |
| `Future::catch()` | method | src/Async/Future.php |
| `Future::onProgress()` | method | src/Async/Future.php |
| `Future::cancel()` | method | src/Async/Future.php |
| `Future::all()` | method | src/Async/Future.php |
| `Future::allSettled()` | method | src/Async/Future.php |
| `Future::race()` | method | src/Async/Future.php |

### @internal（框架内部） · 5

| 符号 | 种类 | 文件 |
|---|---|---|
| `Failure` | class | src/Async/Failure.php |
| `Future::__construct()` | method | src/Async/Future.php |
| `Future::reportProgress()` | method | src/Async/Future.php |
| `Future::resolve()` | method | src/Async/Future.php |
| `Future::reject()` | method | src/Async/Future.php |

## Commands 层

### @api（应用可读面） · 7

| 符号 | 种类 | 文件 |
|---|---|---|
| `CommandArg` | class | src/Commands/CommandArg.php |
| `CommandHandler` | interface | src/Commands/CommandHandler.php |
| `CommandHandler::handle()` | method | src/Commands/CommandHandler.php |
| `CommandRouter` | class | src/Commands/CommandRouter.php |
| `CommandRouter::make()` | method | src/Commands/CommandRouter.php |
| `CommandRouter::on()` | method | src/Commands/CommandRouter.php |
| `CommandRouter::dispatch()` | method | src/Commands/CommandRouter.php |

## Core 层

### @api（应用可读面） · 2

| 符号 | 种类 | 文件 |
|---|---|---|
| `Computed` | class | src/Core/Computed.php |
| `Versioned` | interface | src/Core/Versioned.php |

### @internal（框架内部） · 38

| 符号 | 种类 | 文件 |
|---|---|---|
| `Pipeline` | class | src/Core/Pipeline.php |
| `Pipeline::bumpStructure()` | method | src/Core/Pipeline.php |
| `Pipeline::bumpContent()` | method | src/Core/Pipeline.php |
| `Pipeline::structureVersion()` | method | src/Core/Pipeline.php |
| `Pipeline::contentVersion()` | method | src/Core/Pipeline.php |
| `Pipeline::markDirty()` | method | src/Core/Pipeline.php |
| `Pipeline::pendingDirty()` | method | src/Core/Pipeline.php |
| `Pipeline::markedCount()` | method | src/Core/Pipeline.php |
| `Pipeline::rebuiltCount()` | method | src/Core/Pipeline.php |
| `Pipeline::frameConsumed()` | method | src/Core/Pipeline.php |
| `Pipeline::wantsFrame()` | method | src/Core/Pipeline.php |
| `Pipeline::wantsFrameImmediately()` | method | src/Core/Pipeline.php |
| `Pipeline::noteIdleSpin()` | method | src/Core/Pipeline.php |
| `Pipeline::takeMounted()` | method | src/Core/Pipeline.php |
| `Pipeline::mountedCount()` | method | src/Core/Pipeline.php |
| `Pipeline::noteActiveAnimation()` | method | src/Core/Pipeline.php |
| `Pipeline::takeActiveAnimations()` | method | src/Core/Pipeline.php |
| `Pipeline::activeAnimationCount()` | method | src/Core/Pipeline.php |
| `Pipeline::addConstraintObserver()` | method | src/Core/Pipeline.php |
| `Pipeline::removeConstraintObserver()` | method | src/Core/Pipeline.php |
| `Pipeline::constraintObservers()` | method | src/Core/Pipeline.php |
| `Pipeline::constraintObserverCount()` | method | src/Core/Pipeline.php |
| `Pipeline::setFocusHost()` | method | src/Core/Pipeline.php |
| `Pipeline::focusHost()` | method | src/Core/Pipeline.php |
| `Pipeline::bumpLayoutEpoch()` | method | src/Core/Pipeline.php |
| `Pipeline::bumpPaintEpoch()` | method | src/Core/Pipeline.php |
| `Pipeline::bumpLayout()` | method | src/Core/Pipeline.php |
| `Pipeline::bumpPaint()` | method | src/Core/Pipeline.php |
| `Pipeline::bumpReplay()` | method | src/Core/Pipeline.php |
| `Pipeline::bumpPairCheck()` | method | src/Core/Pipeline.php |
| `Pipeline::bumpSettleRun()` | method | src/Core/Pipeline.php |
| `Pipeline::bumpSettleSkip()` | method | src/Core/Pipeline.php |
| `Pipeline::bumpPaintRun()` | method | src/Core/Pipeline.php |
| `Pipeline::bumpPaintSkip()` | method | src/Core/Pipeline.php |
| `Pipeline::noteConstraintRounds()` | method | src/Core/Pipeline.php |
| `Pipeline::noteConstraintDrift()` | method | src/Core/Pipeline.php |
| `PipelineAccess` | trait | src/Core/PipelineAccess.php |
| `PipelineAccess::pipeline()` | method | src/Core/PipelineAccess.php |

## Diagnostics.php 层

### @internal（框架内部） · 2

| 符号 | 种类 | 文件 |
|---|---|---|
| `Diagnostics` | class | src/Diagnostics.php |
| `Diagnostics::reset()` | method | src/Diagnostics.php |

## Errors.php 层

### @api（应用可读面） · 2

| 符号 | 种类 | 文件 |
|---|---|---|
| `Errors` | class | src/Errors.php |
| `Errors::installFatalBox()` | method | src/Errors.php |

### @internal（框架内部） · 3

| 符号 | 种类 | 文件 |
|---|---|---|
| `Errors::setLogPath()` | method | src/Errors.php |
| `Errors::recentDiagnostics()` | method | src/Errors.php |
| `Errors::reset()` | method | src/Errors.php |

## Geometry 层

### @api（应用可读面） · 9

| 符号 | 种类 | 文件 |
|---|---|---|
| `Alignment` | class | src/Geometry/Alignment.php |
| `Axis` | enum | src/Geometry/Axis.php |
| `BoxConstraints` | class | src/Geometry/BoxConstraints.php |
| `EdgeInsets` | class | src/Geometry/EdgeInsets.php |
| `Matrix2D` | class | src/Geometry/Matrix2D.php |
| `Point` | class | src/Geometry/Point.php |
| `Rect` | class | src/Geometry/Rect.php |
| `Scale` | class | src/Geometry/Scale.php |
| `Size` | class | src/Geometry/Size.php |

## Input 层

### @api（应用可读面） · 21

| 符号 | 种类 | 文件 |
|---|---|---|
| `AcceleratorTable` | class | src/Input/AcceleratorTable.php |
| `CloseRequestedEvent` | class | src/Input/CloseRequestedEvent.php |
| `CursorShape` | enum | src/Input/CursorShape.php |
| `FilesDroppedEvent` | class | src/Input/FilesDroppedEvent.php |
| `FocusNode` | class | src/Input/FocusNode.php |
| `InputEvent` | interface | src/Input/InputEvent.php |
| `KeyChord` | class | src/Input/KeyChord.php |
| `KeyEvent` | class | src/Input/KeyEvent.php |
| `KeyResult` | enum | src/Input/KeyResult.php |
| `PointerEvent` | class | src/Input/PointerEvent.php |
| `PointerPhase` | enum | src/Input/PointerPhase.php |
| `ScrollEvent` | class | src/Input/ScrollEvent.php |
| `SemanticsExpandEvent` | class | src/Input/SemanticsExpandEvent.php |
| `SemanticsInvokeEvent` | class | src/Input/SemanticsInvokeEvent.php |
| `SemanticsNode` | class | src/Input/SemanticsNode.php |
| `SemanticsRole` | enum | src/Input/SemanticsRole.php |
| `SemanticsSetValueEvent` | class | src/Input/SemanticsSetValueEvent.php |
| `SemanticsTree` | class | src/Input/SemanticsTree.php |
| `TextKeymap` | class | src/Input/TextKeymap.php |
| `ThemeChangedEvent` | class | src/Input/ThemeChangedEvent.php |
| `WindowDeactivatedEvent` | class | src/Input/WindowDeactivatedEvent.php |

### @internal（框架内部） · 5

| 符号 | 种类 | 文件 |
|---|---|---|
| `GestureArena` | class | src/Input/GestureArena.php |
| `GestureRecognizer` | class | src/Input/GestureRecognizer.php |
| `GestureRecognizer::claims()` | method | src/Input/GestureRecognizer.php |
| `GestureRecognizer::win()` | method | src/Input/GestureRecognizer.php |
| `TapTracker` | class | src/Input/TapTracker.php |

## Layout 层

### @api（应用可读面） · 47

| 符号 | 种类 | 文件 |
|---|---|---|
| `ChildContainer` | interface | src/Layout/ChildContainer.php |
| `ColumnWidths` | class | src/Layout/ColumnWidths.php |
| `ExtentWindow` | class | src/Layout/ExtentWindow.php |
| `FlexAlign` | enum | src/Layout/FlexAlign.php |
| `ImageFit` | enum | src/Layout/ImageFit.php |
| `LayoutSelfCheck` | class | src/Layout/LayoutSelfCheck.php |
| `Overflow` | enum | src/Layout/Overflow.php |
| `RenderAppBar` | class | src/Layout/RenderAppBar.php |
| `RenderBackdropFilter` | class | src/Layout/RenderBackdropFilter.php |
| `RenderBox` | class | src/Layout/RenderBox.php |
| `RenderBox::constraints()` | method | src/Layout/RenderBox.php |
| `RenderBox::overflow()` | method | src/Layout/RenderBox.php |
| `RenderBox::setCompose()` | method | src/Layout/RenderBox.php |
| `RenderBox::paintComposed()` | method | src/Layout/RenderBox.php |
| `RenderBox::hitTest()` | method | src/Layout/RenderBox.php |
| `RenderButton` | class | src/Layout/RenderButton.php |
| `RenderDivider` | class | src/Layout/RenderDivider.php |
| `RenderDock` | class | src/Layout/RenderDock.php |
| `RenderFlex` | class | src/Layout/RenderFlex.php |
| `RenderIcon` | class | src/Layout/RenderIcon.php |
| `RenderImage` | class | src/Layout/RenderImage.php |
| `RenderMenuItem` | class | src/Layout/RenderMenuItem.php |
| `RenderObject` | class | src/Layout/RenderObject.php |
| `RenderObject::setInteraction()` | method | src/Layout/RenderObject.php |
| `RenderObject::cursor()` | method | src/Layout/RenderObject.php |
| `RenderObject::cursorAt()` | method | src/Layout/RenderObject.php |
| `RenderObject::panelRect()` | method | src/Layout/RenderObject.php |
| `RenderObject::acceptsHover()` | method | src/Layout/RenderObject.php |
| `RenderObject::acceptsPress()` | method | src/Layout/RenderObject.php |
| `RenderPopupPanel` | class | src/Layout/RenderPopupPanel.php |
| `RenderProgress` | class | src/Layout/RenderProgress.php |
| `RenderProxy` | class | src/Layout/RenderProxy.php |
| `RenderProxyBox` | class | src/Layout/RenderProxyBox.php |
| `RenderRichText` | class | src/Layout/RenderRichText.php |
| `RenderScrollView` | class | src/Layout/RenderScrollView.php |
| `RenderSegment` | class | src/Layout/RenderSegment.php |
| `RenderSegmentedControl` | class | src/Layout/RenderSegmentedControl.php |
| `RenderSlider` | class | src/Layout/RenderSlider.php |
| `RenderSpinner` | class | src/Layout/RenderSpinner.php |
| `RenderStack` | class | src/Layout/RenderStack.php |
| `RenderText` | class | src/Layout/RenderText.php |
| `RenderTextArea` | class | src/Layout/RenderTextArea.php |
| `RenderTextField` | class | src/Layout/RenderTextField.php |
| `RenderToggle` | class | src/Layout/RenderToggle.php |
| `RenderWrap` | class | src/Layout/RenderWrap.php |
| `StackFit` | enum | src/Layout/StackFit.php |
| `TextInputBox` | interface | src/Layout/TextInputBox.php |

### @internal（框架内部） · 45

| 符号 | 种类 | 文件 |
|---|---|---|
| `LayoutTolerance` | class | src/Layout/LayoutTolerance.php |
| `LayoutTolerance::reset()` | method | src/Layout/LayoutTolerance.php |
| `RenderBox::clipsOverflow()` | method | src/Layout/RenderBox.php |
| `RenderBox::invalidateLayerCache()` | method | src/Layout/RenderBox.php |
| `RenderCustomPaint` | class | src/Layout/RenderCustomPaint.php |
| `RenderDock::slot()` | method | src/Layout/RenderDock.php |
| `RenderFlex::clearChildren()` | method | src/Layout/RenderFlex.php |
| `RenderModalHost` | class | src/Layout/RenderModalHost.php |
| `RenderObject::$cursor` | property | src/Layout/RenderObject.php |
| `RenderObject::inheritRuntimeState()` | method | src/Layout/RenderObject.php |
| `RenderObject::setCursor()` | method | src/Layout/RenderObject.php |
| `RenderObject::setHovered()` | method | src/Layout/RenderObject.php |
| `RenderObject::setPressed()` | method | src/Layout/RenderObject.php |
| `RenderObject::adoptChild()` | method | src/Layout/RenderObject.php |
| `RenderRichText::styleSize()` | method | src/Layout/RenderRichText.php |
| `RenderRichText::lineCount()` | method | src/Layout/RenderRichText.php |
| `RenderRichText::paragraph()` | method | src/Layout/RenderRichText.php |
| `RenderRichText::spans()` | method | src/Layout/RenderRichText.php |
| `RenderScrollView::setBarOpacity()` | method | src/Layout/RenderScrollView.php |
| `RenderScrollView::scrollAxes()` | method | src/Layout/RenderScrollView.php |
| `RenderScrollView::thumbRectInRoot()` | method | src/Layout/RenderScrollView.php |
| `RenderSegmentedControl::clearChildren()` | method | src/Layout/RenderSegmentedControl.php |
| `RenderStack::clearChildren()` | method | src/Layout/RenderStack.php |
| `RenderTable` | class | src/Layout/RenderTable.php |
| `RenderTable::setHeader()` | method | src/Layout/RenderTable.php |
| `RenderTable::setBody()` | method | src/Layout/RenderTable.php |
| `RenderTable::setBarOpacity()` | method | src/Layout/RenderTable.php |
| `RenderTable::focusRect()` | method | src/Layout/RenderTable.php |
| `RenderTable::headerFocusRect()` | method | src/Layout/RenderTable.php |
| `RenderTable::scrollAxes()` | method | src/Layout/RenderTable.php |
| `RenderTable::thumbRectInRoot()` | method | src/Layout/RenderTable.php |
| `RenderText::styleSize()` | method | src/Layout/RenderText.php |
| `RenderText::lineCount()` | method | src/Layout/RenderText.php |
| `RenderTextArea::setBarOpacity()` | method | src/Layout/RenderTextArea.php |
| `RenderTextArea::scrollAxes()` | method | src/Layout/RenderTextArea.php |
| `RenderTextArea::thumbRectInRoot()` | method | src/Layout/RenderTextArea.php |
| `RenderTextArea::boxStyle()` | method | src/Layout/RenderTextArea.php |
| `RenderTextArea::barOpacity()` | method | src/Layout/RenderTextArea.php |
| `RenderTextField::styleSize()` | method | src/Layout/RenderTextField.php |
| `RenderTextField::isShowingPlaceholder()` | method | src/Layout/RenderTextField.php |
| `RenderWrap::clearChildren()` | method | src/Layout/RenderWrap.php |
| `ScrollBarGeometry` | class | src/Layout/ScrollBarGeometry.php |
| `ScrollBarHost` | interface | src/Layout/ScrollBarHost.php |
| `ScrollBarHost::setScrollOffset()` | method | src/Layout/ScrollBarHost.php |
| `ScrollBarHost::setBarOpacity()` | method | src/Layout/ScrollBarHost.php |

## Logging 层

### @api（应用可读面） · 9

| 符号 | 种类 | 文件 |
|---|---|---|
| `CategoryLogger` | class | src/Logging/CategoryLogger.php |
| `Log` | class | src/Logging/Log.php |
| `LogEntry` | class | src/Logging/LogEntry.php |
| `LogFormatter` | interface | src/Logging/LogFormatter.php |
| `LogSink` | interface | src/Logging/LogSink.php |
| `PatternFormatter` | class | src/Logging/PatternFormatter.php |
| `RingSink` | class | src/Logging/RingSink.php |
| `StderrSink` | class | src/Logging/StderrSink.php |
| `StreamSink` | class | src/Logging/StreamSink.php |

### @internal（框架内部） · 3

| 符号 | 种类 | 文件 |
|---|---|---|
| `CategoryLogger::__construct()` | method | src/Logging/CategoryLogger.php |
| `Log::emit()` | method | src/Logging/Log.php |
| `Log::reset()` | method | src/Logging/Log.php |

## Platform 层

### @api（应用可读面） · 16

| 符号 | 种类 | 文件 |
|---|---|---|
| `Backdrop` | enum | src/Platform/Backdrop.php |
| `Clipboard` | interface | src/Platform/Clipboard.php |
| `Dialog` | interface | src/Platform/Dialog.php |
| `InputSource` | interface | src/Platform/InputSource.php |
| `Platform` | interface | src/Platform/Platform.php |
| `Screen` | interface | src/Platform/Screen.php |
| `SurfaceProvider` | interface | src/Platform/SurfaceProvider.php |
| `Tray` | interface | src/Platform/Tray.php |
| `WakeReason` | enum | src/Platform/WakeReason.php |
| `Win32Platform` | class | src/Platform/Win32/Win32Platform.php |
| `WindowHost` | interface | src/Platform/WindowHost.php |
| `WindowHost::setCloseGuard()` | method | src/Platform/WindowHost.php |
| `WindowHost::setCursor()` | method | src/Platform/WindowHost.php |
| `WindowHost::pushSemantics()` | method | src/Platform/WindowHost.php |
| `WindowMaterial` | class | src/Platform/WindowMaterial.php |
| `WindowMaterialCapabilities` | class | src/Platform/WindowMaterialCapabilities.php |

### @internal（框架内部） · 15

| 符号 | 种类 | 文件 |
|---|---|---|
| `Platform::imeCaret()` | method | src/Platform/Platform.php |
| `Dpr` | class | src/Platform/Win32/Dpr.php |
| `Dpr::fromDpi()` | method | src/Platform/Win32/Dpr.php |
| `ResizeHookState::pump()` | method | src/Platform/Win32/ResizeHookState.php |
| `Win32Clipboard` | class | src/Platform/Win32/Win32Clipboard.php |
| `Win32Dialog` | class | src/Platform/Win32/Win32Dialog.php |
| `Win32Dialog::consumeModalBlockedMs()` | method | src/Platform/Win32/Win32Dialog.php |
| `Win32InputSource` | class | src/Platform/Win32/Win32InputSource.php |
| `Win32RasterSurface` | class | src/Platform/Win32/Win32RasterSurface.php |
| `Win32Screen` | class | src/Platform/Win32/Win32Screen.php |
| `Win32SkiaSurface` | class | src/Platform/Win32/Win32SkiaSurface.php |
| `Win32SurfaceProvider` | class | src/Platform/Win32/Win32SurfaceProvider.php |
| `Win32SurfaceProvider::resolveBackend()` | method | src/Platform/Win32/Win32SurfaceProvider.php |
| `Win32WindowHost` | class | src/Platform/Win32/Win32WindowHost.php |
| `WindowHost::destroy()` | method | src/Platform/WindowHost.php |

## Rendering 层

### @api（应用可读面） · 34

| 符号 | 种类 | 文件 |
|---|---|---|
| `AnimatedValue` | class | src/Rendering/AnimatedValue.php |
| `BoxStyle` | class | src/Rendering/BoxStyle.php |
| `Canvas` | interface | src/Rendering/Canvas.php |
| `CanvasCapabilities` | class | src/Rendering/CanvasCapabilities.php |
| `Color` | class | src/Rendering/Color.php |
| `ColorToken` | enum | src/Rendering/ColorToken.php |
| `DashPattern` | class | src/Rendering/DashPattern.php |
| `DisplayList` | class | src/Rendering/DisplayList.php |
| `DividerStyle` | class | src/Rendering/DividerStyle.php |
| `Elevation` | class | src/Rendering/Elevation.php |
| `FrameStage` | class | src/Rendering/FrameStage.php |
| `GpuContext` | interface | src/Rendering/GpuContext.php |
| `Gradient` | class | src/Rendering/Gradient.php |
| `ImageDecoder` | class | src/Rendering/ImageDecoder.php |
| `ImageHandle` | class | src/Rendering/ImageHandle.php |
| `ImageLoader` | class | src/Rendering/ImageLoader.php |
| `MetricToken` | enum | src/Rendering/MetricToken.php |
| `Modifier` | class | src/Rendering/Modifier.php |
| `Modifier::withBoxStyleIfBlank()` | method | src/Rendering/Modifier.php |
| `Paint` | class | src/Rendering/Paint.php |
| `PaintStyle` | enum | src/Rendering/PaintStyle.php |
| `Path` | class | src/Rendering/Path.php |
| `PngDecoder` | class | src/Rendering/PngDecoder.php |
| `ProgressStyle` | class | src/Rendering/ProgressStyle.php |
| `RasterCanvas` | class | src/Rendering/RasterCanvas.php |
| `RecordingCanvas` | class | src/Rendering/RecordingCanvas.php |
| `RenderSurface` | interface | src/Rendering/RenderSurface.php |
| `SegmentedStyle` | class | src/Rendering/SegmentedStyle.php |
| `Shadow` | class | src/Rendering/Shadow.php |
| `ShadowPainter` | class | src/Rendering/ShadowPainter.php |
| `SkiaCanvas` | class | src/Rendering/SkiaCanvas.php |
| `SkiaCanvas::handle()` | method | src/Rendering/SkiaCanvas.php |
| `SurfaceProfile` | class | src/Rendering/SurfaceProfile.php |
| `ToggleStyle` | class | src/Rendering/ToggleStyle.php |

### @internal（框架内部） · 1

| 符号 | 种类 | 文件 |
|---|---|---|
| `LayerCache` | class | src/Rendering/LayerCache.php |

## Routing 层

### @api（应用可读面） · 8

| 符号 | 种类 | 文件 |
|---|---|---|
| `PageHost` | class | src/Routing/PageHost.php |
| `PageHost::make()` | method | src/Routing/PageHost.php |
| `PageHost::source()` | method | src/Routing/PageHost.php |
| `PageHost::select()` | method | src/Routing/PageHost.php |
| `PageHost::table()` | method | src/Routing/PageHost.php |
| `RouteTable` | class | src/Routing/RouteTable.php |
| `RouteTable::make()` | method | src/Routing/RouteTable.php |
| `RouteTable::route()` | method | src/Routing/RouteTable.php |

### @internal（框架内部） · 6

| 符号 | 种类 | 文件 |
|---|---|---|
| `NoPage` | class | src/Routing/NoPage.php |
| `PageHost::sourceSubject()` | method | src/Routing/PageHost.php |
| `PageHost::selectFn()` | method | src/Routing/PageHost.php |
| `PageHost::routeTable()` | method | src/Routing/PageHost.php |
| `PageHostState` | class | src/Routing/PageHostState.php |
| `PageHostState::cachedCount()` | method | src/Routing/PageHostState.php |

## State 层

### @api（应用可读面） · 19

| 符号 | 种类 | 文件 |
|---|---|---|
| `AsyncCommand` | class | src/State/AsyncCommand.php |
| `AsyncCommand::make()` | method | src/State/AsyncCommand.php |
| `AsyncCommand::value()` | method | src/State/AsyncCommand.php |
| `AsyncCommand::mapError()` | method | src/State/AsyncCommand.php |
| `AsyncCommand::isRunning()` | method | src/State/AsyncCommand.php |
| `AsyncCommand::run()` | method | src/State/AsyncCommand.php |
| `AsyncCommand::append()` | method | src/State/AsyncCommand.php |
| `AsyncCommand::retry()` | method | src/State/AsyncCommand.php |
| `AsyncCommand::cancel()` | method | src/State/AsyncCommand.php |
| `AsyncCommand::dispose()` | method | src/State/AsyncCommand.php |
| `Equatable` | interface | src/State/Equatable.php |
| `Subject` | class | src/State/Subject.php |
| `SubjectBuilder` | class | src/State/SubjectBuilder.php |
| `SubjectBuilder::make()` | method | src/State/SubjectBuilder.php |
| `SubjectBuilder::select()` | method | src/State/SubjectBuilder.php |
| `SubjectBuilder::equals()` | method | src/State/SubjectBuilder.php |
| `SubjectBuilder::builder()` | method | src/State/SubjectBuilder.php |
| `SubscribingWidget` | interface | src/State/SubscribingWidget.php |
| `Subscription` | class | src/State/Subscription.php |

### @internal（框架内部） · 8

| 符号 | 种类 | 文件 |
|---|---|---|
| `Subject::$version` | property | src/State/Subject.php |
| `Subject::unsubscribeId()` | method | src/State/Subject.php |
| `SubjectBuilder::source()` | method | src/State/SubjectBuilder.php |
| `SubjectBuilder::selectFn()` | method | src/State/SubjectBuilder.php |
| `SubjectBuilder::builderFn()` | method | src/State/SubjectBuilder.php |
| `SubjectBuilder::equalsFn()` | method | src/State/SubjectBuilder.php |
| `SubjectBuilderState` | class | src/State/SubjectBuilder.php |
| `Subscription::of()` | method | src/State/Subscription.php |

## Text 层

### @api（应用可读面） · 32

| 符号 | 种类 | 文件 |
|---|---|---|
| `BitmapFontFace` | class | src/Text/BitmapFontFace.php |
| `DeterministicFontCollection` | class | src/Text/DeterministicFontCollection.php |
| `DeterministicFontManager` | class | src/Text/DeterministicFontManager.php |
| `DeterministicMetricsProvider` | class | src/Text/DeterministicMetricsProvider.php |
| `DeterministicShaper` | class | src/Text/DeterministicShaper.php |
| `FontCollection` | interface | src/Text/FontCollection.php |
| `FontFace` | interface | src/Text/FontFace.php |
| `FontFamily` | class | src/Text/FontFamily.php |
| `FontLocator` | class | src/Text/FontLocator.php |
| `FontManager` | interface | src/Text/FontManager.php |
| `FontMetrics` | class | src/Text/FontMetrics.php |
| `FontSlant` | enum | src/Text/FontSlant.php |
| `FontWeight` | enum | src/Text/FontWeight.php |
| `Glyph` | class | src/Text/Glyph.php |
| `GlyphBitmap` | class | src/Text/GlyphBitmap.php |
| `GlyphRun` | class | src/Text/GlyphRun.php |
| `NotdefFontFace` | class | src/Text/NotdefFontFace.php |
| `Paragraph` | class | src/Text/Paragraph.php |
| `TextAlign` | enum | src/Text/TextAlign.php |
| `TextBoundary` | class | src/Text/TextBoundary.php |
| `TextChange` | enum | src/Text/TextChange.php |
| `TextDraft` | class | src/Text/TextDraft.php |
| `TextInputClient` | interface | src/Text/TextInputClient.php |
| `TextLayout` | class | src/Text/TextLayout.php |
| `TextLine` | class | src/Text/TextLine.php |
| `TextMetricsProvider` | interface | src/Text/TextMetricsProvider.php |
| `TextShaper` | interface | src/Text/TextShaper.php |
| `TextSpan` | class | src/Text/TextSpan.php |
| `TextStyle` | class | src/Text/TextStyle.php |
| `TtfFace` | class | src/Text/TtfFace.php |
| `TtfFace::fromFile()` | method | src/Text/TtfFace.php |
| `Utf8` | class | src/Text/Utf8.php |

### @internal（框架内部） · 3

| 符号 | 种类 | 文件 |
|---|---|---|
| `CjkGlyphData` | class | src/Text/CjkGlyphData.php |
| `DeterministicFontData` | class | src/Text/DeterministicFontData.php |
| `LatinGlyphData` | class | src/Text/LatinGlyphData.php |

## Theme 层

### @api（应用可读面） · 3

| 符号 | 种类 | 文件 |
|---|---|---|
| `SystemTheme` | enum | src/Theme/SystemTheme.php |
| `ThemeData` | class | src/Theme/ThemeData.php |
| `ThemeScope` | class | src/Theme/ThemeScope.php |

## Widget 层

### @api（应用可读面） · 188

| 符号 | 种类 | 文件 |
|---|---|---|
| `Activatable` | interface | src/Widget/Activatable.php |
| `ActivationContext` | class | src/Widget/ActivationContext.php |
| `Align` | class | src/Widget/Align.php |
| `AnimatedOpacity` | class | src/Widget/AnimatedOpacity.php |
| `AnimationController` | class | src/Widget/AnimationController.php |
| `AppBar` | class | src/Widget/AppBar.php |
| `AspectRatio` | class | src/Widget/AspectRatio.php |
| `BackdropFilter` | class | src/Widget/BackdropFilter.php |
| `BackdropFilter::make()` | method | src/Widget/BackdropFilter.php |
| `BackdropFilter::sigma()` | method | src/Widget/BackdropFilter.php |
| `BuildContext` | class | src/Widget/BuildContext.php |
| `BuildContext::windowSize()` | method | src/Widget/BuildContext.php |
| `Button` | class | src/Widget/Button.php |
| `Button::enabled()` | method | src/Widget/Button.php |
| `Button::resolvePressedOverlay()` | method | src/Widget/Button.php |
| `ButtonVariant` | enum | src/Widget/ButtonVariant.php |
| `CalendarView` | class | src/Widget/CalendarView.php |
| `Card` | class | src/Widget/Card.php |
| `Center` | class | src/Widget/Center.php |
| `Checkbox` | class | src/Widget/Checkbox.php |
| `Column` | class | src/Widget/Column.php |
| `ComboBox` | class | src/Widget/ComboBox.php |
| `ComboBoxState` | class | src/Widget/ComboBox.php |
| `ConstraintObserver` | interface | src/Widget/ConstraintObserver.php |
| `Curve` | class | src/Widget/Curve.php |
| `CustomPaint` | class | src/Widget/CustomPaint.php |
| `CustomPainter` | class | src/Widget/CustomPainter.php |
| `DatePicker` | class | src/Widget/DatePicker.php |
| `DateTimePicker` | class | src/Widget/DateTimePicker.php |
| `Divider` | class | src/Widget/Divider.php |
| `Dock` | class | src/Widget/Dock.php |
| `Draggable` | class | src/Widget/Drag/Draggable.php |
| `DragTarget` | class | src/Widget/Drag/DragTarget.php |
| `Each` | class | src/Widget/Each.php |
| `EditableText` | interface | src/Widget/EditableText.php |
| `EditableTextState` | class | src/Widget/EditableTextState.php |
| `Element` | class | src/Widget/Element.php |
| `ErrorBoundary` | class | src/Widget/ErrorBoundary.php |
| `ErrorBoundaryElement` | class | src/Widget/ErrorBoundaryElement.php |
| `ErrorPlaceholder` | class | src/Widget/ErrorPlaceholder.php |
| `Expanded` | class | src/Widget/Expanded.php |
| `Flex` | class | src/Widget/Flex.php |
| `FocusHost` | interface | src/Widget/FocusHost.php |
| `Grid` | class | src/Widget/Grid.php |
| `Icon` | class | src/Widget/Icon.php |
| `IconButton` | class | src/Widget/IconButton.php |
| `IconName` | enum | src/Widget/IconName.php |
| `IconSet` | class | src/Widget/IconSet.php |
| `Image` | class | src/Widget/Image.php |
| `Key` | class | src/Widget/Key.php |
| `LayoutBuilder` | class | src/Widget/LayoutBuilder.php |
| `LayoutBuilderState` | class | src/Widget/LayoutBuilderState.php |
| `LazyColumn` | class | src/Widget/LazyColumn.php |
| `LazyGrid` | class | src/Widget/LazyGrid.php |
| `LazyList` | class | src/Widget/LazyList.php |
| `LazyRow` | class | src/Widget/LazyRow.php |
| `LocalDate` | class | src/Widget/LocalDate.php |
| `LocalDateTime` | class | src/Widget/LocalDateTime.php |
| `LocalTime` | class | src/Widget/LocalTime.php |
| `ContextMenu` | class | src/Widget/Menu/ContextMenu.php |
| `Menu` | class | src/Widget/Menu/Menu.php |
| `MenuBar` | class | src/Widget/Menu/MenuBar.php |
| `MenuItem` | class | src/Widget/Menu/MenuItem.php |
| `Modal` | class | src/Widget/Modal.php |
| `MultiChildElement` | class | src/Widget/MultiChildElement.php |
| `MultiChildRenderObjectWidget` | class | src/Widget/MultiChildRenderObjectWidget.php |
| `NavButton` | class | src/Widget/NavButton.php |
| `NullableChildRenderObjectWidget` | class | src/Widget/NullableChildRenderObjectWidget.php |
| `Offstage` | class | src/Widget/Offstage.php |
| `Offstage::make()` | method | src/Widget/Offstage.php |
| `Offstage::visible()` | method | src/Widget/Offstage.php |
| `OffstageElement` | class | src/Widget/OffstageElement.php |
| `Opacity` | class | src/Widget/Opacity.php |
| `Option` | class | src/Widget/Option.php |
| `Option::normalize()` | method | src/Widget/Option.php |
| `Option::fromRows()` | method | src/Widget/Option.php |
| `OptionStatus` | class | src/Widget/OptionStatus.php |
| `Padding` | class | src/Widget/Padding.php |
| `PopupPanel` | class | src/Widget/PopupPanel.php |
| `Progress` | class | src/Widget/Progress.php |
| `ProxyElement` | class | src/Widget/ProxyElement.php |
| `Radio` | class | src/Widget/Radio.php |
| `RenderBoxWidget` | class | src/Widget/RenderBoxWidget.php |
| `RichText` | class | src/Widget/RichText.php |
| `Row` | class | src/Widget/Row.php |
| `Scrollable` | interface | src/Widget/Scrollable.php |
| `ScrollController` | class | src/Widget/ScrollController.php |
| `ScrollMetrics` | interface | src/Widget/ScrollMetrics.php |
| `ScrollView` | class | src/Widget/ScrollView.php |
| `ScrollViewState` | class | src/Widget/ScrollViewState.php |
| `Segment` | class | src/Widget/Segment.php |
| `SegmentedControl` | class | src/Widget/SegmentedControl.php |
| `Select` | class | src/Widget/Select.php |
| `SemanticsBuilder` | class | src/Widget/SemanticsBuilder.php |
| `SemanticsExpandable` | interface | src/Widget/SemanticsExpandable.php |
| `SemanticsValueEditable` | interface | src/Widget/SemanticsValueEditable.php |
| `SingleChildRenderObjectWidget` | class | src/Widget/SingleChildRenderObjectWidget.php |
| `SizedBox` | class | src/Widget/SizedBox.php |
| `Slider` | class | src/Widget/Slider.php |
| `Snackbar` | class | src/Widget/Snackbar.php |
| `SnackbarPanel` | class | src/Widget/SnackbarPanel.php |
| `Spacer` | class | src/Widget/Spacer.php |
| `Span` | class | src/Widget/Span.php |
| `Spinner` | class | src/Widget/Spinner.php |
| `Stack` | class | src/Widget/Stack.php |
| `State` | class | src/Widget/State.php |
| `State::markDirty()` | method | src/Widget/State.php |
| `State::tickFrame()` | method | src/Widget/State.php |
| `State::initState()` | method | src/Widget/State.php |
| `State::didChangeFocus()` | method | src/Widget/State.php |
| `State::asyncCommand()` | method | src/Widget/State.php |
| `State::watch()` | method | src/Widget/State.php |
| `State::onDispose()` | method | src/Widget/State.php |
| `State::handleCommand()` | method | src/Widget/State.php |
| `StatefulElement` | class | src/Widget/StatefulElement.php |
| `StatefulWidget` | class | src/Widget/StatefulWidget.php |
| `StatefulWidget::createRenderObjectFromState()` | method | src/Widget/StatefulWidget.php |
| `StatelessElement` | class | src/Widget/StatelessElement.php |
| `StatelessWidget` | class | src/Widget/StatelessWidget.php |
| `States` | class | src/Widget/States.php |
| `Table` | class | src/Widget/Table.php |
| `TableColumn` | class | src/Widget/TableColumn.php |
| `TableColumnWidth` | class | src/Widget/TableColumnWidth.php |
| `TableRange` | class | src/Widget/TableRange.php |
| `TableSelection` | class | src/Widget/TableSelection.php |
| `TableSelectionBehavior` | enum | src/Widget/TableSelectionBehavior.php |
| `TableSelectionMode` | enum | src/Widget/TableSelectionMode.php |
| `TableSort` | class | src/Widget/TableSort.php |
| `TableSortDirection` | enum | src/Widget/TableSortDirection.php |
| `TabView` | class | src/Widget/TabView.php |
| `TabView::make()` | method | src/Widget/TabView.php |
| `Text` | class | src/Widget/Text.php |
| `TextArea` | class | src/Widget/TextArea.php |
| `TextAreaState` | class | src/Widget/TextAreaState.php |
| `TextField` | class | src/Widget/TextField.php |
| `TextFieldState` | class | src/Widget/TextFieldState.php |
| `ThemeScopeElement` | class | src/Widget/ThemeScopeElement.php |
| `TimeView` | class | src/Widget/TimeView.php |
| `Toggle` | class | src/Widget/Toggle.php |
| `ToggleBase` | class | src/Widget/ToggleBase.php |
| `TooltipPanel` | class | src/Widget/TooltipPanel.php |
| `WidgetFocus::focusOrder()` | method | src/Widget/Traits/WidgetFocus.php |
| `WidgetFocus::skipFocus()` | method | src/Widget/Traits/WidgetFocus.php |
| `WidgetFocus::autoFocus()` | method | src/Widget/Traits/WidgetFocus.php |
| `WidgetFocus::cursor()` | method | src/Widget/Traits/WidgetFocus.php |
| `WidgetInteraction::draggable()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::dragTarget()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::contextMenu()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::hover()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::pointer()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::windowDrag()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::onFilesDropped()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::keyboard()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::tooltip()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::overlayPanel()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::overlaySignature()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetModifiers::padding()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::boxSize()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::aspect()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::background()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::gradient()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::borderDash()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::shadow()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::opacity()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::translate()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::disabled()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::clip()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::repaintBoundary()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::alignContent()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::centerContent()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::grow()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::shrink()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::positioned()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::transform()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::rotate()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetModifiers::scale()` | method | src/Widget/Traits/WidgetModifiers.php |
| `WidgetSemantics::semantics()` | method | src/Widget/Traits/WidgetSemantics.php |
| `WidgetSemantics::semanticsRange()` | method | src/Widget/Traits/WidgetSemantics.php |
| `WidgetSemantics::semanticsState()` | method | src/Widget/Traits/WidgetSemantics.php |
| `TreeNode` | class | src/Widget/TreeNode.php |
| `TreeView` | class | src/Widget/TreeView.php |
| `Widget` | class | src/Widget/Widget.php |
| `Widget::__construct()` | method | src/Widget/Widget.php |
| `Widget::key()` | method | src/Widget/Widget.php |
| `Widget::mutate()` | method | src/Widget/Widget.php |
| `WindowSize` | enum | src/Widget/WindowSize.php |
| `Wrap` | class | src/Widget/Wrap.php |
| `YearMonth` | class | src/Widget/YearMonth.php |

### @internal（框架内部） · 226

| 符号 | 种类 | 文件 |
|---|---|---|
| `AnimatedScalar` | class | src/Widget/AnimatedScalar.php |
| `AnimationController::tick()` | method | src/Widget/AnimationController.php |
| `AnimationController::start()` | method | src/Widget/AnimationController.php |
| `AnimationController::finish()` | method | src/Widget/AnimationController.php |
| `AppBar::kidList()` | method | src/Widget/AppBar.php |
| `AppBar::paintsBoxChrome()` | method | src/Widget/AppBar.php |
| `BuildContext::withWindow()` | method | src/Widget/BuildContext.php |
| `BuildContext::withTheme()` | method | src/Widget/BuildContext.php |
| `Button::paintsBoxChrome()` | method | src/Widget/Button.php |
| `CalendarView::selectedValue()` | method | src/Widget/CalendarView.php |
| `CalendarView::onSelectedHandler()` | method | src/Widget/CalendarView.php |
| `CalendarView::onMonthChangeHandler()` | method | src/Widget/CalendarView.php |
| `ComboBox::optionsValue()` | method | src/Widget/ComboBox.php |
| `ComboBox::valueText()` | method | src/Widget/ComboBox.php |
| `ComboBox::placeholderText()` | method | src/Widget/ComboBox.php |
| `ComboBox::triggerWidthValue()` | method | src/Widget/ComboBox.php |
| `ComboBox::changedHandler()` | method | src/Widget/ComboBox.php |
| `ComboBox::statusValue()` | method | src/Widget/ComboBox.php |
| `ComboBox::openHandler()` | method | src/Widget/ComboBox.php |
| `ComboBox::searchHandler()` | method | src/Widget/ComboBox.php |
| `ComboBoxState::$query` | property | src/Widget/ComboBox.php |
| `ComboBoxState::$editing` | property | src/Widget/ComboBox.php |
| `ComboBoxState::$dismiss` | property | src/Widget/ComboBox.php |
| `ComboBoxState::$knownValue` | property | src/Widget/ComboBox.php |
| `ComboBoxState::$knownLabel` | property | src/Widget/ComboBox.php |
| `ComboBoxState::querySubject()` | method | src/Widget/ComboBox.php |
| `ComboBoxState::panelContent()` | method | src/Widget/ComboBox.php |
| `ComboBoxState::panelSignature()` | method | src/Widget/ComboBox.php |
| `ComboBoxState::matches()` | method | src/Widget/ComboBox.php |
| `ComboBoxState::commit()` | method | src/Widget/ComboBox.php |
| `ComboBoxState::forwardKey()` | method | src/Widget/ComboBox.php |
| `ComboBoxState::onPanelClosed()` | method | src/Widget/ComboBox.php |
| `ComboBoxQuery` | class | src/Widget/ComboBox.php |
| `Curve::LINEAR` | const | src/Widget/Curve.php |
| `Curve::EASE_IN` | const | src/Widget/Curve.php |
| `Curve::EASE_OUT` | const | src/Widget/Curve.php |
| `Curve::EASE_IN_OUT` | const | src/Widget/Curve.php |
| `Curve::kind()` | method | src/Widget/Curve.php |
| `DatePicker::valueOf()` | method | src/Widget/DatePicker.php |
| `DatePicker::initialMonthValue()` | method | src/Widget/DatePicker.php |
| `DatePicker::placeholderText()` | method | src/Widget/DatePicker.php |
| `DatePicker::widthValue()` | method | src/Widget/DatePicker.php |
| `DatePicker::onChangedHandler()` | method | src/Widget/DatePicker.php |
| `DatePickerState` | class | src/Widget/DatePicker.php |
| `DatePickerState::$signal` | property | src/Widget/DatePicker.php |
| `DateTimePicker::valueOf()` | method | src/Widget/DateTimePicker.php |
| `DateTimePicker::initialMonthValue()` | method | src/Widget/DateTimePicker.php |
| `DateTimePicker::placeholderText()` | method | src/Widget/DateTimePicker.php |
| `DateTimePicker::widthValue()` | method | src/Widget/DateTimePicker.php |
| `DateTimePicker::showsSeconds()` | method | src/Widget/DateTimePicker.php |
| `DateTimePicker::onChangedHandler()` | method | src/Widget/DateTimePicker.php |
| `DateTimePickerState` | class | src/Widget/DateTimePicker.php |
| `Dock::slotKid()` | method | src/Widget/Dock.php |
| `Dock::overflowValue()` | method | src/Widget/Dock.php |
| `DockElement` | class | src/Widget/DockElement.php |
| `EditableTextState::$editing` | property | src/Widget/EditableTextState.php |
| `Element::$box` | property | src/Widget/Element.php |
| `Element::root()` | method | src/Widget/Element.php |
| `Element::isAnimationActive()` | method | src/Widget/Element.php |
| `Element::markAnimationActive()` | method | src/Widget/Element.php |
| `Element::onMount()` | method | src/Widget/Element.php |
| `Element::syncBox()` | method | src/Widget/Element.php |
| `Element::effectiveBox()` | method | src/Widget/Element.php |
| `Element::markNeedsBuild()` | method | src/Widget/Element.php |
| `Element::findElement()` | method | src/Widget/Element.php |
| `Element::findBox()` | method | src/Widget/Element.php |
| `Element::findBoxOf()` | method | src/Widget/Element.php |
| `Element::effectiveBoxOf()` | method | src/Widget/Element.php |
| `Element::adoptBoxes()` | method | src/Widget/Element.php |
| `Element::didUpdateWidget()` | method | src/Widget/Element.php |
| `Element::applyContext()` | method | src/Widget/Element.php |
| `Element::settle()` | method | src/Widget/Element.php |
| `Grid::rowOf()` | method | src/Widget/Grid.php |
| `Grid::chunk()` | method | src/Widget/Grid.php |
| `InlineEditorBox` | class | src/Widget/InlineEditorBox.php |
| `InlineEditorBox::make()` | method | src/Widget/InlineEditorBox.php |
| `InlineEditorBox::editorStyle()` | method | src/Widget/InlineEditorBox.php |
| `LayoutBuilder::builderClosure()` | method | src/Widget/LayoutBuilder.php |
| `LazyList::keyMapper()` | method | src/Widget/LazyList.php |
| `LazyList::viewportExtentPx()` | method | src/Widget/LazyList.php |
| `LazyList::initialOffsetPx()` | method | src/Widget/LazyList.php |
| `LazyList::controllerValue()` | method | src/Widget/LazyList.php |
| `LazyList::buildItem()` | method | src/Widget/LazyList.php |
| `LazyListState` | class | src/Widget/LazyListState.php |
| `LazyListState::dispose()` | method | src/Widget/LazyListState.php |
| `MenuItem::paintsBoxChrome()` | method | src/Widget/Menu/MenuItem.php |
| `Modal::bindDismiss()` | method | src/Widget/Modal.php |
| `ModalHost` | class | src/Widget/ModalHost.php |
| `OffstageElement::$parked` | property | src/Widget/OffstageElement.php |
| `OptionPanel` | class | src/Widget/OptionPanel.php |
| `OptionPanel::source()` | method | src/Widget/OptionPanel.php |
| `OptionPanel::content()` | method | src/Widget/OptionPanel.php |
| `OptionPanel::minWidthValue()` | method | src/Widget/OptionPanel.php |
| `OptionPanel::maxHeightValue()` | method | src/Widget/OptionPanel.php |
| `OptionPanel::scrollableValue()` | method | src/Widget/OptionPanel.php |
| `OptionPanel::notifyClosed()` | method | src/Widget/OptionPanel.php |
| `OptionPanelState` | class | src/Widget/OptionPanel.php |
| `PickerPanelSignal` | class | src/Widget/PickerPanelSignal.php |
| `PopupPanel::paintsBoxChrome()` | method | src/Widget/PopupPanel.php |
| `RenderBoxWidget::paintsBoxChrome()` | method | src/Widget/RenderBoxWidget.php |
| `RenderBoxWidget::paintsDisabledLook()` | method | src/Widget/RenderBoxWidget.php |
| `RenderBoxWidget::defaultBoxStyle()` | method | src/Widget/RenderBoxWidget.php |
| `ScrollBarFade` | class | src/Widget/ScrollBarFade.php |
| `ScrollController::attach()` | method | src/Widget/ScrollController.php |
| `ScrollController::detach()` | method | src/Widget/ScrollController.php |
| `ScrollController::sync()` | method | src/Widget/ScrollController.php |
| `ScrollLane` | class | src/Widget/ScrollLane.php |
| `ScrollMotion` | class | src/Widget/ScrollMotion.php |
| `ScrollViewBox` | class | src/Widget/ScrollViewBox.php |
| `ScrollViewBox::make()` | method | src/Widget/ScrollViewBox.php |
| `ScrollViewState::initState()` | method | src/Widget/ScrollViewState.php |
| `ScrollViewState::dispose()` | method | src/Widget/ScrollViewState.php |
| `Select::optionsValue()` | method | src/Widget/Select.php |
| `Select::valueValue()` | method | src/Widget/Select.php |
| `Select::placeholderText()` | method | src/Widget/Select.php |
| `Select::triggerWidthValue()` | method | src/Widget/Select.php |
| `Select::statusValue()` | method | src/Widget/Select.php |
| `Select::onOpenHandler()` | method | src/Widget/Select.php |
| `Select::onChangedHandler()` | method | src/Widget/Select.php |
| `Select::labelAt()` | method | src/Widget/Select.php |
| `SelectState` | class | src/Widget/Select.php |
| `SelectState::$signal` | property | src/Widget/Select.php |
| `SelectState::$dismiss` | property | src/Widget/Select.php |
| `SelectState::$highlight` | property | src/Widget/Select.php |
| `SelectPanelSignal` | class | src/Widget/Select.php |
| `SemanticsBuilder::__construct()` | method | src/Widget/SemanticsBuilder.php |
| `SpinnerState` | class | src/Widget/SpinnerState.php |
| `State::$buildingState` | property | src/Widget/State.php |
| `State::$initializing` | property | src/Widget/State.php |
| `State::$initialized` | property | src/Widget/State.php |
| `State::$lastTickedFrame` | property | src/Widget/State.php |
| `State::attach()` | method | src/Widget/State.php |
| `State::element()` | method | src/Widget/State.php |
| `State::replaceDisposer()` | method | src/Widget/State.php |
| `State::watchSlot()` | method | src/Widget/State.php |
| `State::dropDisposers()` | method | src/Widget/State.php |
| `State::callInit()` | method | src/Widget/State.php |
| `State::consumeDirty()` | method | src/Widget/State.php |
| `State::noteRebuilt()` | method | src/Widget/State.php |
| `State::enterBuild()` | method | src/Widget/State.php |
| `State::leaveBuild()` | method | src/Widget/State.php |
| `State::didUpdateWidget()` | method | src/Widget/State.php |
| `State::adoptWidget()` | method | src/Widget/State.php |
| `StatefulElement::$boxFromState` | property | src/Widget/StatefulElement.php |
| `StatefulElement::syncBox()` | method | src/Widget/StatefulElement.php |
| `Table::keyMapper()` | method | src/Widget/Table.php |
| `Table::copyHandler()` | method | src/Widget/Table.php |
| `Table::selectionChangeHandler()` | method | src/Widget/Table.php |
| `Table::rowTapHandler()` | method | src/Widget/Table.php |
| `Table::columnWidthOverrides()` | method | src/Widget/Table.php |
| `TableBox` | class | src/Widget/TableBox.php |
| `TableBox::make()` | method | src/Widget/TableBox.php |
| `TableContract` | class | src/Widget/TableContract.php |
| `TableElement` | class | src/Widget/TableElement.php |
| `TableGeometry` | class | src/Widget/TableGeometry.php |
| `TableGridPicker` | class | src/Widget/TableGridPicker.php |
| `TableState` | class | src/Widget/TableState.php |
| `TextAreaBox` | class | src/Widget/TextAreaBox.php |
| `TextAreaBox::make()` | method | src/Widget/TextAreaBox.php |
| `TextFieldBox` | class | src/Widget/TextFieldBox.php |
| `TextFieldBox::make()` | method | src/Widget/TextFieldBox.php |
| `WidgetFocus` | trait | src/Widget/Traits/WidgetFocus.php |
| `WidgetFocus::focusNode()` | method | src/Widget/Traits/WidgetFocus.php |
| `WidgetFocus::cursorShape()` | method | src/Widget/Traits/WidgetFocus.php |
| `WidgetFocus::markFocusable()` | method | src/Widget/Traits/WidgetFocus.php |
| `WidgetFocus::isFocusable()` | method | src/Widget/Traits/WidgetFocus.php |
| `WidgetInteraction` | trait | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::draggableValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::dragTargetValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::contextMenuValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::hoverHandlerValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::pointerHandlerValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::pointerOwnsPressValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::windowDragHandlerValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::filesDropHandlerValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::keyHandlerValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::tooltipTextValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::overlayPanelValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::overlayPanelMaxWidthValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetInteraction::overlaySignatureValue()` | method | src/Widget/Traits/WidgetInteraction.php |
| `WidgetModifiers` | trait | src/Widget/Traits/WidgetModifiers.php |
| `WidgetSemantics` | trait | src/Widget/Traits/WidgetSemantics.php |
| `WidgetSemantics::semanticsRole()` | method | src/Widget/Traits/WidgetSemantics.php |
| `WidgetSemantics::semanticsSelectState()` | method | src/Widget/Traits/WidgetSemantics.php |
| `WidgetSemantics::semanticsExpandState()` | method | src/Widget/Traits/WidgetSemantics.php |
| `WidgetSemantics::semanticsLevel()` | method | src/Widget/Traits/WidgetSemantics.php |
| `WidgetSemantics::semanticsName()` | method | src/Widget/Traits/WidgetSemantics.php |
| `WidgetSemantics::semanticsValue()` | method | src/Widget/Traits/WidgetSemantics.php |
| `WidgetSemantics::semanticsEnabled()` | method | src/Widget/Traits/WidgetSemantics.php |
| `WidgetSemantics::semanticsRangeMin()` | method | src/Widget/Traits/WidgetSemantics.php |
| `WidgetSemantics::semanticsRangeMax()` | method | src/Widget/Traits/WidgetSemantics.php |
| `TreeNode::assertNodes()` | method | src/Widget/TreeNode.php |
| `TreeView::visibleRows()` | method | src/Widget/TreeView.php |
| `Widget::$sealed` | property | src/Widget/Widget.php |
| `Widget::$key` | property | src/Widget/Widget.php |
| `Widget::$focus` | property | src/Widget/Widget.php |
| `Widget::$cursor` | property | src/Widget/Widget.php |
| `Widget::$focusable` | property | src/Widget/Widget.php |
| `Widget::$draggable` | property | src/Widget/Widget.php |
| `Widget::$dragTarget` | property | src/Widget/Widget.php |
| `Widget::$contextMenu` | property | src/Widget/Widget.php |
| `Widget::$hoverHandler` | property | src/Widget/Widget.php |
| `Widget::$pointerHandler` | property | src/Widget/Widget.php |
| `Widget::$pointerOwnsPress` | property | src/Widget/Widget.php |
| `Widget::$windowDragHandler` | property | src/Widget/Widget.php |
| `Widget::$filesDropHandler` | property | src/Widget/Widget.php |
| `Widget::$keyHandler` | property | src/Widget/Widget.php |
| `Widget::$tooltipText` | property | src/Widget/Widget.php |
| `Widget::$overlayPanelFactory` | property | src/Widget/Widget.php |
| `Widget::$overlaySignature` | property | src/Widget/Widget.php |
| `Widget::$semRole` | property | src/Widget/Widget.php |
| `Widget::$semName` | property | src/Widget/Widget.php |
| `Widget::$semValue` | property | src/Widget/Widget.php |
| `Widget::$semEnabled` | property | src/Widget/Widget.php |
| `Widget::$semRangeMin` | property | src/Widget/Widget.php |
| `Widget::$semSelect` | property | src/Widget/Widget.php |
| `Widget::$semExpand` | property | src/Widget/Widget.php |
| `Widget::$semLevel` | property | src/Widget/Widget.php |
| `Widget::__clone()` | method | src/Widget/Widget.php |
| `Widget::childContext()` | method | src/Widget/Widget.php |
| `Widget::createElement()` | method | src/Widget/Widget.php |
| `Widget::identity()` | method | src/Widget/Widget.php |
| `Widget::seal()` | method | src/Widget/Widget.php |
| `Widget::isSealed()` | method | src/Widget/Widget.php |
| `Widget::mods()` | method | src/Widget/Widget.php |
| `Widget::expandChildren()` | method | src/Widget/Widget.php |


