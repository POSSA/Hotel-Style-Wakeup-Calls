<?php
namespace FreePBX\modules;
use BMO;
use FreePBX_Helpers;
use PDO;

class Hotelwakeup extends FreePBX_Helpers implements BMO {
    public static $defaultConfig = [
        'maxretries' => 3, 
        'waittime' => 60, 
        'retrytime' => 60, 
        'cnam' => 'Wake Up Calls', 
        'cid' => '*68', 
        'operator_mode' => '1', 
        'operator_extensions' => ['00','01'], 
        'extensionlength' => '4', 
        'application' => 'AGI', 
        'data' => 'wakeupconfirm.php',
        'language' => ''
	];

	public function get_path($name, $backslashend = true)
	{
		$data_return = $this->FreePBX->Config->get('ASTSPOOLDIR');
		switch($name) 
		{
			case "outgoing":
				$data_return .= "/outgoing";
				break;

			case "outgoing_done";
				$data_return .= "/outgoing_done";
				break;

			case "tmp":
				$data_return .= "/tmp";
				break;
			
			default:
				$data_return = "";
		}
		if (! empty($data_return)) 
		{
			if ($backslashend)
			{
				$data_return .= "/";
			}

		}
		return $data_return;
	}

	public function setDatabase($database){
		$this->Database = $database;

		return $this;
	}

	public function resetDatabase(){
		$this->Database = $this->FreePBX->Database;
		return $this;
	}

	public function install() 
	{
        $fcc = new \featurecode('hotelwakeup', 'hotelwakeup');
        $fcc->setDescription('Wake Up Calls');
        $fcc->setDefault('*68');
        $fcc->update();
		unset($fcc);
		

		//Migrate Table > KVSTORE
		$sql = "SHOW TABLES LIKE 'hotelwakeup'";
		$count = $this->Database->query($sql)->rowCount();
		if($count == 1)
		{
			$sql = "SELECT * FROM hotelwakeup LIMIT 1";
			$sth = $this->Database->prepare($sql);
			$sth->execute();
			$fa = $sth->fetch(PDO::FETCH_ASSOC);
			$this->saveSetting($fa);
			unset($fa);
			unset($sth);

			$sql = "DROP TABLE IF EXISTS hotelwakeup";
			$this->Database->query($sql);
		}
		unset($count);
		unset($sql);
		
		//Create default values
		$currentConfig = $this->getSetting();
		if( empty($currentConfig) ) 
		{
			$this->saveSetting(self::$defaultConfig);
		}
    }
	public function uninstall() {}
	public function doConfigPageInit($page) {}
	public function getActionBar($request) {}

	public function ajaxRequest($req, &$setting) {
		// ** Allow remote consultation with Postman **
		// ********************************************
		// $setting['authenticate'] = false;
		// $setting['allowremote'] = true;
		// return true;
		// ********************************************
		switch($req)
		{
			case "savecall":
			case "getable":
			case "removecall":
			case "getsettings":
			case "setsettings":
				return true;
			break;
		}
		return false;
	}

