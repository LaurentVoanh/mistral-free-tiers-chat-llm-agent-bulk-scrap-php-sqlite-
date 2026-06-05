<?php
/**
 * NEXUS UNIFIED — Fusion NEXUS + LITECLAW + BULKHOGAN + VOANH
 * PHP 8.3 | Hostinger Mutualisé | cURL ONLY | 0755/0644
 */
define('ROOT_PATH', dirname(__FILE__));
define('DB_PATH', ROOT_PATH . '/data/nexus.sqlite');
define('LOG_PATH', ROOT_PATH . '/data/nexus.log');

define('MISTRAL_KEYS', [
    '5qaRaH8Rake',
    'o3rG1zaShytu',
    'vEzQaruXkF'
]);
define('MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');

define('MODELS', [
    'codestral-2508'           => ['name' => 'Code Master Ultimate',     'cat' => 'code'],
    'devstral-2512'            => ['name' => 'Dev Agent Pro',            'cat' => 'code'],
    'devstral-medium-2507'     => ['name' => 'Dev Agent Medium',         'cat' => 'code'],
    'devstral-small-2507'      => ['name' => 'Dev Agent Light',          'cat' => 'code'],
    'mistral-large-2512'       => ['name' => 'Mistral Brain Ultra',      'cat' => 'flagship'],
    'mistral-large-2411'       => ['name' => 'Mistral Brain Legacy',     'cat' => 'flagship'],
    'mistral-medium-2508'      => ['name' => 'Corporate Engine Pro',     'cat' => 'balanced'],
    'mistral-medium-2505'      => ['name' => 'Corporate Engine Std',     'cat' => 'balanced'],
    'mistral-small-2603'       => ['name' => 'Fast Automate Turbo',      'cat' => 'fast'],
    'mistral-small-2506'       => ['name' => 'Fast Automate Std',        'cat' => 'fast'],
    'magistral-medium-2509'    => ['name' => 'Agent Router Medium',      'cat' => 'agent'],
    'magistral-small-2509'     => ['name' => 'Agent Router Small',       'cat' => 'agent'],
    'labs-mistral-small-creative' => ['name' => 'Creative Writer',       'cat' => 'creative'],
    'pixtral-large-2411'       => ['name' => 'Vision Analyzer Max',      'cat' => 'vision'],
    'pixtral-12b-2409'         => ['name' => 'Vision Analyzer Light',    'cat' => 'vision'],
    'ministral-14b-2512'       => ['name' => 'Local Engine Heavy',       'cat' => 'edge'],
    'ministral-8b-2512'        => ['name' => 'Local Engine Medium',      'cat' => 'edge'],
    'ministral-3b-2512'        => ['name' => 'Local Engine Micro',       'cat' => 'edge'],
    'voxtral-small-2507'       => ['name' => 'Audio Core Small',         'cat' => 'audio'],
    'voxtral-mini-2507'        => ['name' => 'Audio Core Mini',          'cat' => 'audio'],
]);

// ─── Init ────────────────────────────────────────────────────────────────────
$dataDir = ROOT_PATH . '/data';
if (!is_dir($dataDir)) { mkdir($dataDir, 0755, true); }

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_key TEXT UNIQUE NOT NULL,
            title TEXT DEFAULT 'Nouvelle conversation',
            model TEXT DEFAULT 'mistral-small-2603',
            conversation TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            type TEXT NOT NULL,
            action TEXT,
            model TEXT,
            ms INTEGER DEFAULT 0,
            status TEXT DEFAULT 'ok',
            payload TEXT,
            response TEXT
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            role TEXT,
            system_prompt TEXT,
            model TEXT DEFAULT 'mistral-small-2603',
            temperature REAL DEFAULT 0.7,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        chmod(DB_PATH, 0644);
    }
    return $pdo;
}

// ─── Logging ─────────────────────────────────────────────────────────────────
function logAction(string $type, string $action, string $model = '', int $ms = 0, string $status = 'ok', string $payload = '', string $response = ''): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO logs (type, action, model, ms, status, payload, response) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$type, $action, $model, $ms, $status, mb_substr($payload, 0, 2000), mb_substr($response, 0, 2000)]);
        // Keep max 500 logs
        $db->exec("DELETE FROM logs WHERE id NOT IN (SELECT id FROM logs ORDER BY id DESC LIMIT 500)");
    } catch (Throwable $e) {
        @file_put_contents(LOG_PATH, date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n", FILE_APPEND);
    }
}

