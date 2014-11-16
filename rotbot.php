<?php
/* Copyright © by Luxo & Saibo, 2011

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

$homedir = "/data/project/sbot/Rotatebot/";
$myLockfile = $homedir."rotatebotlock";

ini_set('memory_limit', '100M'); //Speicher auf 100 MBytes hochsetzen
ini_set('user_agent', ' SteinsplitterBot by Luxo on the WMFLABS / PHP');


logfile("Starte Bot!");
$config = botsetup();
if($config['active'] == "false") {
        die("Bot disabled.");
} else if($config['active'] != "true") {
        die("Bot error.");
}

// Killer bot
if ($config['killAllRotatebots'] == "true") {
        logfile("ATTENTION: Going to killAllRotatebots (also myself)!");
        // "php rotbot/rotbot.php" needs to be the process name
        logfile("signal 15");
        system("pkill -15 -f 'php rotbot/rotbot.php'");
        sleep(10);
        logfile("signal 9");
        system("pkill -9 -f 'php rotbot/rotbot.php'");
        // we should be all dead now
}

$dontDieOnLockProblems = false;
if ($config['dontDieOnLockProblems'] == "true") {
        logfile("ATTENTION: dontDieOnLockProblems is true! Lockfile problems (like lockfile already present) will be ignored.");
        $dontDieOnLockProblems = true;
}
getLockOrDie($dontDieOnLockProblems); //check for other concurrently running rotatebot instances. Exit if not alone on the world
// continue ONLY if we are not dead ...
// after this line only suicide() should be done instead of die()!


logfile("Verbinde zur Datenbank!");
$myslink = mysql_connect('commonswiki.labsdb', 's51916', 'PASSWD') or suicide ("Can't connect to MySQL");
$database = "commonswiki_p";
mysql_select_db($database, $myslink)
                        or suicide ("Konnte $databas nicht öffnen: ".mysql_error());
//Datenbank verbunden

$wrongfiles = array();

//Kategorie auf Bilder überprüfen
$katname = "Images_requiring_rotation_by_bot";
logfile("Prüfe 'Category:$katname' auf Bilder");

$queryurl = "http://commons.wikimedia.org/w/api.php?action=query&list=categorymembers&cmtitle=Category:".$katname."&format=php&cmprop=ids|title|sortkey|timestamp&cmnamespace=6&cmsort=timestamp&cmtype=file&cmlimit=".$config['limit'];
$rawrequ = file_get_contents($queryurl) or suicide("Error api.php not accessible.");
$contentarray = unserialize($rawrequ);

if(!$contentarray['query']['categorymembers']['0'])
{
  suicide(logfile("Kategorie leer."));
}

//NS filter (was disabled on api 10.07.09)

foreach($contentarray['query']['categorymembers'] as $temp_img)
{
  if($temp_img['ns'] == 6)
  {
    $contentarray['pages'][] = $temp_img;
  }
}


/*
[0] => Array
   (
      [pageid] => 3830049
      [ns] => 6
      [title] => Image:WTM wikiWhat 074.jpg
      [sortkey] => 270
      [timestamp] => 2008-04-06T00:03:01Z
   )
[1] => Array
    (
    ...
*/

//noch restliche daten nachladen von api.php

foreach($contentarray['pages'] as $picture)
{
  $urlpageids .= "|".$picture['pageid'];
}
$urlpageids = substr($urlpageids,1); //vorderster | wieder wegnehmen (JA, unsauber ;-)

$queryurl = "http://commons.wikimedia.org/w/api.php?action=query&pageids=".$urlpageids."&prop=revisions|imageinfo&format=php&iiprop=timestamp|user|url|size|metadata";
$rawrequ = file_get_contents($queryurl) or suicide("Error api.php not accessible.");
$contentarray2 = unserialize($rawrequ);

$contentarray2['pages'] = $contentarray2['query']['pages'];
//vorhandenes array einbinden

foreach($contentarray['pages'] as $picture)
{
  $ctidd = $picture['pageid'];
  foreach($picture as $contXB => $contXI)
  {
  $contentarray2['pages'][$ctidd][$contXB] = $contXI;
  }
}

$contentarray = $contentarray2;



