<?php

    // 限制只能從 CLI (Cronjob) 執行，禁止瀏覽器訪問
    // Limit execution to CLI (Cronjob) only, block browser access
    if (php_sapi_name() != 'cli') {
        die('Access Denied: This script can only be run from the command line.');
    }

    date_default_timezone_set("Asia/Taipei");
    chdir(dirname(__FILE__));

    include 'mysql.php';
    require_once 'MysqliDb.php';
    require_once 'vendor/autoload.php';
    include 'config.php';

    $db         =   new MysqliDb($mysqli);

    // OpenAI Client Setup
    $client = OpenAI::factory()
        ->withApiKey($openai_api_key)
        ->withBaseUri('https://api.openai.com/v1')
        ->make();

    // === Helper Functions (Moved to top for scope visibility) ===

    function send_push_message($channelAccessToken, $message, $userId) {  
        $url = 'https://api.line.me/v2/bot/message/push';  
        $data = ['to' => $userId, 'messages' => $message];
        $payload = json_encode($data);
        // echo "Payload to $userId: " . $payload . "<br>"; // Debug Output suppressed

        $options = [  
            'http' => [  
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$channelAccessToken}\r\n",  
                'method' => 'POST',  
                'content' => $payload,  
                'ignore_errors' => true 
            ],  
        ];  
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
        if (strpos($http_response_header[0], '200') === false) {
             return 'Error: ' . $http_response_header[0] . ' - ' . $result;
        }

        return 'OK';
    }

    function get_profile_pic_url($token, $uid){
        $url = 'https://api.line.me/v2/bot/profile/'.$uid;
        $options = [  
            'http' => [  
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",  
                'method' => 'GET',  
                'ignore_errors' => true
            ],  
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
        if (strpos($http_response_header[0], '200') === false) {
             return "Error: " . $http_response_header[0] . " Body: " . $result;
        }

        $profile = json_decode($result, true);
        return $profile['pictureUrl'] ?? null;
    }

    function create_match_report_flex($title, $report_text, $conversation_data, $action_button = null) {
        
        // Header
        $contents = [
            [
                "type" => "text",
                "text" => $title,
                "weight" => "bold",
                "size" => "lg", // Standard Large Header
                "color" => "#1DB446"
            ],
            [
                "type" => "text",
                "text" => $report_text,
                "margin" => "md",
                "size" => "sm",
                "wrap" => true,
                "color" => "#666666"
            ],
            [
                "type" => "separator",
                "margin" => "lg"
            ]
        ];

        // Conversation History
        foreach ($conversation_data as $chat) {
            $contents[] = [
                "type" => "box",
                "layout" => "horizontal",
                "margin" => "lg",
                "spacing" => "sm",
                "contents" => [
                    [
                        "type" => "image",
                        "url" => $chat['icon'], 
                        "size" => "40px", // Fixed pixel size for best avatar look
                        "aspectMode" => "cover",
                        "aspectRatio" => "1:1",
                        "flex" => 0
                    ],
                    [
                        "type" => "text",
                        "text" => "{$chat['name']}: {$chat['text']}",
                        "wrap" => true,
                        "color" => $chat['color'] ?? "#111111",
                        "size" => "sm", // Standard readable size
                        "flex" => 4,
                        "gravity" => "center"
                    ]
                ]
            ];
        }

        $bubble = [
            "type" => "bubble",
            "size" => "giga", // Max width
            "body" => [
                "type" => "box",
                "layout" => "vertical",
                "contents" => $contents
            ]
        ];

        // Footer with Action Button
        if ($action_button) {
            $bubble['footer'] = [
                "type" => "box",
                "layout" => "vertical",
                "spacing" => "sm",
                "contents" => [
                    [
                        "type" => "button",
                        "style" => "primary",
                        "height" => "sm",
                        "action" => [
                            "type" => "clipboard",
                            "label" => $action_button['label'],
                            "clipboardText" => $action_button['uid_to_copy']
                        ]
                    ]
                ]
            ];
        }

        return $bubble;
    }
    // ============================================================

    $system     =   $db->getOne('wsystem');
    $service_pref   =   json_decode($system['service_pref'], true);

    // 1. 指定男主角 (Eric)
    // 實際上線後，這裡會改為隨機撈取
    $target_uid = $target_match_uid;
    $eric = $db->where('uid', $target_uid)->getOne('ai_member');

    if (!$eric) {
        die("User not found: $target_uid");
    }

    echo "=== Active User (Eric) ===<br>";
    echo "Name: {$eric['u_name']}<br>";
    echo "Age: {$eric['age']} ({$eric['age_from']} ~ {$eric['age_to']})<br>";
    echo "City: {$eric['city']}<br>";
    echo "Accept Long Distance: " . ($eric['long_distance'] ? 'Yes' : 'No') . "<br>";
    echo "<hr>";


    // 2. 建立黑名單 (Blacklist)
    $blacklist = [];

    // 2a. 歷史配對紀錄
    if (isset($eric['match_history']) && !is_null($eric['match_history'])) {
        $history = json_decode($eric['match_history'], true);
        if (is_array($history)) {
            $blacklist = array_merge($blacklist, $history);
        }
    }

    // 2b. 現有好友 (Connections)
    // Eric 可能是 uid1 或 uid2
    $conns = $db->where('uid1', $eric['uid'])
                ->orWhere('uid2', $eric['uid'])
                ->get('connections', null, 'uid1, uid2');

    foreach($conns as $c) {
        $blacklist[] = ($c['uid1'] == $eric['uid']) ? $c['uid2'] : $c['uid1'];
    }

    // 2c. 排除自己 (雖然 sex 已經擋掉了，但防呆)
    $blacklist[] = $eric['uid'];

    // Debug Blacklist
    // echo "Blacklist UIDs: " . implode(", ", $blacklist) . "<br><hr>";


    // 3. 初步篩選候選人 (Candidates) - SQL 層級
    // 條件：異性、開啟交友、不包括黑名單、年齡符合 Eric 要求
    
    // Determine target sex
    // Strict Mode: Male(1) finds Female(2).
    $target_sex = ($eric['sex'] == 1) ? 2 : 1; 

    echo "Search Criteria:<br>";
    echo " - Target Sex: " . $target_sex . "<br>";
    echo " - Target Age: {$eric['age_from']} ~ {$eric['age_to']}<br>";
    echo " - Blacklist Count: " . count($blacklist) . "<br><hr>";

    $db->where('sex', $target_sex);
    $db->where('new_friend', 1);
    
    // Age Range Check (Eric's preference)
    $db->where('age', $eric['age_from'], '>=');
    $db->where('age', $eric['age_to'], '<=');

    // Exclude Blacklist
    if (!empty($blacklist)) {
        $db->where('uid', $blacklist, 'NOT IN');
    }

    // Filter: Must have compressed AI memory
    $db->where('compressed', NULL, 'IS NOT');

    // Randomize order to give everyone a chance
    $db->orderBy('RAND()');

    // Limit to avoid memory issues
    $candidates = $db->get('ai_member', 10);

    if ($db->count == 0) {
        // echo "No candidates found in initial SQL filter.<br>";
        // echo "Eric Prefs: Sex != {$eric['sex']}, Age {$eric['age_from']}~{$eric['age_to']}<br>";
        // echo "Last SQL Error: " . $db->getLastError() . "<br>";
        // echo "Last SQL Query: " . $db->getLastQuery() . "<br>";
        exit;
    }


    // 4. 進階邏輯驗證 (Double-Check) - PHP 層級
    $final_match = null;

    foreach ($candidates as $judy) {
        
        echo "checking candidate: {$judy['u_name']} (Age: {$judy['age']}, City: {$judy['city']}, Distance: {$judy['long_distance']}) - range: {$judy['age_from']}~{$judy['age_to']}<br>";

        // A. 逆向年齡檢查：Judy 是否接受 Eric 的年齡？
        if (!is_null($judy['age_from']) && !is_null($judy['age_to'])) {
            if ($eric['age'] < $judy['age_from'] || $eric['age'] > $judy['age_to']) {
                // Eric 不符合 Judy 的年齡要求
                echo "-> Rejected: Age mismatch. Eric is {$eric['age']}, she wants {$judy['age_from']}~{$judy['age_to']}<br>";
                continue; 
            }
        }

        // B. 雙向地點檢查
        // 邏輯：(接受遠距) OR (城市模糊比對成功)
        
        // 1. Eric 對 Judy 的地點要求
        $eric_accepts_judy = ($eric['long_distance'] == 1) || 
                             (mb_strpos($judy['city'], $eric['city']) !== false) || 
                             (mb_strpos($eric['city'], $judy['city']) !== false);

        // 2. Judy 對 Eric 的地點要求
        $judy_accepts_eric = ($judy['long_distance'] == 1) || 
                             (mb_strpos($eric['city'], $judy['city']) !== false) || 
                             (mb_strpos($judy['city'], $eric['city']) !== false);

        if ($eric_accepts_judy && $judy_accepts_eric) {
            $final_match = $judy;
            break; // 找到一個就停，或者要找多個？目前先找一個最好的。
        } else {
            echo "-> Rejected: Location mismatch. E->J: " . ($eric_accepts_judy?'OK':'NO') . ", J->E: " . ($judy_accepts_eric?'OK':'NO') . "<br>";
        }
    }


    // 5. 輸出結果與 AI 互動
    if ($final_match) {
        echo "=== MATCH FOUND! ===<br>";
        echo "UID: {$final_match['uid']}<br>";
        echo "Name: {$final_match['u_name']}<br>";
        echo "Age: {$final_match['age']}<br>";
        echo "City: {$final_match['city']}<br>";
        echo "<hr>";

        // === Step 1: Eric Agent Initiates Contact ===
        
        // Prepare Eric's Profile Data
        $eric_sex_str = ($eric['sex'] == 1) ? "男性" : "女性";
        $eric_memory_str = "";
        
        if (!is_null($eric['compressed'])) {
            $mem_api = json_decode($eric['compressed'], true); // Assuming new format is standardized
            // Extract content from compressed memory
            if (is_array($mem_api)) {
                 foreach($mem_api as $m) {
                     if (isset($m['content'])) $eric_memory_str .= $m['content'] . " ";
                 }
            }
        }

        $system_prompt = "你的老闆是 {$eric['u_name']}, 他的資料如下:
        性別: {$eric_sex_str}
        年齡: {$eric['age']}
        居住地: {$eric['city']}
        個人特質與記憶: {$eric_memory_str}

        現在你在交誼廳看到一個女生叫 {$final_match['u_name']}，條件看起來很適合你老闆，但我們要先跟這個 {$final_match['u_name']} Agent 交流一下，才能確定兩個人是否合適。
        
        任務: 請跟她的 Agent 打聲招呼，你可以稱呼他 {$final_match['u_name']} Agent，順便介紹一下你老闆 {$eric['u_name']}。
        語氣: 輕鬆、友善、自信但不油膩。
        限制: 請控制在 150 字元以內。";

        echo "<b>Prompting AI (Eric Agent)...</b><br>";

        // Call OpenAI for Eric
        try {
            $response = $client->chat()->create([
                'model' => 'gpt-5-chat-latest',
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => "開始你的招呼與介紹。"]
                ],
            ]);

            $eric_greeting = $response->choices[0]->message->content;
            echo "<h3>Eric Agent says:</h3>";
            echo "<div style='background:#e6f7ff; padding:10px; border-radius:10px;'>{$eric_greeting}</div>";


            // === Step 2: Judy Agent Responds ===
            echo "<hr>";
            echo "<b>Prompting AI (Judy Agent)...</b><br>";

            // Prepare Judy's Data
            $judy_sex_str = ($final_match['sex'] == 1) ? "男性" : "女性";
            $judy_memory_str = "";
            if (!is_null($final_match['compressed'])) {
                $mem_api = json_decode($final_match['compressed'], true);
                if (is_array($mem_api)) {
                     foreach($mem_api as $m) {
                         if (isset($m['content'])) $judy_memory_str .= $m['content'] . " ";
                     }
                }
            }

            $judy_system_prompt = "你是 {$final_match['u_name']} 的可愛小秘書 (Agent)。
            你老闆 {$final_match['u_name']} 的資料如下:
            性別: {$judy_sex_str}
            年齡: {$final_match['age']}
            居住地: {$final_match['city']}
            個人特質與記憶: {$judy_memory_str}

            現在 Eric 的 Agent (Eric Agent) 傳送訊息來跟你打招呼。
            任務: 請回應 Eric Agent，你可以稱呼他 Eric Agent。稍微介紹一下你的老闆 {$final_match['u_name']}，並根據對方的介紹判斷是否要友善回應。
            語氣: 可愛、親切、大方。
            限制: 請控制在 150 字元以內。";

            // Call OpenAI for Judy
            $response_judy = $client->chat()->create([
                'model' => 'gpt-5-chat-latest',
                'messages' => [
                    ['role' => 'system', 'content' => $judy_system_prompt],
                    ['role' => 'user', 'content' => "Eric Agent 說: " . $eric_greeting]
                ],
            ]);

            $judy_response = $response_judy->choices[0]->message->content;
            echo "<h3>Judy Agent says:</h3>";
            echo "<div style='background:#fff0f6; padding:10px; border-radius:10px;'>{$judy_response}</div>";


            // === Step 3: Eric Agent Evaluates & Responds ===
            echo "<hr>";
            echo "<b>Prompting AI (Eric Agent Evaluation)...</b><br>";

            $eric_score = 0; 

            $tools = [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'record_match_score',
                        'description' => 'Record the compatibility score between Eric and the candidate.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'score' => [
                                    'type' => 'integer',
                                    'description' => 'Compatibility score from 0 to 100.'
                                ]
                            ],
                            'required' => ['score']
                        ]
                    ]
                ]
            ];

            $eric_eval_prompt = "你正在幫你老闆 {$eric['u_name']} 認識新朋友。
            {$eric['u_name']} 的資料:
            性別: {$eric_sex_str}
            年齡: {$eric['age']}
            居住地: {$eric['city']}
            個人特質與記憶: {$eric_memory_str}

            下面是你與 {$final_match['u_name']} 的 Agent (Judy Agent) 的對話：
            Eric Agent (你): {$eric_greeting}
            Judy Agent: {$judy_response}

            任務:
            1. 從她對 {$final_match['u_name']} 的介紹，你覺得 {$final_match['u_name']} 跟 {$eric['u_name']} 的契合度如何？如果滿分是100，你會給出幾分？
            2. 請使用 function `record_match_score` 記錄下分數。
            3. 用輕鬆幽默的風格回覆 Judy Agent，根據分數高低來表達你的興趣或遺憾，並明確說出你給的分數（例如『我覺得這緣分有90分』）。
            限制:文字回覆請控制在 150 字元以內。";

            $response_eval = $client->chat()->create([
                'model' => 'gpt-5-chat-latest',
                'messages' => [
                    ['role' => 'system', 'content' => $eric_eval_prompt],
                    ['role' => 'user', 'content' => "請評分並回覆。"]
                ],
                'tools' => $tools,
                'tool_choice' => 'auto'
            ]);

            $msg_eval = $response_eval->choices[0]->message;
            $eric_final_reply = $msg_eval->content;

            if (!empty($msg_eval->toolCalls)) {
                $tool_call = $msg_eval->toolCalls[0];
                if ($tool_call->function->name === 'record_match_score') {
                    $args = json_decode($tool_call->function->arguments, true);
                    $eric_score = $args['score']; 
                }
            }
            // Fallback score extraction
            if ($eric_score == 0) {
                if (preg_match('/(\d{2,3})/', $eric_final_reply, $matches)) {
                    $eric_score = intval($matches[1]);
                }
            }
            echo "<h3>Eric Agent's Score: <span style='color:red'>{$eric_score}</span></h3>";
            
            echo "<h3>Eric Agent Final Reply:</h3>";
            echo "<div style='background:#e6f7ff; padding:10px; border-radius:10px;'>{$eric_final_reply}</div>";


            // === Step 4: Judy Agent Evaluates Eric ===
            echo "<hr>";
            echo "<b>Prompting AI (Judy Agent Evaluation)...</b><br>";

            $judy_score = 0; 

            $judy_eval_prompt = "你是 {$final_match['u_name']} 的可愛小秘書 (Agent)。
            你老闆 {$final_match['u_name']} 的資料:
            性別: {$judy_sex_str}
            年齡: {$final_match['age']}
            居住地: {$final_match['city']}
            個人特質與記憶: {$judy_memory_str}

            下面是 Eric Agent 與你的對話：
            Eric Agent: {$eric_greeting}
            Judy Agent (你): {$judy_response}
            Eric Agent (回覆與評分): {$eric_final_reply}

            任務:
            1. Eric Agent 已經表達了他覺得兩人是否合適。現在換你評估：就你對 {$final_match['u_name']} 的了解，你覺得她跟 Eric 適合當朋友嗎？
            2. 如果滿分是100，你會給出幾分？
            3. 請使用 function `record_match_score` 記錄下分數。
            4. 用輕鬆幽默的語氣回應 Eric Agent，解釋為什麼你給這個分數（例如：太老了、太遠了、或是感覺很合拍）。
            限制:文字回覆請控制在 150 字元以內。";

            $response_judy_eval = $client->chat()->create([
                'model' => 'gpt-5-chat-latest',
                'messages' => [
                    ['role' => 'system', 'content' => $judy_eval_prompt],
                    ['role' => 'user', 'content' => "請評分並回覆。"]
                ],
                'tools' => $tools,
                'tool_choice' => 'auto'
            ]);

            $msg_judy_eval = $response_judy_eval->choices[0]->message;
            $judy_final_reply = $msg_judy_eval->content;

            if (!empty($msg_judy_eval->toolCalls)) {
                $tool_call = $msg_judy_eval->toolCalls[0];
                if ($tool_call->function->name === 'record_match_score') {
                    $args = json_decode($tool_call->function->arguments, true);
                    $judy_score = $args['score'];
                }
            }
            // Fallback score extraction
            if ($judy_score == 0) {
                if (preg_match('/(\d{2,3})/', $judy_final_reply, $matches)) {
                    $judy_score = intval($matches[1]);
                }
            }
            echo "<h3>Judy Agent's Score: <span style='color:red'>{$judy_score}</span></h3>";

            echo "<h3>Judy Agent Final Reply:</h3>";
            echo "<div style='background:#fff0f6; padding:10px; border-radius:10px;'>{$judy_final_reply}</div>";


            // === Step 5: Final Reports to Users ===
            echo "<hr>";
            echo "<b>Generating Final Reports...</b><br>";

            $final_conversation = "
            Eric Agent: $eric_greeting
            Judy Agent: $judy_response
            Eric Agent Reply: $eric_final_reply
            Judy Agent Reply: $judy_final_reply
            ";

            // 1. Report to Eric (Text Content Generation)
            $report_to_eric_prompt = "下面的對話，是你幫你的老闆 {$eric['u_name']} 認識的新朋友 {$final_match['u_name']} 的過程。
            對話內容:
            $final_conversation
            
            Eric Agent 分數: $eric_score
            Judy Agent 分數: $judy_score
            
            任務: 跟 {$eric['u_name']} 報告結果。
            如果你們的分數都超過 70 分，就代表你們都認為 {$eric['u_name']} 與 {$final_match['u_name']} 是很合適的，跟 {$eric['u_name']} 報告這個好消息吧！
            如果沒有超過 70 分，也請安慰他一下，說明雖然這次不太合適，但你會繼續努力幫他找。
            語氣: 像是貼心的私人助理。
            限制: 100 字以內。
            注意: 不要在報告中直接列出數字分數。";

            $report_eric = $client->chat()->create([
                'model' => 'gpt-5-chat-latest',
                'messages' => [['role' => 'system', 'content' => $report_to_eric_prompt]]
            ]);
            $eric_report_text = $report_eric->choices[0]->message->content;
            echo "<h3>Report to Eric:</h3>";
            echo "<div style='border:2px solid #007bff; padding:10px; border-radius:10px;'>{$eric_report_text}</div>";

            // 2. Report to Judy (Text Content Generation)
            $report_to_judy_prompt = "下面的對話，是你幫你的老闆 {$final_match['u_name']} 認識的新朋友 {$eric['u_name']} 的過程。
            對話內容:
            $final_conversation
            
            Eric Agent 分數: $eric_score
            Judy Agent 分數: $judy_score
            
            任務: 跟 {$final_match['u_name']} 報告結果。
            如果你們的分數都超過 70 分，就代表你們都認為 {$eric['u_name']} 與 {$final_match['u_name']} 是很合適的，跟 {$final_match['u_name']} 報告這個好消息吧！
            如果沒有超過 70 分，也請安慰她一下，說明雖然這次不太合適，但你會繼續努力。
            語氣: 像是貼心的閨蜜小秘書。
            限制: 100 字以內。
            注意: 不要在報告中直接列出數字分數。";

            $report_judy = $client->chat()->create([
                'model' => 'gpt-5-chat-latest',
                'messages' => [['role' => 'system', 'content' => $report_to_judy_prompt]]
            ]);
            $judy_report_text = $report_judy->choices[0]->message->content;
            echo "<h3>Report to Judy:</h3>";
            echo "<div style='border:2px solid #e83e8c; padding:10px; border-radius:10px;'>{$judy_report_text}</div>";


            // === Step 6: Send LINE Messages ===
            echo "<hr>";
            echo "<b>Sending LINE Messages...</b><br>";

            // Fetch Idol Tokens
            $eric_idol = $db->where('id', $eric['idol'])->getOne('idols');
            $judy_idol = $db->where('id', $final_match['idol'])->getOne('idols'); 
            
            if ($eric_idol) {
                $token = $eric_idol['tokens'];
                $j_token = ($judy_idol) ? $judy_idol['tokens'] : $token;

                // Get User Profile Pics
                echo "Fetching Eric Pic (UID: {$eric['uid']})...<br>";
                $eric_pic = get_profile_pic_url($token, $eric['uid']);
                echo "Eric Pic URL: " . ($eric_pic ? $eric_pic : "NULL") . "<br>";

                // Use Judy's token to get her pic
                $j_token_debug = substr($j_token, 0, 10) . "...";

                // Debug: Check why pic is missing
                echo "Judy (uid: {$final_match['uid']}) Idol ID: " . ($final_match['idol'] ?? 'NULL') . "<br>";
                echo "Has Idol Token? " . ($judy_idol ? "Yes" : "No (Using Eric's)") . "<br>";
                echo "Using Token: " . $j_token_debug . "<br>";

                $judy_pic = get_profile_pic_url($j_token, $final_match['uid']);
                echo "Judy Pic Result: " . ($judy_pic ? $judy_pic : "NULL") . "<br>";
                
                if (strpos($judy_pic, "Error:") === 0) {
                     $judy_pic = null;
                }

                // Fallback to UI Avatars if completely missing (User requested NOT to use Agent Avatar)
                if (!$eric_pic) $eric_pic = "https://ui-avatars.com/api/?name=" . urlencode($eric['u_name']);
                if (!$judy_pic) $judy_pic = "https://ui-avatars.com/api/?name=" . urlencode($final_match['u_name']);

                // Sanitize AI Responses (Remove JSON/Tool artifacts)
                $eric_final_reply = preg_replace('/\{.*?"score".*?\}\s*/s', '', $eric_final_reply);
                $judy_final_reply = preg_replace('/\{.*?"score".*?\}\s*/s', '', $judy_final_reply);

                // Conversation Data
                $eric_display_name = $eric['u_name'] . ' Agent';
                $judy_display_name = $final_match['u_name'] . ' Agent';

                $conversation_data = [
                    ['name' => $eric_display_name, 'text' => $eric_greeting, 'icon' => $eric_pic, 'color' => '#0044CC'],
                    ['name' => $judy_display_name, 'text' => $judy_response, 'icon' => $judy_pic, 'color' => '#E60073'],
                    ['name' => $eric_display_name, 'text' => $eric_final_reply, 'icon' => $eric_pic, 'color' => '#0044CC'],
                    ['name' => $judy_display_name, 'text' => $judy_final_reply, 'icon' => $judy_pic, 'color' => '#E60073']
                ];

                // Determine Copy Button (Action) data
                $action_for_eric = null;
                $action_for_judy = null;

                // Debug Scores
                echo "Scores: Eric={$eric_score}, Judy={$judy_score}<br>";

                if ($eric_score > 70 && $judy_score > 70) {
                     $action_for_eric = [
                        'uid_to_copy' => $final_match['uid'],
                        'label' => "複製 {$final_match['u_name']} 的 ID"
                     ];
                     $action_for_judy = [
                        'uid_to_copy' => $eric['uid'],
                        'label' => "複製 {$eric['u_name']} 的 ID"
                     ];
                     
                     // Append instruction text to report
                     $eric_report_text .= "\n\n你只要按下面的複製按鈕，然後貼給我，我就能幫你傳訊息給 {$final_match['u_name']} 嘍～要嗎？";
                     $judy_report_text .= "\n\n你只要按下面的複製按鈕，然後貼給我，我就能幫你傳訊息給 {$eric['u_name']} 嘍～要嗎？";
                }

                // --- Send to Eric ---
                $flex_eric = create_match_report_flex("{$eric['u_name']} 與 {$final_match['u_name']} 的緣分報告", $eric_report_text, $conversation_data, $action_for_eric);
                $messages_to_eric = [['type' => 'flex', 'altText' => '您的緣分報告來囉！', 'contents' => $flex_eric]];

                $res = send_push_message($token, $messages_to_eric, $eric['uid']);
                echo "Sent to Eric: " . $res . "<br>";


                // --- Send to Judy ---
                $flex_judy = create_match_report_flex("{$final_match['u_name']} 與 {$eric['u_name']} 的緣分報告", $judy_report_text, $conversation_data, $action_for_judy);
                $messages_to_judy = [['type' => 'flex', 'altText' => '您的緣分報告來囉！', 'contents' => $flex_judy]];

                $res = send_push_message($j_token, $messages_to_judy, $final_match['uid']);
                // echo "Sent to Judy: " . $res . "<br>";

                // === Step 7: Update Match History in DB ===
                // echo "<hr><b>Updating Match History...</b><br>";
                
                // Update Eric's history
                $eric_history = json_decode($eric['match_history'] ?? '[]', true);
                if (!is_array($eric_history)) $eric_history = [];
                if (!in_array($final_match['uid'], $eric_history)) {
                    $eric_history[] = $final_match['uid'];
                    $db->where('uid', $eric['uid'])->update('ai_member', ['match_history' => json_encode($eric_history)]);
                    // echo "Updated Eric's history.<br>";
                }

                // Update Judy's history
                $judy_history = json_decode($final_match['match_history'] ?? '[]', true);
                if (!is_array($judy_history)) $judy_history = [];
                if (!in_array($eric['uid'], $judy_history)) {
                    $judy_history[] = $eric['uid'];
                    $db->where('uid', $final_match['uid'])->update('ai_member', ['match_history' => json_encode($judy_history)]);
                    // echo "Updated Judy's history.<br>";
                }

            } else {
                // echo "Error: Idol not found for Eric.<br>";
            }


    } else {
        // echo "=== No Match Found ===<br>";
        // echo "Candidates reviewed: " . count($candidates) . "<br>";
        // echo "Reason: Age mismatch or Location mismatch in double-check phase.<br>";
    }

?>
