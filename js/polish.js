document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.AIPolishConfig === 'undefined') return;

    var actionUrl = window.AIPolishConfig.actionUrl;
    var titleInput = document.getElementById('title');
    var textarea = document.getElementById('text');

    // ===== 流式请求核心函数 =====
    function streamPolish(params, onToken, onDone, onError) {
        fetch(actionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params)
        })
        .then(function (response) {
            var contentType = response.headers.get('Content-Type') || '';

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

            function read() {
                reader.read().then(function (result) {
                    if (result.done) {
                        onDone();
                        return;
                    }

                    buffer += decoder.decode(result.value, { stream: true });

                    // 按双换行分割 SSE 事件
                    var parts = buffer.split('\n\n');
                    buffer = parts.pop(); // 最后一段可能不完整，保留

                    for (var i = 0; i < parts.length; i++) {
                        var part = parts[i].trim();
                        if (!part) continue;

                        var lines = part.split('\n');
                        var eventType = '';
                        var eventData = '';

                        for (var j = 0; j < lines.length; j++) {
                            if (lines[j].indexOf('event: ') === 0) eventType = lines[j].slice(7);
                            if (lines[j].indexOf('data: ') === 0)  eventData = lines[j].slice(6);
                        }

                        if (eventType === 'token' && eventData) {
                            try {
                                var obj = JSON.parse(eventData);
                                if (obj.content) onToken(obj.content);
                            } catch (e) {}
                        } else if (eventType === 'done') {
                            onDone();
                            return;
                        } else if (eventType === 'error') {
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


    // ===== 1. 正文润色按钮 =====
    waitForElement('#wmd-button-bar .wmd-button-row', function (toolbar) {
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
        toolbar.appendChild(btnGroup);

        var snapshot = '';

        undoBtn.addEventListener('click', function () {
            textarea.value = snapshot;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            undoBtn.style.display = 'none';
        });

        polishBtn.addEventListener('click', function () {
            var start = textarea.selectionStart;
            var end   = textarea.selectionEnd;
            var isSelection = (start !== end);
            var textToPolish = isSelection ? textarea.value.substring(start, end) : textarea.value;

            if (!textToPolish.trim()) {
                alert('要润色的内容为空！');
                return;
            }

            // 保存快照
            snapshot = textarea.value;
            undoBtn.style.display = 'none';

            var before = isSelection ? textarea.value.substring(0, start) : '';
            var after  = isSelection ? textarea.value.substring(end) : '';
            var accumulated = '';
            var charCount = 0;

            polishBtn.disabled = true;
            polishBtn.classList.add('ai-polish-loading');

            // 先清空目标区域，准备流式填入
            textarea.value = before + after;

            streamPolish(
                {
                    text: textToPolish,
                    type: 'content',
                    context: titleInput ? titleInput.value : ''
                },
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
                }
            );
        });
    });


    // ===== 2. 标题区域按钮 =====
    if (titleInput) {
        var titleParent = titleInput.parentNode;
        if (!titleParent) return;
        titleParent.style.position = 'relative';

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
        titleParent.appendChild(titleBtnBar);

        var titleSnapshot = '';

        undoTitleBtn.addEventListener('click', function () {
            titleInput.value = titleSnapshot;
            titleInput.dispatchEvent(new Event('input', { bubbles: true }));
            undoTitleBtn.style.display = 'none';
        });

        function callTitleStream(type, btnElement, originalLabel) {
            var titleText = titleInput.value;

            if (type === 'title' && !titleText.trim()) {
                alert('标题为空，无法润色！如果想根据内容起标题，请点击"一键起标题"');
                return;
            }
            if (type === 'generate_title' && textarea && !textarea.value.trim()) {
                alert('文章正文为空，无法生成标题！请先写点内容');
                return;
            }

            titleSnapshot = titleText;
            undoTitleBtn.style.display = 'none';

            var contentContext = textarea ? textarea.value.substring(0, 2000) : '';
            var accumulated = '';
            var charCount = 0;

            btnElement.disabled = true;
            btnElement.classList.add('ai-polish-loading');
            titleInput.value = '';

            streamPolish(
                {
                    text: titleText,
                    type: type,
                    context: contentContext
                },
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
                    btnElement.disabled = false;
                    btnElement.classList.remove('ai-polish-loading');
                    btnElement.innerHTML = originalLabel;
                    if (accumulated) undoTitleBtn.style.display = '';
                },
                // onError
                function (msg) {
                    if (!accumulated) titleInput.value = titleSnapshot;
                    alert('操作失败：' + msg);
                    btnElement.disabled = false;
                    btnElement.classList.remove('ai-polish-loading');
                    btnElement.innerHTML = originalLabel;
                    if (accumulated) undoTitleBtn.style.display = '';
                }
            );
        }

        polishTitleBtn.addEventListener('click', function () {
            callTitleStream('title', polishTitleBtn, '润色标题');
        });

        generateTitleBtn.addEventListener('click', function () {
            callTitleStream('generate_title', generateTitleBtn, '一键起标题');
        });
    }
});
