<?php 

namespace App\Http\Controllers\Api\v1;

use Illuminate\Http\Request;
use App\Http\Controllers\Api\BaseController;
use \Group;
use Siggy\Chainmap;
use Dingo\Api\Routing\Helpers;

class ChainmapsController extends BaseController {

    use Helpers;

	public function getList(Request $request) {
		
		$group = $this->user()->group;
		$output = [];

		foreach($group->chainMaps() as $c)
		{
			$hs = explode(",", $c->homesystems);
			$output[] = [
							'id' => (int)$c->id,
							'name' => $c->name,
							'homesystems' => $hs
						];
		}

		return $this->response->array($output);
	}
	
	public function getChainmap($id, Request $request) {
		$chainmap = null;
		
		try
		{
			$chainmap = Chainmap::find($id, $this->user()->group_id);
		}
		catch(Exception $e)
		{
			return $this->response->errorInternal();
		}

		if($chainmap == null)
		{
			return $this->response->errorNotFound();
		}

		$data = $chainmap->get_map_cache();
		
		$output['id'] = $chainmap->id;
		$output['name'] = $chainmap->name;
		$output['wormholes'] = [];
		foreach($data['wormholes'] as $w)
		{
			$output['wormholes'][] = [
										'hash' => $w->hash,
										'to_system_id' => (int)$w->to_system_id,
										'from_system_id' => (int)$w->from_system_id,
										'eol' => (int)$w->eol,
										'mass' => (int)$w->mass,
										'frigate_sized' => (bool)$w->frigate_sized,
										'created_at' => $w->created_at,
										'updated_at' => $w->updated_at,
										'total_tracked_mass' => (int)$w->total_tracked_mass,
									];
		}
		
		return $this->response->array($output);
	}
}
