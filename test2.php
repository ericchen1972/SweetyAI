<?php
	
	require_once './vendor/autoload.php';
	include('./mysql.php');
	require_once './MysqliDb.php';

	$db             =   new MysqliDb($mysqli);
	$system         =   $db->getOne('wsystem');

	$s_client = new \GuzzleHttp\Client();
	$clientId = 'eric.chen1972-74ee2445-500e-4764';
	$clientSecret = '1f2bd57e-2763-43ef-9ae0-86ac7a26228c';

	// Step 1: 取得 Token
	$tokenResponse = $s_client->post('https://tdx.transportdata.tw/auth/realms/TDXConnect/protocol/openid-connect/token', [
	    'form_params' => [
	        'grant_type' => 'client_credentials',
	        'client_id' => $clientId,
	        'client_secret' => $clientSecret,
	    ],
	]);

	$tokenData = json_decode($tokenResponse->getBody(), true);
	$accessToken = $tokenData['access_token'];

	$main_arg 	=	Array();
	$main_arg['start_airport'] = 'TSA';
	$main_arg['end_airport'] = 'TTT';
	$main_arg['query_date'] = '2025-05-26';
	$main_arg = (object) $main_arg;

	$air_list 	=	domestic_flight_search($s_client, $accessToken, $main_arg);

	//print_r($air_list);
	echo $air_list;

	function domestic_flight_search($s_client, $accessToken, $main_arg) {
	    $today = new DateTime();
	    $query_date = $main_arg->query_date;

	    // 檢查起點和終點機場代碼是否為三個字母
	    if (strlen($main_arg->start_airport) != 3 || strlen($main_arg->end_airport) != 3) {
	        return [['type' => 'text', 'text' => '起點或終點機場代碼錯誤']];
	    }

	    try {
	        $response = $s_client->get("https://tdx.transportdata.tw/api/basic/v2/Air/FIDS/Flight/{$query_date}", [
	            'headers' => [
	                'Authorization' => "Bearer {$accessToken}",
	            ],
	            'query' => [
	                '$format' => 'JSON',
	                '$filter' => "DepartureAirportID eq '{$main_arg->start_airport}' and ArrivalAirportID eq '{$main_arg->end_airport}'"
	            ],
	        ]);
	    } catch (\GuzzleHttp\Exception\RequestException $e) {
	        return [['type' => 'text', 'text' => '抱歉，查詢發生錯誤']];
	    }

	    return $response->getBody();

	    $list_data = json_decode($response->getBody(), true);
	    $flight_list = [];

	    foreach ($list_data as $item) {
	        $departure_time = new DateTime($item['ScheduleDepartureTime']);
	        if ($query_date == $today->format('Y-m-d') && $departure_time <= $today) {
	            continue;
	        }

	        $flight_list[] = [
	            'flight_number' => $item['FlightNumber'],
	            'airline' => $item['AirlineID'],
	            'departure_time' => $item['ScheduleDepartureTime'],
	            'arrival_time' => $item['ScheduleArrivalTime'],
	            'departure_airport' => $item['DepartureAirportID'],
	            'arrival_airport' => $item['ArrivalAirportID'],
	        ];
	    }

	    if (empty($flight_list)) {
	        return [['type' => 'text', 'text' => '抱歉，沒有查詢到班次']];
	    }

	    $list_msg = '';
	    foreach ($flight_list as $flight) {
	        $list_msg .= "航班 {$flight['flight_number']} ({$flight['airline']}): {$flight['departure_airport']} {$flight['departure_time']} 起飛 - {$flight['arrival_airport']} {$flight['arrival_time']} 抵達\n";
	    }

	    return [['type' => 'text', 'text' => $list_msg]];
	}

?>