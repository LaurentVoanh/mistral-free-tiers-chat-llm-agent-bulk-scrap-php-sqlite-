<?php
/**
 * NEXUS UNIFIED v2 — Chat Orchestrator + Multi-Source Search
 * PHP 8.3 | Hostinger Mutualisé | cURL ONLY | 0755/0644
 */
define('ROOT_PATH', dirname(__FILE__));
define('DB_PATH', ROOT_PATH . '/data/nexus.sqlite');
define('LOG_PATH', ROOT_PATH . '/data/nexus.log');

define('MISTRAL_KEYS', [
    '5qaRTjWa5ZbH8Rake',
    'o3rG1zvdq1ya3eHXRShytu',
    'vEzQMKaJ30ENDjFruXkF'
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
            source TEXT,
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

function logAction(string $type, string $action, string $model = '', int $ms = 0, string $status = 'ok', string $source = '', string $payload = '', string $response = ''): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO logs (type, action, model, ms, status, source, payload, response) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$type, $action, $model, $ms, $status, $source, mb_substr($payload, 0, 1500), mb_substr($response, 0, 1500)]);
        $db->exec("DELETE FROM logs WHERE id NOT IN (SELECT id FROM logs ORDER BY id DESC LIMIT 500)");
    } catch (Throwable $e) {
        @file_put_contents(LOG_PATH, date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n", FILE_APPEND);
    }
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
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
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
        CURLOPT_USERAGENT      => 'NexusUnified/2.0 PHP-Agent',
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
        logAction('api', 'mistral_error', $model, $ms, 'error', '', json_encode($payload), $res['error']);
        return ['ok' => false, 'error' => 'cURL: ' . $res['error'], 'ms' => $ms];
    }
    if ($res['status'] !== 200) {
        logAction('api', 'mistral_http_' . $res['status'], $model, $ms, 'error', '', json_encode($payload), $res['body']);
        return ['ok' => false, 'error' => 'HTTP ' . $res['status'] . ': ' . mb_substr($res['body'], 0, 200), 'ms' => $ms];
    }
    $data = json_decode($res['body'], true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!$content) {
        logAction('api', 'mistral_empty', $model, $ms, 'error', '', '', $res['body']);
        return ['ok' => false, 'error' => 'Réponse vide', 'ms' => $ms];
    }
    logAction('api', 'mistral_ok', $model, $ms, 'ok', '', $model . ' · ' . ($data['usage']['total_tokens'] ?? 0) . ' tok', mb_substr($content, 0, 200));
    return ['ok' => true, 'content' => $content, 'ms' => $ms, 'usage' => $data['usage'] ?? []];
}

// ─── Robust JSON parser ──────────────────────────────────────────────────────
function parseJsonRobust(string $raw): ?array {
    $raw = trim($raw);
    if (!$raw) return null;
    $p = json_decode($raw, true);
    if (is_array($p)) return $p;
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m)) {
        $p = json_decode(trim($m[1]), true);
        if (is_array($p)) return $p;
    }
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

