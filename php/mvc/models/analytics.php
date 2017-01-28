<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Analytics extends CI_Model
{
	function __construct()
	{
		parent::__construct();
		
		$this->testMode = false;
	}
	
	/*
	| ====================================
	| Analytics Model
	| ====================================
	|
	| Tracks various stats related to the activity that occurs within the app.
	| Consolidates day and month records on-the-fly, as opposed to requiring the use of a cron.
	|
	| item_details()
	| 
	| 
	|
	*/
	
	/*
	| ====================================
	| Per-Item Analytics
	| ====================================
	|
	| order_placed()
	| 
	| Adds the specified stats to the database either by consolidating with an existing record or by inserting a new record.
	| When hooked into the site, this function should be run once for each unique item in an order.
	| Automatically runs real-time consolidation for days and months for this item.
	| Returns: Null
	|
	| order_cancelled()
	|
	| Removes cancelled items from the Item/Day rows.
	| Removes qty from items_sold and adds qty to items_cancelled.
	| Updates averages. Increments orders_cancelled.
	| Params:
	|	Item ID
	|	Quantity: The quantity of items being cancelled
	|	Price: The order price of the cancelled item(s)
	|	Date: The date the order was placed. Must be provided in Unix Timestamp format.
	| Returns: Boolean
	| NOTE: Per-Item cancelled orders or items that are  more than a month old may not be removed from the records because the order date may already be consolidated into Item/Month.
	| 
	| item_cancelled()
	|
	| This function is the same as "order_cancelled" but does not increment "orders_cancelled".
	|
	| Params: All of these functions have mostly the same parameters:
	|
	|	Item ID:  The ID of the item being ordered
	|	Manufacturer ID: The ID of the item's manufacturer
	|	Supplier ID: The ID of the item's supplier
	|	Store ID: The ID of the store placing the order
	|	Quantity: The quantity of this item being ordered
	|	Price: The price this item was purchased at
	|	Saved: The amount saved on the purchase (per-unit savings). If this value is zero, savings data will not be altered
	|	Is Rx: Applicable only to order_placed() and item_added(). Boolean. Whether the item is an Rx item or not
	|	Date: Not applicable to order_placed(). Unix Timestamp representing date order was placed.
	|
	*/
	
	
	
	function item_details($iid, $oid)
	{
		$q = $this->db
			->select('co.store_id as stid, co.drug_id as iid, co.supplier_id as suid, co.manufacturer_id as mid, co.unit_cost price, co.unit_savings, co.quantity as qtyPrev, co.date_time, lc.item_type')
			->from('ci_completedorders as co')
			->join('ci_listings_compiled as lc', 'co.drug_id = lc.drug_id')
			->where('co.drug_id', $iid)
			->where('co.order_id', $oid)
			->limit(1)
			->get();
			
		$r = $q->row();
			
		if ( $q->num_rows() === 0 )
		{
			return false;
		}
		else
		{
			$r->rx = ( $r->item_type == 1 ) ? false : true;
			unset($r->item_type);
			$r->date = strtotime($r->date_time);
			unset($r->date_time);
			
			$this->result_to_table('Queried item details: ', array($r));
			
			return $r;
		}
	}
	
	function order_placed($iid, $mid, $suid, $stid, $qty, $price, $saved, $rx, $date = false, $o = 1)
	{
		if ( $qty == 0  || $price == 0 )
		{
			log_message('error', 'Analytics Per-Item: order_placed(); Neither Price nor Qty can be zero.');
			return false;
		}
		
		$this->load->helper('date');
		
		if ( !$date )
		{
			$y = mdate('%Y', now());
			$m = mdate('%n', now());
			$d = mdate('%j', now());
		}
		else
		{
			$y = mdate('%Y', $date);
			$m = mdate('%n', $date);
			$d = mdate('%j', $date);
		}
		
		$a = array
		(
			'item_id' => $iid,
			'is_rx' => $rx ? 1 : 0,
			'qty_sold' => $qty,
			'qty_cancelled' => 0,
			'orders_placed' => $o,
			'orders_cancelled' => 0,
			
		);
		
		$this->put($a, $price, $saved, $y, $m, $d, 'item', NULL);
		$this->put($a, $price, $saved, $y, $m, $d, 'manufacturer', $mid);
		$this->put($a, $price, $saved, $y, $m, $d, 'supplier', $suid);
		$this->put($a, $price, $saved, $y, $m, $d, 'store', $stid);
	}
	
	
	
	function item_added($iid, $mid, $suid, $stid, $qty, $price, $saved, $rx, $date)
	{
		$this->order_placed($iid, $mid, $suid, $stid, $qty, $price, $saved, $rx, $date, 0);
	}
	
	
	
	function order_cancelled($iid, $mid, $suid, $stid, $qty, $price, $saved, $date, $o = 1)
	{
		if ( $price == 0 )
		{
			log_message('error', 'Analytics Per-Item: order/item_cancelled(); Neither Price nor Qty can be zero.');
			return false;
		}
		
		$this->load->helper('date');
		
		$y = mdate('%Y', $date);
		$m = mdate('%n', $date);
		$d = mdate('%j', $date);
		
		$a = array
		(
			'item_id' => $iid,
			'qty_sold' => 0,
			'qty_cancelled' => $qty,
			'orders_placed' => -($o),
			'orders_cancelled' => $o
		);
		
		$this->put($a, $price, $saved, $y, $m, $d, 'item', NULL);
		$this->put($a, $price, $saved, $y, $m, $d, 'manufacturer', $mid);
		$this->put($a, $price, $saved, $y, $m, $d, 'supplier', $suid);
		$this->put($a, $price, $saved, $y, $m, $d, 'store', $stid);
	}
	
	
	
	function item_cancelled($iid, $mid, $suid, $stid, $qty, $price, $saved, $date)
	{
		$this->order_cancelled($iid, $mid, $suid, $stid, $qty, $price, $saved, $date, 0);
	}
	
	
	
	function put($a, $p, $s, $y, $m, $d, $mode, $id)
	{
		$this->load->helper('date');
		
		switch ( $mode )
		{
			case 'item':
				$db = 'ci_analytics_per_item';
				$col = '';
				break;
			
			case 'manufacturer':
				$db = 'ci_analytics_per_item_manufacturer';
				$col = 'mid';
				break;
			
			case 'supplier':
				$db = 'ci_analytics_per_item_supplier';
				$col = 'suid';
				break;
				
			case 'store':
				$db = 'ci_analytics_per_item_store';
				$col = 'stid';
		}
		
		$this->result_to_table_array('Array passed into put(); Mode: '.$mode, $a);
		
		$this->db
			->select('*')
			->from($db)
			->where('item_id', $a['item_id'])
			->where('year', $y)
			->where('month', $m)
			->where('day', $d);
			
		if ( $col !== '' )
		{
			$this->db->where($col, $id);
		}
			
		$r = $this->db
			->limit(1)
			->get()->row();
		
		if ( count($r) > 0 )
		{
			$this->result_to_table('Queried existing Item/Day record; Mode: '.$mode, array($r));
			
			$qty = $r->qty_sold + ( $a['qty_sold'] - $a['qty_cancelled'] );
			
			if ( $qty === 0 )
			{
				$a['highest_sale_price'] = 0;
			}
			else
			{
				$asp = ( ( $r->average_sale_price * $r->qty_sold ) + ( $p * $a['qty_sold'] ) - ( $a['qty_cancelled'] * $p ) ) / $qty;
			
				//echo 'asp formula: ( ( PrevPrice * PrevQty ) + ( NewPrice * NewQty ) - ( CancelledQty * CancelledPrice ) ) / NewQty <br />';
				//echo 'asp formula: ( ('.$r->average_sale_price.' * '.$r->qty_sold.') + ('.$p.'*'.$a['qty_sold'].') - ('.$a['qty_cancelled'].'*'.$p.') ) / '.$qty . '<br />';
				
				$a['average_sale_price'] = round( $asp, 2 );
				
				$a['highest_sale_price'] = $r->highest_sale_price < $p ? $p : $r->highest_sale_price;
				$a['lowest_sale_price'] = $r->lowest_sale_price > $p ? $p : $r->lowest_sale_price;
				
				if ( $s != 0 )
				{
					$ts = $r->times_saved + ( $a['qty_sold'] - $a['qty_cancelled'] );
					
					$as = ( ( $r->average_savings * $r->times_saved ) + ( $s * $a['qty_sold'] ) - ( $s * $a['qty_cancelled'] ) ) / $ts;
					
					$a['times_saved'] = $ts;
					$a['average_savings'] = round($as, 2);
				}
			}
			
			$a['qty_sold'] = $qty;
			$a['qty_cancelled'] += $r->qty_cancelled;
			$a['orders_placed'] += $r->orders_placed;
			$a['orders_cancelled'] += $r->orders_cancelled;
			
			$this->result_to_table('Compiled Item/Day record to insert; Mode: '.$mode, array($a));
			
			$this->db
				->where('item_id', $a['item_id'])
				->where('year', $y)
				->where('month', $m)
				->where('day', $d)
				->update($db, $a);
		}
		else
		{
			if ( $col !== '' )
			{
				$a[$col] = $id;
			}
			
			$a['year'] = $y;
			$a['month'] = $m;
			$a['day'] = $d;
			$a['highest_sale_price'] = $p;
			$a['lowest_sale_price'] = $p;
			$a['average_sale_price'] = $p;
			
			if ( $s == 0 )
			{
				$a['times_saved'] = 0;
				$a['average_savings'] = 0;
			}
			else
			{
				$a['times_saved'] = $a['qty_sold'];
				$a['average_savings'] = $a['qty_sold'];
			}
			
			
			$this->result_to_table_array('New Item/Day record to insert; Mode: '.$mode, $a);
			
			$this->db->insert($db, $a);
		}
		
		$this->consolidate_days($a['item_id'], $db, $col, $id);
		$this->consolidate_months($a['item_id'], $db, $col, $id);
		
		return true;
		
	} // put()
	
	function consolidate_days($iid, $db, $col, $id)
	{
		$this->db
			->select('year, month')
			->from($db)
			->where('item_id', $iid);
			
		if ( $col !== '' )
		{
			$this->db->where($col, $id);
		}
		
		$r = $this->db
			->where('month != 0')
			->where('day != 0')
			->order_by('month desc')
			->group_by('month')
			->get()->result();
			
		if ( count($r) > 2 )
		{
			$r = array_slice($r, 2);
		}
		else
		{
			return false;
		}
			
		$this->result_to_table('Months to consolidate; Mode: '.$col, $r);
		
		if ( $this->testMode )
		{
			echo 'Rows to consolidate into Item/Month; Mode: '.$col.' <br>';
		}
		
		foreach ( $r as $k => $v )
		{
			$c = array
			(
				'item_id' => $iid,
				'year' => $v->year,
				'month' => $v->month,
				'qty_sold' => 0,
				'qty_cancelled' => 0,
				'orders_placed' => 0,
				'orders_cancelled' => 0,
				'highest_sale_price' => 0,
				'lowest_sale_price' => 1000000,
				'average_sale_price' => 0,
				'times_saved' => 0,
				'average_savings' => 0
			);
			
			if ( $col !== '' )
			{
				$c[$col] = $id;
			}
			
			$this->db
				->select('*')
				->from($db)
				->where('item_id', $iid);
				
			if ( $col !== '' )
			{
				$this->db->where($col, $id);
			}
			
			$ri = $this->db
				->where('month', $v->month)
				->where('year', $v->year)
				->get()->result();
			
			$this->result_to_table('', $ri);
			
			$ids = array();
			
			foreach ( $ri as $ki => $vi )
			{
				$ids[] = $vi->id;
				
				$c['qty_sold'] += $vi->qty_sold;
				$c['qty_cancelled'] += $vi->qty_cancelled;
				$c['orders_placed'] += $vi->orders_placed;
				$c['orders_cancelled'] += $vi->orders_cancelled;
				$c['highest_sale_price'] = $vi->highest_sale_price > $c['highest_sale_price'] ? $vi->highest_sale_price : $c['highest_sale_price'];
				$c['lowest_sale_price'] = $vi->lowest_sale_price < $c['lowest_sale_price'] ? $vi->lowest_sale_price : $c['lowest_sale_price'];
				$c['average_sale_price'] += ( $vi->average_sale_price * $vi->qty_sold );
				$c['times_saved'] += $vi->times_saved;
				$c['average_savings'] += ( $vi->average_savings * $vi->times_saved );
			}
			
			$c['average_sale_price'] = round( $c['average_sale_price'] / $c['qty_sold'], 2 );
			$c['average_savings'] = round( $c['average_savings'] / $c['times_saved'], 2 );
			
			$this->result_to_table_array('Consolidated Item/Month row to insert; Mode: '.$col, $c, false);
			
			$this->db->insert($db, $c);
			
			if ( $this->db->affected_rows() === 1 )
			{
				$this->db->where_in('id', $ids)->delete($db);
			}
			else
			{
				return false;
			}
		}
		
		return true;
	}
	
	function consolidate_months($iid, $db, $col, $id)
	{
		$this->db
			->select('year')
			->from($db)
			->where('item_id', $iid);
			
		if ( $col !== '' )
		{
			$this->db->where($col, $id);
		}
		
		$r = $this->db
			->where('month != 0')
			->where('day', 0)
			->order_by('year desc')
			->group_by('year')
			->get()->result();
			
		if ( count($r) > 2 )
		{
			$r = array_slice($r, 2);
		}
		else
		{
			return false;
		}
			
		$this->result_to_table('Years to consolidate; Mode: '.$col, $r);
		
		if ( $this->testMode )
		{
			echo 'Rows to consolidate into Item/Year; Mode: '.$col.' <br>';
		}
		
		foreach ( $r as $k => $v )
		{
			$c = array
			(
				'item_id' => $iid,
				'year' => $v->year,
				'qty_sold' => 0,
				'qty_cancelled' => 0,
				'orders_placed' => 0,
				'orders_cancelled' => 0,
				'highest_sale_price' => 0,
				'lowest_sale_price' => 1000000,
				'average_sale_price' => 0,
				'times_saved' => 0,
				'average_savings' => 0
			);
			
			if ( $col !== '' )
			{
				$c[$col] = $id;
			}
			
			$this->db
				->select('*')
				->from($db)
				->where('item_id', $iid);
				
			if ( $col !== '' )
			{
				$this->db->where($col, $id);
			}
			
			$ri = $this->db
				->where('year', $v->year)
				->where('day', 0)
				->get()->result();
				
			$this->result_to_table('', $ri);
			
			$ids = array();
			
			foreach ( $ri as $ki => $vi )
			{
				$ids[] = $vi->id;
				
				$c['qty_sold'] += $vi->qty_sold;
				$c['qty_cancelled'] += $vi->qty_cancelled;
				$c['orders_placed'] += $vi->orders_placed;
				$c['orders_cancelled'] += $vi->orders_cancelled;
				$c['highest_sale_price'] = $vi->highest_sale_price > $c['highest_sale_price'] ? $vi->highest_sale_price : $c['highest_sale_price'];
				$c['lowest_sale_price'] = $vi->lowest_sale_price < $c['lowest_sale_price'] ? $vi->lowest_sale_price : $c['lowest_sale_price'];
				$c['average_sale_price'] += ( $vi->average_sale_price * $vi->qty_sold );
				$c['times_saved'] += $vi->times_saved;
				$c['average_savings'] += ( $vi->average_savings * $vi->times_saved );
			}
			
			$c['average_sale_price'] = round( $c['average_sale_price'] / $c['qty_sold'], 2 );
			$c['average_savings'] = round( $c['average_savings'] / $c['times_saved'], 2 );
			
			$this->result_to_table_array('Consolidated Item/Year row to insert; Mode: '.$col, $c, false);
			
			$this->db->insert($db, $c);
			
			if ( $this->db->affected_rows() === 1 )
			{
				$this->db->where_in('id', $ids)->delete($db);
			}
			else
			{
				return false;
			}
		}
		
		return true;
	}
	
	/*
	| ====================================
	| Reporting Queries
	| ====================================
	|
	| top()
	|
	|	Gets a report of the top metrics for the specified Type, Metric, and ID, etc.
	|	Can specify which Type of data to get 
	|
	*/
	
	function top($mode, $metric, $id, $limit = 10, $y = false, $m = false, $d = false, $rx = true, $sort = 'desc')
	{
		switch ( $mode )
		{
			case 'item':
				$db = 'ci_analytics_per_item';
				$id_col = 'item_id';
				break;
			
			case 'manufacturer':
				$db = 'ci_analytics_per_item_manufacturer';
				$id_col = 'mid';
				break;
			
			case 'supplier':
				$db = 'ci_analytics_per_item_supplier';
				$id_col = 'suid';
				break;
				
			case 'store':
				$db = 'ci_analytics_per_item_store';
				$id_col = 'stid';
		}
	
		$this->db
			->select($metric)
			->from($db)
			->where($id_col, $id)
			->where('is_rx', $rx ? 1 : 0)
			->limit($limit)
			->order_by($metric, $sort);
			
		if ( $y )
		{
			$this->db->where('year', $y);
		}
		
		if ( $m )
		{
			$this->db->where('month', $y);
		}
		
		if ( $d )
		{
			$this->db->where('day', $y);
		}
		
		$r = $this->db->get()->result();
		
		$this->result_to_table('Report results: ', $r, false);
	}
	
	function bottom($mode, $metric, $id, $limit = 10, $y = false, $m = false, $d = false, $rx = true)
	{
		$this->top($mode, $metric, $id, $y, $m, $d, $rx, $sort = 'asc');
	}
	
	/*
	| ====================================
	| Testing Functions
	| ====================================
	|
	| 
	|
	*/
	
	function rows($iid, $mode, $id)
	{
		switch ( $mode )
		{
			case 'item':
				$db = 'ci_analytics_per_item';
				$col = '';
				break;
			
			case 'manufacturer':
				$db = 'ci_analytics_per_item_manufacturer';
				$col = 'mid';
				break;
			
			case 'supplier':
				$db = 'ci_analytics_per_item_supplier';
				$col = 'suid';
				break;
				
			case 'store':
				$db = 'ci_analytics_per_item_store';
				$col = 'stid';
		}
		
		$this->db
			->select('*')
			->from($db)
			->where('item_id', $iid);
			
		if ( $col !== '' )
		{
			$this->db->where($col, $id);
		}
			
		$r = $this->db
			->order_by('year desc, month desc')
			->get()->result();
		
		$this->result_to_table('Queried analytics data for Item: '.$iid.'. Mode: '.$mode, $r);
	}
	
	function result_to_table($s, $r)
	{
		if ( !$this->testMode ) { return false; }
		echo $s . '<br>';
		
		echo '<table border="1" cellpadding="5">';
		
		echo '<tr>';
		
		foreach ( $r[0] as $k => $v )
		{
			echo '<th>'.$k.'</th>';
		}
		
		echo '</tr>';
		
		foreach ( $r as $k => $v )
		{
			echo '<tr>';
				
			foreach ( $v as $k2 => $v2 )
			{
				echo '<td>'.$v2.'</td>';
			}
			
			echo '</tr>';
	
		}
		
		echo '</table><br>';
	}
	
	function result_to_table_array($s, $r)
	{
		if ( !$this->testMode ) { return false; }
		echo $s . '<br>';
		
		echo '<table border="1" cellpadding="5">';
		
		echo '<tr>';
		
		foreach ( $r as $k => $v )
		{
			echo '<th>'.$k.'</th>';
		}
		
		echo '</tr><tr>';
		
		foreach ( $r as $k => $v )
		{
			echo '<td>'.$v.'</td>';
		}
		
		echo '</tr>';
		
		echo '</table><br>';
	}
	
	function mock_data($mode)
	{
		switch ( $mode )
		{
			case 'item':
				$db = 'ci_analytics_per_item';
				$col = '';
				break;
			
			case 'manufacturer':
				$db = 'ci_analytics_per_item_manufacturer';
				$col = 'mid';
				break;
			
			case 'supplier':
				$db = 'ci_analytics_per_item_supplier';
				$col = 'suid';
				break;
				
			case 'store':
				$db = 'ci_analytics_per_item_store';
				$col = 'stid';
		}
		
		// Fill database with mock data
		
		$this->db->truncate($db);
		
		// A bunch of random Items
		
		for ( $h = 0; $h < 100; $h++ )
		{
			if ( $mode === 'item' )
			{
				$loop = 1;
			}
			else
			{
				$loop = 3;
			}
			
			for ( $ii = 0; $ii < $loop; $ii++ )
			{				
				$item_id = $h + 1;
			
				// Year consolidations
				
				$year = 1983;
				$data = array();
				
				for ( $i = $year; $i <= 2010; $i++ )
				{
					$data[] = array
					(
						'item_id' => $item_id,
						'year' => $i,
						'qty_sold' => mt_rand(1, 10000),
						'qty_cancelled' => mt_rand(1, 1000),
						'orders_placed' => mt_rand(1, 1000),
						'orders_cancelled' => mt_rand(1, 100),
						'highest_sale_price' => mt_rand(1, 1000),
						'lowest_sale_price' => mt_rand(1, 1000),
						'average_sale_price' => mt_rand(1, 1000),
						'times_saved' => mt_rand(1, 100),
						'average_savings' => mt_rand(1, 100)
					);
				}
				
				if ( $col !== '' )
				{
					foreach ( $data as $k => $v )
					{
						$data[$k][$col] = mt_rand(1, 10);
					}
				}
				
				$this->db->insert_batch($db, $data);
				
				// Month consolidations
				
				$year = 2011;
				$data = array();
				
				for ( $i = $year; $i <= 2014; $i++ )
				{
					for ( $j = 1; $j <= 12; $j++ )
					{
						$data[] = array
						(
							'item_id' => $item_id,
							'year' => $i,
							'month' => $j,
							'qty_sold' => mt_rand(1, 1000),
							'qty_cancelled' => mt_rand(1, 100),
							'orders_placed' => mt_rand(1, 100),
							'orders_cancelled' => mt_rand(1, 10),
							'highest_sale_price' => mt_rand(1, 1000),
							'lowest_sale_price' => mt_rand(1, 1000),
							'average_sale_price' => mt_rand(1, 1000),
							'times_saved' => mt_rand(1, 100),
							'average_savings' => mt_rand(1, 100)
						);
					}
				}
				
				if ( $col !== '' )
				{
					foreach ( $data as $k => $v )
					{
						$data[$k][$col] = mt_rand(1, 10);
					}
				}
				
				$this->db->insert_batch($db, $data);
				
				// Day consolidations
				
				$year = 2015;
				$month = 7;
				$data = array();
				
				for ( $i = $month; $i <= 12; $i++ )
				{
					for ( $j = 1; $j <= 31; $j++ )
					{
						$data[] = array
						(
							'item_id' => $item_id,
							'year' => $year,
							'month' => $i,
							'day' => $j,
							'qty_sold' => mt_rand(1, 100),
							'qty_cancelled' => mt_rand(1, 10),
							'orders_placed' => mt_rand(1, 10),
							'orders_cancelled' => mt_rand(1, 10),
							'highest_sale_price' => mt_rand(1, 1000),
							'lowest_sale_price' => mt_rand(1, 1000),
							'average_sale_price' => mt_rand(1, 1000),
							'times_saved' => mt_rand(1, 100),
							'average_savings' => mt_rand(1, 100)
						);
					}
				}
				
				if ( $col !== '' )
				{
					foreach ( $data as $k => $v )
					{
						$data[$k][$col] = mt_rand(1, 10);
					}
				}
				
				$this->db->insert_batch($db, $data);
			
			}
		}
	}
}