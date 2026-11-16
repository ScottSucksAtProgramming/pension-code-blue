<?php
// Pension Code Blue: price cache. Every visitor's page reads this file, and it
// calls Tiingo at most once every 4 minutes during market hours for all of them.
//
// Setup: put your Tiingo key alone in a file named tiingo.key in the folder ABOVE
// this site's folder, where the web can't reach it. (An env var TIINGO_KEY also works.)
// holdings.json (the fund's top 300 US stocks) must sit next to this file.
//
// Free plan limits: 50 requests/hour, 1,000/day, 1 GB of data/month.
// Budget at these settings (one request for all stocks + one for today's ACWI chart):
//   market hours: 2 requests per 4-minute refresh = ~30/hour, ~200/day
//   outside market hours: no stock requests (prices stay frozen at the last close)
//   daily ACWI closes for the 1M/1Y charts: 1 request every 6 hours
//   data: ~90 KB per refresh x ~100 refreshes a day x ~22 trading days = ~200-250 MB/month
// NEVER call /iex/ without a ticker list: that returns every US ticker (~11 MB per call)
// and would eat the monthly data cap in a few hours.

$keyFile = dirname(__DIR__) . '/tiingo.key';
$KEY = is_readable($keyFile) ? trim(file_get_contents($keyFile)) : (getenv('TIINGO_KEY') ?: 'PUT_YOUR_KEY_HERE');
$PROXY = 'ACWI';
$HOLDINGS = __DIR__ . '/holdings.json';
$CACHE = __DIR__ . '/cache.json';
$CACHE_VERSION = 2;  // bump to ignore an old cache.json after a format change
$OPEN_TTL = 240;     // seconds between refreshes while the market is open
$CLOSED_TTL = 10800; // seconds between refreshes when it's closed (3 hours)

// Testing only: lets test/run-data-test.php feed in fake Tiingo replies and a fake clock.
// Must stay false on the live site.
$ALLOW_MOCK = false;
$MOCK_DIR = $ALLOW_MOCK ? getenv('PCB_MOCK_DIR') : false;
$NOW = ($ALLOW_MOCK && getenv('PCB_MOCK_NOW')) ? (int)getenv('PCB_MOCK_NOW') : time();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$tz = new DateTimeZone('America/New_York');
$ny = (new DateTime('@' . $NOW))->setTimezone($tz);
$today = $ny->format('Y-m-d');
$mins = (int)$ny->format('G') * 60 + (int)$ny->format('i');
$weekday = (int)$ny->format('N') <= 5;
$inSession = $weekday && $mins >= 9*60+25 && $mins <= 16*60+10; // refresh window (a little wider than the bell)
$regular = $weekday && $mins >= 9*60+30 && $mins < 16*60;        // regular trading hours
$ttl = $inSession ? $OPEN_TTL : $CLOSED_TTL;

// What visitors get: the cache minus bookkeeping, with "market open" worked out right now.
function send($c, $regular, $today) {
  $c['marketOpen'] = $regular && (($c['sessionDay'] ?? '') === $today);
  unset($c['sessionDay'], $c['seriesDay'], $c['v']);
  echo json_encode($c);
}

$cache = is_file($CACHE) ? json_decode(file_get_contents($CACHE), true) : null;
if (!is_array($cache) || ($cache['v'] ?? 0) !== $CACHE_VERSION) $cache = null;
$age = $cache ? $NOW - ($cache['fetchedAt'] ?? 0) : PHP_INT_MAX;
if ($cache && $age < $ttl) { send($cache, $regular, $today); exit; }

// One request at a time: skip refresh if another visitor is already doing it.
$lock = fopen($CACHE . '.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
  if ($cache) { send($cache, $regular, $today); exit; }
  flock($lock, LOCK_EX);
}

function tiingo($path, $key) {
  global $MOCK_DIR;
  if ($MOCK_DIR) { // test replies, never used live
    $f = $MOCK_DIR . '/' . preg_replace('/[^a-z0-9]+/', '_', strtolower(strtok($path, '?'))) . '.json';
    if (!is_file($f)) throw new Exception('mock missing: ' . basename($f));
    $j = json_decode(file_get_contents($f), true);
    if (!is_array($j)) throw new Exception('bad reply');
    return $j;
  }
  $ch = curl_init('https://api.tiingo.com/' . $path);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_ENCODING => '',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Token ' . $key]]);
  $raw = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($raw === false || $code !== 200) throw new Exception('tiingo HTTP ' . $code);
  $j = json_decode($raw, true);
  if (!is_array($j)) throw new Exception('bad reply');
  return $j;
}
$tk = fn($s) => strtolower(str_replace('.', '-', $s)); // BRK.B -> brk-b
$nyDay = fn($iso) => $iso ? (new DateTime($iso))->setTimezone($tz)->format('Y-m-d') : '';
$r4 = fn($v) => round((float)$v, 4);