// ─── HTML → texte ────────────────────────────────────────────────────────────
function htmlToText(string $html): string {
    $html = preg_replace('/<(script|style|nav|footer|header|aside|iframe|noscript)[^>]*>.*?<\/\1>/si', '', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('/<[^>]+>/', ' ', $html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('/\s{2,}/', ' ', $html);
    return trim(mb_substr($html, 0, 8000));
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🔍 SEARCH ENGINES — 7 APIs ouvertes sans clé
// ═══════════════════════════════════════════════════════════════════════════════

// 1. Wikipedia FR — recherche + extrait
function searchWikipediaFR(string $query, int $limit = 5): array {
    $url = 'https://fr.wikipedia.org/w/api.php?action=query&list=search&srsearch=' . urlencode($query) . '&srlimit=' . $limit . '&format=json';
    $res = curlGet($url, [], 10);
    if ($res['status'] !== 200) return [];
    $data = json_decode($res['body'], true);
    $results = [];
    foreach (($data['query']['search'] ?? []) as $r) {
        $title = $r['title'] ?? '';
        // Récupérer l'extrait
        $extractUrl = 'https://fr.wikipedia.org/w/api.php?action=query&titles=' . urlencode($title) . '&prop=extracts&exintro=1&explaintext=1&format=json';
        $exRes = curlGet($extractUrl, [], 8);
        $extract = '';
        if ($exRes['status'] === 200) {
            $exData = json_decode($exRes['body'], true);
            $pages = $exData['query']['pages'] ?? [];
            foreach ($pages as $page) {
                $extract = $page['extract'] ?? '';
                break;
            }
        }
        $results[] = [
            'title'   => $title,
            'snippet' => $extract ?: strip_tags($r['snippet'] ?? ''),
            'url'     => 'https://fr.wikipedia.org/wiki/' . urlencode(str_replace(' ', '_', $title)),
            'source'  => 'wikipedia_fr'
        ];
    }
    return $results;
}

// 2. Wikipedia EN — recherche + extrait
function searchWikipediaEN(string $query, int $limit = 3): array {
    $url = 'https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch=' . urlencode($query) . '&srlimit=' . $limit . '&format=json';
    $res = curlGet($url, [], 10);
    if ($res['status'] !== 200) return [];
    $data = json_decode($res['body'], true);
    $results = [];
    foreach (($data['query']['search'] ?? []) as $r) {
        $results[] = [
            'title'   => $r['title'] ?? '',
            'snippet' => strip_tags($r['snippet'] ?? ''),
            'url'     => 'https://en.wikipedia.org/wiki/' . urlencode(str_replace(' ', '_', $r['title'] ?? '')),
            'source'  => 'wikipedia_en'
        ];
    }
    return $results;
}

// 3. Google News RSS — parser XML
function searchGoogleNews(string $query, int $limit = 8): array {
    $url = 'https://news.google.com/rss/search?q=' . urlencode($query) . '&hl=fr&gl=FR&ceid=FR:fr';
    $res = curlGet($url, [], 12);
    if ($res['status'] !== 200 || !$res['body']) return [];
    
    $results = [];
    // Parser XML avec SimpleXML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($res['body'], 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) return [];
    
    $count = 0;
    foreach ($xml->channel->item as $item) {
        if ($count >= $limit) break;
        $results[] = [
            'title'   => (string)($item->title ?? ''),
            'snippet' => strip_tags((string)($item->description ?? '')),
            'url'     => (string)($item->link ?? ''),
            'date'    => (string)($item->pubDate ?? ''),
            'source'  => 'google_news'
        ];
        $count++;
    }
    return $results;
}

// 4. Arxiv — papiers scientifiques
function searchArxiv(string $query, int $limit = 5): array {
    $url = 'http://export.arxiv.org/api/query?search_query=all:' . urlencode($query) . '&start=0&max_results=' . $limit;
    $res = curlGet($url, [], 15);
    if ($res['status'] !== 200 || !$res['body']) return [];
    
    $results = [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($res['body'], 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) return [];
    
    $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
    $xml->registerXPathNamespace('arxiv', 'http://arxiv.org/schemas/atom');
    
    foreach ($xml->entry as $entry) {
        $results[] = [
            'title'   => trim((string)($entry->title ?? '')),
            'snippet' => trim((string)($entry->summary ?? '')),
            'url'     => (string)($entry->id ?? ''),
            'authors' => implode(', ', array_map(function($a) { return (string)$a->name; }, $entry->author ?? [])),
            'date'    => (string)($entry->published ?? ''),
            'source'  => 'arxiv'
        ];
    }
    return $results;
}

// 5. OpenAlex — recherche académique large
function searchOpenAlex(string $query, int $limit = 5): array {
    $url = 'https://api.openalex.org/works?search=' . urlencode($query) . '&per_page=' . $limit . '&mailto=nexus@example.com';
    $res = curlGet($url, [], 12);
    if ($res['status'] !== 200) return [];
    $data = json_decode($res['body'], true);
    $results = [];
    foreach (($data['results'] ?? []) as $work) {
        $results[] = [
            'title'   => $work['title'] ?? '',
            'snippet' => $work['abstract_inverted_index'] ? '(abstract disponible)' : ($work['biblio'] ? 'Publication académique' : ''),
            'url'     => $work['doi'] ?? $work['id'] ?? '',
            'authors' => implode(', ', array_slice(array_column($work['authorships'] ?? [], 'author'), 0, 3)),
            'date'    => $work['publication_year'] ?? '',
            'source'  => 'openalex',
            'citations' => $work['cited_by_count'] ?? 0
        ];
    }
    return $results;
}

// 6. CrossRef — publications avec DOI
function searchCrossRef(string $query, int $limit = 5): array {
    $url = 'https://api.crossref.org/works?query=' . urlencode($query) . '&rows=' . $limit;
    $res = curlGet($url, [], 12);
    if ($res['status'] !== 200) return [];
    $data = json_decode($res['body'], true);
    $results = [];
    foreach (($data['message']['items'] ?? []) as $item) {
        $title = $item['title'][0] ?? '';
        $authors = array_map(function($a) { return ($a['given'] ?? '') . ' ' . ($a['family'] ?? ''); }, $item['author'] ?? []);
        $results[] = [
            'title'   => $title,
            'snippet' => ($item['type'] ?? 'article') . ' · ' . ($item['publisher'] ?? ''),
            'url'     => $item['DOI'] ? 'https://doi.org/' . $item['DOI'] : '',
            'authors' => implode(', ', array_slice($authors, 0, 3)),
            'date'    => $item['published-print']['date-parts'][0][0] ?? $item['published-online']['date-parts'][0][0] ?? '',
            'source'  => 'crossref'
        ];
    }
    return $results;
}

// 7. PubMed — biomédical
function searchPubMed(string $query, int $limit = 5): array {
    // Étape 1 : chercher les IDs
    $searchUrl = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&term=' . urlencode($query) . '&retmax=' . $limit . '&retmode=json';
    $res = curlGet($searchUrl, [], 12);
    if ($res['status'] !== 200) return [];
    $data = json_decode($res['body'], true);
    $ids = $data['esearchresult']['idlist'] ?? [];
    if (empty($ids)) return [];
    
    // Étape 2 : récupérer les détails
    $fetchUrl = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&id=' . implode(',', $ids) . '&retmode=json';
    $res2 = curlGet($fetchUrl, [], 12);
    if ($res2['status'] !== 200) return [];
    $data2 = json_decode($res2['body'], true);
    
    $results = [];
    foreach ($ids as $id) {
        $item = $data2['result'][$id] ?? [];
        if (empty($item)) continue;
        $authors = array_column($item['authors'] ?? [], 'name');
        $results[] = [
            'title'   => $item['title'] ?? '',
            'snippet' => 'PubMed ID: ' . $id . ' · ' . ($item['source'] ?? ''),
            'url'     => 'https://pubmed.ncbi.nlm.nih.gov/' . $id . '/',
            'authors' => implode(', ', array_slice($authors, 0, 3)),
            'date'    => $item['pubdate'] ?? '',
            'source'  => 'pubmed'
        ];
    }
    return $results;
}

// Recherche unifiée multi-sources
function unifiedSearch(string $query, array $sources = ['wikipedia_fr', 'google_news']): array {
    $all = [];
    $sourceMap = [
        'wikipedia_fr' => 'searchWikipediaFR',
        'wikipedia_en' => 'searchWikipediaEN',
        'google_news'  => 'searchGoogleNews',
        'arxiv'        => 'searchArxiv',
        'openalex'     => 'searchOpenAlex',
        'crossref'     => 'searchCrossRef',
        'pubmed'       => 'searchPubMed',
    ];
    foreach ($sources as $src) {
        if (isset($sourceMap[$src])) {
            $start = microtime(true);
            try {
                $results = $sourceMap[$src]($query, 5);
                $ms = (int)((microtime(true) - $start) * 1000);
                logAction('search', $src, '', $ms, 'ok', $src, $query, count($results) . ' résultats');
                $all = array_merge($all, $results);
            } catch (Throwable $e) {
                logAction('search', $src . '_error', '', 0, 'error', $src, $query, $e->getMessage());
            }
        }
    }
    return $all;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🎯 ACTION ORCHESTRATOR — L'IA propose des actions exécutables
// ═══════════════════════════════════════════════════════════════════════════════

// Demande à l'IA d'analyser la requête et proposer des actions
function suggestActions(string $message, string $context = ''): array {
    $sysPrompt = "Tu es un orchestrateur d'actions IA. Analyse la demande utilisateur et propose des actions concrètes.
Réponds UNIQUEMENT en JSON valide (sans markdown, sans texte avant/après) :
{
  \"intent\": \"description courte de l'intention\",
  \"actions\": [
    {\"type\": \"search\", \"query\": \"requête optimisée\", \"sources\": [\"wikipedia_fr\", \"google_news\", \"arxiv\"], \"label\": \"Rechercher sur...\"},
    {\"type\": \"scrape\", \"url\": \"https://...\", \"label\": \"Analyser cette URL\"},
    {\"type\": \"bulk\", \"questions\": [\"Q1?\", \"Q2?\", \"Q3?\"], \"system_prompt\": \"prompt\", \"label\": \"Générer X variations\"},
    {\"type\": \"answer\", \"content\": \"réponse directe si possible\"}
  ]
}

Types d'actions possibles :
- search : recherche web multi-sources (wikipedia_fr, wikipedia_en, google_news, arxiv, openalex, crossref, pubmed)
- scrape : analyse d'URL
- bulk : traitement par lot (l'IA génère les questions)
- answer : réponse directe

Si la demande est simple, mets juste une action \"answer\".
Si elle nécessite de la recherche, propose \"search\" avec les bonnes sources.
Si elle demande plusieurs variations/générations, propose \"bulk\".
Si elle mentionne une URL, propose \"scrape\".
Tu peux proposer plusieurs actions combinées.";

    $ai = callMistral([
        ['role' => 'system', 'content' => $sysPrompt],
        ['role' => 'user', 'content' => $context . "\nDemande: " . $message]
    ], 'mistral-small-2603', 800, 0.4);
    
    if (!$ai['ok']) return ['intent' => '', 'actions' => [['type' => 'answer', 'content' => 'Erreur: ' . $ai['error']]]];
    $parsed = parseJsonRobust($ai['content']);
    if (!$parsed || !isset($parsed['actions'])) {
        return ['intent' => '', 'actions' => [['type' => 'answer', 'content' => $ai['content']]]];
    }
    return $parsed;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 🌐 AJAX ROUTER
// ═══════════════════════════════════════════════════════════════════════════════
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
        $limit = min(300, (int)($input['limit'] ?? 150));
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
        $stmt = $db->prepare("DELETE FROM sessions WHERE session_key = ?");
        $stmt->execute([$input['session'] ?? '']);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'load_session') {
        $stmt = $db->prepare("SELECT conversation FROM sessions WHERE session_key = ? LIMIT 1");
        $stmt->execute([$input['session'] ?? '']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'conversation' => $row ? json_decode($row['conversation'], true) : []]);
        exit;
    }

    // ── UNIFIED SEARCH ─────────────────────────────────────────────────────
    if ($action === 'search') {
        $q = trim($input['query'] ?? '');
        $sources = $input['sources'] ?? ['wikipedia_fr', 'google_news'];
        if (!$q) { echo json_encode(['ok' => false, 'error' => 'Query vide']); exit; }
        $start = microtime(true);
        $results = unifiedSearch($q, $sources);
        $ms = (int)((microtime(true) - $start) * 1000);
        // Grouper par source
        $grouped = [];
        foreach ($results as $r) {
            $grouped[$r['source']][] = $r;
        }
        echo json_encode(['ok' => true, 'results' => $results, 'grouped' => $grouped, 'total' => count($results), 'ms' => $ms]);
        exit;
    }

    // ── SCRAPE ─────────────────────────────────────────────────────────────
    if ($action === 'scrape') {
        $url = filter_var(trim($input['url'] ?? ''), FILTER_VALIDATE_URL);
        if (!$url) { echo json_encode(['ok' => false, 'error' => 'URL invalide']); exit; }
        $start = microtime(true);
        $res = curlGet($url, [], 18);
        if ($res['error'] || $res['status'] < 200 || $res['status'] >= 400) {
            logAction('scrape', 'fail', '', 0, 'error', '', $url, $res['error'] . ' HTTP ' . $res['status']);
            echo json_encode(['ok' => false, 'error' => 'HTTP ' . $res['status'] . ' - ' . $res['error']]);
            exit;
        }
        $text = htmlToText($res['body']);
        if (mb_strlen($text) < 80) {
            echo json_encode(['ok' => false, 'error' => 'Contenu trop court']);
            exit;
        }
        $sysPrompt = "Analyse ce contenu web. Réponds UNIQUEMENT en JSON valide (sans markdown) :
{\"summary\":\"Résumé en 3-5 phrases\",\"topics\":[\"t1\",\"t2\",\"t3\"],\"questions\":[\"Q1?\",\"Q2?\",\"Q3?\",\"Q4?\",\"Q5?\"],\"entities\":[\"personnes/organisations mentionnées\"]}";
        $ai = callMistral([
            ['role' => 'system', 'content' => $sysPrompt],
            ['role' => 'user', 'content' => "URL: $url\n---\n" . mb_substr($text, 0, 6000)]
        ], 'mistral-small-2603', 900, 0.5);
        $ms = (int)((microtime(true) - $start) * 1000);
        $parsed = $ai['ok'] ? parseJsonRobust($ai['content']) : null;
        if (!$parsed || !isset($parsed['summary'])) {
            $parsed = ['summary' => $ai['content'] ?? 'Analyse échouée', 'topics' => [], 'questions' => [], 'entities' => []];
        }
        logAction('scrape', 'ok', 'mistral-small-2603', $ms, 'ok', '', $url, $parsed['summary']);
        echo json_encode(['ok' => true, 'url' => $url, 'summary' => $parsed['summary'], 'topics' => $parsed['topics'] ?? [], 'questions' => $parsed['questions'] ?? [], 'entities' => $parsed['entities'] ?? [], 'text_length' => mb_strlen($text), 'ms' => $ms]);
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
            usleep(150000);
        }
        logAction('bulk', 'complete', $model, 0, 'ok', '', count($questions) . ' questions', count($results) . ' réponses');
        echo json_encode(['ok' => true, 'results' => $results]);
        exit;
    }

    // ── ORCHESTRATE — L'IA analyse et propose des actions ──────────────────
    if ($action === 'orchestrate') {
        $message = trim($input['message'] ?? '');
        if (!$message) { echo json_encode(['ok' => false, 'error' => 'Message vide']); exit; }
        $orch = suggestActions($message);
        echo json_encode(['ok' => true, 'orchestration' => $orch]);
        exit;
    }

    // ── CHAT (avec mode orchestrator) ──────────────────────────────────────
    if ($action === 'chat') {
        $session = trim($input['session'] ?? '');
        $message = trim($input['message'] ?? '');
        $model = $input['model'] ?? 'mistral-small-2603';
        $orchestrator = !empty($input['orchestrator']);
        $agentId = $input['agent_id'] ?? null;
        if (!$session || !$message) { echo json_encode(['ok' => false, 'error' => 'Session/message manquant']); exit; }

        $stmt = $db->prepare("SELECT * FROM sessions WHERE session_key = ? LIMIT 1");
        $stmt->execute([$session]);
        $sess = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sess) { echo json_encode(['ok' => false, 'error' => 'Session introuvable']); exit; }

        $conversation = json_decode($sess['conversation'] ?: '[]', true) ?: [];
        
        $systemContent = "Tu es NEXUS, un assistant IA avancé. Réponds en français, sois précis et structuré. Utilise le markdown pour formater.";
        if ($orchestrator) {
            $systemContent .= "\n\nTu as accès à des outils que tu peux proposer à l'utilisateur via un bloc JSON à la fin de ta réponse. Format :
[[[ACTIONS]]]
{\"actions\":[{\"type\":\"search\",\"query\":\"...\",\"sources\":[\"wikipedia_fr\",\"google_news\"],\"label\":\"...\"},{\"type\":\"scrape\",\"url\":\"...\",\"label\":\"...\"},{\"type\":\"bulk\",\"questions\":[...],\"system_prompt\":\"...\",\"label\":\"...\"}]}
[[[/ACTIONS]]]

Utilise ces actions quand c'est pertinent :
- search : pour rechercher des infos (sources: wikipedia_fr, wikipedia_en, google_news, arxiv, openalex, crossref, pubmed)
- scrape : pour analyser une URL mentionnée
- bulk : pour générer plusieurs variations/réponses en parallèle
- Tu peux combiner plusieurs actions.";
        }
        if ($agentId) {
            $stmtA = $db->prepare("SELECT * FROM agents WHERE id = ?");
            $stmtA->execute([$agentId]);
            $agent = $stmtA->fetch(PDO::FETCH_ASSOC);
            if ($agent && !empty($agent['system_prompt'])) {
                $systemContent = $agent['system_prompt'];
                if (!empty($agent['model'])) $model = $agent['model'];
            }
        }

        $messages = [['role' => 'system', 'content' => $systemContent]];
        foreach ($conversation as $m) $messages[] = $m;
        $messages[] = ['role' => 'user', 'content' => $message];

        $ai = callMistral($messages, $model, 1800, 0.7);
        if (!$ai['ok']) { echo json_encode(['ok' => false, 'error' => $ai['error']]); exit; }

        $answer = $ai['content'];
        
        // Extraire le bloc d'actions s'il existe
        $actions = [];
        if (preg_match('/\[\[\[ACTIONS\]\]\]([\s\S]*?)\[\[\[\/ACTIONS\]\]\]/', $answer, $m)) {
            $parsed = parseJsonRobust($m[1]);
            if ($parsed && isset($parsed['actions'])) {
                $actions = $parsed['actions'];
            }
            // Retirer le bloc de la réponse affichée
            $answer = trim(str_replace($m[0], '', $answer));
        }

        $conversation[] = ['role' => 'user', 'content' => $message];
        $conversation[] = ['role' => 'assistant', 'content' => $answer];
        if (count($conversation) > 20) $conversation = array_slice($conversation, -20);
        
        $title = $sess['title'];
        if ($title === 'Nouvelle conversation') $title = mb_substr($message, 0, 50);
        $stmt = $db->prepare("UPDATE sessions SET conversation=?, title=?, model=?, updated_at=CURRENT_TIMESTAMP WHERE session_key=?");
        $stmt->execute([json_encode($conversation), $title, $model, $session]);

        echo json_encode([
            'ok' => true,
            'answer' => $answer,
            'actions' => $actions,
            'model' => $model,
            'ms' => $ai['ms']
        ]);
        exit;
    }

    // ── AGENTS ─────────────────────────────────────────────────────────────
    if ($action === 'agents_list') {
        $stmt = $db->query("SELECT * FROM agents ORDER BY created_at DESC");
        echo json_encode(['ok' => true, 'agents' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    if ($action === 'agents_save') {
        $stmt = $db->prepare("INSERT INTO agents (name, role, system_prompt, model, temperature) VALUES (?,?,?,?,?)");
        $stmt->execute([
            trim($input['name'] ?? ''),
            trim($input['role'] ?? ''),
            trim($input['system_prompt'] ?? ''),
            $input['model'] ?? 'mistral-small-2603',
            (float)($input['temperature'] ?? 0.7)
        ]);
        echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
        exit;
    }
    if ($action === 'agents_delete') {
        $stmt = $db->prepare("DELETE FROM agents WHERE id = ?");
        $stmt->execute([(int)($input['id'] ?? 0)]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── STATS ──────────────────────────────────────────────────────────────
    if ($action === 'stats') {
        $sessions = $db->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
        $logs = $db->query("SELECT COUNT(*) FROM logs")->fetchColumn();
        $agents = $db->query("SELECT COUNT(*) FROM agents")->fetchColumn();
        $apiCalls = $db->query("SELECT COUNT(*) FROM logs WHERE type='api'")->fetchColumn();
        $avgMs = (int)$db->query("SELECT AVG(ms) FROM logs WHERE type='api' AND ms>0")->fetchColumn();
        $searchCalls = $db->query("SELECT COUNT(*) FROM logs WHERE type='search'")->fetchColumn();
        echo json_encode(['ok' => true, 'sessions' => (int)$sessions, 'logs' => (int)$logs, 'agents' => (int)$agents, 'api_calls' => (int)$apiCalls, 'avg_ms' => $avgMs, 'search_calls' => (int)$searchCalls]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Action inconnue: ' . $action]);

} catch (Throwable $e) {
    logAction('error', 'exception', '', 0, 'error', '', $e->getFile() . ':' . $e->getLine(), $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()]);
}
