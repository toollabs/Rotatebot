<?php
/*   Copyright © by Luxo & Saibo, 2011 - 2014
                 by Steinsplitter, 2014  -

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

ini_set('memory_limit', '1000M'); //Speicher auf 100 MBytes hochsetzen
ini_set('user_agent', 'SteinsplitterBot (rotbot; wmflabs; php)');

require 'vendor/autoload.php';

use \Mediawiki\Api\ApiUser;
use \Mediawiki\Api\MediawikiApi;
use \Mediawiki\Api\MediawikiFactory;
use Mediawiki\Api\UsageException;

class RotateBotConfig {
        /** Bot active? */
        public bool $active = false;
        /**
         * User-group with permission for rotating
         * - 0 = all users
         * - 1 = autoconfirmed users
         */
        public string $rotatepermission = '';
        /**
         * minimum contribs of autoconfirmed users for rotating
         * (ONLY if rotatepermission == 1 !)
         */
        public int $mincontribs = 0;
        /** Size of the Logfile (User:SteinsplitterBot/Rotatebot) */
        public int $logfilesize = 0;
        /** Maximal rotatings at once max: 30 otherwise it could lead to disk full. 40 seems to be okay, too. */
        public int $limit = 0;
        /** Maximal filesize of pictures in bytes */
        public int $fileSizeLimit = 0;
        /** Use chunked upload function (true/false) */
        public string $Chunked = '';
        /** Use the chunked upload function if filesize is bigger than (in bytes) */
        public int $ChunkedStarter = 0;
        /** Automatically remove lockfile if older than x. Example, 8 * 3600 = 8 hours. */
        public string $maxlockfiletime = '';
        /** Minimal lag of the bot in minutes */
        public int $lag = 0;
        /** Kill all running rotatebots? */
        public bool $killAllRotatebots = false;
        /** Do not die if there are lock problems? (true/false) */
        public string $dontDieOnLockProblems = '';
        public string $uploadsummary = '';
        public string $resetuploadsummary = '';
        public string $exifuploadsum = '';
        public string $editsummary = '';
        public string $reseteditsummary = '';
        public string $logheader = '';

        public function __construct(string $file) {
                foreach (explode("\n", $file) as $line) {
                        $line = trim($line);

                        if(substr($line,0,2) != "//" AND $line != "")
                        {
                                $gleich = strpos($line, "=");
                                $namecon = trim(substr($line,3,$gleich-3));
                                $stripu = strpos($line,";");
                                $content = trim(substr($line,$gleich+1,$stripu - ($gleich + 1)));

                                // Remove eventual ""
                                if(substr($content, 0, 1) == '"')
                                {
                                        $content = substr($content, 1);
                                }

                                if(substr($content, -1) == '"')
                                {
                                        $content = substr($content, 0, -1);
                                }

                                try {
                                        $prop = new ReflectionProperty($this, $namecon);
                                        if (($type = $prop->getType()) !== null && $type->getName() === 'bool') {
                                                $this->$namecon = filter_var(trim($content), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                                        } else {
                                                $this->$namecon = trim($content);
                                        }
                                } catch (ReflectionException $e) {
                                        // unknown setting
                                }
                        }
                }
        }
}

class Image {
        /** Title of the image, with namespace, with spaces (not underscores) */
        public string $title;
        public int $pageid;
        public string $url;
        public array $metadata;
        public string $uploader;
        public DateTimeImmutable $upltime;
        /** Size of the image as `WxH` */
        public string $size;
        public string $filetype;
        /** The degree to rotate the image by, or special command `RESETEXIF` (or 0 degrees) to reset EXIF */
        public string $degree;
        /** User who added the template */
        public ?User $tmplsetter = null;
        public DateTimeImmutable $since;
        public int $realdegree;
        public int $exifkey = 0;
        public int $exifkeyafter = 0;
        public string $exifwriteerr = '';
        /**
         * The reason for which this file is wrong, empty string if it’s
         * wrong but this shouldn’t be logged, or `null` if it isn’t wrong
         */
        public ?string $wrongfile = null;
        /** Whether it has a meaningless title. If so, the bot will ask for it to be renamed. */
        public bool $badtitle = false;
        public DateTimeImmutable $doneat;

        public function __construct(string $title) {
                $this->title = $title;
        }
}

class User {
        public string $name;
        public int $id;
        public int $editcount;
        public bool $autoconfirmed;
        public string $logmessage;
}

class RotateBot {
        private const HOME_DIR = __DIR__;
        /** Directory to write logs into (in addition to standard output) */
        private const LOG_DIR = RotateBot::HOME_DIR . '/public_html/rotatelogs/';
        /** Name of the lock file ensuring that only a single instance of RotateBot is running. */
        private const LOCK_FILE = RotateBot::HOME_DIR . '/rotatebotlock';
        /** Directory to download images to */
        private const CACHE_DIR = RotateBot::HOME_DIR . '/cache/';

        private const CATEGORY = 'Category:Images_requiring_rotation_by_bot';
        private const REPORT_PAGE = 'User:SteinsplitterBot/Rotatebot';

        private MediawikiApi $api;
        private MediawikiFactory $services;
        private RotateBotConfig $config;

        private function __construct() {
                $this->logfile('Starting bot...');

                $this->api = $this->login();
                $this->services = new MediawikiFactory( $this->api );
                $this->config = new RotateBotConfig( $this->getPageContent( 'User:SteinsplitterBot/rconfig.js' ) );
        }

