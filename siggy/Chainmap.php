<?php

namespace Siggy;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

use Siggy\ActiveSystem;
use App\Facades\SiggySession;
use \Group;
use \stdClass;

class Chainmap extends Model {

	public $timestamps = false;
	protected $primaryKey = 'id';

	protected $fillable = [
		'name',
		'homesystems',
		'homesystems_ids',
		'skip_purge_home_sigs',
		'fixed',
		'default',
		'group_id'
	];
	
	protected $hidden = [
		'group_id'
	];

	public static function find(int $chainmapId, int $groupId)
	{		
		$map = self::where('id',$chainmapId)
				->where('group_id', $groupId)
				->first();

		return $map;
	}


	public static function findAllByGroup(int $groupId)
	{		
		$maps = self::where('group_id', $groupId)
					->get();

		return $maps;
	}

	public function get_map_cache()
	{
		$cache_name = 'map_data_cache-'.$this->id;

		if( $map_data = Cache::get( $cache_name, FALSE ) )
		{
			return $map_data;
		}
		else
		{
			$map_data = $this->rebuild_map_data_cache();

			return $map_data;
		}
	}

	public function get_map_data()
	{
		$additionalSystems = $this->get_home_systems();

		$data = array();

		$wormholes = DB::select("SELECT w.`hash`,
														w.to_system_id,
														w.from_system_id,
			 											w.eol,
														w.mass,
														w.eol_date_set,
														w.frigate_sized,
														w.created_at,
														w.updated_at,
														s.`mass` as wh_mass,
														s.`jump_mass` as wh_jump_mass,
														s.`lifetime` as wh_lifetime,
														s.`regen` as wh_regen,
														s.`name` as wh_name
			 										FROM wormholes AS w
													LEFT JOIN statics AS s ON(s.id=w.wh_type_id)
													WHERE group_id=:group
													AND chainmap_id=:chainmap",[
														'group' => $this->group_id,
														'chainmap' => $this->id
													]);

		$wormholes = new Collection($wormholes);
		$wormholes = $wormholes->keyBy('hash')->all();
		$systemsToPoll = array();
		$wormholeHashes = array();
		foreach( $wormholes as $k => $wormhole )
		{
			/* Include all the group tracked jumps from all chainmaps since this is important not to trap oneself out */
			$jumpTotal  = DB::selectOne("SELECT COALESCE(SUM(s.mass),0) as total
														FROM wormhole_jumps wt
														LEFT JOIN ships as s ON s.id = wt.ship_id
														WHERE wt.group_id = :groupID AND wt.wormhole_hash = :hash",
														[
															'groupID' => $this->group_id,
															'hash' => $wormhole->hash
														]);

			$wormholes[$k]->total_tracked_mass = $jumpTotal->total;

			$systemsToPoll[] = $wormhole->to_system_id;
			$systemsToPoll[] = $wormhole->from_system_id;
			$wormholeHashes[] = $wormhole->hash;
		}
		$data['wormholes'] = $wormholes;

		/* Stargates */
		$stargates = DB::table('chainmap_stargates')
						->where('group_id', $this->group_id)
						->where('chainmap_id', $this->id)
						->get()
						->keyBy('hash')
						->all();

		$data['stargates'] = $stargates;

		foreach( $stargates as $stargate )
		{
			$systemsToPoll[] = $stargate->to_system_id;
			$systemsToPoll[] = $stargate->from_system_id;
		}

		/* Jump bridges */
		$jumpbridges = DB::table('chainmap_jumpbridges')
						->where('group_id', $this->group_id)
						->where('chainmap_id', $this->id)
						->get()
						->keyBy('hash')
						->all();
		$data['jumpbridges'] = $jumpbridges;

		foreach( $jumpbridges as $jumpbridge )
		{
			$systemsToPoll[] = $jumpbridge->to_system_id;
			$systemsToPoll[] = $jumpbridge->from_system_id;
		}

		/* Cynos */
		$cynos = DB::table('chainmap_cynos')
						->where('group_id', $this->group_id)
						->where('chainmap_id', $this->id)
						->get()
						->keyBy('hash')
						->all();
		$data['cynos'] = $cynos;

		foreach( $cynos as $cyno )
		{
			$systemsToPoll[] = $cyno->to_system_id;
			$systemsToPoll[] = $cyno->from_system_id;
		}

