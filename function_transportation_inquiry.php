<?php

	$transportation_inquiry 	=	[

		                	'name' => 'transportation_inquiry',
		                	'description' => '目前日期是'.$tstr.',如果用戶有提供日期,就將日期依照Y-m-d格式填入date欄位內,將用戶所提供起點填入start欄位,將用戶有提供的終點填入end欄位內,如果用戶使用口語描述日期,例如明天,後天,下週三等,就依目前日期計算出正確日期並填入date內,沒有任何日在資訊則忽略',

		                	'parameters' => [
		                		'type' => 'object',
		                		'properties' => [
		                		    'date' => [
		                		        'type' => 'string',
		                		        'description' => '日期'
		                		    ],
		                		    'start' => [
		                		        'type' => 'string',
		                		        'description' => '起點'
		                		    ],
		                		    'end' => [
		                		        'type' => 'string',
		                		        'description' => '終點'
		                		    ]
		                		],
		                		'required' => ['start', 'end'],

		                	]


				        ];



?>