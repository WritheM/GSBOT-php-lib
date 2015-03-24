<?php
header('Access-Control-Allow-Origin: *');

require_once('config.php');
require_once('database.php');

try
{
    $db = new Database(
        $cfg['db']['host'],
        $cfg['db']['dbase'],
        $cfg['db']['user'],
        $cfg['db']['pass']
    );
}
catch (Exception $e)
{
    print_r($e->getMessage());
    die();
}

// validate the uid and key combo. this user must have access to the api to use it.
if (isset($_GET['key'])) {
    // try to validate with a get key
    if (validate_apiKey($db, $_GET['key'], $_GET['userid'])) {
        //echo "key accepted"; // silently!
    }
    else {
        die("invalid-key");
    }
} else if (isset($_POST['key'])) {
    // try to validate with a post key
    if (validate_apiKey($db, $_POST['key'], $_POST['userid'])) {
        //echo "key accepted"; // silently!
    }
    else {
        die("invalid-key");
    }
} else {
    // all else failed, must be a json key.
    $request_body = file_get_contents('php://input');
    $data = json_decode($request_body);

    if (validate_apiKey($db, $data->key, $data->uID))
    {
        //echo "key accepted"; // silently!
    }
    else {
        die("invalid-key");
    }
}

if (isset($_GET['getStats'])) {
    getStats($db);
} else if (isset($_GET['saveSong'])) {
    saveSong($db);
}

function getStats($db) {
    $query = 'SELECT h.songName,h.artistName,SUM(h.upVotes) as UpVotes, SUM(h.downVotes) as DownVotes, SUM(h.upVotes)-SUM(h.downVotes) as VoteSum, COUNT(h.broadcastSongID) as PlayCount, SUM(h.listens) as TotalListens, (SUM(h.upVotes)-SUM(h.downVotes)) / COUNT(h.broadcastSongID) as VotesPerPlay
    FROM gsdb_songHistory AS h
    WHERE userID = :userid
    AND songID = :songid
    AND timestamp BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) and NOW()
    GROUP BY h.SongID
    ORDER BY VotesPerPlay DESC
    LIMIT 0, 1';

    $params = new QueryParameters();
    $params->addParameter(':userid',$_GET['userid']);
    $params->addParameter(':songid',$_GET['songid']);

    $rows = $db->select($query, $params);

    if (count($rows) > 0 ) {
        $songStats = array(
            "songName" => $rows[0]['songName'],
            "artistName" => $rows[0]['artistName'],
            "totalUpVotes" => $rows[0]['UpVotes'],
            "totalDownVotes" => $rows[0]['DownVotes'],
            "totalVoteSum" => $rows[0]['VoteSum'],
            "playCount" => $rows[0]['PlayCount'],
            "totalListens" => $rows[0]['TotalListens'],
            "votesPerPlay" => $rows[0]['VotesPerPlay']
        );

        echo "Vote Stats for '{$songStats['songName']}' by '{$songStats['artistName']}' for the last 30 days are: ";
        echo "Played {$songStats['playCount']} time" . ($songStats['playCount']>1?"s":"") . " | Heard {$songStats['totalListens']} time" . ($songStats['totalListens']>1?"s":"") . " | TotalVoteSum (TVS): {$songStats['totalVoteSum']} | VotesPerPlay (VpP): {$songStats['votesPerPlay']}\n";
    }
    else {
        echo "It appears that the specified song has not been played before or we don't have any record of it playing in the last 30 days. Keep in mind that song records are only saved when they finish playing.";
    }

}

function saveSong($db) {
    require_once('config.php');
    require_once('database.php');

    $request_body = file_get_contents('php://input');
    $data = json_decode($request_body);

    $parms = new QueryParameters();
    $parms->addParameter(':broadcastSongID',$data->bcSID);
    $parms->addParameter(':userID',$data->uID);
    $parms->addParameter(':songID',$data->sID);
    $parms->addParameter(':songName',$data->sN);
    $parms->addParameter(':artistID',$data->arID);
    $parms->addParameter(':artistName',$data->arN);
    $parms->addParameter(':albumID',$data->alID);
    $parms->addParameter(':albumName',$data->alN);
    $parms->addParameter(':votes',count($data->h->up)-count($data->h->down));
    $parms->addParameter(':upVotes',count($data->h->up));
    $parms->addParameter(':downVotes',count($data->h->down));
    $parms->addParameter(':listens',$data->l);
    $parms->addParameter(':estimateDuration',$data->estD);

    $query = "INSERT INTO  gsdb_songHistory (broadcastSongID, userID, songID,
                songName, artistID, artistName, albumID, albumName, votes, upVotes, downVotes,
                listens, estimateDuration)
            VALUES (:broadcastSongID,  :userID, :songID,
                :songName, :artistID, :artistName, :albumID, :albumName, :votes, :upVotes, :downVotes,
                :listens, :estimateDuration)
            ON DUPLICATE KEY UPDATE votes=:votes, upVotes=:upVotes, downVotes=:downVotes;";

    try
    {
        $result = $db->execute($query, $parms);
    }
    catch (Exception $e)
    {
        print_r($e->getMessage());
    }

    //echo "data captured for '{$data->sN} - {$data->aN}'";
}

function validate_apiKey($db, $key, $uid) {
    $query = 'SELECT id, gs_uid, valCode
    FROM gsdb_users
    WHERE apiKey = :key
    AND gs_uid = :uid
    LIMIT 0, 1';

    $params = new QueryParameters();
    $params->addParameter(':key',$key);
    $params->addParameter(':uid',$uid);

    $rows = $db->select($query, $params);

    //TODO update this to end and die here, instead of redundant checks above.
    if (count($rows) > 0)
        return true;
    else
        return false;
}