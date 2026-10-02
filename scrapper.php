<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);
set_time_limit(0);
ini_set('memory_limit', '512M');

$DIR = __DIR__;
$FILE = $DIR . '/channels_data.json';
$SRC_URL = 'https://raw.githubusercontent.com/iptv-org/iptv/refs/heads/master/streams/in.m3u';

$IPTV_CHANNELS_JSON = $DIR . '/iptv_channels.json';
$IPTV_LOGOS_JSON    = $DIR . '/iptv_logos.json';
$IPTV_FEEDS_JSON    = $DIR . '/iptv_feeds.json';
$JIO_JSON           = $DIR . '/jiotv.json';

$LANG_MAP = [
    'hin'=>'Hindi','tam'=>'Tamil','tel'=>'Telugu','ben'=>'Bengali','mar'=>'Marathi',
    'guj'=>'Gujarati','kan'=>'Kannada','mal'=>'Malayalam','pan'=>'Punjabi','urd'=>'Urdu',
    'ori'=>'Odia','asm'=>'Assamese','mai'=>'Maithili','san'=>'Sanskrit','kas'=>'Kashmiri',
    'kok'=>'Konkani','mni'=>'Manipuri','nep'=>'Nepali','bho'=>'Bhojpuri','awa'=>'Awadhi',
    'hne'=>'Chhattisgarhi','raj'=>'Rajasthani','gar'=>'Garhwali','kum'=>'Kumaoni',
    'doi'=>'Dogri','sat'=>'Santali','snd'=>'Sindhi','tcy'=>'Tulu','eng'=>'English',
    'rus'=>'Russian','spa'=>'Spanish','ory'=>'Odia','zho'=>'Chinese','heb'=>'Hebrew',
    'ind'=>'Indonesian','por'=>'Portuguese','gom'=>'Konkani','sgn'=>'Sign Language','fra'=>'French',
];
$CAT_MAP = [
    'animation'=>'Animation','auto'=>'Auto','business'=>'Business','classic'=>'Classic',
    'comedy'=>'Comedy','cooking'=>'Cooking','culture'=>'Culture','documentary'=>'Documentary',
    'education'=>'Education','entertainment'=>'Entertainment','family'=>'Family',
    'general'=>'General','kids'=>'Kids','lifestyle'=>'Lifestyle','movies'=>'Movies',
    'music'=>'Music','news'=>'News','outdoor'=>'Outdoor','relax'=>'Relax',
    'religious'=>'Religious','science'=>'Science','series'=>'Series','shop'=>'Shop',
    'sports'=>'Sports','travel'=>'Travel','weather'=>'Weather',
];

$BLACKLIST = [
    'Angel TV Africa','Angel TV America','Angel TV Arabia','Angel TV Australia',
    'Angel TV Chinese','Angel TV Europe','Angel TV FarEast','Angel TV Hebrew',
    'Angel TV Indo-China','Angel TV Indonesia','Angel TV Nepal','Angel TV Portuguese',
    'Angel TV Russian','Angel TV Spanish',
    'Harvest USA','Jonack TV','Namdhari','Sairam TV','Shalom Global',
    'SVBC Sri Venkateswara Bhakti Channel','Vyas NIC','Zainabia Channel',
    'Hosanna TV Hindi','Hosanna TV Global',
];

function norm($n) {
    $n = strtolower((string)$n);
    $n = str_replace('&', 'and', $n);
    $n = preg_replace('/\b(hd|sd|fhd|uhd|4k)\b/', '', $n);
    return preg_replace('/[^a-z0-9]/', '', $n);
}
$BLACKSET = [];
foreach ($BLACKLIST as $b) $BLACKSET[norm($b)] = true;

$LANG_BLOCK = ["Nepali","Spanish","Konkani","Chinese","Hebrew","Indonesian","Portuguese","Chhattisgarhi","Santali","Sign Language","French","Russian"];

function cleanName($n) {
    $n = trim((string)$n);
    if ($n === '') return '';
    $n = preg_replace('/[\(\[\{][^\)\]\}]*[\)\]\}]/u', ' ', $n);
    $n = preg_replace('/\s+(HD|SD|FHD|UHD|4K|\+)\s*$/i', '', $n);
    $n = preg_replace('/\s+/', ' ', $n);
    return trim($n, " -_.,|");
}
function isBlacklisted($name) { global $BLACKSET; return isset($BLACKSET[norm($name)]); }

/* IPTV: channels.json → id, name, categories, country
   feeds.json    → id → languages
   logos.json    → id → logo */
