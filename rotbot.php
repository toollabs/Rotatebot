<?php
/*   Copyright © by Luxo & Saibo, 2011 - 2014
                 by Steinsplitter, 2014 - 2026

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

error_reporting(E_ERROR | E_PARSE);

$homedir    = "/home/rotbot/";
$webdir     = "/home/rotbot/public_html/";
$myLockfile = $homedir . "rotatebotlock_nope";

ini_set('memory_limit', '1000M');
define('BOT_USER_AGENT', 'Rotatebot/1.0 (https://commons.wikimedia.org/wiki/User:SteinsplitterBot; commons:User:Steinsplitter)');
ini_set('user_agent', BOT_USER_AGENT);

$downloadCookieFile   = $homedir . "cache/rotbot-download.cookie";
$downloadSessionReady = false;


/* ==========================================================================
   HTTP / download helpers
   ========================================================================== */

function parseRetryAfterSeconds($rawValue) {
        if ($rawValue === null) {
                return 0;
        }

        $rawValue = trim((string)$rawValue);
        if ($rawValue === '') {
                return 0;
        }

        if (ctype_digit($rawValue)) {
                return (int)$rawValue;
        }

        $retryTs = strtotime($rawValue);
        if ($retryTs === false) {
                return 0;
        }

        $delta = $retryTs - time();
        return ($delta > 0) ? (int)$delta : 0;
}

function cookieDomainMatchesHost($cookieDomain, $host) {
        $cookieDomain = strtolower(trim((string)$cookieDomain));
        $host = strtolower(trim((string)$host));

        if ($cookieDomain === '' || $host === '') {
                return false;
        }

        if ($cookieDomain[0] === '.') {
                $cookieDomain = substr($cookieDomain, 1);
        }

        return ($host === $cookieDomain || substr($host, -strlen('.' . $cookieDomain)) === '.' . $cookieDomain);
}

function buildCookieHeaderForUrl($cookieFile, $url) {
        if (!is_file($cookieFile) || !is_readable($cookieFile)) {
                return "";
        }

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!$host) {
                return "";
        }

        if (!$path) {
                $path = '/';
        }

        $isHttps = (strtolower((string)$scheme) === 'https');
        $now = time();
        $pairs = [];

        $lines = @file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
                return "";
        }

        foreach ($lines as $line) {
                if ($line === '' || $line[0] === '#') {
                        continue;
                }

                $parts = explode("\t", $line);
                if (count($parts) < 7) {
                        continue;
                }

                $domain = $parts[0];
                $cookiePath = $parts[2];
                $secure = strtoupper($parts[3]) === 'TRUE';
                $expires = (int)$parts[4];
                $name = $parts[5];
                $value = $parts[6];

                if ($name === '') {
                        continue;
                }

                if ($expires !== 0 && $expires < $now) {
                        continue;
                }

                if ($secure && !$isHttps) {
                        continue;
                }

                if (!cookieDomainMatchesHost($domain, $host)) {
                        continue;
                }

                if ($cookiePath === '') {
                        $cookiePath = '/';
                }

                if (strpos($path, $cookiePath) !== 0) {
                        continue;
                }

                $pairs[$name] = $value;
        }

        if (count($pairs) === 0) {
                return "";
        }

        $cookieParts = [];
        foreach ($pairs as $name => $value) {
                $cookieParts[] = $name . '=' . $value;
        }

        return implode('; ', $cookieParts);
}

