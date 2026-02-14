<?php

    date_default_timezone_set("Asia/Taipei");
    chdir(dirname(__FILE__));

	include 'mysql.php';
    require_once 'MysqliDb.php';
    require_once 'vendor/autoload.php';

    $db         =   new MysqliDb($mysqli);

    $system     =   $db->getOne('wsystem');
    $service_pref   =   json_decode($system['service_pref'], true);
    include 'config.php';

    // Hardcode API Key for isolated environment
    $client = OpenAI::factory()
        ->withApiKey($openai_api_key)
        ->withBaseUri($system['gpt_api_url'])
        ->make();

    // Tool Definition: Extract New Facts
    $extract_facts_def = [
        'name' => 'extract_new_facts',
        'description' => 'Extracts new, permanent facts about the user from the conversation history, avoiding duplicates found in current memory.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'new_facts' => [
                    'type' => 'string',
                    'description' => 'The extracted new facts as a concise string. Return empty string if no new relevant facts found.'
                ]
            ],
            'required' => ['new_facts'],
            'additionalProperties' => false
        ]
    ];

    // Transform to Tools API format
    $compress_tools = [
        [
            'type' => 'function',
            'function' => $extract_facts_def
        ]
    ];

    $users = $db->get('ai_member');

    foreach ($users as $user){

        if (is_null($user['memo']) || empty($user['memo'])) continue;

        // Verify if user is blocked
        $block  =   $db->where('uid', $user['uid'])->getOne('blocked_ai_member');
        if ($db->count > 0) continue;

        // 1. Prepare Current Memory (The "Old" Knowledge)
        // We want to merge all existing memory lines into one context string for the AI to check against.
        $current_memory_arr = [];
        $current_memory_str = "";
        
        if (!is_null($user['compressed'])){
            $current_memory_arr = json_decode($user['compressed'], true);
            if (is_array($current_memory_arr)) {
                foreach ($current_memory_arr as $mem_item) {
                     if (isset($mem_item['content'])) {
                         $current_memory_str .= $mem_item['content'] . " ";
                     }
                }
            }
        }
        
        $current_memory_str = trim($current_memory_str);

        // 2. Prepare Conversation History (The "New" Logs)
        $raw_messages = json_decode($user['memo'], true);
        $clean_messages = [];
        
        // Filter out function/tool messages
        foreach ($raw_messages as $msg) {
             if (isset($msg['role']) && ($msg['role'] === 'function' || $msg['role'] === 'tool')) continue;
             if (isset($msg['content']) && is_null($msg['content'])) $msg['content'] = '';
             $clean_messages[] = $msg;
        }

        // 3. Construct System Prompt (English)
        $system_instruction = "You are a memory manager for an AI assistant.
        
        **Current User Memory**:
        \"" . ($current_memory_str ?: "No prior memory.") . "\"

        **Task**:
        Analyze the following conversation history. Extract ONLY **new**, **permanent**, and **specific** facts about the User.
        
        **Rules**:
        1. **NO Duplicates**: If a fact is already in 'Current User Memory', IGNORE it.
        2. **STRICTLY IGNORE Transient Info**: Do NOT record commands like 'Wake me up at...', 'Set alarm', 'Play music', 'Check weather', 'Google search', 'Book tickets'.
        3. **STRICTLY IGNORE Context-less Queries**: Do NOT record questions like 'What is the capital of France?', 'Who is Taylor Swift?', unless the user explicitly relates it to themselves (e.g., 'I love Taylor Swift').
        4. **Specifics Only**: Focus on User's name, birthday, profession, skills, pets, friends, preferences (likes/dislikes), and biographical data.
        5. **Relationship Context**: Record details about specific friends (e.g., 'User wants to say hi to Eric'), but ignore the specific timing of the message unless relevant to a habit.
        6. **Merge**: Return the new facts as a single concise sentence or paragraph.
        7. **Empty Return**: If there is no *new* permanent info, return an empty string.
        ";

        // Insert System Prompt at the beginning
        array_unshift($clean_messages, ['role' => 'system', 'content' => $system_instruction]);

        // 4. Call API (gpt-5.2 Hardcoded)
        $result = $client->chat()->create([
            'model' => 'gpt-5.2',
            'messages' => $clean_messages,
            'tools' => $compress_tools,
            'tool_choice' => ['type' => 'function', 'function' => ['name' => 'extract_new_facts']]
        ]);

        if (isset($result->error->message)){
            $data = Array();
            $data['msg'] = 'Compress Error: ' . $result->error->message;
            $db->insert('err_log', $data);
            continue;
        }

        // 5. Parse Result
        $msg_obj = $result->choices[0]->message;
        
        if (!empty($msg_obj->toolCalls)){
            $tool_call = $msg_obj->toolCalls[0];
            $arg = json_decode($tool_call->function->arguments);

            if (isset($arg->new_facts) && !empty(trim($arg->new_facts))){
                
                // Append new facts to the existing memory string
                $updated_memory_str = trim($current_memory_str . " " . trim($arg->new_facts));
                
                // Re-package into the single-record structure requested by User
                // [ { "role": "user", "content": "ALL COMBINED FACTS" } ]
                $new_compressed_structure = [
                    [
                        "role" => "user",
                        "content" => $updated_memory_str
                    ]
                ];

                $usr_data   =   Array();
                $usr_data['compressed']   =   json_encode($new_compressed_structure, JSON_UNESCAPED_UNICODE);
                $usr_data['memo']   =   NULL; // Clear processed logs
                
                $db->where('id', $user['id'])->update('ai_member', $usr_data);
                
                // Log success (Optional)
                // $db->insert('err_log', ['msg' => 'Memory Updated for UID ' . $user['uid']]);
            
            } else {
                // No new facts found, but we should still clear the processed memo to avoid processing it again?
                // OR KEEP IT? Usually, we clear it because we've "processed" it (decided it has no value).
                // Let's clear it to prevent infinite loops of processing empty logs.
                $usr_data = Array('memo' => NULL);
                $db->where('id', $user['id'])->update('ai_member', $usr_data);
            }
        } else {
             // If AI didn't call tool (weird), clear memo anyway to be safe? Or log error.
             // For safety, let's just log it.
             // $db->insert('err_log', ['msg' => 'Compress: No tool called.']);
        }

    }

    if ($handle = opendir('./userfiles/web/images/tmp/')) {

        setlocale(LC_ALL,'en_US.UTF-8');
        chdir('./userfiles/web/images/tmp/');

        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                unlink($entry);
            }
        }

    }

?>