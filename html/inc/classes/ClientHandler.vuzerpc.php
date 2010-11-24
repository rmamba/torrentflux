<?php

/* $Id$ */

/*******************************************************************************

 LICENSE

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License (GPL)
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

 To read the license please visit http://www.gnu.org/copyleft/gpl.html

*******************************************************************************/

/**
 * class ClientHandler for vuze xmwebui rpc
 */
class ClientHandlerVuzeRPC extends ClientHandler
{

	// =========================================================================
	// ctor
	// =========================================================================

	/**
	 * ctor
	 */
	function ClientHandlerVuzeRPC() {
		global $cfg;
		
		$this->type = "torrent";
		$this->client = "azureus";
		$this->binSystem = "php";
		$this->binSocket = "php";
		$this->binClient = "php";
		$this->vuzerpcBin = $cfg["docroot"]."bin/clients/vuzerpc/vuzerpc.php";
		
	}

	// =========================================================================
	// public methods
	// =========================================================================

	/**
	 * starts a client
	 *
	 * @param $transfer name of the transfer
	 * @param $interactive (boolean) : is this a interactive startup with dialog ?
	 * @param $enqueue (boolean) : enqueue ?
	 */
	function start($transfer, $interactive = false, $enqueue = false) {
		global $cfg;

		// set vars
		$this->_setVarsForTransfer($transfer);

		// log
		$this->logMessage($this->client."-start : ".$transfer."\n", true);

		// VuzeRPC
		require_once("inc/classes/VuzeRPC.php");

		$this->vuze = VuzeRPC::getInstance();
		$vuze = & $this->vuze;

		// do special-pre-start-checks
		if (!VuzeRPC::isRunning()) {
			$msg = "VuzeRPC not reacheable, cannot start transfer ".$transfer;
			AuditAction($cfg["constants"]["error"], $msg);
			$this->logMessage($msg."\n", true);
			
			// write error to stat
			$sf = new StatFile($this->transfer, $this->owner);
			$sf->time_left = 'Error: VuzeRPC down';
			$sf->write();
			
			// return
			return false;
		}

		// init starting of client
		$this->_init($interactive, $enqueue, true, false);

		// only continue if init succeeded (skip start / error)
		if ($this->state != CLIENTHANDLER_STATE_READY) {
			if ($this->state == CLIENTHANDLER_STATE_ERROR) {
				$msg = "Error after init (".$transfer.",".$interactive.",".$enqueue.",true,".$cfg['enable_sharekill'].")";
				array_push($this->messages , $msg);
				$this->logMessage($msg."\n", true);
			}
			// return
			return false;
		}

		// build the command-string
		$content  = $cfg['user']."\n";
		$content .= $this->savepath."\n";
		$content .= $this->rate."\n";
		$content .= $this->drate."\n";
		$content .= $this->maxuploads."\n";
		$content .= $this->superseeder."\n";
		$content .= $this->runtime."\n";
		$content .= $this->sharekill_param."\n";
		$content .= $this->minport."\n";
		$content .= $this->maxport."\n";
		$content .= $this->maxcons."\n";
		$content .= $this->rerequest;

		$this->command  = "echo -e ".tfb_shellencode($content)." > ".tfb_shellencode($cfg["path"].'.vuzerpc/run/'.$transfer);
		$this->command .= " && ";
		$this->command .= "echo r > ".tfb_shellencode($cfg["path"].'.vuzerpc/vuzerpc.cmd');

		if ($this->isWinOS()) {
			file_put_contents($cfg["path"].'.vuzerpc/run/'.$transfer, $content);
			$this->command = "echo r > ".tfb_shellencode($cfg["path"].'.vuzerpc/vuzerpc.cmd');
		}

		if (!is_dir($cfg["path"].'.vuzerpc'))
			mkdir($cfg["path"].'.vuzerpc',0775);

		if (!is_dir($cfg["path"].'.vuzerpc/run'))
			mkdir($cfg["path"].'.vuzerpc/run',0775);

		// start the client
		$this->_start();

		$req = $vuze->torrent_add_tf($transfer,$content);
		//file_put_contents($cfg["path"].'.vuzerpc/'.$transfer.".log",serialize($req));

		if (is_int($req)) {
			$id = $req;
			$tfs = $vuze->torrent_get_tf(array($id));
			$tf = array_pop($tfs);

			$sf = new StatFile($transfer);
			$sf->running = $tf['running'];
			$sf->percent_done=$tf['percentDone'];
			$sf->peers = $tf['cons'];
			$sf->time_left = $tf['eta'];
			$sf->down_speed = $tf['speedDown'];
			$sf->up_speed = $tf['speedUp'];
			
			$sf->write();
		}

		$this->updateStatFiles();

		// state
		$this->state = CLIENTHANDLER_STATE_READY;
	}

