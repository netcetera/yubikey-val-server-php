<?php

require_once 'ykval-config.php';
require_once 'ykval-common.php';
require_once 'ykval-db.php';
require_once 'ykval-log.php';

class SyncLib
{
  public $syncServers = null;
  public $dbConn = null;

  function __construct($logname='ykval-synclib')
  {
    $this->myLog = new Log($logname);
    global $baseParams;
    $this->syncServers = $baseParams['__YKVAL_SYNC_POOL__'];

    $this->db=new Db($baseParams['__YKVAL_DB_DSN__'],
		     $baseParams['__YKVAL_DB_USER__'],
		     $baseParams['__YKVAL_DB_PW__'],
		     $baseParams['__YKVAL_DB_OPTIONS__'], 
		     $logname . ':db');
    $this->isConnected=$this->db->connect();
    $this->server_nonce=md5(uniqid(rand())); 

  }

  function addField($name, $value)
  {
    $this->myLog->addField($name, $value);
    $this->db->addField($name, $value);
  }

  function isConnected() 
  {
    return $this->isConnected;
  }

  function DbTimeToUnix($db_time)
  {
    $unix=strptime($db_time, '%F %H:%M:%S');
    return mktime($unix[tm_hour], $unix[tm_min], $unix[tm_sec], $unix[tm_mon]+1, $unix[tm_mday], $unix[tm_year]+1900);
  }
  
  function UnixToDbTime($unix)
  {
    return date('Y-m-d H:i:s', $unix);
  }  

  function getServer($index)
  {
    if (isset($this->syncServers[$index])) return $this->syncServers[$index];
    else return "";
  }

  function getClientData($client)
  {
    $res=$this->db->customQuery("SELECT id, secret FROM clients WHERE active AND id='" . $client . "'");
    $r = $res->fetch(PDO::FETCH_ASSOC);
    $res->closeCursor();
    if ($r) return $r;
    else return false;
  }

  public function getQueueLength()
  {
    return count($this->db->findBy('queue', null, null, null));
  }

  public function createInfoString($otpParams, $localParams)
  {
    return 'yk_publicname=' . $otpParams['yk_publicname'] .
      '&yk_counter=' . $otpParams['yk_counter'] .
      '&yk_use=' . $otpParams['yk_use'] .
      '&yk_high=' . $otpParams['yk_high'] .
      '&yk_low=' . $otpParams['yk_low'] .
      '&nonce=' . $otpParams['nonce'] .
      ',local_counter=' . $localParams['yk_counter'] .
      '&local_use=' . $localParams['yk_use'];
  }

  public function otpParamsFromInfoString($info) {
    $out=explode(",", $info);
    parse_str($out[0], $params);
    return $params;
  }

  public function otpPartFromInfoString($info) {
    $out=explode(",", $info);
    return $out[0];
  }

  public function localParamsFromInfoString($info) 
  {
    $out=explode(",", $info);
    parse_str($out[1], $params);
    return array('yk_counter'=>$params['local_counter'], 
		 'yk_use'=>$params['local_use']);
  }

  public function queue($otpParams, $localParams)
  {

    $info=$this->createInfoString($otpParams, $localParams);
    $this->otpParams = $otpParams;
    $this->localParams = $localParams;
    
    $queued=time();
    $res=True;
    foreach ($this->syncServers as $server) {
      
      if(! $this->db->save('queue', array('queued'=>$queued,
					  'modified'=>$otpParams['modified'], 
					  'otp'=>$otpParams['otp'], 
					  'server'=>$server,
					  'server_nonce'=>$this->server_nonce,
					  'info'=>$info))) $res=False;
    }
    return $res;
  }

  public function getNumberOfServers()
  {
    if (is_array($this->syncServers)) return count($this->syncServers);
    else return 0;
  }

  public function log($priority, $msg, $params=NULL)
  {
    $logMsg=$msg;
    if ($params) $logMsg .= ' modified=' . $params['modified'] .
		   ' nonce=' . $params['nonce'] .
		   ' yk_publicname=' . $params['yk_publicname'] .
		   ' yk_counter=' . $params['yk_counter'] .   
		   ' yk_use=' . $params['yk_use'] .   
		   ' yk_high=' . $params['yk_high'] .   
		   ' yk_low=' . $params['yk_low'];
    if ($this->myLog) $this->myLog->log($priority, $logMsg);
    else error_log("Warning: myLog uninitialized in ykval-synclib.php. Message is " . $logMsg);
  }

