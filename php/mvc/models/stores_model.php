<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Stores_model extends CI_Model
{
	function __construct()
	{
		parent::__construct();
	}
	
	/*
	| ====================================
	| Invites
	| ====================================
	|
	| invite_email_exists() t:√_
	|	Checks whether an invite with the email address of the new invitee already exists.
	|	Params: EmailAddress:String
	|	Returns true or false.
	| 
	| invite_code_get() t:√_
	|	Gets the invite code from the already-existing invite having same email.
	|	Params: EmailAddress:String
	|	Returns the invite code or false.
	|
	| invite_create() t:√_
	|	Inserts the invite into database using the provided associative array.
	|	Param 'role' gets converted from a string to a number. E: 'user' becomes '0'.
	|	Params: AssociativeArray:Array (invite_code, email, store_id, role)
	|	Returns true or false.
	|
	| invite_validate() t:√_
	|	Checks the provided invite code against the database.
	|	Params: InviteCode:String
	|	Returns true or false.
	|
	| invite_matches_existing_account() t:√_
	|	Checks whether the email submitted as an invite matches an existing Account.
	|	(If it does, Account should be assigned to Store instantly)
	|
	| invite_details_get() t:√_
	|	Gets [InviteID, Store ID, Role, Invited By] from the invites having the provided invite code.
	|	Params: InviteCode:String
	|	Returns assiciative array of invite details or FALSE.
	|
	| invite_convert_store_users() t:√_
	|	Occurs after invite is followed through with. Receives the invite_id of the user who has created their account and
	|	that users new member_id, and converts all store_user records from pending (i-inviteID) to active (memberID)
	|		Params: InviteID:String
	|		Returns: True or False
	| 
	| invite_remove() t:√_
	|	Removes invites from the invite table having the specified invite code.
	| 
	| ci_store_user_invites:
	|--------------------------
	| - invite_id
	| - invite_code*
	| - email*
	| - store_id
	| - role
	| - date_of_invite
	|
	*/
	
	function invite_store_user($currentMember, $email, $storeList, $roleList)
	{
		$aInvites = array();
		
		$i = 0;
		foreach ( $storeList as $key => $val)
		{
			$aInvites[$i]['store'] = $val;
			$aInvites[$i]['role'] = $roleList[$key];
			
			$i++;
		} //end foreach userAccess
		
		if ( $this->invite_matches_existing_account($email) )
		{
			$this->db->from('members');
			$this->db->where('email', $email);
			$this->db->limit(1);
			$q = $this->db->get();
			$rMember = $q->row_array();
			
			$memberID = $rMember['member_id'];
			
			//check if this member has an existing "active_store" or not
			$this->db->from('ci_member_data');
			$this->db->where('member_id', $memberID);
			$this->db->limit(1);
			$q = $this->db->get();
			$rActive = $q->row_array();
			
			$hasActive = $rActive['active_store'] == '' || $rActive['active_store'] == 0 ? FALSE : TRUE;
			
			foreach ( $aInvites as $rowInvites )
			{
				if ( !$hasActive )
				{
					$data = array
					(
						'active_store' => $rowInvites['store_id']
					);
					$this->db->where('member_id', $memberID);
					$this->db->update('ci_member_data', $data);
					
					$hasActive = TRUE;
				}
				
				$this->db->from('ci_store_users');
				$this->db->where('member_id', $memberID);
				$this->db->where('store_id', $rowInvites['store']);
				$q = $this->db->get();
				$rUser = $q->result_array();
				$numUser = count($rUser);
				
				if ( $numUser == 0 )
				{
					$data = array
					(
						'member_id' => $memberID,
						'store_id' => $rowInvites['store'],
						'role' => $rowInvites['role'],
						'settings' => '0',
						'invited_by' => $currentMember
					);
					$this->user_add_from_invite($data);
					
					//let user know they have been invited
					$userData = array
					(
						'email' => $email,
						'store_id' => $rowInvites['store'],
						'role' => $rowInvites['role']
					);
					
					$v = array( 'inviteCode' => '' );
					$s = array('to' => $email);
					
					$this->load->model('emails');
					$this->emails->existing_member_store_invite($v, $s);
				} //end if numUser
			} //end foreach aInvites
		}
		else //otherwise, there is no actual account to associate. just check if there is an invite for each store in this invite group and if not add one
		{
			foreach ( $aInvites as $rowInvites )
			{
				$this->db->from('ci_store_user_invites');
				$this->db->where('email', $email);
				$this->db->where('store_id', $rowInvites['store']);
				$q = $this->db->get();
				$rInviteID = $q->result_array();
				$numInvites = count($rInviteID);
				
				if ( $numInvites == 0 )
				{
					$code = md5( uniqid('', TRUE) );
					$userData = array
					(
						'invite_code' => $code,
						'email' => $email,
						'store_id' => $rowInvites['store'],
						'role' => $rowInvites['role']
					);
					
					$this->invite_create($userData);
					
					$this->db->from('ci_store_user_invites');
					$this->db->where('email', $email);
					$this->db->where('store_id', $rowInvites['store']);
					$this->db->limit(1);
					$q = $this->db->get();
					$rInviteID = $q->row_array();
					
					$inviteID = $rInviteID['invite_id'];
					$inviteCode = $rInviteID['invite_code'];
					
					foreach ( $aInvites as $rowInvites )
					{
						$data = array
						(
							'member_id' => 'i-'.$inviteID,
							'store_id' => $rowInvites['store'],
							'role' => $rowInvites['role'],
							'settings' => '0',
							'invited_by' => $currentMember
						);
						$this->user_add_from_invite($data);
					}
					
					$v = array( 'inviteCode' => $inviteCode );
					$s = array('to' => $email);
					
					$this->load->model('emails');
					$this->emails->new_store_user_invite($v, $s);				
					
				}
			}
		}
	}
	
	function invite_email_exists($sEmail)
	{
		$this->db->from('ci_store_user_invites');
		$this->db->where('email', $sEmail);
		$q = $this->db->get();
		$rInvite = $q->result_array();
		$numInvite = count($rInvite);
		
		return ( $numInvite == 0 ) ? FALSE : TRUE;
	}
	
	function invite_code_get($sEmail)
	{
		$this->db->select('invite_code');
		$this->db->from('ci_store_user_invites');
		$this->db->where('email', $sEmail);
		$this->db->limit(1);
		$q = $this->db->get();
		$rCode = $q->row_array();
		$numCode = count($rCode);
		
		if ( $numCode == 0 )
		{
			return FALSE;
		}
		else
		{
			return $r['invite_code'];
		}
	}
	
	function invite_create($a)
	{
		$this->load->helper('date');
		
		$a['invited_by'] = member_id();
		$a['date_of_invite'] = now();
		$a['role'] = array_search( $a['role'], config_item('user_types') );
		
		$this->db->insert('ci_store_user_invites', $a);
		
		return ( $this->db->affected_rows() == 0 ) ? FALSE : TRUE;
	}
	
	function invite_validate($sCode)
	{
		$this->db->from('ci_store_user_invites');
		$this->db->where('invite_code', $sCode);
		$this->db->limit(1);
		$q = $this->db->get();
		$rCode = $q->row_array();
		$numCode = count($rCode);
		
		return ( $numCode == 0 ) ? FALSE : TRUE;
	}
	
	function invite_matches_existing_account($sEmail)
	{
		$this->db->from('members');
		$this->db->where('email', $sEmail);
		$this->db->limit(1);
		$q = $this->db->get();
		$rEmail = $q->row_array();
		$numEmail = count($rEmail);
		
		return ( $numEmail == 0 ) ? FALSE : TRUE;
	}
	
	function invite_details_get($sCode)
	{
		$this->db->from('ci_store_user_invites');
		$this->db->where('invite_code', $sCode);
		$this->db->limit(1);
		$q = $this->db->get();
		$r = $q->row_array();
		
		if ( $q->num_rows() == 0 )
		{
			return FALSE;
		}
		else
		{
			return $r;
			
		}
	}
	
	function invite_convert_store_users($inviteID, $memberID)
	{
		$this->db->from('ci_store_users');
		$this->db->where('member_id', 'i-'.$inviteID);
		$q = $this->db->get();
		$rInvites = $q->result_array();
		
		foreach ( $rInvites as $rowInvites )
		{
			$this->db->from('ci_store_users');
			$this->db->where('member_id', $memberID);
			$this->db->where('store_id', $rowInvites['store_id']);
			$q = $this->db->get();
			$rMembers = $q->result_array();
			$numMembers = count($rMembers);
			
			if ( $numMembers > 0 )
			{
				$this->db->where('member_id', 'i-'.$inviteID);
				$this->db->where('store_id', $rowInvites['store_id']);
				$this->db->delete('ci_store_users');
			} //end if numMembers
			
		} //end foreach rInvites
			
		$data = array
		(
			'member_id' => $memberID
		);
		$this->db->where('member_id', 'i-'.$inviteID);
		$this->db->update('ci_store_users', $data);
		
		if ( $this->db->affected_rows() !== 0 )
		{
			if ( userdata('active_store') == '0' )
			{
				$this->load->model('member_data_model');
				
				$this->db->from('ci_store_users');
				$this->db->where('member_id', $memberID);
				$this->db->limit(1);
				$q = $this->db->get();
				$rUsers = $q->row_array();
					
				$b = array(
					'member_id' => $memberID,
					'active_store' => $rUsers['store_id']
				);
				
				// Assign Pharmacy User Level to "1" if first store and role is "user"
				if ( $rUsers['role'] == '0' )
				{
					$b['pharmacy_user_level'] = 1;
				}
					
				$this->member_data_model->update($b);
			}
			else
			{
				// Assign Pharmacy User Level to "2" if not first store and role is not "user"
				if ( $rUsers['role'] != '0' )
				{
					$b = array(
						'pharmacy_user_level' => 2
					);
					$this->member_data_model->update($b);
				}
			}// end if active_store
		} //end if affected_rows
		
		return ( $this->db->affected_rows() == 0 ) ? FALSE : TRUE;
	}
	
	function invite_remove($inviteID)
	{
		$this->db->where('member_id', 'i-'.$inviteID);
		$this->db->delete('ci_store_users');
		
		$this->db->from('ci_store_user_invites');
		$this->db->where('invite_id', $inviteID);
		$q = $this->db->get();
		$rPending = $q->row_array();
		
		$this->db->where('email', $rPending['email']);
		$this->db->where('invited_by', $rPending['invited_by']);
		$this->db->delete('ci_store_user_invites');
		
		return ( $this->db->affected_rows() == 0 ) ? FALSE : TRUE;
	}
	
	/*
	| ====================================
	| Users
	| ====================================
	|
	| user_add() t:√_
	|	Creates one user based on the provided associative array.
	|	Member ID: If Member ID is provided in the parameter, that is used, otherwise finds the member id automatically.
	|		Member ID does need to be provided manually for a Vendor User, their auto store creation, and their assignment to said store.
	|	Param 'role' gets converted from a string to a number. E: 'user' becomes '0'. See config file for reference.
	|	If user has no Active Store, this Store will become the Active.
	|	Params: AssociativeArray:Array (store_id, role, settings, invited_by)
	|
	| user_add_from_invite() t:√_
	|	Does the same thing as user_add(), but without managing active store. also, member_id must be sent. and also role should be numerical,
	|   not converted from string
	|		Params: AssociativeArray:Array (member_id, store_id, role, settings, invited_by)
	|
	| user_remove() t:__
	|	Removes user from the users table based on the provided associative array.
	|	Params: AssociativeArray:Array (member_id, store_id)
	|
	| user_update() t:__
	|	Updates the specified user with a new role and or settings based on the provided assoc array.
	|	Params: AssociativeArray:Array (member_id, store_id, role, settings)
	|
	| user_exists() t:√_
	|	Checks whether Store has the provided Member ID as a User
	|	Converts Member ID to a String which it must be to compare to database. Ex: '85' or 's-85' are both valid Member ID's
	|	Params: Member ID, Store ID
	|	Returns: Boolean
	|
	| user_has_store() t:√_
	|	Checks whether the pharmacy account has at least one Active Store assigned.
	|	If not, user cannot access App (no longer true)
	|	Params: Member ID
	|	Returns: TRUE, 'inactive', FALSE
	|
	| user_store_ids() t:√_
	|	Gets ID's of all Stores to which the user is assigned.
	|	Params: None
	|	Returns: Numeric-Key Array, Empty Array
	|
	| user_has_vendor_stores() t:√_
	|	Determines whether the current member has at least one store that is a vendor store.
	|	Params: None
	|	Returns: Boolean
	|
	| user_stores_list() t:__
	|	Gets all stores to which the user is assigned.
	|	Result contains only the data needed to display a list.
	|	Params: None
	|	Returns: Assoc Array, Empty Array
	|	
	| user_current_role() t:__
	|	Gets given user's role based on the StoreID  and MemberID provided.
	|	Result contains only the numeric role of the active store.
	|	Params: Store ID
	|	Returns: Number [role]
	|
	| ci_store_users:
	|-----------------
	| - member_id*
	| - store_id*
	| - role
	| - settings
	| - invited_by
	| - date_assigned
	|
	*/
	
	function user_add($a)
	{
		$this->load->helper('date');
		
		$a['date_assigned'] = now();
		$a['member_id'] = isset($a['member_id']) ? $a['member_id'] : member_id();
		$a['role'] = array_search( $a['role'], config_item('user_types') );
		
		$this->db->insert('ci_store_users', $a);
		
		if ( $this->db->affected_rows() == 0 )
		{
			return FALSE;
		}
		else
		{
			if ( userdata('active_store') == '0' )
			{
				$this->load->model('member_data_model');
				
				$b = array(
					'member_id' => $a['member_id'],
					'active_store' => $a['store_id']
				);
				
				$this->member_data_model->update($b);
			}
			
			return TRUE;
		}
	}
	
	function user_add_from_invite($a)
	{
		$this->load->helper('date');
		
		$a['date_assigned'] = now();
		
		$this->db->insert('ci_store_users', $a);
		
		if ( $this->db->affected_rows() == 0 )
		{
			return FALSE;
		}
		else
		{			
			return TRUE;
		}
	}
	
	function user_remove($assoc)
	{
		$this->db->delete('ci_store_users', $assoc);
		
		return ( $this->db->affected_rows() == 0 ) ? FALSE : TRUE;
	}
	
	function user_update($assoc)
	{
		$aWhere = array(
			'member_id' => $assoc['member_id'],
			'store_id' => $assoc['store_id']
		);
		
		unset($assoc['member_id']);
		unset($assoc['store_id']);
		
		$this->db->where($aWhere);
		$this->db->update('ci_store_users', $assoc);
		
		return ( $this->db->affected_rows() == 0 ) ? FALSE : TRUE;
	}
	
	function user_exists($mid, $sid)
	{
		$this->db->from('ci_store_users');
		$this->db->where('member_id', (string)$mid);
		$this->db->where('store_id', $sid);
		$q = $this->db->count_all_results();
		
		return ( $q == 0 ) ? FALSE : TRUE;
	}
	
	function user_has_store($mid)
	{
		$this->db->from('ci_store_users as users');
		$this->db->where('users.member_id', $mid);
		$this->db->join('ci_stores as stores', 'users.store_id = stores.store_id');
		$this->db->where('stores.is_active', 1);
		$q = $this->db->count_all_results();
		
		if ( $q == 0 )
		{
			$this->db->from('ci_store_users as users');
			$this->db->where('users.member_id', $mid);
			$this->db->join('ci_stores as stores', 'users.store_id = stores.store_id');
			$q = $this->db->count_all_results();
			
			return ( $q == 0 ) ? FALSE : 'inactive';
		}
		else
		{
			return TRUE;
		}
	}
	
	function user_store_ids()
	{
		$q = $this->db->select('store_id')->
		from('ci_store_users')->
		where('member_id', member_id())->
		get()->result();
		
		$r = array();
		
		foreach ( $q as $row )
		{
			$r[] = $row->store_id;
		}
		
		return $r;
	}
	
	function user_stores_list()
	{
		$r = $this->db->select('users.store_id, stores.dba_name')->
		from('ci_store_users as users')->
		join('ci_stores as stores', 'users.store_id = stores.store_id')->
		where('users.member_id', member_id())->
		where('stores.is_active', 1)->
		order_by('stores.dba_name')->
		get()->result_array();
		
		return $r;
	}
	
	function user_has_vendor_stores()
	{
		$s = $this->stores_model->user_stores_list();
		
		foreach ( $s as $i )
		{
			$q = $this->db->from('ci_store_users')->
			where('store_id', $i['store_id'])->
			get()->result();
			
			foreach ( $q as $j )
			{
				if ( strchr($j->member_id, 's-') === false )
				{
					return false;
				}
			}
		}
		
		return true;
	}
	
	function user_current_role($sid)
	{
		$this->db->select('role');
		$this->db->from('ci_store_users');
		$this->db->where('member_id', member_id());
		$this->db->where('store_id', $sid);
		$q = $this->db->get();
		$r = $q->row_array();
		
		return ( $q->num_rows() === 0 ) ? '' : $r['role'];
	}
	
	/*
	| ====================================
	| Stores
	| ====================================
	|
	| store_exists() t:__
	|	Takes the NPI for a store and checks to see if a store having that NPI exists.
	|	Params: NPI
	|	Returns: True, False
	|
	| store_create() t:√_
	|	Creates a store using the provided associative array.
	|	Duplicates value of 'dba_name' into 'branding_text' column.
	|	This data does not need to be provided in Params: 'store_id', 'date_created', 'created_by'
	|	Params: Assoc Array
	|	Returns: Boolean
	|
	| store_last_created_by() t:√_
	|	Gets all columns of data for a single Store. This is the last store created by the provided Member ID.
	|	This function is primarily used in assigning a new Store creator to their Store, and in sending email notice of store created.
	|	Params: Member ID
	|	Returns: Store Object, False
	|
	| store_get_new() t:__
	|	Gets the ID of one Store assigned to current Account which has been created within the last 5 minutes.
	|	Main purpose of this is so that Stores being assigned to Private Catalogs do not see Public.
	|	This does not prevent existing Stores who are later assigned to a Private Catalog from seeing Public.
	|	But hopefully this does not happen often.
	|	Params: None
	|	Returns: Store ID, False
	|
	| store_list() t:√_
	|	Gives a list of all stores of which the current user is a member.
	|	Params: None
	|	Returns associative array of store data or false.
	|
	| store_details() t:√_
	|	Gets all information about specified store. For edit page, etc.
	|	Params: Store ID
	|	Returns: Object (one-dimensional)
	|
	| store_details_npi() t:√_
	|	Gets all information about specified store. For edit page, etc.
	|	Params: NPI
	|	Returns: Object
	|
	| store_recently_assigned() t:√_
	|	Gets the stores which have been assigned within the last 5 minutes.
	|	Used for displaying on the confirmation page after being assigned.
	|	Params: None
	|	Returns associative array, false.
	|
	| store_update() t:√_
	|	Updates store specified by store_id with the provided associative array.
	|	Params:
	|		StoreID:String
	|		AssociativeArray:Array (All DB fields except 'store_id', 'date_created')
	|	Returns: True, False
	|
	| store_set_suppliers() t:√_
	|	Automatically sets all active-live-public suppliers to visible
	|	Params:
	|		StoreID:String
	|		State:String
	|
	| store_set_parameters() t:√_
	|	Automatically sets all savings parameters for vendor user
	|	Params:
	|		StoreID:String
	|
	| store_add_supplier_link()
	|	Associates a Store with a Supplier so the Store will have access to enable/disable that Supplier's Items that appear on their Order page, etc.
	|	Checks to make sure that duplicates are not being added.
	|	
	|
	| store_in_suppliers_state()
	|	Checks to make sure the supplier ships to the Store's state.
	|
	| ci_stores:
	|----------------
	| - store_id*
	| - npi*
	| - is_active*
	| - date_created
	| - created_by
	| - active_catalog
	| - legal_name
	| - dba_name
	| - contact_person
	| - street_address_1
	| - street_address_2
	| - city
	| - state
	| - zip
	| - phone
	| - fax
	| - email
	| - email_2
	|
	*/
	
	function store_exists($sNPI)
	{
		$this->db->from('ci_stores');
		$this->db->where('npi', $sNPI);
		$q = $this->db->count_all_results();
		
		return ( $q == 0 ) ? FALSE : TRUE;
	}
	
	function store_create($a)
	{
		$this->load->helper('date');
		
		unset($a['store_id']);
		
		$a['legal_name'] = isset( $a['legal_name'] ) ? $a['legal_name'] : $a['dba_name'];
		$a['branding_text'] = isset($a['branding_text']) ? $a['branding_text'] : $a['dba_name'];
		$a['has_public_access'] = isset($a['has_public_access']) ? $a['has_public_access'] : 1;
		$a['created_by'] = isset($a['member_id']) ? $a['member_id'] : member_id();
		$a['date_created'] = now();
		
		$this->db->insert('ci_stores', $a);
		
		return ( $this->db->affected_rows() == 0 ) ? FALSE : TRUE;
	}
	
	function store_last_created_by($mid)
	{
		$this->db->from('ci_stores');
		$this->db->where('created_by', $mid);
		$this->db->order_by('date_created', 'desc');
		$this->db->limit(1);
		$q = $this->db->get();
		$r = $q->row();
		
		return ( $q->num_rows() == 0 ) ? FALSE : $r;
	}
	
	function store_get_new()
	{
		$this->load->helper('date');
		
		$this->db->select('store_id');
		$this->db->from('ci_stores');
		$this->db->where('date_created > ' . (now()-300) );
		$q = $this->db->get();
		$r = $q->row();
		
		return ( $q->num_rows() == 0 ) ? FALSE : $r->store_id;
	}
	
	function store_list()
	{
		$q = $this->db->select('stores.store_id, stores.dba_name')->
		from('ci_store_users as users')->
		where('users.member_id', member_id() )->
		join('ci_stores as stores', 'users.store_id = stores.store_id')->
		get();
		
		return ( $q->num_rows() == 0 ) ? FALSE : $q->result_array();
	}
	
	function store_details($sid)
	{
		$q = $this->db->get_where('ci_stores', array('store_id' => $sid), 1);
		$r = $q->row();
		
		return $r;
	}
	
	function store_details_npi($npi)
	{
		$q = $this->db->get_where('ci_stores', array('npi' => $npi), 1);
		$r = $q->result();
		return $r;
	}
	
	function store_recently_assigned()
	{
		$this->load->helper('date');
		
		$this->db->select('stores.dba_name');
		$this->db->from('ci_store_users as users');
		$this->db->where('users.member_id', member_id());
		$this->db->where('users.date_assigned >', now()-300);
		$this->db->join('ci_stores as stores', 'users.store_id = stores.store_id');
		$q = $this->db->get();
		
		return ( $q->num_rows() == 0 ) ? FALSE : $q->result_array();
	}
	
	function store_update($sStoreID, $assoc)
	{
		$this->db->where('store_id', $sStoreID);
		$this->db->update('ci_stores', $assoc);
		
		return ( $this->db->affected_rows() == 0 ) ? FALSE : TRUE;
	}
	
	function store_set_suppliers($storeID, $state)
	{
		$this->db->select('supplier_id');
		$this->db->from('ci_suppliers');
		$this->db->where('is_active', 1);
		$this->db->where('is_live', 1);
		$this->db->where('is_public', 1);
		$this->db->like('active_states', $state);
		$q = $this->db->get();
		$rSups = $q->result_array();
		
		if( $q->num_rows() > 0 )
		{
			foreach ( $rSups as $rowSups )
			{
				$this->store_add_supplier_link($storeID, $rowSups['supplier_id']);
			}
			
			return true;
		}
		
		return false;
	}
	
	function store_unset_suppliers($storeID)
	{
		$d = array
		(
			'active' => 0
		);
		$this->db->where('store_id', $storeID);
		$this->db->update('ci_pharm_sup_link', $d);
	}
	
	function store_add_supplier_link($stid, $suid)
	{
		$r = $this->db
			->from('ci_pharm_sup_link')
			->where('store_id', $stid)
			->where('supplier_id', $suid)
			->count_all_results();
		
		if ( $r === 0 )
		{
			$d = array
			(
				'store_id' => $stid,
				'supplier_id' => $suid,
				'active' => 1,
				'terms' => 1,
				'terms_agreed' => time(),
				'terms_approved' => time()
			);
			
			$this->db->insert('ci_pharm_sup_link', $d);
			
			if ( $this->db->affected_rows() > 0 )
			{
				return true;
			}
		}
		
		return false;
	}
	
	function store_in_suppliers_state($suid, $state)
	{
		$state = strtoupper($state);
		
		$r = $this->db
			->from('ci_suppliers')
			->where('supplier_id', $suid)
			->like('active_states', $state)
			->count_all_results();
		
		return $r > 0;
	}
	
	function store_suppliers($storeID, $publicOrPrivate, $allOrOn, $listOrDetails)
	{
		//here we'll use params to determine what data to send
		//first returned array index will always be the number of suppliers meeting given criteria
		//second returned array index will be desired data
		
		/*function params
			1.) storeID: storeID in question
			2.) publicOrPrive: requesting suppliers denoted with 'is_public' or suppliers with whom user has been assigned catalogs
			3.) allOrOn: 
				-param 2 = public: requesting public suppliers who are "switched on/off" in pharm_sup_link
				-param 2 = private: requesting supplier whose catalogs are either 'is_active' 1/0
			4.) listOrDetails: get just the supplier ids (list) or total supplier info and catalog info (details)
		*/
		
		$r = array();
		
		if ( $publicOrPrivate == 'public' )
		{
			//find public suppliers
			$this->db->from('ci_pharm_sup_link as l');
			$this->db->join('ci_suppliers as s', 's.supplier_id = l.supplier_id', 'left');
			$this->db->where('l.store_id', $storeID);
			if ( $allOrOn == 'on' )
			{
				$this->db->where('l.active', 1);
			}
			$this->db->where('s.is_live', 1); //administration has made a supplier's catalogs able to be visible. this differs from the s.is_active field which simply gives the supplier access to the app
			$this->db->where('s.is_public', 1); //this indicates that a supplier has an approved (by administration) public catalog. they may also have one or more private catalogs.
			$q = $this->db->get();
			$rSups = $q->result_array();
			$numSups = count($rSups);
			
			$r['num'] = $numSups;
			
			if ( $listOrDetails == 'list' )
			{
				$r['info'] = '';
				
				if ( $numSups > 0 )
				{
					//generate csv list of sups
					foreach ( $rSups as $row )
					{
						$r['info'] .= $row['supplier_id'].',';
					}
					$r['info'] = rtrim($r['info'], ',');
				}
			}
			else if ( $listOrDetails == 'details' )
			{
				$r['info'] = $rSups;
			}
		}
		else if ( $publicOrPrivate == 'private' )
		{
			//find suppliers tied to private catalogs			
			$this->db->from('ci_catalog_users as users');
			$this->db->select('users.catalog_id, users.store_id, users.date_added, users.is_active, cats.name, sups.supplier_id, sups.supplier_name, sups.short_name, sups.contact_person, sups.address, sups.city, sups.state, sups.zip, sups.phone, sups.fax, sups.email, sups.url, sups.general_info');
			$this->db->join('ci_catalogs as cats', 'cats.catalog_id = users.catalog_id', 'left');
			$this->db->join('ci_suppliers as sups', 'sups.supplier_id = cats.supplier_id', 'left');
			$this->db->where('users.store_id', $storeID);
			if ( $allOrOn == 'on' )
			{
				$this->db->where('users.is_active', 1);
			}
			$q = $this->db->get();
			$rSups = $q->result_array();
			$numSups = count($rSups);
			
			$r['num'] = $numSups;
			
			if ( $idsOrDetails == 'ids' )
			{
				$r['info'] = '';
				
				if ( $numSups > 0 )
				{
					//generate csv list of sups
					foreach ( $rSups as $row )
					{
						$r['info'] .= $row['supplier_id'].',';
					}
					$r['info'] = rtrim(',', $r[0]['info']);
				}
			}
			else if ( $idsOrDetails == 'details' )
			{
				$r['info'] = $rSups;
			}
		}
		return $r;
	}
	
	function store_set_parameters($storeID, $memberID)
	{
		$this->db->from('ci_temp_savings_parameters');
		$this->db->where('store_id', $storeID);
		$q = $this->db->get();
		$rParams = $q->result_array();
		$numParams = count($rParams);
		
		if ( $numParams == 0 )
		{
			$data = array
			(
				'store_id' => $storeID,
				'budget_allowance' => 7500,
				'freeze_after_budget' => 1,
				'desired_savings' => 3,
				'savings_delimeter' => 'Dollars',
				'rebate_percentage' => 0,
				'package_multiplier' => 0,
				'usage_period' => 30,
				'bulk' => '0',
				'member_id' => $memberID,
				'data_calculated' => '0',
				'data_received' => '0',
				'manufacturer_tolerance' => 3,
				'package_tolerance' => 3,
				'comparison_status' => '1'
			);
			$this->db->insert('ci_temp_savings_parameters', $data);
		}
	}
	
	function store_set_default_filter($storeID, $memberID, $orderType, $orderView, $sortVal)
	{
		$this->db->from('ci_temp_savings_parameters');
		$this->db->where('store_id', $storeID);
		$q = $this->db->get();
		$rParams = $q->result_array();
		$numParams = count($rParams);
		
		if ( $numParams == 0 )
		{
			$data = array
			(
				'store_id' => $storeID,
				'desired_savings' => 0,
				'savings_delimeter' => 'Dollars',
				'rebate_percentage' => 0,
				'package_multiplier' => 0,
				'usage_period' => '31',
				'bulk' => '0',
				'member_id' => $memberID,
				'data_calculated' => '0',
				'data_received' => '0',
				'manufacturer_tolerance' => 0,
				'package_tolerance' => 0,
				'comparison_status' => 0,
				'order_type' => $orderType,
				'order_view' => $orderView,
				'sort_val' => $sortVal
			);
			$this->db->insert('ci_temp_savings_parameters', $data);
		}
		else
		{
			$data = array
			(
				'order_type' => $orderType,
				'order_view' => $orderView,
				'sort_val' => $sortVal
			);
			$this->db->where('store_id', $storeID);
			$this->db->update('ci_temp_savings_parameters', $data);
		}
	}
	
	function store_needs_walkthrough($storeID, $memberID, $reset)
	{
		$this->load->model('member_data_model');
		
		if ( $this->member_data_model->is_master_member($memberID) )
		{
			return FALSE;
		}
		else
		{		
			$this->db->select('show_walkthrough');
			$this->db->from('ci_store_users');
			$this->db->where('member_id', $memberID);
			$this->db->where('store_id', $storeID);
			$q = $this->db->get();
			$rUserType = $q->row_array();
			
			if ( $rUserType['show_walkthrough'] == 1 )
			{
				return TRUE;
			}
			else
			{
				return FALSE;
			} //end if rUserType
			
			if ( $reset )
			{
				$data = array
				(
					'show_walkthrough' => 0
				);
				
				$this->db->where('member_id', $memberID);
				$this->db->where('store_id', $storeID);
				$this->db->update('ci_store_users', $data);
			} //end if reset
		}
	}
	
	/*
	| ====================================
	| Order Users
	| ====================================
	|
	| These functions deal with Order Users.
	| These are a list of names of people (pharmacists) who place orders.
	|
	| order_user_create()
	|	Creates an new User. The Store ID param would normally be the Active Store.
	|	Params: Store ID, Name
	|
	| order_user_update()
	|	Modifies an existing User
	|	Params: Order User ID, Update Array [ name ]
	|
	| order_user_delete()
	|	Removes a User by setting is_active to '0'
	|	Params: Order User ID
	|
	| order_user_last()
	|	Gets the most recent Order User that was created by the Current User
	|	Params: None
	|	Returns: Order User ID, False
	|	
	|
	| order_users()
	|	Gets a list of all active Order Users for all Stores associated with the current Account / Store User.
	|	Takes a zero-indexed array of Store ID's as provided by the user_store_ids() function.
	|	Finds all Order Users belonging to any of these Stores.
	|	Params: Array of Store ID's
	|	Returns: Object (Empty Object if no results)
	|
	| 
	| ci_order_users
	|----------------------
	| - id**
	| - store_id*
	| - name
	| - is_active
	| - added_by
	| - edited_by
	|
	*/
	
	function order_user_create($sid, $name)
	{
		$a = array(
			'store_id' => $sid,
			'name' => $name,
			'added_by' => member_id()
		);
		
		$this->db->insert('ci_order_users', $a);
		
		return ( $this->db->affected_rows() == 0 ) ? FALSE : TRUE;
	}
	
	function order_user_update($id, $a)
	{
		$a['edited_by'] = member_id();
		
		$this->db->where('id', $id);
		$this->db->update('ci_order_users', $a);
		
		return ( $this->db->affected_rows() == 0 ) ? FALSE : TRUE;
	}
	
	function order_user_delete($id)
	{
		$a = array
		(
			'is_active' => 0,
			'edited_by' => member_id()
		);
		
		$this->db->where('id', $id);
		$this->db->update('ci_order_users', $a );
		
		return ( $this->db->affected_rows() == 0 ) ? FALSE : TRUE;
	}
	
	function order_user_last()
	{
		$this->db->select('id');
		$this->db->from('ci_order_users');
		$this->db->where('added_by', member_id());
		$this->db->order_by('id', 'desc');
		$this->db->limit(1);
		$q = $this->db->get();
		$r = $q->row('id');
		
		return $r;
	}
	
	function order_users($sids)
	{
		$this->db->select('id, name, is_active');
		$this->db->from('ci_order_users');
		$this->db->where('is_active', 1);
		$this->db->where_in('store_id', $sids);
		$this->db->order_by('name', 'asc');
		$q = $this->db->get();
		$r = $q->result();
		
		return $r;
	}
	
	/*
	| ====================================
	| File Parsing
	| ====================================
	|
	| file_get() t:√_
	|	Params: Vendor API, Store NPI
	|	Returns: File Contents, False
	|
	| file_get_usage()
	|	Loads the contents of the Store Usage file.
	|	Params: API, NPI
	|	Returns: File Contents, False
	|
	| file_parse() t:√_
	|	Parses the Store's data file. This file is uploaded via FTP from the vendor right before a user is directed to a crx Store Invite URL.
	|	The CSV file, if present, is parsed into variables which will be used to populate the store create form.
	|	Params: File Contents String
	|	Returns: Assoc Array
	|
	| file_parse_usage()
	|	Parses the Store's Usage Data. Similar process to above file_parse().
	|	Params: File Contents String
	|	Returns: Assoc Array
	|
	| file_insert_usage()
	|	Inserts data into store_vendor_usage table based on given associative array
	|	Params: usage array
	|	Returns:
	|
	| file_delete() t:__
	|	Removes Store Details CSV file after parsing is completed.
	|	Params: API, NPI
	|	Returns: Boolean
	|
	| file_delete_usage() t:__
	|	Removes Usage CSV file after parsing is completed.
	|	Params: API, NPI
	|	Returns: Boolean
	|
	|
	*/
	
	function file_get($api, $npi)
	{
		$this->load->helper('file');
		
		$paths = config_item('paths');
		$f = 'store-' . $api . '-' . $npi . '.csv';
		$p = $paths['uploads'] . $paths['vendor_ftp'] . $f;
		
		if ( !file_exists( $p ) )
		{
			return FALSE;
		}
		else
		{
			$s = read_file($p);
			
			if ( $s == FALSE )
			{
				log_message('error', 'Vendor File - Store Details - File exists but unable to read. API: ' . $api . ' NPI: ' . $npi);
				return FALSE;
			}
			else
			{
				return $s;
			}
		}
	}
	
	function file_get_usage($api, $npi)
	{
		$this->load->helper('file');
		
		$paths = config_item('paths');
		$f = 'usage-' . $api . '-' . $npi . '.csv';
		$p = $paths['uploads'] . $paths['vendor_ftp'] . $f;
		
		if ( !file_exists( $p ) )
		{
			return FALSE;
		}
		else
		{
			$s = read_file($p);
			
			if ( $s == FALSE )
			{
				log_message('error', 'Vendor File - Store Usage - File exists but unable to read. API: ' . $api . ' NPI: ' . $npi);
				return FALSE;
			}
			else
			{
				return $s;
			}
		}
	}
	
	function file_parse($s)
	{
		$this->load->helper('csv');
		
		$cols = array(
			'dba_name',
			'street_address_1',
			'street_address_2',
			'city',
			'state',
			'zip',
			'contact_person',
			'phone',
			'fax',
			'email'
		);
		
		return csvToAssoc($cols, $s);
	}
	
	function file_parse_usage($s)
	{
		$this->load->helper('csv');
		
		$cols = array(
			'ncpdp',
			'drug_gcn',
			'drug_name',
			'drug_mfg',
			'drug_ndc',
			'drug_pkg_size',
			'drug_dir_cost',
			'date_last_used',
			'usage_period',
			'qty_on_hand',
			'wholesaler_order',
			'supplier_name',
			'version'
		);
		
		return csvToAssoc($cols, $s);
	}
	
	function file_insert_usage($a, $npi)
	{
		foreach ( $a as $row )
		{
			$cols = array(
				'ncpdp' => $npi,
				'drug_gcn' => $row['drug_gcn'],
				'drug_name' => $row['drug_name'],
				'drug_mfg' => $row['drug_mfg'],
				'drug_ndc' => $row['drug_ndc'],
				'drug_pkg_size' => $row['drug_pkg_size'],
				'drug_dir_cost' => $row['drug_dir_cost'],
				'date_last_used' => $row['date_last_used'],
				'usage_period' => $row['usage_period'],
				'qty_on_hand' => $row['qty_on_hand'],
				'wholesaler_order' => $row['wholesaler_order'],
				'supplier_name' => $row['supplier_name']
			);
			$this->db->insert('ci_store_vendor_data', $cols);
		} //end foreach $a
		
		//update the software version number
		$this->db->from('ci_stores');
		$this->db->where('npi', $npi);
		$this->db->limit(1);
		$q = $this->db->get();
		$rStore = $q->row_array();
		
		if ( isset($a[0]['version']) )
		{
			$data = array
			(
				'order_type' => $a[0]['version']
			);
			$this->db->where('store_id', $rStore['store_id']);
			$this->db->update('ci_temp_savings_parameters', $data);
		}

	}
	
	function file_delete($api, $npi)
	{
		$paths = config_item('paths');
		$f = 'store-' . $api . '-' . $npi . '.csv';
		$p = $paths['uploads'] . $paths['vendor_ftp'] . $f;
		
		return unlink($p);
	}
	
	function file_delete_usage($api, $npi)
	{
		/*$paths = config_item('paths');
		$f = 'usage-' . $api . '-' . $npi . '.csv';
		$p = $paths['uploads'] . $paths['vendor_ftp'] . $f;
		
		return unlink($p);*/
		
		return TRUE;
	}
}