function loadIptvData($chFile, $logoFile, $feedFile) {
    global $LANG_MAP, $CAT_MAP;
    $idToLogo=[]; $idToName=[]; $nameToLogo=[]; $idCats=[]; $idLangs=[]; $idCountry=[];

    if (file_exists($chFile)) {
        $data = json_decode(file_get_contents($chFile), true);
        if (is_array($data)) {
            foreach ($data as $c) {
                $id   = $c['id']   ?? '';
                $name = $c['name'] ?? '';
                if (!$id) continue;
                if ($name) $idToName[$id] = $name;
                $idCountry[$id] = $c['country'] ?? '';

                $cats = $c['categories'] ?? [];
                $catNames = [];
                foreach ($cats as $x) $catNames[] = $CAT_MAP[$x] ?? ucfirst($x);
                $idCats[$id] = $catNames;

                // Kuch versions me languages bhi hoti hain channels.json me
                $langs = $c['languages'] ?? [];
                if ($langs) {
                    $ln = [];
                    foreach ($langs as $x) $ln[] = $LANG_MAP[$x] ?? strtoupper($x);
                    $idLangs[$id] = $ln;
                }
            }
        }
    }

    if (file_exists($feedFile)) {
        $data = json_decode(file_get_contents($feedFile), true);
        if (is_array($data)) {
            foreach ($data as $f) {
                $cid   = $f['channel'] ?? '';
                $langs = $f['languages'] ?? [];
                if (!$cid || !$langs) continue;
                if (!isset($idLangs[$cid])) $idLangs[$cid] = [];
                foreach ($langs as $x) {
                    $ln = $LANG_MAP[$x] ?? strtoupper($x);
                    if (!in_array($ln, $idLangs[$cid])) $idLangs[$cid][] = $ln;
                }
            }
        }
    }

    if (file_exists($logoFile)) {
        $data = json_decode(file_get_contents($logoFile), true);
        if (is_array($data)) {
            foreach ($data as $l) {
                $cid = $l['channel'] ?? ''; $url = $l['url'] ?? '';
                if (!$cid || !$url) continue;
                if (!isset($idToLogo[$cid])) $idToLogo[$cid] = $url;
                if (isset($idToName[$cid])) {
                    $n = norm($idToName[$cid]);
                    if ($n && !isset($nameToLogo[$n])) $nameToLogo[$n] = $url;
                }
            }
        }
    }
    return [$idToLogo, $idToName, $nameToLogo, $idCats, $idLangs, $idCountry];
}

function loadJioLogos($file) {
    $map = [];
    if (!file_exists($file)) { echo "  [JIO] file not found: $file\n"; return $map; }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) { echo "  [JIO] json invalid\n"; return $map; }
    $list = $data['result'] ?? $data;
    if (!is_array($list)) { echo "  [JIO] result not array\n"; return $map; }

    $CDN = "https://jiotvimages.cdn.jio.com/dare_images/images/";
    foreach ($list as $c) {
        if (!is_array($c)) continue;
        $name = $c["channel_name"] ?? "";
        $logo = $c["logoUrl"] ?? "";
        if (!$name || !$logo) continue;
        if (strpos($logo, "http") !== 0) $logo = $CDN . ltrim($logo, "/");
        $n = norm($name);
        if ($n !== "" && !isset($map[$n])) $map[$n] = $logo;
    }
    return $map;
}

echo "Loading sources...\n";
list($IPTV_BY_ID, $IPTV_ID_NAME, $IPTV_BY_NAME, $IPTV_CATS, $IPTV_LANGS, $IPTV_COUNTRY) =
    loadIptvData($IPTV_CHANNELS_JSON, $IPTV_LOGOS_JSON, $IPTV_FEEDS_JSON);
echo "  logos by-id:" . count($IPTV_BY_ID) . " | cats:" . count($IPTV_CATS) . " | langs:" . count($IPTV_LANGS) . "\n";
$JIO_MAP = loadJioLogos($JIO_JSON);
echo "  JioTV: " . count($JIO_MAP) . "\n";

echo "Fetching...\n";
$ch = curl_init($SRC_URL);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>60, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0, CURLOPT_USERAGENT=>'Mozilla/5.0', CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]);
$raw = curl_exec($ch);
curl_close($ch);
if (!$raw) die("Fetch failed\n");
echo "Fetched: " . strlen($raw) . " bytes\n";