$catcontent = array();
$arraykey = 0;
foreach($contentarray['pages'] as $picture)
{
$wrongfile = false;
logfile("-------------");
logfile("check ".$picture['title']."...");

//dateiendung bestimmen - gültiges Dateiformat?
if(substr(strtolower($picture['title']),-4) == ".jpg" OR substr(strtolower($picture['title']),-5) == ".jpeg") { $catcontent[$arraykey]['filetype'] = "jpg"; }
else if(substr(strtolower($picture['title']),-4) == ".png") { $catcontent[$arraykey]['filetype'] = "png"; }
else if(substr(strtolower($picture['title']),-4) == ".gif") { $catcontent[$arraykey]['filetype'] = "gif"; }
else { $wrongfile = true;
       $wrongfiles[$picture["title"]] = "wrong filetype (".substr(strtolower($picture['title']),-3).")";
        }
//sortkey ab umbruchstelle beschneiden
$picture["sortkey"] = trim(stristr(hexToStr($picture["sortkey"]), "\n", true));

// Do not process big images ...
logfile("Size of this picture: ".$picture['imageinfo']['0']["size"]." bytes. Limit set to ".$config['fileSizeLimit']." bytes");
        if($picture['imageinfo']['0']["size"] > $config['fileSizeLimit']) {
                logfile("File bigger (".$picture['imageinfo']['0']["size"]." B) than limit (".$config['fileSizeLimit']." B)");
                $wrongfiles[$picture["title"]] = "File bigger (".$picture['imageinfo']['0']["size"]." B) than limit (".$config['fileSizeLimit']." B). Please wait until someone does a lossless(!) fix by hand.";
                $wrongfile = true;
        }


 //Korrekte Grade/Aktion prüfen
        if($picture["sortkey"] != 90 AND $picture["sortkey"] != 180 AND $picture["sortkey"] != 270) {
                // exceptions for jpegs... last chance. ;-)
                if(($catcontent[$arraykey]['filetype'] == "jpg" AND ($picture["sortkey"] == "RESETEXIF" OR  $picture["sortkey"] == "0" OR  $picture["sortkey"] == "00" OR $picture["sortkey"] == "000" ))) {
                        // okay, jpg AND (reset OR 0°) requested (cannot include in the in comparision since any string would match == 0) - EXIF based orientation should be applied apparently or be resetted
                } else {
                        logfile("wrong degree-parameter (".$picture["sortkey"]."°)");
                        $wrongfiles[$picture["title"]] = "wrong degree-parameter (".$picture["sortkey"]."°)";
                        $wrongfile = true;
                }
        }

        //Do not process images younger than x minutes
        $lagTime = $config['lag'] * 60;
        $settimestamp   = timestampto(trim($picture["timestamp"]),true);
        $inCatSince     = time() - $settimestamp;
  logfile("set at: '".$settimestamp."' (".$picture["timestamp"].") Lag: ".$lagTime." seconds, in the category since ".$inCatSince." seconds.");

  if($inCatSince < $lagTime)
        {
    $wrongfile = true;
    logfile("Picture younger than ".$lagTime." seconds. \n\nSORT OUT\n\n");
  }  else {
    logfile("Picture older than ".$lagTime." seconds, ok.");
  }


//User, der das Template gesetzt hat, identifizieren

$revitimestp = trim($picture["timestamp"]);

foreach($picture['revisions'] as $key => $revisions)
{
  if(trim($revisions['timestamp']) == $revitimestp)
  {
    $catcontent[$arraykey]['tmplsetter'] = $picture['revisions'][$key]['user'];
    logfile("Template by: ".$catcontent[$arraykey]['tmplsetter']);
  }
  else
  {
    logfile("set time($revitimestp) not identical with this rv, ".$revisions['timestamp'].".");
    //Rev's nachladen
    $ctxctx = file_get_contents("http://commons.wikimedia.org/w/api.php?action=query&prop=revisions&pageids=".$picture['pageid']."&rvlimit=20&rvprop=timestamp|user|comment&format=php") or suicide("api error");
    $totrevs = unserialize($ctxctx);
    logfile("ID: ".$picture['pageid']." ");

    if(is_array($totrevs))
    {
      foreach($totrevs['query']['pages'] as $cxxx)
      {
        foreach($cxxx['revisions'] as $cxxxx)
        {
          if($cxxxx['timestamp'] == $revitimestp)
          {
            $catcontent[$arraykey]['tmplsetter'] = $cxxxx['user'];
            logfile("Older rev, template by: ".$catcontent[$arraykey]['tmplsetter']);
          }
        }
      }
    }
    else
    {
      logfile("API: Error: not a array!");
      logfile($totrevs);
    }
  }
}


//Benutzer prüfen! #########################################
if($catcontent[$arraykey]['tmplsetter']) //autoconfirmed
{
  $wgAuthor = $catcontent[$arraykey]['tmplsetter'];
  logfile("check user ".$wgAuthor.".");

  //Datenbank abfragen nach status
  if(!$cachedbar["$wgAuthor"])
  {
    $mysresult = mysql_query( "SELECT user_id, user_name, user_registration, user_editcount FROM user WHERE user_name='".mysql_real_escape_string($wgAuthor)."'") or suicide("MySQL error");
    $a_row = mysql_fetch_row($mysresult);
    $cachedbar[$wgAuthor] = $a_row;
  }
  else
  {
    $a_row = $cachedbar["$wgAuthor"];
  }


  $setuserid = $a_row[0];
  $user_registration = $a_row[2];
  $user_editcount = $a_row[3];
  $chckx = $user_registration;//zum prüfen

  if(!$setuserid){ $setuserid = "-"; }
  if(!$user_editcount){ $user_editcount = 0; }
  if(!$user_registration){ $user_registration = 20070101000000; $vcx = true; } //alte accounts haben diesen zeitstempel noch nicht

  logfile("Edits: ".$user_editcount);
  logfile("Registred at: ".$user_registration);
  //Wikizeitstempel in unixzeit umrechnen

  $regiUnix = TsToUnixTime($user_registration);

  $actuUnix = time();
  $registeredtime = $actuUnix - $regiUnix;
  $registereddays = number_format($registeredtime / 86400,1,"."," ");//Stunden ausrechnen und runden/formatieren
  if($registeredtime < 345600 OR !$chckx AND $setuserid == "-") //345600 sec = 4 Tage
  {
    logfile($wgAuthor." is not autoconfirmed!");
    //Zu neu, nicht autoconfirmed
    if($config['rotatepermission'] == "1")
    {
      $wrongfile = true;
      $wrongfiles[$picture["title"]] = "The account of the user who set the template ([[User:".$catcontent[$arraykey]['tmplsetter']."|]]) is ''not autoconfirmed''.<br />'''Unlock:''' An autoconfirmed user should delete the parameters <code><nowiki>|</nowiki>nobot=true<nowiki>|</nowiki>reason=...</code> in this image. Thank you --~~~~";
    }
    $xx = "not ";
  } else {
    $xx = ""; //autoconfirmed
  }

  if($vcx == true)
  {
    $registereddays = "?";
  }

  $userforlog[$wgAuthor] = "***$wgAuthor, userid $setuserid, $user_editcount edits, registered since $registereddays days, is '''".$xx."autoconfirmed.'''";

  if($config['mincontribs'] > 0 AND $user_editcount < $config['mincontribs']) //zu wenige edits
  {
    if($config['rotatepermission'] == "1")
    {
      logfile($catcontent[$arraykey]['tmplsetter']." has not enough edits!");
      $wrongfile = true;
      $wrongfiles[$picture["title"]] = "The account of the user who set the template ([[User:".$wgAuthor."|]]) has under ".$config['mincontribs']." edits.<br />'''Unlock:''' An autoconfirmed user with more than ".$config['mincontribs']." edits should delete the parameters <code><nowiki>|</nowiki>nobot=true<nowiki>|</nowiki>reason=...</code> in this image. Thank you --~~~~";
    }
  }
}
//Benutzer geprüft #########################################




//per regex auf schlechte dateinamen prüfen
$regex = "File:(?:DVC|CIMG|IMGP?|PICT|DSC[FN]?|DUW|JD|MGP|scan|untitled|foto|imagen|img|image|picture|p|BILD)?[0-9_ \-\(\)\{\}\[\]]+\..*";
if(preg_match("/".$regex."/",$picture['title']) == 1)
{
  //Kann nicht hochgeladen werden, Blacklisted
  /*#######################
Im moment deaktiviert
######################*/
  //$wrongfile = true;
  //$wrongfiles[$picture["title"]] = "Image can't be rotated by Rotatebot because it has a senseless title. Please rename first.";
  $addrename[$picture["title"]] = true;
} else {
  $addrename[$picture["title"]] = false;
}


if($wrongfile == false) //Bild scheint OK zu sein
{
  logfile("picture and user check finished, sorted for download");

  $catcontent[$arraykey]['title']    = str_replace(" ", "_", $picture["title"]);
  $catcontent[$arraykey]['degree']   = $picture["sortkey"];
  $catcontent[$arraykey]['since']    = $revitimestp;
  $catcontent[$arraykey]['pageid']   = $picture['pageid'];
  $catcontent[$arraykey]['url']      = $picture['imageinfo']['0']['url'];
  $catcontent[$arraykey]['metadata']     = $picture['imageinfo']['0']['metadata'];
  $catcontent[$arraykey]['uploader'] = $picture['imageinfo']['0']['user'];
  $catcontent[$arraykey]['upltime']  = $picture['imageinfo']['0']['timestamp'];
  $catcontent[$arraykey]['size']     = $picture['imageinfo']['0']['width']."x".$picture['imageinfo']['0']['height'];
  $catcontent[$arraykey]['exifkey']  = 0;
  $catcontent[$arraykey]['exifkeyafter']  = 0;
  $catcontent[$arraykey]['exifwriteerr']  = "";
  $arraykey = $arraykey +1;
}
else
{
  logfile("picture and user check finished, no download. ");
//array löschen!
$papierkorb = array_splice($catcontent,$arraykey,1);
}



}