function getrawhttp($url, &$requestMeta = null) {
        global $downloadCookieFile, $downloadSessionReady;

        $con             = curl_init();
        $responseHeaders = [];
        curl_setopt($con, CURLOPT_URL,            $url);
        curl_setopt($con, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($con, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($con, CURLOPT_TIMEOUT,        900);
        curl_setopt($con, CURLOPT_LOW_SPEED_LIMIT, 1024);
        curl_setopt($con, CURLOPT_LOW_SPEED_TIME, 60);
        curl_setopt($con, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($con, CURLOPT_MAXREDIRS, 5);
        curl_setopt($con, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($con, CURLOPT_ENCODING, 'identity');
        curl_setopt($con, CURLOPT_USERAGENT, BOT_USER_AGENT);
        curl_setopt($con, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$responseHeaders) {
                $len    = strlen($headerLine);
                $header = trim($headerLine);

                if ($header === '' || strpos($header, ':') === false) {
                        return $len;
                }

                [$name, $value] = explode(':', $header, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);

                return $len;
        });

        if ($downloadSessionReady === true) {
                curl_setopt($con, CURLOPT_COOKIEJAR, $downloadCookieFile);
                curl_setopt($con, CURLOPT_COOKIEFILE, $downloadCookieFile);

                $cookieHeader = buildCookieHeaderForUrl($downloadCookieFile, $url);
                if ($cookieHeader !== "") {
                        curl_setopt($con, CURLOPT_COOKIE, $cookieHeader);
                }
        }

        $data          = curl_exec($con);
        $curlErrNo     = curl_errno($con);
        $curlErrMsg    = curl_error($con);
        $httpCode      = curl_getinfo($con, CURLINFO_HTTP_CODE);
        $contentType   = curl_getinfo($con, CURLINFO_CONTENT_TYPE);
        $downloadSize  = curl_getinfo($con, CURLINFO_SIZE_DOWNLOAD);
        $contentLength = curl_getinfo($con, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $totalTime     = curl_getinfo($con, CURLINFO_TOTAL_TIME);
        $retryAfter    = 0;
        if (isset($responseHeaders['retry-after'])) {
                $retryAfter = parseRetryAfterSeconds($responseHeaders['retry-after']);
        }

        if ($requestMeta !== null) {
                $requestMeta = array(
                        'curl_errno'     => $curlErrNo,
                        'curl_error'     => $curlErrMsg,
                        'http_code'      => $httpCode,
                        'content_type'   => $contentType,
                        'download_size'  => $downloadSize,
                        'content_length' => $contentLength,
                        'total_time'     => $totalTime,
                        'retry_after'    => $retryAfter
                );
        }

        curl_close($con);
        return $data;
}

function formatDownloadError($requestMeta, $responseBody) {
        $parts = [];

        if (is_array($requestMeta)) {
                if (!empty($requestMeta['curl_errno'])) {
                        $parts[] = "cURL error " . (int)$requestMeta['curl_errno'] . ": " . $requestMeta['curl_error'];
                }

                if (!empty($requestMeta['http_code'])) {
                        $parts[] = "HTTP " . (int)$requestMeta['http_code'];
                }

                if (!empty($requestMeta['content_type'])) {
                        $parts[] = "Content-Type: " . $requestMeta['content_type'];
                }

                if (!empty($requestMeta['download_size'])) {
                        $parts[] = "Bytes: " . (int)$requestMeta['download_size'];
                }

                if (isset($requestMeta['content_length']) && (float)$requestMeta['content_length'] > 0) {
                        $parts[] = "Expected: " . (int)$requestMeta['content_length'];
                }

                if (!empty($requestMeta['total_time'])) {
                        $parts[] = "Time: " . round((float)$requestMeta['total_time'], 2) . "s";
                }

                if (!empty($requestMeta['retry_after'])) {
                        $parts[] = "Retry-After: " . (int)$requestMeta['retry_after'] . "s";
                }
        }

        $bodyPreview = "";
        if (is_string($responseBody) && strlen($responseBody) > 0) {
                $contentType = "";
                if (is_array($requestMeta) && !empty($requestMeta['content_type'])) {
                        $contentType = strtolower($requestMeta['content_type']);
                }

                $looksLikeText = (
                        strpos($contentType, 'text/') !== false ||
                        strpos($contentType, 'json') !== false ||
                        strpos($contentType, 'xml') !== false ||
                        strpos($contentType, 'html') !== false
                );

                if ($looksLikeText) {
                        $bodyPreview = trim(preg_replace('/\s+/', ' ', strip_tags($responseBody)));
                        if (strlen($bodyPreview) > 220) {
                                $bodyPreview = substr($bodyPreview, 0, 220) . "...";
                        }
                }
        }

        if ($bodyPreview !== "") {
                $parts[] = "Server message: \"" . $bodyPreview . "\"";
        }

        if (count($parts) === 0) {
                return "No explicit server/cURL error details";
        }

        return implode(" | ", $parts);
}

function initializeDownloadSession($username, $password) {
        global $downloadCookieFile, $downloadSessionReady;

        $downloadSessionReady = false;

        if (!is_dir(dirname($downloadCookieFile))) {
                return false;
        }

        if (file_exists($downloadCookieFile)) {
                @unlink($downloadCookieFile);
        }

        $tokenUrl = 'https://commons.wikimedia.org/w/api.php?action=query&meta=tokens&type=login&format=json';
        $con = curl_init();
        curl_setopt($con, CURLOPT_URL, $tokenUrl);
        curl_setopt($con, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($con, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($con, CURLOPT_USERAGENT, BOT_USER_AGENT);
        curl_setopt($con, CURLOPT_COOKIEJAR, $downloadCookieFile);
        curl_setopt($con, CURLOPT_COOKIEFILE, $downloadCookieFile);
        $rawToken = curl_exec($con);
        curl_close($con);

        if ($rawToken === false || $rawToken === null || $rawToken === '') {
                return false;
        }

        $tokenData = json_decode($rawToken, true);
        $loginToken = $tokenData['query']['tokens']['logintoken'];
        if (!$loginToken) {
                return false;
        }

        $loginPost = http_build_query([
                'action'     => 'login',
                'lgname'     => $username,
                'lgpassword' => $password,
                'lgtoken'    => $loginToken,
                'format'     => 'json',
        ]);

        $con = curl_init();
        curl_setopt($con, CURLOPT_URL, 'https://commons.wikimedia.org/w/api.php');
        curl_setopt($con, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($con, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($con, CURLOPT_POST, 1);
        curl_setopt($con, CURLOPT_POSTFIELDS, $loginPost);
        curl_setopt($con, CURLOPT_USERAGENT, BOT_USER_AGENT);
        curl_setopt($con, CURLOPT_COOKIEJAR, $downloadCookieFile);
        curl_setopt($con, CURLOPT_COOKIEFILE, $downloadCookieFile);
        $rawLogin = curl_exec($con);
        curl_close($con);

        if ($rawLogin === false || $rawLogin === null || $rawLogin === '') {
                return false;
        }

        $loginData = json_decode($rawLogin, true);
        if ($loginData['login']['result'] !== 'Success') {
                return false;
        }

        $downloadSessionReady = true;
        return true;
}

function cacheImagePath($savepath, $filename, $filetype, $rotated = false) {
        $suffix = $rotated ? "_2" : "";
        return $savepath . $filename . $suffix . "." . $filetype;
}

function rotateJpegLossless($sourcePath, $targetPath, $degrees, &$returnCode) {
        $degrees = (int)$degrees;

        if ($degrees === 0) {
                $ok = copy($sourcePath, $targetPath);
                $returnCode = $ok ? 0 : 1;
                return;
        }

        $cmd = "/usr/bin/jpegtran -rotate " . $degrees . " -trim -copy all -outfile " . escapeshellarg($targetPath) . " " . escapeshellarg($sourcePath);
        passthru($cmd, $returnCode);
}

function getTiffCompression($sourcePath) {
        $output     = [];
        $returnCode = 0;
        exec("/usr/bin/magick identify -format '%C' " . escapeshellarg($sourcePath) . " 2>/dev/null", $output, $returnCode);
        if ($returnCode !== 0 || empty($output) || trim($output[0]) === '') {
                return 'lzw';
        }
        $c = strtolower(trim($output[0]));
        if ($c === 'lzw')                           return 'lzw';
        if ($c === 'zip' || $c === 'deflate')       return 'zip';
        if ($c === 'bzip')                          return 'bzip';
        if ($c === 'rle' || $c === 'packbits')      return 'rle';
        if ($c === 'none' || $c === 'uncompressed') return 'none';
        return 'lzw';
}

function rotateRasterWithConvert($sourcePath, $targetPath, $degrees, $filetype, &$returnCode) {
        $degrees = (int)$degrees;
        $cmd     = "/usr/bin/magick " . escapeshellarg($sourcePath) . " -rotate " . $degrees . " +repage ";

        if ($filetype == "tif") {
                $compression = getTiffCompression($sourcePath);
                $cmd .= "-compress " . escapeshellarg($compression) . " ";
                $cmd .= "-define tiff:rows-per-strip=512 ";
        }

        $cmd .= escapeshellarg($targetPath);
        passthru($cmd, $returnCode);
}

function rotateWebpLossless($sourcePath, $targetPath, $degrees, &$returnCode) {
        $degrees = (int)$degrees;

        if ($degrees === 0) {
                $ok = copy($sourcePath, $targetPath);
                $returnCode = $ok ? 0 : 1;
                return;
        }

        $cmd = "/usr/bin/magick " . escapeshellarg($sourcePath) . " -rotate " . $degrees . " +repage -define webp:lossless=true " . escapeshellarg($targetPath);
        passthru($cmd, $returnCode);
}

function rotateWebmLossless($sourcePath, $targetPath, $degrees, &$returnCode) {
        $degrees = (int)$degrees;

        if ($degrees === 0) {
                $ok = copy($sourcePath, $targetPath);
                $returnCode = $ok ? 0 : 1;
                return;
        }

        switch ($degrees) {
                case 90:  $filter = "transpose=1"; break;
                case 180: $filter = "vflip,hflip";  break;
                case 270: $filter = "transpose=2"; break;
                default:
                        $returnCode = 1;
                        return;
        }

        $cmd = "/usr/bin/ffmpeg -y -i " . escapeshellarg($sourcePath) . " -vf " . escapeshellarg($filter) . " -c:v libvpx-vp9 -lossless 1 -c:a copy " . escapeshellarg($targetPath);
        passthru($cmd, $returnCode);
}

function runExiftoolCommand($args, $imagePath, &$returnCode) {
        $cmd = "/usr/bin/exiftool " . $args . " " . escapeshellarg($imagePath);
        passthru($cmd, $returnCode);
        return $cmd;
}

function readExifOrientation($imagePath) {
        $cmd = "/usr/bin/exiftool -IFD0:Orientation -b -a " . escapeshellarg($imagePath);
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !isset($output[0])) {
                return 0;
        }

        return (int)trim($output[0]);
}

require 'vendor/autoload.php';

use \Mediawiki\Api\MediawikiApi;
use \Mediawiki\Api\ApiUser;

include 'accessdata.php';


/* ==========================================================================
   Wiki API helpers
   ========================================================================== */

function RotateEdit($ptitle, $contents, $summarys) {
        global $api;

        if (!$api->isLoggedin()) {
                die("NOT LOGGED IN");
        }

        $params = [
                'title'   => $ptitle,
                'text'    => $contents,
                'summary' => $summarys,
                'bot'     => 1,
                'token'   => $api->getToken(),
        ];

        $api->postRequest(new \Mediawiki\Api\SimpleRequest("edit", $params));
}

function wikicontent($pagename) {
        global $api;

        if (!$api->isLoggedin()) {
                die();
        }

        $ret = '';
        if ($api) {
                $services  = new \Mediawiki\Api\MediawikiFactory($api);
                $page      = $services->newPageGetter()->getFromTitle($pagename);
                $revision  = $page->getRevisions()->getLatest();
                if ($revision) {
                        $ret = $revision->getContent()->getData();
                }
        }

        return $ret;
}


/* ==========================================================================
   Utility / lock helpers
   ========================================================================== */

function logfile($text) {
        global $webdir;
        echo $text . "\n";
        $line = date("Y-m-d H:i:s") . " - " . $text . "\n";
        file_put_contents($webdir . "rotatelogs/" . date("Y-m-d") . "-rotlog.txt", $line, FILE_APPEND);
}

function timestampto($intime, $unix = false) {
        $unixtime = strtotime($intime);

        if ($unixtime === false) {
                return $intime;
        }

        return $unix ? $unixtime : date("H:i, d F Y", $unixtime);
}

function difftime($settime, $rottime) {
        $diff = timestampto($rottime, true) - timestampto($settime, true);
        return tellSeconds($diff);
}

function tellSeconds($NumberOfSeconds) {
        $time_map = [
                'Years'   => 31536000,
                'Months'  => 2592000,
                'Weeks'   => 604800,
                'Days'    => 86400,
                'Hours'   => 3600,
                'Minutes' => 60,
                'Seconds' => 1,
        ];

        $SecondsLeft = $NumberOfSeconds;
        $stack       = [];

        foreach ($time_map as $k => $v) {
                if ($SecondsLeft < $v || $SecondsLeft == 0) {
                        continue;
                }

                $amount      = floor($SecondsLeft / $v);
                $SecondsLeft = $SecondsLeft % $v;
                $label       = ($amount > 1) ? $k : substr($k, 0, -1);
                $stack[]     = sprintf("'''%s''' %s", $amount, $label);
        }

        $cnt = count($stack);
        if ($cnt > 1) {
                $tmp1 = array_pop($stack);
                $tmp2 = array_pop($stack);
                array_push($stack, $tmp2 . ' and ' . $tmp1);
        }

        return implode(', ', $stack);
}

function deleteold($content, $newab, $maxonlog, $logheader) {
        $beginnat       = 0;
        $abschnittarray = ["0"];

        while ($pos = strpos($content, "----", $beginnat)) {
                $abschnittarray[] = $pos;
                $beginnat         = $pos + 4;
        }

        $abschnittarray[] = strlen($content);
        $alte             = count($abschnittarray) - 2;

        logfile("$alte sections found!");

        $totneu = $newab + $alte;
        if ($totneu <= $maxonlog) {
                logfile("nothing to delete, just add");
                return $content;
        }

        $zuviele = $totneu - $maxonlog;
        if ($zuviele > $alte) {
                $zuviele = $alte;
        }

        $abschnitteneu = $totneu - $zuviele;
        $bereich       = $zuviele + 1;

        logfile("delete $zuviele old sections.");
        logfile("delete section 1 to $bereich");

        $counter = (int)file_get_contents("/home/rotbot/counter.txt") + $newab;
        file_put_contents("/home/rotbot/counter.txt", $counter);
        logfile("new counter: $counter.");

        $intro = sprintf($logheader . "\n", $abschnitteneu, $counter);
        $rest  = substr($content, $abschnittarray[$bereich]);

        return $intro . $rest;
}

function botsetupN() {
        $url  = "https://commons.wikimedia.org/w/index.php?title=User:SteinsplitterBot/rconfig.json&action=raw";
        $json = file_get_contents($url);

        if ($json === false) {
                throw new Exception("Could not fetch JSON config");
        }

        $data = json_decode($json, true);
        if ($data === null) {
                throw new Exception("Invalid JSON in config");
        }

        $array = [];
        foreach ($data as $key => $entry) {
                $value = (is_array($entry) && array_key_exists('value', $entry)) ? $entry['value'] : $entry;

                if (is_bool($value)) {
                        $value = $value ? "true" : "false";
                }

                $array[$key] = $value;
        }

        return $array;
}

function TsToUnixTime($tstime) {
        if (is_string($tstime) && !ctype_digit($tstime)) {
                $result = strtotime($tstime);
                return ($result !== false) ? $result : 0;
        }

        $s = str_pad((string)(int)$tstime, 14, '0', STR_PAD_LEFT);
        return mktime(
                (int)substr($s, 8,  2),
                (int)substr($s, 10, 2),
                (int)substr($s, 12, 2),
                (int)substr($s, 4,  2),
                (int)substr($s, 6,  2),
                (int)substr($s, 0,  4)
        );
}

function hexToStr($hex) {
        $string = '';
        for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
                $string .= chr(hexdec($hex[$i] . $hex[$i + 1]));
        }
        return $string;
}

function getLockOrDie() {
        global $myLockfile;

        if (file_exists($myLockfile)) {
                logfile("Lock file already present. Another instance may be running. Sleeping 15 minutes before removing stale lock and exiting.");
                sleep(900);
                logfile("Sleep done. Removing stale lock file and exiting.");
                @unlink($myLockfile);
                exit();
        }

        touch($myLockfile);
        if (!file_exists($myLockfile)) {
                die("Could not create lock file. Exit.");
        }
}

function removeLock() {
        global $myLockfile;

        if (file_exists($myLockfile)) {
                system("rm " . $myLockfile);
                if (file_exists($myLockfile)) {
                        logfile("Warning: for some reason the lockfile could not be removed.");
                }
        } else {
                logfile("Warning: for some reason the lockfile was missing although it was expected to exist.");
        }
}

function suicide($exitcodeOrString) {
        removeLock();
        die($exitcodeOrString);
}


/* ==========================================================================
   Bootstrap
   ========================================================================== */

$api = new MediawikiApi('https://commons.wikimedia.org/w/api.php');

if (!initializeDownloadSession($botusername, $botkey)) {
        suicide("Error: Could not initialize authenticated download session.");
}

$api->login(new ApiUser($botusername, $botkey));


/* ==========================================================================
   Main script
   ========================================================================== */

logfile("Starting bot...");
$config = botsetupN();
if ($config['active'] == "false") {
        die("Bot disabled.");
} elseif ($config['active'] != "true") {
        die("Bot error.");
}

getLockOrDie();


/* -- Clear old cache files -- */

$cacheunlinks = glob('/home/rotbot/cache/*');
foreach ($cacheunlinks as $cacheunlink) {
        if (is_file($cacheunlink)) {
                unlink($cacheunlink);
                echo "rm " . $cacheunlink;
        }
}

$wrongfiles = [];


/* -- Load category members -- */

$katname = $config['katname'];
logfile("Checking 'Category:$katname' for files.");

$queryurl     = "https://commons.wikimedia.org/w/api.php?action=query&rawcontinue=1&list=categorymembers&cmtitle=Category:" . $katname . "&format=json&cmprop=ids|title|sortkey|timestamp&cmnamespace=6&cmsort=timestamp&cmtype=file&cmlimit=" . $config['limit'];
$rawrequ      = getrawhttp($queryurl);
$contentarray = json_decode($rawrequ, true);

if (!$contentarray['query']['categorymembers']['0']) {
        suicide(logfile("Category empty, no files found!."));
}

foreach ($contentarray['query']['categorymembers'] as $temp_img) {
        if ($temp_img['ns'] == 6) {
                $contentarray['pages'][] = $temp_img;
        }
}


/* -- Load image metadata -- */

$urlpageids = "";
foreach ($contentarray['pages'] as $picture) {
        $urlpageids .= "|" . $picture['pageid'];
}
$urlpageids = substr($urlpageids, 1);

$queryurl      = "https://commons.wikimedia.org/w/api.php?action=query&rawcontinue=1&pageids=" . $urlpageids . "&prop=revisions|imageinfo&format=json&iiprop=timestamp|user|url|size|metadata";
$rawrequ       = getrawhttp($queryurl);
$contentarray2 = json_decode($rawrequ, true);

$contentarray2['pages'] = $contentarray2['query']['pages'];

foreach ($contentarray['pages'] as $picture) {
        $ctidd = $picture['pageid'];
        foreach ($picture as $contXB => $contXI) {
                $contentarray2['pages'][$ctidd][$contXB] = $contXI;
        }
}

$contentarray = $contentarray2;


/* -- Validate and filter images -- */

$catcontent = [];
$arraykey   = 0;

foreach ($contentarray['pages'] as $picture) {
        $wrongfile  = false;
        $titleLower = strtolower($picture['title']);

        logfile("-------------");
        logfile("Checking " . $picture['title'] . "...");

        if (substr($titleLower, -4) === ".jpg" || substr($titleLower, -5) === ".jpeg") {
                $catcontent[$arraykey]['filetype'] = "jpg";
        } elseif (substr($titleLower, -4) === ".png") {
                $catcontent[$arraykey]['filetype'] = "png";
        } elseif (substr($titleLower, -4) === ".gif") {
                $catcontent[$arraykey]['filetype'] = "gif";
        } elseif (substr($titleLower, -5) === ".tiff" || substr($titleLower, -4) === ".tif") {
                if ($config['rotateTiff'] === "true") {
                        $catcontent[$arraykey]['filetype'] = "tif";
                } else {
                        $wrongfile                     = true;
                        $wrongfiles[$picture["title"]] = "TIFF rotation is disabled in config (rotateTiff).";
                }
        } elseif (substr($titleLower, -5) === ".webp") {
                if ($config['rotateWebp'] === "true") {
                        $catcontent[$arraykey]['filetype'] = "webp";
                } else {
                        $wrongfile                     = true;
                        $wrongfiles[$picture["title"]] = "WebP rotation is disabled in config (rotateWebp).";
                }
        } elseif (substr($titleLower, -5) === ".webm") {
                if ($config['rotateWebm'] === "true") {
                        $catcontent[$arraykey]['filetype'] = "webm";
                } else {
                        $wrongfile                     = true;
                        $wrongfiles[$picture["title"]] = "WebM rotation is disabled in config (rotateWebm).";
                }
        } else {
                $wrongfile                     = true;
                $wrongfiles[$picture["title"]] = "filetype not supported (" . substr($titleLower, -3) . ")";
        }

        $picture["sortkey"] = trim(stristr(hexToStr($picture["sortkey"]), "\n", true));

        $skipSizeLimit = (isset($catcontent[$arraykey]['filetype']) && in_array($catcontent[$arraykey]['filetype'], ['webp', 'webm'], true));
        logfile("Size of this picture: " . $picture['imageinfo']['0']["size"] . " bytes. Limit set to " . $config['fileSizeLimit'] . " bytes" . ($skipSizeLimit ? " (size limit skipped for webp/webm)" : ""));

        if (!$skipSizeLimit && $picture['imageinfo']['0']["size"] > $config['fileSizeLimit']) {
                logfile("File bigger (" . $picture['imageinfo']['0']["size"] . " B) than limit (" . $config['fileSizeLimit'] . " B)");
                $wrongfiles[$picture["title"]] = "File bigger (" . $picture['imageinfo']['0']["size"] . " B) than limit (" . $config['fileSizeLimit'] . " B). Please wait until someone does a lossless(!) fix by hand.";
                $wrongfile = true;
        }

        if ($picture["sortkey"] != 90 && $picture["sortkey"] != 180 && $picture["sortkey"] != 270) {
                if (!(isset($catcontent[$arraykey]['filetype']) && $catcontent[$arraykey]['filetype'] === "jpg"
                    && in_array($picture["sortkey"], ["RESETEXIF", "0", "00", "000"], true))) {
                        logfile("wrong degree-parameter (" . $picture["sortkey"] . "°)");
                        $wrongfiles[$picture["title"]] = "wrong degree-parameter (" . $picture["sortkey"] . "°)";
                        $wrongfile = true;
                }
        }

        $lagTime      = $config['lag'] * 60;
        $settimestamp = timestampto(trim($picture["timestamp"]), true);
        $inCatSince   = time() - $settimestamp;
        logfile("set at: '" . $settimestamp . "' (" . $picture["timestamp"] . ") Lag: " . $lagTime . " seconds, in the category since " . $inCatSince . " seconds.");

        if ($inCatSince < $lagTime) {
                $wrongfile = true;
                logfile("Picture younger than " . $lagTime . " seconds. \n\nSORT OUT\n\n");
        } else {
                logfile("Picture older than " . $lagTime . " seconds, ok.");
        }

        $revitimestp = trim($picture["timestamp"]);

        foreach ($picture['revisions'] as $key => $revisions) {
                if (trim($revisions['timestamp']) == $revitimestp) {
                        $catcontent[$arraykey]['tmplsetter'] = $picture['revisions'][$key]['user'];
                        logfile("Template by: " . $catcontent[$arraykey]['tmplsetter']);
                } else {
                        logfile("set time($revitimestp) not identical with this rv, " . $revisions['timestamp'] . ".");
                        $ctxctx1 = "https://commons.wikimedia.org/w/api.php?action=query&rawcontinue=1&prop=revisions&pageids=" . $picture['pageid'] . "&rvlimit=20&rvprop=timestamp|user|comment&format=json";
                        $ctxctx  = getrawhttp($ctxctx1);
                        $totrevs = json_decode($ctxctx, true);
                        logfile("ID: " . $picture['pageid'] . " ");

                        if (is_array($totrevs)) {
                                foreach ($totrevs['query']['pages'] as $cxxx) {
                                        foreach ($cxxx['revisions'] as $cxxxx) {
                                                if ($cxxxx['timestamp'] == $revitimestp) {
                                                        $catcontent[$arraykey]['tmplsetter'] = $cxxxx['user'];
                                                        logfile("Older rev, template by: " . $catcontent[$arraykey]['tmplsetter']);
                                                }
                                        }
                                }
                        } else {
                                logfile("API: Error: not a array!");
                                logfile($totrevs);
                        }
                }
        }

        if ($catcontent[$arraykey]['tmplsetter']) {
                $wgAuthor = $catcontent[$arraykey]['tmplsetter'];
                logfile("Checking user " . $wgAuthor . ".");

                if (!$cachedbar["$wgAuthor"]) {
                        $myqueryurl       = "https://commons.wikimedia.org/w/api.php?action=query&meta=globaluserinfo&guiuser=" . $wgAuthor . "&guiprop=editcount|homewiki&format=json";
                        $rawrequ2         = getrawhttp($myqueryurl);
                        $a_row            = json_decode($rawrequ2, true);
                        $cachedbar[$wgAuthor] = $a_row;
                } else {
                        $a_row = $cachedbar["$wgAuthor"];
                }

                $setuserid         = $a_row["query"]["globaluserinfo"]["id"];
                $user_registration = $a_row["query"]["globaluserinfo"]["registration"];
                $user_editcount    = $a_row["query"]["globaluserinfo"]["editcount"];
                $homewiki          = $a_row["query"]["globaluserinfo"]["home"];
                $chckx             = $user_registration;

                if (!$setuserid)      { $setuserid = "-"; }
                if (!$user_editcount) { $user_editcount = 0; }

                $vcx = false;
                if (!$user_registration) {
                        $user_registration = 20070101000000;
                        $vcx               = true;
                }

                logfile("Edits: " . $user_editcount);
                logfile("Registred at: " . $user_registration);

                $regiUnix       = TsToUnixTime($user_registration);
                $registeredtime = time() - $regiUnix;
                $registereddays = number_format($registeredtime / 86400, 1, ".", " ");

                if ($registeredtime < 345600 || (!$chckx && $setuserid === "-")) {
                        logfile($wgAuthor . " is not autoconfirmed!");
                        if ($config['rotatepermission'] == "1") {
                                $wrongfile                     = true;
                                $wrongfiles[$picture["title"]] = "The account of the user who set the template ([[User:" . $catcontent[$arraykey]['tmplsetter'] . "|]]) is ''not autoconfirmed''.<br />'''Unlock:''' An autoconfirmed user should delete the parameters <code><nowiki>|</nowiki>nobot=true<nowiki>|</nowiki>reason=...</code> in this image. Thank you --~~~~";
                        }
                        $xx = "not ";
                } else {
                        $xx = "";
                }

                if ($vcx === true) {
                        $registereddays = "?";
                }

                $userforlog[$wgAuthor] = "::$wgAuthor (homewiki: $homewiki), global userid $setuserid, $user_editcount global edits, unified login cration at $user_registration, is '''" . $xx . "autoconfirmed.'''";

                if ($config['mincontribs'] > 0 && $user_editcount < $config['mincontribs']) {
                        if ($config['rotatepermission'] == "1") {
                                logfile($catcontent[$arraykey]['tmplsetter'] . " has not enough edits!");
                                $wrongfile                     = true;
                                $wrongfiles[$picture["title"]] = "The account of the user who set the template ([[User:" . $wgAuthor . "|]]) has under " . $config['mincontribs'] . " edits.<br />'''Unlock:''' An autoconfirmed user with more than " . $config['mincontribs'] . " edits should delete the parameters <code><nowiki>|</nowiki>nobot=true<nowiki>|</nowiki>reason=...</code> in this image. Thank you --~~~~";
                        }
                }
        }

        $regex                        = "File:(?:DVC|CIMG|IMGP?|PICT|DSC[FN]?|DUW|JD|MGP|scan|untitled|foto|imagen|img|image|picture|p|BILD)?[0-9_ \-\(\)\{\}\[\]]+\..*";
        $addrename[$picture["title"]] = (preg_match("/" . $regex . "/", $picture['title']) === 1);

        if ($wrongfile === false) {
                logfile("picture and user check finished, sorted for download");

                $catcontent[$arraykey]['title']          = str_replace(" ", "_", $picture["title"]);
                $catcontent[$arraykey]['degree']         = $picture["sortkey"];
                $catcontent[$arraykey]['since']          = $revitimestp;
                $catcontent[$arraykey]['pageid']         = $picture['pageid'];
                $catcontent[$arraykey]['url']            = $picture['imageinfo']['0']['url'];
                $catcontent[$arraykey]['expected_bytes'] = (int)$picture['imageinfo']['0']['size'];
                $catcontent[$arraykey]['metadata']       = $picture['imageinfo']['0']['metadata'];
                $catcontent[$arraykey]['uploader']       = $picture['imageinfo']['0']['user'];
                $catcontent[$arraykey]['upltime']        = $picture['imageinfo']['0']['timestamp'];
                $catcontent[$arraykey]['size']           = $picture['imageinfo']['0']['width'] . "x" . $picture['imageinfo']['0']['height'];
                $catcontent[$arraykey]['exifkey']        = 0;
                $catcontent[$arraykey]['exifkeyafter']   = 0;
                $catcontent[$arraykey]['exifwriteerr']   = "";
                $arraykey++;
        } else {
                logfile("picture and user check finished, no download.");
                array_splice($catcontent, $arraykey, 1);
        }
}

$picture     = [];
$countimages = count($catcontent);

logfile("------------");
logfile("Picture load finished - $countimages pictures ready for download, " . count($wrongfiles) . " pics with errors.");


/* -- Download images -- */

foreach ($catcontent as $filename => $arraycontent) {
        logfile("save " . $arraycontent['title']);
        $savepath           = $homedir . "cache/";
        sleep(1);

        $maxDownloadRetries = 10;
        $attempt            = 0;
        $isValidDownload    = false;
        $isCompleteDownload = false;
        $downloadErrorInfo  = "";
        $expectedBytes      = isset($arraycontent['expected_bytes']) ? (int)$arraycontent['expected_bytes'] : 0;
        $rateLimitStep      = 0;

        while ($attempt <= $maxDownloadRetries) {
                $attempt++;
                $requestMeta = [];
                $file        = getrawhttp($arraycontent['url'], $requestMeta);
                $actualBytes = is_string($file) ? strlen($file) : 0;

                if ($expectedBytes <= 0 && isset($requestMeta['content_length']) && (float)$requestMeta['content_length'] > 0) {
                        $expectedBytes = (int)$requestMeta['content_length'];
                }

                $isValidDownload    = (is_string($file) && strlen($file) > 0);
                $isCompleteDownload = ($expectedBytes <= 0 || $actualBytes >= $expectedBytes);

                if ($isValidDownload && $isCompleteDownload) {
                        break;
                }

                $downloadErrorInfo = formatDownloadError($requestMeta, $file);

                if ($attempt <= $maxDownloadRetries) {
                        $httpCodeForRetry  = isset($requestMeta['http_code'])   ? (int)$requestMeta['http_code']   : 0;
                        $retryAfterSeconds = isset($requestMeta['retry_after']) ? (int)$requestMeta['retry_after'] : 0;

                        if (($httpCodeForRetry === 429 || $httpCodeForRetry === 503) && $retryAfterSeconds > 0) {
                                $rateLimitStep++;
                                $sleepSeconds = $retryAfterSeconds;
                        } elseif ($httpCodeForRetry === 401 || $httpCodeForRetry === 403) {
                                logfile("Download auth issue (HTTP " . $httpCodeForRetry . ") for " . $arraycontent['title'] . ". Trying to refresh authenticated download session.");
                                if (initializeDownloadSession($botusername, $botkey)) {
                                        logfile("Authenticated download session refreshed successfully.");
                                } else {
                                        logfile("Failed to refresh authenticated download session.");
                                }
                                $rateLimitStep = 0;
                                $sleepSeconds  = 5;
                        } elseif ($httpCodeForRetry === 429) {
                                $rateLimitStep++;
                                $sleepSeconds = min((int)(20 * pow(2, $rateLimitStep - 1)), 600);
                        } else {
                                $rateLimitStep = 0;
                                $sleepSeconds  = 22;
                        }

                        logfile("Download invalid for " . $arraycontent['title'] . ". Attempt " . $attempt . "/" . ($maxDownloadRetries + 1) . ". " . $downloadErrorInfo . ". Sleeping " . $sleepSeconds . " seconds, then retrying.");
                        sleep($sleepSeconds);
                }
        }

        if (!$isValidDownload || !$isCompleteDownload) {
                logfile("Download failed for " . $arraycontent['title'] . " after " . $maxDownloadRetries . " retries. " . $downloadErrorInfo . ". Sorting out.");
                $wrongfiles[$arraycontent['title']] = "Download failed (invalid or incomplete file body) after " . $maxDownloadRetries . " retries. " . $downloadErrorInfo;
                unset($catcontent[$filename]);
                continue;
        }

        $fp = fopen(cacheImagePath($savepath, $filename, $arraycontent['filetype']), "wb+");
        fwrite($fp, $file);
        fclose($fp);
}

$file = "";
logfile("Download finished!");


/* -- Rotate images and fix EXIF -- */

$catcontent2 = [];

foreach ($catcontent as $filename => $arraycontent) {
        $return     = 0;
        $returnP    = 0;
        $sourcePath = cacheImagePath($savepath, $filename, $arraycontent['filetype']);
        $targetPath = cacheImagePath($savepath, $filename, $arraycontent['filetype'], true);

        if ($arraycontent['filetype'] == "jpg") {
                $exif = readExifOrientation($sourcePath);
                logfile("EXIF is $exif");
                $arraycontent['exifkey'] = $exif;

                if ($arraycontent['degree'] == "RESETEXIF") {
                        switch ($exif) {
                                case 0:
                                case 1:
                                        logfile("reset EXIF Orientation reset requested but it was already 0 or 1");
                                        $return = 1007;
                                        break;
                                default:
                                        $exifR = 0;
                        }
                } else {
                        if ($exif >= 10) {
                                logfile("duplicate Orientation tags!");
                                $return = 1009;
                        } else {
                                switch ($exif) {
                                        case 0:
                                        case 1:
                                                if ($arraycontent['degree'] == 0) {
                                                        logfile("exif was 0 or 1 and no rotation requested");
                                                        $return = 1008;
                                                } else {
                                                        $exifR = 0;
                                                }
                                                break;
                                        case 3: $exifR = 180; break;
                                        case 6: $exifR = 90;  break;
                                        case 8: $exifR = 270; break;
                                        default:
                                                logfile("exif was not 0,1,3,6,8");
                                                $return = 1003;
                                }
                        }
                }

                if ($return == 0) {
                        if ($arraycontent['degree'] == "RESETEXIF") {
                                $realrotate                 = 0;
                                $arraycontent['realdegree'] = 0;
                        } else {
                                $realrotate                 = (360 + (($arraycontent['degree'] + $exifR) % 360)) % 360;
                                $arraycontent['realdegree'] = $realrotate;
                                logfile("File must be rotated $realrotate degree.");
                        }

                        switch ($realrotate) {
                                case 0:
                                        logfile("just exif correction, picture correct");
                                        rotateJpegLossless($sourcePath, $targetPath, 0, $return);
                                        break;
                                case 90:
                                case 180:
                                case 270:
                                        rotateJpegLossless($sourcePath, $targetPath, $realrotate, $return);
                                        logfile($arraycontent['title'] . " rotated by " . $realrotate . "°.");
                                        break;
                                default:
                                        logfile("Unexpected realrotate value: $realrotate.");
                                        $return = 1004;
                        }

                        $doBruteForceClean = false;

                        if ($return == 0 && !($exif == 0 || $exif == 1)) {
                                $exifwriteerr = "";

                                if ($exif >= 10) {
                                        $cmd = runExiftoolCommand("-IFD0:Orientation= -n", $targetPath, $retexifwrite);
                                        logfile($cmd);
                                        if ($retexifwrite == 0) {
                                                logfile("No errors on EXIF-to-0");
                                        } else {
                                                $cmd = runExiftoolCommand("-IFD0:Orientation= -n -m", $targetPath, $retexifwrite);
                                                logfile($cmd);
                                                if ($retexifwrite == 0) {
                                                        logfile("No errors on EXIF-to-0 (second try)");
                                                        $exifwriteerr = " - EXIF had minor errors. Some EXIF could be lost. - ";
                                                } else {
                                                        $doBruteForceClean = true;
                                                }
                                        }
                                } else {
                                        $cmd = runExiftoolCommand("-IFD0:Orientation=1 -n", $targetPath, $retexifwrite);
                                        logfile($cmd);
                                        if ($retexifwrite == 0) {
                                                logfile("no errors when setting EXIF to 1");
                                        } else {
                                                $cmd = runExiftoolCommand("-IFD0:Orientation=1 -n -m", $targetPath, $retexifwrite);
                                                logfile($cmd);
                                                if ($retexifwrite == 0) {
                                                        logfile("no errors when setting EXIF to 1 (second try)");
                                                        $exifwriteerr = " - EXIF had minor errors. Some EXIF could be lost. - ";
                                                } else {
                                                        $doBruteForceClean = true;
                                                }
                                        }
                                }

                                if ($doBruteForceClean) {
                                        $cmd = runExiftoolCommand("-all= -tagsfromfile @ -all:all --IFD0:Orientation", $targetPath, $retexifwrite);
                                        logfile($cmd);
                                        if ($retexifwrite == 0) {
                                                logfile("no errors when setting EXIF to 0 (third try)");
                                                $exifwriteerr = " - EXIF had major errors. Great parts of EXIF could be lost. - ";
                                        } else {
                                                $return = 1005;
                                        }
                                }

                                $arraycontent['exifwriteerr'] = $exifwriteerr;
                        }

                        if ($return == 0) {
                                $exifafter = readExifOrientation($targetPath);
                                logfile("read EXIF after finish: $exifafter");
                                $arraycontent['exifkeyafter'] = $exifafter;

                                if (!($exifafter == 0 || $exifafter == 1)) {
                                        $return = 1006;
                                }
                        }
                }

        } elseif ($arraycontent['filetype'] == "webp") {
                rotateWebpLossless($sourcePath, $targetPath, $arraycontent['degree'], $returnP);
                $return = $returnP;
                logfile($arraycontent['title'] . " rotated by " . $arraycontent['degree'] . "°: " . $returnP);

        } elseif ($arraycontent['filetype'] == "webm") {
                rotateWebmLossless($sourcePath, $targetPath, $arraycontent['degree'], $returnP);
                $return = $returnP;
                logfile($arraycontent['title'] . " rotated by " . $arraycontent['degree'] . "°: " . $returnP);

        } else {
                rotateRasterWithConvert($sourcePath, $targetPath, $arraycontent['degree'], $arraycontent['filetype'], $returnP);
                $return = $returnP;
                logfile($arraycontent['title'] . " rotated by " . $arraycontent['degree'] . "°: " . $returnP);
        }

        if ($return != 0) {
                $rx = "";
                switch ($return) {
                        case 1:
                                $rx = " (DCT coefficient out of range)";
                                $wrongfiles[$arraycontent["title"]] = "corrupt JPEG file." . $rx;
                                logfile("sort out; corrupt JPEG file." . $rx);
                                break;
                        case 2:
                                $rx = " (Premature end of JPEG file)";
                                $wrongfiles[$arraycontent["title"]] = "corrupt JPEG file." . $rx;
                                logfile("sort out; corrupt JPEG file." . $rx);
                                break;
                        case 1003:
                                $rx = " ($exif)";
                                $wrongfiles[$arraycontent["title"]] = "Unexpected exif orientation: " . $rx;
                                logfile("unexpected exif orientation." . $rx);
                                break;
                        case 1004:
                                $rx = " ($realrotate)";
                                $wrongfiles[$arraycontent["title"]] = "Unexpected realrotate value." . $rx;
                                logfile("Unexpected realrotate value." . $rx);
                                break;
                        case 1005:
                                $rx = " (ec: $retexifwrite)";
                                $wrongfiles[$arraycontent["title"]] = "EXIF had severe errors on write attempt." . $rx;
                                logfile("EXIF had severe errors on write attempt." . $rx);
                                break;
                        case 1006:
                                $rx = " ($exifafter)";
                                $wrongfiles[$arraycontent["title"]] = "EXIF had not 0 or 1 after process." . $rx;
                                logfile("EXIF had not 0 or 1 after process." . $rx);
                                break;
                        case 1007:
                                $rx = " ($exif)";
                                $wrongfiles[$arraycontent["title"]] = "reset EXIF Orientation reset requested but it was already 0 or 1." . $rx;
                                logfile("reset EXIF Orientation reset requested but it was already 0 or 1." . $rx);
                                break;
                        case 1008:
                                $rx = " ($exif)";
                                $wrongfiles[$arraycontent["title"]] = "No rotation requested and no EXIF-based rotation? Sorry, there is nothing I can do!" . $rx;
                                logfile("No rotation requested and no EXIF-based rotation present. Nothing to do." . $rx);
                                break;
                        case 1009:
                                $rx = " ($exif)";
                                $wrongfiles[$arraycontent["title"]] = "Duplicate IFD0:Orientation tags were found. MW handles those in a strange way. Use rotate template's parameter 'resetexif' (instead of degree number) to reset the EXIF orientation information of this file." . $rx;
                                logfile("Duplicate IFD0:Orientation tags were found." . $rx);
                                break;
                        default:
                                $wrongfiles[$arraycontent["title"]] = "Rotation tool exited with unexpected code $return (signal " . ($return - 128) . " if >128, e.g. SIGABRT=134 means ImageMagick hit a resource policy limit).";
                                logfile("Rotation failed with unexpected exit code $return for " . $arraycontent['title']);
                                break;
                }
        } else {
                $catcontent2[$filename] = $arraycontent;
        }
}

logfile("All images turned and EXIF corrected!\nStart upload...");


/* -- Upload rotated images -- */

$logfilew = '';

foreach ($catcontent2 as $filename => $arraycontent) {
        if ($arraycontent['degree'] == "RESETEXIF") {
                $filesum = sprintf($config['resetuploadsummary'] . " " . $config['exifuploadsum'], $arraycontent['exifkey'], $arraycontent['exifkeyafter'], $arraycontent['exifwriteerr'], $arraycontent['realdegree']);
        } elseif (!($arraycontent['exifkey'] == 0 || $arraycontent['exifkey'] == 1)) {
                $filesum = sprintf($config['uploadsummary'] . " " . $config['exifuploadsum'], $arraycontent['degree'], $arraycontent['exifkey'], $arraycontent['exifkeyafter'], $arraycontent['exifwriteerr'], $arraycontent['realdegree']);
        } else {
                $filesum = sprintf($config['uploadsummary'], $arraycontent['degree']);
        }

        logfile("upload " . $arraycontent['title'] . " ... intern name: " . $filename . "_2." . $arraycontent['filetype']);
        sleep(1);
        echo "\n--- STARTING FILE UPLOAD ---\n";

        if (!$api->isLoggedin()) {
                echo "Issues with the api (not logged in).";
                die();
        }

        $title      = $arraycontent['title'];
        $title2     = str_replace(" ", "_", $title);
        $titlelocal = "/home/rotbot/cache/" . $filename . "_2." . $arraycontent['filetype'];

        if (file_exists($titlelocal)) {
                echo "The file " . $titlelocal . " exists.\n";
        } else {
                echo "\n\n\n!!!!!\nERROR: No local file found to upload! Skipping...\n!!!!!\n";
                continue;
        }

        $services     = new \Mediawiki\Api\MediawikiFactory($api);
        $fileUploader = $services->newFileUploader();
        $chksize      = filesize($titlelocal);

        echo "Filesize is " . $chksize . " bytes.\n";

        if ($config['Chunked'] == "true" && $chksize >= $config['ChunkedStarter']) {
                echo "Using chunked upload function for this big file.\n";
                logfile("Setting chunked uploads as upload method.");
                $fileUploader->setChunkSize(1024 * 1024 * 10);
        }

        $renametemp = (isset($addrename[$arraycontent['title']]) && $addrename[$arraycontent['title']] === true) ? "{{rename}}\n" : "";

        try {
                $fileUploader->upload($title2, $titlelocal, null, $filesum, 'nochange', 'true');
        } catch (Exception $e) {
                $reasonFull = $e->getMessage();
                $reason     = substr($reasonFull, 0, 80);

                error_log("Upload exception for $title2: " . $reasonFull);

                $quelltext = wikicontent($title);
                $forupload = str_ireplace("{{rotate", "{{rotate|nobot=true|reason='''Reason''': $reason", $quelltext, $count);
                $forupload = $renametemp . $forupload;
                RotateEdit($title, $forupload, "Bot: Can't rotate image? - MW API Exception");

                $logfilew .= "\n----\n    <span style=\"color:red;text-decoration:blink\">'''Error (MW API)'''</span> can't rotate [[:$title]], API returned an error or a warning:\n ''$reason''\n";
                continue;
        }

        echo "\n--- END FILE UPLOAD ---\n\n";
        sleep(1);

        logfile($arraycontent['title'] . " uploaded!");
        $catcontent2[$filename]['doneat'] = date("Y-m-d\TH:i:s\Z", time());

        $quelltext  = wikicontent($arraycontent['title']);
        $forupload  = preg_replace('/(^((?!\n).)*\{\{[Rr]otate *\|[^\}\}]*\}\}\n|\{\{[Rr]otate *\|[^\}\}]*\}\})/', '', $quelltext);
        $count_alt  = 1;

        if ($count_alt > 0) {
                logfile("remove template");

                if ($arraycontent['degree'] == "RESETEXIF") {
                        $edsum = $config['reseteditsummary'];
                } else {
                        $edsum = sprintf($config['editsummary'], $arraycontent['degree']);
                }

                RotateEdit($arraycontent['title'], $forupload, $edsum);
                $nodelete[$arraycontent['title']] = ($c == true) ? 0 : 1;
        } else {
                logfile("ERROR: TEMPLATE NOT FOUND!");
                $nodelete[$arraycontent['title']] = 1;
        }

        logfile("\n-------NEXT--------- \n");
}

logfile("Upload finished. Do error pictures now.");


/* -- Clear cache -- */

foreach ($catcontent2 as $filename => $arraycontent) {
        unlink("/home/rotbot/cache/" . $filename . "." . $arraycontent['filetype']);
        unlink("/home/rotbot/cache/" . $filename . "_2." . $arraycontent['filetype']);
        unlink("/home/rotbot/cache/" . $filename . "_2." . $arraycontent['filetype'] . "_original");
}

logfile("cache cleared. Write log now.");


/* -- Write wiki log -- */

$logfilew  = wikicontent('User:SteinsplitterBot/Rotatebot');
$somanyrot = count($catcontent2);
$logfilew  = deleteold($logfilew, $somanyrot, $config['logfilesize'], $config['logheader']);

foreach ($wrongfiles as $title => $reason) {
        $quelltext = wikicontent($title);
        $forupload = str_ireplace("{{rotate", "{{rotate|nobot=true|reason='''Reason''': $reason", $quelltext, $count);

        if ($addrename[$title] == true && !stristr($forupload, "{{rename")) {
                $renametemp = "{{rename}}\n";
        } else {
                $renametemp = "";
        }

        $forupload = $renametemp . $forupload;

        if ($count > 0) {
                RotateEdit($title, $forupload, "Bot: Can't rotate image");
                $logfilew .= "\n----\n    <span style=\"color:red;text-decoration:blink\">'''Error'''</span> can't rotate [[:$title]]:\n ''$reason''\n";
                logfile("set template of $title to nobot");
        } else {
                logfile("Template:Rotate in $title NOT FOUND!");
                $logfilew .= "\n----\n    <span style=\"color:red;text-decoration:blink\">'''Error'''</span> can't rotate [[:$title]]:\n ''$reason''\n";
                $logfilew .= "<p>'''Warning(?):'''Template not found, file probably still in the category!!</p>\n";
        }
}

foreach ($catcontent2 as $arraycontent) {
        $logfilew .= "\n----\n";
        $logfilew .= "[[" . $arraycontent['title'] . "|thumb|110px]]\n";
        $logfilew .= "*[[:" . str_replace("_", " ", $arraycontent['title']) . "]] (" . $arraycontent['size'] . ")\n";
        $logfilew .= "*:Last image-version uploaded by [[User:" . $arraycontent['uploader'] . "|]] at " . timestampto($arraycontent['upltime']) . " (UTC)\n";

        if ($arraycontent['tmplsetter']) {
                $logfilew .= "*:{{[[Template:Rotate|]]}} added (or modified) by [[User:" . $arraycontent['tmplsetter'] . "|]] at " . timestampto($arraycontent['since']) . " (UTC)\n";
                $logfilew .= $userforlog[$arraycontent['tmplsetter']] . "\n";
        }

        $logfilew .= ":{{ok|Rotated}} through " . $arraycontent['degree'] . "° at " . timestampto($arraycontent['doneat']) . " (UTC) (=" . difftime($arraycontent['since'], $arraycontent['doneat']) . " later)\n";
        $logfilew .= "<br style=\"clear:both;\" clear=\"all\" />";
}

if ($somanyrot > 0 || count($wrongfiles) > 0) {
        logfile("write logfile. ($somanyrot pictures)");
        RotateEdit("User:SteinsplitterBot/Rotatebot", $logfilew, "Bot: $somanyrot images rotated.");
}

unset($tools_mycnf, $tools_pw);

suicide("Bot finished.");
