<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cookie;

use Siggy\StandardResponse;
use App\Facades\Auth;
use App\Facades\SiggySession;
use Siggy\Chainmap;
use \groupUtils;
use Siggy\CharacterLocation;
use \Pathfinder;

class ChainmapController extends Controller {

	public $chainmap = null;
	
	public function getChainmap()
	{
		if($this->chainmap == null)
		{
			$chainmapID = (!empty($_REQUEST['chainmap']) ? (int)$_REQUEST['chainmap'] : SiggySession::getAccessData()['active_chain_map'] );
			$this->chainmap = Chainmap::find($chainmapID,SiggySession::getGroup()->id);
		}

		return $this->chainmap;
	}

	public function find_nearest_exits(Request $request)
	{
		$target = isset($_REQUEST['target']) ? trim($_REQUEST['target']) : "";
		$targetCurrentSys = isset($_REQUEST['current_system']) ? intval($_REQUEST['current_system']) : 0;

		$targetID = 0;

		$currentLocation = CharacterLocation::findWithinCutoff(SiggySession::getCharacterId());
		if( $targetCurrentSys && $currentLocation != null )
		{
			$targetID = $currentLocation->system_id;
		}
		else if (!empty($target))
		{
			$targetID = $this->getChainmap()->find_system_by_name($target);
		}

		if( $targetID == 0 || $targetID >= 31000000 )
		{
			return response()->json(['error' => 1, 'errorMsg' => 'Invalid system']);
		}

		$systems = DB::select("( SELECT DISTINCT w.to_system_id as sys_id,ss.name
												FROM wormholes w
												LEFT JOIN solarsystems ss ON (ss.id = w.to_system_id)
												WHERE w.to_system_id < 31000000 AND w.group_id=:group1 AND w.chainmap_id=:chainmap1)
												UNION DISTINCT
											( SELECT DISTINCT w.from_system_id as sys_id, ss.name
											FROM wormholes w
											LEFT JOIN solarsystems ss ON (ss.id = w.from_system_id)
											WHERE w.from_system_id < 31000000 AND w.group_id=:group2 AND w.chainmap_id=:chainmap2)",
											[
												'group1' => SiggySession::getGroup()->id,
												'group2' => SiggySession::getGroup()->id,
												'chainmap1' => $this->getChainmap()->id,
												'chainmap2' => $this->getChainmap()->id,
											]);

		$pather = new Pathfinder();
		$result = array();
		foreach($systems as $system)
		{
			$path = $pather->shortest($targetID, $system->sys_id);
			$path = $path['distance'];

			$result[] = array('system_id' => $system->sys_id, 'system_name' => $system->name, 'number_jumps' => $path );
		}

		usort($result, array('\App\Http\Controllers\ChainmapController','sortResults'));

