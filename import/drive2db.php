<?php

/**
 * Import files inside google drive
 */
if (php_sapi_name() !== 'cli') {
    die("Run only from CLI");
}
echo "Import files inside google drive\n";
include "../vendor/autoload.php";
require '../config.php';
require 'function.php';
$dbpath = '../' . $dbpath;
require '../function.php';
$cleanTabel = false;
$cleanCache = false;

if (in_array("table", $argv)) {
    $cleanTabel = true;
} else {
    echo "Clean table before import? (y/n) : ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim($line) == 'y') {
        $cleanTabel = true;
        echo "Clean table $line";
    }
}

if (in_array("cache", $argv)) {
    $cleanCache = true;
} else {
    echo "Clean cache after import? (y/n) : ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim($line) == 'y') {
        $cleanCache = true;
        echo "Clean cache $line";
    }
}

$db = getDatabase();
if ($cleanTabel) {
    $db->exec("DELETE FROM t_games_url");
    echo "TABLE count " . $db->count("t_games_url") . " lines\n";
}
updateToken();
$drives = explode("\n", str_replace("\r", "", file_get_contents("./folder.txt")));
$n = 0;
foreach ($drives as $drive) {
    echo "$drive\n";
    $drive = explode(" ", $drive);
    if (count($drive) >= 2) {
        $idfolder = trim($drive[0]);
        $folder = trim($drive[1]);

        ulang:
        $jsoncredential = json_decode(file_get_contents("token/creds.txt"), true);
        $sisa = time() - filemtime("token/creds.txt");
        if ($sisa > $jsoncredential['expires_in'] - 300) {
            updateToken();
        }
        $pathPageToken = "./temp/$idfolder.2.token";
        $pageToken = (file_exists($pathPageToken)) ? file_get_contents($pathPageToken) : null;

        echo $pageToken;

        $list =  listFiles($idfolder, $pageToken);

        foreach ($list['files'] as $item) {
            if (!$db->has('t_games_url', ['url' => $item['id']])) {
                if ($item['fileExtension'] == 'nsz' || $item['fileExtension'] == 'xci') {
                    if ($item['parents'][0] == $idfolder && !empty($item['name'])) {
                        $gameid = getGameID($item['name']);
                        $games = $db->get("t_games", ["name",'size'], ['titleid' => $gameid]);
                        $gameName = $games['name'];
                        if($item['size']==0){
                            $item['size'] = $games['size']*1;
                        }
                        if (empty($gameName)) $gameName = str_replace([".xci", ".nsp", ".nsz"], "", $item['name']);
                        if(empty($item['driveId'])){
                            $db->insert('t_games_url', [
                                'url' => $item['id'],
                                'filename' => $item['name'],
                                'title' => $gameName,
                                'titleid' => $gameid,
                                'fileSize' => $item['size'],
                                'md5Checksum' => $item['md5Checksum'],
                                'root' => $idfolder,
                                'owner' => trim($item['owners'][0]['emailAddress']),
                                'folder' => $folder,
                                'shared' => ($item['shared']) ? "1" : "0",
                            ]);
                        }else{
                            $db->insert('t_games_url', [
                                'url' => $item['id'],
                                'filename' => $item['name'],
                                'title' => $gameName,
                                'titleid' => $gameid,
                                'fileSize' => $item['size'],
                                'md5Checksum' => $item['md5Checksum'],
                                'root' => $idfolder,
                                'owner' => $item['driveId'],
                                'folder' => $folder,
                                'shared' => (in_array("anyoneWithLink",$item['permissionIds'])) ? "1" : "0",
                            ]);
                        }
                        if ($db->has('t_games_url', ['url' => $item['id']])) {
                            $n++;
                            echo "$n INSERTED " . $item['id'] . " - " . $item['name'] . "\n";
                        } else {
                            echo json_encode($db->error()) . "\n" . $item['name'] . "\n";
                        }
                    } else {
                        echo "Parents different " . $item['parents'][0] . "\n";
                    }
                } else {
                    echo "NOT XCI/NSZ\n";
                    print_r($item);
                }
            } else {
                echo "EXISTS " . $item['id'] . " - " . $item['name'] . "\n";
            }
        }


        if (isset($list['nextPageToken']) && !empty($list['nextPageToken'])) {
            $pageToken = $list['nextPageToken'];
            file_put_contents("$pathPageToken", $pageToken);
            if (!empty($pageToken))
                goto ulang;
            else die("EMPTY $idfolder");
        }

        if(file_exists($pathPageToken))unlink($pathPageToken);
        echo "$idfolder FINISH\n\n";
    }
}
echo "$n games inserted\n";

if ($cleanCache) {
    $files = scandir("../cache/");
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) == 'json') {
            unlink("../cache/$file");
            echo "DELETE CACHE ../cache/$file\n";
        }
    }
}else{
    echo "\n\nDONT FORGET TO RUN clean.php from browser\n\n";
}
if ($dbtype == "sqlite") $db->exec("VACUUM");
