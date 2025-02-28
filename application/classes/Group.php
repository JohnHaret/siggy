<?php

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Group extends Model {
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

	protected $fillable = [
							'name',
							'ticker',
							'password_required',
							'show_sig_size_col',
							'default_activity',
							'password',
							'stats_enabled',
							'record_jumps',
							'stats_sig_add_points',
							'stats_sig_update_points',
							'stats_wh_map_points',
							'stats_pos_add_points',
							'stats_pos_update_points',
							'jump_log_enabled',
							'jump_log_record_names',
							'jump_log_record_time',
							'jump_log_display_ship_type',
							'always_broadcast',
							'chain_map_show_actives_ships',
							'allow_map_height_expand',
							'chainmap_always_show_class',
							'chainmap_max_characters_shown',
							'last_billing_charge_at',
							'enable_wh_sig_link',
							'payment_code'
							];

	public $groupMembers = null;
	public $chainMaps = null;

	public $blacklistCharacters = null;

	//todo, implement
	public $cache_time = 1;

	protected static function boot()
	{
		parent::boot();
		
		static::creating( function ($model) {
			$model->setCreatedAt($model->freshTimestamp());
		});
	}

	public function save(array $options = [])
	{
		$this->last_update = time();

		parent::save($options);
	}

	private static function hashGroupPassword(string $password, string $salt): string
	{
		return sha1($password . $salt);
	}

	public static function createFancy(array $data): Group
	{
		$salt = miscUtils::generateSalt(10);
		$password = "";
		if( $data['password'] != "" && $data['password_required'] == 1 )
		{
			$password = self::hashGroupPassword( $data['password'], $salt );
		}

		$insert = [
			'name' => $data['name'],
			'ticker' => $data['ticker'],
			'password_required' => $data['password_required'],
			'password_salt' => $salt,
			'password' => $password,
			'payment_code' => miscUtils::generateString(14),
			'billable' => 1
		];
		$group = self::create($insert);

		$insert = ['group_id' => $group->id,
						 'default' => 1,
						 'name' => 'Default',
						 'homesystems' => '',
						 'homesystems_ids' => ''
		];

		$map = \Siggy\Chainmap::create($insert);

		return $group;
	}

	public static function findByPaymentCode(string $code)
	{
		return self::where('payment_code', $code)->first();
	}

	public static function findAllByGroupMembership(string $type, int $eveID): array
	{
		$results = self::join('groupmembers', 'groups.id', '=', 'groupmembers.groupID')
			->where('groupmembers.eveID', $eveID)
			->where('groupmembers.memberType', $type)
			->select('groups.*')
			->get()
			->keyBy('id')
			->all();

		return $results;
	}

	public function apiKeys()
	{
		return $this->hasMany('Siggy\ApiKey');
	}

	public function groupMembers(): array
	{
		if($this->groupMembers == null)
		{
			$this->groupMembers = GroupMember::findByGroup($this->id);
		}

		return $this->groupMembers;
	}

	public function findGroupMember(string $type, int $id)
	{
		return GroupMember::findByGroupAndType($this->id, $type, $id);
	}

	public function blacklistCharacters(): array
	{
		if($this->blacklistCharacters == null)
		{
			$this->blacklistCharacters = GroupBlacklistCharacter::findAllByGroup($this->id);
		}

		return $this->blacklistCharacters;
	}

	public function chainMaps(): array
	{
		if($this->chainMaps == null)
		{
			$cache_name = 'group-chainmaps-'.$this->id;

			if( $data = Cache::get( $cache_name, FALSE ) )
			{
				$this->chainMaps = $data;
			}
			else
			{
				$this->chainMaps = $this->recacheChainmaps();
			}
		}

		return $this->chainMaps;
	}
	
	public function logAction( string $type, string $message )
	{
		$insert = array( 'groupID' => $this->id,
						 'type' => $type,
						 'message' => $message,
						 'entryTime' => time()
						);

		DB::table('logs')->insert($insert);
	}

	public function getCharacterUsageCount(): int
	{
		$num_corps = DB::selectOne("SELECT SUM(DISTINCT c.member_count) as total FROM groupmembers gm
										LEFT JOIN corporations c ON(gm.eveID = c.id)
										WHERE gm.groupID=:group AND gm.memberType='corp'",[$this->id]);

		$num_corps = $num_corps->total;

		$num_chars = DB::selectOne("SELECT COUNT(DISTINCT eveID) as total FROM groupmembers
										WHERE groupID=:group AND memberType ='char' ",[$this->id]);
		$num_chars = $num_chars->total;

		return ($num_corps + $num_chars);
	}

	public function activeCharsFromDate(Carbon $date): int
	{
		return CharacterGroup::where('group_id', $this->id)
					->where('last_group_access_at', '>=', $date)
					->count();
	}
	
	public function incrementStat(string $stat, array $acccessData)
	{
		if( !$this->stats_enabled )
		{
			return;
		}

		if( !in_array( $stat, ['adds','updates','wormholes','pos_adds','pos_updates'] ) )
		{
			throw new Exception("invalid stat key");
		}

		$duplicate_update_string = $stat .'='. $stat .'+1';

		DB::insert('INSERT INTO stats (`charID`,`charName`,`groupID`,`chainmap_id`,`dayStamp`,`'.$stat.'`)
												VALUES(:charID, :charName, :groupID, :chainmap, :dayStamp, 1)
												ON DUPLICATE KEY UPDATE '.$duplicate_update_string,
							[
								'charID' => SiggySession::getCharacterId(),
								'charName' => SiggySession::getCharacterName(),
								'groupID' => $this->id ,
								'chainmap' => $acccessData['active_chain_map'] ,
								'dayStamp' => miscUtils::getDayStamp()
							]);
	}


	public function applyISKCharge(float $amount)
	{
		DB::table('groups')
			->where('id', '=',  $this->id)
			->update( ['isk_balance' => DB::raw('isk_balance - '.$amount)] );
	}

	public function applyISKPayment(float $amount)
	{
		DB::table('groups')
			->where('id', '=',  $this->id)
			->update( [ 'isk_balance' => DB::raw('isk_balance + '.$amount), 'billable' => 1 ] );
	}


	public function recacheMembers()
	{
		//placeholder in case we want to implement
	}

	public function recacheChainmaps()
	{
		$chainmaps = \Siggy\Chainmap::findAllByGroup($this->id)->keyBy('id')->all();
		
		foreach($chainmaps as &$c)
		{
			$members = DB::select("SELECT gm.memberType, gm.eveID 
										FROM groupmembers gm
								LEFT JOIN chainmaps_access a ON(gm.id=a.groupmember_id) WHERE chainmap_id=?",[$c->id]);
			$c->access = $members;
		}

		$cache_name = 'group-chainmaps-'.$this->id;

		Cache::put($cache_name, $chainmaps, 1800);

		return $chainmaps;
	}
}