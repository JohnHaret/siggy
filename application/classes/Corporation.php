<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

use Siggy\ESI\Client as ESIClient;

class Corporation extends Model {

	public $timestamps = true;
	public $incrementing = false;

	public const  SYNC_INTERVAL_MINUTES = 60*24;

	protected $fillable = [
		'id',
		'name',
		'ticker',
		'description',
		'member_count',
		'updated_at',
		'last_sync_attempt_at',
		'last_sync_successful_at'
	];

	public $dates = ['last_sync_attempt_at','last_sync_successful_at'];

	public function syncWithApi()
	{
		$update = [];
		$rawData = self::getAPICorpDetails($this->id);
		if( $rawData != null )
		{
			$update = [ 'member_count' => $rawData['member_count'],
					//todo, fix me once ESI adds it back :/
					//	'description' => $rawData['description'],
						'ticker' => $rawData['ticker'],
						'name' => $rawData['name'],
						'last_sync_successful_at' => Carbon::now()->toDateTimeString(),
						'last_sync_attempt_at' => Carbon::now()->toDateTimeString()
					];
		}
		else
		{
			$update = [ 
						'last_sync_attempt_at' => Carbon::now()->toDateTimeString()
					];

		}

		$this->fill($update);
		$this->save();

		return;
	}

	public static function find(int $id): ?Corporation
	{
		$corp = self::where('id', $id)->first();
		if($corp != null)
		{
			if( $corp->last_sync_attempt_at == null ||
				$corp->last_sync_attempt_at->addMinutes(self::SYNC_INTERVAL_MINUTES) < Carbon::now() )
			{
				$corp->syncWithApi();
			}

			return $corp;
		}
		else
		{
			$rawData = self::getAPICorpDetails($id);

			if( $rawData == null )
				return null;

			$insert = [ 'id' => $id,
						'name' => $rawData['name'],
						'member_count' => $rawData['member_count'],
						//todo, fix me once ESI adds it back :/
						//'description' => $rawData['description'],
						'ticker' => $rawData['ticker'],
						'updated_at' => Carbon::now()->toDateTimeString(),
						'last_sync_attempt_at' =>  Carbon::now()->toDateTimeString(),
						'last_sync_successful_at' => Carbon::now()->toDateTimeString()
					];

			$res = self::create($insert);
			
			return $res;
		}
	}

	
	static function getAPICorpDetails( int $id ): ?array
	{
		$details = null;

		$client = new ESIClient();
		$result = $client->getCorporationInformationV5($id);
		
		if($result != null)
		{
			$details = ['member_count' => (int)$result->member_count,
				'name' => $result->name,
				'ticker' => $result->ticker,
				'alliance_id' => property_exists($result, 'alliance_id') ? $result->alliance_id : 0
				//todo, add back description
			];
		}

		return $details;
	}
	
	static function searchEVEAPI( string $name, bool $strict = false ): ?array
	{
		$results = [];

		$client = new ESIClient();
		$result = $client->getSearchV2($name, ['corporation'], 'en-us', $strict);

		if($result != null)
		{
			if(property_exists($result,'corporation'))
			{
				foreach($result->corporation as $id)
				{
					$corp = Corporation::find($id);
					if($corp != null)
					{
						$results[$id] = $corp;
					}
				}
			}
		}

		return $results;
	}
}