        public function __destruct() {
                $this->removeLock();
        }

        private function login(): MediaWikiApi {
                include 'accessdata.php';
                $api = new MediawikiApi( $botapi ?? 'https://commons.wikimedia.org/w/api.php' );
                $api->login( new ApiUser( $botusername, $botkey ) );
                return $api;
        }

        public static function run(): void {
                $bot = new static();
                if ($bot->config->active) {
                        if ($bot->config->killAllRotatebots) {
                                $bot->killAllRotatebots();
                        } else {
                                $bot->runNormal();
                        }
                } else {
                        throw new RuntimeException('Bot disabled.');
                        echo "Bot disabled.\n";
                }
        }

        private function killAllRotatebots(): void {
                $this->logfile("ATTENTION: Going to killAllRotatebots (also myself)!");
                // "php rotbot/rotbot.php" needs to be the process name
                $this->logfile("signal 15");
                system("pkill -15 -f 'php rotbot/rotbot.php'");
                sleep(10);
                $this->logfile("signal 9");
                system("pkill -9 -f 'php rotbot/rotbot.php'");
                // we should be all dead now
                echo "killAllRotatebots is true. Cannot start!\n";
        }

        private function runNormal(): void {
                $this->getLockOrDie();

                // unlink old cache
                foreach (glob(RotateBot::CACHE_DIR . '*') as $cacheunlink) {
                        if(is_file($cacheunlink)) {
                                unlink($cacheunlink);
                                echo "rm $cacheunlink";
                        }
                }

                /** @var Image[] */
                $allImages = array_map([$this, 'checkImage'], $this->loadImages());
                [$goodImages, $wrongFiles] = $this->groupImages($allImages);

                /* ***********************************************************
                *******    ARRAY MIT BILDER & GRADE GELADEN ***************
                *******       WEITER GEHT'S MIT IMAGESAVE   ***************
                *********************************************************** */
                $this->logfile("------------");
                $this->logfile("Picture load finished - " . count($goodImages) . " pictures ready for download, ".count($wrongFiles)." pics with errors.");
                array_walk($goodImages, [$this, 'downloadImage']);
                $this->logfile("Download finished!");

                array_walk($goodImages, [$this, 'rotateImage']);
                $this->logfile("All images turned and exif corrected!\nStart upload...");
                // Some images may have become bad, sort out those
                [$goodImages, $badImages] = $this->groupImages($allImages);

                array_walk($goodImages, [$this, 'uploadImage']);
                [$goodImages, $badImages] = $this->groupImages($allImages);
                $this->logfile("Upload finished. Do error pictures now.");


                //Clean cache
                foreach($goodImages as $i => $image)
                {
                        unlink(RotateBot::CACHE_DIR . "{$i}.{$image->filetype}");
                        unlink(RotateBot::CACHE_DIR . "{$i}_2.{$image->filetype}");
                        unlink(RotateBot::CACHE_DIR . "{$i}_2.{$image->filetype}_original");
                }
                $this->logfile("cache cleared. Write log now.");

                $this->writeLogs($goodImages, $badImages);
                echo "Bot finished.\n";
        }

        /**
         * @param Image[] $images
         * @return array{0:array<int,Image>,1:array<int,Image>}
         */
        private function groupImages(array $images): array {
                $goodImages = [];
                $badImages = [];
                foreach ($images as $image) {
                        if ($image->wrongfile === null) {
                                $goodImages[] = $image;
                        } elseif ($image->wrongfile !== '') {
                                $badImages[] = $image;
                        }
                }
                return [$goodImages, $badImages];
        }

        /**
         * @return array<int,array{pageid:int,ns:int,title:string,sortkey:string,timestamp:string,revisions:array,imageinfo:array}>
         */
        private function loadImages(): array {
                $this->logfile("Checking '" . RotateBot::CATEGORY . "' for files.");

                // Load category members with categorization-specific data
                $params1 = [
                        'list' => 'categorymembers',
                        'cmtitle' => RotateBot::CATEGORY,
                        'cmtype' => 'file',
                        'cmprop' => 'ids|title|sortkey|timestamp',
                        'cmsort' => 'timestamp',
                        'cmlimit' => $this->config->limit,
                ];
                $pages1 = $this->api->getRequest( new \MediaWiki\Api\SimpleRequest( 'query', $params1 ) )['query']['categorymembers'];

                if (!count($pages1)) {
                        return [];
                }

                // Load image-specific data
                $params2 = [
                        'pageids' => implode('|', array_map(fn (array $page) => $page['pageid'], $pages1)),
                        'prop' => 'revisions|imageinfo',
                        'iiprop' => 'timestamp|user|url|size|metadata',
                ];
                $pages2 = $this->api->getRequest( new \MediaWiki\Api\SimpleRequest( 'query', $params2 ) )['query']['pages'];

                foreach ($pages1 as $picture) {
                        $ctidd = $picture['pageid'];
                        foreach ($picture as $contXB => $contXI) {
                                $pages2[$ctidd][$contXB] = $contXI;
                        }
                }
                return $pages2;
        }

