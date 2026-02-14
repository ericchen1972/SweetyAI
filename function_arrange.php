<?php

	$arrange 	=	[

		                	'name' => 'arrange',
		                	'description' => '目前的時間是'.$tstr.',這是用來規劃任務執行所需要的方法,
		                	contact_someone方法代表用戶要求你傳遞訊息給其他人,如果需要使用這個方法，除了將contact_someone放入function_array內,必須查找用戶的聯絡人列表,取得列表內的contact_id並填入contact_id欄位內,列表內將會有聯絡人的名稱以及關係,用戶可以使用名稱或關係來指定要聯絡的對象,例如聯絡人名稱是[小蘭]關係是[老婆],當用戶說:問問我老婆,也是指向[小蘭]這位聯絡人,如果無法在聯絡人列表內找到用戶想聯繫的對象就忽略contact_someone,並在message_reply內告知用戶查無此聯絡人,如果用戶的聯絡人只有1個時,用戶可用第三人稱來替代名稱,例如:[跟他說...],或[告訴他..]等都代表唯一的那個聯絡人,
                            **注意：嚴禁濫用此 function。** 只有在用戶明確指示「發送」、「告訴」、「問」某人，且內容是要傳遞給對方的訊息時才使用。如果用戶只是單純陳述個人狀態（如：「我正在煮飯」）、講笑話、或發送與該聯絡人無關的內容，**絕對不要**使用 contact_someone，請改用 chat_only。
		                	think_someone方法代表用戶的訊息中提及到了某位聯絡人但並沒有要求你傳遞訊息,同樣查找聯絡人列表並將id填入contact_id,
		                	如果執行chat_only純聊天,就分析回覆的內容所需要的情緒,這些情緒值包含
		                	praise,apology,comfort,anger,shyness 等5種,選擇合適的填入emotion內,注意只有在語意非常需要時才填入emotion,不需要情緒就忽略此參數,
		                	如果用戶希望將文字轉換成語音,例如用戶說:用唸的...,就在voice_language內填入用戶的語系,目前支援的語系有[EN,ZH,FR,JP,KR],沒有指定語系或語系不支援則填入ZH,如果用戶沒有語音需求則忽略這個參數。
		                	如果用戶需要查詢交通運輸時刻表,就將運輸方式填入transport_mode內,可接受的值有[高鐵,台鐵,飛機,公車],用戶必須提供[起點]填入start_station,[終點]填入end_station,如果用戶有提供日期,就將日期以Y-m-d格式填入query_date,如果用戶以口語方式提供日期,例如明天,後天,下週三等,就依目前時間計算出正確日期後填入query_date,如果用戶沒有提供日期就填入目前日期,當用戶所提供資訊不足時,在message_reply內提示用戶補充資訊,否則就忽略message_reply,
		                	[edit_profile]: 只有當用戶明確表示「我要更新資料」、「修改個人檔案」、「設定我的年齡/性別」等意圖時才使用此功能。如果用戶只是在聊天中提到自己的年齡或性別（例如：「我今年40歲了，老了」），請視為 chat_only，**不要**執行 edit_profile，除非用戶明確指示要系統記錄下來。',
		                	'parameters' => [
				                            'type' => 'object',
				                            'properties' => [
				                                'function_array' => [
				                                	'type' => 'array',
				                                	'description' => '紀錄function的陣列',
				                                	'items' => [
				                                		'type' => 'object',
				                                			'properties' => [
				                                				'function_name' => [
				                                				    'type' => 'string',
				                                				    'description' => '記錄function名稱'
				                                				]
				                                			],
				                                			'required' => ['function_name']
				                                	]

				                                ],
				                                'contact_id' => [
				                                	'type' =>	'string',
				                                	'description' => '聯絡對象的id'
				                                ],
				                                'voice_language' => [
				                                	'type' =>	'string',
				                                	'description' => '語音使用語系,預設值ZH,無語音需求則忽略'
				                                ],
				                                'emotion' => [
				                                	'type' =>	'string',
				                                	'description' => '情緒參數'
				                                ],
				                                'start_station' => [
				                                	'type' =>	'string',
				                                	'description' => '交通運輸起點'
				                                ],
				                                'end_station' => [
				                                	'type' =>	'string',
				                                	'description' => '交通運輸終點'
				                                ],
				                                'transport_mode' => [
				                                	'type' =>	'string',
				                                	'description' => '交通運輸類型'
				                                ],
				                                'query_date' => [
				                                	'type' =>	'string',
				                                	'description' => '查詢班次日期'
				                                ],
				                                'message_reply' => [
				                                	'type' =>	'string',
				                                	'description' => '回覆給用戶的訊息'
				                                ]
				                            ],

				                        ]


				        ];



?>