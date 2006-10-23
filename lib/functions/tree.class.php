<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later. 
 *
 * Filename $RCSfile: tree.class.php,v $
 *
 * @version $Revision: 1.23 $
 * @modified $Date: 2006/10/23 20:11:28 $ by $Author: schlundus $
 * @author Francisco Mancardi
 *
 * 20061008 - franciscom - ORDER BY node_order -> ORDER BY node_order,id
 * 20060729 - franciscom - fixed bug on new_node() after refactoring in version 1.20
 * 20060722 - franciscom - added possibility to create a new node with an specific ID
 * 20060511 - franciscom - changes in call to insert_id() due to problems with Postgres
 * 20060316 - franciscom - bug on get_path
*/

class tree 
{
	// configurable values - pseudoconstants
	var $node_types = array( 1 => 'testproject','testsuite','testcase','tcversion','testplan');
	var $node_descr_id = array();
	
	var $node_tables = array('testproject' => 'testprojects',
                           'testsuite'   => 'testsuites',
                           'testplan'    => 'testplans',
                           'testcase'    => 'testcases',
                           'tcversion'   => 'tcversions');
 
  
  
	var $ROOT_NODE_TYPE_ID = 1;
	var $ROOT_NODE_PARENT_ID = NULL;
	
	var $db;
    var $obj_table = 'nodes_hierarchy';
    
	function tree(&$db) 
	{
		$this->db = &$db;
		$this->node_descr_id = array_flip($this->node_types);
	}

	function get_available_node_types() 
	{
		$sql = " SELECT * FROM node_types "; 
		$hash_ntypes = $this->db->fetchColumnsIntoMap($sql,"description","id");
		
		return $hash_ntypes;
	}

	/*
		create a new root node in the hierarchy table
		returns: node_id of the new node created
	*/
	function new_root_node($name = '') 
	{
		$this->new_node(null,$this->ROOT_NODE_TYPE_ID,$name,1);
		return $this->db->insert_id($this->obj_table);
	}


	/*
	  create a new  node in the hierarchy table
	  returns: node_id of the new node created
	  
	  20060722 - franciscom - interface changes - added [$node_id]
	*/
	function new_node($parent_id,$node_type_id,$name='',$node_order=0,$node_id=0) 
	{
		$sql = "INSERT INTO {$this->obj_table} " .
		       "(name,node_type_id,node_order";
		
		$values=" VALUES('" . $this->db->prepare_string($name). "'," .
		        " {$node_type_id},{$node_order}";
		if ($node_id)
		{
			$sql .= ",id";
			$values .= ",{$node_id}";
		}
		
		if(is_null($parent_id))
		{
			$sql .= ") {$values} )";
		}
		else
		{
			$sql .= ",parent_id) {$values},{$parent_id})";
    }

		$this->db->exec_query($sql);
		return ($this->db->insert_id($this->obj_table));
 	}

	/*
	get all node hierarchy info from hierarchy table
	returns: node_id of the new node created
	*/
	function get_node_hierachy_info($node_id) 
	{
		$sql = "SELECT * FROM {$this->obj_table} WHERE id = {$node_id}";
		$result = $this->db->exec_query($sql);
		
		return $this->db->fetch_array($result);
	}

	function get_subtree_list($node_id)
	{
		$sql = "SELECT * from {$this->obj_table} WHERE parent_id = {$node_id}";
		$node_list = '';  
		$result = $this->db->exec_query($sql);
		
		if (!$result || !$this->db->num_rows($result))
			return null;
		
		while($row = $this->db->fetch_array($result))
		{
			$node_list .= $row['id'];
			
			$xx_list = $this->get_subtree_list($row['id']);	
		
			if(!is_null($xx_list))
				$node_list .= "," . $xx_list;
		}
		return $node_list;
	}

	function delete_subtree($node_id)
	{
		$children = $this->get_subtree_list($node_id);
		$id2del = $node_id;
		if(strlen($children))
		{
			$id2del .= ",{$children}";	
		}
		$sql = "DELETE FROM {$this->obj_table} WHERE id IN ({$id2del})";
	
		$result = $this->db->exec_query($sql);
	}

	function get_path_new($node_id,$to_node_id = null,$format = 'full') 
	{
		$the_path = array();
		$this->_get_path($node_id,$the_path,$to_node_id,$format); 
		
		return $the_path;
	}

