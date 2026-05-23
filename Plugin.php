<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * AI一键润色插件，支持润色标题和正文，保留Markdown格式，流式响应
 * 
 * @package AIPolish 
 * @author Whbgz
 * @version 1.1.0
 * @link https://www.baiboke.cn/
 */
class AIPolish_Plugin implements Typecho_Plugin_Interface
{
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

        // 测试连接按钮
        $actionUrl = Typecho_Common::url('action/ai-polish', Helper::options()->index);
        $testHtml = '<button type="button" id="ai-polish-test-btn" class="btn primary" style="margin-right:10px;">测试连接</button>'
            . '<span id="ai-polish-test-result" style="font-size:13px;"></span>'
            . '<script>'
            . 'document.getElementById("ai-polish-test-btn").addEventListener("click", function(){'
            . '  var btn = this;'
            . '  var result = document.getElementById("ai-polish-test-result");'
            . '  var apiUrl = document.querySelector("input[name=apiUrl]").value;'
            . '  var apiKey = document.querySelector("input[name=apiKey]").value;'
            . '  var modelName = document.querySelector("input[name=modelName]").value;'
            . '  if(!apiUrl || !apiKey || !modelName){ result.style.color="#c00"; result.textContent="请先填写上方三个配置项"; return; }'
            . '  btn.disabled=true; result.style.color="#666"; result.textContent="正在测试...";'
            . '  fetch("' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '",{'
            . '    method:"POST",'
            . '    headers:{"Content-Type":"application/x-www-form-urlencoded"},'
            . '    body:new URLSearchParams({type:"test",apiUrl:apiUrl,apiKey:apiKey,modelName:modelName})'
            . '  })'
            . '  .then(function(r){return r.json();})'
            . '  .then(function(res){'
            . '    if(res.status==="success"){ result.style.color="#090"; result.textContent=res.msg; }'
            . '    else{ result.style.color="#c00"; result.textContent=res.msg; }'
            . '  })'
            . '  .catch(function(e){ result.style.color="#c00"; result.textContent="网络错误: "+e.message; })'
            . '  .finally(function(){ btn.disabled=false; });'
            . '});'
            . '</script>';

        $testArea = new Typecho_Widget_Helper_Layout('div', array('style' => 'margin: 15px 0;'));
        $testArea->html($testHtml);
        $form->addItem($testArea);

        $defaultPrompt = <<<'PROMPT'
你是一个专业的文字编辑。请帮我润色以下文本，使其表达更自然流畅，同时保持原文的意思不变。
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
6. 【文风要求】润色后的文字必须像真人写的博客一样自然，严禁AI味：
   - 禁止使用"值得一提的是"、"总的来说"、"不仅...还..."、"首先...其次...最后"等模板化套话
   - 不要把简单的话说复杂，保持口语化和个人感
   - 保持博客随笔的亲切轻松感，不要写成官方通稿或营销文案
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

        echo '<script>
            window.AIPolishConfig = {
                actionUrl: "' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '"
            };
        </script>';
        echo '<script src="' . htmlspecialchars($pluginUrl, ENT_QUOTES, 'UTF-8') . '/js/polish.js?v=1.1.0"></script>';
        
        echo '<style>
            .ai-polish-toolbar {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                margin-left: 10px;
                vertical-align: middle;
            }
            .ai-polish-title-bar {
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
                display: flex;
                align-items: center;
                gap: 5px;
                z-index: 10;
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