$picture = array();//leeren (Speicherplatz)

$countimages = count($catcontent);

/* ***********************************************************
   *******    ARRAY MIT BILDER & GRADE GELADEN ***************
   *******       WEITER GEHT'S MIT IMAGESAVE   ***************
   *********************************************************** */
logfile("------------");
logfile("Picture load finished - $countimages pictures ready for download, ".count($wrongfiles)." pics with errors.");


foreach($catcontent as $filename => $arraycontent)
{
logfile("save ".$arraycontent['title']);
$savepath = $homedir."cache/";

//$fp = fopen($arraycontent['url'], "rb");
//fpassthru($fp);
//fclose($fp);
//$file = ob_get_contents();
//ob_clean();
$file = file_get_contents($arraycontent['url']) or suicide("Kann ".$arraycontent['title']." nicht downloaden! (".$arraycontent['url'].")");

$fp = fopen($savepath.$filename.".".$arraycontent['filetype'], "wb+");
fwrite($fp, $file);
fclose($fp);
//sleep(1);
}
$file = "";//Datei löschen um Speicherplatz zu bekommen
logfile("Download finished!");



/*########## BILDER DREHEN, EXIF ANPASSEN ############# */
$catcontent2 = array();
foreach($catcontent as $filename => $arraycontent)
{
  $return=0; // reset
  if($arraycontent['filetype'] == "jpg") //Für JPEG lossless methode
  {
     //Exif auslesen
     // /usr/bin/exiftool -IFD0:Orientation -b 1.jpg     -a is to get dupe tags, too
     $exif = system("/usr/bin/exiftool -IFD0:Orientation -b -a ".$savepath.$filename.".".$arraycontent['filetype']."");
     settype($exif, "integer");
     logfile("EXIF ist auf $exif");
     $arraycontent['exifkey'] = $exif; //for editsummary

     if ($arraycontent['degree'] == "RESETEXIF") {   // if ignoring EXIF is wished ...
        switch($exif) {
                case 0:  // no Orientation tag existent
                case 1:
                        logfile("reset EXIF Orientation reset requested but it was already 0 or 1");
                        $return=1007; // unexpected EXIF was found
                break;
                default:
                        $exifR = 0; // ignore any existing EXIF
        }

     } else {
        if ($exif >= 10) {  //do we have duplicate Orientation tags?  They get reported by exiftool like "18".
                logfile("duplicate Orientation tags!");
                $return=1009; // Duplicate Orientation tags were found
        } else {
                //Use EXIF Orientation (=  roation applied by MediaWiki for displaying) and user input to find the correct rotation
                switch($exif) {
                        case 0:  // no Orientation tag existent
                        case 1:
                                if ($arraycontent['degree'] == 0) { // No rotation requested AND exif normal?
                                        logfile("exif was 0 or 1 and no rotation requested");
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
                                logfile("exif was not 0,1,3,6,8");
                                $return=1003; // unexpected EXIF was found
                }
        }
    }

    if ($return == 0) { // if no unexpected EXIF was found

        if ($arraycontent['degree'] == "RESETEXIF") {   // if ignoring EXIF is wished ...
                $realrotate = 0; // do not rotate
                $arraycontent['realdegree'] = 0;  //    for editsummary
        } else {
                $realrotate = $arraycontent['degree'] + $exifR;  // Saibo2 formula. user specified rotaation + already applied rotation by MW
                logfile("Bild muss um $realrotate Grad gedreht werden.");
                $realrotate = (360 + ($realrotate % 360)) % 360;    // convert to 0-259
                $arraycontent['realdegree'] = $realrotate;  //    for editsummary
        }
            switch($realrotate)
            {
                case 0:
                    //kopie erstellen
                    logfile("just exif correction, picture correct");
                    $cmd = "cp ".$savepath.$filename.".".$arraycontent['filetype']." ".$savepath.$filename."_2.".$arraycontent['filetype'];
                    logfile($cmd);
                    passthru($cmd);
                    break;
                case 90:
                case 180:
                case 270:
                    //rotieren ...
                    $cmd = "jpegtran -rotate ".$realrotate." -trim -copy all ".$savepath.$filename.".".$arraycontent['filetype']." > ".$savepath.$filename."_2.".$arraycontent['filetype'];
                    logfile($cmd);
                    passthru($cmd,$return);
                    logfile($arraycontent['title']." rotated by ".$realrotate."°.");
                    break;

                default:
                    logfile("Bullshit happend: realrotate was $realrotate.");
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
                        $cmd = "/usr/bin/exiftool -IFD0:Orientation= -n  ".$savepath.$filename."_2.".$arraycontent['filetype'];
                        logfile($cmd);
                        passthru($cmd,$retexifwrite);

                        if ($retexifwrite == 0) {  // if successful
                                logfile("No errors on EXIF-to-0");
                                $exifwriteerr = ""; // clear - no error since it worked in first attempt
                        } else {
                                // second attempt (ignoring minor errors)
                                $cmd = "/usr/bin/exiftool -IFD0:Orientation= -n -m  ".$savepath.$filename."_2.".$arraycontent['filetype'];
                                logfile($cmd);
                                passthru($cmd,$retexifwrite);

                                if ($retexifwrite == 0) {  // if successful
                                        logfile("No errors on EXIF-to-0 (second try)");
                                        $exifwriteerr = " - EXIF had minor errors. Some EXIF could be lost. - ";
                                } else {
                                        $doBruteForceClean = true;
                                }
                        }
                } else {
                        // first attempt
                        $cmd = "/usr/bin/exiftool -IFD0:Orientation=1 -n  ".$savepath.$filename."_2.".$arraycontent['filetype'];
                        logfile($cmd);
                        passthru($cmd,$retexifwrite);

                        if ($retexifwrite == 0) {  // if successful
                                logfile("no errors when setting EXIF to 1");
                                $exifwriteerr = ""; // clear - no error since it worked in first attempt
                        } else {
                                // second attempt (ignoring minor errors)
                                $cmd = "/usr/bin/exiftool -IFD0:Orientation=1 -n -m  ".$savepath.$filename."_2.".$arraycontent['filetype'];
                                logfile($cmd);
                                passthru($cmd,$retexifwrite);

                                if ($retexifwrite == 0) {  // if successful
                                        logfile("no errors when setting EXIF to 1 (second try)");
                                        $exifwriteerr = " - EXIF had minor errors. Some EXIF could be lost. - ";
                                } else {
                                        $doBruteForceClean = true;
                                }
                        }
                }


                if ($doBruteForceClean) {
                        // third attempt (ignoring nearly all errors)  - copy all readable tags but leave the Orientation tag away
                        $cmd = "/usr/bin/exiftool -all= -tagsfromfile @ -all:all --IFD0:Orientation ".$savepath.$filename."_2.".$arraycontent['filetype'];
                        logfile($cmd);
                        passthru($cmd,$retexifwrite);

                        if ($retexifwrite == 0) {  // if successful
                                logfile("no errors when setting EXIF to 0 (third try)");
                                $exifwriteerr = " - EXIF had major errors. Great parts of EXIF could be lost. - ";
                        } else {
                                // complete failure
                                $return = 1005;
                        }
                }


                $arraycontent['exifwriteerr'] = $exifwriteerr; //for editsummary
        }

        if ($return == 0) { // only if no error occured
                //Exif auslesen als Test
                // /usr/bin/exiftool -IFD0:Orientation -b 1.jpg    -a is to get dupe tags, too
                $exifafter = system("/usr/bin/exiftool -IFD0:Orientation -b -a ".$savepath.$filename."_2.".$arraycontent['filetype']."");
                settype($exifafter, "integer");
                logfile("read EXIF after finish: $exifafter");
                $arraycontent['exifkeyafter'] = $exifafter; //for editsummary

                if (!($exifafter == 0 || $exifafter == 1)) {  // if unsuccessful
                        $return = 1006;
                }
        }
    }
  }
  else //Für png's und gif's
  {
    passthru("convert ".$savepath.$filename.".".$arraycontent['filetype']." -rotate ".$arraycontent['degree']." ".$savepath.$filename."_2.".$arraycontent['filetype'],$returnP);
    logfile($arraycontent['title']." rotated by ".$arraycontent['degree']."°: ".$returnP);
  }
  // TODO:  ich bau mal ne Verwendung von $returnP in der Fehlerbehandlung nachfolgend ein..  der Fehlerwert beim PNG/GIF-Drehen wird gar nicht verwendet


//  sleep(5); //wait 5 sec. between rotating images

  if($return != 0)
  {
    //Bild aussortieren, da defekt

    $rx = "";
    switch($return) {
        case 1:
          $rx = " (DCT coefficient out of range)";
          $wrongfiles[$arraycontent["title"]] = "corrupt JPEG file.".$rx;
          logfile("sort out; corrupt JPEG file.".$rx);
          break;
        case 2:
          $rx = " (Premature end of JPEG file)";
          $wrongfiles[$arraycontent["title"]] = "corrupt JPEG file.".$rx;
          logfile("sort out; corrupt JPEG file.".$rx);
          break;
        case 1003:
            $rx = " ($exif)";
            $wrongfiles[$arraycontent["title"]] = "Unexpected exif orientation: ".$rx;
            logfile("unexpected exif orientation.".$rx);
            break;
        case 1004:
            $rx = " ($realrotate)";
            $wrongfiles[$arraycontent["title"]] = "Bullshit happend: realrotate was.".$rx;
            logfile("Bullshit happend: realrotate was.".$rx);
            break;
        case 1005:
            $rx = " (ec: $retexifwrite)";
            $wrongfiles[$arraycontent["title"]] = "EXIF had severe errors on write attempt.".$rx;
            logfile("EXIF had severe errors on write attempt.".$rx);
            break;
        case 1006:
            $rx = " ($exifafter)";
            $wrongfiles[$arraycontent["title"]] = "EXIF had not 0 or 1 after process.".$rx;
            logfile("EXIF had not 0 or 1 after process.".$rx);
            break;
        case 1007:
            $rx = " ($exif)";
            $wrongfiles[$arraycontent["title"]] = "reset EXIF Orientation reset requested but it was already 0 or 1.".$rx;
            logfile("reset EXIF Orientation reset requested but it was already 0 or 1.".$rx);
            break;
        case 1008:
            $rx = " ($exif)";
            $wrongfiles[$arraycontent["title"]] = "No rotation requested and no EXIF-based rotation? Sorry, there is nothing I can do!".$rx;
            logfile("No rotation requested and no EXIF-based rotation present. Nothing to do.".$rx);
            break;
        case 1009:
            $rx = " ($exif)";
            $wrongfiles[$arraycontent["title"]] = "Duplicate IFD0:Orientation tags were found. MW handles those in a strange way. Use rotate template's parameter 'resetexif' (instead of degree number) to reset the EXIF orientation information of this file.".$rx;
            logfile("Duplicate IFD0:Orientation tags were found.".$rx);
            break;
    }
  }
  else
  {
    //Korrekt gedreht, Weiter gehen
    $catcontent2[$filename] = $arraycontent;
  }
}
logfile("ALl images turned and exif corrected!\nStart upload...");

