<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class AIPolish_Action extends Typecho_Widget implements Widget_Interface_Do
{
    /**
     * 获取当前用户最近发布的文章片段，用作文风参考
     */
    private function getStyleSamples($authorId, $limit = 3, $excerpt = 300)
    {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll(
            $db->select('title', 'text')
                ->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->where('authorId = ?', $authorId)
                ->order('created', Typecho_Db::SORT_DESC)
                ->limit($limit)
        );

        if (empty($rows)) {
            return '';
        }

        $samples = '';
        foreach ($rows as $i => $row) {
            $num = $i + 1;
            $title = $row['title'];
            $body = mb_substr(strip_tags($row['text']), 0, $excerpt, 'UTF-8');
            $samples .= "【样本{$num}】标题：{$title}\n{$body}\n\n";
        }

        return $samples;
    }

    /**
     * 初始化 SSE 流式响应
     */
    private function startSSE()
    {
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', false);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    /**
     * 发送一条 SSE 事件
     */
    private function pushSSE($event, $data)
    {
        echo "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    public function action()
    {
        // ---- 鉴权 ----
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->hasLogin() || !$user->pass('editor', true)) {
            $this->response->throwJson(array('status' => 'error', 'msg' => '权限不足'));
        }
        if (!$this->request->isPost()) {
            $this->response->throwJson(array('status' => 'error', 'msg' => '非法请求'));
        }

        // ---- 参数 ----
        $type = $this->request->get('type', 'content');

        // ---- 测试端点 ----
        if ($type === 'test') {
            $testUrl   = trim($this->request->get('apiUrl', ''));
            $testKey   = trim($this->request->get('apiKey', ''));
            $testModel = trim($this->request->get('modelName', ''));

            if (empty($testUrl) || empty($testKey) || empty($testModel)) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '请填写完整的接口地址、API Key 和模型名称'));
            }

            $data = array(
                'model'      => $testModel,
                'messages'   => array(array('role' => 'user', 'content' => '你好')),
                'max_tokens' => 10
            );

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $testUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $testKey
            ));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $result   = curl_exec($ch);
            $error    = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($error) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '网络连接失败: ' . $error));
            }

            $resJson = json_decode($result, true);
            if (!$resJson) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '返回数据无法解析 (HTTP ' . $httpCode . ')，请检查接口地址是否正确'));
            }

            if (isset($resJson['choices'][0]['message']['content'])) {
                $reply = $resJson['choices'][0]['message']['content'];
                $this->response->throwJson(array(
                    'status' => 'success',
                    'msg'    => '连接成功！模型 ' . $testModel . ' 回复: ' . mb_substr($reply, 0, 50, 'UTF-8')
                ));
            } else {
                $errorMsg = isset($resJson['error']['message']) ? $resJson['error']['message'] : json_encode($resJson, JSON_UNESCAPED_UNICODE);
                $this->response->throwJson(array('status' => 'error', 'msg' => 'API 返回错误: ' . $errorMsg));
            }
        }

        // ---- 正式请求参数 ----
        $text    = trim($this->request->get('text', ''));
        $context = trim($this->request->get('context', ''));

        if ($type === 'content' && empty($text)) {
            $this->response->throwJson(array('status' => 'error', 'msg' => '润色内容不能为空'));
        }
        if ($type === 'title' && empty($text)) {
            $this->response->throwJson(array('status' => 'error', 'msg' => '标题不能为空'));
        }
        if ($type === 'generate_title' && empty($context)) {
            $this->response->throwJson(array('status' => 'error', 'msg' => '文章正文为空，无法生成标题'));
        }

        // ---- 配置 ----
        $options    = Helper::options();
        $pluginOpts = $options->plugin('AIPolish');
        $apiUrl     = $pluginOpts->apiUrl;
        $apiKey     = $pluginOpts->apiKey;
        $modelName  = $pluginOpts->modelName;
        $prompt     = $pluginOpts->prompt;

        if (empty($apiUrl) || empty($apiKey)) {
            $this->response->throwJson(array('status' => 'error', 'msg' => '请先在后台配置接口地址和 API Key'));
        }

        // ---- 文风参考 ----
        $styleSamples = $this->getStyleSamples($user->uid);
        $styleRef = '';
        if (!empty($styleSamples)) {
            $styleRef = "\n\n【作者的历史文章文风参考（请模仿这种真实的个人写作风格，不要输出这些样本内容）】：\n" . $styleSamples;
        }

        // ---- 构建提示词 ----
        $systemPrompt = $prompt . $styleRef;
        $userMessage  = $text;

        if ($type === 'title') {
            $systemPrompt = "你是一个专业的文章标题润色助手。请在保持原意不变的前提下，让标题更简洁有力、更有吸引力。"
                . "\n【绝对指令】："
                . "\n1. 直接输出润色后的标题，不要包含任何解释、说明或引号。"
                . "\n2. 不要以\"好的\"、\"当然\"、\"以下是\"等语气词开头。"
                . "\n3. 不要使用 markdown 代码块包裹。"
                . "\n4. 不要过度标题党，保持标题与内容相符。"
                . "\n5. 标题要像真人博主自然起的，不要有AI生成的套路感。";
            if (!empty($context)) {
                $systemPrompt .= "\n\n以下是文章正文概要，供你理解语境（不要输出这段内容）：\n"
                    . mb_substr(strip_tags($context), 0, 1000, 'UTF-8');
            }
            $systemPrompt .= $styleRef;

        } elseif ($type === 'generate_title') {
            $systemPrompt = "你是一个专业的文章标题创作助手。请根据用户提供的文章内容，起一个简洁、准确、有吸引力的中文标题。"
                . "\n【绝对指令】："
                . "\n1. 直接输出标题，不要包含任何解释、说明或引号。"
                . "\n2. 不要以\"好的\"、\"当然\"、\"以下是\"等语气词开头。"
                . "\n3. 不要使用 markdown 代码块包裹。"
                . "\n4. 标题控制在 5~25 个字之间，符合个人博客的自然风格，不要过度标题党。"
                . "\n5. 标题要像真人博主自然起的，不要有AI生成的套路感。";
            $systemPrompt .= $styleRef;
            $userMessage = "请根据以下信息给这篇文章起一个合适的标题：\n";
            if (!empty($text)) {
                $userMessage .= "当前标题是：\"" . $text . "\"（仅供参考，你可以完全重新起）\n\n";
            }
            $userMessage .= "文章内容如下：\n" . mb_substr(strip_tags($context), 0, 2000, 'UTF-8');

        } else {
            // 润色正文
            if (!empty($context)) {
                $systemPrompt .= "\n\n本文标题为：【" . $context . "】，请结合标题的主题和语境来润色正文，确保润色后的文风与标题一致。";
            }
        }

        // ---- 开始 SSE 流 ----
        $this->startSSE();

        $data = array(
            'model'       => $modelName,
            'messages'    => array(
                array('role' => 'system', 'content' => $systemPrompt),
                array('role' => 'user',   'content' => $userMessage)
            ),
            'temperature' => 0.7,
            'stream'      => true
        );

        $sseBuffer  = '';
        $isApiError = false;
        $errorBody  = '';
        $self       = $this;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        // 流式回调：逐块解析 OpenAI SSE 并转发给浏览器
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($self, &$sseBuffer, &$isApiError, &$errorBody) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // 非 200 响应，收集错误内容
            if ($httpCode !== 200) {
                $isApiError = true;
                $errorBody .= $chunk;
                return strlen($chunk);
            }

            $sseBuffer .= $chunk;

            // 逐行解析 OpenAI 的 SSE 格式
            while (($pos = strpos($sseBuffer, "\n")) !== false) {
                $line = substr($sseBuffer, 0, $pos);
                $sseBuffer = substr($sseBuffer, $pos + 1);
                $line = trim($line);

                if (empty($line)) continue;

                if (strpos($line, 'data: ') === 0) {
                    $jsonStr = trim(substr($line, 6));

                    if ($jsonStr === '[DONE]') {
                        $self->pushSSE('done', array());
                        return strlen($chunk);
                    }

                    $obj = json_decode($jsonStr, true);
                    if (isset($obj['choices'][0]['delta']['content'])) {
                        $self->pushSSE('token', array(
                            'content' => $obj['choices'][0]['delta']['content']
                        ));
                    }
                }
            }

            return strlen($chunk);
        });

        curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 错误处理
        if ($curlError) {
            $this->pushSSE('error', array('msg' => '请求 AI 接口失败: ' . $curlError));
        } elseif ($isApiError) {
            $resJson = json_decode($errorBody, true);
            $msg = isset($resJson['error']['message']) ? $resJson['error']['message'] : '未知错误 (原始响应: ' . mb_substr($errorBody, 0, 200, 'UTF-8') . ')';
            $this->pushSSE('error', array('msg' => 'AI 返回错误: ' . $msg));
        }

        exit;
    }
}