	/**
	 * stops a client
	 *
	 * @param $transfer name of the transfer
	 * @param $kill kill-param (optional)
	 * @param $transferPid transfer Pid (optional)
	 */
	function stop($transfer, $kill = false, $transferPid = 0) {
		global $cfg;

		// set vars
		$this->_setVarsForTransfer($transfer);

		// VuzeRPC
		require_once("inc/classes/VuzeRPC.php");

		if (!isset($this->vuze))
			$this->vuze = new VuzeRPC($cfg);

		$vuze = & $this->vuze;

		// only if fluazu running and transfer exists in fluazu
		if (!VuzeRPC::isRunning()) {
			array_push($this->messages , "VuzeRPC not running, cannot stop transfer ".$transfer);
			return false;
		}
		
		$hash = getTransferHash($transfer);
		if (!VuzeRPC::transferExists($hash)) {
			$msg = "transfer ".$transfer." does not exist in vuze, deleting pid file (stop).";
			$this->logMessage($msg."\n", true);
			$this->cleanStoppedStatFile($transfer);
			//return false;
		}

		// log
		$this->logMessage($this->client."-stop : ".$transfer."\n", true);
		
		if (!$vuze->torrent_stop_tf($hash)) {
			$msg = "transfer ".$transfer." does not exist in vuze, deleting pid file (stop).";
			$this->logMessage($msg."\n", true);
			$this->cleanStoppedStatFile();
			AuditAction($cfg["constants"]["debug"], $this->client."-stop : error $hash $transfer.");
		}

		// stop the client
		//$this->_stop($kill, $transferPid);
		
		// flag the transfer as stopped (in db)
		stopTransferSettings($this->transfer);
		// set transfers-cache
		cacheTransfersSet();
		
		@unlink($this->transferFilePath.".pid");
		
		$this->updateStatFiles();
	}

	/**
	 * deletes a transfer
	 *
	 * @param $transfer name of the transfer
	 * @return boolean of success
	 */
	function delete($transfer) {
		// set vars
		$this->_setVarsForTransfer($transfer);
		// FluAzu
		require_once("inc/classes/VuzeRPC.php");
		
		$hash = getTransferHash($transfer);
		
		// only if transfer exists in fluazu
		if (VuzeRPC::transferExists($hash)) {
			// only if fluazu running
			if (!FluAzu::isRunning()) {
				array_push($this->messages , "fluazu not running, cannot delete transfer ".$transfer);
				return false;
			}
			else
			// remove from vuze
			if (!VuzeRPC::delTransfer($hash)) {
				array_push($this->messages , $this->client.": error when deleting transfer ".$transfer." :");
				$this->messages = array_merge($this->messages, FluAzu::getMessages());
				return false;
			}
		} else {
			$msg = "transfer ".$transfer." does not exist in vuze, deleting pid file (delete).";
			$this->logMessage($msg."\n", true);
			unlink($this->transferFilePath.".pid");
		}
		$this->updateStatFiles();
		
		// delete
		return $this->_delete();
	}