        /** @param array{pageid:int,ns:int,title:string,sortkey:string,timestamp:string,revisions:array,imageinfo:array} $picture */
        private function checkImage(array $picture): Image {
                $image = new Image($picture['title']);
                $this->logfile("-------------");
                $this->logfile("Checking ".$picture['title']."...");

                //dateiendung bestimmen - gültiges Dateiformat?
                if(substr(strtolower($picture['title']),-4) == ".jpg" OR substr(strtolower($picture['title']),-5) == ".jpeg") { $image->filetype = "jpg"; }
                else if(substr(strtolower($picture['title']),-4) == ".png") { $image->filetype = "png"; }
                else if(substr(strtolower($picture['title']),-4) == ".gif") { $image->filetype = "gif"; }
                // if(substr(strtolower($picture['title']),-5) == ".tiff" OR substr(strtolower($picture['title']),-4) == ".tif") { $image->filetype = "tif"; }
                else { $image->wrongfile = "filetype not supported (".substr(strtolower($image->title),-3).")"; }
                //sortkey ab umbruchstelle beschneiden
                $image->degree = trim(stristr($this->hexToStr($picture["sortkey"]), "\n", true));

                // Do not process big images ...
                $this->logfile("Size of this picture: ".$picture['imageinfo']['0']["size"]." bytes. Limit set to {$this->config->fileSizeLimit} bytes");
                if($picture['imageinfo']['0']["size"] > $this->config->fileSizeLimit) {
                        $this->logfile("File bigger (".$picture['imageinfo']['0']["size"]." B) than limit ({$this->config->fileSizeLimit} B)");
                        $image->wrongfile = "File bigger (".$picture['imageinfo']['0']["size"]." B) than limit ({$this->config->fileSizeLimit} B). Please wait until someone does a lossless(!) fix by hand.";
                }


                //Korrekte Grade/Aktion prüfen
                if($image->degree != 90 AND $image->degree != 180 AND $image->degree != 270) {
                        // exceptions for jpegs... last chance. ;-)
                        if(($image->filetype == "jpg" AND ($image->degree == "RESETEXIF" OR  $image->degree == "0" OR  $image->degree == "00" OR $image->degree == "000" ))) {
                                // okay, jpg AND (reset OR 0°) requested (cannot include in the in comparision since any string would match == 0) - EXIF based orientation should be applied apparently or be resetted
                        } else {
                                $this->logfile("wrong degree-parameter ({$image->degree}°)");
                                $image->wrongfile = "wrong degree-parameter ({$image->degree}°)";
                        }
                }

                $since = trim($picture["timestamp"]);
                $image->since = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $since);
                //Do not process images younger than x minutes
                $lagTime = $this->config->lag * 60;
                $settimestamp   = $image->since->getTimestamp();
                $inCatSince     = time() - $settimestamp;
                $this->logfile("set at: '$settimestamp' ({$since}) Lag: $lagTime seconds, in the category since $inCatSince seconds.");

                if ($inCatSince < $lagTime) {
                        $image->wrongfile = '';
                        $this->logfile("Picture younger than $lagTime seconds. \n\nSORT OUT\n\n");
                } else {
                        $this->logfile("Picture older than $lagTime seconds, ok.");
                }


                // Identify the user who added the template
                foreach($picture['revisions'] as $key => $revisions)
                {
                        if(trim($revisions['timestamp']) == $since)
                        {
                                $tmplsetter = $picture['revisions'][$key]['user'];
                                $this->logfile("Template by: {$tmplsetter}");
                        }
                        else
                        {
                                // The page has been edited since the template was added, look at a few more revisions
                                $this->logfile("set time({$since}) not identical with this rv, ".$revisions['timestamp'].".");
                                $this->logfile("ID: ".$picture['pageid']." ");
                                $page = $this->services->newPageGetter()->getFromPageId( $picture['pageid'], ['rvlimit' => 20] );
                                foreach ($page->getRevisions()->toArray() as $revision) {
                                        if ($revision->getTimestamp() == $since) {
                                                $tmplsetter = $revision->getUser();
                                        }
                                }
                        }
                }


                //Check user! #########################################
                if(isset($tmplsetter)) //autoconfirmed
                {
                        $image->tmplsetter = $this->checkUser($tmplsetter);
                        if ($this->config->rotatepermission == "1") {
                                if (!$image->tmplsetter->autoconfirmed) {
                                        $image->wrongfile = "The account of the user who set the template ([[User:{$tmplsetter}|]]) is ''not autoconfirmed''.<br />'''Unlock:''' An autoconfirmed user should delete the parameters <code><nowiki>|</nowiki>nobot=true<nowiki>|</nowiki>reason=...</code> in this image. Thank you --~~~~";
                                }
                                if ($this->config->mincontribs > 0 && $image->tmplsetter->editcount < $this->config->mincontribs) {
                                        $this->logfile("{$tmplsetter} has not enough edits!");
                                        $image->wrongfile = "The account of the user who set the template ([[User:{$tmplsetter}|]]) has under {$this->config->mincontribs} edits.<br />'''Unlock:''' An autoconfirmed user with more than {$this->config->mincontribs} edits should delete the parameters <code><nowiki>|</nowiki>nobot=true<nowiki>|</nowiki>reason=...</code> in this image. Thank you --~~~~";
                                }
                        }
                }




                //per regex auf schlechte dateinamen prüfen
                $regex = "File:(?:DVC|CIMG|IMGP?|PICT|DSC[FN]?|DUW|JD|MGP|scan|untitled|foto|imagen|img|image|picture|p|BILD)?[0-9_ \-\(\)\{\}\[\]]+\..*";
                if(preg_match("/".$regex."/",$picture['title']) == 1)
                {
                        //Kann nicht hochgeladen werden, Blacklisted
                        /*#######################
                        Im moment deaktiviert
                        ######################*/
                        // $image->wrongfile = "Image can't be rotated by Rotatebot because it has a senseless title. Please rename first.";
                        $image->badtitle = true;
                }


