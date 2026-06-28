<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * AI一键润色插件，支持润色标题和正文，保留Markdown格式，流式响应
 * 
 * @package AIPolish 
 * @author Whbgz
 * @version 1.3.3
 * @link https://www.baiboke.cn/
 */
class AIPolish_Plugin implements Typecho_Plugin_Interface
{
    const VERSION = '1.3.3';
    const GITHUB_REPO = 'Whbgz/AIPolish';
    const GITHUB_REPO_URL = 'https://github.com/Whbgz/AIPolish';

    public static function activate()
    {
        Helper::addAction('ai-polish', 'AIPolish_Action');
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array('AIPolish_Plugin', 'renderJs');
        Typecho_Plugin::factory('admin/write-page.php')->bottom = array('AIPolish_Plugin', 'renderJs');
    }

    public static function deactivate()
    {
        Helper::removeAction('ai-polish');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $apiUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'apiUrl', NULL, 'https://api.openai.com/v1/chat/completions',
            _t('接口地址 (API URL)'),
            _t('兼容 OpenAI 格式的接口地址。例如：https://api.deepseek.com/chat/completions')
        );
        $form->addInput($apiUrl);

        $apiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'apiKey', NULL, '',
            _t('API Key'),
            _t('填写你在平台申请的 API Key')
        );
        $form->addInput($apiKey);

        $modelName = new Typecho_Widget_Helper_Form_Element_Text(
            'modelName', NULL, 'deepseek-chat',
            _t('模型名称 (Model Name)'),
            _t('填入使用的模型名称，例如 deepseek-chat, gpt-4o 等')
        );
        $form->addInput($modelName);

        $imageVision = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'imageVision',
            array('1' => _t('启用图片识别上下文')),
            array(),
            _t('图片识别'),
            _t('开启后，润色正文、标题润色和一键起标题时，插件会按文章中图片出现的顺序把图片作为上下文发送给支持视觉输入的模型。测试图片输入会使用插件自带示例图。')
        );
        $form->addInput($imageVision);

        // 测试连接按钮
        $actionUrl = Typecho_Common::url('action/ai-polish', Helper::options()->index);
        $pluginUrl = Helper::options()->pluginUrl . '/AIPolish';
        $testImageUrl = $pluginUrl . '/img/test_image.png';
        $actionUrlJs = json_encode($actionUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $versionConfigJs = json_encode(array(
            'currentVersion' => self::VERSION,
            'repo' => self::GITHUB_REPO,
            'repoUrl' => self::GITHUB_REPO_URL
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $testImageUrlHtml = htmlspecialchars($testImageUrl, ENT_QUOTES, 'UTF-8');
        $versionCheckScriptUrl = htmlspecialchars($pluginUrl . '/js/version-check.js?v=' . rawurlencode(self::VERSION), ENT_QUOTES, 'UTF-8');
        $testHtml = '<div style="margin: 15px 0;">'
            . '<div style="margin-bottom:8px;">'
            . '<button type="button" id="ai-polish-test-btn" class="btn primary" style="margin-right:8px;">测试连接</button>'
            . '<button type="button" id="ai-polish-image-test-btn" class="btn primary" style="margin-right:8px;">测试图片输入</button>'
            . '<span id="ai-polish-test-result" style="font-size:13px;"></span>'
            . '</div>'
            . '<div style="max-width:560px;padding:10px 12px;border:1px solid #e5e5e5;border-radius:4px;background:#fafafa;">'
            . '<div style="font-size:13px;color:#666;margin-bottom:8px;">测试图片：Google世界杯特色Logo图片</div>'
            . '<img src="' . $testImageUrlHtml . '" alt="Google世界杯特色Logo图片" style="display:block;max-width:220px;width:100%;height:auto;border:1px solid #ddd;background:#fff;margin-bottom:8px;" />'
            . '<div id="ai-polish-image-test-state" style="font-size:13px;margin-bottom:4px;"></div>'
            . '<div id="ai-polish-image-test-detail" style="font-size:13px;color:#666;line-height:1.6;"></div>'
            . '</div>'
            . '<script>(function(){'
            . 'var actionUrl = ' . $actionUrlJs . ';'
            . 'var connBtn = document.getElementById("ai-polish-test-btn");'
            . 'var imageBtn = document.getElementById("ai-polish-image-test-btn");'
            . 'var result = document.getElementById("ai-polish-test-result");'
            . 'var imageState = document.getElementById("ai-polish-image-test-state");'
            . 'var imageDetail = document.getElementById("ai-polish-image-test-detail");'
            . 'function getInputValue(name){'
            . '  var el = document.querySelector("input[name=" + name + "]");'
            . '  return el ? el.value : "";'
            . '}'
            . 'function readConfig(){'
            . '  return {'
            . '    apiUrl: getInputValue("apiUrl"),'
            . '    apiKey: getInputValue("apiKey"),'
            . '    modelName: getInputValue("modelName")'
            . '  };'
            . '}'
            . 'function postTest(type){'
            . '  var cfg = readConfig();'
            . '  if(!cfg.apiUrl || !cfg.apiKey || !cfg.modelName){'
            . '    return Promise.resolve({status:"error", msg:"请先填写上方三个配置项"});'
            . '  }'
            . '  return fetch(actionUrl,{'
            . '    method:"POST",'
            . '    headers:{"Content-Type":"application/x-www-form-urlencoded"},'
            . '    body:new URLSearchParams({type:type,apiUrl:cfg.apiUrl,apiKey:cfg.apiKey,modelName:cfg.modelName})'
            . '  }).then(function(r){ return r.json(); });'
            . '}'
            . 'if(connBtn){ connBtn.addEventListener("click", function(){'
            . '  var btn = this;'
            . '  btn.disabled = true;'
            . '  result.style.color = "#666";'
            . '  result.textContent = "正在测试...";'
            . '  postTest("test").then(function(res){'
            . '    result.style.color = res.status === "success" ? "#090" : "#c00";'
            . '    result.textContent = res.msg || "未知结果";'
            . '  }).catch(function(e){'
            . '    result.style.color = "#c00";'
            . '    result.textContent = "网络错误: " + e.message;'
            . '  }).finally(function(){ btn.disabled = false; });'
            . '}); }'
            . 'if(imageBtn){ imageBtn.addEventListener("click", function(){'
            . '  var btn = this;'
            . '  btn.disabled = true;'
            . '  result.textContent = "";'
            . '  imageState.style.color = "#666";'
            . '  imageState.textContent = "正在测试图片输入...";'
            . '  imageDetail.style.color = "#666";'
            . '  imageDetail.textContent = "";'
            . '  postTest("test_image").then(function(res){'
            . '    if(res.status === "success"){'
            . '      imageState.style.color = res.supported ? "#090" : "#c60";'
            . '      imageState.textContent = res.msg || (res.supported ? "支持图片输入" : "不支持图片输入");'
            . '      imageDetail.style.color = res.supported ? "#090" : "#666";'
            . '      imageDetail.textContent = "模型识别描述：" + (res.visionMsg || "无");'
            . '    } else {'
            . '      imageState.style.color = "#c00";'
            . '      imageState.textContent = res.msg || "测试失败";'
            . '      imageDetail.textContent = "";'
            . '    }'
            . '  }).catch(function(e){'
            . '    imageState.style.color = "#c00";'
            . '    imageState.textContent = "网络错误: " + e.message;'
            . '    imageDetail.textContent = "";'
            . '  }).finally(function(){ btn.disabled = false; });'
            . '}); }'
            . '})();</script>'
            . '</div>';

        $testArea = new Typecho_Widget_Helper_Layout('div', array('style' => 'margin: 15px 0;'));
        $testArea->html($testHtml);
        $form->addItem($testArea);

        $versionHtml = '<div id="ai-polish-version-card" style="max-width:560px;margin:15px 0;padding:10px 12px;border:1px solid #e5e5e5;border-radius:4px;background:#fafafa;">'
            . '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">'
            . '<strong style="font-size:14px;">版本检测</strong>'
            . '<button type="button" id="ai-polish-version-retry" class="btn btn-s">重新检测</button>'
            . '</div>'
            . '<div id="ai-polish-version-state" style="font-size:13px;color:#666;margin-top:8px;">正在检测新版本...</div>'
            . '<div id="ai-polish-version-detail" style="font-size:13px;color:#666;line-height:1.6;margin-top:4px;"></div>'
            . '<div id="ai-polish-version-action" style="font-size:13px;margin-top:6px;"></div>'
            . '<script>window.AIPolishVersionCheck = ' . $versionConfigJs . ';</script>'
            . '<script src="' . $versionCheckScriptUrl . '"></script>'
            . '</div>';

        $versionArea = new Typecho_Widget_Helper_Layout('div', array('style' => 'margin: 15px 0;'));
        $versionArea->html($versionHtml);
        $form->addItem($versionArea);

        $defaultPrompt = <<<'PROMPT'
你是一个专业的文字编辑，请帮我对以下文本做温和润色：只润色，不重写，保留作者本人的语气。
【润色原则】：
1. 严格保留作者本人的语气、口语感、个人风格，不要改成"标准书面语"或官方通稿。
2. 严禁压缩、删减、概括或结构化整理（绝对不能把散文式叙述改成事实清单或要点列表）。
3. 输出长度必须与原文相当，不允许大幅缩短或大幅扩写。
4. 保留 90% 以上的原文表达，只调整：
   - 个别生硬拗口的句子让其更通顺
   - 个别不准确的用词或重复用词
   - 个别标点符号
   禁止整段重写或大段重组。
【绝对指令】：
1. 直接输出润色后的文本，不要包含任何解释、说明或问候。
2. 不要以"好的"、"当然"、"以下是"等语气词开头。
3. 绝对不可使用 markdown 代码块（```）包裹整个文本。
4. 保持原文的段落结构和换行方式不变。
5. 必须原封不动地保留所有 Markdown 格式标记，包括但不限于：
   - 图片引用：![image.png][4] 以及底部的 [1]: https://... 格式
   - 加粗 **文字**、斜体 *文字*
   - 标题 ##、列表 -、引用 > 等
   绝对不要修改、删除或重新排列这些标记。
6. 严禁使用"值得一提的是"、"总的来说"、"不仅...还..."、"首先...其次...最后"等模板化套话。
7. 【图片点评】文中会包含 Markdown 图片标记（如 ![说明][1]），你能真实看到这些图片的内容：
   - 可以在合适的图片附近融入一两句简短感慨或点评，写进作者的叙述流里，不要单独成段、不要加"图示如下"之类引导语。
   - 必须克制：全文最多点评 2~3 张图，每张点评不超过 1~2 句。
   - 点评语气必须和作者本人一致（口语化、个人感、有温度），不要写成解说词、新闻稿或导游词。
   - 不要复述"图片中显示……"这种描述可见内容的话，要写感受、联想、吐槽、回忆。
   - 没什么可说的图就完全不点评，宁缺毋滥。
PROMPT;

        $prompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'prompt', NULL, $defaultPrompt,
            _t('系统提示词 (System Prompt)'),
            _t('发送给 AI 的系统提示词。插件会自动读取你的历史文章供 AI 学习文风，无需在此配置。')
        );
        $form->addInput($prompt);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    public static function renderJs()
    {
        $options = Helper::options();
        $pluginUrl = $options->pluginUrl . '/AIPolish';
        $actionUrl = Typecho_Common::url('action/ai-polish', $options->index);
        $pluginOpts = $options->plugin('AIPolish');
        $imageVisionValue = isset($pluginOpts->imageVision) ? $pluginOpts->imageVision : null;
        $imageVisionEnabled = false;
        if (is_array($imageVisionValue)) {
            $imageVisionEnabled = in_array('1', $imageVisionValue, true) || in_array(1, $imageVisionValue, true);
        } else {
            $imageVisionEnabled = $imageVisionValue === '1' || $imageVisionValue === 1 || $imageVisionValue === true || $imageVisionValue === 'on';
        }

        echo '<script>
            window.AIPolishConfig = {
                actionUrl: "' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '",
                imageVision: ' . ($imageVisionEnabled ? 'true' : 'false') . '
            };
        </script>';
        echo '<script src="' . htmlspecialchars($pluginUrl, ENT_QUOTES, 'UTF-8') . '/js/polish.js?v=' . rawurlencode(self::VERSION) . '"></script>';
        
        echo '<style>
            .ai-polish-toolbar {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                margin-left: 10px;
                vertical-align: middle;
            }
            .ai-polish-submit-toolbar {
                margin-left: 0;
                margin-right: 8px;
            }
            .ai-polish-title-bar {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                margin-left: 12px;
                vertical-align: middle;
                font-size: 13px;
                font-weight: normal;
            }
            /* 让 h2 内的按钮跟随标题文字流，紧贴文字末尾 */
            .typecho-page-title {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 6px;
            }
            /* 小屏幕下按钮换行到下一行，不挤压标题 */
            @media (max-width: 768px) {
                .typecho-page-title { flex-direction: column; align-items: flex-start; }
                .ai-polish-title-bar { margin-left: 0; margin-top: 6px; }
            }
            .ai-polish-undo-btn {
                color: #c00;
            }
            .wmd-button-row { display: flex; align-items: center; flex-wrap: wrap; }
            @keyframes ai-polish-pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }
            .ai-polish-loading {
                animation: ai-polish-pulse 1.5s ease-in-out infinite;
            }
        </style>';
    }
}