	public function ajaxHandler() {
		switch($_REQUEST['command']) {
			case "savecall":
				$lang = empty($_POST['language']) ? '' :  $_POST['language'];
				if(empty($_POST['day']) || empty($_POST['time'])) {
					return array("status" => false, "message" => _("Cannot schedule the call, due to insufficient data"));
				}
				$time_wakeup = strtotime($_POST['day']." ".$_POST['time']);
				$time_now = time();
				$badtime = false;
				if ( $time_wakeup === false || $time_wakeup <= $time_now )  {
					$badtime = true;
				}

				// check for insufficient data
				if ($badtime)  {
					// abandon .call file creation and pop up a js alert to the user
					return array("status" => false, "message" => sprintf(_("Cannot schedule the call the scheduled time is in the past. [Time now: %s] [Wakeup Time: %s]"),date(DATE_RFC2822,$time_now),date(DATE_RFC2822,$time_wakeup)));
				} else {
					$this->addWakeup($_POST['destination'],$time_wakeup,$lang);
					return array("status" => true);
				}
			break;

			case "getable":
				return $this->getAllCalls();
			break;

			case "removecall":
				$id  = empty($_POST['id'])  ? "" : $_POST['id'];
				$ext = empty($_POST['ext']) ? "" : $_POST['ext'];
				if (empty($id))
				{
					$data_return = array("status" => false, "message" => _("The ID parameter is missing!"));
				}
				elseif (empty($ext)) 
				{
					$data_return = array("status" => false, "message" => _("The EXT parameter is missing!"));
				}
				else
				{
					$file = sprintf("wuc.%s.ext.%s.call", $id, $ext);
					$this->removeWakeup($file);
					$data_return = array("status" => true);
				}
				return $data_return;
			break;

			case "getsettings":
				$data_return = array(
					"status"  => true,
					"message" => _("Settings loaded successfully"),
					"config"  => $this->getSetting()
				);

				$data_return['config']['operator_mode'] = ($data_return['config']['operator_mode']) ? "yes" : "no";
				// $data_return['config']['callerid'] = htmlentities($data_return['config']['callerid']);
				// $data_return['config']['operator_extensions'] = implode("\n", $data_return['config']['operator_extensions']);

				return $data_return;
			break;

			case "setsettings":
				$list_options = array(
					"callerid" => array(
						"requiered" => true,
						"type" 		=> "numeric"
					),
					"operator_mode" => array(
						"requiered" => true,
						"type" 		=> "numeric"
					),
					"extensionlength" => array(
						"requiered" => true,
						"type" 		=> "numeric"
					),
					"operator_extensions" => array(
						"requiered" => false,
						"type" 		=> "string"
					),
					"waittime" => array(
						"requiered" => true,
						"type" 		=> "numeric"
					),
					"retrytime" => array(
						"requiered" => true,
						"type" 		=> "numeric"
					),
					"maxretries" => array(
						"requiered" => true,
						"type" 		=> "numeric"
					),
					"language" => array(
						"requiered" => false,
						"type" 		=> "string"
					),
				);
				$new_options = array();
				$missing_options = array();
				$invalid_value = array();

				foreach ($list_options as $key => $value)
				{
					if ( empty($_POST[$key]) && $value['requiered'] )
					{
						$missing_options[] = $key;
						continue;
					}
					switch($key)
					{
						case "callerid":
							preg_match('/"(.*)" <(.*)>/',$_POST[$key],$matches);
							$new_options['cid']  = !empty($matches[2]) ? $matches[2] : $this->getCode();
							$new_options['cnam'] = !empty($matches[1]) ? $matches[1] : self::$defaultConfig['cnam'];
						break;

						case "operator_mode":
							$new_options[$key] = ($_POST[$key] == "yes") ? "1": "0";
						break;

						default:
							$new_options[$key] = $_POST[$key];
							if ( $value['type'] == "numeric" )
							{
								if ( ! is_numeric( $_POST[$key] ) ) 
								{
									$invalid_value[] = $key;
								}
							}
					}
				}

				if (count($missing_options) == 0)
				{
					if (count($invalid_value) == 0)
					{
						$this->saveSetting($new_options);
						$data_return = array("status" => true, "message" => _("Settings saved successfully"));
					}
					else
					{
						$data_return = array("status" => false, "message" => _("Save failed, invalid values!"), "options" => $invalid_value);
					}
				}
				else
				{
					$data_return = array("status" => false, "message" => _("Save failed, missing parameters!"));
				}
				return $data_return;
			break;
		}
		return true;
	}

	public function addWakeup($destination, $time, $lang) {
		$date = $this->getSetting();  // module config provided by user
		if(empty($lang))
		{
			$lang = $date['language'];
			if (empty($lang))
			{
				//if no language has been configured, the system language is used
				$lang = $this->FreePBX->Config->get("UIDEFAULTLANG");
			}
		}
		$this->generateCallFile(array(
			"time"  => $time,
			"date" => 'unused',
			"ext" => $destination,
			"language" => $lang,
			"maxretries" => $date['maxretries'],
			"retrytime" => $date['retrytime'],
			"waittime" => $date['waittime'],
			"callerid" => $date['cnam']." <".$date['cid'].">",
			"application" => 'AGI',
			"data" => 'wakeconfirm.php',
			"AlwaysDelete" => "Yes",
			"Archive" => "Yes"
		));
	}

	public function showPage($page) {
		switch ($page) 
		{
			case "wakeup":
				$data_return = load_view(__DIR__."/views/page.wakeup.php", array("hotelwakeup" => $this));
				break;

			case "wakeup.grid":
				$data_return = load_view(__DIR__.'/views/view.wakeup.grid.php', array("hotelwakeup" => $this));
				break;

			case "wakeup.grid.create":
				$data_return = load_view(__DIR__.'/views/view.wakeup.grid.create.php', array("hotelwakeup" => $this));
				break;

			case "wakeup.settings":
				$data_return = load_view(__DIR__.'/views/view.wakeup.settings.php', array("hotelwakeup" => $this));
				break;

			default:
				$data_return = "";
		}
		return $data_return;
	}

	public function getCode() {
		$fcc = new \featurecode('hotelwakeup', 'hotelwakeup');
		$code = $fcc->getCode();
		return $code;
	}

	public function getSetting()
	{
		$data_return = $this->getAll("setting");
		if (empty($data_return))
		{
			$data_return = self::$defaultConfig;
		}

		$data_return['callerid'] = sprintf('"%s" <%s>', $data_return['cnam'], $data_return['cid']);
		if ( ! is_array($data_return['operator_extensions']) )
		{
			$data_return['operator_extensions'] = explode(",", $data_return['operator_extensions']);
		}
		foreach($data_return['operator_extensions'] as &$ext)
		{
			$ext = trim($ext);
		}
		return $data_return;
	}

