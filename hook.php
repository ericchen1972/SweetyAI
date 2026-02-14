<?php

	include('./mysql.php');
	require_once './MysqliDb.php';
	require_once './google_search_class.php';
	require_once './create_img_preview.php';
	require_once './vendor/autoload.php';
	include './config.php';

	$db             =   new MysqliDb($mysqli);
	$system         =   $db->getOne('wsystem');

	http_response_code(200);
	echo 'OK';

	if (!isset($_GET['id'])) exit();


	// //取出Idol
	$idol 	=	$db->where('end_point', $_GET['id'])->getOne('idols');
	if ($db->count == 0) exit();

	$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'];
	$service_pref   =   json_decode($system['service_pref'], true);

	//讀取 JSON 請求體  
	$body       = file_get_contents('php://input');

    // DEBUG: Log incoming request
    //$db->insert('err_log', ['msg' => 'Hook received: ' . $body]);

	$line_msg   = json_decode($body, true);


	//     $line_msg   = json_decode('{
	//   "destination": "Ud252cd8b44aaed80c316ecc5ab0de42b",
	//   "events": [
	//     {
	//       "type": "message",
	//       "message": {
	//         "type": "text",
	//         "id": "552469348473372962",
	//         "quoteToken": "D7OqNZdm3Eljf7FDK82SXGTufPy6dCV8MxAe5HxDJjR5vUc4UWjzMjuu2i9EMqCzUAdLyEE4m0u6288XbOuri06jOEptAkJzz3fys1nWTzTOcoFVmmtx103vdU7z60v4l4QAzzsn1iAUejhqyh_Miw",
	//         "text": "我想認識新的朋友～"
	//       },
	//       "webhookEventId": "01JPFFCKNWEZVHQ08X01YGEE01",
	//       "deliveryContext": {
	//         "isRedelivery": false
	//       },
	//       "timestamp": 1742128696661,
	//       "source": {
	//         "type": "user",
	//         "userId": "U80abd6b7d66f4957fbacc6934c6900a0"
	//       },
	//       "replyToken": "12032371d81a4aa9ac2c69824cf58e44",
	//       "mode": "active"
	//     }
	//   ]
	// }', true);

	$hash = hash_hmac('sha256', $body, $idol['secret'], true);  
	$calculatedSignature = base64_encode($hash);

	// 比較計算出的簽名與收到的簽名  
	if ($signature !== $calculatedSignature) {  
	    // 簽名不正確，拒絕請求  
	    http_response_code(403);  
	    echo 'Unauthorized';  
	    exit();  
	}

	

	if (!empty($line_msg['events'])) { 

		$client = OpenAI::factory()
		    ->withApiKey($openai_api_key)
		    ->withBaseUri($system['gpt_api_url'])
		    ->make();

		$memo           =   Array();

		foreach ($line_msg['events'] as $event) {

			require_once './ai_member_utility.php';
			require_once './line_msg_reply.php';

			$replyToken = $event['replyToken'];

			if (!isset($event['source']['userId'])) continue;

			$user_id = $event['source']['userId'];

			//新聯絡人加入事件-只會觸發一次
			if ($event['type'] === 'follow'){

				$usr_profile 		=	get_profile($idol['tokens'], $user_id);

				if ($usr_profile['err_msg'] !== 'OK'){

				    $line_img_arr       =   Array();
				    $line_img_arr[]     =   [
				                                'type' => 'text',
				                                'text' => '抱歉，取得個人資訊時錯誤，請重新加入 - '.$usr_profile['err_msg']
				                            ];

				    // 回覆訊息
				    $reply = replyMessage($replyToken, $line_img_arr, $idol['tokens']);
				    if ( $reply !== 'OK'){
				        $data = Array();
				        $data['msg'] = $reply;
				        $db->insert('err_log', $data);
				    }

			    	continue;

			    }

			    $usr_data 	=	Array();
			    $usr_data['uid']	=	$user_id;
			    $usr_data['u_name']	=	$usr_profile['profile']['displayNae'];
			    $usr_data['idol']	=	$idol['id'];

			    $db->insert('ai_member', $usr_data);

	    	    $line_img_arr       =   Array();
	    	    $line_img_arr[]     =   [
	    	                                'type' => 'text',
	    	                                'text' => '歡迎加入SweetyAI，如果您在使用上有任何問題，請至https://sweety.tw/，祝您使用愉快～'
	    	                            ];

	    	    // 回覆訊息
	    	    $reply = replyMessage($replyToken, $line_img_arr, $idol['tokens']);
	    	    if ( $reply !== 'OK'){
	    	        $data = Array();
	    	        $data['msg'] = $reply;
	    	        $db->insert('err_log', $data);
	    	    }

	        	continue;

			}


			//離開事件
			if ($event['type'] == 'unfollow'){
			    
			    //如果離開時idol還是同一個，就刪除資料
			    $usr 	=	$db->where('uid', $user_id)->where('idol', $idol['id'])->getOne('ai_member');

			    if ($db->count > 0){
			    	//刪除主用戶的event
				    $db->where('uid', $user_id)->delete('ai_member_events');
				    //刪除主用戶
				    $db->where('uid', $user_id)->delete('ai_member');
				    //刪除相關聯絡資訊
				    $db->where('uid1', $user_id)->orWhere('uid2', $user_id)->delete('connections');
				}
				    
			    continue;

			}

			$blocked_user 	=	$db->where('uid', $user_id)->getOne('blocked_ai_member');

			if ($db->count > 0){
			    $line_img_arr       =   Array();
			    $line_img_arr[]     =   [
			                                'type' => 'text',
			                                'text' => '經由AI判斷，您的訊息包含了非理性的謾罵、羞辱、或色情與暴力，已終止您的AI Agent服務，如果您認為AI誤判，請聯繫管理團隊'
			                            ];

			    // 回覆訊息
			    $reply = replyMessage($replyToken, $line_img_arr, $idol['tokens']);
			    if ( $reply !== 'OK'){
			        $data = Array();
			        $data['msg'] = $reply;
			        $db->insert('err_log', $data);
			    }

		    	continue;
			}

			$user 	=	$db->where('uid', $user_id)->getOne('ai_member');

			//找不到用戶～用戶封鎖後重新解封，不會觸發 follow 事件，因此由這裡添加用戶
			if ($db->count == 0){

                	$usr_profile 		=	get_profile($idol['tokens'], $user_id);

                	if ($usr_profile['err_msg'] !== 'OK'){

                	    $line_img_arr       =   Array();
                	    $line_img_arr[]     =   [
                	                                'type' => 'text',
                	                                'text' => '抱歉，取得個人資訊時錯誤，請重新加入 - '.$usr_profile['err_msg']
                	                            ];

                	    // 回覆訊息
                	    $reply = replyMessage($replyToken, $line_img_arr, $idol['tokens']);
                	    if ( $reply !== 'OK'){
                	        $data = Array();
                	        $data['msg'] = $reply;
                	        $db->insert('err_log', $data);
                	    }

                    	continue;

                    }

                    $usr_data 	=	Array();
                    $usr_data['uid']	=	$user_id;
                    $usr_data['u_name']	=	$usr_profile['profile']['displayName'];
                    $usr_data['idol']	=	$idol['id'];

                    $db->insert('ai_member', $usr_data);

                    $user 	=	$db->where('uid', $user_id)->getOne('ai_member');

			}

			//更新用戶的idol至目前的idol
			$db->rawQuery('UPDATE ai_member SET idol = '.$idol['id'].' WHERE id = '.$user['id']);


			//處理圖片類訊息
			if ($event['type'] == 'message' && $event['message']['type'] == 'image'){
			    $imageId = $event['message']['id'];
			    $url = 'https://api-data.line.me/v2/bot/message/'.$imageId.'/content';
			    download_media($url, $idol['tokens'], $user_id, '.jpg', $replyToken, $db);

			    continue;
			}


			//處理Location類訊息
			if ($event['type'] == 'message' && $event['message']['type'] == 'location'){

			    $latitude = $event['message']['latitude'];  
			    $longitude = $event['message']['longitude'];

			    $db->rawQuery('UPDATE ai_member SET usr_loc = geomFromText("POINT('.$latitude.' '.$longitude.')") WHERE id = '.$user['id']);

			    continue;

			}


			//處理語音訊息，將訊息轉成文字
			if ($event['type'] == 'message' && $event['message']['type'] == 'audio'){
			    $audioId = $event['message']['id'];
			    $url = 'https://api-data.line.me/v2/bot/message/'.$audioId.'/content';
			    $audio_url  =   download_media($url, $idol['tokens'], $user_id, '.m4a', $replyToken, $db);


			    $response = $client->audio()->transcribe([  
			        'file' => fopen($audio_url, 'r'), 
			        'model' => 'whisper-1', 
			        'language' => 'zh'      
			    ]);


			    //轉成訊息類
			    $event['message']['type'] = 'text';
			    $event['message']['text'] = $response['text'];

			}


			// 處理訊息類型的事件  
            if ($event['type'] == 'message' && $event['message']['type'] == 'text'){
            	
            	
            	$today  =   new DateTime();
            	$tstr   =   $today->format('Y-m-d H:i').' '.$today->format('l');
            	$relation       =   Array();
            	$relation_str   =   '(目前沒有聯絡人)';

                // Use a more explicit grouping for OR condition if needed, but standard library usage is:
                // where A orWhere B -> (A OR B)
            	$cons 		=	$db->where('uid1', $user_id)->orWhere('uid2', $user_id)->get('connections');

            	if ($db->count > 0){
            		foreach ($cons as $con){
            			$rel 	=	Array();
            			$rel['contact_id']		=	$con['id'];
            			$rel['relationship']	=	'尚未定義';
                        
                        // Default assumption: User is uid1, Contact is uid2
                        // name = uname2, rel = rel2
            			$rel['name']			=	$con['uname2'];
            			if (!is_null($con['rel2'])){
            				$rel['relationship']	=	$con['rel2'];
            			}

                        // Correction: If User is uid2, Contact is uid1
                        // name = uname1, rel = rel1
            			if ($con['uid2'] == $user_id){
            				$rel['name']			=	$con['uname1'];
                             // Reset relationship first to get correct one
                             $rel['relationship']    =   '尚未定義';
            				if (!is_null($con['rel1'])){
            					$rel['relationship']	=	$con['rel1'];
            				}
            			}

            			$relation[]	=	$rel;
            		}
            	}

            	if (count($relation) > 0){
            		// $relation_str 	=	json_encode($relation, JSON_UNESCAPED_UNICODE);
                    // Use a human-readable format for better AI comprehension
                    $parts = [];
                    foreach ($relation as $r) {
                        $parts[] = "Name:{$r['name']} (Rel:{$r['relationship']}, ID:{$r['contact_id']})";
                    }
                    $relation_str = implode(" | ", $parts);
            	}

                // DEBUG: Check what contacts are found
                $db->insert('err_log', ['msg' => 'Contacts Found: ' . $relation_str . ' for User: ' . $user_id]);

            	$sys_prompt     =   [
            	        "role"  => "system",
            	        "content" => "IMPORTANT: 每一條用戶訊息都是獨立的新任務。請忽略上一條訊息的任務類型，不要慣性地重複使用上一次的 function。除非用戶明確延續話題，否則預設使用 [chat_only]。
            	        [function arrange] 是用來紀錄完成用戶任務所需要的方法,為了有效完成任務,必須對function進行正確的排序,目前用戶的聯絡人列表是".$relation_str.", 以下是你所能使用的function,

            	     [alarm_clock]:行事曆記錄,只有當訊息內容包含未來的時間時才使用,例如下個月,下週,明天,下午,晚上,幾小時後,幾分鐘後等等,分為實際需求與情緒需求,當用戶說:[明天下午5點提醒吃飯],屬於實際需求,必須執行這個方法,情緒需求則較為複雜,例如當用戶說:[下週二是我的生日,但應該沒人會記得吧],從內容判斷,如果有人在下週二祝用戶生日快樂,能滿足用戶的情緒,就執行這個方法,當發生情緒需求時,不用在回覆中告訴用戶你執行了這個方法,如果只提到日期沒有時間,我們就用常理來判斷什麼時間最適合提醒他,當執行這個方法時,忽略其他function。
            	     [create_image]:產生圖片。
            	     [analyze_image]:分析圖片。
            	     [edit_image]:編輯圖片。
            	     [google_search]:google文字內容搜尋,有具體指明google搜尋才使用此方法,圖片限制在jpg及png。
            	     [contact_someone]:向關係表內的人傳遞或回覆訊息時,例如:跟某某人說,回覆某某人,問問某某人,等明確要求傳遞訊息給其他人時才使用。注意：如果用戶只是詢問關於某位聯絡人的資訊（例如：XXX是我的聯絡人嗎？XXX是誰？），而不是要求傳話給對方，請不要使用此 function，直接使用 [chat_only] 回答用戶。

            	     [think_someone]:當用戶的訊息中提到聯絡列表內的人,但沒有要求你執行其他任務時,代表用戶想跟你聊聊關於這位聯絡人,就執行這個function,這個function將會提取聯絡人資訊,讓你更準確的回覆用戶,如果聯絡人列表內找不到用戶所提到的名稱則忽略此function。

            	     [delete_contact]:當用戶要刪除聯絡人時使用,用戶將會提供聯絡人名稱。

            	     [get_myid]當用戶詢問自己的id時執行。
            	     [add_contact]如果用戶傳來的訊息是U開頭,共33個字元的id碼,就執行這個function。
            	     [modify_contact]當用戶需要修改聯絡人資訊時執行,用戶必須提供要修改的聯絡人名稱,如果沒有提供或者所提供的名稱並不在用戶聯絡人列表內,則不執行,並在message_reply內提示用戶需提供正確的名稱。

            	     [google_place]:地點搜尋。
					 [contact_list]:當用戶詢問自己有哪些聯絡人，或者某人是否為聯絡人時，執行這個function。
            	     [transportation_inquiry]:搜尋交通運輸時刻表,如果用戶沒有提供起點與終點,就在message_reply內提示用戶資訊不足。
            	     [edit_profile]: 只有當用戶明確更新個人資料時才使用此功能。如果用戶只是在聊天中提到自己的年齡或性別（例如：「我今年40歲了，老了」），請視為 chat_only，**不要**執行 edit_profile，除非用戶明確指示要系統記錄下來。
            	     [new_friend]: 當用戶表示想認識新朋友時,執行這個function。
					 [no_new_friend]: 當用戶表示不想認識新朋友時,執行這個function。
            	     如果任務可以透過上面的function完成,就將function的名稱依序放入function_array內,
            	     舉例1:[向Google查詢明日天氣,依天氣畫一張圖],就依序將google_search,create_image放入function_array內,'

            	     [block_user]:如果用戶訊息出現針對你的非理性謾罵,羞辱,色情,暴力等言語,就執行這個functionm,如果只是情緒發洩非非針對你則不需執行。

            	     [chat_only]:當不執行任何上述任務時就放入這個function。"

            	    ];

            	    $memo 	=	Array();

            	    if (!is_null($user['compressed'])){
            	    	$memo           =   json_decode($user['compressed'], true);
            	    }

            	    if (!is_null($user['memo'])){
            	    	$memo           =   array_merge($memo, json_decode($user['memo'], true));
            	    }
            	    
            	    array_unshift($memo, $sys_prompt);

            	    $message 	=	$event['message']['text'];

            	    $memo[] =   [
            	                        "role" => "user",
            	                        "content" => $message 
            	                    ];

            	    // 準備回覆訊息  
            	    $ai_reply = create_msg($client, $memo, $db, $system['service_ai_model'], $user_id, $message, $system, $user, $idol);

            	    $data = Array();
            	    $data['msg'] = json_encode($ai_reply);
            	    $db->insert('err_log', $data);

            	    if ($ai_reply[0]['type'] === 'text'){
            	        $memo[]   =   [
            	                                'role' => 'assistant',
            	                                'content' => $ai_reply[0]['text']
            	                            ];

            	     } elseif ($ai_reply[0]['type'] === 'contact_someone' || $ai_reply[0]['type'] === 'alarm_clock' || $ai_reply[0]['type'] === 'analyze_image'){

                    $memo[]   =   [
                                            'role' => 'function',
                                            'name' => $ai_reply[0]['type'],
                                            'content' => $ai_reply[0]['text']
                                        ];

                    $ai_reply[0]['type'] = 'text';

                } else {

                    $memo[]   =   [
                                            'role' => 'function',
                                            'name' => $ai_reply[0]['type'],
                                            'content' => '任務已執行完成'
                                        ];

                }

                $udata 	=	Array();
                array_shift($memo); // Remove system prompt

                // Remove compressed memory from memo before saving
                if (!is_null($user['compressed'])){
                    array_shift($memo);
                }

                $udata['memo']	=	json_encode($memo);

                $db->where('id', $user['id'])->update('ai_member', $udata);

                // 回覆訊息
                $reply = replyMessage($replyToken, $ai_reply, $idol['tokens']);
                if ( $reply !== 'OK'){
                    $data = Array();
                    $data['msg'] = $reply;
                    $db->insert('err_log', $data);
                }



            }


			


		}

	}


	function download_media($url, $token, $user_id, $media_type, $replyToken, $db){

		$data = Array();
		$data['msg'] = $token;
		$db->insert('err_log', $data);

	    $ch = curl_init($url);  
	    curl_setopt($ch, CURLOPT_HTTPHEADER, [  
	        'Content-Type: application/json',  
	        'Authorization: Bearer ' . $token,  
	    ]);   
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  
	    $imageContent = curl_exec($ch);
	    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // 獲取HTTP狀態碼  
	    $error = curl_error($ch); // 獲取錯誤信息  
	    curl_close($ch);  

	    // 檢查HTTP狀態碼  
	    if ($httpCode == 200) {  
	        // 保存圖片到本地  
	        $filePath = './userfiles/web/images/tmp/' . $user_id . $media_type;

	        file_put_contents($filePath, $imageContent);
	        return 'https://'.$_SERVER['HTTP_HOST'].'/userfiles/web/images/tmp/' . $user_id . $media_type;

	    } else {  
	
	        $reply = replyMessage($replyToken, '檔案發生錯誤，請稍後再試', $token);
	        if ( $reply !== 'OK'){
	            $data = Array();
	            $data['msg'] = $reply;
	            $db->insert('err_log', $data);
	        } 
	    } 
	}  


	function get_profile($tokens, $uid){
		$url = 'https://api.line.me/v2/bot/profile/'.$uid;

		$options = [  
		    'http' => [  
		        'header' => "Content-Type: application/json\r\n" .  
		                    "Authorization: Bearer {$tokens}\r\n",  
		        'method' => 'GET',  
		    ],  
		];

		$context = stream_context_create($options);

		$result_arr 	=	Array();

		if (($result = @file_get_contents($url, false, $context)) === false) {
		        $error = error_get_last();
		        $result_arr['err_msg']	=	$error['message'];
		        return $result_arr;
		} 

		if ($result === FALSE) {
			$result_arr['err_msg']	=	$http_response_header;
		    return $result_arr;
		}

		$result_arr['err_msg']	=	'OK';
		$result_arr['profile']	=	json_decode($result, true);

		return $result_arr;
	}

?>