try {
  $hold = json_decode((string)@file_get_contents($HOLDINGS), true);
  if (!is_array($hold) || !$hold) throw new Exception('holdings.json missing');
  $syms = array_column($hold, 'sym');

  $out = $cache ?: [];
  $out['v'] = $CACHE_VERSION;
  $stocks = $cache['stocks'] ?? [];
  $haveAll = isset($cache['acwi']['price']) && count($stocks) > 0;

  // Outside regular hours, prices stay frozen at the last close, so there's nothing to fetch.
  // (Tiingo's numbers before the bell include pre-market trades, which we don't want.)
  if ($regular || !$haveAll) {
    $list = array_merge([$PROXY], $syms);
    $q = tiingo('iex/?tickers=' . implode(',', array_map($tk, $list)), $KEY);
    $byT = [];
    foreach ($q as $row) if (is_array($row) && isset($row['ticker'])) $byT[strtolower($row['ticker'])] = $row;

    $a = $byT[strtolower($PROXY)] ?? null;
    if (!$a || !isset($a['prevClose'])) throw new Exception('no ACWI quote');
    // It's a live session only if ACWI actually traded today (this skips market holidays).
    $live = $regular && $nyDay($a['timestamp'] ?? '') === $today;

    // One price pair per symbol: today's price vs the previous close.
    $pair = function ($row, $old) use ($live, $today, $mins, $nyDay, $r4) {
      $last = $row['tngoLast'] ?? null; $prev = $row['prevClose'] ?? null;
      if ($prev === null) return $live ? null : $old;
      $tradedToday = $nyDay($row['timestamp'] ?? '') === $today;
      if ($live) return ($tradedToday && $last !== null) ? ['price' => $r4($last), 'prev' => $r4($prev)] : ['price' => $r4($prev), 'prev' => $r4($prev)];
      if ($old) return $old; // keep the frozen close
      // First run with nothing cached: don't show pre-market moves as today's.
      if ($last === null || ($tradedToday && $mins < 9*60+30)) return ['price' => $r4($prev), 'prev' => $r4($prev)];
      return ['price' => $r4($last), 'prev' => $r4($prev)];
    };

    $oldA = isset($cache['acwi']['price']) ? ['price' => $cache['acwi']['price'], 'prev' => $cache['acwi']['prev']] : null;
    $out['acwi'] = array_merge(['series' => []], $cache['acwi'] ?? [], $pair($a, $oldA));
    foreach ($syms as $s) {
      $row = $byT[$tk($s)] ?? null;
      $p = $row ? $pair($row, $stocks[$s] ?? null) : ($live ? null : ($stocks[$s] ?? null));
      if ($p) $stocks[$s] = $p; else unset($stocks[$s]);
    }
    $out['stocks'] = array_intersect_key($stocks, array_flip($syms));

    if ($live) {
      $out['sessionDay'] = $today;
      // Today's ACWI line for the chart, in 5-minute steps.
      try {
        $bars = tiingo('iex/' . strtolower($PROXY) . '/prices?startDate=' . $today . '&resampleFreq=5min&columns=close', $KEY);
        $series = [];
        foreach ($bars as $b) {
          if (!isset($b['date'], $b['close']) || $nyDay($b['date']) !== $today) continue;
          $t = (new DateTime($b['date']))->setTimezone($tz);
          $m = (int)$t->format('G') * 60 + (int)$t->format('i');
          if ($m < 9*60+30 || $m > 16*60) continue;
          $series[] = ['p' => $r4($b['close']), 'label' => $t->format('g:i A')];
        }
        if (!$series) throw new Exception('no bars');
      } catch (Exception $e) {
        // Fallback: build the line ourselves, one point per refresh.
        $series = (($cache['seriesDay'] ?? '') === $today) ? ($cache['acwi']['series'] ?? []) : [];
        $series[] = ['p' => $out['acwi']['price'], 'label' => $ny->format('g:i A')];
      }
      $out['acwi']['series'] = $series;
      $out['seriesDay'] = $today;
    }
  }

  // Daily closes for the 1M and 1Y chart views: 1 request, at most every 6 hours.
  $daily = $cache['daily'] ?? [];
  $dailyAt = $cache['dailyAt'] ?? 0;
  if ($NOW - $dailyAt > 21600) {
    try {
      $start = (clone $ny)->modify('-380 days')->format('Y-m-d');
      $dj = tiingo('tiingo/daily/' . strtolower($PROXY) . '/prices?startDate=' . $start, $KEY);
      $nd = [];
      foreach ($dj as $v) if (isset($v['date'], $v['close'])) $nd[] = ['d' => substr($v['date'], 0, 10), 'p' => $r4($v['close'])];
      if (!$nd) throw new Exception('empty');
      $daily = $nd; $dailyAt = $NOW;
    } catch (Exception $e) { $dailyAt = $NOW - 21600 + 1800; /* keep the old list; retry in 30 min */ }
  }
  // After the bell, use ACWI's official close once Tiingo has it (it drives the headline).
  $lastD = $daily ? end($daily) : null;
  if ($lastD && !$regular && $lastD['d'] === ($out['sessionDay'] ?? '')) $out['acwi']['price'] = $lastD['p'];

  $out['fetchedAt'] = $NOW;
  $out['delayed'] = true;
  $out['daily'] = $daily;
  $out['dailyAt'] = $dailyAt;
  file_put_contents($CACHE, json_encode($out), LOCK_EX);
  send($out, $regular, $today);
} catch (Exception $e) {
  // On an error, wait 2 minutes before trying again so a broken feed can't burn the limits.
  if ($cache) { $cache['fetchedAt'] = $NOW - $ttl + 120; file_put_contents($CACHE, json_encode($cache), LOCK_EX); send($cache, $regular, $today); }
  else { error_log('pension data.php: ' . $e->getMessage()); http_response_code(502); echo json_encode(['error' => 'price feed unavailable']); }
}
flock($lock, LOCK_UN);
