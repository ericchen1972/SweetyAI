<?php  

    // 發送訊息的函數  
    function sendMessage($channelAccessToken, $message, $userId) {  
        $url = 'https://api.line.me/v2/bot/message/push';  

        $data = [  
            'to' => $userId,  
            'messages' => $message,  
        ];

        $options = [  
            'http' => [  
                'header' => "Content-Type: application/json\r\n" .  
                            "Authorization: Bearer {$channelAccessToken}\r\n",  
                'method' => 'POST',  
                'content' => json_encode($data),  
            ],  
        ];  

        $context = stream_context_create($options);

        if (($result = @file_get_contents($url, false, $context)) === false) {
                $error = error_get_last();
                return $error['message'];
        } 

        if ($result === FALSE) {
            return $http_response_header;
            //error_log('Error: ' . print_r($http_response_header, true));  
        }

        return 'OK';

        // 設置 cURL  
        // $ch = curl_init($url);  
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  
        // curl_setopt($ch, CURLOPT_POST, true);  
        // curl_setopt($ch, CURLOPT_HTTPHEADER, [  
        //     'Content-Type: application/json',  
        //     'Authorization: Bearer ' . $channelAccessToken,  
        // ]);  
        // curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));  

        // // 發送請求  
        // $response = curl_exec($ch);  

        // // 錯誤處理  
        // if (curl_errno($ch)) {  
        //     return 'Curl error: ' . curl_error($ch);  
        // }  

        // curl_close($ch);  

        // return 'OK';
    }  

     
    ?>