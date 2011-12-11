<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Many Assets Plugin
 *
 * @package		ExpressionEngine
 * @subpackage	Addons
 * @category	Plugin
 * @author		John D Wells
 * @link		http://johndwells.com
 */

$plugin_info = array(
	'pi_name'		=> 'Many Assets',
	'pi_version'	=> '1.2.0',
	'pi_author'		=> 'John D Wells',
	'pi_author_url'	=> 'http://johndwells.com',
	'pi_description'=> 'Retrieve P&T Assets from across many Entries, and/or across many custom fields.',
	'pi_usage'		=> Many_assets::usage()
);

class Many_assets {

	public $return_data;
    
	/**
	 * Constructor
	 */
	public function __construct()
	{

		// Obviously
		$this->EE =& get_instance();



		/*
		 * create and/or reference our cache
		 * ----------------------------------------------------
		 * ----------------------------------------------------
		 */
		if ( ! isset($this->EE->session->cache['many_assets']))
        {
            $this->EE->session->cache['many_assets'] = array();
        }
        $this->cache =& $this->EE->session->cache['many_assets'];
    


    	/*
    	 * fetch required param(s) - return immediately if any are not provided
		 * ----------------------------------------------------
		 * ----------------------------------------------------
    	 */
		if (($entry_ids	= $this->EE->TMPL->fetch_param('entry_ids', FALSE)) == FALSE) return;



    	/*
    	 * fetch optional params
		 * ----------------------------------------------------
		 * ----------------------------------------------------
    	 */
    	$include		= $this->EE->TMPL->fetch_param('include', FALSE);
    	$orderby		= $this->EE->TMPL->fetch_param('orderby', FALSE);
    	$sort			= $this->EE->TMPL->fetch_param('sort', '');
    	$limit			= $this->EE->TMPL->fetch_param('limit', 0);
    	$offset			= $this->EE->TMPL->fetch_param('offset', 0);



		/*
		 * Format/standardise params
		 * ----------------------------------------------------
		 * ----------------------------------------------------
		 */
		
		$entry_ids = trim($entry_ids, ',|');
    	if(strpos($entry_ids, '|') !== FALSE)
    	{
    		$entry_ids = str_replace('|', ',', $entry_ids);
    	}

		$include = trim($include, ',|');
    	if(strpos($include, '|') !== FALSE)
    	{
    		$include = str_replace('|', ',', $include);
    	}

		$orderby = strtolower($orderby);
    	if($orderby == 'random')
    	{
    		$orderby = 'RAND()';
    		
    		// no sense in sorting if we're random, right?
    		$sort = '';
    	}
    	
    	$limit = intval($limit);
    	$offset = intval($offset);



		/*
		 * Limit query to include specific field(s)?
		 * ----------------------------------------------------
		 * ----------------------------------------------------
		 */
		$sql_include = array();
		if($include)
		{

    		foreach(explode(',', $include) as $names)
    		{
    			switch(substr_count($names, ':'))
    			{
    				case(2) :
	    				// Only run query if our matrix table exists
	    				if($this->EE->db->table_exists('exp_matrix_cols'))
	    				{
		    				list($channel_name, $field_name, $col_name) = explode(':', $names);
		    				$sql = 'SELECT mc.col_id, mc.field_id
		    							FROM exp_matrix_cols mc JOIN exp_channel_fields cf ON mc.field_id = cf.field_id
		    							WHERE cf.field_name = "' . $field_name . '"
		    							AND group_id IN(SELECT field_group FROM exp_channels WHERE channel_name = "'. $channel_name . '")
		    							AND mc.col_name = "' . $col_name . '" LIMIT 1';
				    		$query = $this->EE->db->query($sql);
							if($query->num_rows())
							{
								$row = $query->row();
								$key = 'f' . $row->field_id . 'c' . $row->col_id;
								if( ! array_key_exists($key, $sql_include))
								{
									$sql_include[$key] = ' (ae.field_id = "' . $row->field_id . '" AND ae.col_id="' . $row->col_id . '") ';
								}
							}

							// waste not, want not
							$query->free_result();
						}
    				break;
    				
    				case(1) :
	    				list($channel_name, $field_name) = explode(':', $names);
			    		$sql = 'SELECT field_id FROM exp_channel_fields WHERE field_name = "' . $field_name . '" AND group_id IN(SELECT field_group FROM exp_channels WHERE channel_name = "'. $channel_name . '")';
			    		$query = $this->EE->db->query($sql);
						if($query->num_rows())
						{
							$row = $query->row();
							$key = 'f' . $row->field_id;
							if( ! array_key_exists($key, $sql_include))
							{
								$sql_include[$key] = ' ae.field_id = "' . $query->row()->field_id . '" ';
							}
						}
	
						// waste not, want not
						$query->free_result();
    				break;
    			}
    		}
		}



		/*
		 * build our query
		 * ----------------------------------------------------
		 * ----------------------------------------------------
		 */
		$sql = 'SELECT DISTINCT a.asset_id, a.*
			FROM exp_assets a
			JOIN exp_assets_entries ae ON a.asset_id = ae.asset_id
			WHERE ae.entry_id IN(' . $entry_ids . ')';
		
		
		$sql .= ' AND (' . implode(' OR ', $sql_include) . ')';

		if($orderby)
		{
			$sql .= ' ORDER BY ' . $orderby . ' ' . $sort;
		}
		
		if($limit > 0)
		{
			$sql .= ' LIMIT ' . $limit;
		}
		
		if($offset > 0)
		{
			$sql .= ' OFFSET ' . $limit;
		}



		/*
		 * Fetch & cache our query if not already cached
		 * ----------------------------------------------------
		 * ----------------------------------------------------
		 */
		$key = serialize($sql);
		if ( ! isset($this->_cache[$key]))
		{
			$query = $this->EE->db->query($sql);
			$this->_cache[$key] = $query->result_array();
			$query->free_result();
		}



		/*
		 * process whatever we've found
		 * ----------------------------------------------------
		 * ----------------------------------------------------
		 */
		$files = array();
		if($this->_cache[$key])
		{
		
			// Include dependency classes
			if ( ! class_exists('EE_Fieldtype'))
			{
				include_once (APPPATH . 'fieldtypes/EE_Fieldtype' . EXT);
			}

			if ( ! class_exists('Assets_ft'))
			{
				include_once PATH_THIRD . 'assets/ft.assets.php';
			}

			// heavy lifting
			$Assets_ft = new Assets_ft();
			foreach ($this->_cache[$key] as $row)
			{
				$file = $Assets_ft->helper->get_file($row['file_path']);
	
				if ($file->exists())
				{
					$file->set_row($row);
					
					$files[] = $file;
				}
			}
		}



		/*
		 * What to return?
		 * ----------------------------------------------------
		 * ----------------------------------------------------
		 */
		if(count($files) > 0)
		{
			$this->return_data = $Assets_ft->replace_tag($files, $this->EE->TMPL->tagparams, $this->EE->TMPL->tagdata);
		} else {
			// Nothing - show No Results
			$this->return_data = $this->EE->TMPL->no_results();
		}
		
		// release the hounds!
		return $this->return_data;
    }




	/**
	 * Plugin Usage
	 */
	public static function usage()
	{
		ob_start();
?>

See README.md for details, or visit https://github.com/johndwells/Many-Assets.
<?php
		$buffer = ob_get_contents();
		ob_end_clean();
		return $buffer;
	}
}


/* End of file pi.many_assets.php */
/* Location: /system/expressionengine/third_party/many_assets/pi.many_assets.php */