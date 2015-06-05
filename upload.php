<?php
function wikiupload($project,$filename_local,$filename_wiki,$license,$desc)
{
  GLOBAL $cookies;

  /*
  ****************Upload with api************************
  1.) check login, or login
  2.) get token with prop=info (in)
  3.) Upload

  */
  if(!$cookies["commonswikiUserName"] OR !$cookies["commonswikiUserID"])
  {
    $username = "SteinsplitterBot";
    $password = "PASSWD";
    logfile("Login to $project!\n");
    wikilogin($username,$password,$project,$useragent);
    print_r($cookies);
  }

  if($cookies) {
  logfile("Angemeldet in $project!\n");
  } else {
  suicide("Keine Cookies! Abbruch\n$header\n");
  }

  //Angemeldet, Cookies formatieren**************

  foreach ($cookies as $key=>$value)
  {
    $cookie .= trim($value).";";
  }
  $cookie = substr($cookie,0,-1);

  //get token
  $token = gettoken($project,$cookie);
  if($token)
  {
    print("got a upload token: ".$token."\n");
  } else {
    print("NO UPLOAD TOKEN");
    suicide();
  }

  //Upload
  wiki_upload_file ($filename_local,$filename_wiki,$license,$desc,$project,$cookie,$token);
  print("upload should be done"."\n");

}







  function gettoken($project,$cookie) {
    $url = "http://".$project."/w/api.php";
    $post = "action=query&rawcontinue=1&prop=info&intoken=edit&titles=Foo&format=php";

    $useragent = "Luxobot/2 (wmflabs; php) steinsplitter-wiki@live.com";
    print("Get $url ... Cookies: $cookie \n");
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $ry = curl_exec($ch);
    $data = unserialize($ry);
    //print_r($ry);
    return $data['query']["pages"]["-1"]["edittoken"];

  }

  function wiki_upload_file ($filename_local,$filename_wiki,$license,$desc,$wiki,$cookies,$token)
{
    $file1 = "";//Löschen wegen Speicherplatz
$file1 = file_get_contents("/data/project/sbot/Rotatebot/cache/".$filename_local) or suicide("Fehler - Datei nicht gefunden! ($filename_local)");


    $data_l = array(
    "action" => "upload",
    "file.file" => $file1,
    "filename" => $filename_wiki,
    "comment" => str_replace("\\'","'",$desc),
    "token" => $token,
    "ignorewarnings" => "1");
    $file1 = "";//Löschen wegen Speicherplatz
    wiki_PostToHostFD($wiki, "/w/api.php", $data_l, $wiki, $cookies);

    $data_l = array();//Das auch löschen wegen Speicherplatz

}
function wiki_PostToHostFD ($host, $path, $data_l, $wiki, $cookies) //this function was developed by [[:de:User:APPER]] (Christian Thiele) https://toolserver.org/~apper/code.php?file=wetter/upload.inc.php
{
logfile("verbinde zu $host ...");
    $useragent = "Luxobot (wmflabs; php) steinsplitter-wiki@live.com";
    $dc = 0;
    $bo="-----------------------------305242850528394";
    $filename=$data_l['filename'];
    $fp = fsockopen($host, 80, $errno, $errstr, 30);
    if (!$fp) { echo "$errstr ($errno)<br />\n"; exit; }

    fputs($fp, "POST $path HTTP/1.0\r\n");
    fputs($fp, "Host: $host\r\n");
    fputs($fp, "Accept: image/gif, image/x-xbitmap, image/jpeg, image/pjpeg, image/png, */*\r\n");
    fputs($fp, "Accept-Charset: iso-8859-1,*,utf-8\r\n");
    fputs($fp, "Cookie: ".$cookies."\r\n");
    fputs($fp, "User-Agent: ".$useragent."\r\n");
    fputs($fp, "Content-type: multipart/form-data; boundary=$bo\r\n");

    foreach($data_l as $key=>$val)
    {
        // Hack for attachment
        if ($key == "file.file")
        {
            $ds =sprintf("--%s\r\nContent-Disposition: attachment; name=\"file\"; filename=\"%s\"\r\nContent-type: image/png\r\nContent-Transfer-Encoding: binary\r\n\r\n%s\r\n", $bo, $filename, $val);
        }
        else
        {
            $ds =sprintf("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n", $bo, $key, $val);
        }
        $dc += strlen($ds);
    }
    $dc += strlen($bo)+3;
    fputs($fp, "Content-length: $dc \n");
    fputs($fp, "\n");

    foreach($data_l as $key=>$val)
    {
        if ($key == "file.file")
        {
            $ds =sprintf("--%s\r\nContent-Disposition: attachment; name=\"file\"; filename=\"%s\"\r\nContent-type: image/png\r\nContent-Transfer-Encoding: binary\r\n\r\n%s\r\n", $bo, $filename, $val);
            $data_1["file.file"] = "";//löschen
        }
        else
        {
            $ds =sprintf("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n", $bo, $key, $val);
        }
        fputs($fp, $ds );
    }
    $ds = "--".$bo."--\n";
    fputs($fp, $ds);

    $res = "";
    while(!feof($fp))
    {
        $res .= fread($fp, 1);
    }
    fclose($fp);
    file_put_contents("/data/project/sbot/Rotatebot/cache/log.txt",$res);
    return $res;
    $data_l = array();
}




?>
