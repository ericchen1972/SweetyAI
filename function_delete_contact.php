<?php

	$delete_contact 	=	[

		                	'name' => 'delete_contact',
		                	'description' => '當用戶想要刪除在聯絡人列表中的人,將此人在聯絡列表中的contact_id填入contact_id內,注意有時用戶所給的名稱不一定完整,舉例用戶說:[刪除Eric],而聯絡人列表內的名稱是Eric Chen,就填入Eric Chen的contact_id',

		                	'parameters' => [
		                		'type' => 'object',
		                		'properties' => [
		                		    'contact_id' => [
		                		        'type' => 'string',
		                		        'description' => '聯絡人的contact_id'
		                		    ]
		                		],
		                		'required' => ['contact_id'],

		                	]


				        ];



?>