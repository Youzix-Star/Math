<?php
// 独立联机游戏后端 - SSE 实时推送 (超低延迟 30ms)
session_start();
define('ROOT_PATH', dirname(__FILE__) . '/');
$gameDataDir = ROOT_PATH . 'data/game/';
$webpDir = ROOT_PATH . 'data/webp/';
$langDir = ROOT_PATH . 'lang/';   // 新增语言包目录
if (!is_dir($gameDataDir)) mkdir($gameDataDir, 0777, true);
if (!is_dir($webpDir)) mkdir($webpDir, 0777, true);
if (!is_dir($langDir)) mkdir($langDir, 0777, true);  // 确保语言目录存在

function getCurrentPlayerId() {
    if (!isset($_SESSION['game_player_id'])) {
        $_SESSION['game_player_id'] = uniqid('player_');
    }
    return $_SESSION['game_player_id'];
}

$CARD_DATA = [
    "四色花" => ["up"=>1,"left"=>2,"down"=>3,"right"=>4],
    "倒·四色花" => ["up"=>2,"left"=>3,"down"=>4,"right"=>1],
    "五色花" => ["up"=>3,"left"=>4,"down"=>1,"right"=>2],
    "倒·五色花" => ["up"=>4,"left"=>1,"down"=>2,"right"=>3],
    "三相团子" => ["up"=>0,"left"=>1,"down"=>9999,"right"=>1],
    "小电视" => ["up"=>2,"left"=>3,"down"=>2,"right"=>3],
    "OuO" => ["up"=>3,"left"=>2,"down"=>2,"right"=>2]
];
$ALL_CARDS = array_keys($CARD_DATA);

function getCardObj($name) { global $CARD_DATA; return ["name"=>$name] + $CARD_DATA[$name]; }
function generateRandomHand() { global $ALL_CARDS; $hand = []; for ($i=0; $i<5; $i++) $hand[] = getCardObj($ALL_CARDS[array_rand($ALL_CARDS)]); return $hand; }

function applyBattleAndFlip(&$grid, $index, $owner, $card) {
    $directions = [
        ["my"=>"up","opp"=>"down","nIdx"=>$index-3,"valid"=>$index-3>=0],
        ["my"=>"down","opp"=>"up","nIdx"=>$index+3,"valid"=>$index+3<9],
        ["my"=>"left","opp"=>"right","nIdx"=>$index-1,"valid"=>($index%3 != 0)],
        ["my"=>"right","opp"=>"left","nIdx"=>$index+1,"valid"=>($index%3 != 2)]
    ];
    $flipped = [];
    foreach ($directions as $d) {
        if (!$d['valid']) continue;
        $nIdx = $d['nIdx'];
        if (!isset($grid[$nIdx]) || $grid[$nIdx]['owner'] == $owner) continue;
        $neighborCard = getCardObj($grid[$nIdx]['cardName']);
        $myVal = ($card[$d['my']] == 9999) ? INF : $card[$d['my']];
        $oppVal = ($neighborCard[$d['opp']] == 9999) ? INF : $neighborCard[$d['opp']];
        if ($myVal > $oppVal) { $grid[$nIdx]['owner'] = $owner; $flipped[] = $nIdx; }
    }
    return $flipped;
}

function checkGameOver(&$room) {
    $full = true; $p1 = 0; $p2 = 0;
    for ($i=0;$i<9;$i++) {
        if (!isset($room['grid'][$i])) { $full = false; continue; }
        if ($room['grid'][$i]['owner'] == 1) $p1++; else $p2++;
    }
    if ($full || count($room['player1_hand'])==0 || count($room['player2_hand'])==0) {
        $room['status'] = 'finished';
        if ($p1 > $p2) $room['winner'] = 1;
        elseif ($p2 > $p1) $room['winner'] = 2;
        else $room['winner'] = 0;
        return true;
    }
    return false;
}

function getEmojiList() {
    global $webpDir;
    $emojis = [];
    if (is_dir($webpDir)) {
        $files = scandir($webpDir);
        foreach ($files as $file) {
            if (preg_match('/^(.+)\.webp$/i', $file, $matches)) $emojis[] = $matches[1];
        }
    }
    return $emojis;
}

// 新增：获取可用语言列表（扫描 lang/ 目录下的 json 文件，读取 meta.name）
function getLanguageList() {
    global $langDir;
    $languages = [];
    if (!is_dir($langDir)) return $languages;
    $files = scandir($langDir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
        $filePath = $langDir . $file;
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);
        if (isset($data['meta']['name'])) {
            $code = pathinfo($file, PATHINFO_FILENAME);
            $languages[] = [
                'code' => $code,
                'name' => $data['meta']['name']
            ];
        }
    }
    return $languages;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// SSE 监听端点 (30ms)