// ####################### Bilder gedreht! Weiter gehts mit Hochladen :) ############
include("upload.php");
include("login.php");


foreach($catcontent2 as $filename => $arraycontent)
        {

        //upload summary

        if ($arraycontent['degree'] == "RESETEXIF") {
                $filesum = sprintf($config['resetuploadsummary']." ".$config['exifuploadsum'],$arraycontent['exifkey'],$arraycontent['exifkeyafter'],$arraycontent['exifwriteerr'],$arraycontent['realdegree']);
        } else {

                if (!($arraycontent['exifkey'] == 0 || $arraycontent['exifkey'] == 1)) {  // if EXIF was not 0 or 1
                        $filesum = sprintf($config['uploadsummary']." ".$config['exifuploadsum'],$arraycontent['degree'],$arraycontent['exifkey'],$arraycontent['exifkeyafter'],$arraycontent['exifwriteerr'],$arraycontent['realdegree']);
                } else {
                        $filesum = sprintf($config['uploadsummary'],$arraycontent['degree']);
                }
        }

        //Hochladen
        Logfile("upload ".$arraycontent['title']." ... intern name: ".$filename."_2.".$arraycontent['filetype']);
        wikiupload("commons.wikimedia.org",$filename."_2.".$arraycontent['filetype'],substr($arraycontent['title'],5),"",$filesum);
        Logfile($arraycontent['title']." uploaded!");
        $catcontent2[$filename]['doneat'] = date("Y-m-d\TH:i:s\Z",time());//2007-10-01T10:13:15Z
        //Quelltext laden
        $quelltext = file_get_contents("http://commons.wikimedia.org/w/index.php?title=".urlencode(str_replace(" ", "_",$arraycontent['title']))."&action=raw");

        //Template erkennen
        $strabtemp = NULL;
        $strabtemp = stristr($quelltext, "{{Rotate");
        if(!$strabtemp) { $strabtemp = stristr($quelltext, "{{Template:Rotate"); }
        $upto = strpos($strabtemp,"}}");
        $template = substr($strabtemp,0,$upto+2);

        //löschen
        $forupload = str_ireplace($template."\n", "", $quelltext, $count); // delete template and a newline directly afterwards
        $forupload = str_ireplace($template, "", $forupload, $count2); // delete template

        //Speichern
        if($count + $count2 > 0)
        {
        logfile("remove template $template");

        //Editsummary generieren
        if($arraycontent['degree'] == "RESETEXIF") {
          $edsum = $config['reseteditsummary'];

        } else {
          $edsum = sprintf($config['editsummary'],$arraycontent['degree']);
  }

        $c = wikiedit("commons.wikimedia.org",$arraycontent['title'],$forupload,$edsum,"1");

        if($c == true)
        {
         $nodelete[$arraycontent['title']] = 0;
        }
        else
        {
         $nodelete[$arraycontent['title']] = 1;
        }

        }
        else
        {
        logfile("ERROR: TEMPLATE NICHT GEFUNDEN!");
        $nodelete[$arraycontent['title']] = 1;
        }
        logfile("\n-------NEXT--------- \n");
//      sleep(5);
}
logfile("Upload finished. Do error pictures now.");






