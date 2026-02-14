<?php

	$add_contact 	=	[

		                	'name' => 'add_contact',
		                	'description' => '用戶需要新增聯絡人且有提供聯絡人id時執行,聯絡人id為U開頭共33個字元',

		                	'parameters' => [
		                		'type' => 'object',
		                		'properties' => [
		                		    'contact_id' => [
		                		        'type' => 'string',
		                		        'description' => '聯絡人的id'
		                		    ]
		                		],
		                		'required' => ['contact_id'],

		                	]


				        ];



?>