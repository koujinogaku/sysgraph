<?php
//////////////////////////////////////////////////////////////////////////
// SysGraph : Sysstat Graphical Viewer
//                   Copyright Koujinogaku (2012)
//
// Install  : 1. Setup sysstat that is Linux system tool.
//                 ex. root# yum install sysstat
//            2. Setup Apache 2.x with PHP 5.x
//                 ex. root# yum install httpd php
//            3. Allow the apache acount to execute command of sar.
//                 PHP can access to sar command by default.
//            4. Copy sysgraph.php to htdocs.
//                 ex. root# cp sysgraph.php /var/www/html/.
//            5. Access to sysgraph.php on apache from web browsers.
//                 ex. http://web/sysgraph.php
//            6. You can control to access by .htaccess or any methods.
/////////////////////////////////////////////////////////////////////////

class Monitor {

  public function getCommand()
  {
    if(!isset($_GET['cmd']))
      return 'cpu';
    $cmd = $_GET['cmd'];
    if(array_search($cmd,array('cpu','disk','page','net','mem'))===false)
      return 'cpu';
    return $cmd;
  }

  public function getCmdOpt($cmd)
  {
    $types = array(
      'cpu'  => ' -u',
      'disk' => ' -b',
      'net'  => ' -n DEV',
      'mem'  => ' -r',
      'page' => ' -W',
    );
    return $types[$cmd];
  }

  public function getGraphType($cmd)
  {
    $types = array(
      'cpu'  => 'StackedAreas',
      'disk' => 'StackedAreas',
      'net'  => 'Lines',
      'mem'  => 'StackedAreas',
      'page' => 'StackedAreas',
    );
    return $types[$cmd];
  }

  public function getFileOpt()
  {
    $dirs = array(
      '/var/log/sa',
      '/var/log/sysstat',
    );
    $sadir = null;
    foreach($dirs as $dir) {
      if(file_exists($dir)) {
        $sadir = $dir;
        break;
      }
    }
    $fileopt = '';
    if(isset($_GET['date'])) {
      $date = intval($_GET['date']);
      $fileopt = ' -f '.$sadir . '/sa' . str_pad($date,2,'0', STR_PAD_LEFT);
    }
    return $fileopt;
  }

  public function getData($cmdOpt,$fileOpt)
  {
    $title = array();
    $data = array();
    $pp = popen('export LANG=C;/usr/bin/sar'.$cmdOpt.$fileOpt, 'r');
    $is_title = true;
    while(!feof($pp)) {
      $row = array();
      $line = fgets($pp);
      $tok = strtok($line, " \n\t");
      while ($tok !== false) {
        $row[] = $tok;
        $tok = strtok(" \n\t");
      }
      if(isset($row[1])&&($row[1]=='AM'||$row[1]=='PM')) {
        $time = array_shift($row);
        $row[0] = $time.$row[0];
      }
      if(isset($row[2]) && is_numeric($row[2]) && $row[0]!='Average:')
        $data[] = $row;
      if(isset($row[1]) && ($row[1]=='CPU' || $row[1]=='tps' || $row[1]=='IFACE' || $row[1]=='pgpgin/s' || $row[1]=='kbmemfree')) {
        $title = $row;
      }
    }
    pclose($pp);

    return array($title,$data);
  }

  public function getEthName()
  {
    $eth = null;
    $pp = popen('/sbin/ifconfig', 'r');
    while(!feof($pp)) {
      $line = fgets($pp);
      $t = preg_match('/^([0-9a-z]+)/i',$line,$match);
      if($t && $match[1]!='lo') {
        $eth =  $match[1];
        break;
      }
    }
    pclose($pp);

    return $eth;
  }
  public function getNetScale($title)
  {
    if(isset($title[4]) && $title[4]=='rxbyt/s')
      $scale=1024;
    else
      $scale=1;
    return $scale;
  }

