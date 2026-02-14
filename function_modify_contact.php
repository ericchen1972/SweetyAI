<?php

	$modify_contact 	=	[

		                	'name' => 'modify_contact',
		                	'description' => '用戶需要修改聯絡人資訊時執行的function,用戶必須提供聯絡人的名稱才能進行修改,將用戶想要修改的聯絡人放入old_name欄位內,如果有新的名稱就放入new_name內,如果有新的關係就放入new_relationship內,例如:[將Eric設定為同事],在old_name填入Eric,忽略new_name,將同事填入new_relationship,舉例:[將Eric名字改成艾瑞克],old_name填入Eric,new_name填入艾瑞克,忽略new_relationship,有時用戶所提供的名稱可能並不完整,舉例:用戶提供的名稱是Eric,但在聯絡人列表內的名稱是Eric Chen,就將Eric Chen放入old_name內',

		                	'parameters' => [
		                		'type' => 'object',
		                		'properties' => [
		                		    'old_name' => [
		                		        'type' => 'string',
		                		        'description' => '要修改的聯絡人'
		                		    ],
		                		    'new_name' => [
		                		        'type' => 'string',
		                		        'description' => '新的聯絡人名稱'
		                		    ],
		                		    'new_relationship' => [
		                		        'type' => 'string',
		                		        'description' => '新的聯絡人關係'
		                		    ]
		                		],
		                		'required' => ['old_name'],

		                	]


				        ];



?>