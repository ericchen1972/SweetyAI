<?php
    $edit_profile = [
        'name' => 'edit_profile',
        'description' => '當用戶提供或更新自己的個人資料（如性別、年齡、城市）或擇偶條件（如對象年齡範圍、是否接受遠距離）時使用此功能。',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'sex' => [
                    'type' => 'integer',
                    'description' => '性別，1為男性，2為女性。'
                ],
                'age' => [
                    'type' => 'integer',
                    'description' => '用戶的年齡。'
                ],
                'city' => [
                    'type' => 'string',
                    'description' => '用戶居住城市，例如台北市、高雄市。'
                ],
                'long_distance' => [
                    'type' => 'integer',
                    'description' => '是否接受遠距離戀愛/跨縣市交友。0為不接受（只想找同一座城市），1為接受。'
                ],
                'age_from' => [
                    'type' => 'integer',
                    'description' => '希望對象的最小年齡。'
                ],
                'age_to' => [
                    'type' => 'integer',
                    'description' => '希望對象的最大年齡。'
                ]
            ]
        ]
    ];
?>