function logError(string $msg): void {
    $line = date('[Y-m-d H:i:s] ') . $msg . "\n";
    @file_put_contents(LOG_PATH, $line, FILE_APPEND | LOCK_EX);
    @chmod(LOG_PATH, 0644);
}

// ─── cURL ────────────────────────────────────────────────────────────────────
function curlGet(string $url, array $headers = [], int $timeout = 20): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NexusUnified/1.0; +https://web-4.art)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => 'gzip, deflate',
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    return ['body' => $body ?: '', 'status' => $status, 'error' => $err];
}

function curlPost(string $url, array $payload, array $headers = [], int $timeout = 30): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'NexusUnified/1.0 PHP-Agent',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    return ['body' => $body ?: '', 'status' => $status, 'error' => $err];
}

// ─── Mistral ─────────────────────────────────────────────────────────────────
function getMistralKey(): string {
    return MISTRAL_KEYS[array_rand(MISTRAL_KEYS)];
}

function callMistral(array $messages, string $model = 'mistral-small-2603', int $maxTokens = 1200, float $temp = 0.7): array {
    $start = microtime(true);
    $payload = [
        'model'       => $model,
        'max_tokens'  => $maxTokens,
        'temperature' => $temp,
        'messages'    => $messages
    ];
    $headers = ['Authorization: Bearer ' . getMistralKey()];
    $res = curlPost(MISTRAL_ENDPOINT, $payload, $headers, 30);
    $ms = (int)((microtime(true) - $start) * 1000);
    
    if ($res['error']) {
        logAction('api', 'mistral_error', $model, $ms, 'error', json_encode($payload), $res['error']);
        return ['ok' => false, 'error' => 'cURL: ' . $res['error'], 'ms' => $ms];
    }
    if ($res['status'] !== 200) {
        logAction('api', 'mistral_http_' . $res['status'], $model, $ms, 'error', json_encode($payload), $res['body']);
        return ['ok' => false, 'error' => 'HTTP ' . $res['status'] . ': ' . mb_substr($res['body'], 0, 200), 'ms' => $ms];
    }
    $data = json_decode($res['body'], true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!$content) {
        logAction('api', 'mistral_parse', $model, $ms, 'error', json_encode($payload), $res['body']);
        return ['ok' => false, 'error' => 'Réponse vide', 'ms' => $ms];
    }
    logAction('api', 'mistral_ok', $model, $ms, 'ok', json_encode(['model'=>$model,'tokens'=>$data['usage']['total_tokens']??0]), mb_substr($content, 0, 300));
    return ['ok' => true, 'content' => $content, 'ms' => $ms, 'usage' => $data['usage'] ?? []];
}

// ─── Robust JSON parser (gère le texte avant/après JSON, backticks, etc.) ────
function parseJsonRobust(string $raw): ?array {
    $raw = trim($raw);
    // 1. Essai direct
    $p = json_decode($raw, true);
    if (is_array($p)) return $p;
    // 2. Retirer markdown ```json ... ```
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
        $p = json_decode(trim($m[1]), true);
        if (is_array($p)) return $p;
    }
    // 3. Chercher premier { ou [ et prendre jusqu'au dernier } ou ]
    $startBrace = strpos($raw, '{');
    $startBracket = strpos($raw, '[');
    $start = false; $endChar = '}';
    if ($startBrace !== false && ($startBracket === false || $startBrace < $startBracket)) {
        $start = $startBrace; $endChar = '}';
    } elseif ($startBracket !== false) {
        $start = $startBracket; $endChar = ']';
    }
    if ($start !== false) {
        $end = strrpos($raw, $endChar);
        if ($end !== false && $end > $start) {
            $p = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($p)) return $p;
        }
    }
    return null;
}

