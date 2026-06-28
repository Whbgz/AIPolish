document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.AIPolishConfig === 'undefined') return;

    var actionUrl = window.AIPolishConfig.actionUrl;
    var imageVisionEnabled = !!window.AIPolishConfig.imageVision;
    var maxImageProgressCount = 8;
    var titleInput = document.getElementById('title');
    var textarea = document.getElementById('text');

    function getHiddenInput(name) {
        return document.querySelector('input[name="' + name + '"]');
    }

    function setHiddenValue(name, value) {
        if (!value) return;

        var input = getHiddenInput(name);
        if (!input) {
            var form = document.forms.write_post || document.forms.write_page || document.querySelector('form.typecho-post-area');
            if (!form) return;
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }

        input.value = value;
    }

    function getSavedIds() {
        var cidInput = getHiddenInput('cid');
        var draftInput = getHiddenInput('draft');
        var cid = cidInput ? parseInt(cidInput.value, 10) : 0;
        var draft = draftInput ? parseInt(draftInput.value, 10) : 0;

        if (!cid) {
            var match = window.location.search.match(/[?&]cid=(\d+)/);
            cid = match ? parseInt(match[1], 10) : 0;
        }

        return {
            cid: cid > 0 ? String(cid) : '',
            draft: draft > 0 ? String(draft) : ''
        };
    }

    // 与 PHP 端约定：剥离 <!--markdown--> 前缀后再发送给后端
    function stripMarkdownPrefixJs(text) {
        text = text || '';
        return text.indexOf('<!--markdown-->') === 0 ? text.substring(15) : text;
    }

    function addDraftParams(params, ids, options) {
        options = options || {};
        params.cid = ids.cid;
        if (ids.draft) params.draft = ids.draft;

        // 直接把浏览器里的正文/标题内容传给后端，避免和数据库草稿做 hash 校验
        // （草稿存储时可能对换行/HTML 实体做了转换，导致 hash 永远对不上）
        // 选中润色时不需要传 textContent（只传选中的 text），节省带宽
        if (textarea && !options.skipTextContent) {
            params.textContent = stripMarkdownPrefixJs(textarea.value);
        }
        if (titleInput && !options.skipTitleContent) {
            params.titleContent = titleInput.value;
        }

        return params;
    }

    function parseJsonSafely(text) {
        try {
            return JSON.parse(text);
        } catch (e) {
            return null;
        }
    }

    // ===== 自动保存草稿机制 =====
    // 当点击 AI 按钮时，若检测到内容未保存，弹窗确认后自动触发 Typecho 的"保存草稿"，
    // 并通过已 patch 的 XHR/fetch 拦截响应，等保存完成后再继续 AI 流程。
    var pendingSaveCallbacks = null;

    function syncSavedIdsFromResponse(res) {
        var wasSuccess = res && res.success === 1;
        if (wasSuccess) {
            if (res.cid) setHiddenValue('cid', res.cid);
            if (res.draftId) setHiddenValue('draft', res.draftId);
        }

        // 通知等待中的 saveDraftAndWait
        if (pendingSaveCallbacks) {
            if (wasSuccess) {
                pendingSaveCallbacks.resolve(res);
            } else {
                pendingSaveCallbacks.reject(res && res.msg ? res.msg : '保存草稿失败。');
            }
        }
    }

    // 查找 Typecho 编辑页的"保存草稿"按钮
    function getWriteForm() {
        return document.forms.write_post
            || document.forms.write_page
            || (textarea ? textarea.form : null)
            || document.querySelector('form.typecho-post-area')
            || document.querySelector('form');
    }

    function findDraftSaveButton() {
        var form = getWriteForm();
        if (!form) return null;

        // 方式1：button[name="do"][value="save"]，Typecho 默认的保存草稿按钮
        var btn = form.querySelector('button[name="do"][value="save"], input[type="submit"][name="do"][value="save"]');
        if (btn) return btn;

        // 方式2：按钮文本匹配（兼容"保存草稿"等不同主题）
        var buttons = form.querySelectorAll('button, input[type="submit"]');
        for (var i = 0; i < buttons.length; i++) {
            var text = (buttons[i].textContent || buttons[i].value || '').trim();
            // 必须含"草稿"或"保存草稿"，排除"保存""发布"等可能直接发布的按钮
            if (/草稿/.test(text) || text === '保存草稿') {
                return buttons[i];
            }
        }

        return null;
    }

    // 查找底部提交区的"预览文章/预览页面"按钮，区别于编辑器工具栏的 Markdown 预览按钮
    function findArticlePreviewButton() {
        var form = getWriteForm();
        if (!form) return null;

        var previewBtn = form.querySelector('button[name="do"][value="preview"], input[type="submit"][name="do"][value="preview"]');
        if (previewBtn) return previewBtn;

        var buttons = form.querySelectorAll('button, input[type="submit"], a.btn');
        for (var i = 0; i < buttons.length; i++) {
            if (buttons[i].closest && buttons[i].closest('#wmd-button-bar')) continue;

            var text = (buttons[i].textContent || buttons[i].value || '').replace(/\s+/g, '');
            if (/预览(文章|页面)?/.test(text)) {
                return buttons[i];
            }
        }

        return null;
    }

    // 触发 Typecho 保存草稿，返回 Promise，在 AJAX 保存完成后 resolve
    function saveDraftAndWait(timeout) {
        return new Promise(function (resolve, reject) {
            var saveBtn = findDraftSaveButton();
            if (!saveBtn) {
                reject('未找到"保存草稿"按钮，请手动保存草稿后再试。');
                return;
            }

            var timer = setTimeout(function () {
                pendingSaveCallbacks = null;
                reject('保存草稿超时，请稍后重试。');
            }, timeout || 20000);

            pendingSaveCallbacks = {
                resolve: function (res) { clearTimeout(timer); pendingSaveCallbacks = null; resolve(res); },
                reject: function (err) { clearTimeout(timer); pendingSaveCallbacks = null; reject(err); }
            };

            saveBtn.click();
        });
    }

    // 检查是否可以使用 AI：
    // - 必须有 cid（PHP 端用于权限校验）
    // - 不再做草稿内容 hash 校验（直接从浏览器 textarea 传内容）
    // - 没有 cid 时弹窗询问是否保存草稿（用于新建文章首次保存获取 cid）
    function ensureDraftSaved(actionName, setLoading) {
        return new Promise(function (resolve, reject) {
            var ids = getSavedIds();
            if (ids.cid) {
                resolve(ids);
                return;
            }

            // 没 cid（新建文章未保存过），需要先保存一次拿到 cid
            var confirmed = confirm('使用 AI ' + actionName + ' 前需要先保存草稿（用于权限校验），是否现在保存？');
            if (!confirmed) {
                reject(null);
                return;
            }

            if (typeof setLoading === 'function') setLoading('正在保存草稿...');
            saveDraftAndWait().then(function () {
                resolve(getSavedIds());
            }).catch(function (err) {
                reject(err);
            });
        });
    }

    (function patchTypechoDraftSave() {
        if (window.__AIPolishDraftPatchApplied) return;
        window.__AIPolishDraftPatchApplied = true;

        if (window.XMLHttpRequest) {
            var originalOpen = XMLHttpRequest.prototype.open;
            var originalSend = XMLHttpRequest.prototype.send;

            XMLHttpRequest.prototype.open = function (method, url) {
                this.__aiPolishUrl = url ? String(url) : '';
                return originalOpen.apply(this, arguments);
            };

            XMLHttpRequest.prototype.send = function (body) {
                this.addEventListener('load', function () {
                    if (this.__aiPolishUrl.indexOf('contents-post-edit') === -1
                        && this.__aiPolishUrl.indexOf('contents-page-edit') === -1) {
                        return;
                    }

                    syncSavedIdsFromResponse(parseJsonSafely(this.responseText || ''));
                });

                return originalSend.apply(this, arguments);
            };
        }

        if (window.fetch) {
            var originalFetch = window.fetch;
            window.fetch = function (input, init) {
                return originalFetch.apply(this, arguments).then(function (response) {
                    var url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
                    if (url.indexOf('contents-post-edit') !== -1 || url.indexOf('contents-page-edit') !== -1) {
                        response.clone().text().then(function (text) {
                            syncSavedIdsFromResponse(parseJsonSafely(text || ''));
                        }).catch(function () {});
                    }

                    return response;
                });
            };
        }
    })();

    // ===== 流式请求核心函数 =====
    function streamPolish(params, onToken, onDone, onError, onProgress) {
        fetch(actionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params)
        })
        .then(function (response) {
            var contentType = response.headers.get('Content-Type') || '';

            if (!response.ok) {
                return response.text().then(function (body) {
                    var detail = body ? body.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() : '';
                    if (detail.length > 120) detail = detail.substring(0, 120) + '...';
                    onError('HTTP ' + response.status + (response.status === 524 ? '（网关超时）' : '') + (detail ? ': ' + detail : ''));
                });
            }

            // 预校验阶段的错误以 JSON 返回
            if (contentType.indexOf('application/json') !== -1) {
                return response.json().then(function (res) {
                    onError(res.msg || '未知错误');
                });
            }

            // SSE 流式读取
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';
            var finished = false;

            function read() {
                reader.read().then(function (result) {
                    if (result.done) {
                        if (!finished) {
                            onError('连接提前结束，可能是网关或服务器超时中断。');
                        }
                        return;
                    }

                    buffer += decoder.decode(result.value, { stream: true });

                    // 按双换行（兼容 CRLF）分割 SSE 事件
                    var parts = buffer.split(/\r?\n\r?\n/);
                    buffer = parts.pop(); // 最后一段可能不完整，保留

                    for (var i = 0; i < parts.length; i++) {
                        var part = parts[i].trim();
                        if (!part) continue;

                        var lines = part.split(/\r?\n/);
                        var eventType = '';
                        var dataLines = [];

                        for (var j = 0; j < lines.length; j++) {
                            if (lines[j].indexOf('event: ') === 0) eventType = lines[j].slice(7);
                            else if (lines[j].indexOf('event:') === 0) eventType = lines[j].slice(6).trim();
                            else if (lines[j].indexOf('data: ') === 0) dataLines.push(lines[j].slice(6));
                            else if (lines[j].indexOf('data:') === 0) dataLines.push(lines[j].slice(5).trim());
                        }
                        var eventData = dataLines.join('\n');

                        if (eventType === 'token' && eventData) {
                            try {
                                var obj = JSON.parse(eventData);
                                if (obj.content) onToken(obj.content);
                            } catch (e) {}
                        } else if (eventType === 'ping') {
                            // ping 事件可能带 progress 字段，告诉用户当前在下载图片
                            try {
                                var pingObj = JSON.parse(eventData);
                                if (pingObj && pingObj.progress && typeof onProgress === 'function') {
                                    onProgress(pingObj.progress);
                                }
                            } catch (e) {}
                            continue;
                        } else if (eventType === 'ready') {
                            continue;
                        } else if (eventType === 'done') {
                            finished = true;
                            onDone();
                            return;
                        } else if (eventType === 'error') {
                            finished = true;
                            try {
                                var errObj = JSON.parse(eventData);
                                onError(errObj.msg || '未知错误');
                            } catch (e) {
                                onError('未知错误');
                            }
                            return;
                        }
                    }

                    read(); // 继续读取下一个 chunk
                }).catch(function (err) {
                    onError('网络中断: ' + err.message);
                });
            }

            read();
        })
        .catch(function (err) {
            onError('网络错误: ' + err.message);
        });
    }


    // ===== 图片上下文最小化：只统计数量，图片标记由后端从草稿读取 =====
    function collectImageSource(text) {
        if (!imageVisionEnabled || !text) return { source: '', count: 0 };

        var lines = text.split(/\r?\n/);
        var imageLines = [];
        var imagePattern = /!\[[^\]]*\]\([^)]*\)|!\[[^\]]*\]\[[^\]]*\]|<img\b[^>]*>/ig;
        var imageCount = 0;

        for (var i = 0; i < lines.length; i++) {
            var matches = lines[i].match(imagePattern);
            imagePattern.lastIndex = 0;
            if (matches && matches.length) {
                imageLines.push(lines[i]);
                imageCount += matches.length;
            }
        }

        if (!imageLines.length) return { source: '', count: 0 };
        return {
            source: '',
            count: Math.min(imageCount, maxImageProgressCount)
        };
    }

    function runImageUploadProgress(imageCount, setLabel, done) {
        if (!imageVisionEnabled || imageCount <= 0) {
            done();
            return;
        }

        var current = 0;
        function step() {
            current++;
            var percent = Math.min(100, Math.round((current / imageCount) * 100));
            setLabel('整理图片上下文...(' + current + '/' + imageCount + ') ' + percent + '%');

            if (current >= imageCount) {
                setTimeout(done, 120);
            } else {
                setTimeout(step, 90);
            }
        }

        step();
    }


    // ===== 等待元素出现（wmd 工具栏是 JS 动态渲染的）=====
    function waitForElement(selector, callback, maxWait) {
        maxWait = maxWait || 5000;
        var el = document.querySelector(selector);
        if (el) { callback(el); return; }
        var observer = new MutationObserver(function (mutations, obs) {
            var el = document.querySelector(selector);
            if (el) { obs.disconnect(); callback(el); }
        });
        observer.observe(document.body, { childList: true, subtree: true });
        setTimeout(function () { observer.disconnect(); }, maxWait);
    }


    // ===== 1. 正文润色按钮（优先位于底部"预览文章"按钮左边）=====
    waitForElement('button[name="do"][value="preview"], input[type="submit"][name="do"][value="preview"], #wmd-button-bar .wmd-button-row', function () {
        if (!textarea) return;

        var btnGroup = document.createElement('span');
        btnGroup.className = 'ai-polish-toolbar';

        var polishBtn = document.createElement('button');
        polishBtn.type = 'button';
        polishBtn.className = 'btn btn-s';
        polishBtn.innerHTML = 'AI 一键润色';
        polishBtn.title = '选中文字则只润色选中部分，否则润色全文';

        var undoBtn = document.createElement('button');
        undoBtn.type = 'button';
        undoBtn.className = 'btn btn-s ai-polish-undo-btn';
        undoBtn.innerHTML = '撤销润色';
        undoBtn.style.display = 'none';

        btnGroup.appendChild(polishBtn);
        btnGroup.appendChild(undoBtn);

        // 优先插入到底部"预览文章"按钮左边；找不到时回退到 wmd 工具栏，兼容旧页面结构
        var articlePreviewBtn = findArticlePreviewButton();
        if (articlePreviewBtn && articlePreviewBtn.parentNode) {
            btnGroup.className += ' ai-polish-submit-toolbar';
            polishBtn.classList.remove('btn-s');
            undoBtn.classList.remove('btn-s');
            articlePreviewBtn.parentNode.insertBefore(btnGroup, articlePreviewBtn);
        } else {
            var toolbar = document.querySelector('#wmd-button-bar .wmd-button-row');
            var previewBtn = toolbar ? toolbar.querySelector('#wmd-preview-button, .wmd-preview-button') : null;
            if (toolbar && previewBtn && previewBtn.parentNode === toolbar) {
                toolbar.insertBefore(btnGroup, previewBtn);
            } else if (toolbar) {
                toolbar.appendChild(btnGroup);
            }
        }

        var snapshot = '';

        undoBtn.addEventListener('click', function () {
            if (polishBtn.disabled) return; // 流式进行中禁止撤销
            textarea.value = snapshot;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            undoBtn.style.display = 'none';
        });

        polishBtn.addEventListener('click', function () {
            if (polishBtn.disabled) return; // 防止重复点击

            var start = textarea.selectionStart;
            var end   = textarea.selectionEnd;
            var isSelection = (start !== end);
            var textToPolish = isSelection ? textarea.value.substring(start, end) : textarea.value;

            if (!textToPolish.trim()) {
                alert('要润色的内容为空！');
                return;
            }

            // 进入流程即禁用按钮，避免在 confirm/保存期间被重复点击
            polishBtn.disabled = true;
            polishBtn.classList.add('ai-polish-loading');
            polishBtn.innerHTML = '检查草稿...';

            ensureDraftSaved('润色正文', function (label) {
                polishBtn.innerHTML = label;
            }).then(function (savedIds) {
                // 保存快照
                snapshot = textarea.value;
                undoBtn.style.display = 'none';

                var before = isSelection ? textarea.value.substring(0, start) : '';
                var after  = isSelection ? textarea.value.substring(end) : '';
                var accumulated = '';
                var charCount = 0;
                var imageContext = collectImageSource(snapshot);
                var requestParams = addDraftParams({ type: 'content' }, savedIds, {
                    skipTextContent: isSelection // 选中润色时不传全文，只传选中片段
                });
                // 选中润色时直接把选中部分作为 text 传给 PHP，PHP 无需再做 substring
                if (isSelection) {
                    requestParams.text = textarea.value.substring(start, end);
                }

                runImageUploadProgress(imageContext.count, function (label) {
                    polishBtn.innerHTML = label;
                }, function () {
                    // 先清空目标区域，准备流式填入
                    textarea.value = before + after;

                    streamPolish(
                        requestParams,
                    // onToken
                    function (token) {
                        accumulated += token;
                        charCount += token.length;
                        textarea.value = before + accumulated + after;
                        polishBtn.innerHTML = '润色中...(' + charCount + '字)';
                        // 把光标放到当前写入位置
                        var cursorPos = before.length + accumulated.length;
                        textarea.selectionStart = cursorPos;
                        textarea.selectionEnd = cursorPos;
                    },
                    // onDone
                    function () {
                        textarea.dispatchEvent(new Event('input', { bubbles: true }));
                        polishBtn.disabled = false;
                        polishBtn.classList.remove('ai-polish-loading');
                        polishBtn.innerHTML = 'AI 一键润色';
                        if (accumulated) undoBtn.style.display = '';
                    },
                    // onError
                    function (msg) {
                        // 出错时恢复原文
                        if (!accumulated) textarea.value = snapshot;
                        alert('润色失败：' + msg);
                        polishBtn.disabled = false;
                        polishBtn.classList.remove('ai-polish-loading');
                        polishBtn.innerHTML = 'AI 一键润色';
                        if (accumulated) undoBtn.style.display = '';
                    },
                    // onProgress
                    function (msg) {
                        polishBtn.innerHTML = msg;
                    }
                    );
                });
            }).catch(function (err) {
                // 失败或取消：恢复按钮状态
                polishBtn.disabled = false;
                polishBtn.classList.remove('ai-polish-loading');
                polishBtn.innerHTML = 'AI 一键润色';
                if (err) alert(err); // null 表示用户取消，不弹窗
            });
        });
    });


    // ===== 2. 标题区域按钮（放在页面 H2 标题"撰写新文章/编辑文章"的右边）=====
    if (titleInput) {
        // 找到页面顶部 h2 标题（"撰写新文章"或"编辑文章: xxx"）
        var pageTitleEl = document.querySelector('.typecho-page-title');
        if (!pageTitleEl) {
            // 兜底：取编辑区第一个 h2
            pageTitleEl = document.querySelector('.typecho-page h2, .typecho-content-area h2, h2');
        }

        var titleBtnBar = document.createElement('span');
        titleBtnBar.className = 'ai-polish-title-bar';

        var undoTitleBtn = document.createElement('button');
        undoTitleBtn.type = 'button';
        undoTitleBtn.className = 'btn btn-s ai-polish-undo-btn';
        undoTitleBtn.innerHTML = '撤销';
        undoTitleBtn.style.display = 'none';

        var generateTitleBtn = document.createElement('button');
        generateTitleBtn.type = 'button';
        generateTitleBtn.className = 'btn btn-s';
        generateTitleBtn.innerHTML = '一键起标题';

        var polishTitleBtn = document.createElement('button');
        polishTitleBtn.type = 'button';
        polishTitleBtn.className = 'btn btn-s';
        polishTitleBtn.innerHTML = '润色标题';

        titleBtnBar.appendChild(undoTitleBtn);
        titleBtnBar.appendChild(generateTitleBtn);
        titleBtnBar.appendChild(polishTitleBtn);

        if (pageTitleEl) {
            // 挂到 h2 标题末尾，按钮跟随标题文字流（紧贴文字右侧）
            pageTitleEl.appendChild(titleBtnBar);
        } else {
            // 找不到 h2 时退回到原方案：挂到 #title 父节点右侧
            var titleParent = titleInput.parentNode;
            if (titleParent) {
                titleParent.style.position = 'relative';
                titleParent.appendChild(titleBtnBar);
            }
        }

        var titleSnapshot = '';

        undoTitleBtn.addEventListener('click', function () {
            if (polishTitleBtn.disabled || generateTitleBtn.disabled) return; // 流式进行中禁止撤销
            titleInput.value = titleSnapshot;
            titleInput.dispatchEvent(new Event('input', { bubbles: true }));
            undoTitleBtn.style.display = 'none';
        });

        function callTitleStream(type, btnElement, originalLabel) {
            if (btnElement.disabled) return; // 防止重复点击

            var titleText = titleInput.value;

            if (type === 'title' && !titleText.trim()) {
                alert('标题为空，无法润色！如果想根据内容起标题，请点击"一键起标题"');
                return;
            }
            if (type === 'generate_title' && textarea && !textarea.value.trim()) {
                alert('文章正文为空，无法生成标题！请先写点内容');
                return;
            }

            // 进入流程即禁用两个标题按钮（避免润色标题/生成标题并发竞态）
            polishTitleBtn.disabled = true;
            generateTitleBtn.disabled = true;
            btnElement.classList.add('ai-polish-loading');
            btnElement.innerHTML = '检查草稿...';

            function restoreTitleButtons() {
                polishTitleBtn.disabled = false;
                generateTitleBtn.disabled = false;
                polishTitleBtn.classList.remove('ai-polish-loading');
                generateTitleBtn.classList.remove('ai-polish-loading');
                btnElement.innerHTML = originalLabel;
            }

            ensureDraftSaved(type === 'title' ? '润色标题' : '生成标题', function (label) {
                btnElement.innerHTML = label;
            }).then(function (savedIds) {
                titleSnapshot = titleText;
                undoTitleBtn.style.display = 'none';

                var fullContentContext = textarea ? textarea.value : '';
                var accumulated = '';
                var charCount = 0;
                var requestParams = addDraftParams({ type: type }, savedIds);

                // 标题类操作不走图片识别（服务端不处理），不显示假进度
                titleInput.value = '';

                streamPolish(
                    requestParams,
                // onToken
                function (token) {
                    accumulated += token;
                    charCount += token.length;
                    titleInput.value = accumulated;
                    btnElement.innerHTML = '思考中...(' + charCount + '字)';
                },
                // onDone
                function () {
                    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
                    restoreTitleButtons();
                    if (accumulated) undoTitleBtn.style.display = '';
                },
                // onError
                function (msg) {
                    if (!accumulated) titleInput.value = titleSnapshot;
                    alert('操作失败：' + msg);
                    restoreTitleButtons();
                    if (accumulated) undoTitleBtn.style.display = '';
                },
                // onProgress（标题流程不需要图片下载进度，但保留接口）
                null
                );
            }).catch(function (err) {
                // 失败或取消：恢复按钮状态
                restoreTitleButtons();
                if (err) alert(err); // null 表示用户取消，不弹窗
            });
        }

        polishTitleBtn.addEventListener('click', function () {
            callTitleStream('title', polishTitleBtn, '润色标题');
        });

        generateTitleBtn.addEventListener('click', function () {
            callTitleStream('generate_title', generateTitleBtn, '一键起标题');
        });
    }
});
