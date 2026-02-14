<?php

	function create_msg($client, $memo, $db, $model, $uid, $usr_msg, $system, $user, $idol){

		$today 	=	new DateTime();
		$tstr 	=	$today->format('Y-m-d H:i').' '.$today->format('l');

        // DEBUG: Trace start of create_msg
        $db->insert('err_log', ['msg' => 'create_msg: Start. User msg: ' . $usr_msg]);

		$line_img_arr 	=	Array();
		$line_db_str 	=	'';
		$img_analyze_url =	'';

		$target_uid				=	NULL; //聯絡對象LineID
		$target_name 			=	NULL; //聯絡對象名稱
		$target_relation 		=	NULL; //聯絡對象關係
		$target_idol 			=	NULL; //聯絡對象的line_bot
		$self_name 				=	NULL; //自己在connections中的名字
		$connect 				=	NULL; //聯絡資訊

        $fname_arr  =   Array('alarm_clock', 'analyze_image', 'analyze_web', 'audio_reply', 'contact_someone', 'create_image', 'google_search', 'google_place', 'youtube_search', 'edit_image', 'get_myid', 'add_contact', 'modify_contact', 'think_someone', 'delete_contact', 'chat_only', 'block_user', 'transportation_inquiry', 'edit_profile', 'new_friend', 'no_new_friend', 'contact_list');


		$arrange_functions 		=	Array();
		include './function_arrange.php';
		$arrange_functions[]	=	$arrange;

        // Filter out legacy "role: function" messages to prevent Tools API errors
        // Optimization: Convert function role to assistant role to keep context
        $clean_memo = [];
        foreach ($memo as $m) {
            
            if (isset($m['role']) && $m['role'] === 'function'){
                 $clean_memo[] = [
                    'role' => 'assistant',
                    'content' => "Task [".($m['name'] ?? 'unknown')."] executed. Result: " . ($m['content'] ?? '')
                ];
                continue;
            } 

            // Also ensure content is not null for user/assistant (OpenAI sometimes complains)
            if (isset($m['content']) && is_null($m['content'])) $m['content'] = ''; 
            $clean_memo[] = $m;
        }
        $memo = $clean_memo; // Replace with filtered array

        // Transform arrange to tools format
        $arrange_tools = [];
        foreach ($arrange_functions as $af) {
            $arrange_tools[] = [
                'type' => 'function',
                'function' => $af
            ];
        }

        try {
            // DEBUG: Calling arrange with tools
            $db->insert('err_log', ['msg' => 'create_msg: Calling arrange with Tools API']);

            $result = $client->chat()->create([
                'model' => 'gpt-5.2',
                'messages' => $memo,
                'tools' => $arrange_tools,
                'tool_choice' => ['type' => 'function', 'function' => ['name' => 'arrange']]
            ]);

        } catch (Exception $e) {
            $db->insert('err_log', ['msg' => 'create_msg: Arrange API Exception: ' . $e->getMessage()]);
            $line_img_arr[] = ['type' => 'text', 'text' => 'System Error (Arrange): ' . $e->getMessage()];
            return $line_img_arr;
        }

		// DEBUG: Log arrange result
        // $db->insert('err_log', ['msg' => 'arrange result: ' . json_encode($result)]);

		if (isset($result->error->message)){

			$line_img_arr[] 	=	[  
								       'type' => 'text',  
								       'text' => $result->error->message
								    ];

			return $line_img_arr;
		}

		// $data = Array();
		// $data['msg'] = json_encode($result);
		// $db->insert('err_log', $data);

		// exit($data['msg']);

        // Check for Tool Calls (New Standard)
        $msg_obj = $result->choices[0]->message;
        $main_arg = null;

		if (!empty($msg_obj->toolCalls)){
            $tool_call = $msg_obj->toolCalls[0];
            //$fname = $tool_call->function->name; // Should be 'arrange'
            $args_json = $tool_call->function->arguments;
            
			$main_arg 	=	json_decode($args_json);

			if (!isset($main_arg->function_array)){
				if (!empty($main_arg->message_reply)){

					$line_img_arr[] 	=	[  
										       'type' => 'text',  
										       'text' => $main_arg->message_reply
										    ];

					return $line_img_arr;

				} else {

					$line_img_arr[] 	=	[  
										       'type' => 'text',  
										       'text' => 'AI 發生錯誤，請重新傳送一次(Args Parse Error)'
										    ];

					return $line_img_arr;
				}
			}


			if (count($main_arg->function_array) === 0){
				if (!empty($main_arg->message_reply)){

					$line_img_arr[] 	=	[  
											       'type' => 'text',  
											       'text' => $main_arg->message_reply
											    ];

					return $line_img_arr;

					} else {

					$line_img_arr[] 	=	[  
											       'type' => 'text',  
											       'text' => 'AI 發生錯誤，請重新傳送一次'
											    ];

					return $line_img_arr;
				}
			}
			


			if (file_exists('./userfiles/web/images/tmp/'.$uid.'.jpg')){

				$img_analyze_url 	=	'https://'.$_SERVER['HTTP_HOST'].'/userfiles/web/images/tmp/'.$uid.'.jpg';

			} else {

				$img_analyze_url 	=	'https://faith.tw/userfiles/web/images/dream_girl.png';
			}

			//移除arrange的prompt, 由idol prompt替換
			array_shift($memo);

			$idol_prompt     =   [
			        "role"  => "system",
			        "content" => $idol['default_prompt'].',你目前能做的事情有[傳訊息給其他人,創作圖片,編輯圖片,Google搜尋,高鐵時刻查詢,行事曆提醒,附近商家搜尋,youtube搜尋,網頁分析],除了以上功能外你無法處理其他事務例如上傳分析檔案,訂票預約等'
			    ];

			array_unshift($memo, $idol_prompt);


			foreach ($main_arg->function_array as $func){

				$fname 			=	'';
				if (is_object($func) && isset($func->function_name)){
					$fname 		=	$func->function_name;
				} elseif (is_string($func)) {
					$fname 		=	$func;
				} else {
                    // Fallback or error handling
                    if (is_array($func) && isset($func['function_name'])) {
                        $fname = $func['function_name'];
                    }
                }


				//防止錯誤的function name
				if (!in_array($fname, $fname_arr)){
					if (!empty($main_arg->message_reply)){

						$line_img_arr[] 	=	[  
											       'type' => 'text',  
											       'text' => $main_arg->message_reply
											    ];

						return $line_img_arr;

					} else {

						$line_img_arr[] 	=	[  
											       'type' => 'text',  
											       'text' => '錯誤的function name，請重新傳送一次'
											    ];

						return $line_img_arr;
					}
				}

				// $data = Array();
				// $data['msg'] = $fname;
				// $db->insert('err_log', $data);
				
				//如果是聯繫其他人，調出聯絡紀錄取代原來的memo，調出聯絡人所使用的idol
				if ($fname === 'contact_someone'){

                    try {

					if (!isset($main_arg->contact_id)){
                        // DEBUG: Trace contact_someone error
                        $db->insert('err_log', ['msg' => 'contact_someone: missing contact_id']);

						$line_img_arr[] 	=	[  
											       'type' => 'text',  
											       'text' => 'Sorry, Contact ID not found.'
											    ];

						return $line_img_arr;
					}

					$connect	=	$db->where('id', $main_arg->contact_id)->getOne('connections');


					$target_uid 		=	$connect['uid2'];
					$target_relation 	=	$connect['rel2'];
					$target_name 		=	$connect['uname2'];
					$self_name 			=	$connect['uname1'];

					if ($uid == $connect['uid2']){
						$target_uid 		=	$connect['uid1'];
						$target_relation 	=	$connect['rel1'];
						$target_name 		=	$connect['uname1'];
						$self_name 			=	$connect['uname2'];
					}

					if (is_null($target_relation)){
						$target_relation 	=	'[Undefined]';
					}

					$target_user 	=	$db->where('uid', $target_uid)->getOne('ai_member');
					$target_idol 	=	$db->where('id', $target_user['idol'])->getOne('idols');

					//取出最後一個值檢查是否為任務
					$last_task 		=	end($memo);

					$memo 			=	Array();

					if (!is_null($connect['memo'])){
						$memo		 	=	json_decode($connect['memo'], true);
					}

					// English Prompt for stricter logic
                    // User Language Detection Logic:
                    // We instruct the AI: "Detect the language used by the user in the last message. The output 'contact_msg' MUST be in that same language."
					$task_str 	=	'You are a communication bridge between the user and their friends/family. 
                    Your task is to convey the user\'s message to the contact person.
                    You identify the user\'s intent and relationship context, then rewrite or refine the message to be polite, warm, and appropriate for their relationship.
                    
                    **CRITICAL INSTRUCTIONS**:
                    1. You are NOT chatting with the user here. You are generating the FINAL MESSAGE to be sent to the contact.
                    2. DO NOT output conversational filler like "Okay, I will send this".
                    3. The output MUST be a JSON object calling the function `send_message_to_contact`.
                    4. The `contact_msg` content MUST be in the SAME LANGUAGE as the user\'s original input (e.g., if user speaks Traditional Chinese, output Traditional Chinese).
                    5. **IMPORTANT**: You MUST state clearly who you are speaking for at the beginning of the message. For example: "Hi [Contact Name], [User Name] asked me to tell you..." or "[User Name] wants to say...". Do not leave the recipient guessing who the message is from.
                    
                    **Context**:
                    - User Name: ' . $self_name . '
                    - Target Contact Name: ' . $target_name . '
                    - Relationship (User to Contact): ' . $target_relation . '
                    
                    The following messages are the history between the User and this Contact. The last message is what the user wants to convey now.';

					$sys_prompt     =   [ "role"  => "system", "content" => $idol['default_prompt'] . ". " . $task_str ];
					array_unshift($memo, $sys_prompt);

					$reply_arr 	=	Array();

					$memo[]			=	[ 'role' => 'user', 'content' => '['.$self_name.' says]'.$usr_msg ];

					if (isset($last_task['name'])){
						if ($last_task['name'] == 'create_image' || $last_task['name'] == 'edit_image'){
							$memo[]			=	[ 'role' => 'user', 'content' => '['.$self_name.' says] This is an image for '.$target_name ];

							$reply_arr[]	=	end($line_img_arr);
						}
					}
                    
                    // STRUCTURED OUTPUT DEFINITION (Strict Mode)
                    // We define a strict schema for the function call to force separation of content.
                    $functions_def = [
                        [
                            'name' => 'send_message_to_contact',
                            'description' => 'Send the refined message to the contact person.',
                            'parameters' => [
                                'type' => 'object',
                                'properties' => [
                                    'contact_msg' => [
                                        'type' => 'string',
                                        'description' => 'The actual message content to be sent to the contact. Polished and polite. Must follow the user\'s language.'
                                    ],
                                    'status_for_user' => [
                                        'type' => 'string',
                                        'description' => 'A brief status update for the user (e.g., "Message sent to [Name]").'
                                    ]
                                ],
                                'required' => ['contact_msg', 'status_for_user'],
                                'additionalProperties' => false
                            ]
                        ]
                    ];
                    
                    // Call OpenAI with Tools API (New Standard)
                    // Transforming the functions definition to tools definition
                    $tools = [];
                    foreach ($functions_def as $func) {
                        $tools[] = [
                            'type' => 'function',
                            'function' => $func
                        ];
                    }

                    // DEBUG: Trace before tools call
                    $db->insert('err_log', ['msg' => 'contact_someone: Calling OpenAI Tools API']);

					$contact_result = $client->chat()->create([
					    'model' => 'gpt-5.2', 
					    'messages' => $memo,
                        'tools' => $tools,
                        'tool_choice' => [
                            'type' => 'function',
                            'function' => ['name' => 'send_message_to_contact']
                        ]
					]);

                    // DEBUG LOGGING START
                    $data = Array();
                    $data['msg'] = json_encode($contact_result); // Log the full raw response
                    $db->insert('err_log', $data);
                    // DEBUG LOGGING END

					if (isset($contact_result->error->message)){

						$line_img_arr[] 	=	[  
									       'type' => 'text',  
									       'text' => 'OpenAI API Error: ' . $contact_result->error->message
									    ];

						return $line_img_arr;
					}

                    // Parse the tool call arguments (New Structure)
                    // $contact_result->choices[0]->message->toolCalls[0]->function->arguments
                    $message_obj = $contact_result->choices[0]->message;
                    
                    if (empty($message_obj->toolCalls)){
                        $line_img_arr[] 	=	[  
									       'type' => 'text',  
									       'text' => 'Error: AI did not call the tool (toolCalls is empty). Raw content: ' . $message_obj->content
									    ];
						return $line_img_arr;
                    }

                    $func_args_json = $message_obj->toolCalls[0]->function->arguments;
                    $contact_args = json_decode($func_args_json);

                    if (json_last_error() !== JSON_ERROR_NONE || is_null($contact_args)) {
                         $line_img_arr[] 	=	[  
									       'type' => 'text',  
									       'text' => 'Error: Failed to parse JSON arguments. Raw args: ' . $func_args_json
									    ];
						return $line_img_arr;
                    }

                    if (!isset($contact_args->contact_msg)){
                         $line_img_arr[] 	=	[  
									       'type' => 'text',  
									       'text' => 'Error: Missing contact_msg in arguments.'
									    ];
						return $line_img_arr;
                    }

					$contact_msg 	=	$contact_args->contact_msg;
                    $user_status    =   isset($contact_args->status_for_user) ? $contact_args->status_for_user : '';

					//如果使用語音
					if (isset($main_arg->voice_language)){

						if (mb_strlen($contact_msg) > 300){
							$line_img_arr[]	=	[ 'type' => 'text', 'text' => 'Sorry, text too long for audio generation.' ];
							return $line_img_arr;
						}

						$audio_files 	=	create_audio($system, $client, $uid, $contact_msg, $main_arg->voice_language, $idol);

						if ($audio_files['role'] === 'error'){
							$line_img_arr[]	=	[ 'type' => 'text', 'text' => $audio_files['content'] ];
							return $line_img_arr;
						}

						$line_img_arr[]		=	[  
						                    'type' => 'audio',  
						                    'originalContentUrl' => 'https://'.$_SERVER['HTTP_HOST'].'/'.$audio_files['content'], 
						                    'duration' => $audio_files['duration']
						                ]; 


					} else {
					
						$line_img_arr[] = [  
						    'type' => 'flex',  
						    'altText' => 'Message from '.$self_name,  
						    'contents' => create_msg_box($contact_msg, $idol['avatar'])
						];

					}
					

					require_once './line_msg_send.php';
					$msg_send = sendMessage($target_idol['tokens'], $line_img_arr, $target_uid);

					

					if ($msg_send !== 'OK'){
						$reply_arr[]	=	['type' => 'text', 'text' => $msg_send];
						return $reply_arr;
					}

					//$memo[]			=	['role' => 'assistant', 'content' => '['.$idol['i_name'].'對'.$target_name.'說]'.$contact_msg];
					array_shift($memo);//移除sys_prompt

					//用戶訊息存入connections
					$connect_memo 	=	Array();
					$connect_memo['memo']	=	json_encode($memo);
					$db->where('id', $connect['id'])->update('connections', $connect_memo);

					$reply_arr[] 	=	[  
									          'type' => 'flex',  
									          'altText' => 'Message Sent',  
									          'contents' => create_emotion($idol['end_point'].'_msg.png')
									        ];

					
					//$reply_arr[]	=	['type' => 'contact_someone', 'text' => 'Message Sent'];
					return $reply_arr;

                    } catch (Exception $e) {
                        $data = Array();
                        $data['msg'] = 'Exception in contact_someone: ' . $e->getMessage();
                        $db->insert('err_log', $data);

                        $line_img_arr[] = [
                            'type' => 'text',
                            'text' => 'System Error: ' . $e->getMessage()
                        ];
                        return $line_img_arr;
                    }

				}


				if ($fname === 'think_someone'){
					if (!isset($main_arg->contact_id)){
						$line_img_arr[] 	=	[  
											       'type' => 'text',  
											       'text' => '抱歉，無此聯絡id'
											    ];

						return $line_img_arr;
					}

					$contact =	$db->where('id', $main_arg->contact_id)->getOne('connections');

					$self_name 		=	$contact['uname1'];
					$target_uid 	=	$contact['uid2'];

					if ($contact['uid2'] === $uid){
						 $self_name 	= 	$contact['uname2'];
						 $target_uid 	=	$contact['uid1'];
					}

					$target_user 	=	$db->where('uid', $target_uid)->getOne('ai_member', 'idol');
					$target_idol 	=	$db->where('id', $target_user['idol'])->getOne('idols', 'i_name');


					$memo 	=	Array();

					if (!is_null($contact['memo'])){
						$memo 	=	json_decode($contact['memo'], true);
					}

					$memo[]			=	[ 'role' => 'user', 'content' => '['.$self_name.'說]'.$usr_msg ];

					$contact_data 	=	Array();
					$contact_data['memo']	=	json_encode($memo);
					$db->where('id', $contact['id'])->update('connections', $contact_data);
					

					$sys_prompt     =   [ "role"  => "system", "content" => $idol['default_prompt'].',目前用戶是['.$self_name.'],以下是用戶與聯絡人之間交流的紀錄,參考這些紀錄來給予用戶最好的回覆,注意紀錄內有[xxx說],是為了讓你更好的理解雙方對話,你的回覆不要加上此標籤,在這裡用戶只是單純與你聊聊與這個聯絡人相關的一些事,對方的AI代言人是'.$target_idol['i_name'] ];

					array_unshift($memo, $sys_prompt);

					// $data = Array();
					// $data['msg'] = json_encode($memo);
					// $db->insert('err_log', $data);

					$fname = 'chat_only'; //改成chat_only返回
				}


				if ($fname === 'block_user'){
					$block 	=	Array();
					$block['uid']	=	$uid;
					$db->insert('blocked_ai_member', $block);

					$line_img_arr[] 	=	[  
								       'type' => 'text',  
								       'text' => '經由AI判斷，您的訊息包含了非理性的謾罵、羞辱、或色情與暴力，已終止您的AI Agent服務，如果您認為AI誤判，請聯繫管理團隊'
								    ];

					return $line_img_arr;
				}


				if ($fname ===	'transportation_inquiry'){

					if (!isset($main_arg->query_date) || !isset($main_arg->transport_mode) || !isset($main_arg->start_station) || !isset($main_arg->end_station)){

						$err_msg 	=	'缺少資訊無法進行查詢～';
						if (isset($main_arg->message_reply)){
							$err_msg 	=	$main_arg->message_reply;
						}

						$line_img_arr[]	=	['type' => 'text', 'text' => '起點或終點錯誤'];
						return $line_img_arr;
					}

					if ($main_arg->transport_mode !== '高鐵'){
						$line_img_arr[]	=	['type' => 'text', 'text' => '抱歉！目前只能查詢高鐵時刻'];
						return $line_img_arr;
					}


					$s_client = new \GuzzleHttp\Client();
					$clientId = 'eric.chen1972-74ee2445-500e-4764';
					$clientSecret = '1f2bd57e-2763-43ef-9ae0-86ac7a26228c';

					// Step 1: 取得 Token
					$tokenResponse = $s_client->post('https://tdx.transportdata.tw/auth/realms/TDXConnect/protocol/openid-connect/token', [
					    'form_params' => [
					        'grant_type' => 'client_credentials',
					        'client_id' => $clientId,
					        'client_secret' => $clientSecret,
					    ],
					]);

					$tokenData = json_decode($tokenResponse->getBody(), true);
					$accessToken = $tokenData['access_token'];

					//高鐵查詢
					return thsr_search($s_client, $accessToken, $main_arg);


				}


				//純聊天在此返回
				if ($fname === 'chat_only'){

					$chat_result = $client->chat()->create([
					    'model' => 'chatgpt-4o-latest', //使用聊天效果好的model
					    'messages' => $memo
					]);

					if (isset($chat_result->error->message)){

						$line_img_arr[] 	=	[  
									       'type' => 'text',  
									       'text' => $chat_result->error->message
									    ];

						return $line_img_arr;
					}

					$msg 	=	$chat_result->choices[0]->message->content;

					if (isset($main_arg->emotion)){

						$emotion_img 	=	$idol['end_point'].'_'.$main_arg->emotion.'.png';

						if (file_exists('./idols/'.$emotion_img)){
							$line_img_arr[] 	=	[  
											          'type' => 'flex',  
											          'altText' => $main_arg->emotion,  
											          'contents' => create_emotion($emotion_img)
											        ];
						}
					}

					//如果使用語音
					if (isset($main_arg->voice_language)){

						if (mb_strlen($msg) > 300){
							$line_img_arr[]	=	[ 'type' => 'text', 'text' => '抱歉！超過字元上限，無法製作語音' ];
							$line_img_arr[]	=	[ 'type' => 'text', 'text' => $msg ];
							return $line_img_arr;
						}

						$audio_files 	=	create_audio($system, $client, $uid, $msg, $main_arg->voice_language, $idol);

						if ($audio_files['role'] === 'error'){
							$line_img_arr[]	=	[ 'type' => 'text', 'text' => $audio_files['content'] ];
							return $line_img_arr;
						}

						$line_img_arr[]		=	[  
						                    'type' => 'audio',  
						                    'originalContentUrl' => 'https://'.$_SERVER['HTTP_HOST'].'/'.$audio_files['content'], 
						                    'duration' => $audio_files['duration']
						                ]; 

						return $line_img_arr;

					}


					$line_img_arr[] 	=	[  
									          'type' => 'text',  
									          'text' => $msg
									        ];

					

					return $line_img_arr;
				}



                if ($fname === 'new_friend'){
                    $missing_fields = [];
                    if (is_null($user['sex'])) $missing_fields[] = '性別';
                    if (is_null($user['age'])) $missing_fields[] = '年齡';
                    if (is_null($user['city'])) $missing_fields[] = '居住城市';
                    if (is_null($user['age_from']) || is_null($user['age_to'])) $missing_fields[] = '期望對象年齡範圍';

                    if (count($missing_fields) > 0){
                        $msg = '想要認識新朋友，請先告訴我您的：' . implode('、', $missing_fields);
                        
                        $line_img_arr[]     =   [  
                                               'type' => 'text',  
                                               'text' => $msg
                                            ];
                        
                        // Add to memo so AI knows it asked for this info
                        $memo[] = [
                            'role' => 'assistant',
                            'content' => $msg
                        ];

                        return $line_img_arr;
                    } else {
                        $db->where('id', $user['id'])->update('ai_member', ['new_friend' => 1]);
                        
                        $msg = '太棒了！已經幫您開啟「認識新朋友」功能。如果有適合的對象，我會幫您留意喔！';
                        $line_img_arr[]     =   [  
                                               'type' => 'text',  
                                               'text' => $msg
                                            ];
                        return $line_img_arr;
                    }
                }

                if ($fname === 'no_new_friend'){
                    $db->where('id', $user['id'])->update('ai_member', ['new_friend' => 0]);
                    
                    $msg = '好的，已關閉「認識新朋友」功能。';
                    $line_img_arr[]     =   [  
                                           'type' => 'text',  
                                           'text' => $msg
                                        ];
                    return $line_img_arr;
                }

                if ($fname === 'contact_list'){
                    $relation_str = "目前沒有聯絡人";
                    $cons = $db->where('uid1', $uid)->orWhere('uid2', $uid)->get('connections');
                    
                    if ($db->count > 0){
                        $parts = [];
                        foreach ($cons as $con){
                            $name = $con['uname2'];
                            $rel  = $con['rel2'] ?? '尚未定義';
                            
                            if ($con['uid2'] == $uid){
                                $name = $con['uname1'];
                                $rel  = $con['rel1'] ?? '尚未定義';
                            }
                            $parts[] = "{$name}/{$rel}";
                        }
                        $relation_str = implode("\n", $parts);
                    }
                    
                    $line_img_arr[] = [
                        'type' => 'text',
                        'text' => $relation_str
                    ];
                    
                    $memo[] = [
                        "role" => "function",
                        "name" => "contact_list",
                        "content" => "任務已完成"
                    ];
                    
                    return $line_img_arr;
                }

				$task_function 	=	Array();
				require_once './function_'.$fname.'.php';
				$task_function[]	=	${$fname};

				$func_result = $client->chat()->create([
				    'model' => $model,
				    'messages' => $memo,
				    'functions' => $task_function,
				    'function_call' => ['name' => $fname]
				]);

				if (isset($func_result->error->message)){

					$line_img_arr[] 	=	[  
								       'type' => 'text',  
								       'text' => $func_result->error->message
								    ];

					return $line_img_arr;
				}


				$arg 		=	json_decode($func_result->choices[0]->message->functionCall->arguments);

				if ($fname === 'alarm_clock'){

					// $data = Array();
					// $data['msg'] = json_encode($arg);
					// $db->insert('err_log', $data);

					foreach ($arg->task as $task){
						$data 	=	Array();
						$data['utimer']	=	$task->timer;

						$arr 			=	Array();
						$arr[] 			=	[
										    	"role" 	=> "user",
										   	 	"content" => $usr_msg
											];


						$data['reason']	=	json_encode($arr);
						$data['uid'] 	=	$uid;
						$data['idol']	=	$idol['id'];
						$db->insert('ai_member_events', $data);
						
					}


					$line_img_arr[] 	=	[  
								               'type' => 'alarm_clock',  
								               'text' => $arg->reply_message
								            ];

					return $line_img_arr;

					// $memo[]	=	[
					// 				"role"		=>	"function",
					// 				"name"		=>	"alarm_clock",
					// 				"content" 	=>  "用戶需要的任務與時間已設定完成"
					// 			];

				}


				if ($fname === 'get_myid'){
					$reply_arr 	=	Array();
					$reply_arr[]	=	['type' => 'text', 'text' => $arg->user_id];
					return $reply_arr;
				}

                if ($fname === 'edit_profile'){

                    $data   =   Array();

                    if (isset($arg->sex)) $data['sex'] = $arg->sex;
                    if (isset($arg->age)) $data['age'] = $arg->age;
                    if (isset($arg->city)) $data['city'] = $arg->city;
                    if (isset($arg->long_distance)) $data['long_distance'] = $arg->long_distance;
                    if (isset($arg->age_from)) $data['age_from'] = $arg->age_from;
                    if (isset($arg->age_to)) $data['age_to'] = $arg->age_to;

                    if (count($data) > 0){
                        $db->where('id', $user['id'])->update('ai_member', $data);
                    }

                    $line_img_arr[]     =   [  
                                               'type' => 'text',  
                                               'text' => '個人資料已更新'
                                            ];

                    return $line_img_arr;


                }

                if ($fname === 'add_contact'){

					$reply_arr 	=	Array();

					if ($uid === $arg->contact_id){
						$reply_arr[]	=	['type' => 'text', 'text' => '不要自己加入自己啦'];
						return $reply_arr;
					}



					$contact_user 	=	$db->where('uid', $arg->contact_id)->getOne('ai_member');

					if ($db->count == 0){
						$reply_arr[]	=	['type' => 'text', 'text' => '抱歉無此聯絡人，請對方先加入任何一個 Sweety AI 的 Agent'];

						return $reply_arr;
					}

					if ($user['vip'] == 0){

						$db->where('uid1', $uid)->orWhere('uid2', $uid)->get('connections');

						if ($db->count >= 5){
							$reply_arr[]	=	['type' => 'text', 'text' => '抱歉您最多只能加入6位聯絡人'];

							return $reply_arr;
						}
					}


					$rel_user 	=	$db->where('uid1', $uid)->where('uid2', $arg->contact_id)->getOne('connections');

					if ($db->count == 0){
						$rel_user 	=	$db->where('uid2', $uid)->where('uid1', $arg->contact_id)->getOne('connections');
					}

					if ($db->count > 0){
						$uname 	=	$rel_user['uname2'];
						$u_rel 	=	$rel_user['rel2'];

						if ($contact_user['uid'] ==  $rel_user['uid2']){
							$uname 	=	$rel_user['uname1'];
							$u_rel 	=	$rel_user['rel1'];
						}

						if (is_null($u_rel)) $u_rel 	=	'尚未定義';

						$reply_arr[]	=	['type' => 'text', 'text' => '此ID已在您的聯絡人清單內，名稱為 -'.$uname.'，關係為 -'.$u_rel];
						return $reply_arr;
					}


					$udata				=	Array();
					$udata['uid1']		=	$uid;
					$udata['uid2']		=	$contact_user['uid'];
					$udata['uname1']	=	$user['u_name'];
					$udata['uname2']	=	$contact_user['u_name'];



					$db->insert('connections', $udata);

					$reply_arr[]	=	['type' => 'text', 'text' => '已將此ID加入聯絡人，名稱為 -'.$udata['uname2'].'，關係為 -尚未定義'];


					return $reply_arr;


				}

				if ($fname === 'modify_contact'){

					$reply_arr 	=	Array();

					$modify 	=	$db->where('uid1', $uid)->where('uname2', $arg->old_name)->getOne('connections');

					if ($db->count == 0){
						$modify 	=	$db->where('uid2', $uid)->where('uname1', $arg->old_name)->getOne('connections');
					}

					if ($db->count == 0){
						$reply_arr[]	=	['type' => 'text', 'text' => '抱歉查無此聯絡人，請確定聯絡人名稱正確'];
						return $reply_arr;
					}

					$modify_arr =	Array();

					if ($modify['uid1'] == $uid){
						if (isset($arg->new_name)){
							$modify_arr['uname2']	=	$arg->new_name;
						}
						if (isset($arg->new_relationship)){
							$modify_arr['rel2']	=	$arg->new_relationship;
						}
					} else {
						if (isset($arg->new_name)){
							$modify_arr['uname1']	=	$arg->new_name;
						}
						if (isset($arg->new_relationship)){
							$modify_arr['rel1']	=	$arg->new_relationship;
						}
					}

					$db->where('id', $modify['id'])->update('connections', $modify_arr);

					$reply_arr[]	=	['type' => 'text', 'text' => '聯絡人資訊已更新'];
					return $reply_arr;

				}

				if ($fname === 'delete_contact'){
					$connect 	=	$db->where('id', $arg->contact_id)->getOne('connections', 'id');

					$reply_arr 	=	Array();

					if ($db->count == 0){
						$reply_arr[]	=	['type' => 'text', 'text' => '抱歉～沒有這個聯絡人'];
						return $reply_arr;
					}

					$db->where('id', $arg->contact_id)->delete('connections');
					$db->rawQuery('ALTER TABLE connections AUTO_INCREMENT = 1');

					$reply_arr[]	=	['type' => 'text', 'text' => '聯絡人已刪除'];
					return $reply_arr;

				}


				if ($fname === 'google_search'){

					if (is_null($system['aves_api_key']) || is_null($system['g_search_code'])){
						$line_img_arr[] 	=	[  
									               'type' => 'text',  
									               'text' => '請先至後台進行Google API Key設定'
									            ];

							return $line_img_arr;
					}

					if (!isset($arg->key_array) || count($arg->key_array) === 0){
						$line_img_arr[] 	=	[  
									               'type' => 'text',  
									               'text' => '抱歉，沒有搜尋結果'
									            ];

							return $line_img_arr;
					}

					$key_arr 	=	Array();

					foreach ($arg->key_array as $key){

						$surl 	=	'https://www.googleapis.com/customsearch/v1?key='.$system['aves_api_key'].'&cx='.$system['g_search_code'].'&q=';

						$surl 	.=	$key->key_word;

						if ($key->target == 'image') $surl .= '&searchType=image';

						foreach ($key->filter as $filter){
								$surl 	.=	'&'.$filter;
							}

						$surl 	.=	'&fields=queries(request/searchTerms),items/title,items/snippet,items/link';

						if ($key->target == 'web'){
							$surl .=	',items/pagemap/cse_thumbnail/src,items/pagemap/metatags/og:description';
						}

						if ($key->target == 'image'){
							$surl .=	',items/image/height,items/image/width';
						}

						array_push($key_arr, $surl);

					}

					

					$requestInstance = new GoogleForGPTSerachRequester($key_arr);
					$requestInstance->doRequests();

					$s_array 	=	build_search_result($requestInstance);

					if (!is_array($s_array)){

						$line_img_arr[] 	=	[  
									               'type' => 'text',  
									               'text' => '抱歉，搜尋發生錯誤，請稍後再試一次'
									            ];

						return $line_img_arr;
					}


					if ($arg->key_array[0]->target == 'image' && !empty($uid)){

						$columns 	=	Array();

						foreach ($s_array[0]['items'] as $key=>$item){

							// $img_preview 	=	create_preview($item['link'], 0.4, $key);
							// if (!$img_preview) continue;
							$parse 	=	parse_url($item['link']);
							if ($parse['host'] == $_SERVER['HTTP_HOST']) continue;

							//if (count($columns) >= 4) break; 

							$columns[] = [
									'type' => 'bubble',
							        'body' => [  
							            'type' => 'box',  
							            'layout' => 'vertical',  
							            'contents' => [
							            				[
							            					'type' => 'image',
							            					'url' => $item['link'],
							            					'size' => 'full'
							            				]
							            			  ]
							        			],
							        'size' => 'hecto',
							        'action' => [
							        				'type' => 'uri',
							        				'label' => 'action',
							        				'uri' => $item['link']
							        			]
							    ]; 


						}

						$line_img_arr[] = [  
						    'type' => 'flex',  
						    'altText' => 'Google圖片搜尋',  
						    'contents' => [
						    				'type' => 'carousel',
						    				'contents' => $columns
						    			  ]
						]; 


						// $data = Array();
						// $data['msg'] = json_encode($line_img_arr);
						// $db->insert('err_log', $data);
        

						return $line_img_arr;

					}

					$memo[]	=	[
									"role"		=>	"function",
									"name"		=>	"google_search",
									"content" 	=>  json_encode($s_array, JSON_UNESCAPED_UNICODE)
								];

					

				}


				if ($fname === 'analyze_web'){
					require_once ('./simple_html_dom.php');

					$ch = curl_init();

					curl_setopt($ch, CURLOPT_URL, $arg->web_url);
					curl_setopt($ch, CURLOPT_AUTOREFERER, true);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
					curl_setopt($ch, CURLOPT_VERBOSE, 1);
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
					curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
					curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');

					$html_content = curl_exec($ch);
					$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // 獲取HTTP狀態碼  
					$error = curl_error($ch); // 獲取錯誤信息  

					if ($httpCode == 200) {
						$web_content = str_get_html($html_content);

						$memo[]	=	[
										"role"		=>	"function",
										"name"		=>	"analyze_web",
										"content" 	=>  $web_content->plaintext
									];

					} else {

						$line_img_arr[] 	=	[  
									               'type' => 'text',  
									               'text' => '發生錯誤. HTTP Code: '.$httpCode.' Error: '.$error
									            ];

							return $line_img_arr;
					}

				}


				if ($fname 	===	'analyze_image'){

					if (!isset($arg->img_url)){

						$line_img_arr[] 	=	[  
									               'type' => 'text',  
									               'text' => '發生錯誤，沒有找到圖片'
									            ];

							return $line_img_arr;
					}

					$img_result = $client->chat()->create([
					        'model' => $model,
					        'messages' => [
					            [
					                'role' => 'user',
					                'content' => [
					                    [
					                    	'type' => 'text', 
					                    	'text' => $arg->img_question
					                    ],
					                    [
					                    	'type' => 'image_url', 
					                    	'image_url' => [ 
					                    		'url' => $arg->img_url
					                    	]
					                    ]
					                ]
					            ]
					        ]
					    ]);


					if($func === end($main_arg->function_array)) {

						$line_img_arr[] 	=	[  
									               'type' => 'analyze_image',  
									               'text' => $img_result->choices[0]->message->content
									            ];

						return $line_img_arr;
					}


					$memo[]	=	[
									"role"		=>	"function",
									"name"		=>	"analyze_image",
									"content" 	=>  $img_result->choices[0]->message->content
								];

				}


				if ($fname === 'create_image'){

					// $data = Array();
					// $data['msg'] = json_encode($arg);
					// $db->insert('err_log', $data);

						$response = $client->images()->create([
						    'model' => 'gpt-image-1',
						    'prompt' => $arg->img_description,
						    'n' => 1,
						    'quality' => 'medium',
						    'size' => $arg->img_size
						]);

						$response->created;

						$img_url 	=	'';

						foreach ($response->data as $data) {
							$img_fname =	round(microtime(true) * 1000).'_'.mt_rand(100, 10000).'.png';
							$imageData = base64_decode($data->b64_json);

							file_put_contents('./userfiles/web/images/tmp/'.$img_fname, $imageData);

							$img_url 	=	'https://'.$_SERVER['HTTP_HOST'].'/userfiles/web/images/tmp/'.$img_fname;
						}


						$img_preview 	=	create_preview($img_url, 0.4, mt_rand(100, 10000));

						$line_img_arr[]	=	[
												'type' => 'image',
												'originalContentUrl' => $img_url,
												'previewImageUrl' => $img_preview
											];

						//return $line_img_arr;

						if ($func === end($main_arg->function_array)){
							return $line_img_arr;
						}

						$memo[]	=	[
										"role"		=>	"function",
										"name"		=>	"create_image",
										"content" 	=>  $img_url
									];

				}


				if ($fname === 'edit_image'){

					// $data = Array();
	        		// $data['msg'] = json_encode($arg);
	        		// $db->insert('err_log', $data);

					$img_arr =	Array();

					if (isset($arg->img_arr)){
						if (count($arg->img_arr) > 0){
							foreach ($arg->img_arr as $img){
								$img_arr[]	=	fopen($img->img_url, 'r');
							}
						} else {
							$img_arr[]	=	fopen($img_analyze_url, 'r');
						}
					} else {
						$img_arr[]	=	fopen($img_analyze_url, 'r');
					}


					$response = $client->images()->edit([
					    'model' => 'gpt-image-1',
					    'prompt' => $usr_msg,
					    'image' => $img_arr,
					    'n' => 1,
					    'quality' => 'medium',
					    'size' => $arg->img_size
					]);


					$response->created;

					if (isset($response->error->message)){

						$line_img_arr[] 	=	[  
									       'type' => 'text',  
									       'text' => $response->error->message
									    ];

						return $line_img_arr;
					}

					$img_url 	=	'';

					foreach ($response->data as $data) {
						$img_fname =	round(microtime(true) * 1000).'_'.mt_rand(100, 10000).'.png';
						$imageData = base64_decode($data->b64_json);

						file_put_contents('./userfiles/web/images/tmp/'.$img_fname, $imageData);

						$img_url 	=	'https://'.$_SERVER['HTTP_HOST'].'/userfiles/web/images/tmp/'.$img_fname;

					}

					

					$img_preview 	=	create_preview($img_url, 0.4, mt_rand(100, 10000));

					$line_img_arr[]	=	[
											'type' => 'image',
											'originalContentUrl' => $img_url,
											'previewImageUrl' => $img_preview
										];

					//return $line_img_arr;

					if ($func === end($main_arg->function_array)){
						return $line_img_arr;
					}

					$memo[]	=	[
									"role"		=>	"function",
									"name"		=>	"edit_image",
									"content" 	=>  $img_url
								];

					
				}


				if ($fname === 'google_place'){

					$usr_loc 	=	$db->where('uid', $uid)->getOne('ai_member', 'ST_X(usr_loc) as loc_x, ST_Y(usr_loc) as loc_y');

					if ($db->count == 0 || is_null($usr_loc['loc_x'])){

						$line_img_arr[] 	=	[  
									               'type' => 'text',  
									               'text' => '請先傳送您的位置資訊'
									            ];

						return $line_img_arr;
					}


					if (($place_result = @file_get_contents('https://maps.googleapis.com/maps/api/place/nearbysearch/json?keyword='.$arg->key.'&location='.$usr_loc['loc_x'].'%2C'.$usr_loc['loc_y'].'&radius='.$arg->radius.'&key='.$system['aves_api_key'])) === false) {

					      $error = error_get_last();

					      $line_img_arr[] 	=	[  
					      		                    'type' => 'text',  
					      		                    'text' => $error['message']
					      		               	];

					      return $line_img_arr;


					} 


					// $place_result	=	file_get_contents('https://maps.googleapis.com/maps/api/place/nearbysearch/json?keyword='.$arg->key.'&location='.$usr_loc['loc_x'].'%2C'.$usr_loc['loc_y'].'&radius='.$arg->radius.'&key='.$system['aves_api_key']);

					// $data = Array();
					// $data['msg'] = $place_result;
					// $db->insert('err_log', $data);

					$result_arr 	=	json_decode($place_result);


					if (count($result_arr->results) === 0){
						$line_img_arr[] 	=	[  
							                    	'type' => 'text',  
							                    	'text' => '沒有搜尋結果'  
							               		];

							return $line_img_arr;
					}

					if($func === end($main_arg->function_array) && !empty($uid)) {

						$line_img_arr[]	=	[
												'type' => 'text',
												'text' => $arg->message_reply
											];


						foreach ($result_arr->results as $key=>$item){

							if (!$item->opening_hours->open_now) continue;

							if (count($line_img_arr) >= 5) break;

							if ($item->rating < $arg->rating) continue;


							$line_img_arr[]	=	[
													'type' => 'location',
													'title' => $item->name, 
													'address' => $item->vicinity,
													'latitude' => $item->geometry->location->lat, //緯度  
													'longitude' => $item->geometry->location->lng //經度  
												];

						}

						

						if (count($line_img_arr) === 0){
							$line_img_arr[] 	=	[  
								                    	'type' => 'text',  
								                    	'text' => '沒有符合需求的結果'  
								               		];
						}


						return $line_img_arr;

					}

					$memo[]	=	[
									"role"		=>	"function",
									"name"		=>	"google_place",
									"content" 	=>  $place_result
								];


				}


				if ($fname === 'youtube_search'){
					if (is_null($system['aves_api_key'])){
						$line_img_arr[] 	=	[  
									               'type' => 'text',  
									               'text' => '請先至後台進行Google API Key設定'
									            ];

							return $line_img_arr;
					}

					if (($youtube_result = @file_get_contents('https://www.googleapis.com/youtube/v3/search?part=snippet&maxResults='.$arg->items_count.'&q='.$arg->key.'&type=video&key='.$system['aves_api_key'])) === false) {
						
					      $error = error_get_last();

					      $line_img_arr[] 	=	[  
					      				          'type' => 'text',  
					      				          'text' => $error['message']
					      				        ];

					      	return $line_img_arr;
					} 


					$youtube_arr 	=	json_decode($youtube_result);

					if (count($youtube_arr->items) === 0){

						$line_img_arr[] 	=	[  
										          'type' => 'text',  
										          'text' => '沒有搜尋結果'
										        ];

						return $line_img_arr;
					}

					if($func === end($main_arg->function_array) && !empty($uid)) {

						foreach ($youtube_arr->items as $item){

							$line_img_arr[] = [  
							    'type' => 'flex',  
							    'altText' => $item->snippet->title,  
							    'contents' => [  
							        'type' => 'bubble',  
							        'hero' => [  
							            'type' => 'image',  
							            'url' => $item->snippet->thumbnails->default->url,  
							            'size' => 'full',  
							            'aspectRatio' => '20:13',  
							            'aspectMode' => 'cover',  
							            'action' => [  
							                'type' => 'uri',  
							                'uri' => 'https://www.youtube.com/watch?v='.$item->id->videoId
							            ]  
							        ],  
							        'body' => [  
							            'type' => 'box',  
							            'layout' => 'vertical',  
							            'contents' => [  
							                [  
							                    'type' => 'text',  
							                    'text' => $item->snippet->title,  
							                    'weight' => 'bold',  
							                    'size' => 'xl',  
							                ],  
							                [  
							                    'type' => 'text',  
							                    'text' => $item->snippet->description,  
							                    'wrap' => true,  
							                    'margin' => 'lg',  
							                    'color' => '#666666',  
							                    'size' => 'sm',  
							                ]  
							            ]  
							        ]  
							    ]  
							];  


							if (count($line_img_arr) >= 3) break;
						}

						return $line_img_arr;

					}


					$memo[]	=	[
									"role"		=>	"function",
									"name"		=>	"youtube_search",
									"content" 	=>  $youtube_result
								];

				}

                if ($fname === 'new_friend'){
                    $missing_fields = [];
                    if (is_null($user['sex'])) $missing_fields[] = '性別';
                    if (is_null($user['age'])) $missing_fields[] = '年齡';
                    if (is_null($user['city'])) $missing_fields[] = '居住城市';
                    if (is_null($user['age_from']) || is_null($user['age_to'])) $missing_fields[] = '期望對象年齡範圍';

                    if (count($missing_fields) > 0){
                        $msg = '想要認識新朋友，請先告訴我您的：' . implode('、', $missing_fields);
                        
                        $line_img_arr[]     =   [  
                                               'type' => 'text',  
                                               'text' => $msg
                                            ];
                        
                        // Add to memo so AI knows it asked for this info
                        $memo[] = [
                            'role' => 'assistant',
                            'content' => $msg
                        ];

                        return $line_img_arr;
                    } else {
                        $db->where('id', $user['id'])->update('ai_member', ['new_friend' => 1]);
                        
                        $msg = '太棒了！已經幫您開啟「認識新朋友」功能。如果有適合的對象，我會幫您留意喔！';
                        $line_img_arr[]     =   [  
                                               'type' => 'text',  
                                               'text' => $msg
                                            ];
                        return $line_img_arr;
                    }
                }

                if ($fname === 'no_new_friend'){
                    $db->where('id', $user['id'])->update('ai_member', ['new_friend' => 0]);
                    
                    $msg = '好的，已關閉「認識新朋友」功能。';
                    $line_img_arr[]     =   [  
                                           'type' => 'text',  
                                           'text' => $msg
                                        ];
                    return $line_img_arr;
                }
                


				
                if($func === end($main_arg->function_array)) {

				    // Filter out legacy "role: function" messages to prevent Tools API errors
					// Models using Tools API do not support "role: function" in history
					$clean_memo = [];
					foreach ($memo as $m) {
						if (isset($m['role']) && $m['role'] === 'function') continue;
						// Also ensure content is not null for user/assistant (OpenAI sometimes complains)
						if (isset($m['content']) && is_null($m['content'])) $m['content'] = ''; 
						$clean_memo[] = $m;
					}
					$memo = $clean_memo; // Replace with filtered array

					$result = $client->chat()->create([
					    'model' => 'chatgpt-4o-latest',
					    'messages' => $memo
					]);


				}

			}

		}



		//OpenAI
		if (isset($result->error->message)){

			$line_img_arr[] 	=	[  
								      'type' => 'text',  
								      'text' => $result->error->message
								     ];

			return $line_img_arr;
		}



		$line_img_arr[] 	=	[  
						          'type' => 'text',  
						          'text' => $result->choices[0]->message->content
						        ];

		return $line_img_arr;


		

	}

	//hyperbolic
    function create_voice2($text, $uid, $url, $key, $language) {  
        $audio_client = new \GuzzleHttp\Client();

        // $data = Array();
        // $data['msg'] = $text;
        // $db->insert('err_log', $data);

        try {  
            // 進行 POST 請求  
            $response = $audio_client->post($url, [  
                'headers' => [  
                    'Content-Type' => 'application/json',  
                    'Authorization' => 'Bearer ' . $key  
                ],  
                'json' => [  
                    "text" => $text,  
                    "language" => $language, // 中文 
                    //"voice" => "zh-CN-XiaoyiNeural", // 選擇聲音  
                    "speed" => 0.75 // 語速設置  
                ]  
            ]);

            

            // 解析返回的 JSON 結果  
            $audio_response = json_decode($response->getBody(), true);  
        
            if (isset($audio_response['audio'])) {
               
                $audio_file     =   'userfiles/web/images/tmp/'.time().'_'.$uid.'.mp3';  
                file_put_contents($audio_file, base64_decode($audio_response['audio']));

                $new_content    =   [
                        "role"  => "audio_reply",
                        "content" => $audio_file
                    ];

                return $new_content;
            
            } else {  

                $new_content    =   [
                    "role"  => "error",
                    "content" => "抱歉！，無法取得AUDIO資料 - ".$text
                ];

                return $new_content;
        }  

    } catch (\GuzzleHttp\Exception\RequestException $e) {  
        // 捕獲請求異常

        $new_content    =   [
                "role"  => "error",
                "content" => "抱歉！，語音異常，請稍後再試 (" . $e->getMessage() . ") - ".$text
            ];

            return $new_content;
    }  
}


	//inferless
    function create_voice3($text, $uid, $url, $key, $language) {  
        $audio_client = new \GuzzleHttp\Client();

        // $data = Array();
        // $data['msg'] = $text;
        // $db->insert('err_log', $data);

        try {  
            // 進行 POST 請求  
            $response = $audio_client->post($url, [  
                'headers' => [  
                    'Content-Type' => 'application/json',  
                    'Authorization' => 'Bearer ' . $key  
                ],  
                'json' => [
                    'inputs' => [
                        [
                            'name' => 'text',
                            'shape' => [1],
                            'data' => [$text],
                            'datatype' => 'BYTES',
                        ],
                        [
                            'name' => 'language',
                            'optional' => true,
                            'shape' => [1],
                            'data' => [$language],
                            'datatype' => 'BYTES',
                        ]
                    ],
                ],
            ]);

            

            // 解析返回的 JSON 結果  
            $audio_response = json_decode($response->getBody(), true);  
            $base64Audio = $audio_response['outputs'][0]['data'][0] ?? null;
        
            if (isset($base64Audio)) {

                $audio_file     =   'userfiles/web/images/tmp/'.time().'_'.$uid.'.mp3';  
                file_put_contents($audio_file, base64_decode($base64Audio));

                $new_content    =   [
                        "role"  => "audio_reply",
                        "content" => $audio_file
                    ];

                return $new_content;
            
            } else {  

                $new_content    =   [
                    "role"  => "error",
                    "content" => "抱歉！，無法取得AUDIO資料 - ".$text
                ];

                return $new_content;
        }  

    } catch (\GuzzleHttp\Exception\RequestException $e) {  
        // 捕獲請求異常

        $new_content    =   [
                "role"  => "error",
                "content" => "抱歉！，語音異常，請稍後再試 - ".$text
            ];

            return $new_content;
    }  
}


	function create_audio($system, $client, $uid, $msg, $language, $idol){

		$audio_files    =   [];
		$dur            =   1000;

		if ($idol['id']	==	2 || $idol['id'] == 5){
			$response   =   $client->audio()->speech([
			                                'model' => 'tts-1-1106',
			                                'input' => $msg,
			                                'voice' => 'onyx'
			                            ]);

			$audio_file     =   'userfiles/web/images/tmp/'.time().'_'.$uid.'.mp3';
			file_put_contents($audio_file, $response);

			$audio_files    =   [
			        "role"  => "audio_reply",
			        "content" => $audio_file
			    ];

		} else {
			$audio_files    =   create_voice2($msg, $uid, $system['tts_url'], $system['tts_apikey'], $language);

			if ($audio_files['role'] === 'error') return $audio_files;
		}

		 $audio_files['duration'] 		= get_audio_length($audio_files['content']) * 1000;

		 return $audio_files;
	}


    // function create_audio($system, $client, $uid, $msg, $language){
    //     $audio_files    =   [];
    //     $dur            =   1000;

    //     if (!is_null($system['tts_url']) && !is_null($system['tts_apikey'])){

    //         if ($system['tts_url'] === 'https://api.hyperbolic.xyz/v1/audio/generation'){

    //         	$audio_files    =   create_voice2($msg, $uid, $system['tts_url'], $system['tts_apikey'], $language);
    //             $dur    =   2000;
    //         } else {

    //         	$audio_files    =   create_voice3($msg, $uid, $system['tts_url'], $system['tts_apikey'], $language);

    //         }

    //         if ($audio_files['role'] === 'error') return $audio_files;

    //     } else {

    //         //OpenAI tts
    //         $response   =   $client->audio()->speech([
    //                                         'model' => 'tts-1',
    //                                         'input' => $msg,
    //                                         'voice' => 'nova'
    //                                     ]);

    //         $audio_file     =   'userfiles/web/images/tmp/'.time().'_'.$uid.'.mp3';
    //         file_put_contents($audio_file, $response);

    //         $audio_files    =   [
    //                 "role"  => "audio_reply",
    //                 "content" => $audio_file
    //             ];


    //     }

    //     // require_once './mp3file.php';
    //     // $mp3file    =    new MP3File($audio_files['content']);
    //     // $audio_files['duration']   =    $mp3file->getDurationEstimate() * $dur;

    //     $audio_files['duration'] 		= get_audio_length($audio_files['content']) * 1000;



    //     // $data = Array();
    //     // $data['msg'] = json_encode($audio_files);
    //     // $db->insert('err_log', $data);

    //     return $audio_files;
    // }

    function create_emotion($img){
        $arr   =   json_decode('{
								  "type": "bubble",
								  "size": "hecto",
								  "body": {
								    "type": "box",
								    "layout": "horizontal",
								    "contents": [
								      {
								        "type": "image",
								        "url": "https://sweety.tw/idols/'.$img.'",
								        "aspectMode": "cover",
								        "size": "full"
								      }
								    ],
								    "justifyContent": "center",
								    "alignItems": "center",
								    "backgroundColor": "#fff7e8"
								  }
								}', true);

        return $arr;
    }


    function create_msg_box($msg, $avatar){
        $arr   =   json_decode('{
								  "type": "bubble",
								  "size": "giga",
								  "body": {
								    "type": "box",
								    "layout": "horizontal",
								    "contents": [
								      {
								        "type": "box",
								        "layout": "vertical",
								        "contents": [
								          {
								            "type": "image",
								            "url": "'.$avatar.'",
								            "align": "start",
								            "margin": "none",
								            "flex": 1
								          }
								        ]
								      },
								      {
								        "type": "text",
								        "text": "",
								        "flex": 5,
								        "margin": "xl",
								        "size": "sm",
								        "align": "start",
								        "wrap": true,
								        "weight": "bold"
								      }
								    ],
								    "alignItems": "flex-start"
								  }
								}', true);

        $arr['body']['contents'][1]['text'] = $msg;
        return $arr;
    }


    function get_audio_length($file){
    	$handle = fopen($file, 'rb');  
    	
    	// 跳過 ID3 標籤  
    	fseek($handle, 0);  
    	$header = fread($handle, 1024);  
    	
    	// 查找 MPEG 音訊幀  
    	$duration = 0;  
    	$sample_rates = [44100, 48000, 32000, 22050, 24000, 16000];  

    	for ($i = 0; $i < strlen($header) - 4; $i++) {  
    	    if (ord($header[$i]) == 0xFF && (ord($header[$i+1]) & 0xE0) == 0xE0) {  
    	        // 解析採樣率  
    	        $sample_rate_index = (ord($header[$i+2]) & 0x0C) >> 2;  
    	        
    	        if (isset($sample_rates[$sample_rate_index])) {  
    	            // 獲取文件大小  
    	            $file_size = filesize($file);  
    	            
    	            // 基於標準 MP3 編碼的估算  
    	            $bitrate = 128000; // 預設 128kbps  
    	            $duration = ($file_size * 1.4) / $bitrate;  
    	            
    	            break;  
    	        }  
    	    }  
    	}  
    	
    	fclose($handle);  

    	return round($duration);
    }




    //高鐵查詢
    function thsr_search($s_client, $accessToken, $main_arg) {
	    $today = new DateTime();
	    $stations = ['南港', '台北', '板橋', '桃園', '新竹', '苗栗', '台中', '彰化', '雲林', '嘉義', '台南', '左營'];
	    $result_arr = [];

	    if (!in_array($main_arg->start_station, $stations) || !in_array($main_arg->end_station, $stations)) {
	        return [['type' => 'text', 'text' => '起點或終點錯誤']];
	    }

	    $direction = array_search($main_arg->start_station, $stations) > array_search($main_arg->end_station, $stations) ? 1 : 0;

	    try {
	        $response = $s_client->get("https://tdx.transportdata.tw/api/basic/v2/Rail/THSR/DailyTimetable/TrainDate/{$main_arg->query_date}", [
	            'headers' => [
	                'Authorization' => "Bearer {$accessToken}",
	            ],
	            'query' => ['$format' => 'JSON'],
	        ]);
	    } catch (\GuzzleHttp\Exception\RequestException $e) {
	        return [['type' => 'text', 'text' => '抱歉，查詢發生錯誤']];
	    }

	    $list_data = json_decode($response->getBody(), true);
	    $train_list = [];

	    foreach ($list_data as $item) {
	        if ($item['DailyTrainInfo']['Direction'] != $direction) continue;

	        $time_list = [];

	        foreach ($item['StopTimes'] as $station) {
	            if ($station['StationName']['Zh_tw'] == $main_arg->start_station) {
	                $start_time_str = $station['DepartureTime'] ?? $station['ArrivalTime'];

	                if ($main_arg->query_date === $today->format('Y-m-d')) {
	                    $start_time_obj = new DateTime("{$main_arg->query_date} {$start_time_str}");
	                    if ($start_time_obj <= $today) continue 2; // 跳出這個班次
	                }

	                $time_list['start'] = $main_arg->start_station;
	                $time_list['start_time'] = $start_time_str;
	            }

	            if ($station['StationName']['Zh_tw'] == $main_arg->end_station) {
	                $time_list['end'] = $main_arg->end_station;
	                $time_list['end_time'] = $station['ArrivalTime'] ?? '';
	                $time_list['scount'] = count($item['StopTimes']);
	            }
	        }

	        if (isset($time_list['start_time'], $time_list['end_time'])) {
	            $train_list[] = $time_list;
	        }
	    }

	    if (empty($train_list)) {
	        return [['type' => 'text', 'text' => '抱歉，沒有查詢到班次']];
	    }

	    $list_msg = '';
	    foreach ($train_list as $item) {
	        $list_msg .= "{$item['start_time']} {$item['start']} - {$item['end_time']} {$item['end']} - 共 {$item['scount']} 站\n";
	    }

	    return [['type' => 'text', 'text' => $list_msg]];
}


?>