  function getLocalParams($yk_publicname)
  {
    $this->log(LOG_INFO, "searching for yk_publicname " . $yk_publicname . " in local db");
    $res = $this->db->findBy('yubikeys', 'yk_publicname', $yk_publicname,1);

    if (!$res) {
      $this->log(LOG_NOTICE, 'Discovered new identity ' . $yk_publicname);
      $this->db->save('yubikeys', array('active'=>1, 
					'created'=>time(),
					'modified'=>-1,
					'yk_publicname'=>$yk_publicname,
					'yk_counter'=>-1,
					'yk_use'=>-1,
					'yk_low'=>-1,
					'yk_high'=>-1,
					'nonce'=> '0000000000000000',
					'notes'=>''));
      $res=$this->db->findBy('yubikeys', 'yk_publicname', $yk_publicname,1);
    }
    if ($res) {
      $localParams=array('modified'=>$res['modified'],
			 'nonce'=>$res['nonce'],
			 'active'=>$res['active'],
			 'yk_publicname'=>$yk_publicname,
			 'yk_counter'=>$res['yk_counter'], 
			 'yk_use'=>$res['yk_use'],
			 'yk_high'=>$res['yk_high'],
			 'yk_low'=>$res['yk_low']);
      
      $this->log(LOG_INFO, "yubikey found in db ", $localParams);
      return $localParams;
    } else {
      $this->log(LOG_NOTICE, 'params for yk_publicname ' . $yk_publicname . ' not found in database');
      return false;
    }
  }

  private function parseParamsFromMultiLineString($str)
  {
      $i = preg_match("/^modified=(-1|[0-9]+)/m", $str, $out);
      if ($i != 1) {
	$this->log(LOG_ALERT, "cannot parse modified value: $str");
      }
      $resParams['modified']=$out[1];

      $i = preg_match("/^yk_publicname=([cbdefghijklnrtuv]+)/m", $str, $out);
      if ($i != 1) {
	$this->log(LOG_ALERT, "cannot parse publicname value: $str");
      }
      $resParams['yk_publicname']=$out[1];

      $i = preg_match("/^yk_counter=(-1|[0-9]+)/m", $str, $out);
      if ($i != 1) {
	$this->log(LOG_ALERT, "cannot parse counter value: $str");
      }
      $resParams['yk_counter']=$out[1];

      $i = preg_match("/^yk_use=(-1|[0-9]+)/m", $str, $out);
      if ($i != 1) {
	$this->log(LOG_ALERT, "cannot parse use value: $str");
      }
      $resParams['yk_use']=$out[1];

      preg_match("/^yk_high=(-1|[0-9]+)/m", $str, $out);
      if ($i != 1) {
	$this->log(LOG_ALERT, "cannot parse high value: $str");
      }
      $resParams['yk_high']=$out[1];

      preg_match("/^yk_low=(-1|[0-9]+)/m", $str, $out);
      if ($i != 1) {
	$this->log(LOG_ALERT, "cannot parse low value: $str");
      }
      $resParams['yk_low']=$out[1];

      preg_match("/^nonce=([[:alnum:]]+)/m", $str, $out);
      if ($i != 1) {
	$this->log(LOG_ALERT, "cannot parse counter value: $str");
      }
      $resParams['nonce']=$out[1];

      return $resParams;
  }