$rows = []; $meta = ''; $blocked = 0;
foreach (explode("\n", $raw) as $line) {
    $line = trim($line);
    if ($line === '' || $line === '#EXTM3U') continue;
    if (stripos($line, '#EXTINF:') === 0) { $meta = $line; continue; }
    if ($meta !== '' && (strpos($line,'http')===0 || strpos($line,'rtmp')===0)) {
        $name = '';
        if (preg_match('/,(.+)$/', $meta, $m)) $name = trim($m[1]);
        if ($name === '') { $meta = ''; continue; }
        $clean = cleanName($name);
        if ($clean === '') { $meta = ''; continue; }
        if (isBlacklisted($clean)) { $blocked++; $meta = ''; continue; }
        $tvgId = '';
        if (preg_match('/tvg-id="([^"]*)"/i', $meta, $m)) $tvgId = $m[1];
        $logo = '';
        if (preg_match('/tvg-logo="([^"]*)"/i', $meta, $m)) $logo = trim($m[1]);
        $group = '';
        if (preg_match('/group-title="([^"]*)"/i', $meta, $m)) $group = trim($m[1]);
        $rows[] = ['name'=>$clean,'url'=>trim($line),'tvg_id'=>$tvgId,'logo'=>$logo,'group'=>$group];
        $meta = '';
    }
}
echo "Rows: " . count($rows) . " (blocked: $blocked)\n";

echo "Checking streams...\n";
$total = count($rows); $MAX = 100;
$mh = curl_multi_init(); $map = []; $next = 0; $active = 0;
$add = function($i) use (&$rows, &$mh, &$map) {
    $ch = curl_init($rows[$i]['url']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>3, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>8, CURLOPT_USERAGENT=>'Mozilla/5.0 (Linux; Android 10)', CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0, CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4, CURLOPT_RANGE=>'0-2048']);
    curl_multi_add_handle($mh, $ch);
    $map[(int)$ch] = $i;
};
while ($next < $total && $active < $MAX) { $add($next++); $active++; }
do {
    do { $m = curl_multi_exec($mh, $run); } while ($m === CURLM_CALL_MULTI_PERFORM);
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $i = $map[(int)$ch] ?? null;
        if ($i !== null) {
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = (string)curl_multi_getcontent($ch);
            $ctype = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
            $url = $rows[$i]['url'];
            $status = 'dead';
            if ($code === 401 || $code === 403) $status = 'protected';
            elseif ($code >= 200 && $code < 400) {
                if (preg_match('/\.m3u8|m3u8/i', $url) || strpos($ctype, 'mpegurl') !== false) {
                    if (stripos($body, '#EXTM3U') !== false || stripos($body, '#EXT-X-') !== false) $status = 'working';
                } else $status = 'working';
            }
            $rows[$i]['status'] = $status;
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
            unset($map[(int)$ch]); $active--;
            if ($next < $total) { $add($next++); $active++; }
        }
    }
    if ($run) curl_multi_select($mh, 0.05);
} while ($run || $active);
curl_multi_close($mh);

