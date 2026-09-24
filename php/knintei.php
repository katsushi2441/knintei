<?php
/**
 * Kurage 要介護認定ナビ（knintei）
 *
 * 要介護認定で「何を聞かれるか」を、厚生労働省の認定調査票そのままで見せる。
 * 区分の境目は省令の条文で示し、**区分は当てない**。
 * 基準時間は「厚生労働大臣の定める方法により推計される時間」（省令3条）で、
 * その方法＝一次判定ソフトの樹形モデルの係数は公開されていない。当てられないものを当てない。
 *
 * PHP1ファイル＋SQLite。heteml では .htaccess に AddHandler php-script .php が要る。
 */

$SELF   = '/knintei.php';
$SITE   = 'Kurage 要介護認定ナビ';
$SUB    = '認定調査で何を聞かれるか';
$OGP    = 'https://kurage.exbridge.jp/images/ogp/knintei.png';
$ICON   = 'https://kurage.exbridge.jp/images/kurage-mascot-cutout-300.webp';
$MASCOT = 'https://kurage.exbridge.jp/images/kurage-mascot-cutout-300.webp';
$XBLOGO = 'https://exbridge.jp/images/logo-mark-64.png';
$STORE  = 'https://kappstore.exbridge.jp/app.php?id=';   // 出品後に商品IDを入れる
$DBPATH = __DIR__ . '/knintei_data/knintei.sqlite';
$BASE   = 'https://kurage.exbridge.jp' . $SELF;

mb_internal_encoding('UTF-8');
header('Content-Type: text/html; charset=UTF-8');