  public function updateDbCounters($params)
  {

    if (isset($params['yk_publicname'])) {
      $condition='('.$params['yk_counter'].'>yk_counter or ('.$params['yk_counter'].'=yk_counter and ' .
	$params['yk_use'] . '>yk_use))' ;
      if(! $this->db->conditionalUpdateBy('yubikeys', 'yk_publicname', $params['yk_publicname'], 
					  array('modified'=>$params['modified'], 
						'yk_counter'=>$params['yk_counter'], 
						'yk_use'=>$params['yk_use'],
						'yk_low'=>$params['yk_low'],
						'yk_high'=>$params['yk_high'], 
						'nonce'=>$params['nonce']), 
					  $condition))
	{
	  $this->log(LOG_CRIT, 'failed to update internal DB with new counters');
	  return false;
	} else 
	{
	  if ($this->db->rowCount()>0) $this->log(LOG_INFO, "updated database ", $params);
	  else $this->log(LOG_INFO, 'database not updated', $params);
	  return true;
	}
    } else return false;
  }
  
  public function countersHigherThan($p1, $p2)
  {
    if ($p1['yk_counter'] > $p2['yk_counter'] ||
	($p1['yk_counter'] == $p2['yk_counter'] &&
	 $p1['yk_use'] > $p2['yk_use'])) return true;
    else return false;
  }
  
  public function countersHigherThanOrEqual($p1, $p2)
  {
    if ($p1['yk_counter'] > $p2['yk_counter'] ||
	($p1['yk_counter'] == $p2['yk_counter'] &&
	 $p1['yk_use'] >= $p2['yk_use'])) return true;
    else return false;
  }

  public function countersEqual($p1, $p2) {
    return ($p1['yk_counter']==$p2['yk_counter']) && ($p1['yk_use']==$p2['yk_use']);
  }

  public function deleteQueueEntry($answer) 
  {

    preg_match('/url=(.*)\?/', $answer, $out);
    $server=$out[1];
    $this->log(LOG_INFO, "deleting server=" . $server . 
	       " modified=" . $this->otpParams['modified'] .
	       " server_nonce=" . $this->server_nonce);
    $this->db->deleteByMultiple('queue', 
				array("modified"=>$this->otpParams['modified'],
				      "server_nonce"=>$this->server_nonce, 
				      'server'=>$server));
  }

  public function reSync($older_than=60, $timeout)
  {
    $this->log(LOG_INFO, 'starting resync');
    /* Loop over all unique servers in queue */
    $queued_limit=time()-$older_than;
    $res=$this->db->customQuery("select distinct server from queue WHERE queued < " . $queued_limit . " or queued is null");
    
    foreach ($res as $my_server) {
      $this->log(LOG_INFO, "Sending queue request to server on server " . $my_server['server']);
      $res=$this->db->customQuery("select * from queue WHERE (queued < " . $queued_limit . " or queued is null) and server='" . $my_server['server'] . "'");
      
      while ($entry=$res->fetch(PDO::FETCH_ASSOC)) {
	$this->log(LOG_INFO, "server=" . $entry['server'] . " , info=" . $entry['info']);
	$url=$entry['server'] .  
	  "?otp=" . $entry['otp'] .
	  "&modified=" . $entry['modified'] .
	  "&" . $this->otpPartFromInfoString($entry['info']);
	
	
	/* Send out sync request */
	$this->log(LOG_DEBUG, 'url is ' . $url);
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_USERAGENT, "YK-VAL");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($ch, CURLOPT_FAILONERROR, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	$response = curl_exec($ch);
	curl_close($ch);
	
	if ($response==False) {
	  $this->log(LOG_NOTICE, 'Timeout. Stopping queue resync for server ' . $my_server['server']);
	  break;
	}
	
	if (preg_match("/status=OK/", $response)) {
	  $resParams=$this->parseParamsFromMultiLineString($response);
	  $this->log(LOG_DEBUG, "response contains ", $resParams);
	  
	  /* Update database counters */
	  $this->updateDbCounters($resParams);
	  
	  /* Retrieve info from entry info string */
	  
	  $validationParams=$this->localParamsFromInfoString($entry['info']);
	  $otpParams=$this->otpParamsFromInfoString($entry['info']);
	  $localParams=$this->getLocalParams($otpParams['yk_publicname']);

	  $this->log(LOG_DEBUG, "validation params: ", $validationParams);
	  $this->log(LOG_DEBUG, "OTP params: ", $otpParams);
	  
	  /* Check for warnings  */	   

	  if ($this->countersHigherThan($validationParams, $resParams)) {
	    $this->log(LOG_NOTICE, "Remote server out of sync compared to counters at validation request time. ");
	  }
	  
	  if ($this->countersHigherThan($resParams, $validationParams)) {
	    $this->log(LOG_NOTICE, "Local server out of sync compared to counters at validation request time. ");
	  }
	  
	  if ($this->countersHigherThan($localParams, $resParams)) {
	    $this->log(LOG_WARNING, "Remote server out of sync compared to current local counters.  ");
	  }
	  
	  if ($this->countersHigherThan($resParams, $localParams)) {
	    $this->log(LOG_WARNING, "Local server out of sync compared to current local counters. Local server updated. ");
	  }
	  
	  if ($this->countersHigherThan($resParams, $otpParams)) {
	      $this->log(LOG_ERR, "Remote server has higher counters than OTP. This response would have marked the OTP as invalid. ");
	  } 
	  elseif ($this->countersEqual($resParams, $otpParams) &&
		  $resParams['nonce']!=$otpParams['nonce']) {
	  $this->log(LOG_ERR, "Remote server has equal counters as OTP and nonce differs. This response would have marked the OTP as invalid.");
	}
	  
	  /* Deletion */
	  $this->log(LOG_INFO, 'deleting queue entry with modified=' . $entry['modified'] .
		     ' server_nonce=' . $entry['server_nonce'] .
		     ' server=' . $entry['server']);
	  $this->db->deleteByMultiple('queue', 
				      array("modified"=>$entry['modified'],
					    "server_nonce"=>$entry['server_nonce'], 
					    'server'=>$entry['server']));
	}
	
      } /* End of loop over each queue entry for a server */
    $res->closeCursor();
    } /* End of loop over each distinct server in queue */
    return true;
  }
  
