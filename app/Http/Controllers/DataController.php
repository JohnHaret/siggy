<?php 

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Siggy\StructureType;
use Siggy\POSType;
use Siggy\Ship;
use Siggy\Site;

use \Cache;

class DataController extends Controller {

	public function systems()
	{
		$systems = Cache::tags('staticdata')->remember('systems', 60, function () {
			return collect(DB::select("SELECT ss.id, 
										ss.name, 
										r.regionName as region_name, 
										ss.constellation as constellation_id,
										ss.region as region_id,
										r.regionName as region_name,
										c.constellationName as constellation_name,
										ss.sec, 
										ss.sysClass as class,
										ss.planets,
										ss.moons,
										ss.radius,
										ss.belts,
										ss.truesec,
										ss.effect as effect_id
														FROM solarsystems ss
														INNER JOIN eve_map_constellations c ON(ss.constellation = c.constellationID)
														INNER JOIN eve_map_regions r ON(ss.region = r.regionID)"))->keyBy('id');
		});
		return response()->json($systems);
	}

	public function structures()
	{
		$structures = StructureType::all()->keyBy('id');

		return response()->json($structures);
	}

	public function poses()
	{
		$poses = POSType::all()->keyBy('id');

		return response()->json($poses);
	}

	public function ships()
	{
		$ships = Ship::all()->keyBy('id');

		return response()->json($ships);
	}

	public function effects()
	{
		$effects = \Siggy\SystemEffect::all()->keyBy('id');

		return response()->json($effects);
	}

	public function locale($locale = 'en')
	{
		$path = resource_path() . DIRECTORY_SEPARATOR . 'lang';

		$json = '';
		if( file_exists("{$path}/{$locale}.json") )
		{
			$json = file_get_contents("{$path}/{$locale}.json");
		}

		return response($json)
                  ->header('Content-Type', 'application/json; charset=utf-8');
	}

	public function sigTypes()
	{
		$output = [];
		$wormholeTypes = DB::select("SELECT * FROM statics");

		$types = [];
		foreach($wormholeTypes as &$row)
		{
			$types[ $row->id ] = $row;
		}
		$output['wormhole_types'] = $types;

		$whStaticMap = DB::select("SELECT * FROM wormhole_class_map
												ORDER BY position ASC");

		$outWormholes = [];
		foreach($whStaticMap as $entry)
		{
			$outWormholes[ $entry->system_class ][] = array('static_id' => (int)$entry->static_id, 'position' => (int)$entry->position);
		}

		$output['wormholes'] = $outWormholes;

		$siteTypes = Site::all();

		foreach($siteTypes as $site)
		{
			$output['sites'][$site->id] = ['id' => (int)$site->id, 'name' => $site->name, 'type' => $site->type, 'description' => $site->description];
		}


		$extra = DB::select("SELECT s.id, scm.system_class, s.name, s.type FROM site_class_map scm
													LEFT JOIN sites s ON(s.id=scm.site_id)");

		foreach($extra as $site)
		{
			$output['maps'][ $site->type ][ $site->system_class ][] = $site->id;
		}

		return response()->json($output);
	}
}
