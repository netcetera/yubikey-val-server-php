#!/usr/bin/php
<?php

set_include_path(get_include_path() . PATH_SEPARATOR .
		 "/etc/ykval:/usr/share/ykval");

require_once 'ykval-config.php';

function url2shortname ($url) {
  if (preg_match("/^[^\/]+\/\/([a-z0-9-]+)/", $url, $name)==0){
    echo "Cannot match URL hostname: " . $url . "\n";
    exit (1);
  }

  return $name[1];
}

$urls = $baseParams['__YKVAL_SYNC_POOL__'];
$shortnames = array_map("url2shortname", $urls);

if ($argc==2 && strcmp($argv[1], "autoconf") == 0) {
  print "yes\n";
  exit (0);
}

if ($argc==2 && strcmp($argv[1], "config") == 0) {

  echo "multigraph ykval_latency\n";
  echo "graph_title VAL latency\n";
  echo "graph_vlabel Average VAL Latency (seconds)\n";
  echo "graph_category ykval\n";
  echo "graph_width 400\n";

  foreach ($shortnames as $shortname) {
    echo "ipv4${shortname}_avgwait.label IPv4-${shortname}\n";
    echo "ipv4${shortname}_avgwait.type GAUGE\n";
    echo "ipv4${shortname}_avgwait.info Average VAL round-trip latency\n";
    echo "ipv4${shortname}_avgwait.min 0\n";
    echo "ipv4${shortname}_avgwait.draw LINE1\n";

    echo "ipv6${shortname}_avgwait.label IPv6-${shortname}\n";
    echo "ipv6${shortname}_avgwait.type GAUGE\n";
    echo "ipv6${shortname}_avgwait.info Average VAL round-trip latency\n";
    echo "ipv6${shortname}_avgwait.min 0\n";
    echo "ipv6${shortname}_avgwait.draw LINE1\n";
  }

  exit (0);
}

echo "multigraph ykval_latency\n";
foreach ($urls as $url) {
  $shortname = url2shortname ($url);
  $cmd = "--user-agent ykval-munin-vallatency/1.0 --silent --write-out '%{time_total}' --max-time 3 '$url' -o /dev/null";
  $ipv4time = `curl --ipv4 $cmd`;
  echo "ipv4${shortname}_avgwait.value $ipv4time\n";
  $ipv6time = `curl --ipv6 $cmd`;
  echo "ipv6${shortname}_avgwait.value $ipv6time\n";
}

#%# family=auto
#%# capabilities=autoconf
?>