		/* Systems */

		$data['systems'] = array();
		$data['systemIDs'] = array();

		$systemsToPoll = array_unique($systemsToPoll);


		if( $additionalSystems != null && is_array($additionalSystems) && count($additionalSystems) > 0 )
		{
			$systemsToPoll = array_merge($systemsToPoll, $additionalSystems);
		}

		if( count($systemsToPoll) > 0 )
		{
			$systemsToPoll = implode(',', $systemsToPoll);

			$killCutoff = Carbon::now()->subHours(2);

			$chainMapSystems = DB::select("SELECT ss.name,
															ss.id as systemID,
															COALESCE(sa.displayName,'') as displayName,
															COALESCE(sa.x,0) as x,
															COALESCE(sa.y,10) as y,
															COALESCE(sa.activity,0) as activity,
															COALESCE(sa.inUse,0) as inUse,
															COALESCE(sa.rally,0) as rally,
															COALESCE(sa.hazard,0) as hazard,
															ss.sysClass,
															ss.effect,
															r.regionName as region_name,
															(SELECT SUM(ship_kills) FROM solarsystem_kills WHERE system_id=ss.id AND date_start >= :kill_cutoff1) as kills_in_last_2_hours,
															(SELECT SUM(npc_kills) FROM solarsystem_kills WHERE system_id=ss.id AND date_start >= :kill_cutoff2) as npcs_kills_in_last_2_hours
															FROM solarsystems ss
															LEFT OUTER JOIN activesystems sa ON (ss.id = sa.systemID AND sa.groupID=:group AND sa.chainmap_id=:chainmap)
															INNER JOIN eve_map_regions r ON(r.regionID=ss.region)
															WHERE ss.id IN(".$systemsToPoll.")  ORDER BY ss.id ASC",[
																'group' => $this->group_id,
																'chainmap' => $this->id,
																'kill_cutoff1' =>$killCutoff,
																'kill_cutoff2' =>$killCutoff,
															]);

			$chainMapSystems = new Collection($chainMapSystems);
			$chainMapSystems = $chainMapSystems->keyBy('systemID')->all();