  public function sync($ans_req, $timeout=1) 
  {
    /*
     Construct URLs
    */
    
    $urls=array();
    $res=$this->db->findByMultiple('queue', array("modified"=>$this->otpParams['modified'], "server_nonce"=>$this->server_nonce));
    foreach ($res as $row) {
      $urls[]=$row['server'] .  
	"?otp=" . $row['otp'] .
	"&modified=" . $row['modified'] .
	"&" . $this->otpPartFromInfoString($row['info']);
    }
    
    /*
     Send out requests
    */
    $ans_arr=$this->retrieveURLasync($urls, $ans_req, $timeout);
    
    if (!is_array($ans_arr)) {
      $this->log(LOG_WARNING, 'No responses from validation server pool'); 
      $ans_arr=array();
    }
    
    /*
     Parse responses
    */
    $localParams = $this->localParams;
    
    $this->answers = count($ans_arr);
    $this->valid_answers = 0;
    foreach ($ans_arr as $answer){
      /* Parse out parameters from each response */
      $resParams=$this->parseParamsFromMultiLineString($answer);
      $this->log(LOG_DEBUG, "local db contains ", $localParams);
      $this->log(LOG_DEBUG, "response contains ", $resParams);
      $this->log(LOG_DEBUG, "OTP contains " , $this->otpParams);

      /* Update internal DB (conditional) */
      
      $this->updateDbCounters($resParams);
      
      
      /* Check for warnings 

       See http://code.google.com/p/yubikey-val-server-php/wiki/ServerReplicationProtocol
       
       NOTE: We use localParams for validationParams comparison since they are actually the
       same in this situation and we have them at hand. 
      */
      
      if ($this->countersHigherThan($localParams, $resParams)) {
	$this->log(LOG_NOTICE, "Remote server out of sync");
      }
      
      if ($this->countersHigherThan($resParams, $localParams)) {
	$this->log(LOG_NOTICE, "Local server out of sync");
      }
      
      if ($this->CountersEqual($resParams, $localParams) && 
	  $resParams['nonce']!=$localParams['nonce']) {
	$this->log(LOG_NOTICE, "Servers out of sync. Nonce differs. ");
      }

     
      if ($this->CountersEqual($resParams, $localParams) && 
	  $resParams['modified']!=$localParams['modified']) {
	$this->log(LOG_NOTICE, "Servers out of sync. Modified differs. ");
      }
      
      if ($this->countersHigherThan($resParams, $this->otpParams)){
	  $this->log(LOG_WARNING, 'OTP is replayed. Sync response counters higher than OTP counters.');
	} 
	elseif ($this->countersEqual($resParams, $this->otpParams) &&
		$resParams['nonce']!=$this->otpParams['nonce']) {
	$this->log(LOG_WARNING, 'OTP is replayed. Sync response counters equal to OTP counters and nonce differs.');
      } else {
	
	/* The answer is ok since a REPLAY was not indicated */
	
	$this->valid_answers++;
      }
      
      
      
      
      /*  Delete entry from table */
      $this->deleteQueueEntry($answer);
      
      
    }
   
    /* 
     NULL queued_time for remaining entries in queue, to allow
     daemon to take care of them as soon as possible. */
    
    $this->db->updateBy('queue', 'server_nonce', $this->server_nonce, 
			array('queued'=>NULL));
    
    

    /* Return true if valid answers equals required answers. 
     Since we only obtain the required amount of answers from 
     retrieveAsync this indicates that all answers were actually valid. 
     Otherwise, return false. */
    if ($this->valid_answers==$ans_req) return True;
    else return False;
  }