	/**
	 * gets current transfer-vals of a transfer
	 *
	 * @param $transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferCurrent($transfer) {
		global $db, $transfers;
		$retVal = array();
		// transfer from stat-file
		$sf = new StatFile($transfer);
		$retVal["uptotal"] = $sf->uptotal;
		$retVal["downtotal"] = $sf->downtotal;
		// transfer from db
		$torrentId = getTransferHash($transfer);
		$sql = "SELECT uptotal,downtotal FROM tf_transfer_totals WHERE tid = ".$db->qstr($torrentId);
		$result = $db->Execute($sql);
		$row = $result->FetchRow();
		if (!empty($row)) {
			$retVal["uptotal"] -= $row["uptotal"];
			$retVal["downtotal"] -= $row["downtotal"];
		}
		return $retVal;
	}

	/**
	 * gets current transfer-vals of a transfer. optimized version
	 *
	 * @param $transfer
	 * @param $tid of the transfer
	 * @param $sfu stat-file-uptotal of the transfer
	 * @param $sfd stat-file-downtotal of the transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferCurrentOP($transfer, $tid, $sfu, $sfd) {
		global $transfers;
		$retVal = array();
		$retVal["uptotal"] = (isset($transfers['totals'][$tid]['uptotal']))
			? $sfu - $transfers['totals'][$tid]['uptotal']
			: $sfu;
		$retVal["downtotal"] = (isset($transfers['totals'][$tid]['downtotal']))
			? $sfd - $transfers['totals'][$tid]['downtotal']
			: $sfd;
		return $retVal;
	}

	/**
	 * gets total transfer-vals of a transfer
	 *
	 * @param $transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferTotal($transfer) {
		global $transfers;
		// transfer from stat-file
		$sf = new StatFile($transfer);
		return array("uptotal" => $sf->uptotal, "downtotal" => $sf->downtotal);
	}

	/**
	 * gets total transfer-vals of a transfer. optimized version
	 *
	 * @param $transfer
	 * @param $tid of the transfer
	 * @param $sfu stat-file-uptotal of the transfer
	 * @param $sfd stat-file-downtotal of the transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferTotalOP($transfer, $tid, $sfu, $sfd) {
		return array("uptotal" => $sfu, "downtotal" => $sfd);
	}

	/**
	 * set upload rate of a transfer
	 *
	 * @param $transfer
	 * @param $uprate
	 * @param $autosend
	 */
	function setRateUpload($transfer, $uprate, $autosend = false) {
		// set rate-field
		$this->rate = $uprate;
		// add command
		CommandHandler::add($transfer, "u".$uprate);
		// send command to client
		if ($autosend)
			CommandHandler::send($transfer);
	}

	/**
	 * set download rate of a transfer
	 *
	 * @param $transfer
	 * @param $downrate
	 * @param $autosend
	 */
	function setRateDownload($transfer, $downrate, $autosend = false) {
		// set rate-field
		$this->drate = $downrate;
		// add command
		CommandHandler::add($transfer, "d".$downrate);
		// send command to client
		if ($autosend)
			CommandHandler::send($transfer);
	}

	/**
	 * set runtime of a transfer
	 *
	 * @param $transfer
	 * @param $runtime
	 * @param $autosend
	 * @return boolean
	 */
	function setRuntime($transfer, $runtime, $autosend = false) {
		// set runtime-field
		$this->runtime = $runtime;
		// add command
		CommandHandler::add($transfer, "r".(($this->runtime == "True") ? "1" : "0"));
		// send command to client
		if ($autosend)
			CommandHandler::send($transfer);
	}

	/**
	 * set sharekill of a transfer
	 *
	 * @param $transfer
	 * @param $sharekill
	 * @param $autosend
	 * @return boolean
	 */
	function setSharekill($transfer, $sharekill, $autosend = false) {
		// set sharekill
		$this->sharekill = $sharekill;
		// add command
		CommandHandler::add($transfer, "s".$this->sharekill);
		// send command to client
		if ($autosend)
			CommandHandler::send($transfer);
			// return
			return true;
	}