			foreach( $chainMapSystems as &$sys )
			{
				if( in_array( $sys->systemID, $additionalSystems ) )
				{
						$sys->special = 1;
				}
				else
				{
						$sys->special = 0;
				}
			}
			$data['systems'] = $chainMapSystems;
			$data['systemIDs'] = explode(',', $systemsToPoll);
		}
		$data['wormholeHashes'] = $wormholeHashes;
		$data['updateTime'] = time();

		return $data;
	}

	public function rebuild_map_data_cache()
	{
		$cache_name = 'map_data_cache-'.$this->id;

		$map_data = $this->get_map_data();

		Cache::put($cache_name, $map_data, 1800);

		return $map_data;
	}

	public function get_home_systems()
	{
		$homeSystems = array();
		if( $this->homesystems_ids != '' )
		{
			$homeSystems = explode(',', $this->homesystems_ids);
		}

		return $homeSystems;
	}

	public function get_connected_system($system)
	{
		return DB::select("SELECT x,y FROM activesystems
							WHERE groupID=:group1 AND
							chainmap_id=:chain1 AND
							systemID IN (SELECT
											CASE WHEN w.to_system_id=:sys1
												THEN w.from_system_id
												ELSE w.to_system_id
											END AS `connected_system`
											FROM wormholes w
											WHERE (w.to_system_id=:sys2 OR w.from_system_id=:sys3)
											AND w.group_id=:group2 AND w.chainmap_id=:chain2)",[
												'sys1' => intval($system),
												'sys2' => intval($system),
												'sys3' => intval($system),
												'group1' => $this->group_id,
												'group2' => $this->group_id,
												'chain1' => $this->id,
												'chain2' => $this->id
											]);
	}

	public function system_is_mapped( $system )
	{
		$exists = DB::select("SELECT `hash` 
							FROM wormholes 
							WHERE (from_system_id=:system1 OR to_system_id=:system2) 
								AND group_id=:group AND chainmap_id=:chainmap",
							[
								'system1' => $system,
								'system2' => $system,
								'group' => SiggySession::getGroup()->id,
								'chainmap' => SiggySession::getAccessData()['active_chain_map']
							]);

		if( isset($exists->hash)  )
		{
			return true;
		}

		return false;
	}

	public function delete_all_system_connections( $system )
	{
		DB::delete('DELETE FROM wormholes WHERE (to_system_id = :system1 OR from_system_id = :system2) AND group_id=:groupID AND chainmap_id=:chainmap',
		[
			'groupID' => SiggySession::getGroup()->id,
			'chainmap' => SiggySession::getAccessData()['active_chain_map'],
			'system1' => $system,
			'system2' => $system
		]);
	}

	public function add_system_to_map($sys1, $sys2, $eol=0, $mass=0, $wh_type_id = 0)
	{
		$whHash = self::whHashByID($sys1 , $sys2);

		$this->_placeSystems($sys1,$sys2);

		try
		{
			$insert = array('hash' => $whHash,
							'to_system_id' => $sys1,
							'from_system_id' => $sys2,
							'eol' => $eol,
							'mass' => $mass,
							'group_id' => $this->group_id,
							'wh_type_id' => $wh_type_id,
							'chainmap_id' => $this->id,
							'last_jump' => time(),
							'created_at' => Carbon::now()->toDateTimeString()
							);

			DB::table('wormholes')->insert($insert);
		}
		catch( Exception $e )
		{
			//do nothing
			return;
		}

		$this->rebuild_map_data_cache();
	}

	private function _placeSystems($sys1, $sys2)
	{
		$sys1Connections = $this->get_connected_system($sys1);
		$sys2Connections = $this->get_connected_system($sys2);

		$sys1Count = count($sys1Connections);
		$sys2Count = count($sys2Connections);

		if( $sys1Count == 0 && $sys2Count != 0 )
		{
			$this->_placeSystem($sys2,$sys2Connections, $sys1);
		}
		else if( $sys2Count == 0 && $sys1Count != 0 )
		{
			//sys2 is "new"
			$this->_placeSystem($sys1,$sys1Connections, $sys2);
		}
		else if( $sys1Count == 0 && $sys2Count == 0 )
		{
			//both are new
			//we just map one
			//ensure its not a home system, those stay fixed lol
			$homeSystems = $this->get_home_systems();
			if( in_array($sys2, $homeSystems) && !in_array($sys1, $homeSystems) )
			{
				//sys2 is home system
				//map sys1 instead
				$this->_placeSystem($sys2,$sys2Connections,$sys1);
			}
			else if( in_array($sys1, $homeSystems) && !in_array($sys2, $homeSystems) )
			{
				//sys1 is home system
				//map sys2 instead
				$this->_placeSystem($sys1,$sys1Connections,$sys2);
			}
			else
			{
				//don't mess with the system positions if both are home systems
			}
		}
	}
	

	public static function coordsToBB($x,$y)
	{
		return array( 'left' => $x,
					  'top' => $y,
					  'width' => 78,
					  'height' => 38,
					  'right' => $x+78,
					  'bottom' => $y+38 );
	}

	public static function whHashByID($to, $from)
	{
		if( $to < $from )
		{
			return md5( intval($to) . intval($from) );
		}
		else
		{
			return md5( intval($from) . intval($to) );
		}
	}

	public function add_stargate_to_map($sys1, $sys2)
	{
		$whHash = self::whHashByID($sys1, $sys2);

		$this->_placeSystems($sys1,$sys2);
		try
		{
			$insert = array('hash' => $whHash,
							'to_system_id' => $sys1,
							'from_system_id' => $sys2,
							'group_id' => $this->group_id,
							'chainmap_id' => $this->id,
							'created_at' => Carbon::now()->toDateTimeString()
							);

			DB::table('chainmap_stargates')->insert($insert);
		}
		catch( Exception $e )
		{
			//do nothing
			throw new Exception("Stargate already exists");
			return;
		}

		$this->rebuild_map_data_cache();
	}

	public function add_jumpbridge_to_map($sys1, $sys2)
	{
		$whHash = self::whHashByID($sys1 , $sys2);

		$this->_placeSystems($sys1,$sys2);
		try
		{
			$insert = array('hash' => $whHash,
							'to_system_id' => $sys1,
							'from_system_id' => $sys2,
							'group_id' => $this->group_id,
							'chainmap_id' => $this->id,
							'created_at' => Carbon::now()->toDateTimeString()
							);

			DB::table('chainmap_jumpbridges')->insert($insert);
		}
		catch( Exception $e )
		{
			//do nothing
			throw new Exception("Jumpbridge already exists");
			return;
		}

		$this->rebuild_map_data_cache();
	}

	public function add_cyno_to_map($sys1, $sys2)
	{
		$whHash = self::whHashByID($sys1, $sys2);

		$this->_placeSystems($sys1,$sys2);
		try
		{
			$insert = array('hash' => $whHash,
							'to_system_id' => $sys1,
							'from_system_id' => $sys2,
							'group_id' => $this->group_id,
							'chainmap_id' => $this->id,
							'created_at' => Carbon::now()->toDateTimeString()
							);

			DB::table('chainmap_cynos')->insert($insert);
		}
		catch( Exception $e )
		{
			//do nothing
			throw new Exception("Stargate already exists");
			return;
		}

		$this->rebuild_map_data_cache();
	}

	private function _placeSystem($originSys, $originSystems, $systemToBePlaced)
	{
		$sysPos = NULL;
		$sysData = ActiveSystem::find($this->group_id,$this->id,$originSys);

		if($sysData == null)
		{
			//TODO FIXME
			$sysData = new stdClass();
			$sysData->x = 0;
			$sysData->y = 0;
		}

		$spots = self::generatePossibleSystemLocations($sysData->x, $sysData->y);

		foreach($spots as $spot)
		{
			$intersect = false;
			foreach($originSystems as $sys)
			{
				if( self::doBoxesIntersect(self::coordsToBB($spot['x'],$spot['y']), self::coordsToBB($sys->x,$sys->y)) )
				{
					$intersect = true;
				}
			}

			if( !$intersect )
			{
				//winnar!
				$sysPos = $spot;
				break;
			}
		}

		//if we didnt find a spot, just use the first one and call it a day
		if( $sysPos == NULL )
		{
			if( isset($spots[0]) )
			{
				$sysPos = $spots[0];
			}
			else
			{
				$sysPos['x'] = 100;
				$sysPos['y'] = 100;
			}
		}

		$this->update_system($systemToBePlaced, array( 'x' => intval($sysPos['x']),
															'y' => intval($sysPos['y']),
															'lastUpdate' => time() )
									);
	}

	public function update_system($system_id, $data)
	{
		$params = [
						'systemID' => $system_id,
						'groupID' => $this->group_id,
						'chainmap_id' => $this->id
					];
		
		foreach($data as $k => $v)
		{
			if(!isset($params[$k]))
			{
				$params[$k] = $v;
			}
		}

		ActiveSystem::insertOnDuplicateKey($params);
	}


	public function reset_systems($system_ids)
	{
		if( !is_array($system_ids) || !count($system_ids) )
		{
			return;
		}

		$home_systems = $this->get_home_systems();

		//only enable this "Feature" if we have a home system, a.k.a. RAGE INSURANCE
		if( !count($home_systems)	)
		{
			return;
		}

		foreach( $system_ids as $sys_id )
		{
			if( !in_array($sys_id, $home_systems) )
			{
				$check = DB::selectOne('SELECT * FROM	 wormholes 
													WHERE group_id=:groupID 
														AND chainmap_id=:chain_map 
														AND (to_system_id=:tosys OR from_system_id=:fromsys)',
														[
															'groupID' => $this->group_id,
															'chain_map' => $this->id,
															'tosys' => $sys_id,
															'fromsys' => $sys_id,
														]);

				if( $check == null )
				{
					$this->update_system($sys_id, array('displayName' => '', 'inUse' => 0, 'activity' => 0 ) );
				}
			}
		}
	}

	public function find_system_by_name($name)
	{
		if( empty($name) )
		{
			return 0;
		}

		$tmp = DB::selectOne("SELECT systemID,displayName 
										FROM activesystems 
										WHERE groupID=:groupID 
											AND chainmap_id=:chainmap 
											AND displayName LIKE :name",[ 
													'name' => $name,
													'groupID' => $this->group_id,
													'chainmap' => $this->id
											]);

		if($tmp != null)
		{
			return $tmp->systemID;
		}


		$name = strtolower($name);

		$tmp = DB::selectOne('SELECT id,name FROM solarsystems WHERE LOWER(name) = ?',[$name]);
		if($tmp != null)
		{
			$systemID = $tmp->id;
			return $systemID;
		}

		return 0;
	}

	private function _hash_array_to_string($arr)
	{
		foreach( $arr as $k => $v )
		{
			$arr[$k] = "'".($v)."'";
		}
		return implode(',', $arr);
	}

	public function delete_wormholes($wormholeHashes)
	{
		$log_message = SiggySession::getCharacterName().' performed a mass delete of the following wormholes: ';

		$wormholeHashes = $this->_hash_array_to_string($wormholeHashes);

		$wormholes = DB::select('SELECT w.*, sto.name as to_name, sfrom.name as from_name
													FROM wormholes w
													INNER JOIN solarsystems sto ON sto.id = w.to_system_id
													INNER JOIN solarsystems sfrom ON sfrom.id = w.from_system_id
													WHERE w.hash IN('.$wormholeHashes.') AND w.group_id=:groupID AND w.chainmap_id=:chainmap',
													[
														'groupID' => $this->group_id,
														'chainmap' => $this->id
													]);

		$sigs = [];
		$systemIDs = [];
		foreach( $wormholes as $wh )
		{
			$systemIDs[] = $wh->to_system_id;
			$systemIDs[] = $wh->from_system_id;

			$log_message .= $wh->to_name . ' to ' . $wh->from_name . ', ';
		}

		$systemIDs = array_unique( $systemIDs );
		$sigs = array_unique( $sigs );
		$sigs = implode(',', $sigs);

		DB::delete('DELETE FROM wormholes
					 WHERE hash IN('.$wormholeHashes.') AND group_id=:groupID AND chainmap_id=:chainmap',
							[
								'groupID' => $this->group_id,
								'chainmap' => $this->id
							]);


		DB::delete('DELETE FROM wormhole_jumps 
						WHERE wormhole_hash IN('.$wormholeHashes.') AND group_id=:groupID',
							[
								'groupID' => $this->group_id
							]);

		$log_message .= ' from the chainmap "'. $this->name.'"';

		$group = Group::find($this->group_id);
		$group->logAction('delwhs', $log_message );

		return $systemIDs;
	}

	
	public static function generatePossibleSystemLocations($x, $y)
	{
		$originBB = self::coordsToBB($x,$y);

		$cX = $originBB['left'];
		$cY = $originBB['top'];

		$ret = array();

		for($level = 1; $level <= 3; $level++)
		{
			$positions = 8*$level;
			$rotation = 2 * M_PI / $positions;

			for($position = 0; $position < $positions; ++$position)
			{
				$spot_rotation = $position * $rotation;
				$newx = $cX + 125*$level*cos($spot_rotation);
				$newy = $cY + 85*$level*sin($spot_rotation);

				//limited horizontal span
				if( $newy > 0 && $newx > 0 )
				{
					$ret[] = array('x' => $newx, 'y' => $newy);
				}
			}
		}

		return $ret;
	}

	public static function doBoxesIntersect($a, $b)
	{
		$x1 = $a['left'];
		$x2 = $a['left'] + $a['width'];
		$y1 = $a['bottom'];
		$y2 = $a['bottom'] + $a['height'];

		$a1 = $b['left'];

		$a2 = $b['left'] + $b['width'];
		$b1 =  $b['bottom'];
		$b2 =  $b['bottom'] +  $b['height'];

		return  ( ($x1 <= $a1 && $a1 <= $x2) && ($y1 <= $b1 && $b1 <= $y2) ) ||
				( ($x1 <= $a2 && $a2 <= $x2) && ($y1 <= $b1 && $b1 <= $y2) ) ||
				( ($x1 <= $a1 && $a1 <= $x2) && ($y1 <= $b2 && $b2 <= $y2) ) ||
				( ($x1 <= $a2 && $a1 <= $x2) && ($y1 <= $b2 && $b2 <= $y2) ) ||
				( ($a1 <= $x1 && $x1 <= $a2) && ($b1 <= $y1 && $y1 <= $b2) ) ||
				( ($a1 <= $x2 && $x2 <= $a2) && ($b1 <= $y1 && $y1 <= $b2) ) ||
				( ($a1 <= $x1 && $x1 <= $a2) && ($b1 <= $y2 && $y2 <= $b2) ) ||
				( ($a1 <= $x2 && $x1 <= $a2) && ($b1 <= $y2 && $y2 <= $b2) );
	}
}