	public function saveSetting($options)
	{
		// $options_list = array(
		// 	'cid',
		// 	'cnam',
		// 	'extensionlength',
		// 	'language',
		// 	'maxretries',
		// 	'operator_extensions',
		// 	'operator_mode',
		// 	'retrytime',
		// 	'waittime',
		// );

		unset($options['callerid']);
		if(! empty($options)) 
		{
			if ( ! is_array($options['operator_extensions']) )
			{
				$options['operator_extensions'] = explode(",", $options['operator_extensions']);
			}

			foreach($options as $key => $value)
			{
				$this->setConfig($key, $value, 'setting');
			}
			return true;
		}
		return false;
	}

	public function CheckWakeUpProp($file) {
		$myresult = '';
		$file = basename($file);
		$WakeUpTmp = explode(".", $file);
		$myresult = null;
		if(!empty($WakeUpTmp[1]) && !empty($WakeUpTmp[3])) {
			$myresult[0] = $WakeUpTmp[1];
			$myresult[1] = $WakeUpTmp[3];
		}
		return $myresult;
	}

	public function removeWakeup_done($file) {
		$file = basename($file);
		$file_full_path = $this->get_path("outgoing_done").$file;
		if(file_exists($file_full_path)) {
			unlink($file_full_path);
		}
		return true;
	}

	public function removeWakeup($file) {
		$file = basename($file);
		$file_full_path = $this->get_path("outgoing").$file;
		if(file_exists($file_full_path)) {
			unlink($file_full_path);
		}
		return true;
	}

	public function getAllCalls() {
		$calls = array();
		foreach(glob($this->get_path("outgoing")."wuc*.call") as $file) {
			$res = $this->CheckWakeUpProp($file);
			if(!empty($res)) {
				$filedate = date('M d Y',filemtime($file)); //create a date string to display from the file timestamp
				$filetime = date('H:i',filemtime($file));   //create a time string to display from the file timestamp
				$wucext = $res[1];
				$calls[] = array(
					"filename" => basename($file),
					"timestamp" => filemtime($file),
					"time" => $filetime,
					"date" => $filedate,
					"destination" => $wucext,
					"wakeup_id" => $res[0],
					"wakeup_ext" => $res[1],
					"actionsjs" => '<a href="#" onclick="removeWakeup('.$res[0].','.$res[1].');return false;"><i class="fa fa-trash"></i></a>',
					// Legacy \ Next Line \ Maintain for PMS module compatibility.
					"actions" => '<a href="?display=hotelwakeup&amp;action=delete&amp;id='.$res[0].'&amp;ext='.$res[1].'"><i class="fa fa-times"></i></a>' 
				);
			}
		}
		return $calls;
	}

	public function generateCallFile($foo) {
		if (empty($foo['tempdir'])) {
			$ast_tmp_path = $this->get_path("tmp");
			if(!file_exists($ast_tmp_path)) {
				mkdir($ast_tmp_path,0777,true);
			}
			$foo['tempdir'] = $ast_tmp_path;
		}

		if (empty($foo['outdir'])) {
			$foo['outdir'] = $this->get_path("outgoing");
		}

		$foo['ext'] = preg_replace("/[^\d@\+\#]/","",$foo['ext']);
		if (empty($foo['filename'])) {
			$foo['filename'] = "wuc.".$foo['time'].".ext.".$foo['ext'].".call";
		}

		$foo['filename'] = basename($foo['filename']);

		$tempfile = $foo['tempdir'].$foo['filename'];
		$outfile = $foo['outdir'].$foo['filename'];

		// Delete any old .call file with the same name as the one we are creating.
		if(file_exists($outfile) ) {
			unlink($outfile);
		}

		// Create up a .call file, write and close
		$wuc = fopen($tempfile, 'w');
		fputs( $wuc, "channel: Local/".$foo['ext']."@originate-skipvm\n" );
		fputs( $wuc, "maxretries: ".$foo['maxretries']."\n");
		fputs( $wuc, "retrytime: ".$foo['retrytime']."\n");
		fputs( $wuc, "waittime: ".$foo['waittime']."\n");
		fputs( $wuc, "callerid: ".$foo['callerid']."\n");
		fputs( $wuc, 'set: CHANNEL(language)='.$foo['language']."\n");
		fputs( $wuc, "application: ".$foo['application']."\n");
		fputs( $wuc, "data: ".$foo['data']."\n");
		fputs( $wuc, "AlwaysDelete: ".$foo['AlwaysDelete']."\n");
		fputs( $wuc, "Archive: ".$foo['Archive']."\n");
		fclose( $wuc );

		// set time of temp file and move to outgoing
		touch( $tempfile, $foo['time'], $foo['time'] );
		rename( $tempfile, $outfile );
	}
}
