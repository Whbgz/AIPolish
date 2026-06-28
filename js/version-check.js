(function () {
    if (typeof window.AIPolishVersionCheck === 'undefined') return;

    var config = window.AIPolishVersionCheck || {};
    var stateEl = document.getElementById('ai-polish-version-state');
    var detailEl = document.getElementById('ai-polish-version-detail');
    var actionEl = document.getElementById('ai-polish-version-action');
    var retryBtn = document.getElementById('ai-polish-version-retry');

    if (!stateEl) return;

    var repo = String(config.repo || 'Whbgz/AIPolish');
    var repoUrl = String(config.repoUrl || ('https://github.com/' + repo));
    var currentVersion = normalizeVersion(config.currentVersion);
    var repoPath = repo.split('/').map(encodeURIComponent).join('/');

    function setState(color, text) {
        stateEl.style.color = color;
        stateEl.textContent = text;
    }

    function setDetail(text) {
        if (detailEl) detailEl.textContent = text || '';
    }

    function setAction(url, text) {
        if (!actionEl) return;

        actionEl.textContent = '';
        if (!url) return;

        var link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = text;
        actionEl.appendChild(link);
    }

    function normalizeVersion(version) {
        var match = String(version || '').trim().match(/v?(\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?)/i);
        return match ? match[1] : '';
    }

    function compareVersions(left, right) {
        var leftParts = normalizeVersion(left).split(/[+-]/)[0].split('.');
        var rightParts = normalizeVersion(right).split(/[+-]/)[0].split('.');
        var maxLength = Math.max(leftParts.length, rightParts.length);

        for (var i = 0; i < maxLength; i++) {
            var leftValue = parseInt(leftParts[i] || '0', 10);
            var rightValue = parseInt(rightParts[i] || '0', 10);

            if (leftValue > rightValue) return 1;
            if (leftValue < rightValue) return -1;
        }

        return 0;
    }

    function createHttpError(response) {
        var error = new Error('HTTP ' + response.status);
        error.status = response.status;
        return error;
    }

    function fetchJson(url) {
        if (typeof fetch !== 'function') {
            return Promise.reject(new Error('当前浏览器不支持 fetch。'));
        }

        return fetch(url, {
            headers: { Accept: 'application/vnd.github+json' },
            cache: 'no-store',
            credentials: 'omit',
            referrerPolicy: 'no-referrer'
        }).then(function (response) {
            if (!response.ok) throw createHttpError(response);
            return response.json();
        });
    }

    function fetchText(url) {
        if (typeof fetch !== 'function') {
            return Promise.reject(new Error('当前浏览器不支持 fetch。'));
        }

        return fetch(url, {
            cache: 'no-store',
            credentials: 'omit',
            referrerPolicy: 'no-referrer'
        }).then(function (response) {
            if (!response.ok) throw createHttpError(response);
            return response.text();
        });
    }

    function getLatestRelease() {
        var latestReleaseApi = 'https://api.github.com/repos/' + repoPath + '/releases/latest';

        return fetchJson(latestReleaseApi).then(function (release) {
            var version = normalizeVersion(release.tag_name || release.name);
            if (!version) throw new Error('最新 Release 未包含可识别版本号。');

            return {
                version: version,
                source: 'GitHub Release',
                url: release.html_url || (repoUrl + '/releases/latest')
            };
        });
    }

    function getLatestTag() {
        var tagsApi = 'https://api.github.com/repos/' + repoPath + '/tags?per_page=100';

        return fetchJson(tagsApi).then(function (tags) {
            var bestTag = null;
            var bestVersion = '';

            if (!tags || !tags.length) {
                throw new Error('仓库没有可用 Tag。');
            }

            for (var i = 0; i < tags.length; i++) {
                var version = normalizeVersion(tags[i].name);
                if (!version) continue;

                if (!bestVersion || compareVersions(version, bestVersion) > 0) {
                    bestVersion = version;
                    bestTag = tags[i];
                }
            }

            if (!bestTag || !bestVersion) {
                throw new Error('Tag 未包含可识别版本号。');
            }

            return {
                version: bestVersion,
                source: 'GitHub Tag',
                url: repoUrl + '/tree/' + String(bestTag.name).split('/').map(encodeURIComponent).join('/')
            };
        });
    }

    function getDefaultBranch() {
        var repoApi = 'https://api.github.com/repos/' + repoPath;

        return fetchJson(repoApi).then(function (repoInfo) {
            return repoInfo.default_branch || 'main';
        }).catch(function () {
            return 'main';
        });
    }

    function getPluginVersionFromBranch(branch) {
        var safeBranch = String(branch || 'main').split('/').map(encodeURIComponent).join('/');
        var rawUrl = 'https://raw.githubusercontent.com/' + repoPath + '/' + safeBranch + '/Plugin.php';

        return fetchText(rawUrl).then(function (source) {
            var match = source.match(/@version\s+([^\s*]+)/i);
            var version = normalizeVersion(match ? match[1] : '');

            if (!version) throw new Error('Plugin.php 未包含可识别版本号。');

            return {
                version: version,
                source: '仓库源码',
                url: repoUrl + '/blob/' + safeBranch + '/Plugin.php'
            };
        });
    }

    function getLatestFromPluginFile() {
        return getDefaultBranch().then(function (defaultBranch) {
            var branches = [];
            var candidates = [defaultBranch, 'main', 'master'];

            for (var i = 0; i < candidates.length; i++) {
                if (candidates[i] && branches.indexOf(candidates[i]) === -1) {
                    branches.push(candidates[i]);
                }
            }

            function tryNext(index) {
                if (index >= branches.length) {
                    throw new Error('未能读取仓库版本信息。');
                }

                return getPluginVersionFromBranch(branches[index]).catch(function () {
                    return tryNext(index + 1);
                });
            }

            return tryNext(0);
        });
    }

    function renderVersionResult(latest) {
        var latestVersion = normalizeVersion(latest && latest.version);
        if (!currentVersion || !latestVersion) {
            throw new Error('版本号格式无效。');
        }

        var compareResult = compareVersions(currentVersion, latestVersion);
        var source = latest.source || 'GitHub';

        if (compareResult < 0) {
            setState('#c60', '发现新版本 v' + latestVersion + '，当前版本 v' + currentVersion + '。');
            setDetail('检测来源：' + source + '。建议更新前先备份插件目录与配置。');
            setAction(latest.url || repoUrl, '前往 GitHub 查看更新');
            return;
        }

        if (compareResult > 0) {
            setState('#c60', '当前本地版本 v' + currentVersion + ' 高于公开版本 v' + latestVersion + '。');
            setDetail('检测来源：' + source + '。这通常表示当前是本地开发版，或新版本还没有推送到公开仓库。');
            setAction(repoUrl, '查看 GitHub 仓库');
            return;
        }

        setState('#090', '当前已是最新版本 v' + currentVersion + '。');
        setDetail('检测来源：' + source + '。');
        setAction(repoUrl, '查看 GitHub 仓库');
    }

    function renderFailure() {
        setState('#c00', '版本检测失败。');
        setDetail('无法访问 GitHub 或仓库版本格式不符合预期，请稍后重试。');
        setAction(repoUrl, '手动查看 GitHub 仓库');
    }

    function checkVersion() {
        setState('#666', '正在检测新版本...');
        setDetail('');
        setAction('', '');

        if (retryBtn) retryBtn.disabled = true;

        getLatestRelease()
            .catch(function () {
                return getLatestTag();
            })
            .catch(function () {
                return getLatestFromPluginFile();
            })
            .then(renderVersionResult)
            .catch(renderFailure)
            .then(function () {
                if (retryBtn) retryBtn.disabled = false;
            });
    }

    if (retryBtn) {
        retryBtn.addEventListener('click', checkVersion);
    }

    checkVersion();
})();