  public function listColumn($data,$col,$type=null,$select=null, $scale=1, $func=null,$limit=null)
  {
    if($type===null)
      $type='float';
    $colList = array();
    foreach($data as $row) {
      if($select!==null && $row[1]!==$select)
        continue;
      if($func===null) {
        $value = $row[$col];
      }
      else {
        $value = call_user_func($func,$row);
      }
      if($limit!==null) {
        if($value>$limit)
          $value = $limit;
      }
      switch($type){
        case 'string':
          $colList[] = strval($value);
          break;
        case 'float':
          $colList[] = (float)$value / (float)$scale;
          break;
        case 'int':
          $colList[] = intval($value) / $scale;
          break;
      }
    }
    return $colList;
  }

  public function formatCpu($data)
  {
    $formated = array(
      array('Label' => 'Time',   'Data' => $this->listColumn($data,0,'string')),
      array('Label' => '%User',  'Data' => $this->listColumn($data,2,'int',null,1,null,200)),
      array('Label' => '%Nice',  'Data' => $this->listColumn($data,3,'int',null,1,null,200)),
      array('Label' => '%Steal', 'Data' => $this->listColumn($data,6,'int',null,1,null,200)),
      array('Label' => '%Sys',   'Data' => $this->listColumn($data,4,'int',null,1,null,200)),
      array('Label' => '%IO',    'Data' => $this->listColumn($data,5,'int',null,1,null,200)),
    );
    return $formated;
  }

  public function formatDisk($data)
  {
    $formated = array(
      array('Label' => 'Time',     'Data' => $this->listColumn($data,0,'string')),
      array('Label' => 'Bread/s',  'Data' => $this->listColumn($data,4)),
      array('Label' => 'Bwrtn/s',  'Data' => $this->listColumn($data,5)),
    );
    return $formated;
  }

  public function formatNetwork($data,$select,$scale)
  {
    $formated = array(
      array('Label' => 'Time',    'Data' => $this->listColumn($data,0,'string',$select)),
      array('Label' => 'rxpck/s', 'Data' => $this->listColumn($data,2,'float',$select)),
      array('Label' => 'txpck/s', 'Data' => $this->listColumn($data,3,'float',$select)),
      array('Label' => 'rxKbyt/s', 'Data' => $this->listColumn($data,4,'float',$select,$scale)),
      array('Label' => 'txKbyt/s', 'Data' => $this->listColumn($data,5,'float',$select,$scale)),
    );
    return $formated;
  }

  public function formatPage($data)
  {
    $formated = array(
      array('Label' => 'Time',      'Data' => $this->listColumn($data,0,'string')),
      array('Label' => 'pswpin/s',  'Data' => $this->listColumn($data,1)),
      array('Label' => 'pswout/s',  'Data' => $this->listColumn($data,2)),
    );
    return $formated;
  }

  public function formatMemory($data)
  {
    $formated = array(
      array('Label' => 'Time',   'Data' => $this->listColumn($data,0,'string')),
      array('Label' => 'User',   'Data' => $this->listColumn(
                       $data, null, 'int',null,1,
                       array($this,'calcUserMemory'))),
      array('Label' => 'Buffer', 'Data' => $this->listColumn($data,4)),
      array('Label' => 'Cache',  'Data' => $this->listColumn($data,5)),
      array('Label' => 'Free',   'Data' => $this->listColumn($data,1)),
      array('Label' => 'Swap',   'Data' => $this->listColumn($data,7)),
    );
    return $formated;
  }

  public function calcUserMemory($row)
  {
    $user = intval($row[2]) - intval($row[5]) - intval($row[4]);
    return $user;
  }

} // end class

