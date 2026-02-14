<?php

    date_default_timezone_set("Asia/Taipei");
    chdir(dirname(__FILE__));

	include 'mysql.php';
    require_once 'MysqliDb.php';
    require_once 'vendor/autoload.php';
    include 'ai_member_utility.php';
    include 'line_msg_send.php';

    $db         =   new MysqliDb($mysqli);

    $system     =   $db->getOne('wsystem');
    $service_pref   =   json_decode($system['service_pref'], true);
    include 'config.php';

    $now 		=	new DateTime();
    $now_str 	=	$now->format('Y-m-d H:i').' '.$now->format('l');
    $db->where('utimer', $now->format('Y-m-d H:i'), '<')->delete('ai_events');

    $task 		=	$db->where('utimer', $now->format('Y-m-d H:i').':00')->get('ai_member_events');

    if ($db->count == 0) exit();

    // Hardcode API Key
    $client = OpenAI::factory()
        ->withApiKey($openai_api_key)
        ->withBaseUri($system['gpt_api_url'])
        ->make();


    foreach ($task as $item){

        $idol       =   $db->where('id', $item['idol'])->getOne('idols');

        $sys_prompt     =   [
                    "role" => "system",
                    "content"=> $idol['default_prompt'].',目前時間是 '.$now_str.' ,你會看到這個prompt,就代表用戶在這個時間需要被提醒或是問候的事情,現在這個時間已到達，例如:[十分鐘後提醒我出門],就代表現在10分鐘已到,你應該要提示用戶出門,另一種情境是,用戶沒有要求你做提醒,但是當時你認為必須記錄下來並且在現在給予用戶某些訊息,舉例來說,紀錄內容是用戶說:[我明天下午要參加比賽,好緊張啊],那麼代表現在適合給用戶一些鼓勵的訊息,
                    arrange的function_array放入空陣列,將提醒訊息放入arrange的message_reply內'
                ];

        $memo            =      Array();
        $memo[]		     =	    $sys_prompt;

        $arrs		     =	    json_decode($item['reason'], true);


        if (!isset($arrs[0]['role']) || is_null($arrs[0]['role'])) exit();
        if (!isset($arrs[0]['content']) || is_null($arrs[0]['content'])) exit();
            $arrs[0]['content'] .=  '-'.$item['cdate'];
            $memo[] =   $arrs[0];

        // Hardcode model to gpt-5.2
        $ai_reply = create_msg($client, $memo, $db, 'gpt-5.2', $item['uid'], '', $system, NULL, $idol);

        // $data = Array();
        // $data['msg'] = json_encode($ai_reply);
        // $db->insert('err_log', $data);


        //提醒自己時
        if ($ai_reply[0]['type'] === 'text'){
            $reply = sendMessage($idol['tokens'], $ai_reply, $item['uid']);
            if ( $reply !== 'OK'){
                $data = Array();
                $data['msg'] = $reply;
                $db->insert('err_log', $data);
            }
        }

        $db->where('id', $item['id'])->delete('ai_member_events');
        $db->rawQuery('ALTER TABLE ai_member_events AUTO_INCREMENT = 1');

        // echo json_encode($ai_reply).'<br>';
        // echo $reply;

    }


    // if ($ai_reply['role'] == 'images'){

    //     foreach ($ai_reply['content'] as $img){

    //         $reply = sendMessage($system['line_notify'], $ai_reply['content'], $task['uid'], $img['img_url'], $img['img_priview']);

    //         if ( $reply !== 'OK'){
    //             $data = Array();
    //             $data['msg'] = $reply;
    //             $db->insert('err_log', $data);
    //         } 

    //     }

    //     exit();
    // }


    // $data = Array();
    // $data['msg'] = $ai_reply;
    // $db->insert('err_log', $data);


?>