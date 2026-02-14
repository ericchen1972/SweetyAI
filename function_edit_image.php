<?php

	$edit_image 	=	[

		                	'name' => 'edit_image',
		                	'description' => '如果用戶有提供參考圖的網址(必須是http開頭的網址),就將網址放入img_arr陣列內,如沒有提供網址則忽略此參數,將用戶所指定的圖片尺寸放入img_size欄位內,img_size只允許填入1024x1024或1536x1024或1024x1536,不可填入其他文字，如果用戶指定錯誤或沒有指定尺寸就填入1024x1024,如果用戶用文字描述圖片大小,例如[橫向圖片],就填入1536x1024,[直向圖片]就填入1024x1536',

		                	'parameters' => [
		                		'type' => 'object',
		                		'properties' => [
		                		    'img_arr' => [
		                		    	'type' => 'array',
		                		    	'description' => '紀錄參考圖網址的陣列',
		                		    	'items' => [
		                		    		'type' => 'object',
		                		    			'properties' => [
		                		    				'img_url' => [
		                		    				    'type' => 'string',
		                		    				    'description' => '參考圖網址'
		                		    				]
		                		    			]
		                		    	]

		                		    ],
		                		    'img_size' => [
		                		    	'type' => 'string',
		                		    	'description' => '用戶需要的圖片尺寸,預設值1024x1024'
		                		    ]
		                		],
		                		'required' => ['img_size'],

		                	]


				        ];



?>