//Cache leeren
foreach($catcontent2 as $filename => $arraycontent)
{
unlink("/data/project/sbot/Rotatebot/cache/".$filename.".".$arraycontent['filetype']);
unlink("/data/project/sbot/Rotatebot/cache/".$filename."_2.".$arraycontent['filetype']);
unlink("/data/project/sbot/Rotatebot/cache/".$filename."_2.".$arraycontent['filetype']."_original");
}
logfile("cache cleared. Write log now.");

//##################### LOG LOG LOG LOG LOG LOG LOG #########################

$logfilew = file_get_contents("http://commons.wikimedia.org/w/index.php?title=User:SteinsplitterBot/Rotatebot&action=raw");
$somanyrot = count($catcontent2);

$logfilew = deleteold($logfilew,$somanyrot,$config['logfilesize'],$config['logheader']);

// Fehlerbilder durchgehen
foreach($wrongfiles as $title => $reason)
{



  $quelltext = file_get_contents("http://commons.wikimedia.org/w/index.php?title=".urlencode(str_replace(" ", "_",$title))."&action=raw");

  $forupload = str_ireplace("{{Rotate", "{{Rotate|nobot=true|reason='''Reason''': $reason", $quelltext, $count);

  if($addrename[$title] == true && !stristr($forupload,"{{rename"))
  {
    $renametemp = "{{Rename}}\n";
  }
  else
  {
    $renametemp = "";
  }

  $forupload = $renametemp.$forupload;
  if($count > 0)
  {

    wikiedit("commons.wikimedia.org",str_replace(" ", "_",$title),$forupload,"Bot: Can't rotate image","1");
    $logfilew .= "\n----\n
    <span style=\"color:red;text-decoration:blink\">'''Error'''</span> can't rotate [[:$title]]:\n ''$reason''\n";

    logfile("set template of $title to nobot");
  }
  else
  {
    logfile("Template:Rotate in $title NOT FOUND!");
    $logfilew .= "\n----\n
    <span style=\"color:red;text-decoration:blink\">'''Error'''</span> can't rotate [[:$title]]:\n ''$reason''\n";
    $logfilew .= "<p>'''Warning(?):'''Template not found, file probably still in the category!!</p>\n";
  }

}