// ─── HTML → texte propre ─────────────────────────────────────────────────────
function htmlToText(string $html): string {
    $html = preg_replace('/<(script|style|nav|footer|header|aside|iframe|noscript)[^>]*>.*?<\/\1>/si', '', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('/<[^>]+>/', ' ', $html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('/\s{2,}/', ' ', $html);
    return trim(mb_substr($html, 0, 8000));
}

// ─── Web Search (Wikipedia + DuckDuckGo) ─────────────────────────────────────
function searchWikipedia(string $query, int $limit = 3): array {
    $url = 'https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch=' . urlencode($query) . '&srlimit=' . $limit . '&format=json';
    $res = curlGet($url, [], 10);
    if ($res['status'] !== 200) return [];
    $data = json_decode($res['body'], true);
    $results = [];
    foreach (($data['query']['search'] ?? []) as $r) {
        $results[] = [
            'title'   => $r['title'] ?? '',
            'snippet' => strip_tags($r['snippet'] ?? ''),
            'url'     => 'https://en.wikipedia.org/wiki/' . urlencode(str_replace(' ', '_', $r['title'] ?? ''))
        ];
    }
    return $results;
}

function searchDuckDuckGo(string $query): array {
    $url = 'https://api.duckduckgo.com/?q=' . urlencode($query) . '&format=json&no_html=1';
    $res = curlGet($url, [], 10);
    if ($res['status'] !== 200) return [];
    $data = json_decode($res['body'], true);
    $results = [];
    if (!empty($data['AbstractText'])) {
        $results[] = ['title' => $data['Heading'] ?? $query, 'snippet' => $data['AbstractText'], 'url' => $data['AbstractURL'] ?? ''];
    }
    foreach (($data['RelatedTopics'] ?? []) as $r) {
        if (isset($r['Text'])) {
            $results[] = ['title' => mb_substr($r['Text'], 0, 80), 'snippet' => $r['Text'], 'url' => $r['FirstURL'] ?? ''];
            if (count($results) >= 5) break;
        }
    }
    return $results;
}

function searchNews(string $query): array {
    // Wikipedia Current Events + Google News RSS via proxy
    $url = 'https://en.wikipedia.org/w/api.php?action=parse&page=Portal:Current_events&prop=text&format=json';
    $res = curlGet($url, [], 10);
    if ($res['status'] !== 200) return [];
    $data = json_decode($res['body'], true);
    $html = $data['parse']['text']['*'] ?? '';
    $text = strip_tags($html);
    $text = preg_replace('/\s{2,}/', ' ', $text);
    return [['title' => 'Actualités Wikipedia', 'snippet' => mb_substr($text, 0, 500), 'url' => 'https://en.wikipedia.org/wiki/Portal:Current_events']];
}

// ─── AJAX Router ─────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');

if (!$action) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}

try {
    $db = getDB();

    // ── MODELS ─────────────────────────────────────────────────────────────
    if ($action === 'models') {
        echo json_encode(['ok' => true, 'models' => MODELS]);
        exit;
    }

    // ── LOGS ───────────────────────────────────────────────────────────────
    if ($action === 'logs') {
        $limit = (int)($input['limit'] ?? 100);
        $stmt = $db->prepare("SELECT * FROM logs ORDER BY id DESC LIMIT ?");
        $stmt->execute([$limit]);
        echo json_encode(['ok' => true, 'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'clear_logs') {
        $db->exec("DELETE FROM logs");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── SESSIONS ───────────────────────────────────────────────────────────
    if ($action === 'sessions') {
        $stmt = $db->query("SELECT id, session_key, title, model, created_at, updated_at FROM sessions ORDER BY updated_at DESC LIMIT 50");
        echo json_encode(['ok' => true, 'sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'new_session') {
        $key = bin2hex(random_bytes(8));
        $model = $input['model'] ?? 'mistral-small-2603';
        $title = $input['title'] ?? 'Nouvelle conversation';
        $stmt = $db->prepare("INSERT INTO sessions (session_key, title, model) VALUES (?,?,?)");
        $stmt->execute([$key, $title, $model]);
        echo json_encode(['ok' => true, 'session' => $key]);
        exit;
    }

    if ($action === 'delete_session') {
        $key = $input['session'] ?? '';
        $stmt = $db->prepare("DELETE FROM sessions WHERE session_key = ?");
        $stmt->execute([$key]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── SEARCH (Wikipedia + DDG + News) ────────────────────────────────────
    if ($action === 'search') {
        $q = trim($input['query'] ?? '');
        if (!$q) { echo json_encode(['ok' => false, 'error' => 'Query vide']); exit; }
        $start = microtime(true);
        $wiki = searchWikipedia($q, 3);
        $ddg = searchDuckDuckGo($q);
        $news = searchNews($q);
        $ms = (int)((microtime(true) - $start) * 1000);
        logAction('search', 'web_search', '', $ms, 'ok', $q, json_encode(['wiki'=>count($wiki),'ddg'=>count($ddg),'news'=>count($news)]));
        echo json_encode(['ok' => true, 'wiki' => $wiki, 'ddg' => $ddg, 'news' => $news, 'ms' => $ms]);
        exit;
    }

    // ── SCRAPE ─────────────────────────────────────────────────────────────
    if ($action === 'scrape') {
        $url = filter_var(trim($input['url'] ?? ''), FILTER_VALIDATE_URL);
        if (!$url) { echo json_encode(['ok' => false, 'error' => 'URL invalide']); exit; }
        $start = microtime(true);
        $res = curlGet($url, [], 18);
        if ($res['error'] || $res['status'] < 200 || $res['status'] >= 400) {
            logAction('scrape', 'scrape_fail', '', (int)((microtime(true)-$start)*1000), 'error', $url, $res['error'] . ' HTTP ' . $res['status']);
            echo json_encode(['ok' => false, 'error' => 'HTTP ' . $res['status'] . ' - ' . $res['error']]);
            exit;
        }
        $text = htmlToText($res['body']);
        if (mb_strlen($text) < 80) {
            echo json_encode(['ok' => false, 'error' => 'Contenu trop court']);
            exit;
        }
        // Analyse IA
        $sysPrompt = "Tu es un analyste web. Réponds UNIQUEMENT en JSON valide (sans markdown, sans texte avant/après) :
{\"summary\":\"Résumé en 3-5 phrases\",\"topics\":[\"t1\",\"t2\",\"t3\"],\"questions\":[\"Q1?\",\"Q2?\",\"Q3?\",\"Q4?\",\"Q5?\"]}";
        $ai = callMistral([
            ['role' => 'system', 'content' => $sysPrompt],
            ['role' => 'user',   'content' => "URL: $url\n---\n" . mb_substr($text, 0, 6000) . "\n---\nAnalyse et génère le JSON."]
        ], 'mistral-small-2603', 900, 0.5);
        $ms = (int)((microtime(true) - $start) * 1000);
        $parsed = $ai['ok'] ? parseJsonRobust($ai['content']) : null;
        if (!$parsed || !isset($parsed['summary'])) {
            $parsed = ['summary' => $ai['content'] ?? 'Analyse échouée', 'topics' => [], 'questions' => []];
        }
        logAction('scrape', 'scrape_ok', 'mistral-small-2603', $ms, 'ok', $url, $parsed['summary']);
        echo json_encode(['ok' => true, 'url' => $url, 'summary' => $parsed['summary'], 'topics' => $parsed['topics'] ?? [], 'questions' => $parsed['questions'] ?? [], 'text_length' => mb_strlen($text), 'ms' => $ms]);
        exit;
    }

    // ── CHAT (avec recherche web optionnelle) ─────────────────────────────
    if ($action === 'chat') {
        $session = trim($input['session'] ?? '');
        $message = trim($input['message'] ?? '');
        $model = $input['model'] ?? 'mistral-small-2603';
        $webSearch = !empty($input['web_search']);
        $agentId = $input['agent_id'] ?? null;
        if (!$session || !$message) { echo json_encode(['ok' => false, 'error' => 'Session/message manquant']); exit; }

        $stmt = $db->prepare("SELECT * FROM sessions WHERE session_key = ? LIMIT 1");
        $stmt->execute([$session]);
        $sess = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sess) { echo json_encode(['ok' => false, 'error' => 'Session introuvable']); exit; }

        $conversation = json_decode($sess['conversation'] ?: '[]', true) ?: [];
        
        // Agent system prompt
        $systemContent = "Tu es NEXUS, un assistant IA avancé. Réponds en français, sois précis et structuré.";
        if ($agentId) {
            $stmtA = $db->prepare("SELECT * FROM agents WHERE id = ?");
            $stmtA->execute([$agentId]);
            $agent = $stmtA->fetch(PDO::FETCH_ASSOC);
            if ($agent && !empty($agent['system_prompt'])) {
                $systemContent = $agent['system_prompt'];
                if (!empty($agent['model'])) $model = $agent['model'];
            }
        }

        // Recherche web si demandée
        $webContext = '';
        if ($webSearch) {
            $wiki = searchWikipedia($message, 2);
            $ddg = searchDuckDuckGo($message);
            if (!empty($wiki) || !empty($ddg)) {
                $webContext = "\n\n[CONTEXTE WEB - Résultats de recherche]\n";
                foreach ($wiki as $w) $webContext .= "• Wikipedia - {$w['title']}: {$w['snippet']}\n";
                foreach ($ddg as $d) $webContext .= "• Web - {$d['title']}: {$d['snippet']}\n";
                $webContext .= "[/CONTEXTE WEB]\n";
            }
        }

        // Build messages
        $messages = [['role' => 'system', 'content' => $systemContent . $webContext]];
        foreach ($conversation as $m) $messages[] = $m;
        $messages[] = ['role' => 'user', 'content' => $message];

        $ai = callMistral($messages, $model, 1500, 0.7);
        if (!$ai['ok']) { echo json_encode(['ok' => false, 'error' => $ai['error']]); exit; }

        $answer = $ai['content'];
        $conversation[] = ['role' => 'user', 'content' => $message];
        $conversation[] = ['role' => 'assistant', 'content' => $answer];
        // Trim conversation (max 20 messages)
        if (count($conversation) > 20) {
            $conversation = array_slice($conversation, -20);
        }
        // Update title if first message
        $title = $sess['title'];
        if ($title === 'Nouvelle conversation') {
            $title = mb_substr($message, 0, 50);
        }
        $stmt = $db->prepare("UPDATE sessions SET conversation=?, title=?, model=?, updated_at=CURRENT_TIMESTAMP WHERE session_key=?");
        $stmt->execute([json_encode($conversation), $title, $model, $session]);

        // Generate follow-up questions
        $qAi = callMistral([
            ['role' => 'system', 'content' => "Génère 3 questions de suivi pertinentes. Réponds UNIQUEMENT en JSON array (sans markdown, sans texte) : [\"Q1?\",\"Q2?\",\"Q3?\"]"],
            ['role' => 'user', 'content' => "Contexte: $message\nRéponse: " . mb_substr($answer, 0, 500)]
        ], 'mistral-small-2506', 200, 0.6);
        $questions = [];
        if ($qAi['ok']) {
            $pq = parseJsonRobust($qAi['content']);
            if (is_array($pq)) $questions = array_slice($pq, 0, 3);
        }

        echo json_encode([
            'ok' => true,
            'answer' => $answer,
            'questions' => $questions,
            'model' => $model,
            'ms' => $ai['ms'],
            'web_used' => $webSearch && !empty($webContext)
        ]);
        exit;
    }

    // ── BULK ───────────────────────────────────────────────────────────────
    if ($action === 'bulk') {
        $questions = $input['questions'] ?? [];
        $model = $input['model'] ?? 'mistral-small-2603';
        $systemPrompt = trim($input['system_prompt'] ?? 'Tu es un assistant utile. Réponds de manière concise.');
        if (empty($questions)) { echo json_encode(['ok' => false, 'error' => 'Aucune question']); exit; }
        $results = [];
        foreach ($questions as $i => $q) {
            $q = trim($q);
            if (empty($q)) continue;
            $ai = callMistral([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $q]
            ], $model, 800, 0.7);
            $results[] = [
                'index' => $i,
                'question' => $q,
                'answer' => $ai['ok'] ? $ai['content'] : 'ERREUR: ' . $ai['error'],
                'model' => $model,
                'ms' => $ai['ms'] ?? 0,
                'ok' => $ai['ok']
            ];
            // Petit délai pour éviter rate limit
            usleep(200000);
        }
        echo json_encode(['ok' => true, 'results' => $results]);
        exit;
    }

    // ── AGENTS ─────────────────────────────────────────────────────────────
    if ($action === 'agents_list') {
        $stmt = $db->query("SELECT * FROM agents ORDER BY created_at DESC");
        echo json_encode(['ok' => true, 'agents' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    if ($action === 'agents_save') {
        $name = trim($input['name'] ?? '');
        $role = trim($input['role'] ?? '');
        $prompt = trim($input['system_prompt'] ?? '');
        $model = $input['model'] ?? 'mistral-small-2603';
        $temp = (float)($input['temperature'] ?? 0.7);
        if (!$name || !$prompt) { echo json_encode(['ok' => false, 'error' => 'Nom/prompt requis']); exit; }
        $stmt = $db->prepare("INSERT INTO agents (name, role, system_prompt, model, temperature) VALUES (?,?,?,?,?)");
        $stmt->execute([$name, $role, $prompt, $model, $temp]);
        echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
        exit;
    }
    if ($action === 'agents_delete') {
        $id = (int)($input['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM agents WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── STATS ──────────────────────────────────────────────────────────────
    if ($action === 'stats') {
        $sessions = $db->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
        $logs = $db->query("SELECT COUNT(*) FROM logs")->fetchColumn();
        $agents = $db->query("SELECT COUNT(*) FROM agents")->fetchColumn();
        $apiCalls = $db->query("SELECT COUNT(*) FROM logs WHERE type='api'")->fetchColumn();
        $avgMs = $db->query("SELECT AVG(ms) FROM logs WHERE type='api' AND ms>0")->fetchColumn();
        echo json_encode(['ok' => true, 'sessions' => (int)$sessions, 'logs' => (int)$logs, 'agents' => (int)$agents, 'api_calls' => (int)$apiCalls, 'avg_ms' => (int)$avgMs]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Action inconnue: ' . $action]);

} catch (Throwable $e) {
    logError($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    logAction('error', 'exception', '', 0, 'error', '', $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()]);
}