if ($action == 'game_listen') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    ob_implicit_flush(true);
    ob_end_flush();

    $roomId = $_GET['room_id'] ?? '';
    $clientHash = $_GET['hash'] ?? '';
    if (empty($roomId)) { echo "data: {\"error\":\"room_id_required\"}\n\n"; exit; }
    $file = $gameDataDir . "room_{$roomId}.json";
    $playerId = getCurrentPlayerId();
    $maxLoops = 1000; // 1000 * 0.03s = 30秒超时

    for ($i = 0; $i < $maxLoops; $i++) {
        if (!file_exists($file)) { echo "data: {\"error\":\"room_deleted\"}\n\n"; exit; }
        clearstatcache(true, $file);
        $currentHash = md5_file($file);
        if ($currentHash !== $clientHash) {
            $room = json_decode(file_get_contents($file), true);
            if ($room['player1'] !== $playerId && $room['player2'] !== $playerId) { echo "data: {\"error\":\"not_member\"}\n\n"; exit; }
            $role = ($room['player1'] == $playerId) ? 1 : 2;
            $state = [
                'status' => $room['status'], 'grid' => $room['grid'],
                'player1_hand' => $room['player1_hand'], 'player2_hand' => $room['player2_hand'],
                'current_turn' => $room['current_turn'], 'winner' => $room['winner'],
                'last_emoji' => $room['last_emoji'] ?? null
            ];
            echo "data: " . json_encode(['hash' => $currentHash, 'state' => $state, 'role' => $role]) . "\n\n";
            exit;
        }
        echo ": heartbeat\n\n";
        if (ob_get_level()) { ob_flush(); flush(); }
        usleep(30000); // 0.03 秒
    }
    echo "data: " . json_encode(['hash' => $currentHash ?? '', 'timeout' => true]) . "\n\n";
    exit;
}

// 获取表情列表
if ($action == 'game_get_emojis') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'emojis' => getEmojiList()]);
    exit;
}

// 获取语言列表 (新增)
if ($action == 'game_get_languages') {
    header('Content-Type: application/json');
    $languages = getLanguageList();
    echo json_encode(['success' => true, 'languages' => $languages]);
    exit;
}

// 创建房间
if ($action == 'game_create_room') {
    header('Content-Type: application/json');
    $playerId = getCurrentPlayerId();
    $roomId = rand(100000, 999999);
    while (file_exists($gameDataDir . "room_{$roomId}.json")) $roomId = rand(100000, 999999);
    $room = [
        'room_id' => $roomId,
        'player1' => $playerId,
        'player2' => null,
        'status' => 'waiting',
        'grid' => [],
        'player1_hand' => generateRandomHand(),
        'player2_hand' => generateRandomHand(),
        'current_turn' => 1,
        'winner' => null,
        'create_time' => time(),
        'last_emoji' => null
    ];
    file_put_contents($gameDataDir . "room_{$roomId}.json", json_encode($room, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'room_id' => $roomId]);
    exit;
}