                if($image->wrongfile === null) //Bild scheint OK zu sein
                {
                        $this->logfile("picture and user check finished, sorted for download");

                        $image->pageid   = $picture['pageid'];
                        $image->url      = $picture['imageinfo']['0']['url'];
                        $image->metadata = $picture['imageinfo']['0']['metadata'];
                        $image->uploader = $picture['imageinfo']['0']['user'];
                        $image->upltime  = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $picture['imageinfo']['0']['timestamp']);
                        $image->size     = $picture['imageinfo']['0']['width']."x".$picture['imageinfo']['0']['height'];
                }
                else
                {
                        $this->logfile("picture and user check finished, no download. ");
                }
                return $image;
        }

        private function checkUser(string $name): User {
                static $users = [];
                if (!array_key_exists($name, $users)) {
                        $this->logfile("Checking user $name.");

                        $user = $this->services->newUserGetter()->getFromUsername($name);
                        $user2 = new User;
                        $user2->name = $name;
                        $user2->id = $user->getId();
                        $user2->editcount = $user->getEditcount() ?? 0;
                        $user_registration = $user->getRegistration();

                        $this->logfile("Edits: {$user2->editcount}");
                        $this->logfile("Registred at: " . ($user_registration ?: '?'));

                        if ($user_registration) {
                                $regiUnix = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $user_registration)->getTimestamp();
                                $actuUnix = time();
                                $registeredtime = $actuUnix - $regiUnix;
                                $registereddays = number_format($registeredtime / 86400, 1, '.', ' '); // round/format as hours
                                $user2->autoconfirmed = $registeredtime >= 345600; // 345600 sec = 4 days
                        } else {
                                // Old accounts don’t have registration timestamp, assume they are autoconfirmed
                                $registereddays = '?';
                                $user2->autoconfirmed = $user2->id !== 0;
                        }

                        if ($user2->autoconfirmed) {
                                $xx = '';
                        } else {
                                $this->logfile("$name is not autoconfirmed!");
                                $xx = 'not ';
                        }

                        $setuserid = $user2->id ?: '-';
                        $user2->logmessage = "***$name, userid $setuserid, {$user2->editcount} edits, registered since $registereddays days, is '''{$xx}autoconfirmed.'''";

                        $users[$name] = $user2;
                }
                return $users[$name];
        }

        private function downloadImage(Image $image, int $i): void {
                $this->logfile("save {$image->title}");

                $con = curl_init($image->url);
                $fp = fopen(RotateBot::CACHE_DIR . "$i.{$image->filetype}", "wb");
                curl_setopt($con, CURLOPT_FILE, $fp);
                curl_setopt($con, CURLOPT_CONNECTTIMEOUT, 4);
                curl_setopt($con, CURLOPT_USERAGENT, 'Rotatebot; User:Steinsplitter (wmflabs; php)');
                curl_exec($con);
                curl_close($con);
                fclose($fp);
        }

        /** Rotate an image and adjust EXIF */
        private function rotateImage(Image $image, int $i): void {
                $sourceName = RotateBot::CACHE_DIR . "$i.{$image->filetype}";
                $targetName = RotateBot::CACHE_DIR . "{$i}_2.{$image->filetype}";
                $return = 0;
                if($image->filetype == "jpg") //Für JPEG lossless methode
                {
                        //Exif auslesen
                        // /usr/bin/exiftool -IFD0:Orientation -b 1.jpg     -a is to get dupe tags, too
                        $exif = system("/usr/bin/exiftool -IFD0:Orientation -b -a $sourceName");
                        settype($exif, "integer");
                        $this->logfile("EXIF is $exif");
                        $image->exifkey = $exif; //for editsummary

                        if ($image->degree == "RESETEXIF") {   // if ignoring EXIF is wished ...
                                switch($exif) {
                                        case 0:  // no Orientation tag existent
                                        case 1:
                                                $this->logfile("reset EXIF Orientation reset requested but it was already 0 or 1");
                                                $return=1007; // unexpected EXIF was found
                                        break;
                                        default:
                                                $exifR = 0; // ignore any existing EXIF
                                }

                        } else {
                                if ($exif >= 10) {  //do we have duplicate Orientation tags?  They get reported by exiftool like "18".
                                        $this->logfile("duplicate Orientation tags!");
                                        $return=1009; // Duplicate Orientation tags were found
                                } else {
                                        //Use EXIF Orientation (=  roation applied by MediaWiki for displaying) and user input to find the correct rotation
                                        switch($exif) {
                                                case 0:  // no Orientation tag existent
                                                case 1:
                                                        if ($image->degree == 0) { // No rotation requested AND exif normal?
                                                                $this->logfile("exif was 0 or 1 and no rotation requested");
                                                                $return=1008; //
                                                        } else {
                                                                $exifR = 0;
                                                        }
                                                break;
                                                case 3:
                                                        $exifR = 180;
                                                break;
                                                case 6:
                                                        $exifR = 90;
                                                break;
                                                case 8:
                                                        $exifR = 270;
                                                break;
                                                default:
                                                        $this->logfile("exif was not 0,1,3,6,8");
                                                        $return=1003; // unexpected EXIF was found
                                        }
                                }
                        }

                        if ($return == 0) { // if no unexpected EXIF was found

                                if ($image->degree == "RESETEXIF") {   // if ignoring EXIF is wished ...
                                        $realrotate = 0; // do not rotate
                                        $image->realdegree = 0;  //    for editsummary
                                } else {
                                        $realrotate = $image->degree + $exifR;  // Saibo2 formula. user specified rotaation + already applied rotation by MW
                                        $this->logfile("File must be rotated $realrotate degree.");
                                        $realrotate = (360 + ($realrotate % 360)) % 360;    // convert to 0-259
                                        $image->realdegree = $realrotate;  //    for editsummary
                                }
                                switch($realrotate)
                                {
                                        case 0:
                                                //kopie erstellen
                                                $this->logfile("just exif correction, picture correct");
                                                $cmd = "cp $sourceName $targetName";
                                                $this->logfile($cmd);
                                                passthru($cmd);
                                                break;
                                        case 90:
                                        case 180:
                                        case 270:
                                                //rotieren ...
                                                $cmd = "./bins/jpegtran -rotate $realrotate -trim -copy all $sourceName > $targetName";
                                                $this->logfile($cmd);
                                                passthru($cmd,$return);
                                                $this->logfile("{$image->title} rotated by {$realrotate}°.");
                                                break;

                                        default:
                                                $this->logfile("Bullshit happend: realrotate was $realrotate.");
                                                $return=1004;
                                }

                                //escape shell nicht notwendig, keine Benutzerdaten im cmd verwendet

                                $doBruteForceClean = false; // init

                                if ($return == 0 && !($exif == 0 || $exif == 1)) { // only if no error occured and change necessary
                                        //EXIF-orient-tag auf 1 stellen, nur bei jpeg
                                        // /usr/bin/exiftool -Orientation=1 -n  1.jpg
                                        if ($exif >= 10) {  //dupe Orientation tags?   Kill 'em all!
                                                // Needs to be removed because otherwise the duplicate tag stays
                                                // first attempt
                                                $cmd = "./bins/exiftool -IFD0:Orientation= -n  $targetName";
                                                $this->logfile($cmd);
                                                passthru($cmd,$retexifwrite);

                                                if ($retexifwrite == 0) {  // if successful
                                                        $this->logfile("No errors on EXIF-to-0");
                                                        $exifwriteerr = ""; // clear - no error since it worked in first attempt
                                                } else {
                                                        // second attempt (ignoring minor errors)
                                                        $cmd = "/usr/bin/exiftool -IFD0:Orientation= -n -m  $targetName";
                                                        $this->logfile($cmd);
                                                        passthru($cmd,$retexifwrite);

                                                        if ($retexifwrite == 0) {  // if successful
                                                                $this->logfile("No errors on EXIF-to-0 (second try)");
                                                                $exifwriteerr = " - EXIF had minor errors. Some EXIF could be lost. - ";
                                                        } else {
                                                                $doBruteForceClean = true;
                                                        }
                                                }
                                        } else {
                                                // first attempt
                                                $cmd = "/usr/bin/exiftool -IFD0:Orientation=1 -n  $targetName";
                                                $this->logfile($cmd);
                                                passthru($cmd,$retexifwrite);

                                                if ($retexifwrite == 0) {  // if successful
                                                        $this->logfile("no errors when setting EXIF to 1");
                                                        $exifwriteerr = ""; // clear - no error since it worked in first attempt
                                                } else {
                                                        // second attempt (ignoring minor errors)
                                                        $cmd = "/usr/bin/exiftool -IFD0:Orientation=1 -n -m  $targetName";
                                                        $this->logfile($cmd);
                                                        passthru($cmd,$retexifwrite);

                                                        if ($retexifwrite == 0) {  // if successful
                                                                $this->logfile("no errors when setting EXIF to 1 (second try)");
                                                                $exifwriteerr = " - EXIF had minor errors. Some EXIF could be lost. - ";
                                                        } else {
                                                                $doBruteForceClean = true;
                                                        }
                                                }
                                        }


                                        if ($doBruteForceClean) {
                                                // third attempt (ignoring nearly all errors)  - copy all readable tags but leave the Orientation tag away
                                                $cmd = "/usr/bin/exiftool -all= -tagsfromfile @ -all:all --IFD0:Orientation $targetName";
                                                $this->logfile($cmd);
                                                passthru($cmd,$retexifwrite);

                                                if ($retexifwrite == 0) {  // if successful
                                                        $this->logfile("no errors when setting EXIF to 0 (third try)");
                                                        $exifwriteerr = " - EXIF had major errors. Great parts of EXIF could be lost. - ";
                                                } else {
                                                        // complete failure
                                                        $return = 1005;
                                                }
                                        }


                                        $image->exifwriteerr = $exifwriteerr; //for editsummary
                                }

                                if ($return == 0) { // only if no error occured
                                        //Exif auslesen als Test
                                        // /usr/bin/exiftool -IFD0:Orientation -b 1.jpg    -a is to get dupe tags, too
                                        $exifafter = system("/usr/bin/exiftool -IFD0:Orientation -b -a $targetName");
                                        settype($exifafter, "integer");
                                        $this->logfile("read EXIF after finish: $exifafter");
                                        $image->exifkeyafter = $exifafter; //for editsummary

                                        if (!($exifafter == 0 || $exifafter == 1)) {  // if unsuccessful
                                                $return = 1006;
                                        }
                                }
                        }
                }
                else //For png's und gif's
                {
                        passthru("./bins/convert $sourceName -rotate {$image->degree} $targetName", $returnP);
                        $this->logfile("{$image->title} rotated by {$image->degree}°: $returnP");
                }
                // TODO:  ich bau mal ne Verwendung von $returnP in der Fehlerbehandlung nachfolgend ein..  der Fehlerwert beim PNG/GIF-Drehen wird gar nicht verwendet


                //  sleep(5); //wait 5 sec. between rotating images

                switch($return) {
                        case 0:
                                // correctly rotated, continue
                                break;
                        case 1:
                                $rx = " (DCT coefficient out of range)";
                                $image->wrongfile = "corrupt JPEG file.".$rx;
                                $this->logfile("sort out; corrupt JPEG file.".$rx);
                                break;
                        case 2:
                                $rx = " (Premature end of JPEG file)";
                                $image->wrongfile = "corrupt JPEG file.".$rx;
                                $this->logfile("sort out; corrupt JPEG file.".$rx);
                                break;
                        case 1003:
                                $rx = " ($exif)";
                                $image->wrongfile = "Unexpected exif orientation: ".$rx;
                                $this->logfile("unexpected exif orientation.".$rx);
                                break;
                        case 1004:
                                $rx = " ($realrotate)";
                                $image->wrongfile = "Bullshit happend: realrotate was.".$rx;
                                $this->logfile("Bullshit happend: realrotate was.".$rx);
                                break;
                        case 1005:
                                $rx = " (ec: $retexifwrite)";
                                $image->wrongfile = "EXIF had severe errors on write attempt.".$rx;
                                $this->logfile("EXIF had severe errors on write attempt.".$rx);
                                break;
                        case 1006:
                                $rx = " ($exifafter)";
                                $image->wrongfile = "EXIF had not 0 or 1 after process.".$rx;
                                $this->logfile("EXIF had not 0 or 1 after process.".$rx);
                                break;
                        case 1007:
                                $rx = " ($exif)";
                                $image->wrongfile = "reset EXIF Orientation reset requested but it was already 0 or 1.".$rx;
                                $this->logfile("reset EXIF Orientation reset requested but it was already 0 or 1.".$rx);
                                break;
                        case 1008:
                                $rx = " ($exif)";
                                $image->wrongfile = "No rotation requested and no EXIF-based rotation? Sorry, there is nothing I can do!".$rx;
                                $this->logfile("No rotation requested and no EXIF-based rotation present. Nothing to do.".$rx);
                                break;
                        case 1009:
                                $rx = " ($exif)";
                                $image->wrongfile = "Duplicate IFD0:Orientation tags were found. MW handles those in a strange way. Use rotate template's parameter 'resetexif' (instead of degree number) to reset the EXIF orientation information of this file.".$rx;
                                $this->logfile("Duplicate IFD0:Orientation tags were found.".$rx);
                                break;
                        default:
                                $image->wrongfile = '';
                                break;
                }
        }

        /** Actually upload an image, remove rotation template */
        private function uploadImage(Image $image, int $i): void {
                try {
                        //upload summary
                        $config = $this->config;
                        if ($image->degree == "RESETEXIF") {
                                $filesum = sprintf("{$config->resetuploadsummary} {$config->exifuploadsum}", $image->exifkey,$image->exifkeyafter,$image->exifwriteerr,$image->realdegree);
                        } else {

                                if ($image->exifkey != 0 && $image->exifkey != 1) {  // if EXIF was not 0 or 1
                                        $filesum = sprintf("{$config->uploadsummary} {$config->exifuploadsum}", $image->degree,$image->exifkey,$image->exifkeyafter,$image->exifwriteerr,$image->realdegree);
                                } else {
                                        $filesum = sprintf($config->uploadsummary, $image->degree);
                                }
                        }

                        //Hochladen
                        $targetName = "{$i}_2.{$image->filetype}";
                        $this->logfile("upload {$image->title} ... intern name: $targetName");


                        sleep(1);
                        echo "\n--- STARTING FILE UPLOAD ---\n";
                        $title2 = str_replace(" ", "_", $image->title);
                        $titlelocal = RotateBot::CACHE_DIR . $targetName;

                        if (file_exists($titlelocal)) {
                                echo "The file $titlelocal exists.\n";
                        } else {
                                echo "\n\n\n!!!!!\nERROR: No local file found to upload! Skipping...\n!!!!!\n";
                                return;
                        }

                        $fileUploader = $this->services->newFileUploader();
                        $chksize = filesize($titlelocal);
                        echo "Filesize is $chksize bytes.\n";
                        if ($config->Chunked == "true" && $chksize >= $config->ChunkedStarter) {
                                echo "Using chunked upload function for this big file.\n";
                                $this->logfile("Setting chunked uploads as upload methode.");
                                $fileUploader->setChunkSize( 1024 * 1024 * 10 );
                        }
                        $fileUploader->upload($title2, $titlelocal, null, $filesum, /* $watchlist = */ 'nochange', /* $ignorewarnings = */ true);

                        echo "\n--- END FILE UPLOAD ---\n\n";
                        sleep(1);

                        $this->logfile("{$image->title} uploaded!");
                        $image->doneat = new DateTimeImmutable();

                        //Quelltext laden
                        $quelltext = $this->getPageContent( $image->title );
                        //Template erkennen
                        $forupload = preg_replace('/(^((?!\n).)*\{\{[Rr]otate *\|[^\}\}]*\}\}\n|\{\{[Rr]otate *\|[^\}\}]*\}\})/', '', $quelltext);

                        //Speichern
                        $this->logfile("remove template $template");

                        //Editsummary generieren
                        if($image->degree == "RESETEXIF") {
                                $edsum = $config->reseteditsummary;

                        } else {
                                $edsum = sprintf($config->editsummary, $image->degree);
                        }

                        $this->editPage($image->title, $forupload, $edsum);
                        $this->logfile("\n-------NEXT--------- \n");
                } catch (UsageException $t) {
                        $this->logfile("Unable to upload rotated file '$image->title': $t\n");
                        $image->wrongfile = 'Unable to upload rotated file';
                }
        }

        /**
         * @param Image[] $goodImages
         * @param Image[] $badImages
         */
        private function writeLogs(array $goodImages, array $badImages): void {
                $logmessages = [];

                // Go through bad images
                foreach ($badImages as $image) {
                        $newtext = str_ireplace(
                                "{{rotate",
                                "{{rotate|nobot=true|reason='''Reason''': {$image->wrongfile}",
                                $this->getPageContent($image->title),
                                $count
                        );
                        if ($image->badtitle && !stristr($newtext, '{{rename')) {
                                $newtext = "{{rename}}\n$newtext";
                        }
                        $logmessage = "<span style=\"color:red;text-decoration:blink\">'''Error'''</span> can't rotate [[:{$image->title}]]:\n ''{$image->wrongfile}''\n";
                        if ($count > 0) {
                                try {
                                        $this->editPage($image->title, $newtext, "Bot: Can't rotate image");
                                        $this->logfile("Set template of {$image->title} to nobot");
                                } catch (UsageException $e) {
                                        $this->logfile("Unable to remove set template of {$image->title} to nobot: $e");
                                }
                        } else {
                                $this->logfile("Template:Rotate in {$image->title} NOT FOUND!");
                                $logmessage .= "<p>'''Warning(?):'''Template not found, file probably still in the category!!</p>\n";
                        }
                        $logmessages[] = $logmessage;
                }

                //Writing to logfile
                foreach ($goodImages as $image) {
                        $logmessage = "[[{$image->title}|thumb|110px]]\n";
                        $logmessage .= "*[[:{$image->title}]] ({$image->size})\n";
                        $logmessage .= "*:Last image-version uploaded by [[User:{$image->uploader}|]] at " . $this->formatTime($image->upltime) . "\n";
                        if ($image->tmplsetter !== null) {
                                $logmessage .= "*:{{[[Template:Rotate|]]}} added (or modified) by [[User:{$image->tmplsetter->name}|]] at " . $this->formatTime($image->since) . "\n";
                                $logmessage .= $image->tmplsetter->logmessage . "\n";
                        }
                        $logmessage .= "*:Rotated through {$image->degree}° at " . $this->formatTime($image->doneat) . " (=" . $this->formatTimeDiff($image->since->diff($image->doneat)) . " later)\n";
                        $logmessage .= "<br style=\"clear:both;\" clear=\"all\" />";
                        $logmessages[] = $logmessage;
                }

                if (count($logmessages)) {
                        $somanyrot = count($goodImages);
                        $this->logfile("write logfile. ($somanyrot pictures)");
                        $logfilew = $this->getPageContent(RotateBot::REPORT_PAGE);

                        $logfilew = $this->deleteold($logfilew, $somanyrot) . "\n----\n" . implode("\n----\n", $logmessages);
                        $this->editPage(RotateBot::REPORT_PAGE, $logfilew, "Bot: $somanyrot images rotated.");
                }
        }

        function deleteold(string $content, int $newab): string {
                //Maximale Logfileabschnitte hier einstellen
                $maxonlog = $this->config->logfilesize;
                $logheader = $this->config->logheader;

                $beginnat = 0;
                $abschnittarray = array("0");
                while($pos = strpos($content,"----",$beginnat))
                {
                        $abschnittarray[] = $pos;

                        $beginnat = $pos + 4;
                }
                $abschnittarray[] = strlen($content);//letzter ist am seitenende

                $alte = count($abschnittarray) - 2;

                $this->logfile("$alte sections found!");
                $totneu = $newab + $alte;
                if($totneu <= $maxonlog)
                {
                        //Neue Abschnitte nur anhängen KORREKT
                        $this->logfile("nothing to delete, just add");
                        return $content;

                        //COUNTER
                        $counter = file_get_contents("/data/project/rotbot/counter.txt");
                        $counter = $counter + $newab;
                        file_put_contents("/data/project/rotbot/counter.txt",$counter);
                }
                else
                {
                        //alte löschen
                        $zuviele = $totneu - $maxonlog ;


                        if($zuviele > $alte) //nicht mehr löschen als da sind.
                        {
                                $zuviele = $alte;
                        }

                        //zählen wie viele abschnitte neu auf der seite sind
                        $abschnitteneu = $totneu - $zuviele;


                        $this->logfile("delete $zuviele old sections.");


                        $bereich = $zuviele+1;
                        $this->logfile("delete section 1 to $bereich");

                        $intro = substr($content,0,$abschnittarray['1']);

                        //COUNTER
                        $counter = file_get_contents("/data/project/rotbot/counter.txt");
                        $counter = $counter + $newab;
                        file_put_contents("/data/project/rotbot/counter.txt",$counter);
                        $this->logfile("new counter: $counter.");

                        $intro = sprintf($logheader."\n",$abschnitteneu,$counter); //NEU in settings definiert: der header vom Log
                        $deleteabschn = substr($content,$abschnittarray['1'],$abschnittarray[$bereich]-$abschnittarray['1']);
                        $rest = substr($content,$abschnittarray[$bereich]);

                        return $intro.$rest;

                }

        }

        private function getPageContent(string $title): string {
                $page = $this->services->newPageGetter()->getFromTitle( $title );
                $revision = $page->getRevisions()->getLatest();
                if ( $revision ) {
                        return $revision->getContent()->getData();
                } else {
                        return '';
                }
        }

        private function editPage(string $title, string $content, string $summary): void {
                $params = [
                        'title' => $title,
                        'text' => $content,
                        'summary' => $summary,
                        'bot' => 1,
                        'token' => $this->api->getToken(),
                        'assert' => 'user',
                ];
                $this->api->postRequest( new \Mediawiki\Api\SimpleRequest( "edit", $params ) );
        }

        private function formatTime(DateTimeImmutable $time): string {
                return $time
                        ->setTimezone(new DateTimeZone('UTC')) // use `(UTC)` rather than `(Z)`
                        ->format('H:i, d F Y (T)');
        }

        private function formatTimeDiff(DateInterval $diff): string {
                $intervals = [
                        'Years'   => $diff->y,
                        'Months'  => $diff->m,
                        'Weeks'   => (int)($diff->d / 7),
                        'Days'    => $diff->d % 7,
                        'Hours'   => $diff->h,
                        'Minutes' => $diff->i,
                        'Seconds' => $diff->s,
                ];
                // Did any of the time intervals until now have non-zero values?
                // If not, don’t print anything; there’s no need to print 5sec as
                // “0 Years, 0 Months, 0 Weeks, 0 Days, 0 Hours, 0 Minutes and 5 Seconds”
                $started = false;
                foreach ($intervals as $label => $amount) {
                        $started = $started || ($amount > 0);
                        if ($started) {
                                if ($amount === 1) {
                                        // make the label singular
                                        $label = substr($label, 0, -1);
                                }
                                $stack[] = "'''$amount''' $label";
                        }
                }

                if (count($stack) > 1){
                        $tmp1 = array_pop($stack);
                        $tmp2 = array_pop($stack);
                        array_push($stack, "$tmp2 and $tmp1");
                };
                return implode(', ', $stack);
        }

        private function hexToStr(string $hex): string {
                $string='';
                for ($i=0; $i < strlen($hex)-1; $i+=2)
                {
                        $string .= chr(hexdec($hex[$i].$hex[$i+1]));
                }
                return $string;
        }

        /**
         * Check for other concurrently running rotatebot instances.
         * @throws RuntimeException If not alone in the world, and `dontDieOnLockProblems` isn’t set
         */
        private function getLockOrDie(): void {
                $myLockfile = RotateBot::LOCK_FILE;
                $dontDieOnLockProblems = $this->config->dontDieOnLockProblems == 'true';
                if ($dontDieOnLockProblems) {
                        $this->logfile("ATTENTION: dontDieOnLockProblems is true! Lockfile problems (like lockfile already present) will be ignored.");
                }

                if (!file_exists($myLockfile)) {
                        system("touch ".$myLockfile);
                        if (!file_exists($myLockfile)) {
                                if ($dontDieOnLockProblems) {
                                        $this->logfile("Could not create lock file. DontDieMode prevents death.");
                                } else {
                                        throw new RuntimeException('Could not create lock file');
                                }
                        }
                } else {
                        if ($dontDieOnLockProblems) {
                                $this->logfile("Could not get lock. Lock file already present. DontDieMode prevents death.");

                        } else {
                                system("touch ".$myLockfile);
                                $holdtm = $this->config->maxlockfiletime;
                                if (time()-filemtime($myLockfile) > $holdtm) {
                                        $this->logfile("Lockfile older than $holdtm,  Removing lock file...");
                                        system("rm ".$myLockfile);
                                        sleep(6);
                                        if (file_exists($myLockfile)) {
                                                $this->logfile("Warning: Lockfile was *not* removed.");
                                        } else {
                                                $this->logfile("Lockfile removed. Setting up for a restart (may take a while)...");
                                        }
                                        throw new RuntimeException;
                                }

                                $locktextz = "\n<br style='clear:both;' clear='all' />\n----\n\n    <span style='color:red;text-decoration:blink'>Error</span> Bot locked itself after a internal problem (~~~~~).";
                                $rawlocktextz = $this->getPageContent(RotateBot::REPORT_PAGE);
                                $reasonz = 'Bot: Could not get lock. Lock file already present. Exit.';
                                $resultz = $rawlocktextz . $locktextz;
                                $this->editPage(RotateBot::REPORT_PAGE, $resultz, $reasonz);
                                throw new RuntimeException("Could not get lock. Lock file already present.");
                        }
                }
        }

        /** Try to remove the lock file, log any errors. */
        private function removeLock() {
                $myLockfile = RotateBot::LOCK_FILE;

                if (file_exists($myLockfile)) {
                        system("rm ".$myLockfile);
                        if (file_exists($myLockfile)) {
                                $this->logfile("Warning: for some reason the lockfile could not be removed.");
                        }
                } else {
                        $this->logfile("Warning: for some reason the lockfile was missing although it was expected to exist.");
                }
        }

        private function logfile(string $text): void {
                echo "$text\n";
                $ip = date("Y-m-d H:i:s") ." - $text\n";
                file_put_contents(RotateBot::LOG_DIR . date("Y-m-d") ."-rotlog.txt", $ip, FILE_APPEND);
        }
}

RotateBot::run();