$retry = [];
foreach ($rows as $i => $r) if (($r['status'] ?? '') === 'dead') $retry[] = $i;
echo "Retry: " . count($retry) . "\n";
if ($retry) {
    $mh2 = curl_multi_init(); $map2 = []; $n2 = 0; $a2 = 0; $MAX2 = 30;
    $t2 = count($retry);
    $add2 = function($x) use (&$rows, &$retry, &$mh2, &$map2) {
        $i = $retry[$x];
        $ch = curl_init($rows[$i]['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>3, CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>20, CURLOPT_USERAGENT=>'Mozilla/5.0', CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0, CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]);
        curl_multi_add_handle($mh2, $ch);
        $map2[(int)$ch] = $i;
    };
    while ($n2 < $t2 && $a2 < $MAX2) { $add2($n2++); $a2++; }
    do {
        do { $mm = curl_multi_exec($mh2, $run2); } while ($mm === CURLM_CALL_MULTI_PERFORM);
        while ($info = curl_multi_info_read($mh2)) {
            $ch = $info['handle'];
            $i = $map2[(int)$ch] ?? null;
            if ($i !== null) {
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body = (string)curl_multi_getcontent($ch);
                $ctype = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
                $status = 'dead';
                if ($code === 401 || $code === 403) $status = 'protected';
                elseif ($code >= 200 && $code < 400) {
                    if (preg_match('/\.m3u8|m3u8/i', $rows[$i]['url']) || strpos($ctype, 'mpegurl') !== false) {
                        if (stripos($body, '#EXTM3U') !== false || stripos($body, '#EXT-X-') !== false) $status = 'working';
                    } else $status = 'working';
                }
                $rows[$i]['status'] = $status;
                curl_multi_remove_handle($mh2, $ch); curl_close($ch);
                unset($map2[(int)$ch]); $a2--;
                if ($n2 < $t2) { $add2($n2++); $a2++; }
            }
        }
        if ($run2) curl_multi_select($mh2, 0.05);
    } while ($run2 || $a2);
    curl_multi_close($mh2);
}

$w=0;$p=0;$d=0;
foreach ($rows as $r) { $s = $r['status'] ?? 'dead'; if ($s === 'working') $w++; elseif ($s === 'protected') $p++; else $d++; }
echo "Working: $w | Protected: $p | Dead: $d\n";

$channels = []; $urlSeen = [];
$logoFrom = ['m3u'=>0,'iptv-id'=>0,'iptv-name'=>0,'jio'=>0,'none'=>0];
$noLogoList = [];

foreach ($rows as $r) {
    $s = $r['status'] ?? 'dead';
    if ($s === 'dead') continue;
    $n = norm($r['name']);
    if ($n === '') continue;
    if (isBlacklisted($r['name'])) continue;
    $url = $r['url'];
    if (isset($urlSeen[$n][$url])) continue;
    $urlSeen[$n][$url] = true;

    if (!isset($channels[$n])) {
        $tvg = $r['tvg_id'] ?? ''; $tvg = preg_replace('/@.*$/', '', $tvg);

        if (!empty($r['logo']))                   { $logo = $r['logo'];          $from = 'm3u'; }
        elseif ($tvg && isset($IPTV_BY_ID[$tvg])) { $logo = $IPTV_BY_ID[$tvg];   $from = 'iptv-id'; }
        elseif (isset($IPTV_BY_NAME[$n]))         { $logo = $IPTV_BY_NAME[$n];   $from = 'iptv-name'; }
        elseif (isset($JIO_MAP[$n]))              { $logo = $JIO_MAP[$n];        $from = 'jio'; }
        else                                       { $logo = null;                $from = 'none'; $noLogoList[] = $r['name']; }
        $logoFrom[$from]++;

        // Category
        $cats = [];
        if ($tvg && !empty($IPTV_CATS[$tvg])) $cats = $IPTV_CATS[$tvg];
        if (!$cats && !empty($r['group']))    $cats = [$r['group']];
        $category = $cats[0] ?? 'Entertainment';

        // Languages
        $langs = [];
        if ($tvg && !empty($IPTV_LANGS[$tvg])) {
            $langs = $IPTV_LANGS[$tvg];
        }
        if (!$langs) $langs = ['Hindi'];
        $langs = array_values(array_diff($langs, $LANG_BLOCK));
        if (count($langs) === 0) continue;

        $channels[$n] = [
            'name'     => $r['name'],
            'langs'    => $langs,
            'category' => $category,
            'streams'  => [],
            'sources'  => ['iptv-org'],
            'logo'     => $logo,
        ];
    }
    $channels[$n]['streams'][] = ['url'=>$url,'source'=>'iptv-org','status'=>$s];
}
echo "Unique: " . count($channels) . "\n";
echo "Logo source → m3u:{$logoFrom['m3u']} iptv-id:{$logoFrom['iptv-id']} iptv-name:{$logoFrom['iptv-name']} jio:{$logoFrom['jio']} none:{$logoFrom['none']}\n";

$fe = []; $multi = 0; $catCount = []; $langCount = []; $logoOk = 0;
foreach ($channels as $ch) {
    $streams = $ch['streams'];
    if (count($streams) > 1) $multi++;
    $cat = $ch['category'];
    $catCount[$cat] = ($catCount[$cat] ?? 0) + 1;
    foreach ($ch['langs'] as $l) $langCount[$l] = ($langCount[$l] ?? 0) + 1;
    if (!empty($ch['logo'])) $logoOk++;
    $fe[] = ['name'=>$ch['name'],'langs'=>$ch['langs'],'category'=>$cat,'streams'=>$streams,'stream_type'=>count($streams) > 1 ? 'different' : 'same','sources'=>$ch['sources'],'color'=>'#00b4d8','initial'=>substr($ch['name'], 0, 1) ?: '?','logo'=>$ch['logo'],'type'=>'tv'];
}
usort($fe, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
arsort($catCount); arsort($langCount);

$out = ['generated'=>date('Y-m-d H:i:s'),'total'=>count($fe),'tv_total'=>count($fe),'radio_total'=>0,'multi_stream'=>$multi,'working'=>$w,'protected'=>$p,'dead'=>$d,'categories'=>array_keys($catCount),'languages'=>array_keys($langCount),'channels'=>$fe,'radio'=>[]];
file_put_contents($FILE, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "Saved: channels_data.json\n";
echo "Logos resolved: $logoOk / " . count($fe) . "\n";
echo "Logos MISSING: " . count($noLogoList) . "\n";
if ($noLogoList) {
    echo "--- Channels without logo ---\n";
    foreach (array_slice($noLogoList, 0, 40) as $n) echo "  • $n\n";
    if (count($noLogoList) > 40) echo "  ... +" . (count($noLogoList)-40) . " more\n";
}
echo "\n--- Categories ---\n";
foreach ($catCount as $c=>$n) echo "  $c: $n\n";
echo "\n--- Languages ---\n";
foreach ($langCount as $l=>$n) echo "  $l: $n\n";
echo "\nDONE TV=" . count($fe) . " Working=$w Multi=$multi\n";
