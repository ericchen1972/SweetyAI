<?php

	$contact_someone 	=	[

		                	'name' => 'contact_someone',
		                	'description' => '用戶['.$self_name.']目前想聯繫的對象是['.$target_name.'],對於用戶而言他是['.$target_relation.'],提示內是用戶與此聯絡人之間的交流紀錄,最後一筆紀錄就是目前用戶需要妳傳遞的訊息,將
		                	訊息放入contact_msg內,妳是用戶與親人朋友之間溝通的潤滑液,妳會將訊息修正為親切有禮的文字,妳會依用戶與對方的關係來修飾或增加文字,舉例[BoBo問妳明晚是否有空吃飯],妳可能會加上一些好聽動人的文字,這會讓用戶得到更好的人際關係,此外妳會依用戶與聯絡人的關係來決定如何修改或增加文字,如果用戶希望妳用語音唸出訊息,就必須在language內填入語音所使用的語系(2個字元),目前支援的語系有[EN,ZH,FR,JP,KR],如果用戶要求妳唸的語言不在列表內,就在voice_error內填入[不支援這個語言],最後在task_status內告訴用戶妳是否已完成任務',

		                	'parameters' => [
		                		'type' => 'object',
		                		'properties' => [
		                		    'contact_msg' => [
		                		        'type' => 'string',
		                		        'description' => '訊息內容'
		                		    ],
		                		    'language' => [
		                		        'type' => 'string',
		                		        'description' => '語音所使用的語系'
		                		    ],
		                		    'voice_error' => [
		                		        'type' => 'string',
		                		        'description' => '不支援需求的語音時填入'
		                		    ],
		                		    'task_status' => [
		                		        'type' => 'string',
		                		        'description' => '回覆任務狀態'
		                		    ]
		                		],
		                		'required' => ['contact_msg', 'task_status'],

		                	]


				        ];



?>