		return response()->json(['result' => $result]);
	}

	private static function sortResults($a, $b)
	{
		if ($a['number_jumps'] == $b['number_jumps'])
		{
			return 0;
		}
		return ($a['number_jumps'] < $b['number_jumps']) ? -1 : 1;
	}

	public function save()
	{
		$systemData = json_decode($_POST['systemData'], TRUE);
		if( count( $systemData ) > 0 )
		{
			foreach( $systemData as $system )
			{
				if( !isset($system['y']) || $system['y'] < 0 )
				{
					$system['y'] = 0;
				}

				if( !isset($system['x']) || $system['x'] < 0 )
				{
					$system['x'] = 0;
				}

				if( !SiggySession::getGroup()->allow_map_height_expand && $system['y'] > 400 )
				{
					$system['y'] = 380;
				}

				$this->getChainmap()->update_system($system['id'], array('x' => $system['x'], 'y' => $system['y']));
			}

			SiggySession::getGroup()->logAction('editmap', SiggySession::getCharacterName(). " edited the map");

			$this->getChainmap()->rebuild_map_data_cache();
		}

		return response()->json(true);
	}

	private function _hash_array_to_string($arr)
	{
		foreach( $arr as $k => $v )
		{
			$arr[$k] = DB::connection()->getPdo()->quote($v);
		}
		return implode(',', $arr);
	}

	public function connection_delete(Request $request)
	{
		$systemIDs = array();

		$hashes = json_decode($request->getContent(), true);

		$wormholeHashes = $hashes['wormhole_hashes'];
		$stargateHashes = $hashes['stargate_hashes'];
		$jumpbridgeHashes = $hashes['jumpbridge_hashes'];
		$cynoHashes = $hashes['cyno_hashes'];

		if( is_array($cynoHashes) && count($cynoHashes) > 0 )
		{
			$log_message = SiggySession::getCharacterName().' performed a mass delete of the following cynos: ';

			$cynoHashes = $this->_hash_array_to_string($cynoHashes);

			$stargates = DB::select('SELECT s.*, sto.name as to_name, sfrom.name as from_name
														FROM chainmap_cynos s
														INNER JOIN solarsystems sto ON sto.id = s.to_system_id
														INNER JOIN solarsystems sfrom ON sfrom.id = s.from_system_id
														WHERE s.hash IN('.$cynoHashes.') AND s.group_id=:groupID AND s.chainmap_id=:chainmap',[
															'groupID' => SiggySession::getGroup()->id,
															'chainmap' => SiggySession::getAccessData()['active_chain_map']
														]);

			foreach( $stargates as $sg )
			{
				$systemIDs[] = $sg->to_system_id;
				$systemIDs[] = $sg->from_system_id;

				$log_message .= $sg->to_name . ' to ' . $sg->from_name . ', ';
			}
			$systemIDs = array_unique( $systemIDs );

			DB::delete('DELETE FROM chainmap_cynos WHERE hash IN('.$cynoHashes.') AND group_id=:groupID AND chainmap_id=:chainmap',
						[
							'groupID' => SiggySession::getGroup()->id,
							'chainmap' => SiggySession::getAccessData()['active_chain_map']
						]);

			$log_message .= ' from the chainmap "'. $this->getChainmap()->name.'"';
			
			SiggySession::getGroup()->logAction('delwhs', $log_message );
		}

		if( is_array($jumpbridgeHashes) && count($jumpbridgeHashes) > 0 )
		{
			$log_message = SiggySession::getCharacterName().' performed a mass delete of the following jumpbridges: ';

			$jumpbridgeHashes = $this->_hash_array_to_string($jumpbridgeHashes);

			$stargates = DB::select('SELECT s.*, sto.name as to_name, sfrom.name as from_name
														FROM chainmap_jumpbridges s
														INNER JOIN solarsystems sto ON sto.id = s.to_system_id
														INNER JOIN solarsystems sfrom ON sfrom.id = s.from_system_id
														WHERE s.hash IN('.$jumpbridgeHashes.') AND s.group_id=:groupID AND s.chainmap_id=:chainmap',[
															'groupID' => SiggySession::getGroup()->id,
															'chainmap' => SiggySession::getAccessData()['active_chain_map']
														]);

			foreach( $stargates as $sg )
			{
				$systemIDs[] = $sg->to_system_id;
				$systemIDs[] = $sg->from_system_id;

				$log_message .= $sg->to_name . ' to ' . $sg->from_name . ', ';
			}
			$systemIDs = array_unique( $systemIDs );

			DB::delete('DELETE FROM chainmap_jumpbridges WHERE hash IN('.$jumpbridgeHashes.') AND group_id=:groupID AND chainmap_id=:chainmap',
						[
							'groupID' => SiggySession::getGroup()->id,
							'chainmap' => SiggySession::getAccessData()['active_chain_map']
						]);

			$log_message .= ' from the chainmap "'. $this->getChainmap()->name.'"';
			SiggySession::getGroup()->logAction('delwhs', $log_message );
		}

		if( is_array($stargateHashes) && count($stargateHashes) > 0 )
		{
			$log_message = SiggySession::getCharacterName().' performed a mass delete of the following stargates: ';

			$stargateHashes = $this->_hash_array_to_string($stargateHashes);

			$stargates = DB::select('SELECT s.*, sto.name as to_name, sfrom.name as from_name
														FROM chainmap_stargates s
														INNER JOIN solarsystems sto ON sto.id = s.to_system_id
														INNER JOIN solarsystems sfrom ON sfrom.id = s.from_system_id
														WHERE s.hash IN('.$stargateHashes.') AND s.group_id=:groupID AND s.chainmap_id=:chainmap',[
															'groupID' => SiggySession::getGroup()->id,
															'chainmap' => SiggySession::getAccessData()['active_chain_map']
														]);

			foreach( $stargates as $sg )
			{
				$systemIDs[] = $sg->to_system_id;
				$systemIDs[] = $sg->from_system_id;

				$log_message .= $sg->to_name . ' to ' . $sg->from_name . ', ';
			}
			$systemIDs = array_unique( $systemIDs );

			DB::delete('DELETE FROM chainmap_stargates WHERE hash IN('.$stargateHashes.') AND group_id=:groupID AND chainmap_id=:chainmap',
						[
							'groupID' => SiggySession::getGroup()->id,
							'chainmap' => SiggySession::getAccessData()['active_chain_map']
						]);

			$log_message .= ' from the chainmap "'. $this->getChainmap()->name.'"';
			SiggySession::getGroup()->logAction('delwhs', $log_message );
		}


		if( is_array($wormholeHashes) && count($wormholeHashes) > 0 )
		{
			$tmp = $this->getChainmap()->delete_wormholes($wormholeHashes);
			$systemIDs = array_merge( $systemIDs, $tmp );
			$systemIDs = array_unique( $systemIDs );

			groupUtils::deleteLinkedSigWormholes(SiggySession::getGroup()->id, $wormholeHashes);
		}

		if(!empty($systemIDs))
		{
			//update system to make sigs we deleted disappear
			foreach($systemIDs as $id)
			{
				$this->getChainmap()->update_system( $id, ['lastUpdate' => time(),
													'lastActive' => time()]
											);
			}

			$this->getChainmap()->reset_systems( $systemIDs );

			$this->getChainmap()->rebuild_map_data_cache();
		}

		return response()->json(true);
	}

	public function connection_edit()
	{
		$update = array();
		$hash = ($_POST['hash']);

		if( empty($hash) )
		{
			return response()->json(['error' => 1, 'error_message' => 'Missing wormhole hash']);
		}

		$wormhole = DB::selectOne('SELECT * FROM wormholes WHERE hash=:hash AND group_id=:groupID AND chainmap_id=:chainmap',
											[
												'hash' => $hash,
												'groupID' => SiggySession::getGroup()->id,
												'chainmap' => SiggySession::getAccessData()['active_chain_map']
											]);

		if( $wormhole == null )
		{
			return response()->json(['error' => 1, 'error_message' => 'Wormhole does not exist']);
		}

		if( isset($_POST['eol']) )
		{
			$update['eol'] = intval($_POST['eol']);

			if( !$wormhole->eol && $update['eol'] )
			{
				$update['eol_date_set'] = time();
			}
			elseif( $wormhole->eol && !$update['eol'] )
			{
				$update['eol_date_set'] = 0;
			}
		}

		if( isset($_POST['frigate_sized']) )
		{
			$update['frigate_sized'] = intval($_POST['frigate_sized']);
		}

		if( isset($_POST['mass']) )
		{
			$update['mass'] = intval($_POST['mass']);
		}

		if( isset($_POST['wh_type_name']) )
		{
			$update['wh_type_id'] = $this->lookupWHTypeByName($_POST['wh_type_name']);
		}

		$update['updated_at'] = Carbon::now()->toDateTimeString();

		if( !empty($update) )
		{
			DB::table('wormholes')
					->where('hash', '=', $hash)
					->where('group_id', '=', SiggySession::getGroup()->id)
					->where('chainmap_id', '=', SiggySession::getAccessData()['active_chain_map'])
					->update( $update );

			$this->getChainmap()->rebuild_map_data_cache();
		}
		
		return response()->json(true);
	}

	private function lookupWHTypeByName(string $name): int
	{
		$static = DB::selectOne( "SELECT `id` FROM statics WHERE LOWER(name)=?",[strtolower($name)]);
		if( $static != null )
		{
			return $static->id;
		}
		return 0;
	}

	public function connection_add()
	{
		$type = $_POST['type'];

		$fromSys = trim($_POST['fromSys']);
		$fromSysCurrent = intval($_POST['fromSysCurrent']);
		$toSys	= trim($_POST['toSys']);
		$toSysCurrent = intval($_POST['toSysCurrent']);

		$errors = array();
		if( !$fromSysCurrent && empty($fromSys) )
		{
			$errors[] = "No 'from' system selected!";
		}

		if( !$toSysCurrent && empty($toSys) )
		{
			$errors[] = "No 'to' system selected!";
		}

		if( $toSys == $fromSys || ($toSysCurrent && $fromSysCurrent ) )
		{
			$errors[] = "You cannot link a system to itself!";
		}

		$currentLocation = CharacterLocation::findWithinCutoff(SiggySession::getCharacterId());
		$fromSysID = 0;
		if( $fromSysCurrent )
		{
			if( $currentLocation != null )
			{
				$fromSysID = $currentLocation->system_id;
			}
			else
			{
				$errors[] = "'From current location' will not work out of game";
			}
		}
		elseif( !empty($fromSys) )
		{
			$fromSysID = $this->getChainmap()->find_system_by_name($fromSys);
			if( !$fromSysID )
			{
				$errors[] = "The 'from' system could not be looked up by name.";
			}
		}

		$toSysID = 0;
		if( $toSysCurrent )
		{
			if( $currentLocation != null )
			{
				$toSysID = $currentLocation->system_id;
			}
			else
			{
				$errors[] = "'To current location' will not work out of game";
			}
		}
		elseif( !empty($toSys) )
		{
			$toSysID = $this->getChainmap()->find_system_by_name($toSys);
			if( !$toSysID )
			{
				$errors[] = "The 'to' system could not be looked up by name.";
			}
		}

		if( !$fromSysID )
		{
			$errors[] = "The 'to' system cannot be blank.";
		}

		if( !$toSysID )
		{
			$errors[] = "The 'to' system cannot be blank.";
		}

		if( $fromSysID == $toSysID )
		{
			$errors[] = "You cannot link a system to itself!";
		}

		if( $type == 'wormhole' )
		{
			$whTypeName = $_POST['wh_type_name'];
			$whTypeID = 0;
			if( !empty($whTypeName) )
			{
				$whTypeID = $this->lookupWHTypeByName($whTypeName);
				if(!$whTypeID)
				{
					$errors[] = "Invalid WH Type Name";
				}
			}

			$whHash = Chainmap::whHashByID($fromSysID , $toSysID);

			$connection = DB::selectOne("SELECT `hash` FROM wormholes WHERE hash=:hash AND group_id=:group AND chainmap_id=:chainmap",[
								'hash' => $whHash,
								'group' => SiggySession::getGroup()->id,
								'chainmap' => SiggySession::getAccessData()['active_chain_map']
								]);
			if( $connection != null )
			{
				$errors[] = "Wormhole already exists";
			}

			if( count($errors) > 0 )
			{
				return response()->json(['success' => 0, 'dataErrorMsgs' => $errors ]);
			}

			$eol = intval($_POST['eol']);
			$mass = intval($_POST['mass']);

			$this->getChainmap()->add_system_to_map($fromSysID, $toSysID, $eol, $mass, $whTypeID);

			$message = SiggySession::getCharacterName().' added wormhole manually between system IDs' . $fromSysID . ' and ' . $toSysID;

			SiggySession::getGroup()->logAction('addwh', $message );
		}
		else if( $type == 'stargate' )
		{
			if( count($errors) > 0 )
			{
				return response()->json(['success' => 0, 'dataErrorMsgs' => $errors ]);
			}

			$this->getChainmap()->add_stargate_to_map($fromSysID, $toSysID);
		}
		else if( $type == 'jumpbridge' )
		{
			if( count($errors) > 0 )
			{
				return response()->json(['success' => 0, 'dataErrorMsgs' => $errors ]);
			}

			$this->getChainmap()->add_jumpbridge_to_map($fromSysID, $toSysID);
		}
		else if( $type == 'cyno' )
		{
			if( count($errors) > 0 )
			{
				return response()->json(['success' => 0, 'dataErrorMsgs' => $errors ]);
			}

			$this->getChainmap()->add_cyno_to_map($fromSysID, $toSysID);
		}

		return response()->json(['success' => 1]);
	}

	public function switch()
	{
		$desired_chainmap = intval($_POST['chainmap_id']);
		$selected_id = 0;
		$default_id = 0;
		foreach(SiggySession::accessibleChainMaps() as $c)
		{
			if( $c->id == $desired_chainmap )
			{
				$selected_id = $c->id;
			}
		}

		if( $selected_id )
		{
			Cookie::queue('chainmap', $selected_id);
		}

		if( !$selected_id )
		{
			throw new Exception("Selected chain map not found!");
		}
	}

	public function connections()
	{
		$data = $this->getChainmap()->get_map_cache();

		$output = [
					'connections' => [],
					'systems' => $data['systems']
					];

		foreach($data['wormholes'] as $c)
		{
			$c->type = 'wormhole';
			$output['connections'][] = $c;
		}

		foreach($data['cynos'] as $c)
		{
			$c->type = 'cyno';
			$output['connections'][] = $c;
		}

		foreach($data['stargates'] as $c)
		{
			$c->type = 'stargate';
			$output['connections'][] = $c;
		}

		foreach($data['jumpbridges'] as $c)
		{
			$c->type = 'jumpbridge';
			$output['connections'][] = $c;
		}

		return response()->json($output);
	}

	public function autocomplete_wh()
	{
		$q = '';
		if ( isset($_GET['q']) )
		{
			$q = trim(strtolower($_GET['q']));
		}

		if ( empty($q) )
		{
			return;
		}

		$output = array();
		$customsystems = DB::select('SELECT solarsystems.id, 
											solarsystems.name,
											activesystems.displayName as display_name,
											r.regionName as region_name,
											solarsystems.sysClass as class
										FROM activesystems
										LEFT JOIN solarsystems ON(activesystems.systemID=solarsystems.id)
										LEFT JOIN eve_map_regions r ON(solarsystems.region=r.regionID)
										WHERE displayName like :query
										AND groupID=:group
										AND chainmap_id=:chainmap',
										[
											'query' => $q.'%',
											'group' => SiggySession::getGroup()->id,
											'chainmap' => SiggySession::getAccessData()['active_chain_map']
										]);

		foreach($customsystems as $system)
		{
			$output[] = array('id' => (int)$system->id,
								'name' => $system->name,
								'display_name' => $system->display_name,
								'region_name' => $system->region_name );
		}

		return response()->json($output);
	}

	public function jump_log()
	{
		if( !isset($_GET['wormhole_hash']) || empty( $_GET['wormhole_hash'] ) )
		{
			return response()->json(['error' => 1, 'errorMsg' => 'Missing wormhole_hash parameter.']);
		}

		$hash = $_GET['wormhole_hash'];

		/* Include all the group tracked jumps from all chainmaps since this is important not to trap oneself out */
		$jumpData = array();
		$jumpData  = DB::select("SELECT wt.ship_id, c.name as character_name, wt.character_id, 
													wt.origin_id, 
													wt.destination_id, 
													wt.jumped_at, 
														s.name as shipName, 
														s.mass, 
														s.class as shipClass
													FROM wormhole_jumps wt
													LEFT JOIN ships as s ON s.id = wt.ship_id
													JOIN characters c ON (c.id = wt.character_id)
													WHERE wt.group_id = :groupID AND wt.wormhole_hash = :hash
													ORDER BY wt.jumped_at DESC",[
														'groupID' => SiggySession::getGroup()->id,
														'hash' => $hash
													]);

		$totalMass = 0;
		foreach( $jumpData as $jump )
		{
			$totalMass += $jump->mass;
		}

		$output['totalMass'] = $totalMass;
		$output['jumpItems'] = $jumpData;

		return response()->json($output);
	}
}