	function get_path($node_id,$to_node_id = null,$format = 'full') 
	{
		// look up the parent of this node
		$sql = " SELECT * from {$this->obj_table} WHERE id = {$node_id} ";
	
		$node_list = array();
		$result = $this->db->exec_query($sql);
	
		if(!$result || !$this->db->num_rows($result))
		{
			return null;
		}
	
		while ($row = $this->db->fetch_array($result))
		{
			// only continue if this $node isn't the root node
			// (that's the node with no parent)
			if ($row['parent_id'] != '' && $row['id'] != $to_node_id) 
			{
				// Getting data from the node specific table
				$node_table = $this->node_tables[$this->node_types[$row['node_type_id']]];
				
				// the last part of the path to $node, is the name
				// of the parent of $node
				if($format == "full")
				{
					$node_list[] = array('id' => $row['id'],
											'parent_id' => $row['parent_id'],
											'node_type_id' => $row['node_type_id'],
											'node_order' => $row['node_order'],
											'node_table' => $node_table,
											'name' => $row['name']
										);
				}
				else
				{
					$node_list[$row['parent_id']] = $row['parent_id'];
				}
		
				// we should add the path to the parent of this node
				// to the path
				$node_list = array_merge($this->get_path($row['parent_id'],$to_node_id,$format), $node_list);
			}
		}
		
		return $node_list;
	}


// $node is the name of the node we want the path of
// 20060327 - franciscom
function _get_path($node_id,&$node_list,$to_node_id=null,$format='full') 
{
	
// look up the parent of this node
 $sql = " SELECT * from {$this->obj_table} 
          WHERE id = {$node_id} ";
 
 $result = $this->db->exec_query($sql);
 
 if( $this->db->num_rows($result) == 0 )
 {
    $node_list=null;
    return; 	
 }
  
 while ( $row = $this->db->fetch_array($result) )
 {
   
   // only continue if this $node isn't the root node
   // (that's the node with no parent)
   
   if ($row['parent_id'] != '' && $row['id'] != $to_node_id) 
   {
   	  // 20060309 - franciscom
      // Getting data from the node specific table
      $node_table = $this->node_tables[$this->node_types[$row['node_type_id']]];
      
   		// the last part of the path to $node, is the name
   		// of the parent of $node
   		if( $format == "full" )
   		{
      		$node_list[] = array('id'        => $row['id'],
          		                 'parent_id' => $row['parent_id'],
              		             'node_type_id' => $row['node_type_id'],
                  		         'node_order' => $row['node_order'],
                      		     'node_table' => $node_table,
                          		 'name' => $row['name'] );
      }
      else
      {
      		$node_list[$row['parent_id']] = $row['parent_id'];
      }
			
      // we should add the path to the parent of this node
      // to the path
      //$node_list = array_merge($this->get_path($row['parent_id'],$to_node_id,$format), $node_list);
      $this->_get_path($row['parent_id'],$node_list,$to_node_id,$format);
   }
 }
}

function change_parent($node_id, $parent_id) 
{
  $sql = "UPDATE nodes_hierarchy SET parent_id = {$parent_id} WHERE id = {$node_id}";
  $result = $this->db->exec_query($sql);
 
  return $result ? 1 : 0;
}
 
 
// 20061008 - franciscom - added ID in order by clause
// 
function get_children($id,$exclude_node_types=null) 
{
  $sql = " SELECT * from {$this->obj_table}
          WHERE parent_id = {$id} ORDER BY node_order,id";

  $node_list=array();  
  $result = $this->db->exec_query($sql);
 
  if( $this->db->num_rows($result) == 0 )
  {
    return(null); 	
  }

  while ( $row = $this->db->fetch_array($result) )
  {
    // ----------------------------------------------------------------------------
    // Getting data from the node specific table
    $node_table = $this->node_tables[$this->node_types[$row['node_type_id']]];

    if( !isset($exclude_node_types[$this->node_types[$row['node_type_id']]]))
    {
      $node_list[] = array('id'        => $row['id'],
                           'parent_id' => $row['parent_id'],
                           'node_type_id' => $row['node_type_id'],
                           'node_order' => $row['node_order'],
                           'node_table' => $node_table,
                           'name' => $row['name']);
  	}
  }
  return ($node_list);
}
 
 
/* 20060310 - franciscom */
/* both hash indexed by the same value -> the node_id
   example:
   $hash_node_id=array(10=>10, 23=>23, 30=>30);
   $hash_node_order=array(10=>3, 23=>1, 30=>2);
*/   
function change_order_bulk($hash_node_id, $hash_node_order) 
{
	foreach($hash_node_id as $the_id => $elem)
	{
		$order = intval($hash_node_order[$the_id]);
		$the_id = intval($the_id);
	  	$sql = "UPDATE {$this->obj_table} SET node_order = {$order}
	      	    WHERE id = {$the_id}";
	  	$result = $this->db->exec_query($sql);
	}
  
	return $result;
}


function get_subtree($node_id,$exclude_node_types=null,
                              $exclude_children_of=null,
                              $exclude_branches=null, $and_not_in_clause='',$bRecursive = false)
{
 		$the_subtree=array();
 		
 		// Generate NOT IN CLAUSE to exclude some node types
 		$not_in_clause='';
 	  if( !is_null($exclude_node_types) )
  	{
  			$exclude=array();
    		foreach($exclude_node_types as $the_key => $elem)
    		{
      			$exclude[]= $this->node_descr_id[$the_key];
    		}
    		$not_in_clause = " AND node_type_id NOT IN (" . implode(",",$exclude) . ")";
  	}
    
	if ($bRecursive)
	    $this->_get_subtree_rec($node_id,$the_subtree,$not_in_clause,
	                                          $exclude_children_of,
	                                          $exclude_branches);
	else
	    $this->_get_subtree($node_id,$the_subtree,$not_in_clause,
	                                          $exclude_children_of,
	                                          $exclude_branches);

    return ($the_subtree);
}


// 20061008 - franciscom - added ID in order by clause
// 
// 20060312 - franciscom
// Changed and improved following some Andreas Morsing advice.
//
// I would like this method will be have PRIVate scope, but seems not possible in PHP4
// that's why I've prefixed with _
//
function _get_subtree($node_id,&$node_list,$and_not_in_clause='',
                                           $exclude_children_of=null,
                                           $exclude_branches=null)
{

  	$sql = " SELECT * from nodes_hierarchy
    	       WHERE parent_id = {$node_id}  {$and_not_in_clause} ORDER BY node_order,id";
 
    $result = $this->db->exec_query($sql);
  
    if( $this->db->num_rows($result) == 0 )
    {
  	   return; 	
    }
  
    while ( $row = $this->db->fetch_array($result) )
    {

      if( !isset($exclude_branches[$row['id']]) )
      {  
        	$node_table = $this->node_tables[$this->node_types[$row['node_type_id']]];
          $node_list[] = array('id'        => $row['id'],
                               'parent_id' => $row['parent_id'],
                               'node_type_id' => $row['node_type_id'],
                               'node_order' => $row['node_order'],
                               'node_table' => $node_table,
                               'name' => $row['name']);
          
          // Basically we use this because:
          // 1. Sometimes we don't want the children if the parent is a testcase,
          //    due to the version management
          //
          // 2. Sometime we want to exclude all descendants (branch) of a node.
          //
          // [franciscom]: 
          // I think ( but I have no figures to backup my thoughts) doing this check and 
          // avoiding the function call is better that passing a condition that will result
          // in a null result set.
          //
          //
          if( !isset($exclude_children_of[$this->node_types[$row['node_type_id']]]) && 
              !isset($exclude_branches[$row['id']])
            )
          {
        	  $this->_get_subtree($row['id'],$node_list,
        	                                 $and_not_in_clause,
        	                                 $exclude_children_of,
        	                                 $exclude_branches);	
         	  
        	}
    	}
  	}
} // function end
 
 
// 20061008 - franciscom - added ID in order by clause
// 
function _get_subtree_rec($node_id,&$pnode,$and_not_in_clause = '',
                                           $exclude_children_of = null,
                                           $exclude_branches = null)
{
  	$sql = " SELECT * from {$this->obj_table} WHERE parent_id = {$node_id} {$and_not_in_clause}" .
		       " ORDER BY node_order,id";
 
    $result = $this->db->exec_query($sql);
    while($row = $this->db->fetch_array($result))
    {
		$rowID = $row['id'];
		$nodeTypeID = $row['node_type_id'];
		$nodeType = $this->node_types[$nodeTypeID];
		
		if(!isset($exclude_branches[$rowID]))
		{  
			$node_table = $this->node_tables[$nodeType];
			$node =  array(	   'id' => $rowID,
                               'parent_id' => $row['parent_id'],
                               'node_type_id' => $nodeTypeID,
                               'node_order' => $row['node_order'],
                               'node_table' => $node_table,
                               'name' => $row['name'],
							   'childNodes' => null,
							   );
          
          // Basically we use this because:
          // 1. Sometimes we don't want the children if the parent is a testcase,
          //    due to the version management
          //
          // 2. Sometime we want to exclude all descendants (branch) of a node.
          //
          // [franciscom]: 
          // I think ( but I have no figures to backup my thoughts) doing this check and 
          // avoiding the function call is better that passing a condition that will result
          // in a null result set.
          //
          //
          if(!isset($exclude_children_of[$nodeType]) && 
              !isset($exclude_branches[$rowID])
            )
			{
				$this->_get_subtree_rec($rowID,$node,
        	                            $and_not_in_clause,
        	                            $exclude_children_of,
        	                            $exclude_branches);	
         	}
			
			$pnode['childNodes'][] = $node;
		}
  	}
}
 
}// end class
?>