$monitor = new Monitor();
$cmd = $monitor->getCommand();
$cmdOpt = $monitor->getCmdOpt($cmd);
$fileOpt = $monitor->getFileOpt();
$select = '';
list($title,$data) = $monitor->getData($cmdOpt,$fileOpt);
switch($cmd) {
  case 'cpu';
    $formatedData = $monitor->formatCpu($data);
    break;
  case 'disk';
    $formatedData = $monitor->formatDisk($data);
    break;
  case 'net';
    $select = $monitor->getEthName();
    if($select===null)
      break;
    $scale = $monitor->getNetScale($title);
    $formatedData = $monitor->formatNetwork($data,$select,$scale);
    break;
  case 'page';
    $formatedData = $monitor->formatPage($data);
    break;
  case 'mem';
    $formatedData = $monitor->formatMemory($data);
    break;
}
$formatedData = json_encode($formatedData);
$graphType = $monitor->getGraphType($cmd);


?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html lang="ja">
<head>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<meta http-equiv="content-style-type" content="text/css">
<meta http-equiv="content-script-type" content="text/javascript">
<link rel="stylesheet" href="http://ajax.googleapis.com/ajax/libs/dojo/1.7.1/dijit/themes/claro/document.css">
<link rel="stylesheet" href="http://ajax.googleapis.com/ajax/libs/dojo/1.7.1/dijit/themes/claro/claro.css">
<script src="http://ajax.googleapis.com/ajax/libs/dojo/1.7.1/dojo/dojo.js" type="text/javascript" djConfig="parseOnLoad:true"></script>
<script>
    dojo.require("dijit.dijit"); // loads the optimized dijit layer
    //dojo.require("dijit.Calendar");
    dojo.require("dijit.form.DateTextBox");
    dojo.require("dijit.form.Button");
    dojo.require("dojo.date.locale");
    dojo.require('dojox.charting.Chart2D');
    dojo.require('dojox.charting.widget.Chart2D');
    dojo.require('dojox.charting.widget.Legend');
    //dojo.require('dojox.charting.themes.PlotKit.blue');
    dojo.require('dojox.charting.themes.Wetland');
    //dojo.require('dojox.charting.themes.ThreeD');

    /* JSON information */
    var json = { data: <?php echo $formatedData; ?> };

    /* build pie chart data */
    //var chartData = [];
    //dojo.forEach(json['User'],function(item,i) {
    //    chartData.push({ x: i, y: json['User'][i] });
    //});

    function getQueryObject() {
        var uri =  location.href;
        var hostLen = uri.indexOf("?");
        var query;
        if(hostLen < 0)
          query = '';
        else
          query = uri.substring(uri.indexOf("?") + 1, uri.length);
        query = dojo.queryToObject(query);
        return query;
    }

    function jumpToQueryObject(query) {
        var uri =  location.href;
        var hostLen = uri.indexOf("?");
        var newUri;
        if(hostLen < 0)
          newUri = uri + '?' +  dojo.objectToQuery(query);
        else
          newUri = uri.substring(0,hostLen+1) +  dojo.objectToQuery(query);
        location.href = newUri;
    }

    function changeResource(cmd) {
        var query = getQueryObject();
        query.cmd = cmd;
        jumpToQueryObject(query);
    }

    function getQueryDate() {
        var today = new Date();
        var queryDate;
        var query = getQueryObject();
        if('date' in query)
            queryDate = query.date;
        else
            queryDate = today.getDate();
        var queryMonth = today.getMonth();
        if(queryDate > today.getDate())
            queryMonth--;
        var queryYear = today.getFullYear();
        if(queryMonth<0) {
            queryYear--;
            queryMonth=12;
        }
        return new Date(queryYear,queryMonth,queryDate)
    }

    /* resources are ready... */
    dojo.ready(function() {

        var btnCpu = new dijit.form.Button({
            onClick: function(){
                changeResource('cpu');
            }
        }, "cmdCpu");
        var btnMem = new dijit.form.Button({
            onClick: function(){
                changeResource('mem');
            }
        }, "cmdMem");
        var btnDisk = new dijit.form.Button({
            onClick: function(){
                changeResource('disk');
            }
        }, "cmdDisk");
        var btnNet = new dijit.form.Button({
            onClick: function(){
                changeResource('net');
            }
        }, "cmdNet");
        var btnPage = new dijit.form.Button({
            onClick: function(){
                changeResource('page');
            }
        }, "cmdPage");
        var btnDirect = new dijit.form.DateTextBox({ // new dijit.Calendar
            value: getQueryDate(),
            //isDisabledDate: function(d){
            //    var d = new Date(d); d.setHours(0, 0, 0, 0);
            //    var today = new Date(); today.setHours(0, 0, 0, 0);
            //    return Math.abs(dojo.date.difference(d, today, "week")) > 0;
            //}
            onChange: function(){
                var query = getQueryObject();
                query.date = arguments[0].getDate();
                jumpToQueryObject(query);
            }
        }, "calDirect");
        var btnForward = new dijit.form.Button({
            onClick: function(){
                var queryDate = getQueryDate();
                queryDate = dojo.date.add(queryDate,"day",1);
                var query = getQueryObject();
                query.date = queryDate.getDate();
                jumpToQueryObject(query);
            }
        }, "calForward");
        var btnBackward = new dijit.form.Button({
            onClick: function(){
                var queryDate = getQueryDate();
                queryDate = dojo.date.add(queryDate,"day",-1);
                var query = getQueryObject();
                query.date = queryDate.getDate();
                jumpToQueryObject(query);
            }
        }, "calBackward");

        
        //create / swap data
        //var barData = [];
        //dojo.forEach(chartData,function(item) {
        //    barData.push({ x: item['y'], y: item['x'] });
        //});

        var chart1 = new dojox.charting.Chart2D('chart1').
            //setTheme(dojox.charting.themes.PlotKit.blue).
            setTheme(dojox.charting.themes.Wetland).
            addPlot('default', {
                type: '<?php echo $graphType ?>',
                markers:false,
                tension:0,
                lines:false,
                areas:false,
            }).
            //addAxis('x', { fixUpper: 'major', fixLower: 'major' }).
            //addAxis('x', { fixUpper: 145, fixLower: 'major', max: 145}).
            addAxis('y', { vertical: true, fixLower: 'major', fixUpper: 'major', min:0 });

            //addSeries('User',json['User']).
            //addSeries('Nice',json['Nice']).
            //addSeries('St',json['Steal']).
            //addSeries('Sys',json['Sys']).
            //addSeries('IO',json['IO']);
        dojo.forEach(json['data'],function(item) {
            if(item['Label']=='Time') {
                var xAxis = [];
                dojo.forEach(item['Data'],function(title,i) {
                    //alert(i);
                    //alert(title);
                    xAxis.push({ value: i, text: title });
                });
                chart1.addAxis('x', { labels: xAxis, max: 145, majorLabels: true, majorTickStep: 12 });
            }
            else {
              chart1.addSeries(item['Label'], item['Data']);
            }
        });
        //var anim4b = new dojox.charting.action2d.Tooltip(chart1, 'default');
        //var anim4c = new dojox.charting.action2d.Shake(chart1,'default');
        chart1.render();
        var legend1 = new dojox.charting.widget.Legend({chart: chart1}, "legend1");

    });
</script>
</head>
<body class="claro">
<button id="cmdCpu" type="button">CPU</button>
<button id="cmdMem" type="button">Mem</button>
<button id="cmdDisk" type="button">Disk</button>
<button id="cmdPage" type="button">Paging</button>
<button id="cmdNet" type="button">Network</button>
<button id="calBackward" type="button">&lt;</button>
<div id="calDirect"></div>
<button id="calForward" type="button">&gt;</button>
<hr>
<div id="comment"></div>
<?php echo $cmdOpt.' '.$select.$fileOpt ?>
<hr>
<div id="legend1" style="width:960px;height:10px;"></div>
<div id="chart1" style="width:960px;height:200px;"></div>
</body>