//Normaler Log schreiben

foreach($catcontent2 as $arraycontent)
{
  $logfilew .= "\n----\n";
  $logfilew .= "[[".$arraycontent['title']."|thumb]]\n";
  $logfilew .= "*[[:".$arraycontent['title']."]] (".$arraycontent['size'].")\n";

  if($nodelete[$arraycontent['title']] == 1)
  {

    $logfilew .= "<p>Template not found, file probably still in the category!</p>\n";

  }


  if($arraycontent['metadata']['Make'] and $arraycontent['metadata']['DateTimeDigitized'])
  {
    $logfilew .= "**Image taken with a [[:en:".trim($arraycontent['metadata']['Make'])."|".ucwords(strtolower(trim($arraycontent['metadata']['Make'])))."]] ".ucwords(strtolower(trim($arraycontent['metadata']['Model'])))." at ".trim($arraycontent['metadata']['DateTimeDigitized']).".\n";
  }

  $logfilew .= "**Last image-version uploaded by [[User:".$arraycontent['uploader']."|]] at ".timestampto($arraycontent['upltime'])." (UTC)\n";
  if($arraycontent['tmplsetter'])
  {
    $logfilew .= "**{{[[Template:Rotate|]]}} added (or modified) by [[User:".$arraycontent['tmplsetter']."|]] at ".timestampto($arraycontent['since'])." (UTC)\n";
    $logfilew .= $userforlog[$arraycontent['tmplsetter']]."\n";
  }
  $logfilew .= "**Rotated by ~~~ through ".$arraycontent['degree']."° at ".timestampto($arraycontent['doneat'])." (UTC) (=".difftime($arraycontent['since'],$arraycontent['doneat'])." later)\n";
  $logfilew .= "<br style=\"clear:both;\" clear=\"all\" />";
}

