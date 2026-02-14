<?php

	$get_myid 	=	[

		                	'name' => 'get_myid',
		                	'description' => '用戶的ID為 '.$uid.' , 將這個id放入user_id欄位內
		                	',

		                	'parameters' => [
		                		'type' => 'object',
		                		'properties' => [
		                		    'user_id' => [
		                		        'type' => 'string',
		                		        'description' => '用戶ID'
		                		    ]
		                		],
		                		'required' => ['user_id'],

		                	]


				        ];



?>