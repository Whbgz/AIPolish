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
        @set_time_limit(0);
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', false);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
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

    /**
     * 统一读取 POST 参数，避免 PHP 8 下数组参数触发 trim TypeError。
     */
    private function getRequestString($name, $default = '')
    {
        $value = $this->request->get($name, $default);
        if (is_array($value) || is_object($value)) {
            return $default;
        }

        return trim((string) $value);
    }

    private function getRequestInt($name, $default = 0)
    {
        $value = $this->request->get($name, $default);
        if (is_array($value) || is_object($value) || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    private function applyCurlSecurityDefaults($ch, $allowRedirects = false)
    {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        if ($allowRedirects && defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
    }

    private function fetchContentRow($cid)
    {
        if ($cid <= 0) {
            return null;
        }

        $db = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select('cid', 'title', 'text', 'type', 'status', 'parent', 'authorId', 'modified')
                ->from('table.contents')
                ->where('cid = ?', $cid)
                ->limit(1)
        );

        return empty($row) ? null : $row;
    }

    private function isEditableContentType($type)
    {
        return in_array($type, array('post', 'post_draft', 'page', 'page_draft', 'revision'), true);
    }

    /**
     * 发送非流式 OpenAI 兼容请求，用于配置页测试。
     */
    private function sendChatRequest($apiUrl, $apiKey, $data, $timeout = 30)
    {
        $ch = curl_init();
        if ($ch === false) {
            return array(
                'body'     => '',
                'error'    => '无法初始化 cURL，请确认 PHP curl 扩展已启用',
                'httpCode' => 0,
                'json'     => null
            );
        }
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $this->applyCurlSecurityDefaults($ch);

        $body     = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array(
            'body'     => $body,
            'error'    => $error,
            'httpCode' => $httpCode,
            'json'     => json_decode($body, true)
        );
    }

    private function getChoiceContent($resJson)
    {
        if (isset($resJson['choices'][0]['message']['content'])) {
            return $resJson['choices'][0]['message']['content'];
        }

        return null;
    }

    private function isImageVisionEnabled($value)
    {
        if (is_array($value)) {
            return in_array('1', $value, true) || in_array(1, $value, true);
        }

        return $value === '1' || $value === 1 || $value === true || $value === 'on';
    }

    private function encodeLocalTestImage()
    {
        $path = dirname(__FILE__) . '/img/test_image.png';
        if (!is_readable($path)) {
            return '';
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($content);
    }

    private function buildVisionTestPrompt()
    {
        return "你正在执行 AIPolish 插件的图片输入能力检测。\n"
            . "你必须只返回严格 JSON，格式如下：{\"support\":true,\"msg\":\"你实际看到的图片内容\"}\n"
            . "判断规则：\n"
            . "1. 只有当你确实能看到随附图片内容时，support 才能为 true。\n"
            . "2. 如果你看不到图片、图片未传入、只看到文件名/URL/base64/文字提示，或不确定图片内容，必须返回 {\"support\":false,\"msg\":\"无法确认看到图片内容\"}。\n"
            . "3. msg 只描述你实际看见的画面，不要猜测，不要为了迎合测试返回 true。\n"
            . "4. 不要输出 Markdown，不要输出代码块，不要输出 JSON 以外的任何内容。";
    }

    private function normalizeVisionBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        $value = strtolower(trim((string) $value));
        return in_array($value, array('true', 'yes', '支持', '可以', '1'), true);
    }

    private function parseVisionTestReply($reply)
    {
        $clean = trim($reply);
        if (preg_match('/\x60\x60\x60(?:json)?\s*(.*?)\x60\x60\x60/is', $clean, $matches)) {
            $clean = trim($matches[1]);
        }

        $obj = json_decode($clean, true);
        if (!is_array($obj) && preg_match('/\{.*\}/s', $clean, $matches)) {
            $obj = json_decode($matches[0], true);
        }

        if (is_bool($obj)) {
            return array(
                'supported' => $obj,
                'msg'       => $obj ? '模型返回支持图片输入，但没有给出图片描述。' : '模型返回不支持图片输入。'
            );
        }

        if (!is_array($obj)) {
            return array(
                'supported' => false,
                'msg'       => '模型没有按检测规范返回 JSON：' . mb_substr($reply, 0, 200, 'UTF-8')
            );
        }

        $supportValue = false;
        foreach (array('support', 'supported', 'can_see_image', 'vision', 'has_image') as $key) {
            if (array_key_exists($key, $obj)) {
                $supportValue = $obj[$key];
                break;
            }
        }

        $msg = isset($obj['msg']) ? trim((string) $obj['msg']) : '';
        if ($msg === '') {
            $msg = $this->normalizeVisionBool($supportValue)
                ? '模型返回支持图片输入，但没有给出图片描述。'
                : '模型返回不支持图片输入。';
        }

        $supported = $this->normalizeVisionBool($supportValue);
        if ($supported && preg_match('/(无法|不能|看不到|未看到|没有看到|不确定|无法确认|只看到|文件名|URL|base64)/iu', $msg)) {
            $supported = false;
        }

        return array(
            'supported' => $supported,
            'msg'       => mb_substr($msg, 0, 500, 'UTF-8')
        );
    }

    private function getErrorMessageFromResponse($resJson, $body)
    {
        if (isset($resJson['error']['message'])) {
            return $resJson['error']['message'];
        }

        if (is_array($resJson)) {
            return json_encode($resJson, JSON_UNESCAPED_UNICODE);
        }

        return mb_substr((string) $body, 0, 200, 'UTF-8');
    }

    private function collectReferenceDefinitions($text)
    {
        $defs = array();
        if (preg_match_all('/^[ \t]*\[([^\]]+)\]:[ \t]*(\S+)(?:[ \t]+(?:["\'][^"\']*["\']|\([^)]+\)))?[ \t]*$/m', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $defs[strtolower(trim($match[1]))] = $this->cleanImageUrl($match[2]);
            }
        }

        return $defs;
    }

    private function cleanImageUrl($url)
    {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES, 'UTF-8');
        return trim($url, " \t\n\r\0\x0B<>\"'");
    }

    private function resolveImageUrl($url)
    {
        $url = $this->cleanImageUrl($url);
        if ($url === '') {
            return '';
        }

        if (stripos($url, 'data:image/') === 0) {
            return strlen($url) <= 1024 * 1024 ? $url : '';
        }

        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        if (strpos($url, '//') === 0) {
            $siteUrl = Helper::options()->siteUrl;
            $scheme = parse_url($siteUrl, PHP_URL_SCHEME);
            return ($scheme ? $scheme : 'https') . ':' . $url;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return '';
        }

        $siteUrl = rtrim(Helper::options()->siteUrl, '/') . '/';
        if ($url[0] === '/') {
            $parts = parse_url($siteUrl);
            if (empty($parts['scheme']) || empty($parts['host'])) {
                return '';
            }
            $host = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $host .= ':' . $parts['port'];
            }
            return $host . $url;
        }

        return $siteUrl . ltrim($url, '/');
    }

    private function extractAltFromHtml($html)
    {
        if (preg_match('/\balt\s*=\s*(["\'])(.*?)\1/iu', $html, $match)) {
            return html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
        }

        return '';
    }

    private function extractArticleImages($text, $max = 8)
    {
        if (trim($text) === '') {
            return array();
        }

        $defs = $this->collectReferenceDefinitions($text);
        $found = array();

        if (preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+["\'][^)]*["\'])?\)/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $found[] = array(
                    'offset' => $match[0][1],
                    'url'    => $match[2][0],
                    'alt'    => $match[1][0],
                    'raw'    => $match[0][0]
                );
            }
        }

        if (preg_match_all('/!\[([^\]]*)\]\[([^\]]*)\]/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $alt = $match[1][0];
                $ref = trim($match[2][0]) !== '' ? trim($match[2][0]) : $alt;
                $key = strtolower(trim($ref));
                if (isset($defs[$key])) {
                    $found[] = array(
                        'offset' => $match[0][1],
                        'url'    => $defs[$key],
                        'alt'    => $alt,
                        'raw'    => $match[0][0]
                    );
                }
            }
        }

        if (preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>/iu', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $found[] = array(
                    'offset' => $match[0][1],
                    'url'    => $match[2][0],
                    'alt'    => $this->extractAltFromHtml($match[0][0]),
                    'raw'    => $match[0][0]
                );
            }
        }

        usort($found, function ($a, $b) {
            if ($a['offset'] == $b['offset']) {
                return 0;
            }
            return $a['offset'] < $b['offset'] ? -1 : 1;
        });

        $images = array();
        $seen = array();
        foreach ($found as $image) {
            $resolvedUrl = $this->resolveImageUrl($image['url']);
            if ($resolvedUrl === '') {
                continue;
            }

            $dedupeKey = strtolower($resolvedUrl);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $images[] = array(
                'url' => $resolvedUrl,
                'alt' => mb_substr(trim($image['alt']), 0, 120, 'UTF-8'),
                'raw' => mb_substr(trim($image['raw']), 0, 200, 'UTF-8')
            );

            if (count($images) >= $max) {
                break;
            }
        }

        return $images;
    }

    /**
     * 下载图片并转为 base64 data URI。
     * 许多 OpenAI 兼容中转站不支持 URL 形式的 image_url（转发给 Anthropic 时
     * 要求图片必须是 base64），这里统一下载转 data URI 再发给 AI。
     */
    private function fetchImageAsDataUrl($url, $maxSize = 2097152)
    {
        if (stripos($url, 'data:image/') === 0) {
            return $url;
        }
        if (!preg_match('/^https?:\/\//i', $url)) {
            return '';
        }
        $ch = curl_init();
        if ($ch === false) {
            return '';
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxSize);
        $this->applyCurlSecurityDefaults($ch, true);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error || $httpCode !== 200 || empty($body)) {
            return '';
        }
        $mime = '';
        if (stripos($contentType, 'image/jpeg') !== false || stripos($contentType, 'image/jpg') !== false) {
            $mime = 'image/jpeg';
        } elseif (stripos($contentType, 'image/png') !== false) {
            $mime = 'image/png';
        } elseif (stripos($contentType, 'image/gif') !== false) {
            $mime = 'image/gif';
        } elseif (stripos($contentType, 'image/webp') !== false) {
            $mime = 'image/webp';
        } else {
            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $map = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp');
            $mime = isset($map[$ext]) ? $map[$ext] : 'image/jpeg';
        }
        return 'data:' . $mime . ';base64,' . base64_encode($body);
    }

    private function buildUserContentWithImages($text, $images, $progressCallback = null)
    {
        if (empty($images)) {
            return $text;
        }

        $content = array(array(
            'type' => 'text',
            'text' => $text . "\n\n【图片上下文】以下图片已按它们在文章中出现的先后顺序附上，每张图标注了在原文中对应的 Markdown 标记。你可以基于实际看到的图片内容，在文中对应图片附近融入简短感慨或点评（按系统提示词的图片点评要求执行：克制、口语化、和作者语气一致）。"
        ));

        $total = count($images);
        foreach ($images as $index => $image) {
            $label = '【文章图片' . ($index + 1) . '】';
            if (!empty($image['raw'])) {
                $label .= '对应文中标记：' . $image['raw'];
            }
            if ($image['alt'] !== '') {
                $label .= ' | Alt 文本：' . $image['alt'];
            }

            $content[] = array('type' => 'text', 'text' => $label);

            // 下载图片转 base64 data URI，确保所有中转站都能识别
            $dataUrl = $this->fetchImageAsDataUrl($image['url']);
            if ($dataUrl === '') {
                // 下载失败时退回 URL 形式
                $dataUrl = $image['url'];
            }

            $content[] = array(
                'type' => 'image_url',
                'image_url' => array(
                    'url'    => $dataUrl,
                    'detail' => 'auto'
                )
            );

            // 进度回调（用于 SSE 保活）
            if ($progressCallback !== null) {
                $progressCallback($index + 1, $total);
            }
        }

        return $content;
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
        $type = $this->getRequestString('type', 'content');
        if (!in_array($type, array('content', 'title', 'generate_title', 'test', 'test_image'), true)) {
            $this->response->throwJson(array('status' => 'error', 'msg' => '未知操作类型'));
        }

        // ---- 测试端点 ----
        if ($type === 'test') {
            $testUrl   = $this->getRequestString('apiUrl');
            $testKey   = $this->getRequestString('apiKey');
            $testModel = $this->getRequestString('modelName');

            if (empty($testUrl) || empty($testKey) || empty($testModel)) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '请填写完整的接口地址、API Key 和模型名称'));
            }

            $data = array(
                'model'      => $testModel,
                'messages'   => array(array('role' => 'user', 'content' => '你好')),
                'max_tokens' => 10
            );

            $result = $this->sendChatRequest($testUrl, $testKey, $data, 15);
            if ($result['error']) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '网络连接失败: ' . $result['error']));
            }

            $resJson = $result['json'];
            if (!$resJson) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '返回数据无法解析 (HTTP ' . $result['httpCode'] . ')，请检查接口地址是否正确'));
            }

            $reply = $this->getChoiceContent($resJson);
            if ($reply !== null) {
                $this->response->throwJson(array(
                    'status' => 'success',
                    'msg'    => '连接成功！模型 ' . $testModel . ' 回复: ' . mb_substr($reply, 0, 50, 'UTF-8')
                ));
            }

            $errorMsg = $this->getErrorMessageFromResponse($resJson, $result['body']);
            $this->response->throwJson(array('status' => 'error', 'msg' => 'API 返回错误: ' . $errorMsg));
        }

        if ($type === 'test_image') {
            $testUrl   = $this->getRequestString('apiUrl');
            $testKey   = $this->getRequestString('apiKey');
            $testModel = $this->getRequestString('modelName');

            if (empty($testUrl) || empty($testKey) || empty($testModel)) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '请填写完整的接口地址、API Key 和模型名称'));
            }

            $imageDataUrl = $this->encodeLocalTestImage();
            if ($imageDataUrl === '') {
                $this->response->throwJson(array('status' => 'error', 'msg' => '测试图片不存在或无法读取：img/test_image.png'));
            }

            $data = array(
                'model'    => $testModel,
                'messages' => array(
                    array('role' => 'system', 'content' => $this->buildVisionTestPrompt()),
                    array(
                        'role' => 'user',
                        'content' => array(
                            array('type' => 'text', 'text' => '请检测你是否能实际看到下面这张图片。只返回规定 JSON。'),
                            array(
                                'type' => 'image_url',
                                'image_url' => array(
                                    'url'    => $imageDataUrl,
                                    'detail' => 'auto'
                                )
                            )
                        )
                    )
                ),
                'temperature' => 0,
                'max_tokens'  => 300
            );

            $result = $this->sendChatRequest($testUrl, $testKey, $data, 45);
            if ($result['error']) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '网络连接失败: ' . $result['error']));
            }

            $resJson = $result['json'];
            if (!$resJson) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '返回数据无法解析 (HTTP ' . $result['httpCode'] . ')，请检查接口是否兼容图片输入'));
            }

            $reply = $this->getChoiceContent($resJson);
            if ($reply === null) {
                $errorMsg = $this->getErrorMessageFromResponse($resJson, $result['body']);
                $this->response->throwJson(array('status' => 'error', 'msg' => 'API 返回错误: ' . $errorMsg));
            }

            $vision = $this->parseVisionTestReply($reply);
            $this->response->throwJson(array(
                'status'    => 'success',
                'supported' => $vision['supported'],
                'visionMsg' => $vision['msg'],
                'msg'       => $vision['supported']
                    ? '这个大模型反馈说它支持图片识别哦，可以看它识别出来的正不正确来做判断是否开启哦。'
                    : '这个模型貌似不支持图片输入哦，不建议开启图片识别。'
            ));
        }

        // ---- 正式请求参数 ----
        // 改为从浏览器直接传内容，不再依赖数据库草稿做 hash 校验
        // （草稿存储时可能对换行/HTML 实体做了转换，hash 永远对不上）
        $text        = $this->getRequestString('text');        // 选中润色时为选中片段，否则为空
        $textContent = $this->getRequestString('textContent'); // 浏览器里的完整正文
        $titleContent = $this->getRequestString('titleContent');// 浏览器里的标题
        $cid         = $this->getRequestInt('cid');
        $draftCid    = $this->getRequestInt('draft');

        // cid 仅用于权限校验，不再读取草稿内容
        if ($cid > 0) {
            $base = $this->fetchContentRow($cid);
            if (empty($base)) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '没有找到这篇文章，请刷新页面后重试。'));
            }
            if (!$this->isEditableContentType($base['type'])) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '当前内容类型不支持 AI 润色。'));
            }
            if (!$user->pass('editor', true) && (int) $base['authorId'] !== (int) $user->uid) {
                $this->response->throwJson(array('status' => 'error', 'msg' => '没有编辑这篇内容的权限。'));
            }
        }

        // 根据操作类型，从浏览器传来的内容中取 text/context
        if ($type === 'content') {
            // context 用作标题上下文
            $context = $titleContent;
            // text：选中润色时为选中片段，全文润色时为完整正文
            $text = ($text !== '') ? $text : $textContent;
        } elseif ($type === 'title') {
            $text = $titleContent;
            $context = $textContent;
        } elseif ($type === 'generate_title') {
            $text = $titleContent;
            $context = $textContent;
        }

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
        $prompt      = $pluginOpts->prompt;
        $imageVision = $this->isImageVisionEnabled(isset($pluginOpts->imageVision) ? $pluginOpts->imageVision : null);

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

        // 图片只在正文润色时提取（标题润色/生成标题不需要给 AI 看图片，省时间）
        $sourceForImages = $type === 'content'
            ? ($text !== '' ? $text : $textContent)
            : '';
        $articleImages = ($imageVision && $sourceForImages !== '') ? $this->extractArticleImages($sourceForImages) : array();
        if (!empty($articleImages)) {
            $systemPrompt .= "\n\n【图片识别已开启】用户消息中可能包含按文章出现顺序附带的图片。请只把图片作为理解文章语境的辅助信息，不要编造无法确认的图片内容。";
        }
        // ---- 开始 SSE 流（在下载图片之前启动，避免长时间无输出导致浏览器/网关断连）----
        $this->startSSE();
        $this->pushSSE('ready', array('msg' => '已连接，正在请求 AI 接口'));

        // 下载图片期间通过 SSE ping 保活，避免长连接被网关切断
        $self = $this;
        $lastHeartbeat = time();
        $userContent = $this->buildUserContentWithImages($userMessage, $articleImages, function ($current, $total) use ($self, &$lastHeartbeat) {
            if (time() - $lastHeartbeat >= 5) {
                $self->pushSSE('ping', array(
                    'time' => time(),
                    'progress' => '下载文章图片 ' . $current . '/' . $total
                ));
                $lastHeartbeat = time();
            }
        });

        // 准备好的提示
        if (!empty($articleImages)) {
            $this->pushSSE('ping', array('time' => time(), 'progress' => '图片准备完成，开始请求 AI'));
        }

        $data = array(
            'model'       => $modelName,
            'messages'    => array(
                array('role' => 'system', 'content' => $systemPrompt),
                array('role' => 'user',   'content' => $userContent)
            ),
            'temperature' => 0.7,
            'stream'      => true
        );

        $sseBuffer  = '';
        $isApiError = false;
        $errorBody  = '';
        $self       = $this;
        $lastHeartbeat = time();
        $doneSent = false;

        $ch = curl_init();
        if ($ch === false) {
            $this->pushSSE('error', array('msg' => '无法初始化 cURL，请确认 PHP curl 扩展已启用'));
            exit;
        }
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        $this->applyCurlSecurityDefaults($ch);

        // 流式回调：逐块解析 OpenAI SSE 并转发给浏览器
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($self, &$sseBuffer, &$isApiError, &$errorBody, &$doneSent) {
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

                if (strpos($line, 'data:') === 0) {
                    $jsonStr = trim(substr($line, 5));

                    if ($jsonStr === '[DONE]') {
                        if (!$doneSent) {
                            $self->pushSSE('done', array());
                            $doneSent = true;
                        }
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

        $mh = curl_multi_init();
        if ($mh === false) {
            $this->pushSSE('error', array('msg' => '无法初始化 cURL multi，请确认 PHP curl 扩展可用'));
            curl_close($ch);
            exit;
        }

        curl_multi_add_handle($mh, $ch);
        $running = null;
        do {
            do {
                $multiStatus = curl_multi_exec($mh, $running);
            } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

            if (time() - $lastHeartbeat >= 15) {
                $this->pushSSE('ping', array('time' => time()));
                $lastHeartbeat = time();
            }

            if ($running && $multiStatus === CURLM_OK) {
                $selected = curl_multi_select($mh, 1.0);
                if ($selected === -1) {
                    usleep(100000);
                }
            }
        } while ($running && $multiStatus === CURLM_OK);

        $curlError = curl_error($ch);
        if (isset($multiStatus) && $multiStatus !== CURLM_OK && !$curlError) {
            $curlError = 'cURL multi error: ' . $multiStatus;
        }
        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);
        curl_close($ch);

        // 错误处理
        if ($curlError) {
            $this->pushSSE('error', array('msg' => '请求 AI 接口失败: ' . $curlError));
        } elseif ($isApiError) {
            $resJson = json_decode($errorBody, true);
            $msg = isset($resJson['error']['message']) ? $resJson['error']['message'] : '未知错误 (原始响应: ' . mb_substr($errorBody, 0, 200, 'UTF-8') . ')';
            $this->pushSSE('error', array('msg' => 'AI 返回错误: ' . $msg));
        } elseif (!$doneSent) {
            $this->pushSSE('done', array());
        }

        exit;
    }
}