if($somanyrot > 0 OR count($wrongfiles) > 0)
{
  logfile("write logfile. ($somanyrot pictures)");

  if(count($wrongfiles) > 0)
  {
    $msgerr = ", ".count($wrongfiles)." errors";
  }

  wikiedit("commons.wikimedia.org","User:SteinsplitterBot/Rotatebot",$logfilew,"Bot: $somanyrot images rotated".$msgerr.".","1");
}

mysql_close($myslink); // TODO should/can this be moved to function suicide? - Probably not due to line 33.
suicide ("Bot finished.");
// END script





// functions start:




function logfile($text)
{
  echo $text."\n";
}

function timestampto($intime,$unix=false)
{
//UNIX-zeit erstellen
//2007-11-20T19:11:17Z
$year = substr($intime,0,4);
$month = substr($intime,5,2);
$day = substr($intime,8,2);
$hour = substr($intime,11,2);
$min  = substr($intime,14,2);
$sec  = substr($intime,17,2);

$unixtime = mktime($hour,$min,$sec,$month,$day,$year);
if($unix == true)
{
return $unixtime;
}
else
{
return date("H:i, d F Y",$unixtime);
}

}

function difftime($settime, $rottime)
{
$unixset = timestampto($settime,true);
$unixrot = timestampto($rottime,true);

$diff = $unixrot - $unixset; //differenz in sekunden



return tellSeconds($diff);

}

function tellSeconds($NumberOfSeconds) // function Copyright (C) simplecontent.net
{

    $time_map = array(

     'Years'     => 31536000,    # 365 Tage
     'Months'    => 2592000,    # 30 Tage
     'Weeks'    => 604800,    # 7 Tage
     'Days'     => 86400,
     'Hours'     => 3600,
     'Minutes'     => 60,
     'Seconds'     => 1,
    );

    $SecondsTotal     = $NumberOfSeconds;

    $SecondsLeft     = $SecondsTotal;

    $stack = array();

    foreach ($time_map as $k => $v) {

        if ($SecondsLeft < $v || $SecondsLeft == 0) {
                continue;
        } else {
                $amount = floor($SecondsLeft/ $v);
                    $SecondsLeft = $SecondsLeft % $v;

            $label = ($amount>1)
                ? $k
                : substr($k, 0, -1);

                    $stack[] = sprintf("'''%s''' %s", $amount, $label);
        }
    }
    $cnt = count($stack);

    if ($cnt > 1){
        $tmp1 = array_pop($stack);
        $tmp2 = array_pop($stack);
        array_push ($stack, $tmp2 . ' and '.$tmp1);
    };
    $result = join (', ', $stack);
    return $result;

}