// 加入房间
if ($action == 'game_join_room') {
    header('Content-Type: application/json');
    $roomId = $_POST['room_id'] ?? '';
    $file = $gameDataDir . "room_{$roomId}.json";
    if (!file_exists($file)) {
        echo json_encode(['success' => false, 'message' => '房间不存在']);
        exit;
    }
    $room = json_decode(file_get_contents($file), true);
    if ($room['status'] != 'waiting') {
        echo json_encode(['success' => false, 'message' => '游戏已经开始或已结束']);
        exit;
    }
    $playerId = getCurrentPlayerId();
    if ($room['player1'] == $playerId) {
        echo json_encode(['success' => false, 'message' => '不能重复加入自己创建的房间']);
        exit;
    }
    $room['player2'] = $playerId;
    $room['status'] = 'playing';
    if (!isset($room['last_emoji'])) $room['last_emoji'] = null;
    file_put_contents($file, json_encode($room, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
    exit;
}

// 获取游戏状态
if ($action == 'game_get_state') {
    header('Content-Type: application/json');
    $roomId = $_POST['room_id'] ?? '';
    $file = $gameDataDir . "room_{$roomId}.json";
    if (!file_exists($file)) {
        echo json_encode(['success' => false, 'message' => '房间不存在']);
        exit;
    }
    $room = json_decode(file_get_contents($file), true);
    $playerId = getCurrentPlayerId();
    $role = null;
    if ($room['player1'] == $playerId) $role = 1;
    elseif ($room['player2'] == $playerId) $role = 2;
    else {
        echo json_encode(['success' => false, 'message' => '你不是该房间成员']);
        exit;
    }
    $state = [
        'status' => $room['status'],
        'grid' => $room['grid'],
        'player1_hand' => $room['player1_hand'],
        'player2_hand' => $room['player2_hand'],
        'current_turn' => $room['current_turn'],
        'winner' => $room['winner'],
        'last_emoji' => $room['last_emoji'] ?? null
    ];
    echo json_encode(['success' => true, 'state' => $state, 'role' => $role]);
    exit;
}

// 放置卡牌
if ($action == 'game_place_card') {
    header('Content-Type: application/json');
    $roomId = $_POST['room_id'] ?? '';
    $slot = (int)$_POST['slot'];
    $cardIdx = (int)$_POST['card_idx'];
    $file = $gameDataDir . "room_{$roomId}.json";
    if (!file_exists($file)) {
        echo json_encode(['success' => false, 'message' => '房间不存在']);
        exit;
    }
    $fp = fopen($file, 'r+');
    if (flock($fp, LOCK_EX)) {
        $room = json_decode(file_get_contents($file), true);
        $playerId = getCurrentPlayerId();
        $role = null;
        if ($room['player1'] == $playerId) $role = 1;
        elseif ($room['player2'] == $playerId) $role = 2;
        if (!$role || $room['status'] != 'playing' || $room['current_turn'] != $role) {
            flock($fp, LOCK_UN); fclose($fp);
            echo json_encode(['success' => false, 'message' => '非法操作或未到你的回合']);
            exit;
        }
        $hand = ($role == 1) ? $room['player1_hand'] : $room['player2_hand'];
        if ($cardIdx >= count($hand)) {
            flock($fp, LOCK_UN); fclose($fp);
            echo json_encode(['success' => false, 'message' => '无效的手牌索引']);
            exit;
        }
        $card = $hand[$cardIdx];
        if (isset($room['grid'][$slot])) {
            flock($fp, LOCK_UN); fclose($fp);
            echo json_encode(['success' => false, 'message' => '该格子已有卡牌']);
            exit;
        }
        $room['grid'][$slot] = ['cardName' => $card['name'], 'owner' => $role];
        $flipped = applyBattleAndFlip($room['grid'], $slot, $role, $card);
        array_splice($hand, $cardIdx, 1);
        if ($role == 1) $room['player1_hand'] = $hand;
        else $room['player2_hand'] = $hand;
        $room['current_turn'] = ($role == 1) ? 2 : 1;
        checkGameOver($room);
        file_put_contents($file, json_encode($room, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
        fclose($fp);
        echo json_encode(['success' => true, 'flipped' => $flipped]);
    } else {
        echo json_encode(['success' => false, 'message' => '服务器繁忙']);
    }
    exit;
}

// 发送表情
if ($action == 'game_send_emoji') {
    header('Content-Type: application/json');
    $roomId = $_POST['room_id'] ?? '';
    $emoji = $_POST['emoji'] ?? '';
    $file = $gameDataDir . "room_{$roomId}.json";
    if (!file_exists($file)) {
        echo json_encode(['success' => false, 'message' => '房间不存在']);
        exit;
    }
    if (empty($emoji)) {
        echo json_encode(['success' => false, 'message' => '表情标识不能为空']);
        exit;
    }
    $fp = fopen($file, 'r+');
    if (flock($fp, LOCK_EX)) {
        $room = json_decode(file_get_contents($file), true);
        $playerId = getCurrentPlayerId();
        $role = null;
        if ($room['player1'] == $playerId) $role = 1;
        elseif ($room['player2'] == $playerId) $role = 2;
        if (!$role || $room['status'] != 'playing') {
            flock($fp, LOCK_UN); fclose($fp);
            echo json_encode(['success' => false, 'message' => '无法发送表情']);
            exit;
        }
        $room['last_emoji'] = ['sender' => $role, 'emoji' => $emoji, 'time' => time()];
        $writeResult = file_put_contents($file, json_encode($room, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
        fclose($fp);
        if ($writeResult === false) {
            echo json_encode(['success' => false, 'message' => '文件写入失败']);
        } else {
            echo json_encode(['success' => true]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => '服务器繁忙']);
    }
    exit;
}

// 离开房间
if ($action == 'game_leave_room') {
    header('Content-Type: application/json');
    $roomId = $_POST['room_id'] ?? '';
    $file = $gameDataDir . "room_{$roomId}.json";
    if (file_exists($file)) {
        $room = json_decode(file_get_contents($file), true);
        $playerId = getCurrentPlayerId();
        if ($room['player1'] == $playerId || $room['player2'] == $playerId) {
            unlink($file);
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => '无效请求']);