	/**
	 * clean stat file
	 *
	 * @param $transfer
	 * @return boolean
	 */
	function cleanStoppedStatFile($transfer) {
		$this->updateStatFiles();

		@unlink($this->transferFilePath.".pid");
		$sf = new StatFile($this->transfer, $this->owner);
		$sf->running = "0";
		$sf->percent_done=100;
		$sf->peers = "";
		$sf->time_left = "Stopped";
		$sf->down_speed = "";
		$sf->up_speed = "";
		//var_dump($sf);die();
		return $sf->write();
	}


	function updateStatFiles() {
		global $cfg, $db;
		
		$this->vuze = VuzeRPC::getInstance();
		$vuze = & $this->vuze;

		// do special-pre-start-checks
		if (!VuzeRPC::isRunning()) {
			return;
		}

		// log
		$this->logMessage($this->client."-stat\n", true);

		$tfs = $vuze->torrent_get_tf();
		//file_put_contents($cfg["path"].'.vuzerpc/'."updateStatFiles.log",serialize($tfs));
		
		if (empty($tfs))
			return;

		$hashes = array("''");
		foreach ($tfs as $name => $t)
			$hashes[$t['hashString']] = "'".$t['hashString']."'";

		$sql = "SELECT hash, transfer FROM tf_transfers WHERE type='torrent' AND client='azureus' AND hash IN (".implode(',',$hashes).")";
		$recordset = $db->Execute($sql);
		$hashes=array();
		while (list($hash, $transfer) = $recordset->FetchRow()) {
			$hashes[$hash] = $transfer;
		}

		//convertTime
		require_once("inc/functions/functions.core.php");

		foreach ($tfs as $name => $t) {
			if (isset($hashes[$t['hashString']])) {

				$transfer = $hashes[$t['hashString']];
				//file_put_contents($cfg["path"].'.vuzerpc/'."updateStatFiles4.log",serialize($t));
				$sf = new StatFile($transfer);
				$sf->running = $t['running'];

				if ($t['eta'] < -1) {
					$t['eta'] = "Finished in ".convertTime(abs($t['eta']));
				} elseif ($t['eta'] > 0) {
					$t['eta'] = convertTime($t['eta']);
				} elseif ($t['eta'] == -1) {
					$t['eta'] = "";
				}
				$sf->time_left = $t['eta'];
				
				if ($sf->running) {
				
					$sf->percent_done = max($t['percentDone'],$t['sharing']);
					
					if ($t['status'] != 9 && $t['status'] != 5) {
						$sf->down_speed = GetSpeedValue($t['speedDown']);
						$sf->up_speed = GetSpeedValue($t['speedUp']);
						$sf->peers = $t['peers'];
						$sf->seeds = $t['seeds'];
					} else {
						
					}
					if ($t['status'] == 8) {
						$sf->percent_done = 100;
						$sf->down_speed = "";
					}
					if ($t['status'] == 9) {
						$sf->percent_done = 100;
						$sf->up_speed = "";
						$sf->down_speed = "";
					}
				
				} else {
					$sf->down_speed = "";
					$sf->up_speed = "";
					$sf->peers = "";
					if ($sf->percent_done >= 100)
						$sf->time_left = "Download Succeeded!";
				}
				if ($t['downTotal'] > 0 || $t['upTotal'] > 0) {
					$sf->downtotal = formatBytesTokBMBGBTB($t['downTotal']);
					$sf->uptotal = formatBytesTokBMBGBTB($t['upTotal']);
				}
				
				if ($sf->seeds = -1);
					$sf->seeds = '';
				if ($sf->size > 0) {
					$sf->write();
				}
			}
		}
	}


}

?>