<?php

// 回覆訊息的函數  
function replyMessage($replyToken, $message, $channelAccessToken) {  
    $url = 'https://api.line.me/v2/bot/message/reply';  
    $data = [  
        'replyToken' => $replyToken,  
        'messages' => $message
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
}  


?>