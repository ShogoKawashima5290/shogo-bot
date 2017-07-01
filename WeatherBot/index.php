<?php
	// Composerでインストールしたライブラリを一括読み込み
	require_once __DIR__ . '/vendor/autoload.php';

	// アクセストークンを使いCurlHTTPClientをインスタンス化
	$httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));

	// CurlHTTPClientとシークレットを使いLINEBotをインスタンス化
	$bot = new \LINE\LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);

	// LINE Messaging APIがリクエストに付与した署名を取得
	$signature = $_SERVER['HTTP_' . \LINE\LINEBot\Constant\HTTPHeader::LINE_SIGNATURE];

	// 署名が正当化チェック。正当であればリクエストをパースし配列へ
	try {
		$events = $bot->parseEventRequest(file_get_contents('php://input'), $signature);
	} catch(\LINE\LINEBot\Exception\InvalidSignatureException $e) {
		error_log('parseEventRequest failed. InvalidSignatureException => ' .var_export($e, true));
	} catch(\LINE\LINEBot\Exception\UnknownEventTypeException $e) {
		error_log('parseEventRequest failed. UnknownEventTypeException => ' .var_export($e, true));
	} catch(\LINE\LINEBot\Exception\UnknownMessageTypeException $e) {
		error_log('parseEventRequest failed. UnknownMessageTypeException => ' .var_export($e, true));
	} catch(\LINE\LINEBot\Exception\InvalidEventRequestException $e) {
		error_log('parseEventRequest failed. InvalidEventRequestException => ' .var_export($e, true));
	}

	// 配列に格納された各イベントをループで処理
	foreach ($events as $event) {
		// MessageEventクラスのインスタンスでなければ処理をスキップ
		if (!($event instanceof \LINE\LINEBot\Event\MessageEvent)) {
			error_log('Non message event has come');
			continue;
		}
		// TextMessageクラスのインスタンスでなければ処理をスキップ
		if ($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage) {
			$location = $event -> getText();
		}
		// LocationMessageクラスのインスタンスの場合
		else if ($event instanceof \LINE\LINEBot\Event\MessageEvent\LocationMessage) {
			// Google APIにアクセスし経度緯度から住所を取得
			$jsonString = file_get_contents('https://maps.googleapis.com/maps/api/geocode/json?language=ja&latlng=' . $event -> getLatitude() . ',' . $event -> getLongitude());

			// 文字列を連想配列に変換
			$json = json_decode($jsonString, true);
			// 住所情報のみを取り出し
			$addressComponentArray = $json['results'][0]['address_components'];
			// 要素をループで処理
			foreach($addressComponentArray as $addressComponent) {
				// 県名を取得
				if (in_array('administrative_area_level_1', $addressComponent['types'])) {
					$prefName = $addressComponent['long_name'];
					break;
				}
			}
			// 東京都大阪の場合他県と内容が違うので特別な処理
			if ($prefName === '東京都') {
				$location = '東京';
			} else if($prefName === '大阪府') {
				$location = '大阪';
			// それ以外
			} else {
				// 要素をループで処理
				foreach($addressComponentArray as $addressComponent) {
					// 市名を取得
					if (in_array('locality', $addressComponent['types']) && !in_array('ward', $addressComponent['types'])) {
						$location = $addressComponent['long_name'];
						break;
					}
				}
			}
		}

		// 住所ID用変数
		$locationId;
		// XMLファイルをパースするクラス
		$client = new Goutte\Client();
		// XMLファイルを取得
		$crawler = $client->request('GET', 'http://weather.livedoor.com/forecast/rss/primary_area.xml');
		// 使命のみを摘出しユーザーが入力した市名と比較
		foreach ($crawler->filter('channel ldWeather|source pref city') as $city)  {
			// 一致すれば住所IDを取得し処理を抜ける
			if($city->getAttribute('title') == $location | $city->getAttribute('title'). "市" == $location) {
				$locationId = $city->getAttribute('id');
				break;
			}
		}
		// 一致するものがなければ
		if(empty($locationId)) {
			// 位置情報が送られたときは件名を取得済みなのでそれを代入
			if ($event instanceof \LINE\LINEBot\Event\MessageEvent\LocationMessage) {
				$location = $prefName;
			}
			// 公募の配列
			$suggestArray = array();
			// 県名を摘出しユーザーが入力した県名と比較
			foreach ($crawler->filter('channel ldWeather|source pref') as $pref) {
				// 一致すれば
				if(strpos($pref->getAttribute('title'), $location) !== false) {
					// その県に属する市を配列に追加
					foreach($pref->childNodes as $child) {
						if($child instanceof DOMElement && $child->nodeName == 'city') {
							array_push($suggestArray, $child->getAttribute('title'));
						}
					}
					break;
				}
			}
			// 候補が存在する場合
			if(count($suggestArray) > 0) {
				// アクションの配列
				$actionArray = array();
				// 候補をすべてアクションにして追加
				foreach($suggestArray as $city) {
					array_push($actionArray, new LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder ($city, $city));
				}
				// Buttonsテンプレートを返信
				$builder = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder(
					'見つかりませんでした。',
					new \LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder( '見つかりませんでした。', 'もしかして？', null, $actionArray));
				$bot->replyMessage($event->getReplyToken(), $builder);
			}
			// 候補が存在しない場合
			else {
				// 正しい入力方法を返信
				replyTextMessage($bot, $event->getReplyToken(),  '入力された地名が見つかりませんでした。市を入力してください。');
			}
			// 以降の処理はスキップ
			continue;
		}
		// 住所IDが取得できた場合、その住所の天気情報を取得
		$jsonString = file_get_contents('http://weather.livedoor.com/forecast/webservice/json/v1?city=' . $locationId);
		// 文字列を連想配列に変換
		$json = json_decode($jsonString, true);

		// 形式を指定して天気の更新時刻をパース
		$date = date_parse_from_format('Y-m-d\TTH:i:sp' , $json['description']['publicTime']);

		// 予想が晴れの場合
		if ($json['forecast'][0]['telop'] === '晴れ') {
			// 天気情報、更新時刻、晴れのスタンプをまとめて送信
			replyMultiMessage($bot , $event -> getReplyToken(),
				new \LINE\LINEBot\MessageBuilder\TextMessageBuilder(
					$json['description']['text'] . PHP_EOL . PHP_EOL . '最終更新：' . sprintf('%s月%s日%s時%s分', $date['month'], $date['day'], $date['hour'], $date['minute'])),
					new \LINE\LINEBot\MessageBuilder\StickerMessageBuilder(2, 513));
		} else if ($json['forecast'][0]['telop'] === '雨') {
			// 天気情報、更新時刻、雨のスタンプをまとめ送信
			replyMultiMessage($bot , $event -> getReplyToken(),
				new \LINE\LINEBot\MessageBuilder\TextMessageBuilder(
					$json['description']['text'] . PHP_EOL . PHP_EOL . '最終更新：' . sprintf('%s月%s日%s時%s分', $date['month'], $date['day'], $date['hour'], $date['minute'])),
					new \LINE\LINEBot\MessageBuilder\StickerMessageBuilder(2, 507));
		} else {
			// 天気情報と更新時刻をまとめ返信
			replyTextMessage($bot, $event -> getReplyToken(), $json['description']['text'] . PHP_EOL . PHP_EOL .
				'最終更新：' . sprintf('%s月%s日%s時%s分', $date['month'], $date['day'], $date['hour'], $date['minute']));
		}

	}

	// テキストを返信。引数はLINEBot、返信先、テキスト
	function replyTextMessage($bot, $replyToken, $text) {
		// 返信を行いレスポンスを取得
		// TextMessageBuilderの引数はテキスト
		$response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($text));

		// レスポンスが異常な場合
		if (!$response->isSucceeded()) {
			// エラー内容を出力
			error_log('Failed!' . $response->getHTTPStatus . ' ' . $response->getRawBody());
		}
	}

	// 画像を返信。引数はLINEBot、返信先、画像URL、サムネイルURL
	function replyImageMessage($bot, $replyToken, $originalImageUrl, $previewImageUrl) {
		// ImageMessageBuilderの引数は画像URL、サムネイルURL
		$response = $bot->replyMessage($replyToken, new \LINE\LINEBot\NessageBuilder\ImageMessageBuilder($originalImageUrl, $previewImageUrl));
		if (!$response->isSucceeded()) {
			error_log('Failed!' . $response->getHTTPStatus . ' ' . $response->getRawBody());
		}
	}

	// 位置情報を返信。引数はLINEBot、返信先、タイトル、住所、緯度、経度
	function replyLocationMessage($bot, $replyToken, $title, $address, $lat, $lon) {
		// LocationMessageBuilderの引数はダイアログのタイトル、住所、経度、緯度
		$response = $bot->replyMessage($replyToke, new \LINE\LINEBot\MessageBuilder\LocationMessageBuilder($title, $address, $lat, $lon));
		if (!$response->isSucceeded()) {
			error_log('Failed!' . $response->getHTTPStatus . ' ' . $response->getRawBody());
		}
	}

	// スタンプを返信。引数はLINEBot、返信先、市＠単ぷのパッケージID、スタンプID
	function replyStickerMessage($bot, $replyToken, $packageId, $stickerId) {
		// StickerMessageBuilderの引数はスタンプのパッケージID、スタンプID
		$response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\StickerMessageBuilder($packageId, $stickerId));
		if (!$response->isSucceeded()) {
			error_log('Failed!' . $response->getHTTPStatus . ' ' . $response->getRawbody());
		}
	}

	// 動画を返信。引数はLINEBot、返信先、動画URL、サムネイルURL
	function replyVideoMessage($bot, $replyToken, $originalContentUrl, $previewImageUrl) {
		// VideoMessageBuilderの引数は動画URL、サムネイルURL
		$response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\VideoMessageBuilder($originalContentUrl, $previewImageUrl));
		if (!$response->isSucceeded()) {
			error_log('Failed!' . $response->getHTTPStatus . ' ' . $response->getRawbody());
		}
	}

	// オーディファイルを返信。引数はLINEBot、返信先、ファイルのURL、ファイルの再生時間
	function replyAudioMessage($bot, $replyToken, $originalContentUrl, $audioLength) {
		// AudioMessageBuilderの引数はファイルのURL、ファイルの再生時間
		$response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\AudioMessageBuilder($originalContentUrl, $audioLength));
		if (!$response->isSucceeded()) {
			error_log('Failed!' . $response->getHTTPStatus . ' ' . $response->getRawbody());
		}
	}

	// 複数のメッセージをまとめて返信。引数はLINEBot、返信先、メッセージ（可変長引数）
	function replyMultiMessage($bot, $replyToken, ...$msgs) {
		// MultiMessageBuilderをインスタンス化
		$builder = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
		// ビルダーにメッセージをすべて追加
		foreach ($msgs as $value) {
			$builder -> add($value);
		}
		$response = $bot -> replyMessage($replyToken, $builder);
		if (!$response -> isSucceeded()) {
			error_log('Failed!' . $response->getHTTPStatus . ' ' . $response->getRawbody());
		}
	}

	// Buttonsテンプレートを返信。引数はLINEBot、返信先、代替テキスト、画像URL、タイトル、本文、アクション（可変長引数）
	function replyButtonsTemplate($bot, $replyToken, $alternativeText, $imageUrl, $title, $text, ...$actions) {
		// アクションを格納する配列
		$actionArray = array();
		// アクションをすべて追加
		foreach ($actions as $value) {
			array_push($actionArray, $value);
		}
		// TemplateMesageBuilderの引数は代替テキスト、ButtonTemplateBuilder
		$builder = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder($alternativeText,
		// ButtonTemplateBuilderの引数はタイトル、本文、画像URL、アクションの配列
		 new\LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder($title, $text, $imageUrl, $actionArray));
		 $response = $bot->replyMessage($replyToken, $builder);
		 if (!$response->isSucceeded()) {
 			error_log('Failed!' . $response->getHTTPStatus . ' ' . $response->getRawbody());
 		}
	}

	// Confirmテンプレートを返信。引数はLINEBot、返信先、代替テキスト、本文、アクション（可変長引数）
	function replyConfirmTemplate($bot, $replyToken, $alternativeText, $text, ...$actions) {
		$actionArray = array();
		foreach ($actions as $value) {
			array_push($actionArray, $value);
		}
		$builder = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder($alternativeText,
		// Confirmテンプレートの引数はテキスト、アクションの配列
		new \LINE\LINEBot\MessageBuilder\TemplateBuilder\ConfirmTemplateBuilder($text, $actionArray));
		$response = $bot->replyMessage($replyToken, $builder);
		if (!$response->isSucceeded()) {
		   error_log('Failed!' . $response->getHTTPStatus . ' ' . $response->getRawbody());
	   }
	}

	// Carouselテンプレートを返信。引数はLINEBot、返信先、代替テキスト、ダイアログの配列
	function replyCarouseITemplate($bot, $replyToken, $alternativeText, $columnArray) {
		$builder = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder($alternativeText,
		// Carouselテンプレートの引数はダイアログの配列
		new \LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselTemplateBuilder($columnArray));
		$respose = $bot->replyMessage($replyToken, $builder);
		if (!$response->isSucceeded()) {
		   error_log('Failed!' . $response->getHTTPStatus . ' ' . $response->getRawbody());
	   }
	}
?>
