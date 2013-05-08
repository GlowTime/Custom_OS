<?php

//Call this file only from server
if ( php_sapi_name() == 'cli' || empty($_SERVER["REMOTE_ADDR"]) ) { header('HTTP/1.1 404 Not Found'); die; }
    
	include("../config.php");
	include("../inc/common.php");
    include("../inc/class.database.php");
	include("../inc/db_connect.php");
	
function OS_UpdateScoresTable( $name = "" ) {
    $db = new db("mysql:host=".OSDB_SERVER.";dbname=".OSDB_DATABASE."", OSDB_USERNAME, OSDB_PASSWORD);
	
	$name = safeEscape( trim($name) );
	if ( !empty($name) ) {
	$sth = $db->prepare("SELECT * FROM scores WHERE (name) = ('".$name."')");
	$result = $sth->execute();
    if( $limit = $sth->rowCount() <= 0 ) {
    $sth = $db->prepare("INSERT INTO scores(category, name)VALUES('dota_elo','".$name."')");
	$result = $sth->execute();
    }
	
    //Get updated result
    $resultScore = $db->prepare("SELECT player,score FROM ".OSDB_STATS." WHERE (player) = ('".$name."')");
	$result = $resultScore->execute();
    $rScore = $resultScore->fetch(PDO::FETCH_ASSOC);
    //update "scores" table
    $UpdateScoreTable = $db->prepare("UPDATE `scores` SET `score` = '".$rScore["score"]."' 
	WHERE (name) = ('".$rScore["player"]."') ");
	$result = $UpdateScoreTable->execute();
	}
}
	
	$sth = $db->prepare( "SELECT COUNT(*) FROM ".OSDB_GAMES." 
	WHERE (map) LIKE ('%".$MapString."%') AND stats = 0 AND duration>='".$MinDuration."' ORDER BY `id`" );
	$result = $sth->execute();
    $r = $sth->fetch(PDO::FETCH_NUM);
    $Total = $r[0];
	
	if ( $Total>=1 ) {
	//GET ALL ADMINS
	$sth = $db->prepare("SELECT * FROM ".OSDB_ADMINS." WHERE id>=1");
	$result = $sth->execute();
	$admins = array();
	while ($row = $sth->fetch(PDO::FETCH_ASSOC)) { $admins[]= strtolower($row["name"]);  }
	//GET ALL USERS FROM SAFELIST
	$sth = $db->prepare("SELECT * FROM ".OSDB_SAFELIST." WHERE id>=1");
	$result = $sth->execute();
	$safelist = array();
	while ($row = $sth->fetch(PDO::FETCH_ASSOC)) { $safelist[]= strtolower($row["name"]);	}
	
	$sth = $db->prepare( "SELECT id FROM ".OSDB_GAMES." 
	WHERE (map) LIKE ('%".$MapString."%') AND stats = 0 AND duration>='".$MinDuration."' LIMIT ".$updateGamesCron." " );
	$result = $sth->execute();
	while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
	 $gid = $row["id"];
	 $sth2 = $db->prepare("SELECT winner, dp.gameid, gp.colour, newcolour, kills, deaths, assists, creepkills, creepdenies, neutralkills, towerkills, gold,  raxkills, courierkills, g.duration as duration, g.gamename,
	   gp.name as name, 
	   gp.ip as ip, gp.spoofed, gp.spoofedrealm, gp.reserved, gp.left, gp.leftreason,
	   b.name as banname 
	   FROM ".OSDB_DP." AS dp 
	   LEFT JOIN ".OSDB_DP." AS gp ON gp.gameid = dp.gameid and dp.colour = gp.colour 
	   LEFT JOIN ".OSDB_DG." AS dg ON dg.gameid = dp.gameid 
	   LEFT JOIN ".OSDB_GAMES." AS g ON g.id = dp.gameid 
	   LEFT JOIN ".OSDB_BANS." as b ON b.name=gp.name
	   WHERE dp.gameid='".$gid."'
	   GROUP by gp.name
	   ORDER BY newcolour");
	   $result = $sth2->execute();
	   if ($sth2->rowCount()<=0)  {
	   $update = $db->prepare("UPDATE ".OSDB_GAMES." SET stats = 1 WHERE id = '".$gid."'");
	   $result = $update->execute();
	   }
	   
	   while ($list = $sth2->fetch(PDO::FETCH_ASSOC)) {
		$kills=$list["kills"];
		$deaths=$list["deaths"];
		$assists=$list["assists"];
		$creepkills=$list["creepkills"];
		$creepdenies=$list["creepdenies"];
		$neutralkills=$list["neutralkills"];
		$towerkills=$list["towerkills"];
		$raxkills=$list["raxkills"];
		$courierkills=$list["courierkills"];
		$duration=$list["duration"];
		$name=(trim($list["name"]));
		$IPaddress = $list["ip"];
		$banname=$list["banname"];
		$win=$list["winner"];
		$newcolour=$list["newcolour"];
		
		$warn_expire = $list["expiredate"];
		$warn = $list["warn"];
		$gamename = $list["gamename"];
		
		if ( $warn>=1 ) $warn_qry = 'warn = '.$warn.', '; else $warn_qry = "";
				
		if ( in_array( strtolower($name), $admins ) )   $is_admin = 1; else $is_admin = 0;
		if ( in_array( strtolower($name), $safelist ) ) $is_safe = 1;  else $is_safe  = 0;
		
		if ( strtolower($banname)==strtolower($name) ) $BANNED = 1; else $BANNED = 0;
		
		if ($win==1 AND $newcolour<=5) {$winner = 1; $loser = 0;}
		if ($win==0) {$winner = 0; $loser = 0;}
		if ($win==2 AND $newcolour>5) {$winner = 1; $loser = 0;}
		if ($win==1 AND $newcolour>5) {$winner = 0; $loser = 1;}
		if ($win==2 AND $newcolour<=5) {$winner = 0; $loser = 1;}
		
		if ($winner == 1) $score = $ScoreStart + $ScoreWins;
		if ($winner == 0) $score = $ScoreStart - $ScoreLosses;
		if ($win==0) $score = $ScoreStart;
		
                if( !isset($list["leftreason"]) AND empty($list["leftreason"]) ) $list["leftreason"] = "No entry!";
		if ( ( $list["left"] <= ($list["duration"] - $MinDuration) ) AND $list["leftreason"] == "has left the game voluntarily" ) {
		   $leaver = 1; $score = "";
		} else $leaver = 0;
		
		// Score formula for each game (uncomment below to user score formula)
		// $scoreFormula = (((($kills-$deaths+$assists*0.5+$towerkills*0.5+$raxkills*0.2+($courierkills+$creepdenies)*0.1+$neutralkills*0.03+$creepkills*0.03) * .2)+($score)));
		
		// $score = $scoreFormula;
		
		if ($win==0) $draw = 1; else $draw = 0;
		if (!empty($name) AND $duration >= $MinDuration) {
		
		//LEAVER
		if ( ( $list["left"] <= ( $list["duration"] - $LeftTimePenalty ) ) AND $list["duration"] == "has left the game voluntarily" ) {
		$score = $ScoreStart - $ScoreDisc;
		}
                //DISC
                $splitreason = explode( " ", $list["leftreason"] );
                if( $splitreason[1] == "lost" ) $dc = 1; else $dc = 0;

/** CUSTOM AUTOBAN **/
                //preperation
                $games = 1;
                $dc_count = 0;
                $leave_count = 0;
                //check the player
                $GetUserInfo = $db->prepare("SELECT * FROM ".OSDB_STATS." WHERE player = '".$name."'");
                $result = $GetUserInfo->execute();
                while ($list = $GetUserInfo->fetch(PDO::FETCH_ASSOC)) {
                        $games=$list["games"];
                        $dc_count=$list["dc_count"];
                        $leave_count=$list["leaver"];
                        $alreadybanned=$list["banned"];
                }

                //calculations
                //Current Leave
                $games = $games+1;
                if( $leaver == 1 ) $leave_count = $leave_count+1;
                if( $dc == 1 ) $dc_count = $dc_count+1;
                $leaveratio = round( (($leave_count/$games)*100), 2 );
                $dcratio = round( (($dc_count/$games)*100), 2 );

                        //Check players with lower games than 5 for a high amount of leaving (over or 3 out of 5 games is to much)
                        if( $games <= 5 AND $leave_count >= 3  AND $is_admin == "0" AND $is_safe == "0" AND $alreadybanned == "0" AND $BANNED == 0 ) {
                                $reason = "AUTOBAN: Player left ".$leave_count." out of ".$games." games.";
                                $db->exec( "INSERT INTO ".OSDB_BANS." (botid,server,name,ip,gamename,date,admin,reason) VALUES ('1', '$realm', '$name', '$IPaddress', '$gamename', CURRENT_TIMESTAMP(), 'Grief-Ban', '$reason')" );
                        }
			//Check players with lower games than 10 for a high amount of leaving (over or 6 out of 10 games is to much)
			if( $games <= 10 AND $leave_count >= 6  AND $is_admin == "0" AND $is_safe == "0" AND $alreadybanned == "0" AND $BANNED == 0 ) {
                                $reason = "AUTOBAN: Player left ".$leave_count." out of ".$games." games.";
                                $db->exec( "INSERT INTO ".OSDB_BANS." (botid,server,name,ip,gamename,date,admin,reason) VALUES ('1', '$realm', '$name', '$IPaddress', '$gamename', CURRENT_TIMESTAMP(), 'Grief-Ban', '$reason')" );
                        }
                        //Check players with more than 10 games, only 10% is a accepted amount of leaving
                        if( $games > 15 AND ( $leaveratio > 10 ) AND $is_admin == "0" AND $is_safe == "0" AND $alreadybanned == "0" AND $BANNED == 0 ) {
                                $reason = "AUTOBAN: Player left has a leaving ratio of ".$leaveratio."% out of ".$games." games.";
                                $db->exec( "INSERT INTO ".OSDB_BANS." (botid,server,name,ip,gamename,date,admin,reason) VALUES ('1', '$realm', '$name', '$IPaddress', '$gamename', CURRENT_TIMESTAMP(), 'Grief-Ban', '$reason')" );
                        }
                        //Now check for a high amount of disconnects, they could be done on purpose!
                        if( $dcratio > 20 AND $games > 20 AND $is_admin == "0" AND $is_safe == "0" AND $alreadybanned == "0" AND $BANNED == 0 ) {
                                $reason = "AUTOBAN: Player has a disconnect ratio of ".$dcratio."% out of ".$games." games.";
                                $db->exec( "INSERT INTO ".OSDB_BANS." (botid,server,name,ip,gamename,date,admin,reason) VALUES ('1', '$realm', '$name', '$IPaddress', '$gamename', CURRENT_TIMESTAMP(), 'Grief-Ban', '$reason')" );
                        }
		
		$result2 = $db->prepare("SELECT player FROM ".OSDB_STATS." WHERE (player) = ?");
		$result2->bindValue(1, strtolower( trim($name) ), PDO::PARAM_STR);
		$result = $result2->execute();
        $stats = $result2->fetch(PDO::FETCH_ASSOC);
		$streak = $stats["streak"];
		$maxstreak = $stats["maxstreak"];
		$losingstreak = $stats["losingstreak"];
		$maxlosingstreak = $stats["maxlosingstreak"];
		
		//WIN STREAK
		//increase maxstreak until lose.
		if ($winner == 1) {
		$streak = $streak+1; 
		if ( $streak > $maxstreak ) $maxstreak = $maxstreak+1;
		} 
		if ($winner == 0) $streak = 0;
		//if player lose, reset streak.
		
		//LOSING STREAK
		//increase maxstreak until win.
		if ($winner == 0) {
		$losingstreak = $losingstreak+1; 
		if ( $losingstreak > $maxlosingstreak ) $maxlosingstreak = $maxlosingstreak+1;
		} 
		if ($winner == 1) $losingstreak = 0;
		//if player win, reset streak.
		
		if ( $deaths == 0 AND $draw!=1 ) $zerodeaths = 1; else $zerodeaths = 0;
		
		//Create a new player...
		  if ( $result2->rowCount() <=0) {
          $sql3 = "INSERT INTO ".OSDB_STATS."(player, player_lower, score, games, wins, losses, draw, kills, deaths, assists, creeps, denies, neutrals, towers, rax, banned, ip, warn_expire, warn, admin, safelist, realm, reserved, leaver, streak, maxstreak, losingstreak, maxlosingstreak, zerodeaths, dc_count) 
		  VALUES('$name', '".strtolower( trim($name))."', '$score','1',$winner,$loser,$draw,$kills,$deaths,$assists,$creepkills,$creepdenies,$neutralkills, $towerkills, $raxkills, $BANNED, '$IPaddress', '$warn_expire', '$warn', '$is_admin', '$is_safe', '$realm', '$reserved', '$leaver', '$streak', '$maxstreak', '$losingstreak', '$maxlosingstreak', '$zerodeaths', '$dc' )";
          } else {
		  //...or update player data
		  if ($winner == 1) $score = "score = score + $ScoreWins,";
		  if ($winner == 0) $score = "score = score - $ScoreLosses,";
		  if ($win==0) $score = "";
		  
		  //LEAVER
		  if ( ( $list["left"] <= ( $list["duration"] - $LeftTimePenalty ) ) AND $list["leftreason"] == "has left the game voluntarily" ) {
		  $score = "score = score - $ScoreDisc,"; }
		  
		  $sql3 = "UPDATE ".OSDB_STATS." SET 
		  $score
		  player = '$name',
		  player_lower = '".strtolower( trim($name))."',
		  games = games+1, 
		  wins = wins +$winner,
		  losses = losses+$loser,
		  draw = draw + $draw,
		  kills = kills + $kills,
		  deaths = deaths + $deaths,
		  assists = assists + $assists,
		  creeps = creeps + $creepkills,
		  denies = denies + $creepdenies,
		  neutrals = neutrals + $neutralkills,
		  towers = towers + $towerkills,
		  rax = rax + $raxkills,
          banned = $BANNED,
		  ip = '$IPaddress',
		  warn_expire = '$warn_expire',
		  $warn_qry
		  admin = '$is_admin',
		  safelist = '$is_safe',
		  realm = '$realm',
		  reserved = reserved + $reserved,
		  leaver = leaver + $leaver,
		  streak = '$streak',
		  maxstreak = '$maxstreak',
		  losingstreak = '$losingstreak',
		  maxlosingstreak = '$maxlosingstreak',
		  zerodeaths = zerodeaths+$zerodeaths,
                  dc_count = dc_count + $dc
		  WHERE (player) = ('$name');";
		   }
		  $result3 = $db->prepare($sql3);
		  $result = $result3->execute(); 
		  //OS_UpdateScoresTable( $name  );
		 }
		 //$return.="\nGame ($gid) updated!";
	     //Update "games" table so we can know what games have been updated
	     $update = $db->prepare("UPDATE ".OSDB_GAMES." SET stats = 1 WHERE id = '".$gid."'");
		 $result = $update->execute(); 
	   }
	   $return.="\nGame ($gid) updated!";
	}
	
  }
?>