try {
    $db = new PDO('sqlite:' . $DBPATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><p>準備中です。</p>';
    exit;
}

$META = array();
foreach ($db->query('SELECT k, v FROM meta') as $r) { $META[$r['k']] = $r['v']; }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($v) { return number_format((int)$v); }
function jd($s) { $v = json_decode((string)$s, true); return is_array($v) ? $v : array(); }

/** 省令の漢数字の分数を、そのままの意味で読める形に。**足し算はしない。** */
function kubun_range($k) {
    if ((int)$k['hi'] === 0) { return $k['lo'] . '分以上'; }
    return $k['lo'] . '分以上' . $k['hi'] . '分未満';
}

function city_by_slug($db, $pref, $slug) {
    $st = $db->prepare('SELECT * FROM cities WHERE pref = ? AND slug = ? LIMIT 1');
    $st->execute(array($pref, $slug));
    return $st->fetch();
}

function law_box($db, $law, $article, $limit = 320) {
    $st = $db->prepare('SELECT * FROM law_articles WHERE law = ? AND article = ?');
    $st->execute(array($law, $article));
    $a = $st->fetch();
    if (!$a) { return; }
    $st2 = $db->prepare('SELECT * FROM laws WHERE name = ?');
    $st2->execute(array($law));
    $lw = $st2->fetch();
    echo '<div class="law"><div class="ttl">' . h($law) . ' ' . h($a['title'])
       . ($a['caption'] ? '（' . h($a['caption']) . '）' : '') . '</div>';
    $t = preg_replace('/\s+/u', ' ', $a['text']);
    echo '<blockquote>' . h(mb_substr($t, 0, $limit)) . (mb_strlen($t) > $limit ? '…' : '') . '</blockquote>';
    if ($lw) {
        echo '<div class="ttl" style="margin:6px 0 0"><a href="' . h($lw['url']) . '" rel="nofollow">'
           . 'e-Gov法令検索で全文を読む</a>'
           . ($lw['enforcement'] ? '（' . h($lw['enforcement']) . ' 施行時点）' : '') . '</div>';
    }
    echo '</div>';
}

function head_html($title, $desc, $canon, $ld_extra = null) {
    global $SELF, $SITE, $SUB, $OGP, $BASE, $META, $ICON;
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<meta name="description" content="' . h($desc) . '">';
    echo '<link rel="canonical" href="' . h($BASE . $canon) . '">';
    echo '<link rel="icon" type="image/webp" href="' . h($ICON) . '">';
    echo '<meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '">';
    echo '<meta property="og:type" content="website"><meta property="og:image" content="' . h($OGP) . '">';
    echo '<meta property="og:site_name" content="' . h($SITE) . '"><meta property="og:url" content="' . h($BASE . $canon) . '">';
    echo '<meta property="og:locale" content="ja_JP">';
    echo '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="' . h($OGP) . '">';
    echo '<style>'
       . ':root{--ink:#1d2430;--mut:#616c7a;--ac:#3d7a6b;--ac-d:#2d5d51;--line:#e0e5e3;--bg:#f6f8f7;'
       . '--red:#a5453a;--red-l:#fbeeec;--amb:#8a6a1f;--amb-l:#fbf4e4;--grn-l:#eef4f0}'
       . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);'
       . 'font:16px/1.85 "Noto Serif JP",Georgia,"Hiragino Mincho ProN",serif}'
       . 'a{color:var(--ac-d)}.wrap{width:min(920px,100% - 32px);margin:0 auto}'
       . 'header{background:#fff;border-bottom:1px solid var(--line)}'
       . '.brand{display:flex;align-items:center;gap:11px;padding:14px 0 6px;font-weight:700;font-size:19px;'
       . 'text-decoration:none;color:var(--ink);font-family:system-ui,sans-serif}'
       . '.brand img{width:42px;height:42px;border-radius:11px;object-fit:contain;background:#eef3f1;padding:3px;flex:none}'
       . '.brand small{display:block;font-weight:400;font-size:12.5px;color:var(--mut);letter-spacing:.04em;margin-top:1px}'
       . '.menu{display:flex;gap:16px;flex-wrap:wrap;padding-bottom:12px;font-size:14px}'
       . '.menu a{text-decoration:none;color:var(--mut)}'
       . 'main{padding:24px 0 48px}'
       . 'h1{font-size:26px;line-height:1.5;margin:0 0 12px;text-wrap:balance}'
       . 'h2{font-size:20px;margin:34px 0 12px;padding-bottom:6px;border-bottom:2px solid var(--line)}'
       . 'h3{font-size:17px;margin:20px 0 8px}'
       . '.lead{color:var(--mut);font-size:15px}'
       . '.hero{display:grid;grid-template-columns:1fr 190px;gap:18px;align-items:center;margin:4px 0 6px}'
       . '.hero img{width:100%;max-width:190px;height:auto;justify-self:end}'
       . '@media(max-width:640px){.hero{grid-template-columns:1fr}'
       . '.hero img{max-width:128px;height:auto;justify-self:center;margin-top:4px}}'
       . '.eyebrow{display:inline-block;font-family:system-ui,sans-serif;font-size:12px;letter-spacing:.12em;'
       . 'color:var(--ac-d);background:#e9f1ee;border:1px solid #cfdfd9;border-radius:999px;padding:3px 13px;margin-bottom:12px}'
       . '.panel{background:#fff;border:1px solid var(--line);border-radius:10px;padding:20px;margin:16px 0}'
       . '.panel.quiet{background:#fbfcfb}'
       . '.panel.store{border-color:#cddfd8;background:linear-gradient(180deg,#fff 0%,#f7fbf9 100%)}'
       . '.cols2{display:grid;grid-template-columns:1fr 300px;gap:22px;align-items:center}'
       . '.store-fig img{width:100%;height:auto;border:1px solid var(--line);border-radius:8px}'
       . '@media(max-width:700px){.cols2{grid-template-columns:1fr}.store-fig{display:none}}'
       . '.btn{display:inline-block;background:var(--ac);color:#fff;border:0;border-radius:8px;padding:13px 26px;'
       . 'font:inherit;font-weight:700;text-decoration:none;cursor:pointer}'
       . '.btn.ghost{background:#fff;color:var(--ac-d);border:1px solid var(--line);padding:9px 16px;font-size:14px}'
       . '.tscroll{overflow-x:auto;background:#fff;border:1px solid var(--line);border-radius:10px}'
       . 'table.t{width:100%;border-collapse:collapse;font-size:14px;min-width:420px}'
       . 'table.t th,table.t td{border-bottom:1px solid #edf1ef;padding:10px 14px;text-align:left;vertical-align:top}'
       . 'table.t tr:last-child td{border-bottom:0}'
       . 'table.t th{color:var(--mut);font-size:11.5px;font-family:system-ui,sans-serif;font-weight:400;'
       . 'letter-spacing:.06em;background:#fafbfa;border-bottom:1px solid var(--line)}'
       . 'table.t td a{color:var(--ink);text-decoration:none;border-bottom:1px solid #cfdad6}'
       . 'table.t td a:hover{color:var(--ac-d);border-bottom-color:var(--ac)}'
       . 'table.t tr.gh td{background:#eef2f0;border-bottom:1px solid #dde5e2;padding:9px 14px}'
       . 'table.t tr.gh a{font-family:system-ui,sans-serif;font-weight:700;font-size:13.5px;color:var(--ac-d);border:0}'
       . 'td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}'
       . '.law{background:#fafbfa;border:1px solid var(--line);border-radius:8px;padding:12px 14px;margin:10px 0;font-size:14px}'
       . '.law .ttl{font-family:system-ui,sans-serif;font-size:12.5px;color:var(--mut);margin-bottom:4px}'
       . '.law blockquote{margin:0;font-size:14px;line-height:1.8;color:#3a4450}'
       . '.opt{display:flex;gap:10px;align-items:flex-start;padding:9px 0;border-bottom:1px solid #eef2f0;font-size:15px}'
       . '.opt:last-child{border-bottom:0}'
       . '.opt b{font-family:system-ui,sans-serif;font-size:12.5px;background:#eef3f1;color:var(--ac-d);'
       . 'border-radius:5px;padding:2px 9px;flex:none;margin-top:3px}'
       . '.def{white-space:pre-line;font-size:15px}'
       . '.crit{white-space:pre-line;font-size:14.5px;color:#3a4450;background:#fbfcfb;'
       . 'border:1px solid var(--line);border-radius:8px;padding:14px 16px}'
       . '.cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:4px 14px;font-size:14.5px}'
       . '.big{font-size:30px;font-weight:700;font-variant-numeric:tabular-nums;line-height:1.3}'
       . '.src{font-size:12.5px;color:var(--mut);line-height:1.85;font-family:system-ui,sans-serif}'
       . '.co{display:flex;align-items:center;gap:12px;margin:0 0 10px}'
       . '.co img{width:44px;height:44px;border-radius:9px;object-fit:contain;background:#fff;border:1px solid var(--line);flex:none}'
       . '.co b{font-family:system-ui,sans-serif;font-size:15px;display:block;color:var(--ink)}'
       . '.co span{font-size:12.5px;color:var(--mut)}'
       . 'footer{border-top:1px solid var(--line);padding:24px 0 48px;background:#fff}'
       . '@media(max-width:520px){h1{font-size:22px}body{font-size:15.5px}}'
       . '</style>';
    echo '<script>(function(){var s=document.createElement("script");s.src="https://kurage.exbridge.jp/simpletrack.php?url='
       . '"+encodeURIComponent(location.href)+"&ref="+encodeURIComponent(document.referrer);s.async=true;'
       . 'document.head.appendChild(s)})();</script>';
    $graph = array(
        array('@type' => 'WebApplication', 'name' => $SITE, 'alternateName' => $SUB,
              'url' => $BASE . '/', 'applicationCategory' => 'GovernmentApplication',
              'operatingSystem' => 'Web', 'inLanguage' => 'ja',
              'description' => '要介護認定の認定調査で何を聞かれるかを、厚生労働省の認定調査票そのままで見られます。区分の境目は省令の条文つき。全国' . n($META['n_cities']) . '市区町村の窓口ページつき。',
              'offers' => array('@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'JPY'),
              'publisher' => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/')),
        array('@type' => 'FAQPage', 'mainEntity' => array(
            array('@type' => 'Question', 'name' => '要介護認定の調査では何を聞かれますか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '基本調査は5群' . $META['n_items'] . '問です。第1群 身体機能・起居動作13問、第2群 生活機能12問、第3群 認知機能9問、第4群 精神・行動障害15問、第5群 社会生活への適応6問。これに過去14日間にうけた特別な医療12項目が加わります。よく言われる「74項目」は、麻痺の有無を部位ごとに5、拘縮の有無を4と数えて62、それに特別な医療12を足した数え方です。')),
            array('@type' => 'Question', 'name' => '要支援2と要介護1は何が違いますか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '要介護認定等基準時間はどちらも32分以上50分未満で同じです。状態の維持・改善の見込みがあるかどうかで分かれます（要介護認定等に係る介護認定審査会による審査及び判定の基準等に関する省令2条1項2号）。')),
            array('@type' => 'Question', 'name' => '要介護度は事前に分かりますか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '分かりません。区分は要介護認定等基準時間で決まり、その時間は「厚生労働大臣の定める方法により推計される時間」と省令3条が定めています。この推計方法（一次判定ソフトの樹形モデル）の係数は公開されていないため、調査の回答から区分を計算することはできません。このサイトは区分の境目と根拠の条文までを示し、区分は出しません。')),
            array('@type' => 'Question', 'name' => '要介護認定等基準時間は介護にかかる時間のことですか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '実際に家庭で介護にかけている時間ではありません。省令3条は、入浴・排せつ・食事等の介護、洗濯・掃除等の家事援助等、徘徊に対する探索や不潔な行為に対する後始末等、歩行訓練・日常生活訓練等の機能訓練、輸液の管理やじょく瘡の処置等の診療の補助等について、1日あたりの時間として推計される時間と定めています。介護の手間の総量をあらわす物差しです。')),
            array('@type' => 'Question', 'name' => '申請してからどのくらいで結果が出ますか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '介護保険法27条11項は、申請のあった日から30日以内にしなければならないと定めています。調査に日数を要するなどの理由があるときは、期間を延長することがあり、その場合は延長の理由と処理の見込み期間が通知されます。')))),
    );
    if ($ld_extra) {
        if (isset($ld_extra['@type'])) { $graph[] = $ld_extra; }
        else { foreach ($ld_extra as $x) { $graph[] = $x; } }
    }
    echo '<script type="application/ld+json">'
       . json_encode(array('@context' => 'https://schema.org', '@graph' => $graph),
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    echo '</head><body><header><div class="wrap">';
    echo '<a class="brand" href="' . h($SELF) . '/">'
       . '<img src="' . h($ICON) . '" alt="Kurage" width="42" height="42">'
       . '<span>' . h($SITE) . '<small>' . h($SUB) . '</small></span></a>';
    echo '<nav class="menu">';
    foreach (array('/' => '調査項目を見る', '/kubun' => '区分の境目', '/nagare' => '申請の流れ',
                   '/cities' => '市区町村の窓口', '/about' => 'このサイトについて') as $u => $t) {
        echo '<a href="' . h($SELF . $u) . '">' . h($t) . '</a>';
    }
    echo '</nav></div></header><main><div class="wrap">';
}

function foot_html() {
    global $META, $XBLOGO;
    echo '</div></main><footer><div class="wrap">';
    echo '<p class="src">認定調査の項目・定義・選択基準は' . h($META['items_source'])
       . '（<a href="' . h($META['items_source_url']) . '" rel="nofollow">PDF</a>）から、'
       . '言い換えずに引用しています。区分の境目と申請の期限は'
       . '<a href="https://laws.e-gov.go.jp/" rel="nofollow">e-Gov法令検索</a>（デジタル庁）の条文。'
       . '市区町村の人口・65歳以上人口は' . h($META['age_source']) . '（' . h($META['age_asof']) . '現在）。'
       . 'いずれも政府標準利用規約に従って出典を示しています。<br>'
       . '<strong>このサイトは要介護度を判定しません。</strong>認定は市区町村の介護認定審査会が行います。'
       . '申請や区分の相談は、お住まいの市区町村の介護保険担当課か地域包括支援センターへ。</p>';
    echo '<div class="co"><img src="' . h($XBLOGO) . '" alt="株式会社エクスブリッジ" width="44" height="44">'
       . '<span><b>株式会社エクスブリッジ</b>'
       . '<span>EXBRIDGE, INC.／名古屋市瑞穂区　創業2004年　'
       . '<a href="https://exbridge.jp/">会社のサイト</a>　'
       . '<a href="https://kurage.exbridge.jp/">Kurage のほかのシステム</a></span></span></div>';
    echo '<p class="src">名古屋市内の会社なら、<a href="https://exbridge.jp/ai-it-komon.html?ref=knintei">AI-IT顧問契約</a>'
       . '（月15時間・税別150,000円）の期間中に構築できる商品は、商品代金をいただかず当社が設置まで行います。'
       . 'ソースコードごと御社の資産として残ります。</p>';
    echo '</div></footer></body></html>';
}

/** 調査項目1件のカード。$full で定義と選択基準まで出す。 */
function item_html($db, $p, $full = false) {
    global $SELF;
    echo '<div class="panel">';
    echo '<h3 style="margin-top:0"><span class="src">' . h($p['id']) . '</span> ';
    if ($full) { echo h($p['name']); }
    else { echo '<a href="' . h($SELF . '/item/' . rawurlencode($p['id'])) . '">' . h($p['name']) . '</a>'; }
    if ((int)$p['multi']) { echo ' <span class="src">（あてはまるものすべて）</span>'; }
    echo '</h3>';
    foreach (jd($p['options']) as $o) {
        echo '<div class="opt"><b>' . h($o['no']) . '</b><span>' . h($o['label']) . '</span></div>';
    }
    if ($full) {
        if ($p['definition']) {
            echo '<h3>調査項目の定義</h3><div class="def">' . h($p['definition']) . '</div>';
        }
        if ($p['criteria']) {
            echo '<h3>' . h($p['criteria_label']) . '</h3><div class="crit">' . h($p['criteria']) . '</div>';
        }
    }
    echo '</div>';
}

/** オンプレミス版の案内。人が着地するページの本文に置く。 */
function store_html($ref, $lead = '') {
    global $STORE, $OGP;
    if ($STORE === 'https://kappstore.exbridge.jp/app.php?id=') { return; }  // 出品前は出さない
    echo '<div class="panel store"><div class="cols2"><div>';
    echo '<div class="src" style="margin-bottom:4px">自分のサーバーに置く</div>';
    echo '<h3 style="margin:0 0 8px">このシステムのオンプレミス版</h3>';
    echo '<p style="margin:0;font-size:14.5px">' . ($lead ? h($lead) . '<br>' : '')
       . 'PHP1ファイルとSQLite1本だけです。自分の市区町村の窓口名・電話・様式のリンクを足して使えます。'
       . '地域包括支援センターや居宅介護支援事業所が、相談の場でそのまま開ける作りです。</p>';
    echo '<p style="margin:14px 0 0"><a class="btn" href="' . h($STORE . '&ref=' . $ref) . '">オンプレミス版を見る</a></p>';
    echo '</div><div class="store-fig"><img src="' . h($OGP) . '" alt="" loading="lazy" width="1200" height="630"></div>';
    echo '</div></div>';
}

// ---- ルーティング ---------------------------------------------------------
$path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '/';
$path = rtrim($path, '/');
if ($path === '') { $path = '/'; }

if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    $lm = date('Y-m-d', filemtime($DBPATH));
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $urls = array('/', '/kubun', '/nagare', '/cities', '/about');
    foreach ($db->query('SELECT id FROM groups ORDER BY sort') as $g) { $urls[] = '/group/' . $g['id']; }
    foreach ($db->query('SELECT id FROM items ORDER BY sort') as $i) { $urls[] = '/item/' . rawurlencode($i['id']); }
    foreach ($db->query('SELECT DISTINCT pref FROM cities') as $r) { $urls[] = '/pref/' . rawurlencode($r['pref']); }
    foreach ($db->query('SELECT pref, slug FROM cities ORDER BY code') as $r) {
        $urls[] = '/city/' . rawurlencode($r['pref']) . '/' . rawurlencode($r['slug']);
    }
    foreach ($urls as $u) { echo '<url><loc>' . h($BASE . $u) . '</loc><lastmod>' . $lm . '</lastmod></url>'; }
    echo '</urlset>';
    exit;
}

if ($path === '/llms.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "# " . $SITE . "（" . $SUB . "）\n\n";
    echo "要介護認定の認定調査で何を聞かれるかを、厚生労働省の認定調査票そのままで見られます。\n\n";
    echo "## 覚えておくとよい事実\n";
    echo "- 基本調査の設問は5群で" . $META['n_items'] . "問。よく言われる「74項目」は、麻痺の有無を部位ごとに5、拘縮の有無を4と数えて62、それに特別な医療12を足した数え方。\n";
    echo "- 要支援2と要介護1は、要介護認定等基準時間がどちらも32分以上50分未満で同じ。状態の維持・改善の見込みで分かれる（省令2条1項2号）。\n";
    echo "- 要介護認定等基準時間は「厚生労働大臣の定める方法により推計される時間」（省令3条）。推計方法の係数は公開されていないので、調査の回答から区分は計算できない。\n";
    echo "- 要介護認定等基準時間は、実際に家庭で介護にかけている時間ではない。介護の手間の総量をあらわす物差し。\n";
    echo "- 申請から認定までは30日以内（介護保険法27条11項）。延長するときは理由と見込み期間が通知される。\n";
    echo "- 介護保険の第1号被保険者は65歳以上（介護保険法9条1号）。\n\n";
    echo "## ページ\n";
    echo "- " . $BASE . "/ 認定調査の" . $META['n_items'] . "問\n";
    echo "- " . $BASE . "/kubun 区分の境目（要支援1〜要介護5）\n";
    echo "- " . $BASE . "/nagare 申請から認定までの流れ\n";
    echo "- " . $BASE . "/cities 全国" . n($META['n_cities']) . "市区町村\n";
    echo "\n提供: 株式会社エクスブリッジ（名古屋市） https://exbridge.jp/\n";
    exit;
}

if ($path === '/api/items') {
    header('Content-Type: application/json; charset=UTF-8');
    $out = array();
    foreach ($db->query('SELECT * FROM items ORDER BY sort') as $i) {
        $out[] = array('id' => $i['id'], 'group' => $i['grp'], 'name' => $i['name'],
                       'multi' => (bool)$i['multi'], 'options' => jd($i['options']),
                       'definition' => $i['definition'], 'url' => $BASE . '/item/' . rawurlencode($i['id']));
    }
    echo json_encode(array('count' => count($out), 'items' => $out,
                           'source' => $META['items_source'], 'count_note' => $META['count_note']),
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- /item/<id> 調査項目1つ ------------------------------------------------
if (preg_match('#^/item/([^/]+)$#', $path, $m)) {
    $st = $db->prepare('SELECT * FROM items WHERE id = ?');
    $st->execute(array(rawurldecode($m[1])));
    $p = $st->fetch();
    if (!$p) { http_response_code(404); head_html('見つかりません｜' . $SITE, '', '/'); echo '<h1>見つかりません</h1>'; foot_html(); exit; }
    $gs = $db->prepare('SELECT * FROM groups WHERE id = ?'); $gs->execute(array($p['grp']));
    $g = $gs->fetch();
    $title = '要介護認定の調査項目「' . $p['name'] . '」｜選択肢と定義（' . $p['id'] . '）';
    $desc = mb_substr(preg_replace('/\s+/u', ' ', $p['definition']), 0, 110);
    $ld = array(array('@type' => 'BreadcrumbList', 'itemListElement' => array(
        array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $BASE . '/'),
        array('@type' => 'ListItem', 'position' => 2, 'name' => '第' . $g['id'] . '群 ' . $g['name'],
              'item' => $BASE . '/group/' . $g['id']),
        array('@type' => 'ListItem', 'position' => 3, 'name' => $p['name']))));
    head_html($title, $desc, '/item/' . rawurlencode($p['id']), $ld);
    echo '<p class="src"><a href="' . h($SELF) . '/">' . h($SITE) . '</a> ＞ '
       . '<a href="' . h($SELF . '/group/' . $g['id']) . '">第' . h($g['id']) . '群 ' . h($g['name']) . '</a></p>';
    echo '<h1>' . h($p['id']) . '　' . h($p['name']) . '</h1>';
    echo '<p class="lead">認定調査員が実際に見て選ぶ項目です。文言は厚生労働省の認定調査員テキストのまま載せています。'
       . '<strong>言い換えていません</strong>。選択肢の言葉そのものが判断の基準だからです。</p>';
    item_html($db, $p, true);
    echo '<p class="src">この項目の回答が、そのまま要介護度になるわけではありません。'
       . '<a href="' . h($SELF) . '/kubun">区分は要介護認定等基準時間で決まり</a>、その推計方法は公開されていません。</p>';

    $st = $db->prepare('SELECT * FROM items WHERE grp = ? AND id <> ? ORDER BY sort');
    $st->execute(array($p['grp'], $p['id']));
    $sib = $st->fetchAll();
    if ($sib) {
        echo '<h2>第' . h($g['id']) . '群 ' . h($g['name']) . 'のほかの項目</h2>';
        echo '<div class="tscroll"><table class="t"><tr><th style="width:16%">番号</th><th>項目</th></tr>';
        foreach ($sib as $s) {
            echo '<tr><td>' . h($s['id']) . '</td><td><a href="' . h($SELF . '/item/' . rawurlencode($s['id'])) . '">'
               . h($s['name']) . '</a></td></tr>';
        }
        echo '</table></div>';
    }
    store_html('knintei-item');
    foot_html();
    exit;
}

// ---- /group/<n> 群 ---------------------------------------------------------
if (preg_match('#^/group/([^/]+)$#', $path, $m)) {
    $st = $db->prepare('SELECT * FROM groups WHERE id = ?'); $st->execute(array(rawurldecode($m[1])));
    $g = $st->fetch();
    if (!$g) { http_response_code(404); head_html('見つかりません｜' . $SITE, '', '/'); echo '<h1>見つかりません</h1>'; foot_html(); exit; }
    $st = $db->prepare('SELECT * FROM items WHERE grp = ? ORDER BY sort'); $st->execute(array($g['id']));
    $rows = $st->fetchAll();
    $title = '要介護認定の調査項目・第' . $g['id'] . '群「' . $g['name'] . '」' . count($rows) . '問の一覧';
    $desc = '認定調査の第' . $g['id'] . '群 ' . $g['name'] . '（' . count($rows) . '問）を、選択肢と定義つきで並べています。厚生労働省の認定調査員テキストから引用。';
    $ld = array(array('@type' => 'ItemList', 'name' => $title, 'numberOfItems' => count($rows),
        'itemListElement' => array()), array('@type' => 'BreadcrumbList', 'itemListElement' => array(
            array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $BASE . '/'),
            array('@type' => 'ListItem', 'position' => 2, 'name' => '第' . $g['id'] . '群 ' . $g['name']))));
    $i = 1;
    foreach ($rows as $r) {
        $ld[0]['itemListElement'][] = array('@type' => 'ListItem', 'position' => $i++,
            'name' => $r['name'], 'url' => $BASE . '/item/' . rawurlencode($r['id']));
    }
    head_html($title, $desc, '/group/' . $g['id'], $ld);
    echo '<h1>第' . h($g['id']) . '群　' . h($g['name']) . '</h1>';
    echo '<p class="lead">' . count($rows) . '問。項目名を押すと、定義と選択基準が出ます。</p>';
    foreach ($rows as $r) { item_html($db, $r); }
    echo '<h2>ほかの群</h2><div class="cols">';
    foreach ($db->query('SELECT * FROM groups ORDER BY sort') as $o) {
        if ($o['id'] === $g['id']) { continue; }
        echo '<div><a href="' . h($SELF . '/group/' . $o['id']) . '">第' . h($o['id']) . '群 '
           . h($o['name']) . '（' . h($o['n']) . '問）</a></div>';
    }
    echo '</div>';
    store_html('knintei-group');
    foot_html();
    exit;
}

// ---- /kubun 区分の境目 -----------------------------------------------------
if ($path === '/kubun') {
    $rows = $db->query('SELECT * FROM kubun ORDER BY sort')->fetchAll();
    $title = '要支援と要介護の違い｜要介護1〜5・要支援1〜2の境目（省令の条文つき）';
    $desc = '要介護度の区分は要介護認定等基準時間で決まります。要介護1は32分以上50分未満、要介護5は110分以上。'
          . '要支援2と要介護1は同じ時間帯で、状態の維持・改善の見込みで分かれます。根拠の条文つき。';
    head_html($title, $desc, '/kubun', array(array('@type' => 'BreadcrumbList', 'itemListElement' => array(
        array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $BASE . '/'),
        array('@type' => 'ListItem', 'position' => 2, 'name' => '区分の境目')))));
    echo '<h1>要支援と要介護の違い、区分の境目</h1>';
    echo '<p class="lead">区分は<strong>要介護認定等基準時間</strong>で決まります。境目は省令に書いてあります。</p>';
    echo '<div class="tscroll"><table class="t"><tr><th style="width:26%">区分</th><th>要介護認定等基準時間</th></tr>';
    foreach ($rows as $k) {
        echo '<tr><td><strong>' . h($k['name']) . '</strong></td><td>' . h(kubun_range($k))
           . ($k['text'] ? '<br><span class="src">' . h($k['text']) . '</span>' : '') . '</td></tr>';
    }
    echo '</table></div>';
    law_box($db, '要介護認定等に係る介護認定審査会による審査及び判定の基準等に関する省令', '1', 420);

    echo '<h2>要介護認定等基準時間は、介護にかけている時間ではありません</h2>';
    echo '<p>「うちは1日3時間介護している」という時間とは別のものです。省令はこう定めています。</p>';
    law_box($db, '要介護認定等に係る介護認定審査会による審査及び判定の基準等に関する省令', '3', 460);
    echo '<p>入浴・排せつ・食事等の介護、洗濯・掃除等の家事援助、徘徊に対する探索や不潔な行為の後始末、'
       . '機能訓練、輸液の管理やじょく瘡の処置。この5つについて、1日あたりどれだけの手間がかかるかを'
       . '推計した時間です。<strong>介護の手間の総量をあらわす物差し</strong>だと考えてください。</p>';

    echo '<h2>だから、このサイトは区分を当てません</h2>';
    echo '<div class="panel"><p style="margin:0">上の条文に「<strong>厚生労働大臣の定める方法により推計される時間</strong>」とあります。'
       . 'この方法が一次判定ソフトの計算で、<strong>その係数は公開されていません</strong>。'
       . '調査の回答を入れれば区分が出る、という計算はできません。</p>';
    echo '<p style="margin:12px 0 0">「あなたは要介護2です」と書いてしまうと、支給限度額が変わる話で嘘をつくことになります。'
       . 'このサイトは<a href="' . h($SELF) . '/">調査で何を聞かれるか</a>と、区分の境目までを出します。'
       . '認定は市区町村の介護認定審査会が行います。</p></div>';

    echo '<h2>要支援2と要介護1は、時間が同じです</h2>';
    echo '<p>どちらも32分以上50分未満。分かれるのは、状態の維持・改善の見込みがあるかどうかです。</p>';
    law_box($db, '要介護認定等に係る介護認定審査会による審査及び判定の基準等に関する省令', '2', 420);
    echo '<p><a class="btn ghost" href="' . h($SELF) . '/">認定調査の' . h($META['n_items']) . '問を見る</a> '
       . '<a class="btn ghost" href="' . h($SELF) . '/nagare">申請の流れを見る</a></p>';
    store_html('knintei-kubun');
    foot_html();
    exit;
}

// ---- /nagare 申請の流れ ----------------------------------------------------
if ($path === '/nagare') {
    $title = '介護認定までの流れ｜申請から結果まで30日以内（介護保険法27条）';
    $desc = '要介護認定の申請から結果が出るまでの流れを、根拠の条文つきで。申請は市区町村へ、認定は原則30日以内。'
          . '認定調査と主治医意見書の2つで審査されます。';
    head_html($title, $desc, '/nagare', array(array('@type' => 'BreadcrumbList', 'itemListElement' => array(
        array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $BASE . '/'),
        array('@type' => 'ListItem', 'position' => 2, 'name' => '申請の流れ')))));
    echo '<h1>介護認定までの流れ</h1>';
    echo '<p class="lead">申請してから結果が届くまでを、根拠の条文と一緒に並べます。'
       . '窓口はお住まいの市区町村です。</p>';
    $steps = array(
        array('1', '市区町村に申請する', '申請書に被保険者証を添えて市区町村へ出します。'
            . '居宅介護支援事業者や地域包括支援センターに代行してもらうこともできます（介護保険法27条1項）。'
            . '<strong>本人や家族が書けないときに代わりに出せる</strong>のは、知っておくと違います。'),
        array('2', '認定調査を受ける', '市区町村の職員などが自宅や施設を訪ねて、'
            . '<a href="' . h($SELF) . '/">基本調査' . h($META['n_items']) . '問</a>を聞き取ります。'
            . 'このとき、答えに迷った内容は<strong>特記事項</strong>として文章で残されます。'),
        array('3', '主治医意見書が出される', '市区町村が主治医に意見書を求めます。'
            . 'かかりつけ医がいないときは、市区町村が指定する医師の診断を受けます（法27条3項）。'),
        array('4', '一次判定', '基本調査の結果から、要介護認定等基準時間が'
            . '「厚生労働大臣の定める方法」で推計されます。'
            . '<a href="' . h($SELF) . '/kubun">この推計方法は公開されていません</a>。'),
        array('5', '介護認定審査会（二次判定）', '保健・医療・福祉の専門家が、'
            . '一次判定の結果に特記事項と主治医意見書を合わせて審査します。'
            . '<strong>一次判定と違う区分になることがあります</strong>。'),
        array('6', '結果が届く', '原則として申請から30日以内。'
            . '調査に日数がかかるときは延長され、理由と見込み期間が通知されます（法27条11項）。'),
    );
    foreach ($steps as $s) {
        echo '<div class="panel"><h3 style="margin-top:0"><span class="src">STEP ' . h($s[0]) . '</span><br>'
           . h($s[1]) . '</h3><p style="margin:0;font-size:15px">' . $s[2] . '</p></div>';
    }
    echo '<h2>30日以内、の根拠</h2>';
    law_box($db, '介護保険法', '27', 520);
    echo '<h2>65歳になったら</h2>';
    echo '<p>介護保険の第1号被保険者は65歳以上です。40歳から64歳の方（第2号被保険者）は、'
       . '加齢に伴う特定の病気が原因のときに申請できます。</p>';
    law_box($db, '介護保険法', '9', 300);
    store_html('knintei-nagare');
    foot_html();
    exit;
}

// ---- /city/<都道府県>/<市区町村> -------------------------------------------
if (preg_match('#^/city/([^/]+)/([^/]+)$#', $path, $m)) {
    $c = city_by_slug($db, rawurldecode($m[1]), rawurldecode($m[2]));
    if (!$c) { http_response_code(404); head_html('見つかりません｜' . $SITE, '', '/cities'); echo '<h1>見つかりません</h1>'; foot_html(); exit; }
    $full = $c['pref'] . $c['city'];
    $title = $full . 'の要介護認定｜申請の窓口と認定調査で聞かれること';
    $desc = $full . 'で要介護認定を申請するときの窓口と、認定調査' . $META['n_items'] . '問の内容。'
          . ($c['e65'] ? $full . 'には65歳以上が' . n($c['e65']) . '人（' . $c['rate65'] . '%）います。' : '');
    $ld = array(array('@type' => 'BreadcrumbList', 'itemListElement' => array(
        array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $BASE . '/'),
        array('@type' => 'ListItem', 'position' => 2, 'name' => '市区町村の窓口', 'item' => $BASE . '/cities'),
        array('@type' => 'ListItem', 'position' => 3, 'name' => $c['pref'], 'item' => $BASE . '/pref/' . rawurlencode($c['pref'])),
        array('@type' => 'ListItem', 'position' => 4, 'name' => $c['city']))));
    head_html($title, $desc, '/city/' . rawurlencode($c['pref']) . '/' . rawurlencode($c['slug']), $ld);
    echo '<p class="src"><a href="' . h($SELF) . '/">' . h($SITE) . '</a> ＞ '
       . '<a href="' . h($SELF) . '/cities">市区町村の窓口</a> ＞ '
       . '<a href="' . h($SELF . '/pref/' . rawurlencode($c['pref'])) . '">' . h($c['pref']) . '</a></p>';
    echo '<h1>' . h($full) . 'の要介護認定</h1>';

    if ($c['e65']) {
        echo '<div class="panel"><div class="cols" style="align-items:baseline">';
        echo '<div><div class="src">65歳以上の人</div><div class="big">' . n($c['e65']) . '<span style="font-size:16px">人</span></div></div>';
        echo '<div><div class="src">65歳以上の割合</div><div class="big">' . h($c['rate65']) . '<span style="font-size:16px">%</span></div></div>';
        echo '<div><div class="src">75歳以上の人</div><div class="big">' . n($c['e75']) . '<span style="font-size:16px">人</span></div></div>';
        echo '</div>';
        $nat = (float)$META['rate65_national'];
        $cmp = $c['rate65'] > $nat ? '全国平均（' . $nat . '%）より高く' : '全国平均（' . $nat . '%）より低く';
        echo '<p style="margin:14px 0 0">' . h($full) . 'の人口は' . n($c['pop']) . '人で、'
           . 'そのうち<strong>65歳以上が' . n($c['e65']) . '人</strong>、割合は<strong>' . h($c['rate65']) . '%</strong>です'
           . '（' . h($META['age_asof']) . '現在・住民基本台帳）。' . h($cmp) . '、'
           . '高齢化率は全国' . n($c['rate65_total']) . '市区町村のなかで<strong>' . n($c['rate65_rank']) . '番目</strong>に高い順位です。</p>';
        echo '<p class="src" style="margin-top:8px">介護保険の第1号被保険者は65歳以上（介護保険法9条1号）なので、'
           . 'この' . n($c['e65']) . '人が' . h($c['city']) . 'で要介護認定を申請しうる方の土台にあたります。'
           . '実際に認定を受けている人数は市区町村が公表しています。</p>';
        echo '</div>';
    }

    echo '<h2>申請の窓口</h2>';
    echo '<p>要介護認定の申請は<strong>' . h($c['city']) . ($c['seirei_ku'] ? '役所' : '') . 'の介護保険担当課</strong>、'
       . 'または地域包括支援センターです。'
       . ($c['seirei_ku']
          ? h($c['parent']) . 'は政令指定都市なので、<strong>' . h($c['city']) . '役所</strong>が窓口になります。'
          : '')
       . '本人や家族が書けないときは、居宅介護支援事業者・地域包括支援センター・介護保険施設に'
       . '<strong>代わりに出してもらえます</strong>（介護保険法27条1項）。</p>';
    echo '<p class="src">課の名前・電話・受付時間・様式は' . h($c['city']) . 'が決めています。'
       . '行く前に' . h($c['city']) . 'の公式ページか電話で確かめてください。'
       . 'このサイトは市区町村の公式ページの本文を転載していないため、課名や電話は載せていません。</p>';

    echo '<h2>' . h($c['city']) . 'で認定調査を受けるときに聞かれること</h2>';
    echo '<p class="lead">認定調査の内容は全国共通です（厚生労働省の認定調査票）。' . h($META['n_items']) . '問あります。</p>';
    echo '<div class="tscroll"><table class="t"><tr><th style="width:38%">群</th><th class="n">問</th><th>中身</th></tr>';
    foreach ($db->query('SELECT * FROM groups ORDER BY sort') as $g) {
        $st = $db->prepare('SELECT name FROM items WHERE grp = ? ORDER BY sort LIMIT 4');
        $st->execute(array($g['id']));
        $ex = array();
        foreach ($st->fetchAll() as $x) { $ex[] = $x['name']; }
        echo '<tr><td><a href="' . h($SELF . '/group/' . $g['id']) . '">第' . h($g['id']) . '群 ' . h($g['name']) . '</a></td>'
           . '<td class="n">' . h($g['n']) . '</td>'
           . '<td style="font-size:13px;color:var(--mut)">' . h(implode('・', $ex)) . ' など</td></tr>';
    }
    echo '</table></div>';
    echo '<p><a class="btn ghost" href="' . h($SELF) . '/">' . h($META['n_items']) . '問を全部見る</a> '
       . '<a class="btn ghost" href="' . h($SELF) . '/kubun">区分の境目を見る</a> '
       . '<a class="btn ghost" href="' . h($SELF) . '/nagare">申請の流れを見る</a></p>';

    echo '<h2>認定が出たあと</h2>';
    echo '<p>ケアプランを作る居宅介護支援事業所や、訪問介護・訪問看護の事業所を探すことになります。'
       . '当社の別のシステムで、住所から近くの事業所を引けます。</p><div class="cols">';
    foreach (array(
        array('訪問介護・ケアマネ事業所', 'https://kurage.exbridge.jp/kkaigo.php/?ref=knintei-city'),
        array('訪問看護ステーション', 'https://kurage.exbridge.jp/khokan.php/?ref=knintei-city'),
        array('使える制度を探す', 'https://kurage.exbridge.jp/kseido.php/?ref=knintei-city'),
    ) as $x) { echo '<div><a href="' . h($x[1]) . '">' . h($x[0]) . '</a></div>'; }
    echo '</div>';
    store_html('knintei-city', $full . 'の窓口名・電話・様式のリンクを足して、そのまま使えます。');

    $st = $db->prepare('SELECT slug, city, e65 FROM cities WHERE pref = ? AND code <> ? ORDER BY e65 DESC LIMIT 12');
    $st->execute(array($c['pref'], $c['code']));
    $near = $st->fetchAll();
    if ($near) {
        echo '<h2>' . h($c['pref']) . 'のほかの市区町村</h2><div class="cols">';
        foreach ($near as $x) {
            echo '<div><a href="' . h($SELF . '/city/' . rawurlencode($c['pref']) . '/' . rawurlencode($x['slug'])) . '">'
               . h($x['city']) . '</a></div>';
        }
        echo '</div><p class="src"><a href="' . h($SELF . '/pref/' . rawurlencode($c['pref'])) . '">'
           . h($c['pref']) . 'の全市区町村を見る</a></p>';
    }
    foot_html();
    exit;
}

// ---- /pref/<都道府県> ------------------------------------------------------
if (preg_match('#^/pref/([^/]+)$#', $path, $m)) {
    $pref = rawurldecode($m[1]);
    $st = $db->prepare('SELECT * FROM cities WHERE pref = ? ORDER BY code');
    $st->execute(array($pref));
    $rows = $st->fetchAll();
    if (!$rows) { http_response_code(404); head_html('見つかりません｜' . $SITE, '', '/cities'); echo '<h1>見つかりません</h1>'; foot_html(); exit; }
    $sum = 0; $pop = 0;
    foreach ($rows as $r) { if (!$r['seirei_ku']) { $sum += (int)$r['e65']; $pop += (int)$r['pop']; } }
    $title = $pref . 'の市区町村別・要介護認定の窓口一覧表（' . count($rows) . '件）';
    $desc = $pref . 'の' . count($rows) . '市区町村ごとに、要介護認定の申請窓口と認定調査の内容をまとめています。'
          . $pref . '全体では65歳以上が' . n($sum) . '人います。';
    head_html($title, $desc, '/pref/' . rawurlencode($pref), array(
        array('@type' => 'BreadcrumbList', 'itemListElement' => array(
            array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $BASE . '/'),
            array('@type' => 'ListItem', 'position' => 2, 'name' => '市区町村の窓口', 'item' => $BASE . '/cities'),
            array('@type' => 'ListItem', 'position' => 3, 'name' => $pref)))));
    echo '<h1>' . h($pref) . 'の要介護認定の窓口</h1>';
    echo '<p class="lead">' . h($pref) . 'の' . count($rows) . '市区町村。'
       . h($pref) . '全体では65歳以上が' . n($sum) . '人（人口' . n($pop) . '人の'
       . h(number_format($pop ? $sum / $pop * 100 : 0, 1)) . '%）です（' . h($META['age_asof']) . '現在）。</p>';
    echo '<div class="tscroll"><table class="t"><tr><th>市区町村</th><th class="n">65歳以上</th><th class="n">割合</th><th class="n">75歳以上</th></tr>';
    foreach ($rows as $r) {
        echo '<tr><td><a href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($r['slug'])) . '">'
           . h($r['city']) . '</a></td>'
           . '<td class="n">' . ($r['e65'] !== null ? n($r['e65']) : '—') . '</td>'
           . '<td class="n">' . ($r['rate65'] !== null ? h($r['rate65']) . '%' : '—') . '</td>'
           . '<td class="n">' . ($r['e75'] !== null ? n($r['e75']) : '—') . '</td></tr>';
    }
    echo '</table></div>';
    echo '<p class="src">政令指定都市は区ごとに窓口が分かれるため、市の行と区の行の両方を載せています。'
       . '都道府県の合計には区の数を重ねて数えないようにしています。</p>';
    store_html('knintei-pref');
    foot_html();
    exit;
}

// ---- /cities ---------------------------------------------------------------
if ($path === '/cities') {
    global $MASCOT;
    $title = '全国' . n($META['n_cities']) . '市区町村の要介護認定の窓口';
    head_html($title . '｜' . $SITE,
        '都道府県から市区町村を選ぶと、要介護認定の申請窓口と、その市区町村に65歳以上が何人いるかが出ます。', '/cities');
    echo '<div class="hero"><div>';
    echo '<span class="eyebrow">全国' . n($META['n_cities']) . '市区町村</span>';
    echo '<h1>市区町村の窓口</h1>';
    echo '<p class="lead">政令指定都市' . h($META['n_seirei']) . '市の区と東京23区を含みます。'
       . '全国では65歳以上が' . n($META['e65_national']) . '人（' . h($META['rate65_national']) . '%）います。</p>';
    echo '</div><img src="' . h($MASCOT) . '" alt="" loading="lazy" width="265" height="300" decoding="async"></div>';
    echo '<div class="panel"><div class="cols">';
    foreach ($db->query('SELECT pref, pref_code, COUNT(*) c FROM cities GROUP BY pref ORDER BY pref_code') as $r) {
        echo '<div><a href="' . h($SELF . '/pref/' . rawurlencode($r['pref'])) . '">' . h($r['pref']) . '</a>'
           . ' <span class="src">' . n($r['c']) . '</span></div>';
    }
    echo '</div></div>';
    foot_html();
    exit;
}

// ---- /about ----------------------------------------------------------------
if ($path === '/about') {
    global $MASCOT;
    head_html('このサイトについて｜' . $SITE,
        '区分を判定しない理由と、データの出どころ、できないことを書いています。', '/about');
    echo '<div class="hero"><div>';
    echo '<span class="eyebrow">Kurage のシステム ／ 株式会社エクスブリッジ</span>';
    echo '<h1>このサイトについて</h1>';
    echo '<p class="lead">要介護度を判定しない理由と、データの出どころ、できないことを書いています。'
       . 'PHP 1ファイルと SQLite 1本だけで動くので、ご自分のサーバーにも置けます。</p>';
    echo '</div><img src="' . h($MASCOT) . '" alt="" loading="lazy" width="265" height="300" decoding="async"></div>';

    echo '<div class="panel"><h3 style="margin-top:0">要介護度を判定しません</h3>';
    echo '<p>「調査の答えを入れると要介護度が出る」という作りにはしていません。できないからです。</p>';
    echo '<p>区分は要介護認定等基準時間で決まり、その時間は省令3条で'
       . '「<strong>厚生労働大臣の定める方法により推計される時間</strong>」と定められています。'
       . 'この方法＝一次判定ソフトの計算に使われる係数は公開されていません。'
       . '公開データから同じ計算を再現することはできません。</p>';
    echo '<p>「目安です」と書いて数字を出すこともできますが、区分が変われば支給限度額が変わります。'
       . '外したときに困るのは使う人なので、<a href="' . h($SELF) . '/kubun">境目と根拠の条文</a>までを出して止めています。</p></div>';

    echo '<div class="panel"><h3 style="margin-top:0">調査項目は、言い換えずに載せています</h3>';
    echo '<p>認定調査は選択肢の文言そのものが判断の基準です。'
       . '「つかまらないでできる」と「何かにつかまればできる」の差が、そのまま結果に効きます。'
       . '読みやすくするために言い換えると、別のものになります。</p>';
    echo '<p>項目名・選択肢・定義・選択基準は、すべて' . h($META['items_source'])
       . 'からそのまま引用しています。' . h($META['n_items']) . '問・選択肢' . h($META['n_options']) . '個。</p></div>';

    echo '<div class="panel"><h3 style="margin-top:0">「74項目」の数え方</h3>';
    echo '<p>設問は' . h($META['n_items']) . '問しかありません。74になるのは数え方が違うためです。</p>';
    echo '<p class="src">' . h($META['count_note']) . '</p>';
    echo '<p>障害高齢者・認知症高齢者の日常生活自立度2つは74の外です（一次判定には使いません）。</p></div>';

    echo '<div class="panel"><h3 style="margin-top:0">できないこと</h3>';
    echo '<p>市区町村の課の名前・電話・受付時間・様式は載せていません。市区町村ごとに違い、'
       . '公式ページの本文を転載しない方針だからです。窓口は各市区町村の公式ページで確かめてください。</p>';
    echo '<p>認定の結果に納得できないときの不服申立てや、区分変更の見通しについては判断しません。'
       . '市区町村の介護保険担当課、地域包括支援センター、ケアマネジャーにご相談ください。</p></div>';

    echo '<div class="panel quiet"><h3 style="margin-top:0">当社に作らせることもできます</h3>';
    echo '<p>名古屋市内の会社なら、<a href="https://exbridge.jp/ai-it-komon.html?ref=knintei-about">AI-IT顧問契約</a>'
       . '（月15時間・税別150,000円）の期間中に構築できる商品は、商品代金をいただかず当社が設置まで行います。'
       . 'ご相談は<a href="https://exbridge.jp/">株式会社エクスブリッジ</a>へ。</p></div>';
    foot_html();
    exit;
}

// ---- / トップ ---------------------------------------------------------------
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$hits = array();
if ($q !== '') {
    $st = $db->prepare('SELECT * FROM items WHERE name LIKE ? OR definition LIKE ? OR options LIKE ? ORDER BY sort');
    $st->execute(array('%' . $q . '%', '%' . $q . '%', '%' . $q . '%'));
    $hits = $st->fetchAll();
}
$title = $q !== ''
    ? '「' . $q . '」を含む認定調査の項目｜要介護認定ナビ'
    : '要介護認定の調査で何を聞かれるか｜認定調査' . $META['n_items'] . '問の一覧表と区分の境目';
$desc = $q !== ''
    ? '認定調査の項目を「' . $q . '」で探した結果です。'
    : '要介護認定の認定調査で実際に聞かれる' . $META['n_items'] . '問を、選択肢と定義つきで並べています。'
      . '厚生労働省の認定調査票そのまま。区分の境目は省令の条文つき。'
      . '全国' . n($META['n_cities']) . '市区町村の窓口ページつき。';
head_html($title, $desc, '/');

if ($q === '') {
    echo '<div class="hero"><div>';
    echo '<span class="eyebrow">厚生労働省の認定調査票そのまま</span>';
    echo '<h1>要介護認定の調査で、何を聞かれるか</h1>';
    echo '<p class="lead">認定調査は' . h($META['n_items']) . '問あります。'
       . '当日いきなり聞かれると答えに困るものが多いので、<strong>先に中身を見ておけます</strong>。'
       . '文言は言い換えていません。</p>';
    echo '</div><img src="' . h($MASCOT) . '" alt="" loading="lazy" width="265" height="300" decoding="async"></div>';
}

echo '<form class="panel" method="get" action="' . h($SELF) . '/">';
echo '<label class="src" for="q">気になる言葉で探す（例: 寝返り、入浴、物忘れ、薬）</label>';
echo '<p style="margin:8px 0 0;display:flex;gap:10px;flex-wrap:wrap">'
   . '<input type="text" id="q" name="q" value="' . h($q) . '" placeholder="調査項目を探す" '
   . 'style="flex:1 1 240px;min-width:0;font:inherit;font-size:16px;padding:12px 14px;border:1px solid var(--line);border-radius:8px">'
   . '<button class="btn" type="submit">探す</button></p></form>';

if ($q !== '') {
    echo '<h1>「' . h($q) . '」を含む項目</h1>';
    echo '<p class="lead">' . count($hits) . '件。項目名・定義・選択肢のどれかに含まれるものです。</p>';
    if (!$hits) { echo '<div class="panel"><p style="margin:0">見つかりませんでした。'
        . '<a href="' . h($SELF) . '/">' . h($META['n_items']) . '問を全部見る</a>と探しやすいかもしれません。</p></div>'; }
    foreach ($hits as $r) { item_html($db, $r); }
    foot_html();
    exit;
}

echo '<h2>先に知っておくとよいこと</h2>';
echo '<div class="panel"><ul style="margin:0;padding-left:20px">';
foreach (array(
    '<strong>「74項目」の設問は' . h($META['n_items']) . '問です。</strong>麻痺の有無を部位ごとに5、拘縮の有無を4と数え、特別な医療12を足すと74になります。',
    '<strong>要支援2と要介護1は、基準時間が同じです。</strong>どちらも32分以上50分未満。状態の維持・改善の見込みで分かれます。',
    '<strong>要介護認定等基準時間は、家で介護にかけている時間ではありません。</strong>介護の手間の総量をあらわす物差しです。',
    '<strong>申請は代わりに出してもらえます。</strong>居宅介護支援事業者・地域包括支援センター・介護保険施設が代行できます（介護保険法27条1項）。',
    '<strong>答えに迷った内容は特記事項に残ります。</strong>選択肢で表しきれないことは、文章で審査会に伝わります。',
) as $x) { echo '<li style="margin-bottom:8px">' . $x . '</li>'; }
echo '</ul></div>';

echo '<h2>認定調査の項目一覧表（全' . h($META['n_items']) . '問）</h2>';
echo '<p class="lead">項目名を押すと、定義と選択基準が出ます。すべて厚生労働省の認定調査員テキストからの引用です。</p>';
echo '<div class="tscroll"><table class="t"><tr><th style="width:14%">番号</th><th style="width:44%">項目</th><th>選択肢</th></tr>';
foreach ($db->query('SELECT * FROM groups ORDER BY sort') as $g) {
    echo '<tr class="gh"><td colspan="3"><a href="' . h($SELF . '/group/' . $g['id']) . '">第' . h($g['id'])
       . '群 ' . h($g['name']) . '</a> <span class="src">' . h($g['n']) . '問</span></td></tr>';
    $st = $db->prepare('SELECT * FROM items WHERE grp = ? ORDER BY sort');
    $st->execute(array($g['id']));
    foreach ($st->fetchAll() as $r) {
        $o = array();
        foreach (jd($r['options']) as $x) { $o[] = $x['no'] . '.' . $x['label']; }
        echo '<tr><td>' . h($r['id']) . '</td>'
           . '<td><a href="' . h($SELF . '/item/' . rawurlencode($r['id'])) . '">' . h($r['name']) . '</a></td>'
           . '<td style="font-size:13px;color:var(--mut)">' . h(mb_substr(implode(' / ', $o), 0, 64))
           . (mb_strlen(implode(' / ', $o)) > 64 ? '…' : '') . '</td></tr>';
    }
}
echo '<tr class="gh"><td colspan="3">その他 <span class="src">過去14日間にうけた特別な医療 '
   . h($META['n_medical']) . '項目</span></td></tr>';
$med = array();
foreach ($db->query('SELECT * FROM medical ORDER BY no') as $r) { $med[] = $r['no'] . '.' . $r['label']; }
echo '<tr><td>—</td><td colspan="2" style="font-size:13.5px">' . h(implode(' / ', $med)) . '</td></tr>';
echo '</table></div>';

echo '<h2>区分の境目</h2>';
echo '<p class="lead">区分は要介護認定等基準時間で決まります。境目は省令に書いてあります。'
   . '<strong>このサイトは区分を当てません。</strong></p>';
echo '<div class="tscroll"><table class="t"><tr><th style="width:30%">区分</th><th>要介護認定等基準時間</th></tr>';
foreach ($db->query('SELECT * FROM kubun ORDER BY sort') as $k) {
    echo '<tr><td><strong>' . h($k['name']) . '</strong></td><td>' . h(kubun_range($k)) . '</td></tr>';
}
echo '</table></div>';
echo '<p><a class="btn ghost" href="' . h($SELF) . '/kubun">境目と根拠の条文を見る</a> '
   . '<a class="btn ghost" href="' . h($SELF) . '/nagare">申請の流れを見る</a></p>';
store_html('knintei-top');

echo '<h2>市区町村の窓口を調べる</h2>';
echo '<p class="lead">全国' . n($META['n_cities']) . '市区町村ぶんのページがあります。'
   . 'その市区町村に65歳以上が何人いるかも載せています。</p>';
echo '<div class="panel"><div class="cols">';
foreach ($db->query('SELECT pref FROM cities GROUP BY pref ORDER BY pref_code') as $r) {
    echo '<div><a href="' . h($SELF . '/pref/' . rawurlencode($r['pref'])) . '">' . h($r['pref']) . '</a></div>';
}
echo '</div></div>';
foot_html();
