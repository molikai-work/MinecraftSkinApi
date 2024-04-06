<?php
// ?name=<id>&avatar=true&avatarsize=64

// 速率限制配置
$rate_limit = 10; // 允許的請求次數
$time_period = 120; // 時間段，單位為秒

// 獲取當前時間戳
$current_time = time();

// 獲取客戶端 IP 地址
$client_ip = $_SERVER['REMOTE_ADDR'];

// 設置存儲限制信息的文件夾路徑
$folder_name = 'rate_limit_';

// 創建文件夾
if (!file_exists($folder_name)) {
    mkdir($folder_name);
}

// 設置存儲限制信息的文件路徑
$filename = $folder_name . '/' . 'rate_limit_' . $client_ip . '.txt';

// 如果文件不存在，則創建文件並初始化限制信息
if (!file_exists($filename)) {
    file_put_contents($filename, json_encode(array('requests' => 0, 'last_request_time' => 0)));
}

// 從文件中讀取限制信息
$limit_info = json_decode(file_get_contents($filename), true);

// 如果上次請求時間與當前時間超出時間段，則重置請求次數
if ($current_time - $limit_info['last_request_time'] > $time_period) {
    $limit_info['requests'] = 0;
}

// 如果請求次數超出限制，則返回錯誤信息
if ($limit_info['requests'] >= $rate_limit) {
    header("HTTP/1.1 429 Too Many Requests");
    header('Content-Type: application/json');
    echo json_encode (
        array(
            'code' => 429,
            'msg' => '請求過多，稍請後再試',
            'time' => $current_time
        ));
    exit;
}

// 更新限制信息
$limit_info['requests']++;
$limit_info['last_request_time'] = $current_time;

// 存儲更新後的限制信息到文件中
file_put_contents($filename, json_encode($limit_info));

// 初始化 JSON 資料陣列
$json_data = array();

if (empty($_GET['name'])) {
    $json_data['code'] = 500;
    $json_data['msg'] = "請提供一個有效的 Minecraft 使用者名";
    $json_data['time'] = time();
} else {
    $mojang_uuid = curl_get_https('https://api.mojang.com/users/profiles/minecraft/' . $_GET['name']);
    $de_uuid = json_decode($mojang_uuid, true);

    if (!is_null($de_uuid['id'])) {
        $player_profile = curl_get_https('https://sessionserver.mojang.com/session/minecraft/profile/' . $de_uuid['id']);
        $de_profile = json_decode($player_profile, true);

        $de_textures = json_decode(base64_decode($de_profile['properties'][0]['value']), true);

        // 檢查是否需要處理頭像
        $include_avatar = isset($_GET['avatar']) ? ($_GET['avatar'] == 'true') : false;

        if ($include_avatar) {
            // 設置頭像大小，默認 64px
            $size_avatar = isset($_GET['avatarsize']) ? $_GET['avatarsize'] : 64;

            // 創建頭像
            $copyskin = imagecreatetruecolor($size_avatar, $size_avatar);
            $originalskin = imagecreatefromstring(file_get_contents($de_textures['textures']['SKIN']['url']));

            // 圖像修改
            if ($copyskin && $originalskin) {
                imagecopyresized($copyskin, $originalskin, 0, 0, 8, 8, $size_avatar, $size_avatar, 8, 8);
                imagecopyresized($copyskin, $originalskin, 0, 0, 40, 8, $size_avatar, $size_avatar, 8, 8);

                // 保存頭像
                $avatar_filename = uniqid(rand(), true) . ".png";
                if (imagepng($copyskin, $avatar_filename)) {
                    // 調用接口上傳圖片
                    $result = upload_image($avatar_filename);

                    if ($result && $result['code'] == 200 && isset($result['data']['url'])) {
//                        $json_data['avatar'] = $result['data']['url']; // 兩種方法
                    } else {
                        $json_data['code'] = 500;
                        $json_data['avatar'] = "頭像上傳失敗";
                        $json_data['time'] = time();
                    }

                    // 刪除臨時文件
                    unlink($avatar_filename);
                } else {
                    $json_data['code'] = 500;
                    $json_data['avatar'] = "頭像保存失敗";
                    $json_data['time'] = time();
                }

                // 銷毀圖像資源
                imagedestroy($copyskin);
                imagedestroy($originalskin);
            } else {
                $json_data['code'] = 500;
                $json_data['avatar'] = "圖像資源創建失敗";
                $json_data['time'] = time();
            }
        }

        // 構建 JSON 資料
        $json_data['code'] = 200;
        $json_data['msg'] = "皮膚請求成功";
        $json_data['time'] = time();

        $json_data['data'] = array(
            'skin_url' => $de_textures['textures']['SKIN']['url'],
            'cape_url' => !empty($de_textures['textures']['CAPE']['url']) ? $de_textures['textures']['CAPE']['url'] : ''
        );

        $json_data['avatar'] = $result['data'];
    } else {
        $json_data['code'] = 500;
        $json_data['msg'] = "無法通過使用者名獲取 UUID";
        $json_data['time'] = time();
    }
}

// 輸出 JSON 資料
header('Content-Type: application/json');
echo json_encode($json_data);

// 網路請求函數
function curl_get_https($url) {
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_HEADER => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, // 設為 2 進行嚴格的證書檢查
    ));

    $response = curl_exec($curl);

    if ($response === false) {
        // 請求失敗，輸出錯誤信息
        $error = curl_error($curl);
        curl_close($curl);
        die("Curl failed: " . $error);
    }

    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($httpCode >= 400) {
        // HTTP 錯誤碼處理
        curl_close($curl);
        die("HTTP request failed with code {$httpCode}");
    }

    curl_close($curl);
    return $response;
}

// 上傳圖片函數
function upload_image($filename) {
    // 接口地址
    $api_url = "https://imgtp.com/api/upload";

    // 設置請求頭部
    $headers = array(
        "token: " // imgtp.com API 的 token 為空則遊客上傳
    );

    // 構建POST請求數據
    $post_data = array(
        'image' => new CURLFile(realpath($filename))
    );

    // 初始化CURL
    $curl = curl_init();

    // 設置請求URL
    curl_setopt($curl, CURLOPT_URL, $api_url);

    // 設置POST請求
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    // 執行請求
    $response = curl_exec($curl);

    // 關閉CURL
    curl_close($curl);

    // 解析返回的 JSON 數據
    $result = json_decode($response, true);
    
    // 提取相關信息
    $name = $result['data']['name'];
    $url = $result['data']['url'];
    
    // 構建 JSON 資料
    $result = array(
        'code' => $result['code'],
        'msg' => $result['msg'],
        'time' => $result['time'],
        'data' => array(
            'img_code' => $result['code'],
            'img_msg' => $result['msg'],
            'name' => $name,
            'url' => $url
        )
    );

    return $result;
}
?>