  public function getNumberOfValidAnswers()
  {
    if (isset($this->valid_answers)) return $this->valid_answers;
    else return 0;
  }

  public function getNumberOfAnswers()
  {
    if (isset($this->answers)) return $this->answers;
    else return 0;
  }


  /*
   This function takes a list of URLs.  It will return the content of
   the first successfully retrieved URL, whose content matches ^OK.
   The request are sent asynchronously.  Some of the URLs can fail
   with unknown host, connection errors, or network timeout, but as
   long as one of the URLs given work, data will be returned.  If all
   URLs fail, data from some URL that did not match parameter $match 
   (defaults to ^OK) is returned, or if all URLs failed, false.
  */
  function retrieveURLasync ($urls, $ans_req=1, $timeout=1.0) {
    $mh = curl_multi_init();

    $ch = array();
    foreach ($urls as $id => $url) {
      $this->log(LOG_DEBUG, "url in retrieveURLasync is " . $url);
      $handle = curl_init();
      
      curl_setopt($handle, CURLOPT_URL, $url);
      curl_setopt($handle, CURLOPT_USERAGENT, "YK-VAL");
      curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($handle, CURLOPT_FAILONERROR, true);
      curl_setopt($handle, CURLOPT_TIMEOUT, $timeout);
      
      curl_multi_add_handle($mh, $handle);
      
      $ch[$handle] = $handle;
    }
    
    $str = false;
    $ans_count = 0;
    $ans_arr = array();
    
    do {
      while (($mrc = curl_multi_exec($mh, $active)) == CURLM_CALL_MULTI_PERFORM)
	;
      
      while ($info = curl_multi_info_read($mh)) {
	debug ("YK-KSM multi", $info);
	if ($info['result'] == CURL_OK) {
	  $str = curl_multi_getcontent($info['handle']);
	  if (preg_match("/status=OK/", $str)) {
	    $error = curl_error ($info['handle']);
	    $errno = curl_errno ($info['handle']);
	    $cinfo = curl_getinfo ($info['handle']);
	    debug("YK-KSM errno/error: " . $errno . "/" . $error, $cinfo);
	    $ans_count++;
	    $ans_arr[]="url=" . $cinfo['url'] . "\n" . $str;
	  }
	  
	  if ($ans_count >= $ans_req) {
	    foreach ($ch as $h) {
	      curl_multi_remove_handle ($mh, $h);
	      curl_close ($h);
	    }
	    curl_multi_close ($mh);
	    
	    return $ans_arr;
	  }
	  
	  curl_multi_remove_handle ($mh, $info['handle']);
	  curl_close ($info['handle']);
	  unset ($ch[$info['handle']]);
	}
	
	curl_multi_select ($mh);
      }
    } while($active);

    
    foreach ($ch as $h) {
      curl_multi_remove_handle ($mh, $h);
      curl_close ($h);
    }
    curl_multi_close ($mh);

    if ($ans_count>0) return $ans_arr;
    else return $str;
  }
  
}

?>