function deleteold($content,$newab,$maxonlog,$logheader)
{
//$maxonlog = 20; //Maximale Logfileabschnitte hier einstellen

$beginnat = 0;
$abschnittarray = array("0");
while($pos = strpos($content,"----",$beginnat))
{
$abschnittarray[] = $pos;

$beginnat = $pos + 4;
}
$abschnittarray[] = strlen($content);//letzter ist am seitenende

$alte = count($abschnittarray) - 2;

logfile("$alte sections found!");
$totneu = $newab + $alte;
if($totneu <= $maxonlog)
{
//Neue Abschnitte nur anhängen KORREKT
logfile("nothing to delete, just add");
return $content;

//COUNTER
$counter = file_get_contents("/data/project/sbot/Rotatebot/counter.txt");
$counter = $counter + $newab;
file_put_contents("/data/project/sbot/Rotatebot/counter.txt",$counter);
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


logfile("delete $zuviele old sections.");


$bereich = $zuviele+1;
logfile("delete section 1 to $bereich");

$intro = substr($content,0,$abschnittarray['1']);

//COUNTER
$counter = file_get_contents("/data/project/sbot/Rotatebot/counter.txt");
$counter = $counter + $newab;
file_put_contents("/data/project/sbot/Rotatebot/counter.txt",$counter);
logfile("new counter: $counter.");

$intro = sprintf($logheader."\n",$abschnitteneu,$counter); //NEU in settings definiert: der header vom Log
$deleteabschn = substr($content,$abschnittarray['1'],$abschnittarray[$bereich]-$abschnittarray['1']);
$rest = substr($content,$abschnittarray[$bereich]);

return $intro.$rest;

}

}

function botsetup()
{
  $setupraw = file("http://commons.wikimedia.org/w/index.php?title=User:SteinsplitterBot/rconfig.js&action=raw");

  foreach($setupraw as $line)
  {
    $line = trim($line);

    if(substr($line,0,2) != "//" AND $line != "")
    {

      $gleich = strpos($line, "=");

      $namecon = trim(substr($line,3,$gleich-3));

      $stripu = strpos($line,";");

      $content = trim(substr($line,$gleich+1,$stripu - ($gleich + 1)));

      //falls vorhanden "" entfernen
      if(substr($content, 0, 1) == '"')
      {
        $content = substr($content, 1);
      }

      if(substr($content, -1) == '"')
      {
        $content = substr($content, 0, -1);
      }

      $content = trim($content);

      $array[$namecon] = $content;

    }
  }
  return $array;
}

function TsToUnixTime($tstime)
{
  $regYear = substr($tstime,0,4);
  $regMonth = substr($tstime,4,2);
  $regDay = substr($tstime,6,2);
  $regHour = substr($tstime,8,2);
  $regMin = substr($tstime,10,2);
  $regSec = substr($tstime,12,2);

return mktime($regHour, $regMin, $regSec, $regMonth, $regDay, $regYear);
}

function hexToStr($hex)
{
    $string='';
    for ($i=0; $i < strlen($hex)-1; $i+=2)
    {
        $string .= chr(hexdec($hex[$i].$hex[$i+1]));
    }
    return $string;
}


//checks for other concurrently running rotatebot instances. Exits if not alone on the world
//Params:
//        global $myLockfile - String containing a filename to be touched
//        $dontDieOnLockProblems - Boolean for overriding death
function getLockOrDie($dontDieOnLockProblems) {
        global $myLockfile;

        if (!file_exists($myLockfile)) {
                system("touch ".$myLockfile);
                        if (!file_exists($myLockfile)) {
                                if ($dontDieOnLockProblems) {
                                        logfile("Could not create lock file. DontDieMode prevents death.");
                                } else {
                                        die("Could not create lock file. Exit.");
                                }
                        }
        } else {
                if ($dontDieOnLockProblems) {
                        logfile("Could not get lock. Lock file already present. DontDieMode prevents death.");
                } else {
                        die("Could not get lock. Lock file already present. Exit.");
                }
        }
}

// tries to remove the lockfile. Logs any errors.
//Params: global $myLockfile  - String containing a filename to be removed
function removeLock() {
        global $myLockfile;

        if (file_exists($myLockfile)) {
                system("rm ".$myLockfile);
                if (file_exists($myLockfile)) {
                        logfile("Warning: for some reason the lockfile could not be removed.");
                }
        } else {
                logfile("Warning: for some reason the lockfile was missing although it was expected to exist.");
        }
}

// gracefully commits sucide. Removes the lock file before...
function suicide ($exitcodeOrString) {
        removeLock();
        die($exitcodeOrString);
}

?>
