<?php
	// Maximum age of a mirror in seconds
	$MAX_TIME_DIFF = 60*125; // 120 minutes + a little margin
	// Path for zone file
	$ZONE_PATH = "/usr/local/bind/var/";
	// IP of the master machine that keeps the main timestamp
	$MASTERIP = '65.19.161.25';

	// Commandline has receiver of mirror reports
	$MAIL_TO = $argv[1];
	if ($MAIL_TO == '') {
		echo "Missing or invalid mail receipient.\n";
		exit(1);
	}

	$log =& new Logger(1,$MAIL_TO);
	$db =& new Database($log);

        $log->status('Resetting flapping flags...');
	$oldflap = $db->Query("SELECT id FROM mirrors INNER JOIN mirror_state_change ON mirrors.id=mirror_state_change.mirror WHERE enabled=1 AND flapping=1 GROUP BY id HAVING (julianday('now')-max(julianday(dat)))>1");
	if (sqlite_num_rows($oldflap) > 0) {
		while ($row = sqlite_fetch_array($oldflap)) {
			$log->Log('Resetting flapping flag for ' . $row[0]);
			$db->NonFlappingMirror($row[0]);
		}
	}

	$log->Status('Fetching list of mirrors...');
	$mirrors = $db->Query("SELECT id,ip,insync,description FROM mirrors WHERE enabled=1 AND flapping=0", TRUE);
	
	$log->Status('Loading from wwwmaster...');
	$wwwmaster =& new MirrorLoader($log,$MASTERIP,'wwwmaster.postgresql.org');
	if (!$wwwmaster->FetchLastUpdate()) {
		$log->Log('Failed to load sync date from wwwmaster!');
		$log->Flush();
		exit(0); // Exitcode 0 will cause double error msgs
	}
	$log->Status('wwwmaster has sync date: ' . $wwwmaster->LastUpdatedStr());

	while ($row = sqlite_fetch_array($mirrors)) {
		$log->Status('Scanning mirror ' . $row[1]);
		
		$current =& new MirrorLoader($log,$row[1],'www.postgresql.org');
		if (!$current->FetchLastUpdate()) {
			$log->Log('Mirror ' . $row[1] . ' (' . $row[3] . ') returns no timestamp!');
			if ($row[2] == 1) {
				$db->DisableMirror($row[0],'No timestamp');
				$log->Log('Mirror ' . $row[1] . ' now disabled');
			}
			continue;
		}

		$diff = $wwwmaster->_lastupdate - $current->_lastupdate;
		if ($diff < 0) {
			$log->Log('Mirror ' . $row[1] . ' (' . $row[3] . ') claims to be newer than wwwmaster!');
			$log->Log('Mirror has ' . $current->LastUpdatedStr() . ', wwwmaster has ' . $wwwmaster->LastUpdatedStr());
			if ($row[2] == 1) {
				$db->DisableMirror($row[0],'Newer than master');
				$log->Log('Mirror ' . $row[1] . ' now disabled');
			}
			continue;
		}
		if ($diff > $MAX_TIME_DIFF) {
			$log->Log('Mirror ' . $row[1] . ' (' . $row[3] . ') has not been updated.');
			$log->Log('Mirror has ' . $current->LastUpdatedStr() . ', wwwmaster has ' . $wwwmaster->LastUpdatedStr());
			if ($row[2] == 1) {
				$db->DisableMirror($row[0],'Not updated');
				$log->Log('Mirror ' . $row[1] . ' now disabled');
			}
			continue;
		}
		if ($row[2] == 0) {
			$db->EnableMirror($row[0],'Recovered');
			$log->Log('Mirror ' . $row[1] . ' (' . $row[3] . ') recovered, now enabled.');
		}
	}


	// Look for flapping servers.
	// We define flapping has having more than four state-changes in the past five hours
	$log->Status('Looking for flapping servers');
	$flappers = $db->Query("SELECT id,ip,description FROM mirrors INNER JOIN mirror_state_change ON mirrors.id=mirror_state_change.mirror WHERE (julianday('now')*24-julianday(dat)*24)<5 AND mirrors.enabled=1 AND mirrors.flapping=0 GROUP BY id,ip,description HAVING count(*) > 3", TRUE);
	while ($row = sqlite_fetch_array($flappers)) {
		$log->Log('Mirror ' . $row[1] . ' (' . $row[2] . ') is flapping, disabling.');
		$db->FlappingMirror($row[0]);
	}

	// Make sure we don't spit out a completely empty zone file
	$log->Status('Looking for empty mirror types');
	$emptytypes = $db->Query('SELECT type FROM mirrors GROUP BY type having sum(CASE WHEN enabled=1 AND insync=1 AND flapping=0 THEN 1 ELSE 0 END)=0', TRUE);
	if (sqlite_num_rows($emptytypes) > 0) {
		// YIKES!
		$log->Log('WARNING! One or more mirror types would end up empty:');
		while ($row = sqlite_fetch_array($emptytypes)) {
			$log->Log('Type: ' . $row[0]);
		}
		$log->Log('ROLLING BACK ALL CHANGES AND REVERTING TO PREVIOUS VERSION OF ZONE!');
		$db->Rollback();
		$log->Flush();
		exit(0);
	}

	$db->Commit();
	$db->Begin();

	if (!$db->_changed) {
		// No changes made. But we still spit out one zone / day, so scripts
		// monitoring this script will know we are alive
		$lastdump = $db->Query("SELECT CASE WHEN julianday('now')-julianday(lastdump)>1 THEN 1 ELSE 0 END FROM zone_last_dump", TRUE);
		if (!($row = sqlite_fetch_array($lastdump))) {
			$log->Log('Could not determine last dump date - zero rows!');
			$log->Flush();
			exit(1);
		}
		if ($row[0] == 0) {
			$log->Status('Not dumping zone - no changes');
			$log->Flush(FALSE);
			exit(0);
		}
		$log->Log('Rebuilding zone because last update was more than 24 hours ago.');
	}


	$zg =& new ZoneGenerator($log,$db,$ZONE_PATH);
	$entries = $db->Query('SELECT type,ip FROM mirrors WHERE enabled=1 AND insync=1 AND flapping=0 ORDER BY type',TRUE);
	while ($row = sqlite_fetch_array($entries)) {
		$zg->AddServer($row[0],$row[1]);
	}

	$log->Log('Dumping new zonefile');
	$db->Query("UPDATE zone_last_dump SET lastdump=datetime('now')",TRUE);
	if ($zg->DumpFile()) {
		$db->Commit();
	}

	$log->Log('Completed.');
	$log->Flush();
	exit(0);


	//
	// Mirror loader
	//
	class MirrorLoader {
		var $_log;
		var $_ip='';
		var $_host;
		var $_lastupdate = -1;
		var $_port = 80;

		function MirrorLoader(&$log,$ip,$host) {
			$this->_log =& $log;
			$this->_host = $host;
			$this->_ip = $ip;
		}

		function FetchLastUpdate() {
			$fp = @fsockopen($this->_ip, $this->_port);
			if (!$fp) {
				$this->_log->Log('Failed to connect to port ' . $this->_port . ' on ip ' . $this->_ip);
				return FALSE;
			}


			$q = "GET /web_sync_timestamp HTTP/1.0\nHost: " . $this->_host . "\nUser-Agent: pgautomirror/0\n\n";
			if (!fwrite($fp, $q)) {
				$this->_log->Log('Failed to write network data to ' . $this->_ip);
				fclose($fp);
				return FALSE;
			}

			$buf = '';
			while ($tmp = fread($fp, 8192)) {
				$buf .= $tmp;
			}
			fclose($fp);

			if ($buf == '') {
				$this->_log->Log('No data returned from ' . $this->_ip);
				return FALSE;
			}

			if (!preg_match('@^HTTP/1.[0-9] 200@', $buf)) {
				$r = strpos($buf,"\n");
				if (!$r) $r = strlen($buf);
				$this->_log->Log($this->_ip . ' returned "' . substr($buf, 0,$r-1) . '" instead of 200');
				return FALSE;
			}

			// Find content length
			if (!preg_match('@Content-Length: ([0-9]+)@', $buf, $parts)) {
				$this->_log->Log($this->_ip . ' did not return a valid Content-Length');
				return FALSE;
			}

			$this->_lastupdate = strtotime(substr($buf, -$parts[1], 23));
			if ($this->_lastupdate == -1) {
				$this->_log->Log($this->_ip . ' did not return a valid timestamp');
				return FALSE;
			}

			return TRUE;
		}

		function LastUpdatedStr() {
			return date("Y-m-d H:i:s O",$this->_lastupdate);
		}
	}


	//
	// A very simple database wrapper
	//
	class Database {
		var $_db = null;
		var $_log = null;
		var $_changed = FALSE;

		function Database(&$log) {
			$this->_log =& $log;

			$this->_db = sqlite_open('mirror.db');
			if (!$this->_db) {
				$this->_log->Log('Failed to connect to database: ' . $php_errormsg . '!');
				$this->_log->Flush();
				exit(1);
			}

			$this->Begin();
		}

		function Begin() {
			if (!sqlite_query($this->_db, "BEGIN TRANSACTION")) {
				$this->_log->Log('Failed to start transaction: ' . sqlite_last_error($this->_db));
				$this->_log->Flush();
				exit(1);
			}
		}

		function Commit() {
			if (!sqlite_query($this->_db, "COMMIT TRANSACTION")) {
				$this->_log->Log('Failed to commit transaction: ' . sqlite_last_error($this->_db));
				return false;
			}
			return true;
		}

		function Rollback() {
			if (!sqlite_query($this->_db, "ROLLBACK TRANSACTION")) {
				$this->_log->Log('Failed to rollback transaction: ' . sqlite_last_error($this->_db));
				return false;
			}
			return true;
		}

		function Query($query, $exitonfail=FALSE) {
			$r = sqlite_query($this->_db, $query);
			if (!$r) {
				$this->_log->Log('Query to database backend failed: ' . sqlite_last_error($this->_db));
				$this->_log->Log('Query was: "' . $query . '"');
				if ($exitonfail) {
					$this->_log->Flush();
					exit(1);
				}
				return FALSE;
			}
			return $r;
		}

		function DisableMirror($mirrid,$reason) {
			$this->SetMirrorState($mirrid,0,$reason);
		}
		function EnableMirror($mirrid,$reason) {
			$this->SetMirrorState($mirrid,1,$reason);
		}
		function SetMirrorState($mirrid,$state,$reason) {
			$this->Query("INSERT INTO mirror_state_change(mirror,dat,newstate,comment) VALUES (" . $mirrid . ",datetime('now')," . $state . ",'" . $reason . "')",TRUE);
			$this->Query("UPDATE mirrors SET insync=" . $state . " WHERE id=" . $mirrid,TRUE);
			$this->_changed = TRUE;
		}
		function FlappingMirror($mirrid) {
			$this->Query("UPDATE mirrors SET flapping=1 WHERE id=" . $mirrid,TRUE);
			$this->_changed = TRUE;
		}
		function NonFlappingMirror($mirrid) {
			$this->Query("UPDATE mirrors SET flapping=0 WHERE id=" . $mirrid,TRUE);
			$this->_changed = TRUE;
		}
	}


	//
	// Handles generation of the actual zones
	//
	class ZoneGenerator {
		var $_log;
		var $_entries;
		var $_db;
		var $_path;

		function ZoneGenerator(&$log, &$db, $path) {
			$this->_log =& $log;
			$this->_db =& $db;
			$this->_entries = Array();
			$this->_path = $path;
		}

		function AddServer($type, $ip) {
			$a = $this->_entries[$type];
			if (empty($a)) {
				$a = Array();
				$this->_entries[$type] = $a;
			}
			$this->_entries[$type][] = $ip;
		}

		function DumpFile() {
			$serial = time();
			$nameservers = $this->_db->Query("SELECT host FROM nameservers", TRUE);
			$contents = '
$TTL 15M
@	IN SOA ns.hub.org. root.hub.org. (
		' . $serial . ' ; serial
		15M ; refresh
		5M ; retry
		1W  ; expire
		15M ; Minimum TTL
)
';
			while ($row = sqlite_fetch_array($nameservers)) {
				$contents .= '@ IN NS ' . $row[0] . ".\n";
			}
			$contents .= "\n\n";

			foreach ($this->_entries as $type=>$entries) {
				foreach ($entries as $entry) {
					$contents .= $type . ' IN A ' . $entry . "\n";
				}
			}
			
			$f = fopen($this->_path . '/db.mirrors.postgresql.org','w+');
			if (!$f) {
				$this->_log->Log('Failed to write to ' . $this->_path . '/mirror.zone');
				$this->_log->Log('Could not dump zone file');
				return false;
			}
			fwrite($f,$contents);
			fclose($f);
			return true;
		}
	}
		
	//
	// Handles logging, including sending it out as mail
	//
	class Logger {
		var $_l = '';
		var $_debug = 0;
		var $_mail;

		function Logger($debug,$mail) {
			$this->_debug = $debug;
			$this->_mail = $mail;
		}

		function Log($str) {
			$this->_l .= $str . "\n";
		}

		function Flush($domail=TRUE) {
			if ($this->_l != '') {
				echo " *** LOG START ***\n";
				echo $this->_l;
				echo " **** LOG END  ****\n";
				if ($domail) {
					mail($this->_mail, 'PostgreSQL AutoMirror Report', $this->_l, '', $this->_mail);
				}
			}
		}

		function Status($str) {
			if ($this->_debug) {
				echo $str . "\n";
			}
		}
	}
?>
